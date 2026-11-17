<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\Concerns\AdminAuthorization;
use App\Controllers\BaseController;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use InvalidArgumentException;

class PurchaseController extends BaseController
{
    use AdminAuthorization;

    public function index()
    {
        if (! $this->userCan('finance.payment.review')) {
            return $this->denyPermission();
        }

        $status  = trim((string) $this->request->getGet('status'));
        $search  = trim((string) $this->request->getGet('q'));
        $allowed = ['pending', 'approved', 'rejected', 'cancelled', ''];
        if (! in_array($status, $allowed, true)) {
            $status = '';
        }

        $model = model(CoinPurchaseRequestModel::class);
        $rows  = $model->adminList(
            $status !== '' ? $status : null,
            $search !== '' ? $search : null,
            20
        );

        return view('admin/purchases/index', [
            'title'         => 'Purchase Requests',
            'rows'          => $rows,
            'pager'         => $model->pager,
            'status'        => $status,
            'search'        => $search,
            'pendingCount'  => $model->countByStatus('pending'),
            'approvedCount' => $model->countByStatus('approved'),
            'rejectedCount' => $model->countByStatus('rejected'),
            'inventory'     => service('coinInventoryService')->summary(),
        ]);
    }

    public function show(string $purchaseRef)
    {
        if (! $this->userCan('finance.payment.review')) {
            return $this->denyPermission();
        }

        $row = service('coinPurchaseService')->findByRef($purchaseRef);
        if ($row === null) {
            return redirect()->to(site_url('admin/purchases'))
                ->with('error', 'Purchase request not found.');
        }

        $profile = model(MemberProfileModel::class)->findByUserId((int) $row['member_user_id']);
        $reviewer = null;
        if (! empty($row['reviewed_by'])) {
            $user = model(UserModel::class)->find((int) $row['reviewed_by']);
            $reviewer = $user?->getEmail();
        }

        $requiresDeposit = service('coinPurchaseService')->requiresVerifiedDeposit();
        $hasVerifiedCover = false;
        if ($requiresDeposit) {
            $hasVerifiedCover = service('usdtDepositService')->hasVerifiedDepositCovering(
                (int) $row['member_user_id'],
                (string) $row['total_usdt']
            );
        }

        $allocation = service('usdtPaymentAllocationService')->findForPurchase($purchaseRef);
        $linkedDeposit = null;
        if (! empty($row['payment_deposit_ref'])) {
            $linkedDeposit = service('usdtDepositService')->findByRef((string) $row['payment_deposit_ref']);
        } elseif ($allocation !== null) {
            $linkedDeposit = service('usdtDepositService')->findByRef((string) $allocation['deposit_ref']);
        }

        $pendingPayment = null;
        if ($requiresDeposit && ! $hasVerifiedCover && $row['status'] === 'pending') {
            $pendingPayment = service('usdtDepositService')->findMatchingPendingPaymentForPurchase($row);
        }

        return view('admin/purchases/show', [
            'title'             => 'Purchase Request',
            'request'           => $row,
            'profile'           => $profile,
            'reviewer'          => $reviewer,
            'inventory'         => service('coinInventoryService')->summary(),
            'requiresDeposit'   => $requiresDeposit,
            'hasVerifiedCover'  => $hasVerifiedCover,
            'allocation'        => $allocation,
            'linkedDeposit'     => $linkedDeposit,
            'pendingPayment'    => $pendingPayment,
        ]);
    }

    public function approve(string $purchaseRef)
    {
        if (! $this->userCan('finance.payment.approve')) {
            return $this->denyPermission();
        }

        $rules = config('Validation')->coinPurchaseApprove;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $result = service('coinPurchaseService')->approveRequest(
                $purchaseRef,
                (int) auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Purchase approve failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to approve purchase request. Please try again.');
        }

        $msg = 'Purchase request approved. Inventory deducted and ownership recorded.';
        if (! empty($result['activated'])) {
            $msg .= ' Member account activated.';
        }

        return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode($purchaseRef)))
            ->with('success', $msg);
    }

    public function reject(string $purchaseRef)
    {
        if (! $this->userCan('finance.payment.approve')) {
            return $this->denyPermission();
        }

        $rules = config('Validation')->coinPurchaseReject;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('coinPurchaseService')->rejectRequest(
                $purchaseRef,
                (int) auth()->id(),
                (string) $this->request->getPost('rejection_reason')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Purchase reject failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to reject purchase request.');
        }

        return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode($purchaseRef)))
            ->with('success', 'Purchase request rejected. Inventory was not deducted.');
    }
}
