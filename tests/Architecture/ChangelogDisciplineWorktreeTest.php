<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ChangelogDisciplineWorktreeTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-changelog-worktree-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/tools', 0o777, true);
        mkdir($this->fixtureRoot . '/packages/example/src', 0o777, true);
        copy(dirname(__DIR__, 2) . '/tools/check-changelog-discipline.sh', $this->fixtureRoot . '/tools/check-changelog-discipline.sh');
        file_put_contents($this->fixtureRoot . '/packages/example/src/Example.php', "<?php\nfinal class Example {}\n");
        file_put_contents($this->fixtureRoot . '/CHANGELOG.md', "# Changelog\n");

        $this->executeCommand('git init --quiet');
        $this->executeCommand('git config user.email test@example.com');
        $this->executeCommand('git config user.name "Changelog Test"');
        $this->executeCommand('git add .');
        $this->executeCommand('git commit --quiet -m baseline');
    }

    protected function tearDown(): void
    {
        $this->executeCommand('find . -depth -delete', allowFailure: true);
        @rmdir($this->fixtureRoot);
    }

    #[Test]
    public function worktree_surface_change_requires_a_worktree_changelog_update(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/example/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );

        [$committedExit] = $this->executeCommand('bash tools/check-changelog-discipline.sh HEAD', allowFailure: true);
        [$worktreeExit, $worktreeOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );

        self::assertSame(0, $committedExit);
        self::assertSame(1, $worktreeExit, $worktreeOutput);
        self::assertStringContainsString('packages/example/src/Example.php', $worktreeOutput);

        file_put_contents($this->fixtureRoot . '/CHANGELOG.md', "# Changelog\n\n- Changed example.\n");
        [$fixedExit, $fixedOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );
        self::assertSame(0, $fixedExit, $fixedOutput);
    }

    /** @return array{int, string} */
    private function executeCommand(string $command, bool $allowFailure = false): array
    {
        exec('cd ' . escapeshellarg($this->fixtureRoot) . ' && ' . $command . ' 2>&1', $output, $exitCode);
        $joined = implode("\n", $output);
        if (!$allowFailure) {
            self::assertSame(0, $exitCode, $joined);
        }

        return [$exitCode, $joined];
    }
}
