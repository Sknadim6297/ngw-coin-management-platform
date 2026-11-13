<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class SponsorRelationshipModel extends Model
{
    protected $table         = 'sponsor_relationships';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'member_user_id',
        'sponsor_user_id',
        'sponsor_member_id',
    ];

    public function findByMemberUserId(int $memberUserId): ?array
    {
        $row = $this->where('member_user_id', $memberUserId)->first();

        return $row ?: null;
    }
}
