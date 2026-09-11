<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #3064. Studio #36 proved that APP_ENV=production install:init still required
 * an active CFG-02 generation after db:init --no-sync-schema. The property is
 * not covered by check-fresh-install-boot, which uses APP_ENV=local.
 */
#[CoversNothing]
final class ProductionInstallGenesisGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-production-install-genesis';

    private const string PROBE = 'tests/ProductionInstallGenesis/probe.php';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_proof_and_probe_exist_and_pin_the_exact_production_sequence(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        self::assertFileExists($harness);
        self::assertTrue(is_executable($harness), self::HARNESS . ' must be executable.');
        self::assertFileExists($this->repoRoot . '/' . self::PROBE);

        $harnessSource = (string) file_get_contents($harness);
        self::assertStringContainsString('APP_ENV=production', $harnessSource);
        self::assertStringContainsString('db:init --no-sync-schema', $harnessSource);
        self::assertStringContainsString('waaseyaa install:init', $harnessSource);
        self::assertStringContainsString('Activated generation', $harnessSource);
        self::assertStringContainsString('Configuration already initialized', $harnessSource);
        self::assertStringContainsString('waaseyaa_config_activation_v2 WHERE is_genesis = 1', $harnessSource);
        self::assertStringContainsString('probe.php refuse', $harnessSource);
        self::assertStringContainsString('probe.php boot', $harnessSource);
        self::assertStringContainsString('field-access:preflight --write-artifact', $harnessSource);
        self::assertStringNotContainsString("printf 'APP_ENV=local", $harnessSource);
        self::assertStringNotContainsString('APP_ENV=local\\n', $harnessSource);

        $probe = (string) file_get_contents($this->repoRoot . '/' . self::PROBE);
        self::assertStringContainsString('HttpKernel', $probe);
        self::assertStringContainsString('requireActiveGenerationId', $probe);
        self::assertStringContainsString('production-install ordinary boot refused before genesis', $probe);
        self::assertStringContainsString('production-install ordinary boot OK', $probe);
        self::assertStringContainsString('production-install active generation OK', $probe);
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/fresh-install-boot', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
    }
}
