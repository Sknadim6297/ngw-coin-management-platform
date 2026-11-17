<?php

declare(strict_types=1);

namespace App\Services;

use Config\Database;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 12D — prepare / apply Member ID sequence correction to NGW1234+ (pad 4).
 * Never rewrites existing Member IDs. Production apply is gated.
 */
class MemberIdSequenceCorrectionService
{
    public const TARGET_START   = 1234;
    public const TARGET_PADDING = 4;
    public const PREFIX         = 'NGW';

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ProductionSafetyGate $gate = new ProductionSafetyGate()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(?string $dbGroup = null, ?int $start = null, ?int $padding = null): array
    {
        $start   = max(1, $start ?? self::TARGET_START);
        $padding = max(1, $padding ?? self::TARGET_PADDING);
        $info    = $this->gate->inspect($dbGroup);
        $db      = Database::connect($info['group'], false);
        $prefix  = self::PREFIX;

        $seq = $db->table('member_id_sequences')->where('prefix', $prefix)->get()->getRowArray();
        $existing = $this->listExistingIds($db, $prefix);
        $maxExisting = 0;
        foreach ($existing as $row) {
            $maxExisting = max($maxExisting, (int) $row['numeric']);
        }

        $currentLast = (int) ($seq['last_number'] ?? 0);
        $currentCfg  = config('Platform');
        $currentStart = max(1, (int) $currentCfg->memberIdStartNumber);
        $currentPad   = max(1, (int) $currentCfg->memberIdPadding);

        $currentFloor = max($currentLast, $maxExisting, $currentStart - 1);
        $currentNext  = $prefix . str_pad((string) ($currentFloor + 1), $currentPad, '0', STR_PAD_LEFT);

        $targetLast   = $start - 1;
        // Generator will still raise floor to maxExisting if higher than targetLast.
        $effectiveFloor = max($targetLast, $maxExisting);
        $nextNumber     = $effectiveFloor + 1;
        $nextId         = $prefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);

        $collision = $db->table('member_profiles')->where('member_id', $nextId)->countAllResults() > 0;
        $blockedByExistingHigh = $maxExisting >= $start;

        $ok = ! $collision && ! $blockedByExistingHigh && $nextNumber === $start;

