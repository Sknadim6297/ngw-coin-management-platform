<?php

declare(strict_types=1);

namespace App\Services;

use Config\Blockchain;
use Config\Platform;

/**
 * Phase 4C: read-only deployment preflight checks.
 * Never prints secrets. Never mutates server settings.
 */
class DeploymentPreflightService
{
    /**
     * @return array{
     *   generated_at: string,
     *   ready_for_controlled_review: bool,
     *   live_payments_recommended: bool,
     *   checks: list<array{id: string, label: string, status: string, detail: string, blocker: bool}>
     * }
     */
    public function run(): array
    {
        $checks = [];
        $checks[] = $this->phpVersion();
        $checks[] = $this->extensions();
        $checks[] = $this->database();
        $checks[] = $this->migrations();
        $checks[] = $this->https();
        $checks[] = $this->environment();
        $checks[] = $this->canonicalUrlAndDebug();
        $checks[] = $this->paymentGate();
        $checks[] = $this->blockchainConfig();
        $checks[] = $this->networks();
        $checks[] = $this->evidenceStorage();
        $checks[] = $this->schedulerNote();

        $blockers = array_filter($checks, static fn (array $c): bool => $c['blocker'] && $c['status'] !== 'pass');
        $liveOk   = $this->livePaymentsOk($checks);

        return [
            'generated_at'                 => date('Y-m-d H:i:s'),
            'ready_for_controlled_review'  => $blockers === [],
            'live_payments_recommended'    => $liveOk,
            'checks'                       => $checks,
        ];
    }

