<?php

declare(strict_types=1);

namespace App\Validation;

use Config\Platform;

class MemberRules
{
    public function valid_bep20_wallet(string $value, ?string &$error = null): bool
    {
        /** @var Platform $config */
        $config = config('Platform');

        if (preg_match($config->bep20Pattern, $value) !== 1) {
            $error = 'USDT wallet must be a valid BEP20 address (0x followed by 40 hex characters).';

            return false;
        }

        return true;
    }

    public function valid_sponsor_id(string $value, ?string &$error = null): bool
    {
        $result = service('sponsorValidator')->validate($value);

        if (! $result['valid']) {
            $error = $result['message'];

            return false;
        }

        return true;
    }
}
