<?php

declare(strict_types=1);

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class MemberProfile extends Entity
{
    protected $dates = [
        'created_at',
        'updated_at',
        'activated_at',
        'usdt_wallet_changed_at',
        'usdt_wallet_cooldown_until',
    ];

    protected $casts = [
        'id'              => 'integer',
        'user_id'         => 'integer',
        'is_root_sponsor' => 'boolean',
    ];

    public function isActive(): bool
    {
        return $this->account_status === 'active';
    }

    public function isInactive(): bool
    {
        return $this->account_status === 'inactive';
    }

    public function profileCompletionPercent(): int
    {
        $fields = [
            $this->member_name,
            $this->mobile,
            $this->usdt_wallet,
        ];

        $filled = 0;
        foreach ($fields as $value) {
            if (is_string($value) && trim($value) !== '') {
                $filled++;
            }
        }

        // Email is always required at registration; count as complete.
        $total = count($fields) + 1;

        return (int) round(($filled + 1) / $total * 100);
    }
}
