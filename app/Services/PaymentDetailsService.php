<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Blockchain;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * BEP20 payment details for Admin.
 *
 * Bootstrap (empty receiving wallet): first enable can go live immediately.
 * Subsequent wallet / QR / enable changes require dual-control proposals.
 * Metadata (recipient, instructions, min, logo) may update without dual-control.
 */
class PaymentDetailsService
{
    public const NETWORK_KEY  = 'bep20';
    public const NETWORK_NAME = 'BEP20';
    public const CHAIN_ID     = 56;

    public function __construct(
        private readonly UsdtPaymentNetworkService $networks = new UsdtPaymentNetworkService(),
        private readonly PaymentQrService $qr = new PaymentQrService(),
        private readonly PaymentDestinationChangeService $destinations = new PaymentDestinationChangeService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null,
        private readonly ?Blockchain $blockchain = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureBep20(): array
    {
        $existing = $this->networks->findByKey(self::NETWORK_KEY);
        if ($existing !== null) {
            return $existing;
        }

        $pattern = ($this->config ?? config('Platform'))->bep20Pattern;

        return $this->networks->create([
            'network_key'            => self::NETWORK_KEY,
            'network_name'           => self::NETWORK_NAME,
            'chain_id'               => self::CHAIN_ID,
            'verifier_type'          => 'evm_erc20',
            'token_symbol'           => 'USDT',
            'token_decimals'         => 18,
            'destination_wallet'     => null,
            'is_enabled'             => 0,
            'is_demo'                => 0,
            'display_status'         => 'hidden',
            'min_deposit_usdt'       => '5.00000000',
            'max_deposit_usdt'       => '100000.00000000',
            'required_confirmations' => 15,
            'rpc_url_env_key'        => 'blockchain.evmRpcUrl',
            'address_pattern'        => $pattern,
            'tx_hash_pattern'        => '/^0x[a-fA-F0-9]{64}$/',
            'sort_order'             => 1,
        ], 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function liveForMembers(): ?array
    {
        $readiness = $this->paymentReadiness();
        if (! $readiness['ready']) {
            return null;
        }

        return $readiness['network'];
    }

    /**
     * @return array{
     *   ready: bool,
     *   code: string,
     *   message: string,
     *   network: ?array<string, mixed>
     * }
     */
    public function paymentReadiness(): array
    {
        $row = $this->networks->findByKey(self::NETWORK_KEY);
        $wallet = trim((string) ($row['destination_wallet'] ?? ''));
        if ($row === null
            || (int) ($row['is_enabled'] ?? 0) !== 1
            || ! $this->isUsableBep20Address($wallet)
        ) {
            return [
                'ready'   => false,
                'code'    => 'payment_details_missing',
                'message' => 'Payments are not available right now. Please try again later.',
                'network' => null,
            ];
        }

        if ($this->qr->isDemoNetwork($row) || $this->qr->isBurnOrNullWallet($wallet)) {
            return [
                'ready'   => false,
                'code'    => 'payment_details_missing',
                'message' => 'Payments are not available right now. Please try again later.',
                'network' => null,
            ];
        }

        return [
            'ready'   => true,
            'code'    => 'ok',
            'message' => '',
            'network' => $row,
        ];
    }

    /**
     * @return array{ready: bool, message: string}
     */
    public function verifierStatus(): array
    {
        $bc = $this->blockchain ?? config('Blockchain');
        if (($bc->depositVerifier ?? '') !== 'evm_erc20') {
            return [
                'ready'   => false,
                'message' => 'On-chain verifier is not configured (admin/ops only).',
            ];
        }
        if (trim((string) ($bc->evmRpcUrl ?? '')) === '') {
            return [
                'ready'   => false,
                'message' => 'RPC URL is not configured (admin/ops only).',
            ];
        }

        return ['ready' => true, 'message' => ''];
    }

    public function verifierReady(): bool
    {
        return $this->verifierStatus()['ready'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingDestinationChange(): ?array
    {
        $network = $this->networks->findByKey(self::NETWORK_KEY);
        if ($network === null || ! db_connect()->tableExists('payment_destination_change_proposals')) {
            return null;
        }

        $row = db_connect()->table('payment_destination_change_proposals')
            ->where('network_id', (int) $network['id'])
            ->where('status', 'pending')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $platformAdminDirectApply When true (admin.settings), apply wallet/QR/enable live
     *        instead of dual-control propose — solo platform admins must not get stuck behind self-approval.
     * @return array{network: array<string, mixed>, proposal: ?array<string, mixed>, message: string}
     */
    public function save(
        array $data,
        int $actorUserId,
        ?UploadedFile $logoFile = null,
        ?UploadedFile $qrFile = null,
        bool $platformAdminDirectApply = false
    ): array {
        $network = $this->ensureBep20();
        $pattern = ($this->config ?? config('Platform'))->bep20Pattern;

        $recipient    = mb_substr(trim((string) ($data['recipient_name'] ?? '')), 0, 120);
        $contract     = mb_substr(trim((string) ($data['token_contract_address'] ?? '')), 0, 128);
        $wallet       = mb_substr(trim((string) ($data['receiving_wallet_address'] ?? '')), 0, 128);
        $instructions = trim((string) ($data['instructions'] ?? ''));
        $wantEnable   = ! empty($data['is_enabled']);
        $removeQr     = ! empty($data['remove_qr']);

        $scale = ($this->config ?? config('Platform'))->usdtScale;
        try {
            $min = MoneyMath::normalize((string) ($data['min_deposit_usdt'] ?? '5'), $scale);
            if (! MoneyMath::isPositive($min, $scale)) {
                $min = MoneyMath::normalize('5', $scale);
            }
        } catch (\Throwable) {
            $min = MoneyMath::normalize('5', $scale);
        }

        $logoName = $network['logo_stored_name'] ?? null;
        $logoMime = $network['logo_mime'] ?? null;
        if ($logoFile !== null && $logoFile->getError() !== UPLOAD_ERR_NO_FILE) {
            $logoMeta = $this->storeLogo($logoFile);
            if (! empty($logoName)) {
                $this->deleteLogoFile((string) $logoName);
            }
            $logoName = $logoMeta['stored_name'];
            $logoMime = $logoMeta['mime'];
        }

        // Treat placeholder / invalid stored values as empty so first real setup is bootstrap (not dual-control).
        $storedWallet = trim((string) ($network['destination_wallet'] ?? ''));
        $currentWallet = $this->isUsableBep20Address($storedWallet) ? $storedWallet : '';
        if ($wallet === '') {
            $wallet = $currentWallet;
        }
        if ($wallet !== '' && preg_match($pattern, $wallet) !== 1) {
            throw new InvalidArgumentException('Receiving wallet must be a valid BEP20 address (0x + 40 hex).');
        }
        if ($wallet !== '' && $this->qr->isBurnOrNullWallet($wallet)) {
            throw new InvalidArgumentException('Burn or demo wallets cannot be used as payment destinations.');
        }
        if ($contract !== '' && preg_match($pattern, $contract) !== 1) {
            throw new InvalidArgumentException('USDT token contract must be a valid BEP20 address.');
        }

        $pending = $this->pendingDestinationChange();
        if ($pending !== null) {
            throw new InvalidArgumentException(
                'A destination change is already pending approval (' . ($pending['proposal_ref'] ?? '')
                . '). Approve, or Cancel the pending change first.'
            );
        }

        $walletChanging = $currentWallet !== '' && $wallet !== '' && strcasecmp($currentWallet, $wallet) !== 0;
        $walletBootstrap = $currentWallet === '' && $wallet !== '';
        $enableChanging  = $wantEnable !== ((int) ($network['is_enabled'] ?? 0) === 1);
        $qrChanging      = $removeQr || ($qrFile !== null && $qrFile->getError() !== UPLOAD_ERR_NO_FILE);

        // Always persist non-sensitive metadata immediately.
        $this->applyMetadata((int) $network['id'], [
            'recipient_name'   => $recipient !== '' ? $recipient : null,
            'logo_stored_name' => $logoName,
            'logo_mime'        => $logoMime,
            'min_deposit_usdt' => $min,
            'instructions'     => $instructions !== '' ? $instructions : null,
            'token_contract_address' => $contract !== ''
                ? $contract
                : ($this->isUsableBep20Address((string) ($network['token_contract_address'] ?? ''))
                    ? $network['token_contract_address']
                    : null),
            'address_pattern'  => $pattern,
            'tx_hash_pattern'  => '/^0x[a-fA-F0-9]{64}$/',
        ], $actorUserId);

        // Bootstrap first usable wallet (including replacing placeholder text): apply live immediately.
        // Platform admins with Settings also apply sensitive changes live (audited) so the form never
        // locks them behind a pending proposal they cannot self-approve.
        if ($walletBootstrap || ($platformAdminDirectApply && ($walletChanging || $qrChanging || $enableChanging))) {
            if ($platformAdminDirectApply && ! $walletBootstrap) {
                $this->audit->log('payment.details_platform_admin_direct_apply', $actorUserId, 'usdt_payment_network', self::NETWORK_KEY, [
                    'wallet_changing' => $walletChanging,
                    'qr_changing'     => $qrChanging,
                    'enable_changing' => $enableChanging,
                ]);
            }

            return $this->applyLiveDestination(
                $network,
                $actorUserId,
                $wallet !== '' ? $wallet : $currentWallet,
                $contract,
                $recipient,
                $min,
                $instructions,
                $wantEnable,
                $logoName,
                $logoMime,
                $qrFile,
                $removeQr
            );
        }

        // Subsequent sensitive changes require dual-control (non–platform-admin operators).
        if ($walletChanging || $qrChanging || ($enableChanging && $wantEnable === false) || ($enableChanging && $wantEnable)) {
            $qrMode    = 'keep';
            $staging   = null;
            $stagingMime = null;
            $decoded   = null;

            if ($removeQr) {
                $qrMode = 'remove';
            } elseif ($qrFile !== null && $qrFile->getError() !== UPLOAD_ERR_NO_FILE) {
                if ($qrFile->getSize() > 4 * 1024 * 1024) {
                    throw new InvalidArgumentException('Payment QR image must be at most 4 MB.');
                }
                try {
                    $stored = $this->qr->storeUploadedQr($qrFile, $wallet !== '' ? $wallet : 'pending', 'qr_stage');
                } catch (\Throwable) {
                    $stored = $this->qr->storeUploadedQrLoose($qrFile, 'qr_stage');
                }
                $qrMode      = 'upload';
                $staging     = $stored['stored_name'];
                $stagingMime = $stored['mime'];
                $decoded     = $stored['decoded_payload'] !== '' ? $stored['decoded_payload'] : null;
            } elseif ($walletChanging && empty($network['qr_stored_name'])) {
                try {
                    $stored      = $this->qr->generateFromWallet($wallet, 'qr_stage');
                    $qrMode      = 'generate';
                    $staging     = $stored['stored_name'];
                    $stagingMime = $stored['mime'];
                    $decoded     = $stored['decoded_payload'];
                } catch (\Throwable) {
                    $qrMode = 'keep';
                }
            }

            // propose() requires wallet change OR qr change; for enable-only, force a settings proposal
            // by using skip when wallet unchanged via generate keep + display_enabled.
            if (! $walletChanging && ! $qrChanging && $enableChanging) {
                // Enable/disable without wallet change: propose with same wallet + display flag.
                $proposal = $this->destinations->propose([
                    'network_id'               => (int) $network['id'],
                    'to_destination_wallet'    => $currentWallet,
                    'instructions'             => $instructions,
                    'display_enabled'          => $wantEnable,
                    'min_deposit_usdt'         => $min,
                    'qr_mode'                  => 'keep',
                    'recipient_name'           => $recipient !== '' ? $recipient : null,
                    'token_contract_address'   => $contract !== '' ? $contract : ($network['token_contract_address'] ?? null),
                    'allow_same_wallet'        => true,
                ], $actorUserId);
            } else {
                $proposal = $this->destinations->propose([
                    'network_id'               => (int) $network['id'],
                    'to_destination_wallet'    => $wallet !== '' ? $wallet : $currentWallet,
                    'instructions'             => $instructions,
                    'display_enabled'          => $wantEnable,
                    'min_deposit_usdt'         => $min,
                    'qr_mode'                  => $qrMode,
                    'qr_staging_stored_name'   => $staging,
                    'qr_staging_mime'          => $stagingMime,
                    'qr_decoded_payload'       => $decoded,
                    'recipient_name'           => $recipient !== '' ? $recipient : null,
                    'token_contract_address'   => $contract !== '' ? $contract : ($network['token_contract_address'] ?? null),
                    'allow_same_wallet'        => ! $walletChanging,
                ], $actorUserId);
            }

            $this->audit->log('payment.details_change_proposed', $actorUserId, 'usdt_payment_network', self::NETWORK_KEY, [
                'proposal_ref' => $proposal['proposal_ref'] ?? null,
            ]);

            return [
                'network'  => $this->networks->findByKey(self::NETWORK_KEY) ?? $network,
                'proposal' => $proposal,
                'message'  => 'Destination change submitted for dual-control approval. Live wallet is unchanged until approved.',
            ];
        }

        $this->audit->log('payment.details_metadata_saved', $actorUserId, 'usdt_payment_network', self::NETWORK_KEY, []);

        return [
            'network'  => $this->networks->findByKey(self::NETWORK_KEY) ?? $network,
            'proposal' => null,
            'message'  => 'Payment details saved.',
        ];
    }

    /**
     * @param array<string, mixed> $network
     * @return array{network: array<string, mixed>, proposal: null, message: string}
     */
    private function applyLiveDestination(
        array $network,
        int $actorUserId,
        string $wallet,
        string $contract,
        string $recipient,
        string $min,
        string $instructions,
        bool $wantEnable,
        ?string $logoName,
        ?string $logoMime,
        ?UploadedFile $qrFile,
        bool $removeQr
    ): array {
        $pattern = ($this->config ?? config('Platform'))->bep20Pattern;
        $qrStored   = $network['qr_stored_name'] ?? null;
        $qrMime     = $network['qr_mime'] ?? null;
        $qrSource   = $network['qr_source'] ?? PaymentQrService::SOURCE_NONE;
        $qrDecoded  = $network['qr_decoded_payload'] ?? null;
        $qrVerified = $network['qr_verified_at'] ?? null;

        if ($removeQr) {
            if (! empty($qrStored)) {
                $this->qr->deleteIfExists((string) $qrStored);
            }
            $qrStored = $qrMime = $qrDecoded = $qrVerified = null;
            $qrSource = PaymentQrService::SOURCE_NONE;
        } elseif ($qrFile !== null && $qrFile->getError() !== UPLOAD_ERR_NO_FILE) {
            if ($qrFile->getSize() > 4 * 1024 * 1024) {
                throw new InvalidArgumentException('Payment QR image must be at most 4 MB.');
            }
            try {
                $stored = $this->qr->storeUploadedQr($qrFile, $wallet, 'qr_live');
            } catch (\Throwable) {
                $stored = $this->qr->storeUploadedQrLoose($qrFile, 'qr_live');
            }
            if (! empty($qrStored)) {
                $this->qr->deleteIfExists((string) $qrStored);
            }
            $qrStored   = $stored['stored_name'];
            $qrMime     = $stored['mime'];
            $qrSource   = $stored['source'];
            $qrDecoded  = $stored['decoded_payload'] !== '' ? $stored['decoded_payload'] : null;
            $qrVerified = date('Y-m-d H:i:s');
        } elseif ($wallet !== '' && empty($qrStored)) {
            try {
                $stored     = $this->qr->generateFromWallet($wallet, 'qr_live');
                $qrStored   = $stored['stored_name'];
                $qrMime     = $stored['mime'];
                $qrSource   = $stored['source'];
                $qrDecoded  = $stored['decoded_payload'];
                $qrVerified = date('Y-m-d H:i:s');
            } catch (\Throwable) {
                // Optional auto-QR.
            }
        }

        $enableNow = $wantEnable && $wallet !== '' ? 1 : 0;
        $contractForSave = $contract !== ''
            ? $contract
            : trim((string) ($network['token_contract_address'] ?? ''));

        $payload = [
            'network_name'           => self::NETWORK_NAME,
            'chain_id'               => self::CHAIN_ID,
            'verifier_type'          => 'evm_erc20',
            'token_symbol'           => 'USDT',
            'token_contract_address' => $contractForSave !== '' ? $contractForSave : null,
            'token_decimals'         => (int) ($network['token_decimals'] ?? 18),
            'destination_wallet'     => $wallet !== '' ? $wallet : null,
            'address_pattern'        => $pattern,
            'tx_hash_pattern'        => '/^0x[a-fA-F0-9]{64}$/',
            'required_confirmations' => (int) ($network['required_confirmations'] ?? 15),
            'min_deposit_usdt'       => $min,
            'max_deposit_usdt'       => (string) ($network['max_deposit_usdt'] ?? '100000.00000000'),
            'instructions'           => $instructions !== '' ? $instructions : null,
            'rpc_url_env_key'        => 'blockchain.evmRpcUrl',
            'is_enabled'             => $enableNow,
            'is_demo'                => 0,
            'display_status'         => $enableNow === 1 ? 'live' : 'hidden',
            'sort_order'             => 1,
            'qr_stored_name'         => $qrStored,
            'qr_mime'                => $qrMime,
            'qr_source'              => $qrSource,
            'qr_decoded_payload'     => $qrDecoded,
            'qr_verified_at'         => $qrVerified,
        ];

        $updated = $this->networks->update((int) $network['id'], $payload, $actorUserId);
        db_connect()->table('usdt_payment_networks')->where('id', (int) $network['id'])->update([
            'recipient_name'   => $recipient !== '' ? $recipient : null,
            'logo_stored_name' => $logoName,
            'logo_mime'        => $logoMime,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log('payment.details_bootstrapped', $actorUserId, 'usdt_payment_network', self::NETWORK_KEY, [
            'enabled' => $enableNow,
        ]);

        return [
            'network'  => $this->networks->findByKey(self::NETWORK_KEY) ?? $updated,
            'proposal' => null,
            'message'  => 'Payment details saved and enabled for members.',
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function applyMetadata(int $networkId, array $fields, int $actorUserId): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        db_connect()->table('usdt_payment_networks')->where('id', $networkId)->update($fields);
        $this->audit->log('payment.details_metadata_updated', $actorUserId, 'usdt_payment_network', self::NETWORK_KEY, [
            'fields' => array_keys($fields),
        ]);
    }

    /**
     * @return array{stored_name: string, mime: string}
     */
    private function storeLogo(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Network logo upload is invalid.');
        }
        $max = 4 * 1024 * 1024;
        if ($file->getSize() <= 0 || $file->getSize() > $max) {
            throw new InvalidArgumentException('Network logo must be at most 4 MB.');
        }
        $ext = strtolower((string) $file->getExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new InvalidArgumentException('Network logo must be PNG, JPG, or WebP.');
        }
        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException('Network logo file type is not allowed.');
        }
        if (@getimagesize($file->getTempName()) === false) {
            throw new InvalidArgumentException('Network logo image is invalid.');
        }

        $dir = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment_logo';
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create logo upload directory.');
        }
        $stored = 'logo_' . bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $file->move($dir, $stored, true);

        return ['stored_name' => $stored, 'mime' => $mime];
    }

    private function deleteLogoFile(string $storedName): void
    {
        $path = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment_logo' . DIRECTORY_SEPARATOR . basename($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function logoAbsolutePath(string $storedName): string
    {
        $path = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment_logo' . DIRECTORY_SEPARATOR . basename($storedName);
        if (! is_file($path)) {
            throw new InvalidArgumentException('Logo not found.');
        }

        return $path;
    }

    /**
     * True when value is a real BEP20 address (not empty/placeholder UI text).
     */
    public function isUsableBep20Address(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        $pattern = ($this->config ?? config('Platform'))->bep20Pattern;

        return preg_match($pattern, $value) === 1;
    }
}
