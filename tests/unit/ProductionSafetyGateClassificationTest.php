<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FinancialSandboxService;
use App\Services\ProductionSafetyGate;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Production database classification / fail-closed guard (no data is read or written).
 *
 * @internal
 */
final class ProductionSafetyGateClassificationTest extends CIUnitTestCase
{
    private const REAL_PROD = 'wwwzeont_ngw_test_db';

    protected function tearDown(): void
    {
        putenv('platform.liveDatabase');
        unset($_SERVER['platform.liveDatabase'], $_ENV['platform.liveDatabase']);
        $cfg = config('Database');
        unset($cfg->safetyprobe);
        parent::tearDown();
    }

    /** Point a throw-away group at an arbitrary DB name (lazy connection; never connects). */
    private function probeGroup(string $database): string
    {
        $cfg                = config('Database');
        $cfg->safetyprobe   = array_merge($cfg->tests, ['database' => $database]);

        return 'safetyprobe';
    }

    public function testActualProductionDatabaseIsClassifiedLive(): void
    {
        $this->assertSame('live', ProductionSafetyGate::classify(self::REAL_PROD));
        $this->assertTrue(ProductionSafetyGate::isLiveDatabase(self::REAL_PROD));
        $this->assertContains(self::REAL_PROD, ProductionSafetyGate::liveDatabases());
        $this->assertContains(self::REAL_PROD, FinancialSandboxService::FORBIDDEN_DATABASES);

        $info = (new ProductionSafetyGate())->inspect($this->probeGroup(self::REAL_PROD));
        $this->assertTrue($info['is_live']);
        $this->assertSame('live', $info['classification']);
        $this->assertSame(self::REAL_PROD, $info['database']);
    }

    public function testKnownProductionDatabasesRemainLive(): void
    {
        foreach (['coin_platform', 'ng_database'] as $db) {
            $this->assertSame('live', ProductionSafetyGate::classify($db), $db);
        }
        // Case variants are still production (fail-closed).
        $this->assertSame('live', ProductionSafetyGate::classify('WWWZEONT_NGW_TEST_DB'));
        $this->assertSame('live', ProductionSafetyGate::classify('anything_prod_db'));
    }

    public function testProductionMutationRequiresExplicitAuthorizationAndExactPhrase(): void
    {
        $gate  = new ProductionSafetyGate();
        $group = $this->probeGroup(self::REAL_PROD);

        foreach ([[false, null], [false, ProductionSafetyGate::CONFIRM_PHRASE], [true, null], [true, ''], [true, 'yes'], [true, 'i_authorize_production_change']] as [$allow, $phrase]) {
            try {
                $gate->assertMutationAllowed($allow, $phrase, $group, 'unit');
                $this->fail('Production mutation was not refused (allow=' . var_export($allow, true) . ').');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $ok = $gate->assertMutationAllowed(true, ProductionSafetyGate::CONFIRM_PHRASE, $group, 'unit');
        $this->assertTrue($ok['is_live']);
    }

    public function testNameContainingTestOrPhaseIsNotTreatedAsSandbox(): void
    {
        foreach (['my_test_db', 'prod_phase13c', 'wwwzeont_other_phase_db', 'customer_test'] as $db) {
            $this->assertNotSame('sandbox', ProductionSafetyGate::classify($db), $db);
            $this->assertTrue(ProductionSafetyGate::requiresProductionProtection($db), $db);
        }

        // Ambiguous (unknown) name: mutation is refused without authorization (fail-closed).
        $this->expectException(RuntimeException::class);
        (new ProductionSafetyGate())->assertMutationAllowed(false, null, $this->probeGroup('some_unlisted_db'), 'unit');
    }

    public function testSandboxDatabasesRemainIsolatedAndNeverPassAsProduction(): void
    {
        foreach (FinancialSandboxService::ALLOWED_DATABASES as $db) {
            $this->assertSame('sandbox', ProductionSafetyGate::classify($db), $db);
            $this->assertFalse(ProductionSafetyGate::requiresProductionProtection($db), $db);
        }
        $this->assertSame('sandbox', ProductionSafetyGate::classify('ng_database_phase14_snapshot'));

        $info = (new FinancialSandboxService())->inspectConnection($this->probeGroup('ng_database_phase14'));
        $this->assertTrue($info['is_allowed']);
        $this->assertFalse($info['is_forbidden']);

        $prod = (new FinancialSandboxService())->inspectConnection($this->probeGroup(self::REAL_PROD));
        $this->assertFalse($prod['is_allowed']);
        $this->assertTrue($prod['is_forbidden']);

        $this->expectException(RuntimeException::class);
        (new FinancialSandboxService())->assertIsolatedDatabase($this->probeGroup(self::REAL_PROD));
    }

    public function testEveryAllowlistedSandboxIsListedAsNonProductionAndNeverLive(): void
    {
        $this->assertSame([], array_values(array_intersect(ProductionSafetyGate::LIVE_DATABASES, ProductionSafetyGate::NON_PRODUCTION_DATABASES)));
        $this->assertSame([], array_values(array_diff(FinancialSandboxService::ALLOWED_DATABASES, ProductionSafetyGate::NON_PRODUCTION_DATABASES)));
        $this->assertSame([], array_values(array_diff(ProductionSafetyGate::LIVE_DATABASES, FinancialSandboxService::FORBIDDEN_DATABASES)));
    }

    public function testEnvOverrideIsAdditiveAndLiveWinsOverSandbox(): void
    {
        putenv('platform.liveDatabase=custom_live_db, coin_platform_phase13a');
        $this->assertSame('live', ProductionSafetyGate::classify('custom_live_db'));
        // Explicit live declaration beats the sandbox allowlist.
        $this->assertSame('live', ProductionSafetyGate::classify('coin_platform_phase13a'));
        // Hard-coded production names can never be removed by configuration.
        $this->assertSame('live', ProductionSafetyGate::classify(self::REAL_PROD));
    }

    public function testEmptyDatabaseNameFailsClosed(): void
    {
        $this->assertSame('unknown', ProductionSafetyGate::classify(''));
        $this->assertTrue(ProductionSafetyGate::requiresProductionProtection(''));
    }

    public function testPhpUnitDatabaseIsSandboxOnlyUnderTestingEnvironment(): void
    {
        $testsDb = (string) config('Database')->tests['database'];
        $this->assertSame('testing', ENVIRONMENT);
        $this->assertSame('sandbox', ProductionSafetyGate::classify($testsDb));

        // If the tests group ever pointed at the real production DB, live still wins.
        $this->assertSame('live', ProductionSafetyGate::classify(self::REAL_PROD));
    }

    public function testFinancialKillSwitchesStillOff(): void
    {
        $gate = new ProductionSafetyGate();
        $gate->assertKillSwitchesOff();
        foreach ($gate->killSwitchSnapshot() as $name => $on) {
            $this->assertFalse((bool) $on, $name);
        }
    }
}
