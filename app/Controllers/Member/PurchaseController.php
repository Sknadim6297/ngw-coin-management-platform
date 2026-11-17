<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

class PurchaseController extends BaseController
{
    public function buyForm()
    {
        // Legacy route — Phase 8 Buy Coins wizard is the single purchase entry point.
        return redirect()->to(site_url('member/buy-coins'));
    }

    public function buyStore()
    {
        $rules = config('Validation')->coinPurchaseRequest;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $idempotencyKey = (string) $this->request->getPost('idempotency_key');

        try {
            $result = service('coinPurchaseService')->createRequest(
                (int) auth()->id(),
                (string) $this->request->getPost('quantity'),
                $this->nullablePost('member_note'),
                $idempotencyKey
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Purchase request failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to submit purchase request. Please try again.');
        }

        $ref = $result['request']['purchase_ref'];

        if (! empty($result['duplicate'])) {
            return redirect()->to(site_url('member/purchases/' . $ref))
                ->with('success', 'This submission was already received. No duplicate request was created.');
        }

        return redirect()->to(site_url('member/purchases/' . $ref))
            ->with('success', 'Purchase request submitted as pending. This is not a paid or settled transaction.');
    }

    public function history()
    {
        $status = trim((string) $this->request->getGet('status'));
        $allowed = ['pending', 'approved', 'rejected', 'cancelled', ''];
        if (! in_array($status, $allowed, true)) {
            $status = '';
        }

        $model = model(CoinPurchaseRequestModel::class);
        $rows  = $model->forMember((int) auth()->id(), $status !== '' ? $status : null, 20);

        return view('member/purchases/history', [
            'title'  => 'Purchase History',
            'rows'   => $rows,
            'pager'  => $model->pager,
            'status' => $status,
        ]);
    }

    public function show(string $purchaseRef)
    {
        $userId = (int) auth()->id();
        $row = service('coinPurchaseService')->findOwnedByMember($userId, $purchaseRef);
        if ($row === null) {
            return redirect()->to(site_url('member/purchases'))
                ->with('error', 'Purchase request not found.');
        }

        $allocation = service('usdtPaymentAllocationService')->findForPurchase($purchaseRef);
        $linkedDeposit = null;
        if (! empty($row['payment_deposit_ref'])) {
            $linkedDeposit = service('usdtDepositService')->findOwnedByMember($userId, (string) $row['payment_deposit_ref']);
        } elseif ($allocation !== null) {
            $linkedDeposit = service('usdtDepositService')->findOwnedByMember($userId, (string) $allocation['deposit_ref']);
        }

        $availableDeposit = null;
        if ($row['status'] === 'pending' && ($row['payment_status'] ?? 'unpaid') === 'unpaid') {
            $availableDeposit = service('usdtDepositService')->findAvailableVerifiedDepositForAmount(
                $userId,
                (string) $row['total_usdt']
            );
        }

        return view('member/purchases/show', [
            'title'            => 'Purchase Details',
            'request'          => $row,
            'availableDeposit' => $availableDeposit,
            'allocation'       => $allocation,
            'linkedDeposit'    => $linkedDeposit,
        ]);
    }

    public function linkDeposit(string $purchaseRef)
    {
        $depositRef = trim((string) $this->request->getPost('deposit_ref'));
        if ($depositRef === '') {
            return redirect()->back()->with('error', 'Select a verified deposit to link.');
        }

        try {
            service('usdtPaymentAllocationService')->linkDepositToPurchase(
                (int) auth()->id(),
                $depositRef,
                $purchaseRef
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Payment link failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to link deposit to purchase.');
        }

        return redirect()->to(site_url('member/purchases/' . $purchaseRef))
            ->with('success', 'Verified deposit linked. An administrator can now approve the purchase.');
    }

    public function cancel(string $purchaseRef)
    {
        try {
            service('coinPurchaseService')->cancelRequest((int) auth()->id(), $purchaseRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Purchase cancel failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->with('error', 'Unable to cancel purchase request.');
        }

        return redirect()->to(site_url('member/purchases/' . $purchaseRef))
            ->with('success', 'Purchase request cancelled. No inventory was deducted.');
    }

    public function holdings()
    {
        // Phase 6: holdings UI consolidated into the coin wallet overview.
        return redirect()->to(site_url('member/wallet'));
    }

    private function nullablePost(string $key): ?string
    {
        $value = trim((string) $this->request->getPost($key));

        return $value === '' ? null : $value;
    }
}
