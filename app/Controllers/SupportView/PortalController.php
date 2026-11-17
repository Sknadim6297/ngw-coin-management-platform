<?php

declare(strict_types=1);

namespace App\Controllers\SupportView;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use App\Models\MemberWalletChangeEventModel;
use App\Models\SponsorRelationshipModel;
use App\Models\UsdtDepositRequestModel;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Read-only member portal under /support-view (Phase 10D).
 * Uses impersonation target user id — never swaps Shield session.
 */
class PortalController extends BaseController
{
    /**
     * @return array<string, mixed>
     */
    private function ctx(): array
    {
        $ctx = service('impersonationService')->activeContext();
        if ($ctx === null) {
            throw new \RuntimeException('Support view context missing.');
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $view, array $data)
    {
        $ctx = $this->ctx();

        return view($view, array_merge($data, [
            'supportViewMode'    => true,
            'supportViewContext' => $ctx,
            'panelBase'          => 'support-view',
        ]));
    }

    public function dashboard()
    {
        $ctx    = $this->ctx();
        $userId = (int) $ctx['target_user_id'];
        service('auditLogger')->log(
            'admin.impersonation_member_viewed',
            (int) $ctx['admin_user_id'],
            'impersonation_session',
            (string) $ctx['session_ref'],
            ['target_member_id' => $ctx['target_member_id'], 'page' => 'dashboard']
        );

        try {
            $dashboard = service('memberDashboardService')->build($userId);
        } catch (\RuntimeException $e) {
            return redirect()->to(site_url('support-view/ended'))->with('error', 'Member profile not found.');
        }

        return $this->render('member/dashboard/index', [
            'title'           => 'Member Dashboard',
            'kpis'            => $dashboard['kpis'],
            'charts'          => $dashboard['charts'],
            'profile'         => $dashboard['profile'],
            'activation'      => $dashboard['activation'],
            'walletSummary'   => $dashboard['wallet_summary'],
            'pendingPayments' => $dashboard['pending_payments'],
            'recentPurchases' => $dashboard['recent_purchases'],
            'referralSummary' => $dashboard['referral_summary'],
            'referralLink'    => $dashboard['referral_link'],
            'priceSummary'    => $dashboard['price_summary'],
            'inventory'       => $dashboard['inventory'],
            'activeLocks'     => $dashboard['active_locks'],
            'maturedLocks'    => $dashboard['matured_locks'],
            'recentLocks'     => $dashboard['recent_locks'],
            'recentWallet'    => $dashboard['recent_wallet'],
            'timezone'        => $dashboard['timezone'],
            'todayStart'      => $dashboard['today_start'],
            'todayEnd'        => $dashboard['today_end'],
            'generatedAt'     => $dashboard['generated_at'],
        ]);
    }

    public function profile()
    {
        $userId  = (int) $this->ctx()['target_user_id'];
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('support-view/ended'))->with('error', 'Member profile not found.');
        }

        $user = model(UserModel::class)->findById($userId);

