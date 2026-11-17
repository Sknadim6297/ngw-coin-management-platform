<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Phase 8: dual-control proposals for payment destination wallet / QR changes.
 * Approved changes update the network config only — existing deposit snapshots stay immutable.
 */
class PaymentDestinationChangeService
{
    public function __construct(
        private readonly UsdtPaymentNetworkService $networks = new UsdtPaymentNetworkService(),
        private readonly PaymentQrService $qr = new PaymentQrService(),
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * @param array{
     *   network_id: int,
     *   to_destination_wallet: string,
     *   instructions?: string,
     *   display_enabled?: bool,
     *   min_deposit_usdt?: string,
     *   qr_mode?: string,
     *   qr_staging_stored_name?: ?string,
     *   qr_staging_mime?: ?string,
     *   qr_decoded_payload?: ?string,
     *   recipient_name?: ?string,
     *   token_contract_address?: ?string
     * } $data
     * @return array<string, mixed>
     */
    public function propose(array $data, int $actorUserId): array
    {
        $networkId = (int) ($data['network_id'] ?? 0);
        $network   = $this->networks->find($networkId);
        if ($network === null) {
            throw new InvalidArgumentException('Payment network not found.');
        }

        $to = trim((string) ($data['to_destination_wallet'] ?? ''));
        if ($to === '') {
            throw new InvalidArgumentException('Receiving wallet address is required.');
        }
        // Payment Details admin may save free-form destinations; skip strict pattern when requested.
        if (empty($data['skip_wallet_format'])) {
            $this->networks->assertValidWallet($to, $network);
        }

        $from = trim((string) ($network['destination_wallet'] ?? ''));
        $qrMode = strtolower(trim((string) ($data['qr_mode'] ?? 'keep')));
        if (! in_array($qrMode, ['keep', 'upload', 'generate', 'remove'], true)) {
            throw new InvalidArgumentException('Invalid QR change mode.');
        }

        $walletSame = $from !== '' && strcasecmp($from, $to) === 0;
        $qrChanging = in_array($qrMode, ['upload', 'generate', 'remove'], true);
        $allowSame  = ! empty($data['allow_same_wallet']);
        if ($walletSame && ! $qrChanging && ! $allowSame) {
            throw new InvalidArgumentException('New destination matches the current wallet.');
        }

        if (in_array($qrMode, ['upload', 'generate'], true)) {
            $staging = trim((string) ($data['qr_staging_stored_name'] ?? ''));
            if ($staging === '') {
                throw new InvalidArgumentException('Staged QR image is required for this change.');
            }
        }

        // Block while a pending proposal exists for this network.
        $pending = db_connect()->table('payment_destination_change_proposals')
            ->where('network_id', $networkId)
            ->where('status', 'pending')
            ->countAllResults();
        if ($pending > 0) {
            throw new InvalidArgumentException('A destination change is already pending approval for this network.');
        }

        $ref = 'PDC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = date('Y-m-d H:i:s');
        db_connect()->table('payment_destination_change_proposals')->insert([
            'proposal_ref'            => $ref,
            'network_id'              => $networkId,
            'network_key'             => $network['network_key'],
            'from_destination_wallet' => $from !== '' ? $from : null,
            'to_destination_wallet'   => $to,
            'instructions'            => trim((string) ($data['instructions'] ?? '')) ?: null,
            'display_enabled'         => ! empty($data['display_enabled']) ? 1 : 0,
            'min_deposit_usdt'        => isset($data['min_deposit_usdt']) ? (string) $data['min_deposit_usdt'] : null,
            'qr_mode'                 => $qrMode,
            'qr_staging_stored_name'  => $data['qr_staging_stored_name'] ?? null,
            'qr_staging_mime'         => $data['qr_staging_mime'] ?? null,
            'qr_decoded_payload'      => $data['qr_decoded_payload'] ?? null,
            'recipient_name'          => $data['recipient_name'] ?? null,
            'token_contract_address'  => $data['token_contract_address'] ?? null,
            'status'                  => 'pending',
            'proposed_by'             => $actorUserId,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);
        $this->recordEvent($ref, 'proposed', $actorUserId, null, 'pending', [
            'to'      => $to,
            'qr_mode' => $qrMode,
        ]);
        $this->audit->log('payment.destination_change_proposed', $actorUserId, 'payment_destination_change', $ref, [
            'network_id' => $networkId,
        ]);

        return $this->findByRef($ref) ?? ['proposal_ref' => $ref];
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(string $proposalRef, int $actorUserId): array
    {
        $row = $this->require($proposalRef);
        if (($row['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('Only pending proposals can be approved.');
        }
        if ((int) ($row['proposed_by'] ?? 0) === $actorUserId) {
            throw new InvalidArgumentException('Self-approval is not allowed for payment destination changes.');
        }

        $network = $this->networks->find((int) $row['network_id']);
        if ($network === null) {
            throw new InvalidArgumentException('Payment network not found.');
        }

        $payload = [
            'destination_wallet'       => $row['to_destination_wallet'],
            'is_enabled'               => (int) ($network['is_enabled'] ?? 0),
            'network_name'             => $network['network_name'],
            'token_symbol'             => $network['token_symbol'],
            'token_contract_address'   => $row['token_contract_address'] ?? $network['token_contract_address'],
            'token_decimals'           => $network['token_decimals'],
            'address_pattern'          => $network['address_pattern'],
            'tx_hash_pattern'          => $network['tx_hash_pattern'],
            'required_confirmations'   => $network['required_confirmations'],
            'min_deposit_usdt'         => $row['min_deposit_usdt'] ?? $network['min_deposit_usdt'],
            'max_deposit_usdt'         => $network['max_deposit_usdt'],
            'instructions'             => $row['instructions'] ?? $network['instructions'],
            'explorer_tx_url_template' => $network['explorer_tx_url_template'] ?? null,
            'rpc_url_env_key'          => $network['rpc_url_env_key'] ?? null,
            'verifier_type'            => $network['verifier_type'] ?? 'none',
            'chain_id'                 => $network['chain_id'] ?? null,
            'sort_order'               => $network['sort_order'] ?? 100,
            'is_demo'                  => 0,
        ];

        $qrMode = strtolower((string) ($row['qr_mode'] ?? 'keep'));
        if ($qrMode === 'upload' || $qrMode === 'generate') {
            $staging = (string) ($row['qr_staging_stored_name'] ?? '');
            $mime    = (string) ($row['qr_staging_mime'] ?? 'image/png');
            if ($staging === '') {
                throw new InvalidArgumentException('Approved proposal is missing staged QR image.');
            }
            $path = $this->qr->absolutePath($staging);
            $decoded = '';
            try {
                $decoded = $this->qr->decodePayload($path);
                $toWallet = (string) $row['to_destination_wallet'];
                if (preg_match('/^0x[a-fA-F0-9]{40}$/', $toWallet) === 1) {
                    $this->qr->assertPayloadMatchesWallet($decoded, $toWallet);
                }
            } catch (\Throwable) {
                // Free-form admin destinations may not be EVM-matchable; still promote uploaded image.
                $decoded = (string) ($row['qr_decoded_payload'] ?? '');
            }

            $promoted = $this->qr->promoteStaging($staging, $mime);
            if (! empty($network['qr_stored_name'])) {
                $this->qr->deleteIfExists((string) $network['qr_stored_name']);
            }
            $payload['qr_stored_name']     = $promoted['stored_name'];
            $payload['qr_mime']            = $promoted['mime'];
            $payload['qr_source']          = $qrMode === 'generate' ? PaymentQrService::SOURCE_GENERATED : PaymentQrService::SOURCE_UPLOADED;
            $payload['qr_decoded_payload'] = $decoded;
            $payload['qr_verified_at']     = date('Y-m-d H:i:s');
        } elseif ($qrMode === 'remove') {
            if (! empty($network['qr_stored_name'])) {
                $this->qr->deleteIfExists((string) $network['qr_stored_name']);
            }
            $payload['qr_stored_name']     = null;
            $payload['qr_mime']            = null;
            $payload['qr_source']          = PaymentQrService::SOURCE_NONE;
            $payload['qr_decoded_payload'] = null;
            $payload['qr_verified_at']     = null;
        }

        // Payment Details dual-control: enable/disable from proposal without requiring live RPC.
        if (array_key_exists('display_enabled', $row)) {
            $wantEnable = ! empty($row['display_enabled']);
            $payload['is_enabled']     = $wantEnable ? 1 : 0;
            $payload['display_status'] = $wantEnable ? 'live' : 'hidden';
        }

        $this->networks->update((int) $row['network_id'], $payload, $actorUserId);

        // Display-only columns (may not be in normalizePayload).
        $extra = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (array_key_exists('recipient_name', $row) && $row['recipient_name'] !== null) {
            $extra['recipient_name'] = $row['recipient_name'];
        }
        if (isset($payload['display_status'])) {
            $extra['display_status'] = $payload['display_status'];
        }
        db_connect()->table('usdt_payment_networks')->where('id', (int) $row['network_id'])->update($extra);

        $now = date('Y-m-d H:i:s');
        db_connect()->table('payment_destination_change_proposals')->where('proposal_ref', $proposalRef)->update([
            'status'      => 'approved',
            'approved_by' => $actorUserId,
            'approved_at' => $now,
            'updated_at'  => $now,
        ]);
        $this->recordEvent($proposalRef, 'approved', $actorUserId, 'pending', 'approved', null);
        $this->audit->log('payment.destination_change_approved', $actorUserId, 'payment_destination_change', $proposalRef, [
            'network_id' => $row['network_id'],
            'note'       => 'Existing deposit destination snapshots remain unchanged.',
        ]);

        return $this->findByRef($proposalRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $proposalRef, int $actorUserId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }
        $row = $this->require($proposalRef);
        if (($row['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('Only pending proposals can be rejected.');
        }

        // Cleanup staged QR on reject.
        if (! empty($row['qr_staging_stored_name'])) {
            $this->qr->deleteIfExists((string) $row['qr_staging_stored_name']);
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('payment_destination_change_proposals')->where('proposal_ref', $proposalRef)->update([
            'status'           => 'rejected',
            'rejected_by'      => $actorUserId,
            'rejected_at'      => $now,
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ]);
        $this->recordEvent($proposalRef, 'rejected', $actorUserId, 'pending', 'rejected', ['reason' => $reason]);

        return $this->findByRef($proposalRef) ?? $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 50): array
    {
        return db_connect()->table('payment_destination_change_proposals')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        $row = db_connect()->table('payment_destination_change_proposals')
            ->where('proposal_ref', $ref)
            ->get()
            ->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['events'] = db_connect()->table('payment_destination_change_events')
            ->where('proposal_ref', $ref)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function require(string $ref): array
    {
        $row = $this->findByRef($ref);
        if ($row === null) {
            throw new InvalidArgumentException('Destination change proposal not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(
        string $ref,
        string $type,
        int $actor,
        ?string $from,
        ?string $to,
        ?array $payload
    ): void {
        db_connect()->table('payment_destination_change_events')->insert([
            'proposal_ref'  => $ref,
            'event_type'    => $type,
            'actor_user_id' => $actor,
            'from_status'   => $from,
            'to_status'     => $to,
            'payload_json'  => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
