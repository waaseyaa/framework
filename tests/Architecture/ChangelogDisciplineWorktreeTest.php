<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class ChangelogDisciplineWorktreeTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-changelog-worktree-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/tools', 0o777, true);
        mkdir($this->fixtureRoot . '/tools/lib', 0o777, true);
        mkdir($this->fixtureRoot . '/bin', 0o777, true);
        mkdir($this->fixtureRoot . '/changes/unreleased', 0o777, true);
        mkdir($this->fixtureRoot . '/packages/example/src', 0o777, true);
        copy(dirname(__DIR__, 2) . '/tools/check-changelog-discipline.sh', $this->fixtureRoot . '/tools/check-changelog-discipline.sh');
        copy(dirname(__DIR__, 2) . '/tools/lib/ChangelogFragments.php', $this->fixtureRoot . '/tools/lib/ChangelogFragments.php');
        copy(dirname(__DIR__, 2) . '/bin/changelog-fragments', $this->fixtureRoot . '/bin/changelog-fragments');
        touch($this->fixtureRoot . '/changes/unreleased/.gitkeep');
        file_put_contents($this->fixtureRoot . '/changes/unreleased/42.baseline.fixed.md', "- Original.\n");
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

        file_put_contents(
            $this->fixtureRoot . '/changes/unreleased/2563.example.changed.md',
            "- Changed example.\n",
        );
        [$fixedExit, $fixedOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );
        self::assertSame(0, $fixedExit, $fixedOutput);
    }

    #[Test]
    public function root_changelog_edits_require_the_separate_maintenance_marker(): void
    {
        file_put_contents($this->fixtureRoot . '/CHANGELOG.md', "# Changelog\n\nHistorical correction.\n");

        [$refused, $output] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );
        self::assertSame(1, $refused);
        self::assertStringContainsString('release-owned', $output);

        [$accepted, $acceptedOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
            environment: ['PR_BODY' => 'changelog-maintenance: correct a released link'],
        );
        self::assertSame(0, $accepted, $acceptedOutput);
    }

    #[Test]
    public function invalid_or_preexisting_fragment_mutation_is_refused(): void
    {
        file_put_contents($this->fixtureRoot . '/changes/unreleased/bad-name.md', "- Bad.\n");
        [$invalid, $invalidOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );
        self::assertSame(1, $invalid);
        self::assertStringContainsString('invalid fragment filename', $invalidOutput);
        unlink($this->fixtureRoot . '/changes/unreleased/bad-name.md');

        file_put_contents($this->fixtureRoot . '/changes/unreleased/42.baseline.fixed.md', "- Rewritten.\n");
        [$rewritten, $rewrittenOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );
        self::assertSame(1, $rewritten);
        self::assertStringContainsString('append-only', $rewrittenOutput);
    }

    #[Test]
    public function a_fragment_added_by_this_pr_can_be_amended_before_merge(): void
    {
        file_put_contents($this->fixtureRoot . '/changes/unreleased/2563.amend.changed.md', "- First draft.\n");
        $this->executeCommand('git add changes/unreleased/2563.amend.changed.md');
        $this->executeCommand('git commit --quiet -m current-pr-fragment');
        file_put_contents($this->fixtureRoot . '/changes/unreleased/2563.amend.changed.md', "- Reviewed draft.\n");

        [$exitCode, $output] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD^',
            allowFailure: true,
        );
        self::assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function upgrade_guide_and_documented_no_changelog_paths_remain_available(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/packages/example/src/Example.php',
            "<?php\nfinal class Example { public const CHANGED = true; }\n",
        );
        mkdir($this->fixtureRoot . '/docs/upgrades', 0o777, true);
        file_put_contents($this->fixtureRoot . '/docs/upgrades/example.md', "# Upgrade\n");
        [$upgrade, $upgradeOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
        );
        self::assertSame(0, $upgrade, $upgradeOutput);

        unlink($this->fixtureRoot . '/docs/upgrades/example.md');
        [$override, $overrideOutput] = $this->executeCommand(
            'bash tools/check-changelog-discipline.sh --include-worktree HEAD',
            allowFailure: true,
            environment: ['PR_BODY' => 'no-changelog: internal compatibility-only adjustment'],
        );
        self::assertSame(0, $override, $overrideOutput);
    }

    /** @return array{int, string} */
    private function executeCommand(string $command, bool $allowFailure = false, array $environment = []): array
    {
        $bash = 'bash';
        if (PHP_OS_FAMILY === 'Windows') {
            $gitExecutables = preg_split('/\R/', (string) shell_exec('where git 2>NUL')) ?: [];
            foreach ($gitExecutables as $gitExecutable) {
                $candidate = dirname(dirname($gitExecutable)) . '/bin/bash.exe';
                if ($gitExecutable !== '' && is_file($candidate)) {
                    $bash = $candidate;
                    break;
                }
            }
        }
        $process = new Process(
            [$bash, '-c', $command],
            $this->fixtureRoot,
            $environment === [] ? null : array_merge($_ENV, $environment),
        );
        $exitCode = $process->run();
        $joined = trim($process->getOutput() . $process->getErrorOutput());
        if (!$allowFailure) {
            self::assertSame(0, $exitCode, $joined);
        }

        return [$exitCode, $joined];
    }
}
