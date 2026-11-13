<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Blockchain\JsonRpcClient;
use App\Models\BlockchainHealthSnapshotModel;
use App\Models\UsdtDepositRequestModel;
use Config\Blockchain;
use RuntimeException;

/**
 * Phase 4C: payment/RPC health probes and admin dashboard metrics.
 * Never exposes RPC credentials or full URLs with secrets.
 */
class PaymentHealthService
{
    public function __construct(
        private readonly ?Blockchain $blockchain = null,
        private readonly ?JsonRpcClient $rpc = null,
        private readonly ?BlockchainHealthSnapshotModel $snapshots = null,
        private readonly ?UsdtDepositRequestModel $deposits = null,
        private readonly ?UsdtPaymentNetworkService $networks = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?int $checkedBy = null, bool $probeLive = false): array
    {
        $cfg = $this->blockchain ?? config('Blockchain');
        $latest = ($this->snapshots ?? model(BlockchainHealthSnapshotModel::class))->latest('global');

        if ($probeLive) {
            $latest = $this->probeAndStore($checkedBy);
        }

        $metrics = $this->verificationMetrics();

        return [
            'rpc_configured'           => $this->isRpcConfigured($cfg),
            'deposit_verifier'         => (string) ($cfg->depositVerifier ?? 'not_configured'),
            'rpc_host_redacted'        => $this->redactedHostFromConfig($cfg),
            'rpc_connectivity_status'  => $latest['status'] ?? 'unavailable',
            'chain_id_expected'        => $latest['expected_chain_id'] ?? $this->primaryExpectedChainId(),
            'chain_id_reported'        => $latest['reported_chain_id'] ?? null,
            'chain_id_consistent'      => $this->chainConsistent($latest),
            'latest_verified_block'    => $latest['head_block_number'] ?? null,
            'last_health_check_at'     => $latest['checked_at'] ?? null,
            'last_health_latency_ms'   => $latest['latency_ms'] ?? null,
            'last_health_error'        => $latest['error_summary'] ?? null,
            'last_health_error_category' => $latest['error_category'] ?? null,
            'pending_deposits'         => $metrics['pending_deposits'],
            'awaiting_confirmations'   => $metrics['awaiting_confirmations'],
            'verification_failures_24h'=> $metrics['verification_failures_24h'],
            'error_categories'         => $metrics['error_categories'],
            'avg_verification_latency_ms' => $metrics['avg_verification_latency_ms'],
            'last_successful_verification_at' => $metrics['last_successful_verification_at'],
            'integrity_flags'          => $metrics['integrity_flags'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function probeAndStore(?int $checkedBy = null): array
    {
        $cfg      = $this->blockchain ?? config('Blockchain');
        $now      = date('Y-m-d H:i:s');
        $snapshot = [
            'scope'                => 'global',
            'network_id'           => null,
            'rpc_host_fingerprint' => null,
            'rpc_host_redacted'    => $this->redactedHostFromConfig($cfg),
            'expected_chain_id'    => $this->primaryExpectedChainId(),
            'reported_chain_id'    => null,
            'head_block_number'    => null,
            'latency_ms'           => null,
            'status'               => 'unavailable',
            'error_category'       => null,
            'error_summary'        => null,
            'checked_by'           => $checkedBy,
            'checked_at'           => $now,
            'created_at'           => $now,
        ];

        if (! $this->isRpcConfigured($cfg)) {
            $snapshot['status']         = 'not_configured';
            $snapshot['error_category'] = 'not_configured';
            $snapshot['error_summary']  = 'Trusted RPC URL or EVM verifier is not configured.';
            ($this->snapshots ?? model(BlockchainHealthSnapshotModel::class))->insert($snapshot);

            return $snapshot;
        }

        $url = trim((string) $cfg->evmRpcUrl);
        $hostInfo = $this->fingerprintHost($url);
        $snapshot['rpc_host_fingerprint'] = $hostInfo['fingerprint'];
        $snapshot['rpc_host_redacted']    = $hostInfo['redacted'];

        $client = $this->rpc ?? service('jsonRpcClient');
        $started = (int) (hrtime(true) / 1_000_000);

        try {
            $chainHex = $client->call('eth_chainId');
            $blockHex = $client->call('eth_blockNumber');
            $ended    = (int) (hrtime(true) / 1_000_000);

            $reported = $this->hexToInt((string) $chainHex);
            $head     = $this->hexToInt((string) $blockHex);
            $expected = $snapshot['expected_chain_id'];

            $snapshot['latency_ms']        = max(0, $ended - $started);
            $snapshot['reported_chain_id'] = $reported;
            $snapshot['head_block_number'] = $head;

            if ($expected !== null && $reported !== $expected) {
                $snapshot['status']         = 'wrong_chain';
                $snapshot['error_category'] = 'wrong_network';
                $snapshot['error_summary']  = 'RPC chain ID does not match configured network chain ID.';
            } else {
                $snapshot['status'] = 'ok';
            }
        } catch (RuntimeException $e) {
            $ended = (int) (hrtime(true) / 1_000_000);
            $snapshot['latency_ms']     = max(0, $ended - $started);
            $snapshot['status']         = 'down';
            $snapshot['error_category'] = 'provider_unavailable';
            $snapshot['error_summary']  = $this->sanitizeError($e->getMessage());
        } catch (\Throwable $e) {
            $snapshot['status']         = 'down';
            $snapshot['error_category'] = 'provider_error';
            $snapshot['error_summary']  = $this->sanitizeError($e->getMessage());
        }

        ($this->snapshots ?? model(BlockchainHealthSnapshotModel::class))->insert($snapshot);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function verificationMetrics(): array
    {
        $db = db_connect();

        $pending = (int) $db->table('usdt_deposit_requests')
            ->whereIn('status', ['submitted', 'under_review'])
            ->countAllResults();

        $awaiting = (int) $db->table('usdt_deposit_requests')
            ->whereIn('status', ['submitted', 'under_review'])
            ->whereIn('last_verification_status', [
                'pending_confirmations',
                'insufficient_confirmations',
                'tx_not_found',
            ])
            ->countAllResults();

        $since = date('Y-m-d H:i:s', time() - 86400);
        $failRows = $db->table('usdt_deposit_requests')
            ->select('last_verification_status, COUNT(*) AS cnt')
            ->where('last_verification_at >=', $since)
            ->where('last_verification_status IS NOT NULL')
            ->where('last_verification_status !=', 'verified')
            ->groupBy('last_verification_status')
            ->get()
            ->getResultArray();

        $categories = [];
        $failTotal  = 0;
        foreach ($failRows as $row) {
            $key = (string) ($row['last_verification_status'] ?? 'unknown');
            $cnt = (int) ($row['cnt'] ?? 0);
            $categories[$key] = $cnt;
            $failTotal += $cnt;
        }

        $avgLatency = null; // latency is recorded on health snapshots, not per deposit
        $avgRow = $db->table('blockchain_health_snapshots')
            ->selectAvg('latency_ms', 'avg_ms')
            ->where('checked_at >=', $since)
            ->where('latency_ms IS NOT NULL')
            ->get()
            ->getRowArray();
        if ($avgRow !== null && $avgRow['avg_ms'] !== null) {
            $avgLatency = (int) round((float) $avgRow['avg_ms']);
        }

        $lastOk = $db->table('usdt_deposit_requests')
            ->select('verified_at')
            ->where('status', 'verified')
            ->where('verified_at IS NOT NULL')
            ->orderBy('verified_at', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $integrity = $db->table('usdt_deposit_requests')
            ->select('integrity_flag, COUNT(*) AS cnt')
            ->where('integrity_flag IS NOT NULL')
            ->groupBy('integrity_flag')
            ->get()
            ->getResultArray();
        $integrityMap = [];
        foreach ($integrity as $row) {
            $integrityMap[(string) $row['integrity_flag']] = (int) $row['cnt'];
        }

        return [
            'pending_deposits'                => $pending,
            'awaiting_confirmations'          => $awaiting,
            'verification_failures_24h'       => $failTotal,
            'error_categories'                => $categories,
            'avg_verification_latency_ms'     => $avgLatency,
            'last_successful_verification_at' => $lastOk['verified_at'] ?? null,
            'integrity_flags'                 => $integrityMap,
        ];
    }

    private function isRpcConfigured(Blockchain $cfg): bool
    {
        return strtolower((string) $cfg->depositVerifier) === 'evm_erc20'
            && trim((string) $cfg->evmRpcUrl) !== '';
    }

    private function primaryExpectedChainId(): ?int
    {
        $networks = $this->networks ?? service('usdtPaymentNetworkService');
        foreach ($networks->listAll() as $network) {
            if (($network['verifier_type'] ?? '') === 'evm_erc20' && ! empty($network['chain_id'])) {
                return (int) $network['chain_id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $latest
     */
    private function chainConsistent(?array $latest): ?bool
    {
        if ($latest === null) {
            return null;
        }
        if (($latest['status'] ?? '') === 'not_configured') {
            return null;
        }
        if ($latest['expected_chain_id'] === null || $latest['reported_chain_id'] === null) {
            return null;
        }

        return (int) $latest['expected_chain_id'] === (int) $latest['reported_chain_id'];
    }

    private function redactedHostFromConfig(Blockchain $cfg): ?string
    {
        $url = trim((string) $cfg->evmRpcUrl);
        if ($url === '') {
            return null;
        }

        return $this->fingerprintHost($url)['redacted'];
    }

    /**
     * @return array{fingerprint: string, redacted: string}
     */
    private function fingerprintHost(string $url): array
    {
        $parts = parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? 'unknown'));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port  = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return [
            'fingerprint' => hash('sha256', $host),
            'redacted'    => $scheme . '://' . $host . $port . '/***',
        ];
    }

    private function hexToInt(string $hex): int
    {
        $hex = strtolower(trim($hex));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if ($hex === '' || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            throw new RuntimeException('Invalid hex integer from RPC.');
        }

        return (int) hexdec($hex);
    }

    private function sanitizeError(string $message): string
    {
        // Strip URLs / query tokens that may contain credentials.
        $clean = preg_replace('#https?://\S+#i', '[redacted-url]', $message) ?? $message;
        $clean = preg_replace('/(api[_-]?key|token|secret|password)=[^\s&]+/i', '$1=[redacted]', $clean) ?? $clean;

        return mb_substr($clean, 0, 240);
    }
}
