<?php

declare(strict_types=1);

namespace App\Controllers\Media;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Serves the configured platform logo from writable storage.
 */
class PlatformLogoController extends BaseController
{
    public function show()
    {
        $path = trim(service('platformSettingsService')->get('platform_logo', ''));
        if ($path === '' || str_contains($path, '..')) {
            throw PageNotFoundException::forPageNotFound();
        }

        $full = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (! is_file($full)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $mime = mime_content_type($full) ?: 'application/octet-stream';
        if (! str_starts_with((string) $mime, 'image/')) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Cache-Control', 'public, max-age=3600')
            ->setBody((string) file_get_contents($full));
    }
}
