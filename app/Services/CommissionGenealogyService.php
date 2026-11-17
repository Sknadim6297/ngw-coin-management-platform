<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Phase 7C: resolve upline sponsors for commission levels from sponsor_relationships.
 * Read-only. Uses current edges (historical sponsor snapshots are not stored — documented limitation).
 */
class CommissionGenealogyService
{
    public function __construct(
        private readonly ReferralTeamService $team = new ReferralTeamService()
    ) {
    }

    /**
     * @return array{
     *   purchaser_user_id: int,
     *   chain: list<array{level: int, user_id: int, member_id: string}>,
     *   valid: bool,
     *   reason_codes: list<string>
     * }
     */
    public function resolveUpline(int $purchaserUserId, int $maxLevels = 3): array
    {
        $maxLevels = max(1, min(3, $maxLevels));
        $db        = db_connect();
        $chain     = [];
        $reasons   = [];
        $seen      = [$purchaserUserId => true];
        $current   = $purchaserUserId;

        for ($level = 1; $level <= $maxLevels; $level++) {
            $row = $db->table('sponsor_relationships sr')
                ->select('sr.sponsor_user_id, sr.sponsor_member_id, p.member_id, p.account_status')
                ->join('member_profiles p', 'p.user_id = sr.sponsor_user_id', 'left')
                ->where('sr.member_user_id', $current)
                ->get()
                ->getRowArray();

            if ($row === null) {
                $reasons[] = 'missing_level_' . $level;
                break;
            }

            $sponsorUserId = (int) $row['sponsor_user_id'];
            if ($sponsorUserId <= 0) {
                $reasons[] = 'invalid_sponsor_user_level_' . $level;
                break;
            }
            if ($sponsorUserId === $purchaserUserId) {
                $reasons[] = 'self_referral';
                break;
            }
            if (isset($seen[$sponsorUserId])) {
                $reasons[] = 'circular_or_duplicate_beneficiary';
                break;
            }
            if ($this->team->wouldCreateCycle($current, $sponsorUserId) && $level === 1) {
                // Existing edge cycle detection for corrupted trees.
                $reasons[] = 'circular_sponsorship';
                break;
            }

            $memberId = (string) ($row['member_id'] ?? $row['sponsor_member_id'] ?? '');
            if ($memberId === '') {
                $reasons[] = 'sponsor_profile_missing_level_' . $level;
                break;
            }

            $seen[$sponsorUserId] = true;
            $chain[] = [
                'level'           => $level,
                'user_id'         => $sponsorUserId,
                'member_id'       => $memberId,
                'account_status'  => (string) ($row['account_status'] ?? ''),
            ];
            $current = $sponsorUserId;
        }

        return [
            'purchaser_user_id' => $purchaserUserId,
            'chain'             => $chain,
            'valid'             => $chain !== [] && ! in_array('self_referral', $reasons, true)
                && ! in_array('circular_sponsorship', $reasons, true)
                && ! in_array('circular_or_duplicate_beneficiary', $reasons, true),
            'reason_codes'      => $reasons,
            'snapshot_note'     => 'Uses current sponsor_relationships; historical sponsor-at-purchase snapshots are not stored.',
        ];
    }
}
