<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Phase 7A: read-only genealogy / sponsor-network integrity checks.
 * Detects issues only — never rewrites sponsor relationships.
 */
class GenealogyIntegrityService
{
    public function __construct(
        private readonly ReferralTeamService $team = new ReferralTeamService()
    ) {
    }

    /**
     * @return array{
     *   generated_at: string,
     *   summary: array<string, int>,
     *   findings: list<array<string, mixed>>,
     *   auto_repair: false
     * }
     */
    public function scan(int $limit = 100): array
    {
        $limit    = max(1, min(500, $limit));
        $findings = array_merge(
            $this->selfSponsorship($limit),
            $this->orphanProfiles($limit),
            $this->missingSponsorUsers($limit),
            $this->invalidSponsorMemberIds($limit),
            $this->circularChains($limit),
            $this->duplicateEdges($limit)
        );

        $summary = [
            'total_findings' => count($findings),
            'critical'       => 0,
            'warning'        => 0,
            'info'           => 0,
        ];
        foreach ($findings as $f) {
            $sev = (string) ($f['severity'] ?? 'info');
            if (isset($summary[$sev])) {
                $summary[$sev]++;
            }
        }

        if ($findings === []) {
            $findings[] = [
                'code'     => 'genealogy_ok',
                'severity' => 'info',
                'message'  => 'No genealogy integrity issues detected in this bounded scan.',
            ];
            $summary['info']           = 1;
            $summary['total_findings'] = 1;
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'summary'      => $summary,
            'findings'     => $findings,
            'auto_repair'  => false,
        ];
    }

    /**
     * @return array{critical: int, warning: int, total: int}
     */
    public function issueCounts(int $limit = 50): array
    {
        $report = $this->scan($limit);
        $critical = 0;
        $warning  = 0;
        foreach ($report['findings'] as $f) {
            if (($f['code'] ?? '') === 'genealogy_ok') {
                continue;
            }
            $sev = (string) ($f['severity'] ?? 'info');
            if ($sev === 'critical') {
                $critical++;
            } elseif ($sev === 'warning') {
                $warning++;
            }
        }

        return [
            'critical' => $critical,
            'warning'  => $warning,
            'total'    => $critical + $warning,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selfSponsorship(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT id, member_user_id, sponsor_user_id, sponsor_member_id
             FROM sponsor_relationships
             WHERE member_user_id = sponsor_user_id
             ORDER BY id ASC
             LIMIT ' . (int) $limit
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'              => 'self_sponsorship',
                'severity'          => 'critical',
                'message'           => 'Member sponsors themselves.',
                'member_user_id'    => (int) $row['member_user_id'],
                'sponsor_member_id' => $row['sponsor_member_id'],
            ];
        }

        return $out;
    }

    /**
     * Non-root profiles with no sponsor edge.
     *
     * @return list<array<string, mixed>>
     */
    private function orphanProfiles(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT p.user_id, p.member_id, p.member_name, p.account_status
             FROM member_profiles p
             LEFT JOIN sponsor_relationships sr ON sr.member_user_id = p.user_id
             WHERE p.is_root_sponsor = 0 AND sr.id IS NULL
             ORDER BY p.id ASC
             LIMIT ' . (int) $limit
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'           => 'orphan_member',
                'severity'       => 'warning',
                'message'        => 'Non-root member has no sponsor relationship row.',
                'member_user_id' => (int) $row['user_id'],
                'member_id'      => $row['member_id'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingSponsorUsers(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT sr.id, sr.member_user_id, sr.sponsor_user_id, sr.sponsor_member_id
             FROM sponsor_relationships sr
             LEFT JOIN users u ON u.id = sr.sponsor_user_id
             WHERE u.id IS NULL
             ORDER BY sr.id ASC
             LIMIT ' . (int) $limit
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'              => 'missing_sponsor_user',
                'severity'          => 'critical',
                'message'           => 'Sponsor user_id does not exist.',
                'member_user_id'    => (int) $row['member_user_id'],
                'sponsor_user_id'   => (int) $row['sponsor_user_id'],
                'sponsor_member_id' => $row['sponsor_member_id'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invalidSponsorMemberIds(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT sr.id, sr.member_user_id, sr.sponsor_user_id, sr.sponsor_member_id
             FROM sponsor_relationships sr
             LEFT JOIN member_profiles p ON p.member_id = sr.sponsor_member_id
             WHERE p.id IS NULL
             ORDER BY sr.id ASC
             LIMIT ' . (int) $limit
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'              => 'invalid_sponsor_member_id',
                'severity'          => 'critical',
                'message'           => 'sponsor_member_id does not match any member profile.',
                'member_user_id'    => (int) $row['member_user_id'],
                'sponsor_member_id' => $row['sponsor_member_id'],
            ];
        }

        // Denormalized sponsor_member_id disagrees with sponsor_user_id profile.
        $mismatch = db_connect()->query(
            'SELECT sr.id, sr.member_user_id, sr.sponsor_user_id, sr.sponsor_member_id, p.member_id AS actual_member_id
             FROM sponsor_relationships sr
             INNER JOIN member_profiles p ON p.user_id = sr.sponsor_user_id
             WHERE sr.sponsor_member_id <> p.member_id
             ORDER BY sr.id ASC
             LIMIT ' . (int) $limit
        )->getResultArray();
        foreach ($mismatch as $row) {
            $out[] = [
                'code'              => 'sponsor_id_mismatch',
                'severity'          => 'warning',
                'message'           => 'sponsor_member_id does not match sponsor_user_id profile.',
                'member_user_id'    => (int) $row['member_user_id'],
                'sponsor_member_id' => $row['sponsor_member_id'],
                'actual_member_id'  => $row['actual_member_id'],
            ];
        }

        return $out;
    }

    /**
     * Detect cycles by walking ancestry with a seen set.
     *
     * @return list<array<string, mixed>>
     */
    private function circularChains(int $limit): array
    {
        $rows = db_connect()->table('sponsor_relationships')
            ->select('member_user_id, sponsor_user_id, sponsor_member_id')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $memberId = (int) $row['member_user_id'];
            $sponsorId = (int) $row['sponsor_user_id'];
            if ($this->team->wouldCreateCycle($memberId, $sponsorId)) {
                // wouldCreateCycle is true for self OR if member appears in sponsor ancestry.
                // For an existing edge member->sponsor, cycle means walking from sponsor hits member.
                $out[] = [
                    'code'              => 'circular_sponsorship',
                    'severity'          => 'critical',
                    'message'           => 'Circular sponsor chain detected.',
                    'member_user_id'    => $memberId,
                    'sponsor_user_id'   => $sponsorId,
                    'sponsor_member_id' => $row['sponsor_member_id'],
                ];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Unique constraint should prevent this; still report if present.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateEdges(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT member_user_id, COUNT(*) AS cnt
             FROM sponsor_relationships
             GROUP BY member_user_id
             HAVING COUNT(*) > 1
             ORDER BY cnt DESC
             LIMIT ' . (int) $limit
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'           => 'duplicate_sponsor_edge',
                'severity'       => 'critical',
                'message'        => 'Member has multiple sponsor relationship rows.',
                'member_user_id' => (int) $row['member_user_id'],
                'count'          => (int) $row['cnt'],
            ];
        }

        return $out;
    }
}
