<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Platform;

/**
 * Wipe transactional / member data and keep only the root admin login.
 *
 * Usage:
 *   php spark platform:fresh-keep-admin
 */
class PlatformFreshKeepAdmin extends BaseCommand
{
    protected $group       = 'Platform';
    protected $name        = 'platform:fresh-keep-admin';
    protected $description = 'Delete all member/business data and keep only the admin account.';
    protected $usage       = 'platform:fresh-keep-admin';

    public function run(array $params)
    {
        /** @var Platform $config */
        $config = config('Platform');
        $rootId = trim($config->rootSponsorId !== '' ? $config->rootSponsorId : 'ROOT0001');
        $email  = trim($config->adminEmail !== '' ? $config->adminEmail : (string) env('platform.adminEmail'));

        $db = db_connect();

        $adminUserId = null;
        $rootProfile = $db->table('member_profiles')->where('member_id', $rootId)->get()->getRowArray();
        if ($rootProfile !== null) {
            $adminUserId = (int) $rootProfile['user_id'];
        }

        if ($adminUserId === null && $email !== '') {
            $identity = $db->table('auth_identities')
                ->where('type', 'email_password')
                ->where('secret', $email)
                ->get()
                ->getRowArray();
            if ($identity !== null) {
                $adminUserId = (int) $identity['user_id'];
            }
        }

        if ($adminUserId === null || $adminUserId <= 0) {
            CLI::error('Admin account not found. Seed root admin first, then retry.');

            return;
        }

        CLI::write("Keeping admin user_id={$adminUserId} ({$rootId} / {$email})", 'yellow');

        $db->transBegin();
        $db->query('SET FOREIGN_KEY_CHECKS=0');

        try {
            $truncate = [
                'audit_logs',
                'blockchain_health_snapshots',
                'coin_inventory_transactions',
                'coin_locking_plans',
                'coin_price_history',
                'coin_purchase_requests',
                'financial_alerts',
                'financial_remediation_events',
                'financial_remediation_proposals',
                'member_coin_balances',
                'member_coin_lock_events',
                'member_coin_locks',
                'member_coin_ownership_transactions',
                'notification_delivery_events',
                'notification_outbox',
                'ops_job_locks',
                'password_reset_tokens',
                'sponsor_relationships',
                'usdt_deposit_events',
                'usdt_deposit_requests',
                'usdt_payment_allocation_events',
                'usdt_payment_allocations',
                'auth_logins',
                'auth_remember_tokens',
                'auth_token_logins',
                'auth_permissions_users',
            ];

            foreach ($truncate as $table) {
                if ($db->tableExists($table)) {
                    $db->table($table)->truncate();
                    CLI::write("Truncated {$table}");
                }
            }

            // Delete every non-admin member profile and user.
            $otherProfiles = $db->table('member_profiles')
                ->where('user_id !=', $adminUserId)
                ->get()
                ->getResultArray();
            $otherUserIds = array_map(static fn (array $row): int => (int) $row['user_id'], $otherProfiles);

            $db->table('member_profiles')->where('user_id !=', $adminUserId)->delete();

            if ($otherUserIds !== []) {
                $db->table('auth_groups_users')->whereIn('user_id', $otherUserIds)->delete();
                $db->table('auth_identities')->whereIn('user_id', $otherUserIds)->delete();
                $db->table('users')->whereIn('id', $otherUserIds)->delete();
                CLI::write('Deleted ' . count($otherUserIds) . ' non-admin user(s)');
            }

            // Ensure admin profile remains root/active.
            $now = date('Y-m-d H:i:s');
            $existingAdminProfile = $db->table('member_profiles')->where('user_id', $adminUserId)->get()->getRowArray();
            if ($existingAdminProfile === null) {
                $db->table('member_profiles')->insert([
                    'user_id'         => $adminUserId,
                    'member_id'       => $rootId,
                    'member_name'     => $config->adminName !== '' ? $config->adminName : 'Platform Admin',
                    'mobile'          => '0000000000',
                    'usdt_wallet'     => '0x0000000000000000000000000000000000000001',
                    'account_status'  => 'active',
                    'is_root_sponsor' => 1,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            } else {
                $db->table('member_profiles')->where('user_id', $adminUserId)->update([
                    'member_id'       => $rootId,
                    'account_status'  => 'active',
                    'is_root_sponsor' => 1,
                    'updated_at'      => $now,
                ]);
            }

            // Auth groups: keep admin (+ finance) groups on admin user only.
            $db->table('auth_groups_users')->where('user_id', $adminUserId)->delete();
            foreach (['admin', 'member', 'finance_operator', 'finance_approver'] as $group) {
                $db->table('auth_groups_users')->insert([
                    'user_id'    => $adminUserId,
                    'group'      => $group,
                    'created_at' => $now,
                ]);
            }

            // Reset ID sequence for NGW members.
            $prefix = trim((string) $config->memberIdPrefix) ?: 'NGW';
            $db->table('member_id_sequences')->truncate();
            $db->table('member_id_sequences')->insert([
                'prefix'      => $prefix,
                'last_number' => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            // Reset inventory singleton.
            if ($db->tableExists('coin_inventory')) {
                $db->table('coin_inventory')->truncate();
                $db->table('coin_inventory')->insert([
                    'id'                => 1,
                    'available_balance' => '0.00000000',
                    'total_added'       => '0.00000000',
                    'total_allocated'   => '0.00000000',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            // Keep coin_settings row but leave price as-is if present; ensure singleton exists.
            if ($db->tableExists('coin_settings')) {
                $settings = $db->table('coin_settings')->where('id', 1)->get()->getRowArray();
                if ($settings === null) {
                    $db->table('coin_settings')->insert([
                        'id'                 => 1,
                        'current_price_usdt' => null,
                        'min_purchase_usdt'  => '5.00000000',
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]);
                }
            }

            $db->query('SET FOREIGN_KEY_CHECKS=1');

            if ($db->transStatus() === false) {
                $db->transRollback();
                CLI::error('Fresh reset failed; rolled back.');

                return;
            }

            $db->transCommit();

            $remaining = (int) $db->table('member_profiles')->countAllResults();
            $users     = (int) $db->table('users')->countAllResults();
            CLI::write("Fresh complete. member_profiles={$remaining}, users={$users}", 'green');
            CLI::write('Admin login kept. Members list will stay empty until new registrations.');
            CLI::write("Next member ID will be {$prefix}000001");
        } catch (\Throwable $e) {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
            $db->transRollback();
            CLI::error($e->getMessage());
        }
    }
}
