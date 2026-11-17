<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\Concerns\AdminAuthorization;
use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;
use InvalidArgumentException;

class MemberController extends BaseController
{
    use AdminAuthorization;

    public function index(?string $status = null)
    {
        if (! $this->userCan('admin.members.manage')) {
            return $this->denyPermission();
        }

        // Prefer query ?status= for consolidated tabs; keep /active|/inactive path compat.
        $queryStatus = trim((string) $this->request->getGet('status'));
        if ($queryStatus !== '') {
            $status = $queryStatus;
        }
        if ($status === 'all' || $status === '') {
            $status = null;
        }

        $search = trim((string) $this->request->getGet('q'));

        $profiles = model(MemberProfileModel::class);
        $profiles->excludeRootSponsors();
        $builder = $profiles->orderBy('created_at', 'DESC');

        $title = 'All Members';
        if ($status === 'active') {
            $builder->where('account_status', 'active');
            $title = 'Active Members';
        } elseif ($status === 'inactive') {
            $builder->where('account_status', 'inactive');
            $title = 'Inactive Members';
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('member_id', $search)
                ->orLike('member_name', $search)
                ->orLike('mobile', $search)
            ->groupEnd();
        }

        $members = $builder->paginate(20);
        $sponsors = [];
        $purchasedCoins = [];

        $userIds = [];
        foreach ($members as $member) {
            $userIds[] = (int) $member->user_id;
        }
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds !== []) {
            $rels = model(SponsorRelationshipModel::class)
                ->whereIn('member_user_id', $userIds)
                ->findAll();
            foreach ($rels as $rel) {
                $sponsors[(int) $rel['member_user_id']] = (string) ($rel['sponsor_member_id'] ?? '—');
            }

            foreach ($userIds as $uid) {
                $summary = service('memberCoinOwnershipService')->summaryForUser($uid);
                $purchasedCoins[$uid] = $summary['total_acquired'] ?? '0';
            }
        }

        return view('admin/members/index', [
            'title'           => $title,
            'status'          => $status ?? 'all',
            'search'          => $search,
            'members'         => $members,
            'pager'           => $profiles->pager,
            'sponsors'        => $sponsors,
            'purchasedCoins'  => $purchasedCoins,
            'canImpersonate'  => $this->userCan('admin.members.impersonate'),
        ]);
    }

    public function create()
    {
        if (! $this->userCan('admin.members.manage')) {
            return $this->denyPermission();
        }

        $generator = service('memberIdGenerator');

        return view('admin/members/create', [
            'title'           => 'Register Member',
            'previewMemberId' => $generator->previewNext(),
        ]);
    }

    public function store()
    {
        if (! $this->userCan('admin.members.manage')) {
            return $this->denyPermission();
        }

        $rules = config('Validation')->memberRegistration;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'member_name' => (string) $this->request->getPost('member_name'),
            'sponsor_id'  => (string) $this->request->getPost('sponsor_id'),
            'mobile'      => (string) $this->request->getPost('mobile'),
            'email'       => strtolower(trim((string) $this->request->getPost('email'))),
            'usdt_wallet' => (string) $this->request->getPost('usdt_wallet'),
            'password'    => (string) $this->request->getPost('password'),
        ];

        try {
            $result = service('registrationService')->register(
                $data,
                auth()->id(),
                'admin'
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Admin member create failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Member could not be created. Please try again.');
        }

        return redirect()->to(site_url('admin/members'))
            ->with('success', 'Member ' . $result['member_id'] . ' created as inactive.');
    }

    public function show(string $memberId)
    {
        if (! $this->userCan('admin.members.manage')) {
            return $this->denyPermission();
        }

        $profile = model(MemberProfileModel::class)->findByMemberId($memberId);

        if ($profile === null) {
            return redirect()->to(site_url('admin/members'))->with('error', 'Member not found.');
        }

        $rootId = trim((string) config('Platform')->rootSponsorId);
        if ($profile->is_root_sponsor || ($rootId !== '' && $profile->member_id === $rootId)) {
            return redirect()->to(site_url('admin/members'))->with('error', 'Admin account is not listed as a member.');
        }

        $relationship = model(SponsorRelationshipModel::class)
            ->findByMemberUserId($profile->user_id);

        $email = null;
        $user  = auth()->getProvider()->findById($profile->user_id);
        if ($user !== null) {
            $email = $user->getEmail();
        }

        $activation = service('memberActivationService')->statusForMember((int) $profile->user_id);
        $owned = service('memberCoinOwnershipService')->summaryForUser((int) $profile->user_id);

        return view('admin/members/show', [
            'title'          => 'Member Profile',
            'profile'        => $profile,
            'relationship'   => $relationship,
            'email'          => $email,
            'activation'     => $activation,
            'purchasedCoins' => $owned['total_acquired'] ?? '0',
            'canImpersonate' => $this->userCan('admin.members.impersonate'),
        ]);
    }
}
