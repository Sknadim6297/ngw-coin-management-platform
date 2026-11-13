<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stores deposit evidence outside the public web root.
 */
class EvidenceUploadService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{stored_name: string, original_name: string, mime: string, size: int}
     */
    public function storeDepositEvidence(UploadedFile $file, string $depositRef): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Uploaded evidence file is invalid.');
        }

        $maxBytes = ($this->config ?? config('Platform'))->depositEvidenceMaxBytes;
        if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException(
                'Evidence file must be between 1 byte and ' . (int) ($maxBytes / 1024 / 1024) . ' MB.'
            );
        }

        $extension = strtolower((string) $file->getExtension());
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Evidence must be a JPG, PNG, WEBP, or PDF file.');
        }

        $clientMime = strtolower((string) $file->getClientMimeType());
        $detected   = $this->detectMime($file->getTempName());

        if (! in_array($clientMime, self::ALLOWED_MIMES, true) || ! in_array($detected, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Evidence file type is not allowed.');
        }

        if (in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            if (@getimagesize($file->getTempName()) === false) {
                throw new InvalidArgumentException('Evidence image content is invalid.');
            }
        }

        if ($detected === 'application/pdf') {
            $header = (string) @file_get_contents($file->getTempName(), false, null, 0, 5);
            if (! str_starts_with($header, '%PDF-')) {
                throw new InvalidArgumentException('Evidence PDF content is invalid.');
            }
        }

        $dir = $this->storageDirectory();
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to prepare evidence storage.');
        }

        $storedName = 'ev_' . bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $file->move($dir, $storedName, true);

        $original = basename((string) $file->getClientName());
        $original = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $original) ?: 'evidence';
        $original = substr($original, 0, 200);

        return [
            'stored_name'   => $storedName,
            'original_name' => $original,
            'mime'          => $detected,
            'size'          => (int) filesize($dir . DIRECTORY_SEPARATOR . $storedName),
        ];
    }

    public function absolutePath(string $storedName): string
    {
        $storedName = basename($storedName);
        if ($storedName === '' || str_contains($storedName, '..')) {
            throw new InvalidArgumentException('Invalid evidence reference.');
        }

        $path = $this->storageDirectory() . DIRECTORY_SEPARATOR . $storedName;
        if (! is_file($path)) {
            throw new InvalidArgumentException('Evidence file not found.');
        }

        return $path;
    }

    public function deleteIfExists(?string $storedName): void
    {
        if ($storedName === null || $storedName === '') {
            return;
        }

        try {
            $path = $this->absolutePath($storedName);
            @unlink($path);
        } catch (\Throwable) {
            // Ignore missing files during cleanup.
        }
    }

    private function storageDirectory(): string
    {
        return rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deposit_evidence';
    }

    private function detectMime(string $tempPath): string
    {
        if (! is_file($tempPath)) {
            return '';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = strtolower((string) $finfo->file($tempPath));

        return $mime;
    }
}
