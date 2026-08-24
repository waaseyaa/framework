<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class MutationPilotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function dependency_and_plugin_execution_are_explicitly_owned(): void
    {
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('^0.34', $composer['require-dev']['infection/infection'] ?? null);
        self::assertTrue($composer['config']['allow-plugins']['infection/extension-installer'] ?? false);
    }

    #[Test]
    public function pilot_is_bounded_advisory_and_publishes_evidence(): void
    {
        $runner = (string) file_get_contents($this->root . '/bin/test-mutation-pilot');
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');
        $documentation = (string) file_get_contents($this->root . '/docs/testing/mutation.md');

        foreach ([
            'packages/access/src/AccessResult.php',
            'packages/api/src/Sanitizer/RichTextSanitizer.php',
            'packages/foundation/src/Audit/Approval/CanonicalArgumentFingerprint.php',
            'packages/workflows/src/Binding/WorkflowBindingResolver.php',
            'packages/workflows/src/Listener/WorkflowPointerMoveGuard.php',
            'packages/workflows/src/Listener/WorkflowStateGuard.php',
        ] as $source) {
            self::assertStringContainsString($source, $runner);
        }

        self::assertStringContainsString('--min-msi=91', $runner);
        self::assertStringContainsString('--min-covered-msi=91', $runner);
        self::assertStringContainsString('--only-covering-test-cases', $runner);
        self::assertStringContainsString('FirstPublishEstablishmentFlowTest', $runner);
        self::assertStringContainsString('GroupConstraintSaveGuardTest', $runner);
        self::assertStringContainsString('mutation-summary.json', $runner);
        self::assertStringContainsString('name: ci/mutation-pilot', $workflow);
        self::assertStringContainsString('coverage: pcov', $workflow);
        self::assertStringContainsString('name: mutation-pilot', $workflow);
        self::assertStringContainsString('The original two-run baseline', $documentation);
        self::assertStringContainsString('91.40%', $documentation);
    }

    #[Test]
    public function runner_fails_actionably_without_a_line_coverage_driver(): void
    {
        if (extension_loaded('pcov') || extension_loaded('xdebug')) {
            self::markTestSkipped('The no-coverage guard is exercised only in ordinary non-coverage lanes.');
        }

        $command = [PHP_BINARY, $this->root . '/bin/test-mutation-pilot'];
        // cwd preserved exactly; env null because proc_open was given no env
        // array and the child inherited the parent's. timeout null keeps the
        // gate run unbounded.
        $process = new Process($command, $this->root, null, null, null);
        $exitCode = $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('requires PCOV or Xdebug line coverage', $output);
    }
}