        return [
            'database'              => $info['database'],
            'group'                 => $info['group'],
            'is_live'               => $info['is_live'],
            'existing_ids'          => $existing,
            'preserved'             => ['ROOT0001', 'NGW000001'],
            'before'                => [
                'memberIdStartNumber' => $currentStart,
                'memberIdPadding'     => $currentPad,
                'sequence_last'       => $currentLast,
                'max_existing_numeric'=> $maxExisting,
                'preview_next'        => $currentNext,
            ],
            'after_projected'       => [
                'memberIdStartNumber' => $start,
                'memberIdPadding'     => $padding,
                'sequence_last'       => $targetLast,
                'max_existing_numeric'=> $maxExisting,
                'preview_next'        => $nextId,
                'env_lines'           => [
                    'platform.memberIdStartNumber = ' . $start,
                    'platform.memberIdPadding = ' . $padding,
                ],
            ],
            'collision_next_id'     => $collision,
            'blocked_by_high_existing' => $blockedByExistingHigh,
            'already_past_target'   => $maxExisting >= $start,
            'ready'                 => $ok,
            'note'                  => $ok
                ? 'Safe to apply sequence last_number=' . $targetLast . ' after config update; existing IDs unchanged.'
                : ($collision
                    ? 'Collision: ' . $nextId . ' already exists.'
                    : ($blockedByExistingHigh
                        ? 'Existing NGW numeric max=' . $maxExisting . ' >= target start ' . $start . ' (e.g. staging already issued NGW1234+).'
                        : 'Cannot safely reach ' . $prefix . $start . ' with current data/config.')),
        ];
    }

    /**
     * Apply sequence state only (last_number). Caller must also update Platform/.env.
     *
     * @return array<string, mixed>
     */
    public function apply(
        bool $dryRun,
        bool $allowProduction,
        ?string $confirmPhrase,
        ?string $dbGroup = null,
        ?int $start = null,
        ?int $padding = null,
        ?int $actorUserId = null
    ): array {
        $this->gate->assertKillSwitchesOff();
        $start   = max(1, $start ?? self::TARGET_START);
        $padding = max(1, $padding ?? self::TARGET_PADDING);

        if (! $dryRun) {
            $this->gate->assertMutationAllowed(
                $allowProduction,
                $confirmPhrase,
                $dbGroup,
                'member_id_sequence_correction'
            );
        }

        $preview = $this->preview($dbGroup, $start, $padding);
        if (! $preview['ready']) {
            throw new RuntimeException('Sequence correction not ready: ' . ($preview['note'] ?? 'blocked'));
        }

        if ($dryRun) {
            return array_merge($preview, [
                'executed' => false,
                'dry_run'  => true,
            ]);
        }

        $info = $this->gate->inspect($dbGroup);
        if ($dbGroup !== null && $dbGroup !== '') {
            config('Database')->defaultGroup = $dbGroup;
            Database::connect($dbGroup, false);
            db_connect($dbGroup, false);
        }
        $db = Database::connect($info['group'], false);
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT id, last_number FROM member_id_sequences WHERE prefix = ? FOR UPDATE',
                [self::PREFIX]
            )->getRowArray();

            $now        = date('Y-m-d H:i:s');
            $targetLast = $start - 1;
            $beforeLast = (int) ($row['last_number'] ?? 0);

            // Re-check collision under lock.
            $nextId = self::PREFIX . str_pad((string) $start, $padding, '0', STR_PAD_LEFT);
            $hit = $db->table('member_profiles')->where('member_id', $nextId)->countAllResults();
            if ($hit > 0) {
                throw new RuntimeException('Collision detected for ' . $nextId);
            }

            if ($row === null) {
                $db->table('member_id_sequences')->insert([
                    'prefix'      => self::PREFIX,
                    'last_number' => $targetLast,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            } else {
                $db->table('member_id_sequences')->where('id', $row['id'])->update([
                    'last_number' => $targetLast,
                    'updated_at'  => $now,
                ]);
            }

            // Align runtime config for this process (staging verification).
            /** @var Platform $platform */
            $platform = config('Platform');
            $platform->memberIdStartNumber = $start;
            $platform->memberIdPadding     = $padding;

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Sequence correction transaction failed.');
            }
            $db->transCommit();

            // Verify after commit so a separate connection sees last_number.
            $verifyNext = service('memberIdGenerator', false)->previewNext();
            if ($verifyNext !== $nextId) {
                throw new RuntimeException(
                    'Post-apply previewNext mismatch: expected ' . $nextId . ' got ' . $verifyNext
                    . ' (sequence committed; investigate config/start floor)'
                );
            }

            $this->audit->log('member_id.sequence_corrected', $actorUserId, 'member_id_sequence', self::PREFIX, [
                'database'    => $info['database'],
                'before_last' => $beforeLast,
                'after_last'  => $targetLast,
                'start'       => $start,
                'padding'     => $padding,
                'next_id'     => $nextId,
                'dry_run'     => false,
            ]);

            return array_merge($preview, [
                'executed'    => true,
                'dry_run'     => false,
                'before_last' => $beforeLast,
                'after_last'  => $targetLast,
                'verify_next' => $verifyNext,
                'rollback_hint' => 'UPDATE member_id_sequences SET last_number=' . $beforeLast
                    . ' WHERE prefix=\'NGW\'; restore prior .env start/padding.',
            ]);
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * @return list<array{member_id: string, numeric: int, digits: int}>
     */
    private function listExistingIds(object $db, string $prefix): array
    {
        $out = [];
        if (! $db->tableExists('member_profiles')) {
            return $out;
        }
        $rows = $db->table('member_profiles')->select('member_id')->orderBy('id', 'ASC')->get()->getResultArray();
        $quoted = preg_quote($prefix, '/');
        foreach ($rows as $row) {
            $id = (string) ($row['member_id'] ?? '');
            if (preg_match('/^' . $quoted . '(\d+)$/', $id, $m) === 1) {
                $out[] = [
                    'member_id' => $id,
                    'numeric'   => (int) $m[1],
                    'digits'    => strlen($m[1]),
                ];
            }
        }

        return $out;
    }
}
