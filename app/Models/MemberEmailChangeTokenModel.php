<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MemberEmailChangeTokenModel extends Model
{
    protected $table         = 'member_email_change_tokens';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'user_id',
        'member_id',
        'current_email',
        'new_email',
        'token_hash',
        'expires_at',
        'used_at',
        'cancelled_at',
        'created_at',
    ];

    public function findValidByHash(string $tokenHash): ?array
    {
        $row = $this->where('token_hash', $tokenHash)
            ->where('used_at', null)
            ->where('cancelled_at', null)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->first();

        return $row ?: null;
    }

    public function findActiveForUser(int $userId): ?array
    {
        $row = $this->where('user_id', $userId)
            ->where('used_at', null)
            ->where('cancelled_at', null)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->first();

        return $row ?: null;
    }

    public function countRecentForUser(int $userId, int $withinSeconds): int
    {
        $since = date('Y-m-d H:i:s', time() - $withinSeconds);

        return (int) $this->where('user_id', $userId)
            ->where('created_at >=', $since)
            ->countAllResults();
    }

    public function cancelOpenForUser(int $userId): void
    {
        $this->where('user_id', $userId)
            ->where('used_at', null)
            ->where('cancelled_at', null)
            ->set(['cancelled_at' => date('Y-m-d H:i:s')])
            ->update();
    }
}
