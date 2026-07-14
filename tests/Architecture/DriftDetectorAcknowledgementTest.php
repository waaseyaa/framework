<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DriftDetectorAcknowledgementTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-drift-detector-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/tools', 0o777, true);
        mkdir($this->fixtureRoot . '/packages/entity/src', 0o777, true);
        mkdir($this->fixtureRoot . '/docs/specs', 0o777, true);

        copy(dirname(__DIR__, 2) . '/tools/drift-detector.sh', $this->fixtureRoot . '/tools/drift-detector.sh');
        file_put_contents($this->fixtureRoot . '/packages/entity/src/Example.php', "<?php\nfinal class Example {}\n");
        file_put_contents($this->fixtureRoot . '/docs/specs/entity-system.md', "# Entity system\n");

        $this->executeCommand('git init --quiet');
        $this->executeCommand('git config user.email test@example.com');
        $this->executeCommand('git config user.name "Drift Detector Test"');
        $this->executeCommand('git add .');
        $this->executeCommand('git commit --quiet -m baseline');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->fixtureRoot);
    }

    #[Test]
    public function accepts_a_backtick_wrapped_commit_trailer(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'fix: non-contract change' -m 'spec-reviewed: `docs/specs/entity-system.md` - no contract change'");

        [$exitCode, $output] = $this->executeCommand('bash tools/drift-detector.sh HEAD~1', allowFailure: true);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString("OK: docs/specs/entity-system.md (acknowledged via 'spec-reviewed:')", $output);
    }

    #[Test]
    public function ignores_pr_body_acknowledgements_because_ci_enforces_commit_trailers(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/entity/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        $this->executeCommand('git add packages/entity/src/Example.php');
        $this->executeCommand("git commit --quiet -m 'fix: unacknowledged contract-bearing change'");

        [$exitCode, $output] = $this->executeCommand(
            "PR_BODY='spec-reviewed: docs/specs/entity-system.md - claimed only in PR body' bash tools/drift-detector.sh HEAD~1",
            allowFailure: true,
        );

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('STALE: docs/specs/entity-system.md', $output);
    }

    /** @return array{int, string} */
    private function executeCommand(string $command, bool $allowFailure = false): array
    {
        $output = [];
        $exitCode = 0;
        exec('cd ' . escapeshellarg($this->fixtureRoot) . ' && ' . $command . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);

        if (!$allowFailure) {
            self::assertSame(0, $exitCode, "Command failed: {$command}\n{$joined}");
        }

        return [$exitCode, $joined];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
