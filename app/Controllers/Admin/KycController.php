<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Admin KYC review for commission withdrawals. Members cannot self-approve.
 */
class KycController extends BaseController
{
    public function index()
    {
        if (! $this->canReview()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $filters = [
            'status'   => trim((string) $this->request->getGet('status')),
            'member_q' => trim((string) $this->request->getGet('member_q')),
        ];

        return view('admin/kyc/index', [
            'title'   => 'Member KYC',
            'rows'    => service('memberKycService')->adminList($filters, 100),
            'filters' => $filters,
        ]);
    }

    public function show(int $memberUserId)
    {
        if (! $this->canReview()) {
            return redirect()->to(site_url('admin/kyc'))->with('error', 'Permission denied.');
        }

        $row = service('memberKycService')->adminFind($memberUserId);
        if ($row === null) {
            return redirect()->to(site_url('admin/kyc'))->with('error', 'KYC record not found.');
        }

        return view('admin/kyc/show', [
            'title' => 'KYC Review',
            'row'   => $row,
        ]);
    }

    public function approve(int $memberUserId)
    {
        if (! $this->canReview()) {
            return redirect()->to(site_url('admin/kyc'))->with('error', 'Permission denied.');
        }

        try {
            service('memberKycService')->approve(
                $memberUserId,
                (int) auth()->id(),
                (string) $this->request->getPost('admin_notes')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/kyc/' . $memberUserId))
            ->with('success', 'KYC approved.');
    }

    public function reject(int $memberUserId)
    {
        if (! $this->canReview()) {
            return redirect()->to(site_url('admin/kyc'))->with('error', 'Permission denied.');
        }

        try {
            service('memberKycService')->reject(
                $memberUserId,
                (int) auth()->id(),
                (string) $this->request->getPost('admin_notes')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/kyc/' . $memberUserId))
            ->with('success', 'KYC rejected.');
    }

    private function canReview(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->inGroup('admin')
            || $user->can('admin.members.manage')
            || $user->can('commission.withdrawal.review');
    }
}
