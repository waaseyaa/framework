<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2820. `health:report` crashed in every consumer application while the
 * monorepo, the unit suite, and the first fix's own unit test were green: the
 * property was never proven from installed bytes with no application code.
 * `tests/PackagedForm/check-cli-health-report` is that proof — a disposable
 * `waaseyaa/core` + `waaseyaa/cli` consumer built from the candidate tree
 * that runs the real console runtime. It needs minutes of Composer work and
 * network, so it lives in its own hosted lane; this class is the fast
 * repo-state half that keeps the harness shape and its CI wiring under a gate
 * a developer can run in seconds.
 */
#[CoversNothing]
final class CliHealthReportGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-cli-health-report';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_proof_exists_is_runnable_and_is_consumer_shaped(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        self::assertFileExists($harness);
        self::assertTrue(is_executable($harness), self::HARNESS . ' must be executable.');

        $source = (string) file_get_contents($harness);

        // A production consumer: --no-dev, waaseyaa/core + waaseyaa/cli only,
        // installed exactly as a deployment installs it.
        self::assertStringContainsString('--no-dev', $source);
        self::assertStringContainsString('"waaseyaa/core"', $source);
        self::assertStringContainsString('"waaseyaa/cli"', $source);
        self::assertStringContainsString('waaseyaa install:init', $source);
        self::assertStringContainsString('Installation is complete.', $source);

        // No application code may stand in for the framework binding: the
        // consumer strips the skeleton's src/ and PSR-4 root before install.
        self::assertStringContainsString('rm -rf "$consumer/src"', $source);
        self::assertStringContainsString('$composer["autoload"]', $source);

        // Both documented output modes, plus the file sink, are executed.
        self::assertStringContainsString('waaseyaa health:report )', $source);
        self::assertStringContainsString('waaseyaa health:report --json )', $source);
        self::assertStringContainsString('waaseyaa health:report --json --output', $source);
        self::assertStringContainsString('JSON_THROW_ON_ERROR', $source);

        // The exact defect is rejected by name whatever the exit code, and the
        // one value only the composition contract can supply is pinned.
        self::assertStringContainsString('Cannot auto-wire', $source);
        self::assertStringContainsString('unresolvable parameter', $source);
        self::assertStringContainsString('Project Root', $source);

        // Path repositories, not the published artifact — what makes the proof
        // runnable before a tag exists.
        self::assertStringContainsString('"type" => "path"', $source);
        self::assertStringNotContainsString('repo.packagist.org', $source);
        self::assertStringNotContainsString('create-project', $source);
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/cli-health-report', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
    }

    #[Test]
    public function the_in_tree_wiring_test_accompanies_the_hosted_proof(): void
    {
        // The seconds-fast half of the same property: the real bus composes
        // the handler in the monorepo. It is what a developer runs locally;
        // the hosted lane is what proves the installed bytes.
        $test = $this->repoRoot . '/tests/Integration/OperatorDiagnostics/HealthReportCommandWiringTest.php';
        self::assertFileExists($test);

        $source = (string) file_get_contents($test);
        self::assertStringContainsString('HealthReportHandler::class', $source);
        self::assertStringContainsString("'--json' => true", $source);
    }
}