    /**
     * @param list<array{id: string, label: string, status: string, detail: string, blocker: bool}> $checks
     */
    private function livePaymentsOk(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['status'] !== 'pass') {
                return false;
            }
        }

        return false; // Never auto-recommend live until explicit ops sign-off; always false in code.
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function phpVersion(): array
    {
        $ok = version_compare(PHP_VERSION, '8.2.0', '>=');

        return [
            'id'      => 'php_version',
            'label'   => 'PHP version >= 8.2',
            'status'  => $ok ? 'pass' : 'fail',
            'detail'  => 'Detected PHP ' . PHP_VERSION,
            'blocker' => true,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function extensions(): array
    {
        $required = ['intl', 'json', 'mbstring', 'openssl', 'curl', 'pdo_mysql', 'bcmath'];
        $missing  = [];
        foreach ($required as $ext) {
            if (! extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        $intlMissing = in_array('intl', $missing, true);

        return [
            'id'      => 'php_extensions',
            'label'   => 'Required PHP extensions',
            'status'  => $missing === [] ? 'pass' : 'fail',
            'detail'  => $missing === []
                ? 'All required extensions loaded (incl. intl).'
                : ('Missing: ' . implode(', ', $missing) . ($intlMissing ? ' — PHP intl is a deployment blocker.' : '')),
            'blocker' => true,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function database(): array
    {
        try {
            $db = db_connect();
            $db->query('SELECT 1');

            return [
                'id'      => 'database',
                'label'   => 'Database connectivity',
                'status'  => 'pass',
                'detail'  => 'Connected to configured database (credentials redacted).',
                'blocker' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'id'      => 'database',
                'label'   => 'Database connectivity',
                'status'  => 'fail',
                'detail'  => 'Database connection failed.',
                'blocker' => true,
            ];
        }
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function migrations(): array
    {
        try {
            $runner = service('migrations');
            $history = $runner->getHistory();
            $count = is_array($history) ? count($history) : 0;

            $requiredHints = [
                'usdt_deposit_requests',
                'usdt_payment_allocations',
                'blockchain_health_snapshots',
            ];
            $missingTables = [];
            $db = db_connect();
            foreach ($requiredHints as $table) {
                if (! $db->tableExists($table)) {
                    $missingTables[] = $table;
                }
            }

            if ($missingTables !== []) {
                return [
                    'id'      => 'migrations',
                    'label'   => 'Required migrations / tables',
                    'status'  => 'fail',
                    'detail'  => 'Missing tables: ' . implode(', ', $missingTables),
                    'blocker' => true,
                ];
            }

            return [
                'id'      => 'migrations',
                'label'   => 'Required migrations / tables',
                'status'  => 'pass',
                'detail'  => "Migration history entries: {$count}. Required payment tables present.",
                'blocker' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'id'      => 'migrations',
                'label'   => 'Required migrations / tables',
                'status'  => 'fail',
                'detail'  => 'Unable to inspect migrations/tables.',
                'blocker' => true,
            ];
        }
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function https(): array
    {
        $base = (string) config('App')->baseURL;
        $isHttps = str_starts_with(strtolower($base), 'https://');
        $isLocal = str_contains($base, 'localhost') || str_contains($base, '127.0.0.1');

        if ($isHttps) {
            return [
                'id' => 'https', 'label' => 'HTTPS baseURL', 'status' => 'pass',
                'detail' => 'baseURL uses HTTPS.', 'blocker' => false,
            ];
        }

        return [
            'id'      => 'https',
            'label'   => 'HTTPS baseURL',
            'status'  => $isLocal ? 'warn' : 'fail',
            'detail'  => $isLocal
                ? 'Local HTTP baseURL detected — acceptable for development only.'
                : 'Non-local baseURL is not HTTPS.',
            'blocker' => ! $isLocal,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function environment(): array
    {
        $env = strtolower((string) ENVIRONMENT);

        return [
            'id'      => 'environment',
            'label'   => 'CI environment',
            'status'  => $env === 'production' ? 'warn' : 'pass',
            'detail'  => 'ENVIRONMENT=' . ENVIRONMENT . ($env === 'production'
                ? ' — production mode detected; complete ops checklist before enabling live payments.'
                : ' — not claiming production readiness.'),
            'blocker' => false,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function canonicalUrlAndDebug(): array
    {
        $base    = rtrim((string) config('App')->baseURL, '/');
        $isLocal = str_contains($base, 'localhost') || str_contains($base, '127.0.0.1');
        $debugOn = defined('CI_DEBUG') && CI_DEBUG === true;
        $wrongDomain = str_contains(strtolower($base), 'ngw.in.net');

        if ($wrongDomain) {
            return [
                'id'      => 'canonical_url_and_debug',
                'label'   => 'Canonical baseURL & Debugbar',
                'status'  => 'fail',
                'detail'  => 'app.baseURL points to legacy/mismatched ngw.in.net instead of https://ngw.zodianwealth.info/.',
                'blocker' => true,
            ];
        }

        if (! $isLocal && $debugOn) {
            return [
                'id'      => 'canonical_url_and_debug',
                'label'   => 'Canonical baseURL & Debugbar',
                'status'  => 'fail',
                'detail'  => 'CI_DEBUG=true (Debugbar active) on non-local host. Set CI_ENVIRONMENT=production in .env.',
                'blocker' => true,
            ];
        }

        return [
            'id'      => 'canonical_url_and_debug',
            'label'   => 'Canonical baseURL & Debugbar',
            'status'  => ($debugOn || ENVIRONMENT !== 'production') ? 'warn' : 'pass',
            'detail'  => sprintf('ENVIRONMENT=%s CI_DEBUG=%s baseURL=%s', ENVIRONMENT, $debugOn ? 'true' : 'false', $base ?: '(empty)'),
            'blocker' => false,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function paymentGate(): array
    {
        $on = (bool) (config('Platform')->requireVerifiedDepositForApproval ?? false);

        return [
            'id'      => 'payment_gate',
            'label'   => 'Verified deposit approval gate',
            'status'  => $on ? 'pass' : 'fail',
            'detail'  => $on
                ? 'platform.requireVerifiedDepositForApproval=true'
                : 'Payment gate is disabled — unsafe for live payments.',
            'blocker' => true,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function blockchainConfig(): array
    {
        /** @var Blockchain $cfg */
        $cfg = config('Blockchain');
        $mode = strtolower((string) $cfg->depositVerifier);
        $rpc  = trim((string) $cfg->evmRpcUrl);

        if ($mode !== 'evm_erc20') {
            return [
                'id' => 'blockchain_config', 'label' => 'Trusted RPC / verifier config',
                'status' => 'warn',
                'detail' => 'depositVerifier is not evm_erc20 — live verification unavailable (expected until ops enables it).',
                'blocker' => false,
            ];
        }

        if ($rpc === '') {
            return [
                'id' => 'blockchain_config', 'label' => 'Trusted RPC / verifier config',
                'status' => 'fail',
                'detail' => 'evm_erc20 enabled but blockchain.evmRpcUrl is empty.',
                'blocker' => true,
            ];
        }

        $host = parse_url($rpc, PHP_URL_HOST);

        return [
            'id' => 'blockchain_config', 'label' => 'Trusted RPC / verifier config',
            'status' => 'pass',
            'detail' => 'Verifier=evm_erc20; RPC host present (' . ($host ?: 'redacted') . ') — URL/credentials not displayed.',
            'blocker' => true,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function networks(): array
    {
        $list = service('usdtPaymentNetworkService')->listAll();
        $evm  = array_values(array_filter(
            $list,
            static fn (array $n): bool => ($n['verifier_type'] ?? '') === 'evm_erc20'
        ));

        if ($evm === []) {
            return [
                'id' => 'networks', 'label' => 'EVM payment network row',
                'status' => 'warn',
                'detail' => 'No network with verifier_type=evm_erc20 configured.',
                'blocker' => false,
            ];
        }

        $n = $evm[0];
        $missing = [];
        foreach (['chain_id', 'token_contract_address', 'destination_wallet', 'token_decimals', 'required_confirmations'] as $field) {
            if ($n[$field] === null || $n[$field] === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return [
                'id' => 'networks', 'label' => 'EVM payment network row',
                'status' => 'fail',
                'detail' => 'EVM network missing: ' . implode(', ', $missing),
                'blocker' => true,
            ];
        }

        return [
            'id' => 'networks', 'label' => 'EVM payment network row',
            'status' => 'pass',
            'detail' => sprintf(
                'Network %s chain_id=%s confirmations=%s (wallet/contract present, values not printed in full).',
                $n['network_key'],
                $n['chain_id'],
                $n['required_confirmations']
            ),
            'blocker' => true,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function evidenceStorage(): array
    {
        $path = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'deposit_evidence';
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        $writable = is_dir($path) && is_writable($path);

        return [
            'id'      => 'evidence_storage',
            'label'   => 'Evidence storage permissions',
            'status'  => $writable ? 'pass' : 'fail',
            'detail'  => $writable
                ? 'writable/uploads/deposit_evidence is writable.'
                : 'Evidence upload directory is not writable.',
            'blocker' => true,
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string, blocker: bool}
     */
    private function schedulerNote(): array
    {
        return [
            'id'      => 'scheduler',
            'label'   => 'Background worker / scheduler',
            'status'  => 'warn',
            'detail'  => 'No mandatory cron worker in Phase 4C. Admins retry verification manually. Optional future: schedule integrity re-checks.',
            'blocker' => false,
        ];
    }
}
