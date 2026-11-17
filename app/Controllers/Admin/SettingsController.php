<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

class SettingsController extends BaseController
{
    public function index()
    {
        if (! $this->canManageSettings()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage settings.');
        }

        $tab = $this->normalizeTab((string) $this->request->getGet('tab'));
        $settings = service('platformSettingsService')->all();
        $audit    = ['rows' => [], 'total' => 0];
        if ($tab === 'audit') {
            $audit = service('platformSettingsService')->auditHistory(
                max(1, (int) $this->request->getGet('page')),
                25
            );
        }

        return view('admin/settings/index', [
            'title'            => 'Platform Settings',
            'tab'              => $tab,
            'tabs'             => $this->tabs($tab),
            'settings'         => $settings,
            'security'         => service('platformSettingsService')->securitySnapshot(),
            'notifyInfra'      => service('platformSettingsService')->notificationInfrastructureStatus(),
            'audit'            => $audit,
            'dateFormats'      => \App\Services\PlatformSettingsService::DATE_FORMATS,
            'timezones'        => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update()
    {
        if (! $this->canManageSettings()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage settings.');
        }

        $group = strtolower(trim((string) $this->request->getPost('group')));
        try {
            $result = service('platformSettingsService')->updateGroup($group, $this->request->getPost() ?? [], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Settings update failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to save settings.');
        }

        $msg = $result['changed'] === []
            ? 'No setting changes detected.'
            : 'Settings updated successfully.';

        $tab = match ($group) {
            'registration'  => 'registration',
            'notifications' => 'notifications',
            default         => 'general',
        };

        return redirect()->to(site_url('admin/settings?tab=' . $tab))->with('success', $msg);
    }

    public function uploadLogo()
    {
        if (! $this->canManageSettings()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to manage settings.');
        }

        try {
            service('platformSettingsService')->storeLogo($this->request->getFile('platform_logo'), (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/settings?tab=general'))->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Logo upload failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->to(site_url('admin/settings?tab=general'))->with('error', 'Unable to upload logo.');
        }

        return redirect()->to(site_url('admin/settings?tab=general'))->with('success', 'Platform logo updated.');
    }

    private function canManageSettings(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->inGroup('admin') || $user->can('admin.settings');
    }

    private function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, ['general', 'registration', 'notifications', 'security', 'audit'], true)
            ? $tab
            : 'general';
    }

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    private function tabs(string $active): array
    {
        $keys = [
            'general'       => 'General',
            'registration'  => 'Member Registration',
            'notifications' => 'Notifications',
            'security'      => 'Security',
            'audit'         => 'Audit History',
        ];
        $tabs = [];
        foreach ($keys as $key => $label) {
            $tabs[] = [
                'key'   => $key,
                'label' => $label,
                'url'   => site_url('admin/settings?tab=' . $key),
            ];
        }

        return $tabs;
    }
}
