<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Phase 4E unified finance overview — real DB counts only.
 */
class FinanceDashboardService
{
    /**
     * @return array<string, int|string|bool>
     */
    public function summary(): array
    {
        $db = db_connect();

        $openAlerts = (int) $db->table('financial_alerts')->where('status', 'open')->countAllResults();
        $pendingRem = (int) $db->table('financial_remediation_proposals')->where('status', 'proposed')->countAllResults();
        $reserved   = (int) $db->table('usdt_payment_allocations')->where('status', 'linked')->countAllResults();
        $consumed   = (int) $db->table('usdt_payment_allocations')->where('status', 'consumed')->countAllResults();
        $released   = (int) $db->table('usdt_payment_allocations')->where('status', 'released')->countAllResults();
        $integrity  = (int) $db->table('usdt_deposit_requests')
            ->whereIn('integrity_flag', ['reorg_suspected', 'needs_investigation'])
            ->countAllResults();

        $recon = service('paymentReconciliationService')->run();
        $notifFail = 0;
        if ($db->tableExists('notification_outbox')) {
            $notifFail = (int) $db->table('notification_outbox')->where('status', 'failed')->countAllResults();
        }

        return [
            'open_alerts'              => $openAlerts,
            'pending_investigations'   => $integrity,
            'reconciliation_issues'    => (int) ($recon['summary']['total_findings'] ?? 0),
            'reconciliation_critical'  => (int) ($recon['summary']['critical'] ?? 0),
            'allocations_reserved'     => $reserved,
            'allocations_consumed'     => $consumed,
            'allocations_released'     => $released,
            'pending_remediations'     => $pendingRem,
            'notification_failures'    => $notifFail,
            'notifications_configured' => service('financialNotificationService')->isDeliveryConfigured(),
            'generated_at'             => date('Y-m-d H:i:s'),
        ];
    }
}
