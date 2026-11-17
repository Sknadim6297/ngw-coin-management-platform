<?php

declare(strict_types=1);

namespace App\Services;

use App\Entities\MemberProfile;
use App\Models\MemberProfileModel;
use Config\Platform;

class SponsorValidator
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_INACTIVE  = 'inactive';
    public const REASON_SELF      = 'self';
    public const REASON_OK        = 'ok';

    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{valid: bool, reason: string, profile: ?MemberProfile, message: string}
     */
    public function validate(string $sponsorId, ?string $excludeMemberId = null): array
    {
        $sponsorId = trim($sponsorId);
        $excludeMemberId = $excludeMemberId !== null ? strtoupper(trim($excludeMemberId)) : null;

        if ($sponsorId === '') {
            return [
                'valid'   => false,
                'reason'  => self::REASON_NOT_FOUND,
                'profile' => null,
                'message' => 'Sponsor ID is required.',
            ];
        }

        if ($excludeMemberId !== null && strtoupper($sponsorId) === $excludeMemberId) {
            return [
                'valid'   => false,
                'reason'  => self::REASON_SELF,
                'profile' => null,
                'message' => 'A member cannot sponsor themselves.',
            ];
        }

        $profile = $this->profiles->findByMemberId($sponsorId);

        if ($profile === null) {
            return [
                'valid'   => false,
                'reason'  => self::REASON_NOT_FOUND,
                'profile' => null,
                'message' => 'Sponsor ID was not found.',
            ];
        }

        $config = $this->config ?? config('Platform');

        // Root sponsor is always eligible regardless of other rules.
        if ($profile->is_root_sponsor || $profile->member_id === $config->rootSponsorId) {
            return [
                'valid'   => true,
                'reason'  => self::REASON_OK,
                'profile' => $profile,
                'message' => 'Sponsor validated.',
            ];
        }

        if ($profile->isInactive()) {
            return [
                'valid'   => false,
                'reason'  => self::REASON_INACTIVE,
                'profile' => $profile,
                'message' => 'Inactive members cannot sponsor registrations.',
            ];
        }

        return [
            'valid'   => true,
            'reason'  => self::REASON_OK,
            'profile' => $profile,
            'message' => 'Sponsor validated.',
        ];
    }
}
