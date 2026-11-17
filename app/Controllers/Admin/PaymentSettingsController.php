<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Phase 8 unified payment settings (USDT receiving destination + QR preview).
 * Destination wallet changes require dual-control approval.
 */
class PaymentSettingsController extends BaseController
{
    public function index()
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $networks = service('usdtPaymentNetworkService')->listAll();
        $verifier = (string) (config('Blockchain')->depositVerifier ?? 'not_configured');

        return view('admin/payment_settings/index', [
            'title'      => 'Payment Settings',
            'networks'   => $networks,
            'proposals'  => service('paymentDestinationChangeService')->listRecent(30),
            'verifier'   => $verifier,
            'canPropose' => $this->canPropose(),
            'canApprove' => $this->canApprove(),
        ]);
    }

    public function proposeDestination()
    {
        if (! $this->canPropose()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            $row = service('paymentDestinationChangeService')->propose([
                'network_id'            => (int) $this->request->getPost('network_id'),
                'to_destination_wallet' => (string) $this->request->getPost('to_destination_wallet'),
                'instructions'          => (string) $this->request->getPost('instructions'),
                'display_enabled'       => (bool) $this->request->getPost('display_enabled'),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/payment-settings'))
            ->with('success', 'Destination change proposed (' . $row['proposal_ref'] . '). Dual-control approval required before members see the new address.');
    }

    public function approveDestination(string $ref)
    {
        if (! $this->canApprove()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('paymentDestinationChangeService')->approve($ref, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/payment-settings'))
            ->with('success', 'Destination approved. Existing pending deposits keep their original address snapshot.');
    }

    public function rejectDestination(string $ref)
    {
        if (! $this->canApprove()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        try {
            service('paymentDestinationChangeService')->reject(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('reason')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/payment-settings'))->with('success', 'Destination change rejected.');
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

        // Operators propose; platform admins may also propose (self-approval still blocked).
        return $user !== null && (
            $user->can('finance.remediation.propose')
            || $user->can('admin.members.manage')
            || $user->can('admin.settings')
        );
    }

    private function canApprove(): bool
    {
        $user = auth()->user();

        // Approvers only — keeps dual-control when admin proposed.
        return $user !== null && $user->can('finance.remediation.approve');
    }
}
