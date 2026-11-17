<?php

declare(strict_types=1);

namespace App\Controllers;

use InvalidArgumentException;

/**
 * Public single-use email-change confirmation (token in URL).
 */
class ConfirmEmailChangeController extends BaseController
{
    public function confirm(string $token)
    {
        try {
            $result = service('memberProfileService')->confirmEmailChange($token);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('login'))->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Email change confirm failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->to(site_url('login'))->with('error', 'Unable to confirm email change.');
        }

        $target = auth()->loggedIn() ? site_url('member/profile') : site_url('login');

        return redirect()->to($target)->with(
            'success',
            'Email verified. Your login email is now ' . $result['email'] . '.'
        );
    }
}
