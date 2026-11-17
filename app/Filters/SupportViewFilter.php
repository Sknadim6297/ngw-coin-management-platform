<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\ImpersonationService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Phase 10D: isolates /support-view/* behind path-scoped cookie + live admin Shield session.
 * Blocks mutating member actions (read-only support mode).
 */
class SupportViewFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');

        $uri = uri_string();
        $isStart = $uri === 'support-view/start';
        $isEnd   = $uri === 'support-view/end';
        $isEnded = $uri === 'support-view/ended';

        if (! auth()->loggedIn()) {
            return redirect()->to(site_url('admin/login'))
                ->with('error', 'Admin session required for support view.');
        }

        $svc = service('impersonationService');
        if (! $svc->canImpersonate(auth()->user()) && ! $isEnded) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'Impersonation permission required.');
        }

        $method = strtoupper($request->getMethod());
        if (! in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            return service('response')->setStatusCode(405)->setBody('Method not allowed in support view.');
        }

        // Read-only: only start (claim) and end may POST.
        if ($method === 'POST' && ! $isStart && ! $isEnd) {
            return redirect()->to(site_url('support-view/dashboard'))
                ->with('error', 'Read-only Support Mode — actions are blocked.');
        }

        if ($isStart || $isEnd || $isEnded) {
            return null;
        }

        try {
            $token   = $request->getCookie(ImpersonationService::COOKIE_NAME);
            $context = $svc->resolveActiveContext((int) auth()->id(), is_string($token) ? $token : null);
            $svc->setActiveContext($context);
        } catch (\Throwable $e) {
            return redirect()->to(site_url('support-view/ended'))
                ->with('error', $e->getMessage());
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');

        return $response;
    }
}
