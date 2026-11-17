<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberProfileModel;

/**
 * Phase 8: explain member activation status using real purchase/deposit data.
 * Activation itself remains owned by CoinPurchaseService::approveRequest.
 */
class MemberActivationService
{
    public const REASON_NO_APPROVED_PURCHASE         = 'NO_APPROVED_PURCHASE';
    public const REASON_PAYMENT_PENDING              = 'PAYMENT_PENDING';
    public const REASON_PAYMENT_VERIFICATION_FAILED  = 'PAYMENT_VERIFICATION_FAILED';
    public const REASON_PURCHASE_APPROVAL_PENDING    = 'PURCHASE_APPROVAL_PENDING';
    public const REASON_INSUFFICIENT_INVENTORY       = 'INSUFFICIENT_INVENTORY';
    public const REASON_ACTIVATION_COMPLETE          = 'ACTIVATION_COMPLETE';

    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel()
    ) {
    }

    /**
     * @return array{
     *   account_status: string,
     *   is_active: bool,
     *   activated_at: ?string,
     *   activation_purchase_ref: ?string,
     *   reason_code: string,
     *   message: string,
     *   pending_purchase: ?array<string, mixed>,
     *   pending_deposit: ?array<string, mixed>
     * }
     */
    public function statusForMember(int $memberUserId): array
    {
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            return [
                'account_status'           => 'inactive',
                'is_active'                => false,
                'activated_at'             => null,
                'activation_purchase_ref'  => null,
                'reason_code'              => self::REASON_NO_APPROVED_PURCHASE,
                'message'                  => 'Member profile not found.',
                'pending_purchase'         => null,
                'pending_deposit'          => null,
            ];
        }

        if (($profile->account_status ?? '') === 'active') {
            return [
                'account_status'          => 'active',
                'is_active'               => true,
                'activated_at'            => $profile->activated_at ?? null,
                'activation_purchase_ref' => $profile->activation_purchase_ref ?? null,
                'reason_code'             => self::REASON_ACTIVATION_COMPLETE,
                'message'                 => 'Account is active after a verified and approved coin purchase.',
                'pending_purchase'        => null,
                'pending_deposit'         => null,
            ];
        }

        $db = db_connect();
        $pendingPurchase = $db->table('coin_purchase_requests')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'pending')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $pendingDeposit = $db->table('usdt_deposit_requests')
            ->where('member_user_id', $memberUserId)
            ->whereIn('status', ['submitted', 'under_review', 'draft'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $failedDeposit = $db->table('usdt_deposit_requests')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'rejected')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if ($pendingPurchase !== null) {
            $payStatus = (string) ($pendingPurchase['payment_status'] ?? 'unpaid');
            if ($payStatus === 'verified' || $payStatus === 'deposit_linked') {
                return [
                    'account_status'          => 'inactive',
                    'is_active'               => false,
                    'activated_at'            => null,
                    'activation_purchase_ref' => null,
                    'reason_code'             => self::REASON_PURCHASE_APPROVAL_PENDING,
                    'message'                 => 'Payment verified. Waiting for purchase approval.',
                    'pending_purchase'        => $pendingPurchase,
                    'pending_deposit'         => $pendingDeposit,
                ];
            }

            if ($pendingDeposit !== null || in_array($payStatus, ['submitted', 'pending_verification'], true)) {
                return [
                    'account_status'          => 'inactive',
                    'is_active'               => false,
                    'activated_at'            => null,
                    'activation_purchase_ref' => null,
                    'reason_code'             => self::REASON_PAYMENT_PENDING,
                    'message'                 => 'Payment submitted. Waiting for verification.',
                    'pending_purchase'        => $pendingPurchase,
                    'pending_deposit'         => $pendingDeposit,
                ];
            }

            // Check inventory gate for messaging only
            $qty = (string) ($pendingPurchase['coin_quantity'] ?? '0');
            if (! service('coinInventoryService')->hasSufficientInventory($qty)) {
                return [
                    'account_status'          => 'inactive',
                    'is_active'               => false,
                    'activated_at'            => null,
                    'activation_purchase_ref' => null,
                    'reason_code'             => self::REASON_INSUFFICIENT_INVENTORY,
                    'message'                 => 'Purchase is pending but inventory is currently insufficient for approval.',
                    'pending_purchase'        => $pendingPurchase,
                    'pending_deposit'         => $pendingDeposit,
                ];
            }

            return [
                'account_status'          => 'inactive',
                'is_active'               => false,
                'activated_at'            => null,
                'activation_purchase_ref' => null,
                'reason_code'             => self::REASON_PAYMENT_PENDING,
                'message'                 => 'Purchase request created. Complete payment using the admin QR / receiving address.',
                'pending_purchase'        => $pendingPurchase,
                'pending_deposit'         => $pendingDeposit,
            ];
        }

        if ($failedDeposit !== null && $pendingDeposit === null) {
            return [
                'account_status'          => 'inactive',
                'is_active'               => false,
                'activated_at'            => null,
                'activation_purchase_ref' => null,
                'reason_code'             => self::REASON_PAYMENT_VERIFICATION_FAILED,
                'message'                 => 'A payment verification failed or was rejected. Submit a new verified deposit.',
                'pending_purchase'        => null,
                'pending_deposit'         => $failedDeposit,
            ];
        }

        if ($pendingDeposit !== null) {
            return [
                'account_status'          => 'inactive',
                'is_active'               => false,
                'activated_at'            => null,
                'activation_purchase_ref' => null,
                'reason_code'             => self::REASON_PAYMENT_PENDING,
                'message'                 => 'Payment submitted. Waiting for verification.',
                'pending_purchase'        => null,
                'pending_deposit'         => $pendingDeposit,
            ];
        }

        return [
            'account_status'          => 'inactive',
            'is_active'               => false,
            'activated_at'            => null,
            'activation_purchase_ref' => null,
            'reason_code'             => self::REASON_NO_APPROVED_PURCHASE,
            'message'                 => 'Your account will become active after your first verified and approved coin purchase.',
            'pending_purchase'        => null,
            'pending_deposit'         => null,
        ];
    }
}
