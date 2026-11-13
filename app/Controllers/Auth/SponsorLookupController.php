<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

class SponsorLookupController extends BaseController
{
    public function lookup()
    {
        $sponsorId = trim((string) $this->request->getPost('sponsor_id'));
        $result    = service('sponsorValidator')->validate($sponsorId);

        $csrf = [
            'csrf_token_name' => csrf_token(),
            'csrf_hash'       => csrf_hash(),
        ];

        if (! $result['valid']) {
            return $this->response->setStatusCode(422)->setJSON([
                'valid'   => false,
                'message' => $result['message'],
                'name'    => null,
            ] + $csrf);
        }

        return $this->response->setJSON([
            'valid'   => true,
            'message' => $result['message'],
            'name'    => $result['profile']->member_name,
        ] + $csrf);
    }
}
