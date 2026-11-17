<?php

declare(strict_types=1);

namespace App\Controllers\SupportView;

use App\Controllers\BaseController;
use App\Services\ImpersonationService;
use InvalidArgumentException;

/**
 * Claim / end path-scoped support-view sessions without swapping Shield identity.
 */
class SessionController extends BaseController
{
    public function start()
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            return redirect()->to(site_url('support-view/ended'))->with('error', 'Invalid start method.');
        }

        $token = (string) $this->request->getPost('grant_token');
        $svc   = service('impersonationService');

        try {
            $claimed = $svc->claimGrant($token, (int) auth()->id());
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return redirect()->to(site_url('support-view/ended'))->with('error', $e->getMessage());
        }

        $response = redirect()->to(site_url('support-view/dashboard'));
        $response = $svc->attachSessionCookie(
            $response,
            $claimed['raw_token'],
            ImpersonationService::SESSION_MAX_SECONDS
        );

        return $response->with('success', 'Support view started (read-only).');
    }

    public function end()
    {
        $svc   = service('impersonationService');
        $token = $this->request->getCookie(ImpersonationService::COOKIE_NAME);
        $svc->endSessionByToken(is_string($token) ? $token : null, 'ended');

        $response = redirect()->to(site_url('support-view/ended'));
        $response = $svc->clearSessionCookie($response);

        return $response->with('success', 'Member support view ended.');
    }

    public function ended()
    {
        return view('support_view/ended', [
            'title' => 'Support View Ended',
        ]);
    }
}
