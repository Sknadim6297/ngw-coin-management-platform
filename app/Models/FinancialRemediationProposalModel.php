<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class FinancialRemediationProposalModel extends Model
{
    protected $table            = 'financial_remediation_proposals';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'proposal_ref',
        'status',
        'remediation_type',
        'reason_code',
        'title',
        'reason',
        'evidence_notes',
        'evidence_stored_name',
        'evidence_original_name',
        'evidence_mime',
        'entity_type',
        'entity_id',
        'before_state_json',
        'planned_after_state_json',
        'proposed_by',
        'reviewed_by',
        'review_note',
        'proposed_at',
        'reviewed_at',
        'execution_allowed',
        'execution_result',
        'idempotency_key',
    ];
}
