<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;

/**
 * Phase 11E read-only coin selling reconciliation. Never mutates financial data.
 */
class CoinSellingReconciliationService
{
    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{summary: array<string, int>, mismatches: list<array<string, mixed>>, note: string}
     */
    public function run(int $limit = 200): array
    {
        $scale      = ($this->config ?? config('Platform'))->coinQuantityScale;
        $mismatches = [];
        $db         = db_connect();

        if (! $db->tableExists('coin_sell_requests')) {
            return [
                'summary'    => ['mismatches' => 0],
                'mismatches' => [],
                'note'       => 'Coin sell tables missing.',
            ];
        }

        $rows = $db->table('coin_sell_requests')->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
        $biz  = service('miningEligibilityService')->businessDate();

        foreach ($rows as $row) {
            $ref = (string) $row['sell_ref'];
            $lock = $db->table('member_coin_locks')
                ->where('purchase_ref', (string) $row['purchase_ref'])
                ->get()->getRowArray();

            if ($lock !== null && ! service('coinSellingService')->isLotMaturedByBusinessDate($lock, $biz)
                && (string) $row['status'] !== CoinSellingService::STATUS_DRAFT
            ) {
                $mismatches[] = ['type' => 'immature_coin_sale', 'sell_ref' => $ref, 'purchase_ref' => $row['purchase_ref']];
            }

            if ((int) $row['is_reserved'] === 1) {
                $reserves = $db->table('member_coin_ownership_transactions')
                    ->where('sell_ref', $ref)
                    ->where('type', MemberCoinOwnershipService::TYPE_SALE_RESERVE)
                    ->countAllResults();
                if ($reserves < 1) {
                    $mismatches[] = ['type' => 'missing_sale_reservation_ledger', 'sell_ref' => $ref];
                }
                if ($reserves > 1) {
                    $mismatches[] = ['type' => 'duplicate_reservation', 'sell_ref' => $ref, 'count' => $reserves];
                }
            }

            if ((string) $row['status'] === CoinSellingService::STATUS_COMPLETED) {
                if ((int) $row['is_settled'] !== 1) {
                    $mismatches[] = ['type' => 'completed_without_settled_flag', 'sell_ref' => $ref];
                }
                if (trim((string) ($row['settlement_evidence_ref'] ?? '')) === '') {
                    $mismatches[] = ['type' => 'completed_without_verified_settlement', 'sell_ref' => $ref];
                }
                $debits = $db->table('member_coin_ownership_transactions')
                    ->where('sell_ref', $ref)
                    ->where('type', MemberCoinOwnershipService::TYPE_SALE_DEBIT)
                    ->countAllResults();
                if ($debits !== 1) {
                    $mismatches[] = ['type' => 'missing_ownership_debit', 'sell_ref' => $ref, 'debits' => $debits];
                }
            }
        }

        // Over-selling: sold+reserved > lot quantity
        $lots = $db->query(
            'SELECT purchase_ref, member_user_id, quantity FROM member_coin_locks WHERE purchase_ref IS NOT NULL AND purchase_ref != \'\''
        )->getResultArray();
        foreach ($lots as $lot) {
            $pref = (string) $lot['purchase_ref'];
            $sold = $db->table('coin_sell_requests')->selectSum('quantity', 'qty')
                ->where('purchase_ref', $pref)->where('status', CoinSellingService::STATUS_COMPLETED)
                ->get()->getRowArray();
            $res = $db->table('coin_sell_requests')->selectSum('quantity', 'qty')
                ->where('purchase_ref', $pref)->where('is_reserved', 1)
                ->whereIn('status', CoinSellingService::RESERVED_STATUSES)
                ->get()->getRowArray();
            $used = MoneyMath::add(
                MoneyMath::normalize((string) ($sold['qty'] ?? '0'), $scale),
                MoneyMath::normalize((string) ($res['qty'] ?? '0'), $scale),
                $scale
            );
            if (MoneyMath::compare($used, MoneyMath::normalize((string) $lot['quantity'], $scale), $scale) === 1) {
                // Note: lock quantity may already be reduced after partial unlock-for-sale; compare vs original via sold+reserved vs purchase credit.
                $credit = $db->table('member_coin_ownership_transactions')
                    ->selectSum('quantity', 'qty')
                    ->where('purchase_ref', $pref)
                    ->where('type', MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT)
                    ->get()->getRowArray();
                $orig = MoneyMath::normalize((string) ($credit['qty'] ?? $lot['quantity']), $scale);
                if (MoneyMath::compare($used, $orig, $scale) === 1) {
                    $mismatches[] = ['type' => 'over_selling', 'purchase_ref' => $pref, 'used' => $used, 'original' => $orig];
                }
            }
        }

        // Orphaned SALE_DEBIT without sell request
        $debits = $db->table('member_coin_ownership_transactions')
            ->where('type', MemberCoinOwnershipService::TYPE_SALE_DEBIT)
            ->limit($limit)
            ->get()->getResultArray();
        foreach ($debits as $d) {
            $sref = (string) ($d['sell_ref'] ?? '');
            if ($sref === '' || $db->table('coin_sell_requests')->where('sell_ref', $sref)->countAllResults() < 1) {
                $mismatches[] = ['type' => 'orphaned_sale_ledger', 'transaction_ref' => $d['transaction_ref'] ?? null];
            }
        }

        return [
            'summary' => [
                'rows'       => count($rows),
                'mismatches' => count($mismatches),
            ],
            'mismatches' => $mismatches,
            'note'       => 'Read-only. Coin selling execution remains OFF in Phase 11E.',
        ];
    }
}
