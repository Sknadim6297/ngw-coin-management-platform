<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MoneyMath;
use CodeIgniter\Shield\Models\UserModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Financial governance (Config\Platform::$financialGovernanceMode).
 *
 * dual_control (default): nothing changes — the services keep refusing self-approval / self-activation.
 *
 * single_admin: NGW is operated by exactly one administrator. That designated user may approve/activate their own
 * Mining & Commission items, but ONLY when ALL of the following hold (checked server-side inside the approving
 * service, so no controller or CLI path can skip them):
 *   1. the mode is single_admin AND the actor is Config\Platform::$financialSingleAdminUserId (fail-closed if unset);
 *   2. the actor is active, holds the approval permission, and is not acting from Support View;
 *   3. a separate, recorded REVIEW of the exact terms by the same admin exists, is unused and younger than the TTL;
 *   4. the terms and the subject version are unchanged since that review (stale / tampered versions are refused);
 *   5. fresh password re-authentication, the typed phrase "APPROVE <ref>" / "ACTIVATE <ref>", confirmation of every
 *      single financial term, and an explicit acknowledgement;
 *   6. the terms equal the owner-approved business rules (3% monthly COIN mining, calendar-day divisor, approval day
 *      excluded, no compounding, Asia/Kolkata; commission 10/5/3% of the approved USDT purchase amount). Anything
 *      else still needs a second, independent approver.
 * Every review / approval / activation (dual_control too) is appended to financial_governance_actions.
 * Nothing here changes kill-switches, enables automation, or posts credits.
 */
class FinancialGovernanceService
{
    public const MODE_DUAL_CONTROL = 'dual_control';
    public const MODE_SINGLE_ADMIN = 'single_admin';

    public const SUBJECT_POLICY = 'financial_policy';
    public const SUBJECT_RATE   = 'mining_rate';
    public const SUBJECT_PLAN   = 'commission_plan';
    public const SUBJECTS       = [self::SUBJECT_POLICY, self::SUBJECT_RATE, self::SUBJECT_PLAN];

    public const INTENT_APPROVE  = 'approve';
    public const INTENT_ACTIVATE = 'activate';

    public const TABLE = 'financial_governance_actions';

    private const COMMISSION_BASES = ['approved_usdt_purchase_amount', 'approved_purchase_usdt'];

