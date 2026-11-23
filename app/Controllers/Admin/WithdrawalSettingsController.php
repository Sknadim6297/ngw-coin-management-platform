<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Admin-only commission withdrawal fee / minimum / request activation.
 * Does not write production .env kill-switches.
 */
class WithdrawalSettingsController extends BaseController
{
    public function index()
    {
        if (! $this->canManage()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage withdrawal settings.');
        }

        $settings = service('commissionWithdrawalSettingsService')->current();
        $status   = service('commissionWithdrawalService')->status();

        return view('admin/withdrawal_settings/index', [
            'title'    => 'Withdrawal Settings',
            'settings' => $settings,
            'status'   => $status,
            'audit'    => service('commissionWithdrawalSettingsService')->auditTrail(40),
        ]);
    }

    public function update()
    {
        if (! $this->canManage()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage withdrawal settings.');
        }

        $post = $this->request->getPost() ?? [];
        try {
            service('commissionWithdrawalSettingsService')->update([
                'minimum_usdt'     => (string) ($post['minimum_usdt'] ?? ''),
                'fee_usdt'         => (string) ($post['fee_usdt'] ?? ''),
                'approve_policy'   => ! empty($post['approve_policy']),
                'requests_enabled' => ! empty($post['requests_enabled']),
                'notes'            => (string) ($post['notes'] ?? ''),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Withdrawal settings update failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to save withdrawal settings.');
        }

        return redirect()->to(site_url('admin/withdrawal-settings'))
            ->with('success', 'Withdrawal settings saved.');
    }

    private function canManage(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->inGroup('admin')
            || $user->can('admin.settings')
            || $user->can('commission.withdrawal.review');
    }
}
