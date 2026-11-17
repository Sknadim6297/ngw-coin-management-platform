<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'member';

    public array $groups = [
        'admin' => [
            'title'       => 'Administrator',
            'description' => 'Platform administration. Does not automatically receive all finance privileges (Phase 4E).',
        ],
        'member' => [
            'title'       => 'Member',
            'description' => 'Registered platform members.',
        ],
        // Phase 4E least-privilege finance roles
        'finance_viewer' => [
            'title'       => 'Finance Viewer',
            'description' => 'Read-only access to finance dashboards, alerts, and allocations.',
        ],
        'finance_operator' => [
            'title'       => 'Finance Operator',
            'description' => 'May acknowledge alerts and propose remediations; cannot dual-approve.',
        ],
        'finance_approver' => [
            'title'       => 'Finance Approver',
            'description' => 'May dual-control review remediations; cannot bypass separation of duties.',
        ],
        'finance_auditor' => [
            'title'       => 'Finance Auditor',
            'description' => 'Read-only financial audit access; cannot mutate records.',
        ],
    ];

    public array $permissions = [
        'admin.access'         => 'Can access the admin panel',
        'admin.members.manage' => 'Can manage members',
        'admin.members.impersonate' => 'Login as member (read-only support view)',
        'admin.settings'       => 'Can access platform settings',
        'admin.support.manage' => 'Manage member support tickets',
        'admin.reports.view'   => 'View admin reports and analytics',
        'admin.reports.export' => 'Export admin report CSV files',
        'member.access'        => 'Can access the member panel',
        'member.sponsor'       => 'Can sponsor new members when active',
        // Phase 4E granular finance permissions (no blanket finance.* for admin)
        'finance.monitor.view'         => 'View finance dashboard, allocations, alerts, remediation queue',
        'finance.alerts.acknowledge'   => 'Acknowledge financial alerts',
        'finance.remediation.propose'  => 'Propose financial remediation investigations',
        'finance.remediation.approve'  => 'Dual-control approve/reject remediation proposals',
        'finance.notifications.manage' => 'View notification outbox / delivery status',
        // Phase 10B payment review / approval (separate from finance.* monitor)
        'finance.payment.review'       => 'View payment approvals, deposits, and purchase review screens',
        'finance.payment.approve'      => 'Approve or reject member payments and coin purchases',
        'admin.coins.manage'           => 'Manage coin inventory and pricing',
        // Phase 7B mining configuration (simulation only — never posts rewards)
        'mining.rates.view'    => 'View mining rate configurations and simulations',
        'mining.rates.propose' => 'Create draft mining rate configurations',
        'mining.rates.approve' => 'Approve or disable mining rate configurations',
        'mining.simulate.run'  => 'Run mining simulation jobs (non-payable)',
        // Phase 7C commission configuration (simulation only — never posts commissions)
        'commission.plans.view'    => 'View commission plans and simulations',
        'commission.plans.propose' => 'Create draft commission plans',
        'commission.plans.approve' => 'Approve or disable commission plans',
        'commission.simulate.run'  => 'Run commission simulation jobs (non-payable)',
        'commission.export'        => 'Export commission simulation CSV',
        // Phase 7D wallet / posting / withdrawals (mutations gated by kill-switches)
        'commission.wallet.view'           => 'View commission wallet accounts and ledger',
        'commission.posting.propose'       => 'Create/submit commission posting proposals',
        'commission.posting.approve'       => 'Approve/reject commission posting proposals',
        'commission.withdrawal.view'       => 'View commission withdrawal requests',
        'commission.withdrawal.review'     => 'Review withdrawal requests (architecture only while disabled)',
        'commission.wallet.reconcile'      => 'Run commission wallet reconciliation (read-only)',
    ];

    public array $matrix = [
        'admin' => [
            'admin.*',
            'member.access',
            'member.sponsor',
            // Intentionally NO blanket finance.* — assign finance_* groups for dual-control.
            // Platform admin may still review/approve payments, monitor ops, and manage coins.
            'finance.payment.review',
            'finance.payment.approve',
            'finance.monitor.view',
            'mining.rates.view',
            'mining.simulate.run',
            'commission.plans.view',
            'commission.simulate.run',
            'commission.export',
            'commission.wallet.view',
            'commission.withdrawal.view',
            'commission.wallet.reconcile',
        ],
        'member' => [
            'member.access',
        ],
        'finance_viewer' => [
            'admin.access',
            'admin.reports.view',
            'admin.reports.export',
            'finance.monitor.view',
            'finance.notifications.manage',
            'finance.payment.review',
            'mining.rates.view',
            'commission.plans.view',
            'commission.wallet.view',
            'commission.withdrawal.view',
        ],
        'finance_operator' => [
            'admin.access',
            'admin.reports.view',
            'admin.reports.export',
            'finance.monitor.view',
            'finance.alerts.acknowledge',
            'finance.remediation.propose',
            'finance.notifications.manage',
            'finance.payment.review',
            'finance.payment.approve',
            'mining.rates.view',
            'mining.rates.propose',
            'mining.simulate.run',
            'commission.plans.view',
            'commission.plans.propose',
            'commission.simulate.run',
            'commission.export',
            'commission.wallet.view',
            'commission.posting.propose',
            'commission.withdrawal.view',
            'commission.wallet.reconcile',
        ],
        'finance_approver' => [
            'admin.access',
            'admin.reports.view',
            'admin.reports.export',
            'finance.monitor.view',
            'finance.alerts.acknowledge',
            'finance.remediation.approve',
            'finance.notifications.manage',
            'finance.payment.review',
            'finance.payment.approve',
            'mining.rates.view',
            'mining.rates.approve',
            'commission.plans.view',
            'commission.plans.approve',
            'commission.export',
            'commission.wallet.view',
            'commission.posting.approve',
            'commission.withdrawal.view',
            'commission.withdrawal.review',
            'commission.wallet.reconcile',
        ],
        'finance_auditor' => [
            'admin.access',
            'admin.reports.view',
            'admin.reports.export',
            'finance.monitor.view',
            'finance.notifications.manage',
            'finance.payment.review',
            'mining.rates.view',
            'commission.plans.view',
            'commission.export',
            'commission.wallet.view',
            'commission.withdrawal.view',
            'commission.wallet.reconcile',
        ],
    ];
}
