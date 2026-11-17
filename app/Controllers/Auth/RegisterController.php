<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use InvalidArgumentException;

class RegisterController extends BaseController
{
    public function index()
    {
        $settings = service('platformSettingsService');
        if (! $settings->bool('registration_enabled')) {
            return redirect()->to(site_url('login'))
                ->with('error', 'Public registration is currently disabled.');
        }

        $generator = service('memberIdGenerator');
        $all       = $settings->all();

        return view('auth/register', [
            'title'           => 'Create Account',
            'previewMemberId' => $generator->previewNext(),
            'appName'         => $all['platform_name'] !== '' ? $all['platform_name'] : config('Platform')->appName,
            'sponsorRequired' => $settings->bool('sponsor_required'),
            'regInstructions' => $all['registration_instructions'] ?? '',
        ]);
    }

    public function store()
    {
        if (! service('platformSettingsService')->bool('registration_enabled')) {
            return redirect()->to(site_url('login'))
                ->with('error', 'Public registration is currently disabled.');
        }

        $rules = config('Validation')->memberRegistration;
        if (! service('platformSettingsService')->bool('sponsor_required')) {
            $rules['sponsor_id']['rules'] = 'permit_empty|max_length[32]';
        }

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'member_name' => (string) $this->request->getPost('member_name'),
            'sponsor_id'  => (string) $this->request->getPost('sponsor_id'),
            'mobile'      => (string) $this->request->getPost('mobile'),
            'email'       => strtolower(trim((string) $this->request->getPost('email'))),
            'usdt_wallet' => (string) $this->request->getPost('usdt_wallet'),
            'password'    => (string) $this->request->getPost('password'),
        ];

        try {
            $result = service('registrationService')->register($data, null, 'public');
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Registration failed: {message}', ['message' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Registration could not be completed. Please try again.');
        }

        return redirect()->to(site_url('login'))
            ->with('success', 'Registration successful. Your Member ID is ' . $result['member_id'] . '. Your account is inactive until the first approved coin purchase.');
    }
}
