<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

/**
 * Phase 7A: member direct referrals and genealogy (read-only).
 */
class ReferralController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        try {
            $list = service('referralTeamService')->directReferrals($userId, [
                'status'   => $this->request->getGet('status'),
                'q'        => $this->request->getGet('q'),
                'per_page' => $this->request->getGet('per_page'),
                'page'     => $this->request->getGet('page'),
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/referrals'))->with('error', $e->getMessage());
        }

        $counts = service('referralTeamService')->countsForSponsor($userId, 3);
        $kpis   = [
            KpiCardFactory::ok('direct_total', 'Direct Referrals', $list['summary']['total'], 'purple', 'bi-person-plus-fill', 'members'),
            KpiCardFactory::ok('direct_active', 'Active Direct', $list['summary']['active'], 'green', 'bi-person-check-fill', 'members'),
            KpiCardFactory::ok('direct_inactive', 'Inactive Direct', $list['summary']['inactive'], 'orange', 'bi-person-x-fill', 'members'),
            KpiCardFactory::ok('team_total', 'Total Team', $counts['total'], 'blue', 'bi-people-fill', 'members', site_url('member/genealogy')),
        ];

        return view('member/referrals/index', [
            'title'        => 'My Referrals',
            'profile'      => $profile,
            'rows'         => $list['rows'],
            'pager'        => $list['pager'],
            'filters'      => $list['filters'],
            'summary'      => $list['summary'],
            'kpis'         => $kpis,
            'referralLink' => site_url('register?sponsor=' . urlencode($profile->member_id)),
        ]);
    }

    public function genealogy()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $depth = max(1, min(5, (int) ($this->request->getGet('depth') ?: 3)));
        $tree  = service('referralTeamService')->subtree($userId, $depth, 300);
        $counts = service('referralTeamService')->countsForSponsor($userId, 3);

        $kpis = [
            KpiCardFactory::ok('level1', 'Level 1', $counts['levels'][1]['total'] ?? 0, 'cyan', 'bi-1-circle', 'members'),
            KpiCardFactory::ok('level2', 'Level 2', $counts['levels'][2]['total'] ?? 0, 'indigo', 'bi-2-circle', 'members'),
            KpiCardFactory::ok('level3', 'Level 3', $counts['levels'][3]['total'] ?? 0, 'teal', 'bi-3-circle', 'members'),
            KpiCardFactory::ok('team_total', 'Total Team', $counts['total'], 'blue', 'bi-diagram-3', 'members'),
            KpiCardFactory::ok('team_active', 'Active Team', $counts['active'], 'green', 'bi-person-check', 'members'),
            KpiCardFactory::ok('team_inactive', 'Inactive Team', $counts['inactive'], 'orange', 'bi-person-dash', 'members'),
        ];

        return view('member/referrals/genealogy', [
            'title'   => 'My Genealogy',
            'profile' => $profile,
            'tree'    => $tree,
            'counts'  => $counts,
            'kpis'    => $kpis,
            'depth'   => $depth,
        ]);
    }

    public function children()
    {
        $userId = (int) auth()->id();
        $parent = (int) $this->request->getGet('parent_user_id');

        try {
            $children = service('referralTeamService')->childrenOf($userId, $parent, 100);
        } catch (InvalidArgumentException $e) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $e->getMessage()]);
        }

        return $this->response->setJSON(['children' => $children]);
    }

    /**
     * Phase 2 — Level-wise referral Business Volume (read-only).
     */
    public function business()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $level = max(1, min(3, (int) ($this->request->getGet('level') ?: 1)));
        $filters = [
            'q'         => $this->request->getGet('q'),
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
            'per_page'  => $this->request->getGet('per_page'),
            'page'      => $this->request->getGet('page'),
        ];

        try {
            $summary = service('referralBusinessVolumeService')->summary($userId, $filters);
            $list    = service('referralBusinessVolumeService')->membersAtLevel($userId, $level, $filters);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/referrals/business'))->with('error', $e->getMessage());
        }

        $kpis = [
            KpiCardFactory::ok(
                'total_bv',
                'Total Team Business Volume',
                $summary['total_business_volume_usdt'],
                'gold',
                'bi-graph-up-arrow',
                'usdt'
            ),
            KpiCardFactory::ok('l1_bv', 'Level 1 Volume', $summary['levels'][1]['business_volume_usdt'], 'cyan', 'bi-1-circle', 'usdt'),
            KpiCardFactory::ok('l2_bv', 'Level 2 Volume', $summary['levels'][2]['business_volume_usdt'], 'indigo', 'bi-2-circle', 'usdt'),
            KpiCardFactory::ok('l3_bv', 'Level 3 Volume', $summary['levels'][3]['business_volume_usdt'], 'teal', 'bi-3-circle', 'usdt'),
            KpiCardFactory::ok('net', 'Network (3 levels)', $summary['network_member_count'], 'blue', 'bi-people', 'members'),
        ];

        return view('member/referrals/business', [
            'title'    => 'Referral Business Volume',
            'profile'  => $profile,
            'summary'  => $summary,
            'list'     => $list,
            'level'    => $level,
            'kpis'     => $kpis,
            'filters'  => $list['filters'],
        ]);
    }

    public function businessMember(string $memberId)
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $filters = [
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
            'per_page'  => $this->request->getGet('per_page'),
            'page'      => $this->request->getGet('page'),
        ];

        try {
            $detail = service('referralBusinessVolumeService')->memberPurchases($userId, $memberId, $filters);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/referrals/business'))->with('error', $e->getMessage());
        }

        return view('member/referrals/business_member', [
            'title'   => 'Referral Business Detail',
            'profile' => $profile,
            'detail'  => $detail,
            'filters' => $detail['filters'],
        ]);
    }
}
