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
        'admin.settings'       => 'Can access platform settings',
        'member.access'        => 'Can access the member panel',
        'member.sponsor'       => 'Can sponsor new members when active',
        // Phase 4E granular finance permissions (no blanket finance.* for admin)
        'finance.monitor.view'         => 'View finance dashboard, allocations, alerts, remediation queue',
        'finance.alerts.acknowledge'   => 'Acknowledge financial alerts',
        'finance.remediation.propose'  => 'Propose financial remediation investigations',
        'finance.remediation.approve'  => 'Dual-control approve/reject remediation proposals',
        'finance.notifications.manage' => 'View notification outbox / delivery status',
    ];

    public array $matrix = [
        'admin' => [
            'admin.*',
            'member.access',
            'member.sponsor',
            // Intentionally NO finance.* — assign finance_* groups explicitly.
        ],
        'member' => [
            'member.access',
        ],
        'finance_viewer' => [
            'admin.access',
            'finance.monitor.view',
            'finance.notifications.manage',
        ],
        'finance_operator' => [
            'admin.access',
            'finance.monitor.view',
            'finance.alerts.acknowledge',
            'finance.remediation.propose',
            'finance.notifications.manage',
        ],
        'finance_approver' => [
            'admin.access',
            'finance.monitor.view',
            'finance.alerts.acknowledge',
            'finance.remediation.approve',
            'finance.notifications.manage',
        ],
        'finance_auditor' => [
            'admin.access',
            'finance.monitor.view',
            'finance.notifications.manage',
        ],
    ];
}
