<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only governance trail for financial policy / mining rate / commission plan reviews, approvals and
 * activations (both dual_control and single_admin). Additive: creates one table, touches nothing else.
 */
class CreateFinancialGovernanceActions extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('financial_governance_actions')) {
            return;
        }

        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'action_ref'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'governance_mode' => ['type' => 'VARCHAR', 'constraint' => 20],
            'subject_type'    => ['type' => 'VARCHAR', 'constraint' => 30],
            'subject_ref'     => ['type' => 'VARCHAR', 'constraint' => 60],
            'subject_version' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'action'          => ['type' => 'VARCHAR', 'constraint' => 30],
            'terms_hash'      => ['type' => 'CHAR', 'constraint' => 64],
            'terms_json'      => ['type' => 'TEXT', 'null' => true],
            'review_ref'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'actor_user_id'   => ['type' => 'INT', 'unsigned' => true],
            'database_name'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'ip_address'      => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'      => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('action_ref');
        $this->forge->addKey(['subject_type', 'subject_ref', 'action']);
        $this->forge->addKey('review_ref');
        $this->forge->addKey('actor_user_id');
        $this->forge->createTable('financial_governance_actions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('financial_governance_actions', true);
    }
}
