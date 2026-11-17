<?php

declare(strict_types=1);

namespace App\Services;

use Config\Database;
use RuntimeException;

/**
 * Phase 12B — prove staging isolation before any financial execution.
 */
class StagingIsolationService
{
    public const STAGING_DB_GROUP = 'phase12b';

    public const STAGING_DATABASE = 'coin_platform_phase12b';

    /**
     * @return array{
     *   ok: bool,
     *   checks: list<array{name: string, ok: bool, detail: string}>,
     *   staging: array<string, mixed>,
     *   production_baseline: array<string, mixed>
     * }
     */
    public function proveIsolation(): array
    {
        $checks = [];

        $staging = service('financialSandboxService')->inspectConnection(self::STAGING_DB_GROUP);
        $checks[] = [
            'name'   => 'staging_db_allowlisted',
            'ok'     => $staging['is_allowed'] && $staging['database'] === self::STAGING_DATABASE,
            'detail' => 'group=' . $staging['group'] . ' database=' . $staging['database'],
        ];

        $prod = service('financialSandboxService')->inspectConnection('default');
        $checks[] = [
            'name'   => 'default_is_not_staging',
            'ok'     => $prod['database'] !== self::STAGING_DATABASE,
            'detail' => 'default database=' . $prod['database'],
        ];
        $checks[] = [
            'name'   => 'production_db_forbidden_for_sandbox',
            'ok'     => $prod['is_forbidden'] || $prod['database'] === 'coin_platform',
            'detail' => 'default database="' . $prod['database'] . '" must remain untouched',
        ];

        $sessionCookie = (string) config('Session')->cookieName;
        $checks[] = [
            'name'   => 'session_cookie_documented',
            'ok'     => $sessionCookie !== '',
            'detail' => 'session.cookieName=' . $sessionCookie . ' (staging CLI uses isolated DB; web staging must override to ngw_phase12b_session)',
        ];

        $blockchain = (string) env('blockchain.depositVerifier', 'not_configured');
        $checks[] = [
            'name'   => 'blockchain_verifier_not_live_payout',
            'ok'     => in_array($blockchain, ['not_configured', 'mock', ''], true)
                || ! (bool) config('Platform')->commissionBlockchainPayoutEnabled,
            'detail' => 'blockchain.depositVerifier=' . $blockchain
                . '; commissionBlockchainPayoutEnabled='
                . ((bool) config('Platform')->commissionBlockchainPayoutEnabled ? 'true' : 'false'),
        ];

        $emailEnabled = filter_var(env('financeNotifications.emailEnabled', false), FILTER_VALIDATE_BOOLEAN);
        $checks[] = [
            'name'   => 'finance_email_off',
            'ok'     => ! $emailEnabled,
            'detail' => 'financeNotifications.emailEnabled=' . ($emailEnabled ? 'true' : 'false'),
        ];

        $switches = service('financialSandboxService')->productionSwitchSnapshot();
        $allOff = empty($switches['miningPostingEnabled'])
            && empty($switches['miningP2pEnabled'])
            && empty($switches['commissionPostingEnabled'])
            && empty($switches['commissionWithdrawalEnabled'])
            && empty($switches['commissionBlockchainPayoutEnabled'])
            && empty($switches['coinSellingEnabled']);
        $checks[] = [
            'name'   => 'production_kill_switches_off',
            'ok'     => $allOff,
            'detail' => json_encode($switches, JSON_UNESCAPED_SLASHES),
        ];

        $ok = true;
        foreach ($checks as $c) {
            if (! $c['ok']) {
                $ok = false;
            }
        }

        return [
            'ok'                   => $ok,
            'checks'               => $checks,
            'staging'              => $staging,
            'production_baseline'  => $this->readOnlyProductionBaseline(),
            'staging_assumptions'  => [
                'member_id_start'        => 1234,
                'member_id_padding'      => 4,
                'member_id_prefix'       => 'NGW',
                'label'                  => 'PHASE12B_STAGING_ONLY',
                'mining_divisor'         => 'calendar_days',
                'mining_asset'           => 'COIN',
                'commission_base'        => 'approved_purchase_total_usdt',
                'commission_rounding'    => 'bcmath_truncate_toward_zero',
                'note'                   => 'These assumptions must not be copied into production .env without business approval.',
            ],
        ];
    }

    public function assertOrFail(): array
    {
        $proof = $this->proveIsolation();
        if (! $proof['ok']) {
            $failed = array_values(array_filter($proof['checks'], static fn ($c) => ! $c['ok']));
            throw new RuntimeException(
                'Phase 12B staging isolation failed: ' . json_encode($failed, JSON_UNESCAPED_SLASHES)
            );
        }

        return $proof;
    }

    /**
     * Read-only snapshot of live default DB — never mutate.
     *
     * @return array<string, mixed>
     */
    public function readOnlyProductionBaseline(): array
    {
        $db = Database::connect('default', false);
        $out = [
            'database' => (string) ($db->database ?? config('Database')->default['database'] ?? ''),
            'note'     => 'read-only baseline; no mutations',
        ];

        try {
            if ($db->tableExists('member_profiles')) {
                $out['member_profiles'] = (int) $db->table('member_profiles')->countAllResults();
            }
            if ($db->tableExists('member_coin_balances')) {
                $out['member_coin_balances'] = (int) $db->table('member_coin_balances')->countAllResults();
            }
            if ($db->tableExists('member_coin_ownership_transactions')) {
                $out['ownership_ledger_rows'] = (int) $db->table('member_coin_ownership_transactions')->countAllResults();
            }
            if ($db->tableExists('coin_inventory')) {
                $out['inventory'] = $db->table('coin_inventory')->get()->getRowArray();
            }
            if ($db->tableExists('member_id_sequences')) {
                $out['member_id_sequences'] = $db->table('member_id_sequences')->get()->getResultArray();
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * Apply in-process staging isolation markers (CLI E2E). Does not rewrite production .env.
     *
     * @return array<string, string>
     */
    public function applyCliStagingMarkers(string $runId): array
    {
        $markers = [
            'run_id'              => $runId,
            'session_cookie'      => 'ngw_phase12b_session',
            'csrf_cookie'         => 'ngw_phase12b_csrf',
            'cache_prefix'        => 'p12b_' . $runId . '_',
            'log_path_hint'       => WRITEPATH . 'logs/phase12b/',
            'base_url_hint'       => 'http://localhost:8081/',
            'email_delivery'      => 'fake/disabled',
            'blockchain_provider' => 'mock/not_configured',
        ];

        // Soft overrides for this process only.
        config('Session')->cookieName = $markers['session_cookie'];
        config('Security')->cookieName = $markers['csrf_cookie'];
        if (is_dir(WRITEPATH . 'logs') && ! is_dir(WRITEPATH . 'logs/phase12b')) {
            @mkdir(WRITEPATH . 'logs/phase12b', 0775, true);
        }

        return $markers;
    }
}
