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
        'usdt_wallet',
        'account_status',
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

    public function countByStatus(?string $status = null): int
    {
        $builder = $this->builder();

        if ($status !== null) {
            $builder->where('account_status', $status);
        }

        return (int) $builder->countAllResults();
    }

    /**
     * @return list<MemberProfile>
     */
    public function recentRegistrations(int $limit = 8): array
    {
        return $this->orderBy('created_at', 'DESC')->findAll($limit);
    }
}
