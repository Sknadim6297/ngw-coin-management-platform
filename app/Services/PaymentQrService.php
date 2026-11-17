<?php

declare(strict_types=1);

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Secure USDT payment QR upload, decode validation, generation, and authorized storage.
 * Never trusts an uploaded image merely because it looks like a QR.
 */
class PaymentQrService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const SOURCE_UPLOADED  = 'uploaded';
    public const SOURCE_GENERATED = 'generated';
    public const SOURCE_NONE      = 'none';

    /** Known burn / null destinations that must never be live payment targets. */
    private const BURN_WALLETS = [
        '0x000000000000000000000000000000000000dead',
        '0x0000000000000000000000000000000000000000',
    ];

    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    public function isBurnOrNullWallet(string $wallet): bool
    {
        return in_array(strtolower(trim($wallet)), self::BURN_WALLETS, true);
    }

    /**
     * @param array<string, mixed> $network
     */
    public function isDemoNetwork(array $network): bool
    {
        if (! empty($network['is_demo'])) {
            return true;
        }

        $wallet = trim((string) ($network['destination_wallet'] ?? ''));
        if ($wallet !== '' && $this->isBurnOrNullWallet($wallet)) {
            return true;
        }

        $key  = strtolower((string) ($network['network_key'] ?? ''));
        $name = strtolower((string) ($network['network_name'] ?? ''));
        if (str_contains($key, 'demo') || str_contains($name, 'demo')) {
            return true;
        }
        if (str_contains($name, 'test') && (str_contains($name, 'evm') || str_contains($name, 'ngw'))) {
            return true;
        }

        return false;
    }

    /**
     * Store an uploaded QR after MIME/size checks and payload match against the target wallet.
     *
     * @return array{stored_name: string, mime: string, decoded_payload: string, source: string, size: int}
     */
    public function storeUploadedQr(UploadedFile $file, string $expectedWallet, string $prefix = 'qr'): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Uploaded QR file is invalid.');
        }

        $maxBytes = $this->maxBytes();
        if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException(
                'QR image must be between 1 byte and ' . (int) ($maxBytes / 1024 / 1024) . ' MB.'
            );
        }

        $extension = strtolower((string) $file->getExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('QR must be a JPG, PNG, or WEBP image.');
        }

        $clientMime = strtolower((string) $file->getClientMimeType());
        $detected   = $this->detectMime($file->getTempName());
        if (! in_array($clientMime, self::ALLOWED_MIMES, true) || ! in_array($detected, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('QR file type is not allowed.');
        }

        if (@getimagesize($file->getTempName()) === false) {
            throw new InvalidArgumentException('QR image content is invalid.');
        }

        $decoded = $this->decodePayload($file->getTempName());
        $this->assertPayloadMatchesWallet($decoded, $expectedWallet);

        $dir = $this->storageDirectory();
        $this->ensureDirectory($dir);

        $ext        = $extension === 'jpeg' ? 'jpg' : $extension;
        $storedName = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $file->move($dir, $storedName, true);

        return [
            'stored_name'      => $storedName,
            'mime'             => $detected,
            'decoded_payload'  => $decoded,
            'source'           => self::SOURCE_UPLOADED,
            'size'             => (int) filesize($dir . DIRECTORY_SEPARATOR . $storedName),
        ];
    }

    /**
     * Generate a QR PNG that encodes the wallet address, then re-decode to verify.
     *
     * @return array{stored_name: string, mime: string, decoded_payload: string, source: string, size: int}
     */
    public function generateFromWallet(string $wallet, string $prefix = 'qrgen'): array
    {
        $wallet = trim($wallet);
        if ($wallet === '') {
            throw new InvalidArgumentException('Cannot generate QR for an empty wallet address.');
        }

        $dir = $this->storageDirectory();
        $this->ensureDirectory($dir);

        $storedName = $prefix . '_' . bin2hex(random_bytes(16)) . '.png';
        $path       = $dir . DIRECTORY_SEPARATOR . $storedName;

        $options = new QROptions([
            'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
            'scale'        => 8,
            'imageBase64'  => false,
        ]);

        try {
            (new QRCode($options))->render($wallet, $path);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to generate payment QR image.', 0, $e);
        }

        if (! is_file($path)) {
            throw new RuntimeException('Generated QR file was not written.');
        }

        $decoded = $this->decodePayload($path);
        // Best-effort match only when both sides look like EVM addresses.
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet) === 1) {
            $this->assertPayloadMatchesWallet($decoded, $wallet);
        }

        return [
            'stored_name'     => $storedName,
            'mime'            => 'image/png',
            'decoded_payload' => $decoded,
            'source'          => self::SOURCE_GENERATED,
            'size'            => (int) filesize($path),
        ];
    }

    /**
     * Store an uploaded QR/image without requiring wallet-payload match (admin free-form save).
     *
     * @return array{stored_name: string, mime: string, decoded_payload: string, source: string, size: int}
     */
    public function storeUploadedQrLoose(UploadedFile $file, string $prefix = 'qr'): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Uploaded QR file is invalid.');
        }

        $maxBytes = $this->maxBytes();
        if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException(
                'QR image must be between 1 byte and ' . (int) ($maxBytes / 1024 / 1024) . ' MB.'
            );
        }

        $extension = strtolower((string) $file->getExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('QR must be a JPG, PNG, or WEBP image.');
        }

        $detected = $this->detectMime($file->getTempName());
        if (! in_array($detected, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('QR file type is not allowed.');
        }

        if (@getimagesize($file->getTempName()) === false) {
            throw new InvalidArgumentException('QR image content is invalid.');
        }

        $decoded = '';
        try {
            $decoded = $this->decodePayload($file->getTempName());
        } catch (Throwable) {
            $decoded = '';
        }

        $dir = $this->storageDirectory();
        $this->ensureDirectory($dir);

        $ext        = $extension === 'jpeg' ? 'jpg' : $extension;
        $storedName = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $file->move($dir, $storedName, true);

        return [
            'stored_name'     => $storedName,
            'mime'            => $detected,
            'decoded_payload' => $decoded,
            'source'          => self::SOURCE_UPLOADED,
            'size'            => (int) filesize($dir . DIRECTORY_SEPARATOR . $storedName),
        ];
    }

    public function absolutePath(string $storedName): string
    {
        $storedName = basename($storedName);
        if ($storedName === '' || str_contains($storedName, '..')) {
            throw new InvalidArgumentException('Invalid QR reference.');
        }

        $path = $this->storageDirectory() . DIRECTORY_SEPARATOR . $storedName;
        if (! is_file($path)) {
            throw new InvalidArgumentException('QR image not found.');
        }

        return $path;
    }

    public function deleteIfExists(?string $storedName): void
    {
        if ($storedName === null || $storedName === '') {
            return;
        }

        try {
            @unlink($this->absolutePath($storedName));
        } catch (Throwable) {
            // Ignore missing files during cleanup.
        }
    }

    /**
     * Promote a staging QR into the active namespace (copy + new name) for approved config.
     *
     * @return array{stored_name: string, mime: string}
     */
    public function promoteStaging(string $stagingStoredName, string $mime): array
    {
        $src = $this->absolutePath($stagingStoredName);
        $ext = pathinfo($src, PATHINFO_EXTENSION) ?: 'png';
        $dir = $this->storageDirectory();
        $this->ensureDirectory($dir);

        $destName = 'qr_live_' . bin2hex(random_bytes(16)) . '.' . strtolower((string) $ext);
        $dest     = $dir . DIRECTORY_SEPARATOR . $destName;
        if (! @copy($src, $dest)) {
            throw new RuntimeException('Unable to promote approved QR image.');
        }

        return [
            'stored_name' => $destName,
            'mime'        => $mime !== '' ? $mime : 'image/png',
        ];
    }

    public function decodePayload(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new InvalidArgumentException('QR image not found for decoding.');
        }

        try {
            $result = (new QRCode())->readFromFile($absolutePath);
            $payload = trim((string) $result);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'Unable to decode a QR payload from the uploaded image. Rejected as unverifiable.',
                0,
                $e
            );
        }

        if ($payload === '') {
            throw new InvalidArgumentException('Decoded QR payload is empty. Rejected.');
        }

        return $payload;
    }

    public function assertPayloadMatchesWallet(string $payload, string $expectedWallet): void
    {
        $expected = strtolower(trim($expectedWallet));
        if ($expected === '' || ! preg_match('/^0x[a-f0-9]{40}$/', $expected)) {
            throw new InvalidArgumentException('Configured wallet address is invalid for QR matching.');
        }

        if ($this->isBurnOrNullWallet($expected)) {
            throw new InvalidArgumentException('Burn or null wallets cannot be used as payment destinations.');
        }

        $extracted = $this->extractEvmAddress($payload);
        if ($extracted === null) {
            throw new InvalidArgumentException(
                'QR payload does not contain a supported EVM wallet address. Unsupported or unverifiable payload rejected.'
            );
        }

        if (strcasecmp($extracted, $expected) !== 0) {
            throw new InvalidArgumentException(
                'QR destination does not match the configured receiving wallet address. Mismatched QR rejected.'
            );
        }

        // Reject obvious non-EVM / wrong-network URI schemes when present.
        $lower = strtolower($payload);
        foreach (['bitcoin:', 'bitcoincash:', 'solana:', 'tron:', 'ripple:', 'litecoin:'] as $bad) {
            if (str_starts_with($lower, $bad)) {
                throw new InvalidArgumentException('QR payload targets an unsupported blockchain network.');
            }
        }
    }

    public function extractEvmAddress(string $payload): ?string
    {
        $payload = trim($payload);
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $payload) === 1) {
            return $payload;
        }

        // ethereum:0x... or EIP-681 ethereum:0x...@chainId / transfer forms
        if (preg_match('/(?:ethereum:)?(0x[a-fA-F0-9]{40})/i', $payload, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    public function publicMediaUrl(string $networkKey, ?string $storedName): ?string
    {
        if ($storedName === null || $storedName === '') {
            return null;
        }

        return site_url('media/payment-qr/' . rawurlencode($networkKey));
    }

    private function storageDirectory(): string
    {
        return rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment_qr';
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to prepare payment QR storage.');
        }
    }

    private function detectMime(string $tempPath): string
    {
        if (! is_file($tempPath)) {
            return '';
        }

        return strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($tempPath));
    }

    private function maxBytes(): int
    {
        $cfg = $this->config ?? config('Platform');

        return (int) ($cfg->paymentQrMaxBytes ?? $cfg->depositEvidenceMaxBytes ?? 5242880);
    }
}
