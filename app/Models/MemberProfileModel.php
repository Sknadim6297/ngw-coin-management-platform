<?php

declare(strict_types=1);

namespace App\Models;

use App\Entities\MemberProfile;
use CodeIgniter\Model;

class MemberProfileModel extends Model
{
    protected $table            = 'member_profiles';
    protected $primaryKey       = 'id';
    protected $returnType       = MemberProfile::class;
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'user_id',
        'member_id',
        'member_name',
        'mobile',
        'pending_email',
        'usdt_wallet',
        'usdt_wallet_changed_at',
        'usdt_wallet_cooldown_until',
        'account_status',
        'activated_at',
        'activation_purchase_ref',
        'is_root_sponsor',
    ];

    public function findByMemberId(string $memberId): ?MemberProfile
    {
        return $this->where('member_id', $memberId)->first();
    }

    public function findByUserId(int $userId): ?MemberProfile
    {
        return $this->where('user_id', $userId)->first();
    }

    /**
     * Admin/root sponsor stays in DB for login + sponsor validation,
     * but must not appear in member management / dashboard member KPIs.
     */
    public function excludeRootSponsors(): self
    {
        $this->builder()->where('is_root_sponsor', 0);

        $rootId = trim((string) config('Platform')->rootSponsorId);
        if ($rootId !== '') {
            $this->builder()->where('member_id !=', $rootId);
        }

        return $this;
    }

    public function countByStatus(?string $status = null, bool $excludeRoot = true): int
    {
        if ($excludeRoot) {
            $this->excludeRootSponsors();
        }

        $builder = $this->builder();

        if ($status !== null) {
            $builder->where('account_status', $status);
        }

        return (int) $builder->countAllResults();
    }

    /**
     * @return list<MemberProfile>
     */
    public function recentRegistrations(int $limit = 8, bool $excludeRoot = true): array
    {
        if ($excludeRoot) {
            $this->excludeRootSponsors();
        }

        return $this->orderBy('created_at', 'DESC')->findAll($limit);
    }
}
