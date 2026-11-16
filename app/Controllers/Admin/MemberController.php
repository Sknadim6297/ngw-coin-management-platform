<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;
use InvalidArgumentException;

class MemberController extends BaseController
{
    public function index(?string $status = null)
    {
        $profiles = model(MemberProfileModel::class);
        // Root admin/sponsor must never appear in member management lists.
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

        return view('admin/members/index', [
            'title'   => $title,
            'status'  => $status,
            'members' => $builder->paginate(20),
            'pager'   => $profiles->pager,
        ]);
    }

    public function create()
    {
        $generator = service('memberIdGenerator');

        return view('admin/members/create', [
            'title'           => 'Register Member',
            'previewMemberId' => $generator->previewNext(),
        ]);
    }

    public function store()
    {
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

        return view('admin/members/show', [
            'title'        => 'Member Profile',
            'profile'      => $profile,
            'relationship' => $relationship,
            'email'        => $email,
        ]);
    }
}
