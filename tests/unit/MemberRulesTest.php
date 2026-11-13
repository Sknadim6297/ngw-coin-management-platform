<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\MemberRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class MemberRulesTest extends CIUnitTestCase
{
    public function testValidSponsorRuleRejectsEmptyThroughService(): void
    {
        $rules = new MemberRules();
        $error = null;

        // Sponsor rule still validates via backend service.
        $this->assertFalse($rules->valid_sponsor_id('', $error));
        $this->assertNotEmpty($error);
    }
}
