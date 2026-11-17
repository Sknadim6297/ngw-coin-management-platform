<?php

declare(strict_types=1);

namespace App\Controllers\Media;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Authorized public media route for approved payment QR images.
 * Serves files from writable storage only after network + auth checks.
 */
class PaymentQrController extends BaseController
{
    public function show(string $networkKey): ResponseInterface
    {
        $user = auth()->user();
        if ($user === null) {
            return $this->response->setStatusCode(401)->setBody('Authentication required.');
        }

        $isAdmin = $user->can('admin.access')
            || $user->can('admin.settings')
            || $user->can('finance.monitor.view');
        $isMember = $user->inGroup('member') || $user->can('member.access');

        if (! $isAdmin && ! $isMember) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $network = service('usdtPaymentNetworkService')->findByKey($networkKey);
        if ($network === null) {
            return $this->response->setStatusCode(404)->setBody('Not found.');
        }

        $qr = service('paymentQrService');
        if ($qr->isDemoNetwork($network) || $qr->isBurnOrNullWallet((string) ($network['destination_wallet'] ?? ''))) {
            return $this->response->setStatusCode(404)->setBody('Not found.');
        }

        // Members may only load QR for the live BEP20 payment details network.
        if (! $isAdmin) {
            $live = service('paymentDetailsService')->liveForMembers();
            if ($live === null || (int) $live['id'] !== (int) $network['id']) {
                return $this->response->setStatusCode(404)->setBody('Not found.');
            }
        }

        $stored = (string) ($network['qr_stored_name'] ?? '');
        if ($stored === '') {
            return $this->response->setStatusCode(404)->setBody('QR not configured.');
        }

        try {
            $path = $qr->absolutePath($stored);
        } catch (Throwable) {
            return $this->response->setStatusCode(404)->setBody('QR not found.');
        }

        $mime = (string) ($network['qr_mime'] ?? 'image/png');
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $mime = 'image/png';
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Cache-Control', 'private, max-age=300')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents($path));
    }
}
