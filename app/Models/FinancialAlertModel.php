<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class FinancialAlertModel extends Model
{
    protected $table            = 'financial_alerts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'alert_ref',
        'alert_type',
        'severity',
        'dedupe_key',
        'status',
        'title',
        'message',
        'entity_type',
        'entity_id',
        'metadata',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'acknowledged_by',
        'acknowledged_at',
        'ack_note',
    ];
}
