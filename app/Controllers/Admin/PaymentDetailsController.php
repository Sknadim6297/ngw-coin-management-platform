<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Simplified BEP20 Payment Details admin page.
 */
class PaymentDetailsController extends BaseController
{
    public function index()
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $svc = service('paymentDetailsService');
        $network = $svc->ensureBep20();

        $pending = $svc->pendingDestinationChange();
        $actorId = (int) (auth()->id() ?? 0);

        return view('admin/payment_details/index', [
            'title'      => 'Payment Details',
            'network'    => $network,
            'pending'    => $pending,
            'canSave'    => $this->canPropose(),
            'canApprove' => $this->canApprove(),
            'canCancelPending' => $pending !== null && (
                $this->canApprove()
                || ((int) ($pending['proposed_by'] ?? 0) === $actorId && $this->canPropose())
            ),
            'allowSelfApprove' => $this->canPlatformAdminSelfApprove(),
            'qrUrl'      => service('paymentQrService')->publicMediaUrl(
                (string) $network['network_key'],
                $network['qr_stored_name'] ?? null
            ),
            'logoUrl'    => ! empty($network['logo_stored_name'])
                ? site_url('media/payment-logo/' . rawurlencode((string) $network['network_key']))
                : null,
            'setupHint'  => ! $svc->isUsableBep20Address((string) ($network['destination_wallet'] ?? ''))
                ? 'Current receiving wallet is not set (placeholder). Enter a real BEP20 wallet and Save — first setup applies immediately.'
                : null,
        ]);
    }

    public function save()
    {
        if (! $this->canPropose()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        /** @var UploadedFile|null $logo */
        $logo = $this->request->getFile('network_logo');
        /** @var UploadedFile|null $qr */
        $qr = $this->request->getFile('payment_qr');

        try {
            $result = service('paymentDetailsService')->save([
                'token_contract_address'   => (string) $this->request->getPost('token_contract_address'),
                'receiving_wallet_address' => (string) $this->request->getPost('receiving_wallet_address'),
                'recipient_name'           => (string) $this->request->getPost('recipient_name'),
                'min_deposit_usdt'         => (string) $this->request->getPost('min_deposit_usdt'),
                'instructions'             => (string) $this->request->getPost('instructions'),
                'is_enabled'               => (bool) $this->request->getPost('is_enabled'),
                'remove_qr'                => (bool) $this->request->getPost('remove_qr'),
            ], (int) auth()->id(), $logo, $qr);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Payment details save failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to save payment details.');
        }

        return redirect()->to(site_url('admin/payment-details'))
            ->with('success', $result['message']);
    }

    public function approveDestination(string $ref)
    {
        if (! $this->canApprove()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('paymentDestinationChangeService')->approve(
                $ref,
                (int) auth()->id(),
                $this->canPlatformAdminSelfApprove()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/payment-details'))
            ->with('success', 'Destination change approved. Existing deposit snapshots are unchanged.');
    }

    public function rejectDestination(string $ref)
    {
        $actorId = (int) auth()->id();
        $pending = service('paymentDestinationChangeService')->findByRef($ref);
        $isProposer = $pending !== null && (int) ($pending['proposed_by'] ?? 0) === $actorId;

        // Approvers may reject; proposer may cancel their own pending change to unlock the form.
        if (! $this->canApprove() && ! ($isProposer && $this->canPropose())) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('paymentDestinationChangeService')->reject(
                $ref,
                $actorId,
                (string) ($this->request->getPost('reason')
                    ?: ($isProposer && ! $this->canApprove()
                        ? 'Cancelled by proposer.'
                        : 'Rejected by finance approver.'))
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/payment-details'))
            ->with('success', $isProposer && ! $this->canApprove()
                ? 'Pending destination change cancelled. You can save payment details again.'
                : 'Destination change rejected.');
    }

    public function logo(string $networkKey): ResponseInterface
    {
        $user = auth()->user();
        if ($user === null || ! (
            $user->can('admin.access')
            || $user->can('admin.settings')
            || $user->can('finance.monitor.view')
        )) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $network = service('usdtPaymentNetworkService')->findByKey($networkKey);
        if ($network === null || empty($network['logo_stored_name'])) {
            return $this->response->setStatusCode(404)->setBody('Not found.');
        }

        try {
            $path = service('paymentDetailsService')->logoAbsolutePath((string) $network['logo_stored_name']);
        } catch (Throwable) {
            return $this->response->setStatusCode(404)->setBody('Not found.');
        }

        $mime = (string) ($network['logo_mime'] ?? 'image/png');
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $mime = 'image/png';
        }

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Cache-Control', 'private, max-age=300')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents($path));
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('finance.monitor.view')
            || $user->can('admin.settings')
            || $user->can('admin.access')
        );
    }

    private function canPropose(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('finance.remediation.propose')
            || $user->can('admin.members.manage')
            || $user->can('admin.settings')
        );
    }

    private function canApprove(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('finance.remediation.approve')
            || $user->can('admin.settings')
        );
    }

    /**
     * Solo platform admins may approve their own destination proposals (audited).
     * Dedicated finance operators without admin.settings remain dual-control only.
     */
    private function canPlatformAdminSelfApprove(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('admin.settings');
    }
}
