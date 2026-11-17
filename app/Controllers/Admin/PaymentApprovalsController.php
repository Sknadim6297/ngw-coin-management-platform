<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\Concerns\AdminAuthorization;
use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Unified Payment Approvals UI — reuses deposit + purchase services (no second finance path).
 */
class PaymentApprovalsController extends BaseController
{
    use AdminAuthorization;

    public function index()
    {
        if (! $this->userCan('finance.payment.review')) {
            return $this->denyPermission();
        }

        $tab    = trim((string) $this->request->getGet('tab'));
        $search = trim((string) $this->request->getGet('q'));
        if ($tab === '') {
            $tab = 'all';
        }

        $result = service('paymentApprovalsService')->listForAdmin($tab, $search !== '' ? $search : null, 20);

        return view('admin/payment_approvals/index', [
            'title'  => 'Payment Approvals',
            'tab'    => $tab,
            'search' => $search,
            'rows'   => $result['rows'],
            'pager'  => $result['pager'],
            'counts' => $result['counts'],
            'mode'   => $result['mode'],
        ]);
    }

    public function show(string $purchaseRef)
    {
        if (! $this->userCan('finance.payment.review')) {
            return $this->denyPermission();
        }

        $request = service('coinPurchaseService')->findByRef($purchaseRef);
        if ($request === null) {
            return redirect()->to(site_url('admin/payment-approvals'))
                ->with('error', 'Purchase request not found.');
        }

        $profile = model(MemberProfileModel::class)->findByUserId((int) $request['member_user_id']);

        $allocation = service('usdtPaymentAllocationService')->findForPurchase($purchaseRef);
        $deposit = null;
        if (! empty($request['payment_deposit_ref'])) {
            $deposit = service('usdtDepositService')->findByRef((string) $request['payment_deposit_ref']);
        } elseif ($allocation !== null) {
            $deposit = service('usdtDepositService')->findByRef((string) $allocation['deposit_ref']);
        } else {
            $deposit = service('usdtDepositService')->findMatchingPendingPaymentForPurchase($request);
        }

        $network = null;
        $events  = [];
        $reviewer = null;
        if ($deposit !== null) {
            $network = service('usdtPaymentNetworkService')->find((int) $deposit['network_id']);
            $events  = service('usdtDepositService')->eventsFor((string) $deposit['deposit_ref']);
            if (! empty($deposit['reviewed_by'])) {
                $user = model(UserModel::class)->find((int) $deposit['reviewed_by']);
                $reviewer = $user?->getEmail();
            }
        }

        $requiresDeposit  = service('coinPurchaseService')->requiresVerifiedDeposit();
        $hasVerifiedCover = false;
        if ($requiresDeposit) {
            $hasVerifiedCover = service('usdtDepositService')->hasVerifiedDepositCovering(
                (int) $request['member_user_id'],
                (string) $request['total_usdt']
            );
        }

        return view('admin/payment_approvals/show', [
            'title'            => 'Payment Review',
            'request'          => $request,
            'profile'          => $profile,
            'deposit'          => $deposit,
            'network'          => $network,
            'events'           => $events,
            'reviewer'         => $reviewer,
            'allocation'       => $allocation,
            'inventory'        => service('coinInventoryService')->summary(),
            'requiresDeposit'  => $requiresDeposit,
            'hasVerifiedCover' => $hasVerifiedCover,
        ]);
    }

    public function depositShow(string $depositRef)
    {
        if (! $this->userCan('finance.payment.review')) {
            return $this->denyPermission();
        }

        $deposit = service('usdtDepositService')->findByRef($depositRef);
        if ($deposit === null) {
            return redirect()->to(site_url('admin/payment-approvals?tab=unlinked'))
                ->with('error', 'Payment not found.');
        }

        if (! empty($deposit['linked_purchase_ref'])) {
            return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode((string) $deposit['linked_purchase_ref'])));
        }

        $purchase = service('usdtDepositService')->findMatchingPendingPurchase($deposit);
        if ($purchase !== null && ! empty($purchase['purchase_ref'])) {
            return redirect()->to(site_url('admin/payment-approvals/' . rawurlencode((string) $purchase['purchase_ref'])));
        }

        $profile  = model(MemberProfileModel::class)->findByUserId((int) $deposit['member_user_id']);
        $network  = service('usdtPaymentNetworkService')->find((int) $deposit['network_id']);
        $events   = service('usdtDepositService')->eventsFor($depositRef);
        $reviewer = null;
        if (! empty($deposit['reviewed_by'])) {
            $user = model(UserModel::class)->find((int) $deposit['reviewed_by']);
            $reviewer = $user?->getEmail();
        }

        return view('admin/payment_approvals/deposit_show', [
            'title'    => 'Unlinked Payment Review',
            'deposit'  => $deposit,
            'profile'  => $profile,
            'network'  => $network,
            'events'   => $events,
            'reviewer' => $reviewer,
            'purchase' => null,
        ]);
    }
}
