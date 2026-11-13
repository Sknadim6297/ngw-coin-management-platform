<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class PasswordResetTokenModel extends Model
{
    protected $table         = 'password_reset_tokens';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'user_id',
        'email',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    public function findValidByHash(string $tokenHash): ?array
    {
        $row = $this->where('token_hash', $tokenHash)
            ->where('used_at', null)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->first();

        return $row ?: null;
    }
}