    public function __construct(
        private readonly ?Platform $config = null,
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    public function mode(): string
    {
        return strtolower(trim((string) $this->cfg()->financialGovernanceMode)) === self::MODE_SINGLE_ADMIN
            ? self::MODE_SINGLE_ADMIN
            : self::MODE_DUAL_CONTROL;
    }

    public function isSingleAdmin(): bool
    {
        return $this->mode() === self::MODE_SINGLE_ADMIN;
    }

    public function singleAdminUserId(): int
    {
        return max(0, (int) $this->cfg()->financialSingleAdminUserId);
    }

    /**
     * @return array{mode: string, single_admin_user_id: int, ready: bool, problems: list<string>, review_ttl_seconds: int}
     */
    public function status(): array
    {
        $problems = [];
        if ($this->isSingleAdmin()) {
            if ($this->singleAdminUserId() <= 0) {
                $problems[] = 'single_admin mode is set but platform.financialSingleAdminUserId is not configured.';
            } elseif ($this->findUser($this->singleAdminUserId()) === null) {
                $problems[] = 'The designated single administrator #' . $this->singleAdminUserId() . ' does not exist.';
            }
            if (! db_connect()->tableExists(self::TABLE)) {
                $problems[] = 'Table ' . self::TABLE . ' is missing (run migration 2026-11-20-997001).';
            }
        }

        return [
            'mode'                 => $this->mode(),
            'single_admin_user_id' => $this->singleAdminUserId(),
            'ready'                => $problems === [],
            'problems'             => $problems,
            'review_ttl_seconds'   => $this->ttl(),
        ];
    }

    /** Single-admin approvals are only made while every production financial kill-switch is OFF. */
    public function assertKillSwitchesOff(): void
    {
        (new ProductionSafetyGate())->assertKillSwitchesOff();
    }

    public function confirmPhrase(string $intent, string $ref): string
    {
        return strtoupper($intent) . ' ' . $ref;
    }

    /**
     * Current reviewable terms of a subject (read-only).
     *
     * @return array{type: string, ref: string, status: string, version: string, terms: array<string, string>, terms_hash: string, rule_problems: list<string>, created_by: int, submitted_by: int, approved_by: int, label: string}
     */
    public function terms(string $type, string $ref): array
    {
        $this->assertSubject($type);
        $db = db_connect();

        if ($type === self::SUBJECT_POLICY) {
            $row = $db->table('financial_policy_versions')->where('policy_ref', $ref)->get()->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Policy not found.');
            }
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $terms   = [
                'domain'         => (string) $row['domain'],
                'title'          => (string) $row['title'],
                'version_no'     => (string) $row['version_no'],
                'effective_from' => $this->str($row['effective_from'] ?? null),
                'effective_to'   => $this->str($row['effective_to'] ?? null),
            ];
            foreach ($payload as $k => $v) {
                if (is_string($k) && ! str_starts_with($k, '_')) {
                    $terms['payload.' . $k] = $this->str($v);
                }
            }
            $problems = $this->policyRuleProblems((string) $row['domain'], $payload);
            $out      = [
                'status' => (string) $row['status'], 'version' => (string) $row['lock_version'],
                'created_by' => (int) $row['created_by'], 'submitted_by' => (int) ($row['submitted_by'] ?? 0),
                'approved_by' => (int) ($row['approved_by'] ?? 0), 'label' => ucfirst((string) $row['domain']) . ' policy',
            ];
        } elseif ($type === self::SUBJECT_RATE) {
            $row = $db->table('mining_rate_configs')->where('rate_ref', $ref)->get()->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Rate configuration not found.');
            }
            $terms = [
                'effective_month'    => (string) $row['effective_month'],
                'rate_percent'       => MoneyMath::normalize((string) $row['rate_percent'], 8),
                'rate_basis'         => (string) $row['rate_basis'],
                'eligibility_policy' => (string) $row['eligibility_policy'],
                'precision_scale'    => (string) $row['precision_scale'],
                'reward_asset'       => 'COIN',
            ];
            $problems = [];
            if ($terms['rate_basis'] !== 'monthly' || MoneyMath::compare($terms['rate_percent'], '3', 8) !== 0) {
                $problems[] = 'Mining rate must be exactly 3% monthly (found ' . $terms['rate_percent'] . '% ' . $terms['rate_basis'] . ').';
            }
            $out = [
                'status' => (string) $row['status'], 'version' => (string) $row['updated_at'],
                'created_by' => (int) $row['created_by'], 'submitted_by' => 0,
                'approved_by' => (int) ($row['approved_by'] ?? 0), 'label' => 'Mining rate',
            ];
        } else {
            $row = $db->table('commission_plans')->where('plan_ref', $ref)->get()->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Plan not found.');
            }
            $levels = service('commissionPlanService')->levelsFor($ref);
            $terms  = [
                'plan_name'           => (string) $row['plan_name'],
                'effective_from'      => $this->str($row['effective_from'] ?? null),
                'effective_to'        => $this->str($row['effective_to'] ?? null),
                'level_count'         => (string) $row['level_count'],
                'commission_currency' => strtoupper((string) $row['commission_currency']),
                'calculation_base'    => 'approved_usdt_purchase_amount',
                'eligible_purchase_policy'   => $this->str($row['eligible_purchase_policy'] ?? null),
                'sponsor_eligibility_policy' => $this->str($row['sponsor_eligibility_policy'] ?? null),
            ];
            foreach ($levels as $level => $pct) {
                $terms['level_' . $level . '_percent'] = $pct;
            }
            $problems = [];
            $expected = [1 => '10', 2 => '5', 3 => '3'];
            if ((int) $row['level_count'] !== 3 || count($levels) !== 3) {
                $problems[] = 'Commission plan must have exactly 3 levels.';
            }
            foreach ($expected as $level => $pct) {
                if (! isset($levels[$level]) || MoneyMath::compare($levels[$level], $pct, 8) !== 0) {
                    $problems[] = 'Level ' . $level . ' must be ' . $pct . '% (found ' . ($levels[$level] ?? 'missing') . ').';
                }
            }
            if ($terms['commission_currency'] !== 'USDT') {
                $problems[] = 'Commission currency must be USDT.';
            }
            $out = [
                'status' => (string) $row['status'], 'version' => (string) $row['updated_at'],
                'created_by' => (int) $row['created_by'], 'submitted_by' => 0,
                'approved_by' => (int) ($row['approved_by'] ?? 0), 'label' => 'Commission plan',
            ];
        }

        ksort($terms);

        return $out + [
            'type'          => $type,
            'ref'           => $ref,
            'terms'         => $terms,
            'terms_hash'    => hash('sha256', json_encode([$type, $ref, $terms], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'rule_problems' => $problems,
        ];
    }

    /**
     * Step 1 (separate from the decision): the single administrator records that they reviewed these exact terms.
     *
     * @return array<string, mixed>
     */
    public function review(string $type, string $ref, string $intent, int $actorUserId, string $password): array
    {
        $this->assertIntent($type, $intent);
        $this->assertSingleAdminActor($type, $actorUserId);
        $this->verifyPassword($actorUserId, $password);
        $this->assertTable();

        $t = $this->terms($type, $ref);
        $this->assertStatusFor($t, $intent);
        if ($t['rule_problems'] !== []) {
            throw new InvalidArgumentException('Single-admin approval is limited to the owner-approved business rules: ' . implode(' ', $t['rule_problems']) . ' Use dual-control for anything else.');
        }

        $reviewRef = $this->insert($type, $ref, $t['version'], 'review_' . $intent, $t['terms_hash'], $t['terms'], null, $actorUserId);
        $this->audit->log('financial_governance.reviewed', $actorUserId, $type, $ref, [
            'intent' => $intent, 'review_ref' => $reviewRef, 'version' => $t['version'], 'terms_hash' => $t['terms_hash'],
            'governance_mode' => self::MODE_SINGLE_ADMIN,
        ]);

        return $t + [
            'review_ref'     => $reviewRef,
            'intent'         => $intent,
            'confirm_phrase' => $this->confirmPhrase($intent, $ref),
            'expires_at'     => date('Y-m-d H:i:s', time() + $this->ttl()),
        ];
    }

    /** Latest unused, unexpired review of the current version (for the confirmation form). */
    public function pendingReview(string $type, string $ref, string $intent, int $actorUserId): ?array
    {
        if (! db_connect()->tableExists(self::TABLE)) {
            return null;
        }
        $row = db_connect()->table(self::TABLE)
            ->where('subject_type', $type)->where('subject_ref', $ref)->where('action', 'review_' . $intent)
            ->where('actor_user_id', $actorUserId)->orderBy('id', 'DESC')->get()->getRowArray();
        if ($row === null || strtotime((string) $row['created_at']) < time() - $this->ttl() || $this->reviewUsed((string) $row['action_ref'])) {
            return null;
        }
        $t = $this->terms($type, $ref);
        if ($t['terms_hash'] !== $row['terms_hash'] || $t['version'] !== (string) $row['subject_version']) {
            return null;
        }

        return $row;
    }

    /**
     * Step 2: validate a single-admin approval/activation. Called by the approving service inside its transaction.
     *
     * @param array<string, mixed> $input review_ref, terms_hash, confirm, confirmed_terms (list), acknowledge
     * @return array{review_ref: string, terms_hash: string, version: string}
     */
    public function authorize(string $type, string $ref, string $intent, int $actorUserId, string $password, array $input): array
    {
        $this->assertIntent($type, $intent);
        $this->assertSingleAdminActor($type, $actorUserId);
        $this->verifyPassword($actorUserId, $password);
        $this->assertTable();

        $t = $this->terms($type, $ref);
        $this->assertStatusFor($t, $intent);
        if ($t['rule_problems'] !== []) {
            throw new InvalidArgumentException('Single-admin approval is limited to the owner-approved business rules: ' . implode(' ', $t['rule_problems']));
        }

        $reviewRef = trim((string) ($input['review_ref'] ?? ''));
        $review    = $reviewRef === '' ? null : db_connect()->table(self::TABLE)
            ->where('action_ref', $reviewRef)->where('action', 'review_' . $intent)
            ->where('subject_type', $type)->where('subject_ref', $ref)->where('actor_user_id', $actorUserId)
            ->get()->getRowArray();
        if ($review === null) {
            throw new InvalidArgumentException('No matching single-admin review. Review the terms first (separate step), then confirm.');
        }
        if ($this->reviewUsed($reviewRef)) {
            throw new InvalidArgumentException('This review was already used. Each approval/activation needs a fresh review.');
        }
        if (strtotime((string) $review['created_at']) < time() - $this->ttl()) {
            throw new InvalidArgumentException('Your review expired. Review the terms again.');
        }
        if ((string) $review['subject_version'] !== $t['version'] || (string) $review['terms_hash'] !== $t['terms_hash']
            || trim((string) ($input['terms_hash'] ?? '')) !== $t['terms_hash']) {
            throw new RuntimeException('The terms or version changed since your review (stale version). Review again.');
        }
        if (trim((string) ($input['confirm'] ?? '')) !== $this->confirmPhrase($intent, $ref)) {
            throw new InvalidArgumentException('Type the confirmation exactly: ' . $this->confirmPhrase($intent, $ref));
        }
        $confirmed = array_map('strval', (array) ($input['confirmed_terms'] ?? []));
        $missing   = array_values(array_diff(array_keys($t['terms']), $confirmed));
        if ($missing !== []) {
            throw new InvalidArgumentException('Confirm every financial term. Not confirmed: ' . implode(', ', $missing) . '.');
        }
        if (empty($input['acknowledge'])) {
            throw new InvalidArgumentException('Acknowledge that you are approving as the single administrator without an independent second approver.');
        }

        return ['review_ref' => $reviewRef, 'terms_hash' => $t['terms_hash'], 'version' => $t['version']];
    }

    /**
     * Append the governance record of an approval/activation (both modes). Call after the status update, in the same
     * transaction. Without the table (not yet migrated) dual_control actions are still allowed; single_admin is not.
     *
     * @param array{review_ref: string, terms_hash: string, version: string}|null $auth
     */
    public function record(string $type, string $ref, string $intent, int $actorUserId, ?array $auth): void
    {
        if (! db_connect()->tableExists(self::TABLE)) {
            if ($auth !== null) {
                throw new RuntimeException('Governance table missing.');
            }

            return;
        }
        $t    = $this->terms($type, $ref);
        $mode = $auth !== null ? self::MODE_SINGLE_ADMIN : self::MODE_DUAL_CONTROL;
        $this->insert($type, $ref, $auth['version'] ?? $t['version'], $intent, $auth['terms_hash'] ?? $t['terms_hash'], $t['terms'], $auth['review_ref'] ?? null, $actorUserId, $mode);
    }

    /**
     * Readiness: is this approved/active row validly approved under the configured governance? Drafts and tampered
     * rows are never accepted; a self-approval counts only with a matching single-admin record of unchanged terms.
     *
     * @param array<string, mixed> $row
     */
    public function approvalProblem(string $type, array $row): ?string
    {
        $approver = (int) ($row['approved_by'] ?? 0);
        if ($approver <= 0) {
            return 'no recorded approver.';
        }
        $selfApproved  = $approver === (int) ($row['created_by'] ?? 0)
            || ($type === self::SUBJECT_POLICY && $approver === (int) ($row['submitted_by'] ?? 0));
        $selfActivated = $type === self::SUBJECT_POLICY && ($row['status'] ?? '') === 'active'
            && (int) ($row['activated_by'] ?? 0) === $approver;
        if (! $selfApproved && ! $selfActivated) {
            return null;
        }
        if (! $this->isSingleAdmin()) {
            return 'approver is not independent of the author/submitter (governance mode: dual_control).';
        }
        if ($this->singleAdminUserId() <= 0 || $approver !== $this->singleAdminUserId()) {
            return 'self-approved by user #' . $approver . ', who is not the designated single administrator.';
        }
        if (! db_connect()->tableExists(self::TABLE)) {
            return 'governance table missing (run migration 2026-11-20-997001).';
        }
        $ref  = (string) ($row[$this->refColumn($type)] ?? '');
        $hash = $this->terms($type, $ref)['terms_hash'];
        foreach (array_filter([$selfApproved ? self::INTENT_APPROVE : null, $selfActivated ? self::INTENT_ACTIVATE : null]) as $action) {
            $rec = db_connect()->table(self::TABLE)
                ->where('subject_type', $type)->where('subject_ref', $ref)->where('action', $action)
                ->where('governance_mode', self::MODE_SINGLE_ADMIN)->where('actor_user_id', $approver)
                ->orderBy('id', 'DESC')->get()->getRowArray();
            if ($rec === null) {
                return 'self-' . $action . ' has no single-admin governance record.';
            }
            if ((string) $rec['terms_hash'] !== $hash) {
                return 'terms changed after the single-admin ' . $action . '.';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    public function approvalLabel(string $type, array $row): string
    {
        $approver = (int) ($row['approved_by'] ?? 0);
        $self     = $approver === (int) ($row['created_by'] ?? 0) || ($type === self::SUBJECT_POLICY && $approver === (int) ($row['submitted_by'] ?? 0));

        return $self
            ? 'approved by the single administrator #' . $approver . ' (single_admin governance, reviewed & reauthenticated)'
            : 'independently approved by user #' . $approver;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $type, string $ref): array
    {
        if (! db_connect()->tableExists(self::TABLE)) {
            return [];
        }

        return db_connect()->table(self::TABLE)->where('subject_type', $type)->where('subject_ref', $ref)->orderBy('id', 'ASC')->get()->getResultArray();
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string, mixed> $p
     * @return list<string>
     */
    private function policyRuleProblems(string $domain, array $p): array
    {
        $bad = [];
        if ($domain === FinancialPolicyService::DOMAIN_MINING) {
            $eq = static fn (string $k, mixed $v): bool => ($p[$k] ?? null) === $v;
            if (MoneyMath::compare(MoneyMath::normalize((string) ($p['monthly_percent'] ?? '0'), 8), '3', 8) !== 0) {
                $bad[] = 'monthly_percent must be 3.';
            }
            if (isset($p['rate_basis']) && $p['rate_basis'] !== 'monthly') {
                $bad[] = 'rate_basis must be monthly.';
            }
            if (! $eq('month_day_divisor_mode', 'calendar_days')) {
                $bad[] = 'Day divisor must be the actual calendar days of the month.';
            }
            if (! $eq('approval_day_policy', MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY)) {
                $bad[] = 'Approval day must be excluded.';
            }
            if (! $eq('earning_asset', 'COIN')) {
                $bad[] = 'Reward asset must be COIN.';
            }
            if (! $eq('compounding_enabled', false)) {
                $bad[] = 'Compounding must be disabled.';
            }
            if (! $eq('business_timezone', 'Asia/Kolkata')) {
                $bad[] = 'Business timezone must be Asia/Kolkata.';
            }
            $cfg = $this->cfg();
            if ($cfg->miningMonthDayDivisorMode !== 'calendar_days' || $cfg->miningApprovalDayPolicy !== MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY
                || $cfg->miningEarningAsset !== 'COIN' || $cfg->miningBusinessTimezone !== 'Asia/Kolkata') {
                $bad[] = 'Platform mining configuration does not match the owner-approved rules.';
            }
            try {
                $runtime = MiningCalculationPolicy::fromPlatform($cfg)->version();
                if ((string) ($p['policy_version'] ?? '') !== $runtime) {
                    $bad[] = 'policy_version must match the engine configuration (' . $runtime . ').';
                }
            } catch (Throwable $e) {
                $bad[] = 'Engine configuration invalid: ' . $e->getMessage();
            }

            return $bad;
        }
        if ($domain === FinancialPolicyService::DOMAIN_COMMISSION) {
            foreach ([['l1', 'level1_percent', '10'], ['l2', 'level2_percent', '5'], ['l3', 'level3_percent', '3']] as [$a, $b, $want]) {
                $v = (string) ($p[$a] ?? $p[$b] ?? '');
                if ($v === '' || ! is_numeric($v) || MoneyMath::compare(MoneyMath::normalize($v, 8), $want, 8) !== 0) {
                    $bad[] = strtoupper($a) . ' must be ' . $want . '%.';
                }
            }
            if (! in_array((string) ($p['base'] ?? $p['calculation_base'] ?? ''), self::COMMISSION_BASES, true)) {
                $bad[] = 'Commission base must be the approved USDT purchase amount.';
            }

            return $bad;
        }

        return ['Single-admin approval covers only Mining and Commission policies (' . $domain . ' needs dual-control).'];
    }

    private function assertSingleAdminActor(string $type, int $actorUserId): void
    {
        if (! $this->isSingleAdmin()) {
            throw new InvalidArgumentException('Self-approval is not allowed: financial governance mode is dual_control. A different authorized approver is required.');
        }
        $admin = $this->singleAdminUserId();
        if ($admin <= 0) {
            throw new InvalidArgumentException('single_admin governance is not fully configured (platform.financialSingleAdminUserId is missing).');
        }
        if ($actorUserId !== $admin) {
            throw new InvalidArgumentException('Only the designated single administrator (#' . $admin . ') may self-approve under single_admin governance.');
        }
        $user = $this->findUser($actorUserId);
        if ($user === null || ! (bool) ($user->active ?? false)) {
            throw new InvalidArgumentException('The designated single administrator account is missing or inactive.');
        }
        $perms = match ($type) {
            self::SUBJECT_RATE => ['mining.rates.approve', 'admin.settings'],
            self::SUBJECT_PLAN => ['commission.plans.approve', 'admin.settings'],
            default            => ['finance.remediation.approve', 'admin.settings'],
        };
        $allowed = false;
        foreach ($perms as $perm) {
            $allowed = $allowed || $user->can($perm);
        }
        if (! $allowed) {
            throw new InvalidArgumentException('The single administrator lacks the approval permission for this item.');
        }
        if (service('impersonationService')->activeContext() !== null || (! is_cli() && str_starts_with(uri_string(), 'support-view'))) {
            throw new RuntimeException('Financial approvals cannot be made from Support View.');
        }
    }

    private function verifyPassword(int $actorUserId, string $password): void
    {
        if (trim($password) === '') {
            throw new InvalidArgumentException('Admin password reauthentication is required.');
        }
        $user = auth()->user();
        if ($user === null || (int) $user->id !== $actorUserId) {
            throw new InvalidArgumentException('Authenticated admin mismatch.');
        }
        $identity = db_connect()->table('auth_identities')
            ->where('user_id', $actorUserId)->where('type', 'email_password')->get()->getRowArray();
        $secret = (string) ($identity['secret2'] ?? '');
        if ($secret === '' || ! service('passwords')->verify(trim($password), $secret)) {
            throw new InvalidArgumentException('Password reauthentication failed.');
        }
    }

    /** @param array<string, mixed> $t */
    private function assertStatusFor(array $t, string $intent): void
    {
        $need = match (true) {
            $t['type'] === self::SUBJECT_POLICY && $intent === self::INTENT_APPROVE  => FinancialPolicyService::STATUS_PENDING_APPROVAL,
            $t['type'] === self::SUBJECT_POLICY && $intent === self::INTENT_ACTIVATE => FinancialPolicyService::STATUS_APPROVED,
            default                                                                 => 'draft',
        };
        if ($t['status'] !== $need) {
            throw new InvalidArgumentException($t['label'] . ' ' . $t['ref'] . ' is ' . $t['status'] . '; only ' . $need . ' items can be ' . ($intent === self::INTENT_APPROVE ? 'approved' : 'activated') . '.');
        }
    }

    private function assertIntent(string $type, string $intent): void
    {
        $this->assertSubject($type);
        if (! in_array($intent, [self::INTENT_APPROVE, self::INTENT_ACTIVATE], true)
            || ($intent === self::INTENT_ACTIVATE && $type !== self::SUBJECT_POLICY)) {
            throw new InvalidArgumentException('Unsupported governance action.');
        }
    }

    private function assertSubject(string $type): void
    {
        if (! in_array($type, self::SUBJECTS, true)) {
            throw new InvalidArgumentException('Unknown governance subject.');
        }
    }

    private function assertTable(): void
    {
        if (! db_connect()->tableExists(self::TABLE)) {
            throw new RuntimeException('Table ' . self::TABLE . ' is missing. Run migration 2026-11-20-997001 first.');
        }
    }

    private function reviewUsed(string $reviewRef): bool
    {
        return db_connect()->table(self::TABLE)->where('review_ref', $reviewRef)->countAllResults() > 0;
    }

    /** @param array<string, string> $terms */
    private function insert(string $type, string $ref, string $version, string $action, string $hash, array $terms, ?string $reviewRef, int $actor, string $mode = self::MODE_SINGLE_ADMIN): string
    {
        $actionRef = 'FGA-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
        $request   = service('request');
        db_connect()->table(self::TABLE)->insert([
            'action_ref'      => $actionRef,
            'governance_mode' => $mode,
            'subject_type'    => $type,
            'subject_ref'     => $ref,
            'subject_version' => mb_substr($version, 0, 40),
            'action'          => $action,
            'terms_hash'      => $hash,
            'terms_json'      => json_encode($terms, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'review_ref'      => $reviewRef,
            'actor_user_id'   => $actor,
            'database_name'   => db_connect()->getDatabase(),
            'ip_address'      => is_object($request) && method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent'      => is_object($request) && method_exists($request, 'getUserAgent') ? mb_substr((string) $request->getUserAgent(), 0, 255) : null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return $actionRef;
    }

    private function refColumn(string $type): string
    {
        return match ($type) {
            self::SUBJECT_RATE => 'rate_ref',
            self::SUBJECT_PLAN => 'plan_ref',
            default            => 'policy_ref',
        };
    }

    private function findUser(int $id): ?object
    {
        return $id > 0 ? model(UserModel::class)->findById($id) : null;
    }

    private function str(mixed $v): string
    {
        return match (true) {
            $v === null   => 'null',
            is_bool($v)   => $v ? 'true' : 'false',
            is_array($v)  => (string) json_encode($v, JSON_UNESCAPED_SLASHES),
            default       => (string) $v,
        };
    }

    private function ttl(): int
    {
        return max(60, (int) $this->cfg()->financialSingleAdminReviewTtlSeconds);
    }

    private function cfg(): Platform
    {
        return $this->config ?? config('Platform');
    }
}
