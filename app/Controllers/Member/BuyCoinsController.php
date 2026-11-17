<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use InvalidArgumentException;

/**
 * Phase 8 guided buy-coins wizard (USDT amount → purchase → pay QR → submit tx).
 */
class BuyCoinsController extends BaseController
{
    public function index()
    {
        $userId     = (int) auth()->id();
        $profile    = model(MemberProfileModel::class)->findByUserId($userId);
        $context    = service('coinPurchaseService')->purchasePageContext();
        $payment    = service('paymentDetailsService')->paymentReadiness();
        $activation = service('memberActivationService')->statusForMember($userId);
        $token      = bin2hex(random_bytes(16));
        session()->setFlashdata('buy_coins_idempotency', $token);

        return view('member/buy_coins/wizard', [
            'title'          => 'Buy Coins',
            'profile'        => $profile,
            'context'        => $context,
            'payment'        => $payment,
            'network'        => $payment['network'],
            'activation'     => $activation,
            'idempotencyKey' => $token,
            'step'           => 1,
        ]);
    }

    public function store()
    {
        $userId = (int) auth()->id();
        $amount = (string) $this->request->getPost('purchase_amount_usdt');
        $idem   = (string) $this->request->getPost('idempotency_key');

        $context = service('coinPurchaseService')->purchasePageContext();
        if (! $context['can_purchase']) {
            return redirect()->back()->withInput()->with('error', (string) ($context['block_reason'] ?? 'Purchases unavailable.'));
        }

        $payment = service('paymentDetailsService')->paymentReadiness();
        if (! $payment['ready']) {
            return redirect()->back()->withInput()->with('error', $payment['message']);
        }

        try {
            $result = service('coinPurchaseService')->createRequestFromUsdtAmount(
                $userId,
                $amount,
                $this->nullablePost('member_note'),
                $idem
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Buy coins failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to create purchase request.');
        }

        $purchase = $result['request'];
        session()->set('buy_coins_network_id', (int) $payment['network']['id']);

        return redirect()->to(site_url('member/buy-coins/pay/' . rawurlencode($purchase['purchase_ref'])))
            ->with('success', $result['duplicate']
                ? 'Existing purchase request reused (idempotent).'
                : 'Purchase request created. Pay the exact USDT amount using the destination below.');
    }

    public function pay(string $purchaseRef)
    {
        $userId = (int) auth()->id();
        $purchase = service('coinPurchaseService')->findOwnedByMember($userId, $purchaseRef);
        if ($purchase === null) {
            return redirect()->to(site_url('member/buy-coins'))->with('error', 'Purchase not found.');
        }

        $payment = service('paymentDetailsService')->paymentReadiness();
        if (! $payment['ready']) {
            return redirect()->to(site_url('member/buy-coins'))->with('error', $payment['message']);
        }
        $network = $payment['network'];

        $linkedDeposit = null;
        if (! empty($purchase['payment_deposit_ref'])) {
            $linkedDeposit = service('usdtDepositService')->findOwnedByMember($userId, (string) $purchase['payment_deposit_ref']);
        }

        $token = bin2hex(random_bytes(16));
        session()->setFlashdata('buy_coins_pay_idempotency', $token);

        return view('member/buy_coins/pay', [
            'title'          => 'Pay for Coins',
            'purchase'       => $purchase,
            'network'        => $network,
            'linkedDeposit'  => $linkedDeposit,
            'idempotencyKey' => $token,
            'qrUrl'          => service('paymentQrService')->publicMediaUrl(
                (string) $network['network_key'],
                $network['qr_stored_name'] ?? null
            ),
            'activation'     => service('memberActivationService')->statusForMember($userId),
        ]);
    }

    public function submitPayment(string $purchaseRef)
    {
        $userId = (int) auth()->id();
        $purchase = service('coinPurchaseService')->findOwnedByMember($userId, $purchaseRef);
        if ($purchase === null) {
            return redirect()->to(site_url('member/buy-coins'))->with('error', 'Purchase not found.');
        }
        if (($purchase['status'] ?? '') !== 'pending') {
            return redirect()->to(site_url('member/purchases/' . rawurlencode($purchaseRef)))
                ->with('error', 'Only pending purchases accept payment submission.');
        }

        $payment = service('paymentDetailsService')->paymentReadiness();
        if (! $payment['ready']) {
            return redirect()->back()->withInput()->with('error', $payment['message']);
        }

        $networkId = (int) $payment['network']['id'];
        $txHash    = (string) $this->request->getPost('tx_hash');
        $idem      = (string) $this->request->getPost('idempotency_key');
        /** @var UploadedFile|null $file */
        $file = $this->request->getFile('evidence');

        try {
            $depositResult = service('usdtDepositService')->submitRequest(
                $userId,
                $networkId,
                (string) $purchase['total_usdt'],
                $txHash,
                $idem,
                $file
            );
            $deposit = $depositResult['deposit'];
            session()->set('buy_coins_deposit_ref_' . $purchaseRef, (string) $deposit['deposit_ref']);

            // Soft-associate purchase ↔ deposit for admin review (allocation still requires verified payment).
            $now = date('Y-m-d H:i:s');
            db_connect()->table('coin_purchase_requests')
                ->where('id', (int) $purchase['id'])
                ->where('status', 'pending')
                ->update([
                    'payment_deposit_ref' => (string) $deposit['deposit_ref'],
                    'updated_at'          => $now,
                ]);
            db_connect()->table('usdt_deposit_requests')
                ->where('id', (int) $deposit['id'])
                ->update([
                    'linked_purchase_ref' => $purchaseRef,
                    'updated_at'          => $now,
                ]);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Buy coins payment submit failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to submit payment details.');
        }

        return redirect()->to(site_url('member/buy-coins/status/' . rawurlencode($purchaseRef)))
            ->with('success', 'Payment Submitted — Waiting for Verification');
    }

    public function status(string $purchaseRef)
    {
        $userId = (int) auth()->id();
        $purchase = service('coinPurchaseService')->findOwnedByMember($userId, $purchaseRef);
        if ($purchase === null) {
            return redirect()->to(site_url('member/buy-coins'))->with('error', 'Purchase not found.');
        }

        $deposit = null;
        if (! empty($purchase['payment_deposit_ref'])) {
            $deposit = service('usdtDepositService')->findOwnedByMember($userId, (string) $purchase['payment_deposit_ref']);
        } else {
            $sessionDeposit = session('buy_coins_deposit_ref_' . $purchaseRef);
            if (is_string($sessionDeposit) && $sessionDeposit !== '') {
                $deposit = service('usdtDepositService')->findOwnedByMember($userId, $sessionDeposit);
            }
        }

        if ($deposit !== null && ($deposit['status'] ?? '') === 'verified' && ($purchase['payment_status'] ?? '') === 'unpaid') {
            try {
                service('usdtPaymentAllocationService')->linkDepositToPurchase(
                    $userId,
                    (string) $deposit['deposit_ref'],
                    $purchaseRef
                );
                $purchase = service('coinPurchaseService')->findOwnedByMember($userId, $purchaseRef) ?? $purchase;
            } catch (\Throwable $e) {
                // Leave pending for manual link.
            }
        }

        return view('member/buy_coins/status', [
            'title'      => 'Purchase Status',
            'purchase'   => $purchase,
            'deposit'    => $deposit,
            'activation' => service('memberActivationService')->statusForMember($userId),
        ]);
    }

    private function nullablePost(string $key): ?string
    {
        $v = $this->request->getPost($key);
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