        return $this->render('member/profile/index', [
            'title'           => 'My Profile',
            'profile'         => $profile,
            'relationship'    => model(SponsorRelationshipModel::class)->findByMemberUserId($userId),
            'email'           => $user?->getEmail(),
            'activation'      => service('memberActivationService')->statusForMember($userId),
            'completion'      => $profile->profileCompletionPercent(),
            'walletEvents'    => model(MemberWalletChangeEventModel::class)->recentForUser($userId, 5),
            'walletCooldownUntil' => service('memberProfileService')->walletCooldownUntil($userId),
            'supportReadOnly' => true,
        ]);
    }

    public function purchases()
    {
        $userId = (int) $this->ctx()['target_user_id'];
        $status = trim((string) $this->request->getGet('status'));
        $model  = model(CoinPurchaseRequestModel::class);
        $rows   = $model->forMember($userId, $status !== '' ? $status : null, 20);

        return $this->render('member/purchases/history', [
            'title'  => 'Purchase History',
            'rows'   => $rows,
            'pager'  => $model->pager,
            'status' => $status,
        ]);
    }

    public function purchaseShow(string $purchaseRef)
    {
        $userId = (int) $this->ctx()['target_user_id'];
        $row = service('coinPurchaseService')->findOwnedByMember($userId, $purchaseRef);
        if ($row === null) {
            return redirect()->to(site_url('support-view/purchases'))->with('error', 'Purchase not found for this member.');
        }
        $allocation = service('usdtPaymentAllocationService')->findForPurchase($purchaseRef);

        return $this->render('member/purchases/show', [
            'title'            => 'Purchase Details',
            'request'          => $row,
            'availableDeposit' => null,
            'allocation'       => $allocation,
            'linkedDeposit'    => null,
            'supportReadOnly'  => true,
        ]);
    }

    public function deposits()
    {
        $userId = (int) $this->ctx()['target_user_id'];
        $status = trim((string) $this->request->getGet('status'));
        $allowed = ['draft', 'submitted', 'under_review', 'verified', 'rejected', 'cancelled', ''];
        if (! in_array($status, $allowed, true)) {
            $status = '';
        }

        $model = model(UsdtDepositRequestModel::class);
        $rows  = $model->forMember($userId, $status !== '' ? $status : null, 20);

        return $this->render('member/deposits/index', [
            'title'  => 'Payment History',
            'rows'   => $rows,
            'pager'  => $model->pager,
            'status' => $status,
        ]);
    }

    public function wallet()
    {
        $userId = (int) $this->ctx()['target_user_id'];

        return $this->render('member/wallet/index', [
            'title'   => 'My Wallet',
            'tab'     => 'overview',
            'wallet'  => service('memberWalletService')->overview($userId),
            'profile' => model(MemberProfileModel::class)->findByUserId($userId),
        ]);
    }

    public function locking()
    {
        $userId  = (int) $this->ctx()['target_user_id'];
        $summary = service('memberCoinOwnershipService')->summaryForUser($userId);
        $plans   = service('coinLockingService')->listPlans(true);
        $locks   = service('coinLockingService')->memberDashboardSnapshot($userId);

        return $this->render('member/locking/index', [
            'title'          => 'Coin Locking',
            'summary'        => $summary,
            'plans'          => $plans,
            'active'         => $locks['active'],
            'matured'        => $locks['matured'],
            'recent'         => $locks['recent'],
            'idempotencyKey' => '',
            'supportReadOnly'=> true,
        ]);
    }

    public function referrals()
    {
        $userId  = (int) $this->ctx()['target_user_id'];
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        $list    = service('referralTeamService')->directReferrals($userId, [
            'status'   => $this->request->getGet('status'),
            'q'        => $this->request->getGet('q'),
            'per_page' => $this->request->getGet('per_page'),
            'page'     => $this->request->getGet('page'),
        ]);
        $counts = service('referralTeamService')->countsForSponsor($userId, 3);
        $kpis   = [
            KpiCardFactory::ok('direct_total', 'Direct Referrals', $list['summary']['total'], 'purple', 'bi-person-plus-fill', 'members'),
            KpiCardFactory::ok('direct_active', 'Active Direct', $list['summary']['active'], 'green', 'bi-person-check-fill', 'members'),
            KpiCardFactory::ok('direct_inactive', 'Inactive Direct', $list['summary']['inactive'], 'orange', 'bi-person-x-fill', 'members'),
            KpiCardFactory::ok('team_total', 'Total Team', $counts['total'], 'blue', 'bi-people-fill', 'members'),
        ];

        return $this->render('member/referrals/index', [
            'title'        => 'My Referrals',
            'profile'      => $profile,
            'rows'         => $list['rows'],
            'pager'        => $list['pager'],
            'filters'      => $list['filters'],
            'summary'      => $list['summary'],
            'kpis'         => $kpis,
            'referralLink' => site_url('register?sponsor=' . urlencode((string) $profile->member_id)),
        ]);
    }

    public function genealogy()
    {
        $userId  = (int) $this->ctx()['target_user_id'];
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        $depth   = 3;
        $tree    = service('referralTeamService')->subtree($userId, $depth, 300);
        $counts  = service('referralTeamService')->countsForSponsor($userId, 3);
        $kpis    = [
            KpiCardFactory::ok('level1', 'Level 1', $counts['levels'][1]['total'] ?? 0, 'cyan', 'bi-1-circle', 'members'),
            KpiCardFactory::ok('level2', 'Level 2', $counts['levels'][2]['total'] ?? 0, 'indigo', 'bi-2-circle', 'members'),
            KpiCardFactory::ok('level3', 'Level 3', $counts['levels'][3]['total'] ?? 0, 'teal', 'bi-3-circle', 'members'),
            KpiCardFactory::ok('team_total', 'Total Team', $counts['total'], 'blue', 'bi-diagram-3', 'members'),
        ];

        return $this->render('member/referrals/genealogy', [
            'title'   => 'Genealogy',
            'profile' => $profile,
            'tree'    => $tree,
            'counts'  => $counts,
            'kpis'    => $kpis,
            'depth'   => $depth,
        ]);
    }

    public function mining()
    {
        $userId = (int) $this->ctx()['target_user_id'];
        $today  = service('miningEligibilityService')->businessDate();
        $rate   = service('miningRateService')->approvedForDate($today);
        $eval   = service('miningEligibilityService')->evaluate($userId, $today, $rate);
        $expected = '0.00000000';
        if ($rate !== null && ! empty($eval['eligible'])) {
            $expected = service('miningRateService')->previewAmount((string) $eval['eligible_coins'], $rate, $today);
        }
        $wallet = service('miningWalletService')->balanceFor($userId);

        $kpis = [
            KpiCardFactory::ok('owned', 'Total Owned Coins', $eval['total_owned'], 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('eligible', 'Eligible Mining Coins', $eval['eligible_coins'], 'cyan', 'bi-check2-circle', 'coins'),
            KpiCardFactory::ok('rate', 'Approved Monthly Rate', $rate['rate_percent'] ?? 'Not configured', 'teal', 'bi-percent', 'rates'),
            KpiCardFactory::ok('today_preview', 'Expected Daily Mining', $expected, 'indigo', 'bi-lightning-charge', 'coins'),
            KpiCardFactory::unavailable('actual', 'Posting Status', 'gold', 'bi-safe', 'Mining Preview — Actual Credits Not Activated'),
        ];

        return $this->render('member/mining/index', [
            'title'    => 'Mining',
            'profile'  => model(MemberProfileModel::class)->findByUserId($userId),
            'kpis'     => $kpis,
            'eval'     => $eval,
            'rate'     => $rate,
            'today'    => $today,
            'expected' => $expected,
            'wallet'   => $wallet,
            'ledger'   => [],
        ]);
    }

    public function commissions()
    {
        $userId  = (int) $this->ctx()['target_user_id'];
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        $snap    = service('commissionSimulationService')->memberSnapshot($userId);
        $team    = service('referralTeamService')->countsForSponsor($userId);
        $kpis    = [
            KpiCardFactory::ok('direct', 'My Direct Referrals', $team['direct'], 'purple', 'bi-person-plus', 'members'),
            KpiCardFactory::ok('l1', 'Level 1 Commission Preview', $snap['level1_preview'], 'indigo', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('l2', 'Level 2 Commission Preview', $snap['level2_preview'], 'blue', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('l3', 'Level 3 Commission Preview', $snap['level3_preview'], 'teal', 'bi-cash', 'usdt'),
            KpiCardFactory::unavailable('actual', 'Actual Commission', 'gold', 'bi-safe', $snap['actual_status']),
        ];

        return $this->render('member/commissions/index', [
            'title'   => 'Commission Simulation',
            'profile' => $profile,
            'kpis'    => $kpis,
            'snap'    => $snap,
            'team'    => $team,
            'recent'  => service('commissionSimulationService')->historyForBeneficiary($userId, 15),
        ]);
    }

    public function support()
    {
        $userId = (int) $this->ctx()['target_user_id'];
        $list   = service('supportTicketService')->memberList($userId);

        return $this->render('member/support/index', [
            'title'           => 'Support History',
            'rows'            => $list['rows'],
            'total'           => $list['total'],
            'categories'      => \App\Services\SupportTicketService::CATEGORIES,
            'statuses'        => \App\Services\SupportTicketService::STATUSES,
            'supportReadOnly' => true,
        ]);
    }

    public function blocked()
    {
        return redirect()->to(site_url('support-view/dashboard'))
            ->with('error', 'Read-only Support Mode — that action is blocked.');
    }
}
