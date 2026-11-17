<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

/**
 * Phase 7A: admin read-only referral & genealogy inspection.
 */
class ReferralController extends BaseController
{
    public function index()
    {
        $stats    = service('referralTeamService')->platformStats();
        $integrity = service('genealogyIntegrityService')->issueCounts(50);
        $q        = trim((string) $this->request->getGet('q'));
        $result   = null;
        $error    = null;

        if ($q !== '') {
            $profile = model(MemberProfileModel::class)->findByMemberId(strtoupper($q));
            if ($profile === null) {
                // Fuzzy name search — first match only for overview jump.
                $row = db_connect()->table('member_profiles')
                    ->groupStart()
                        ->like('member_id', $q)
                        ->orLike('member_name', $q)
                    ->groupEnd()
                    ->orderBy('member_id', 'ASC')
                    ->get()
                    ->getRowArray();
                if ($row !== null) {
                    $profile = model(MemberProfileModel::class)->findByMemberId((string) $row['member_id']);
                }
            }
            if ($profile === null) {
                $error = 'No member matched that search.';
            } else {
                $counts = service('referralTeamService')->countsForSponsor((int) $profile->user_id, 3);
                $result = ['profile' => $profile, 'counts' => $counts];
            }
        }

        $kpis = [
            KpiCardFactory::ok('total_members', 'Total Members', $stats['total_members'], 'blue', 'bi-people-fill', 'members', site_url('admin/members')),
            KpiCardFactory::ok('active_members', 'Active Members', $stats['active_members'], 'green', 'bi-person-check-fill', 'members'),
            KpiCardFactory::ok('inactive_members', 'Inactive Members', $stats['inactive_members'], 'orange', 'bi-person-x-fill', 'members'),
            KpiCardFactory::ok('relationships', 'Direct Referral Relationships', $stats['total_relationships'], 'purple', 'bi-diagram-2', 'edges'),
            KpiCardFactory::ok('with_sponsors', 'Members With Sponsors', $stats['members_with_sponsors'], 'cyan', 'bi-link-45deg', 'members'),
            KpiCardFactory::ok('without_sponsors', 'Members Without Sponsors', $stats['members_without_sponsors'], 'amber', 'bi-exclamation-circle', 'members'),
            KpiCardFactory::ok('integrity_alerts', 'Network Integrity Alerts', $integrity['total'], 'red', 'bi-shield-exclamation', 'issues', site_url('admin/genealogy')),
        ];

        return view('admin/referrals/index', [
            'title'  => 'Referral Management',
            'kpis'   => $kpis,
            'q'      => $q,
            'result' => $result,
            'error'  => $error,
        ]);
    }

    public function show(string $memberId)
    {
        $profile = model(MemberProfileModel::class)->findByMemberId($memberId);
        if ($profile === null) {
            return redirect()->to(site_url('admin/referrals'))->with('error', 'Member not found.');
        }

        try {
            $list = service('referralTeamService')->directReferrals((int) $profile->user_id, [
                'status'   => $this->request->getGet('status'),
                'q'        => $this->request->getGet('q'),
                'per_page' => $this->request->getGet('per_page') ?: 25,
                'page'     => $this->request->getGet('page'),
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/referrals/' . rawurlencode($memberId)))
                ->with('error', $e->getMessage());
        }

        $counts = service('referralTeamService')->countsForSponsor((int) $profile->user_id, 3);
        $rel    = model(\App\Models\SponsorRelationshipModel::class)->findByMemberUserId((int) $profile->user_id);

        return view('admin/referrals/show', [
            'title'   => 'Member Referrals',
            'profile' => $profile,
            'rows'    => $list['rows'],
            'pager'   => $list['pager'],
            'filters' => $list['filters'],
            'summary' => $list['summary'],
            'counts'  => $counts,
            'sponsor' => $rel,
        ]);
    }

    public function genealogy()
    {
        $memberId = strtoupper(trim((string) $this->request->getGet('member_id')));
        $report   = service('genealogyIntegrityService')->scan(100);
        $tree     = null;
        $profile  = null;
        $counts   = null;
        $error    = null;

        if ($memberId !== '') {
            $profile = model(MemberProfileModel::class)->findByMemberId($memberId);
            if ($profile === null) {
                $error = 'Member not found for genealogy view.';
            } else {
                $depth  = max(1, min(5, (int) ($this->request->getGet('depth') ?: 3)));
                $tree   = service('referralTeamService')->subtree((int) $profile->user_id, $depth, 400);
                $counts = service('referralTeamService')->countsForSponsor((int) $profile->user_id, 3);
            }
        }

        $depth = max(1, min(5, (int) ($this->request->getGet('depth') ?: 3)));

        return view('admin/referrals/genealogy', [
            'title'    => 'Genealogy & Integrity',
            'memberId' => $memberId,
            'profile'  => $profile,
            'tree'     => $tree,
            'counts'   => $counts,
            'report'   => $report,
            'error'    => $error,
            'depth'    => $depth,
        ]);
    }
}
