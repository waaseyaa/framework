<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Unit tests for bin/lib/s1-roster.php — the shared roster scanner whose
 * schema-v2 identity binds semantic content (path, pattern, class, normalized
 * match hash, occurrence index), never line numbers or whole-file hashes
 * (#2400 item 6, docs/specs/governed-gates.md §6).
 *
 * The fixture is a real git repository: since #2925 the scanner enumerates
 * repository files through git (tracked plus untracked-but-not-ignored), so
 * the ignore boundary — not a hand-maintained path denylist — is what keeps
 * nested worktrees and nested vendor/ trees out of every scan. Files written
 * by the individual tests are left untracked on purpose: an untracked,
 * non-ignored file is exactly the worktree content governed-gates.md §1 says
 * a local run must see.
 */
#[CoversNothing]
final class S1RosterLibraryTest extends TestCase
{
    private string $tempDir = '';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/lib/s1-roster.php';
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_s1roster_' . uniqid('', true);
        mkdir($this->tempDir . '/packages/demo/src', 0o755, true);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 's1roster@example.test');
        $this->git('config', 'user.name', 'S1 Roster Test');
        // Mirrors the real repository's ignore boundary for the trees that
        // exist only in developer clones (#2865, #2925).
        file_put_contents($this->tempDir . '/.gitignore', implode("\n", [
            'vendor/',
            'packages/*/vendor/',
            'node_modules/',
            'storage/*',
            '/tmp/',
            '.worktrees/',
            '.claude/worktrees/',
            '',
        ]));
        $this->git('add', '.gitignore');
        $this->git('commit', '-q', '-m', 'fixture ignore boundary');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tempDir);
    }

    private function git(string ...$arguments): void
    {
        $process = new Process(['git', '-C', $this->tempDir, ...$arguments]);
        $process->mustRun();
    }

    /**
     * @param list<string> $scanRoots
     * @return list<array<string, int|string>>
     */
    private function scan(array $scanRoots = ['packages']): array
    {
        return s1RosterScan(
            $this->tempDir,
            $scanRoots,
            ['needle' => '/\bGovernedNeedle\b/'],
            static fn(string $relative, string $contents): bool => str_ends_with($relative, '.php'),
            static fn(string $relative, string $patternId): string => 'demo-class',
        );
    }

    #[Test]
    public function canonical_entries_bind_semantic_identity_only(): void
    {
        file_put_contents(
            $this->tempDir . '/packages/demo/src/A.php',
            "<?php\nGovernedNeedle::run();\n",
        );

        $canonical = s1RosterCanonicalize($this->scan());

        $this->assertCount(1, $canonical);
        $this->assertSame(
            ['path', 'pattern', 'class', 'match_sha256', 'occurrence'],
            array_keys($canonical[0]),
            'Canonical entries must carry exactly the semantic identity keys — no line, line_sha256, or source_sha256.',
        );
        $this->assertSame('packages/demo/src/A.php', $canonical[0]['path']);
        $this->assertSame(1, $canonical[0]['occurrence']);
    }

    #[Test]
    public function line_shifts_and_unrelated_edits_do_not_change_the_canonical_document(): void
    {
        $file = $this->tempDir . '/packages/demo/src/A.php';
        file_put_contents($file, "<?php\nGovernedNeedle::run();\n");
        $before = s1RosterCanonicalize($this->scan());

        // The #2399 failure shape: an added import/comment line above the match.
        file_put_contents($file, "<?php\n// unrelated comment\nuse Some\\NewImport;\n\nGovernedNeedle::run();\n");
        $after = s1RosterCanonicalize($this->scan());

        $this->assertSame($before, $after, 'A pure line shift must not invalidate the roster.');
    }

    #[Test]
    public function duplicate_matches_are_preserved_via_occurrence_indices(): void
    {
        file_put_contents(
            $this->tempDir . '/packages/demo/src/A.php',
            "<?php\nGovernedNeedle::run();\nGovernedNeedle::run();\n",
        );

        $canonical = s1RosterCanonicalize($this->scan());

        $this->assertCount(2, $canonical);
        $this->assertSame([1, 2], array_column($canonical, 'occurrence'));
        $this->assertSame($canonical[0]['match_sha256'], $canonical[1]['match_sha256']);
    }

    #[Test]
    public function removing_one_duplicate_changes_the_canonical_document(): void
    {
        $file = $this->tempDir . '/packages/demo/src/A.php';
        file_put_contents($file, "<?php\nGovernedNeedle::run();\nGovernedNeedle::run();\n");
        $two = s1RosterCanonicalize($this->scan());

        file_put_contents($file, "<?php\nGovernedNeedle::run();\n");
        $one = s1RosterCanonicalize($this->scan());

        $this->assertNotSame($two, $one, 'Multiplicity is part of the governed surface.');
    }

    #[Test]
    public function match_hash_normalizes_case_and_internal_whitespace(): void
    {
        // Same semantic occurrence spelled with different case/whitespace must
        // produce one identity, so formatting churn cannot invalidate rosters.
        $this->assertSame(
            s1RosterMatchHash('New  GovernedNeedle ('),
            s1RosterMatchHash('new GovernedNeedle('),
        );
    }

    #[Test]
    public function tracked_and_untracked_repository_files_are_both_scanned(): void
    {
        // governed-gates.md §1: a local run sees staged, unstaged, AND
        // untracked worktree files — a new source file a developer has not
        // yet `git add`ed is still governed content.
        file_put_contents($this->tempDir . '/packages/demo/src/Tracked.php', "<?php\nGovernedNeedle::run();\n");
        $this->git('add', 'packages/demo/src/Tracked.php');
        $this->git('commit', '-q', '-m', 'tracked candidate');
        file_put_contents($this->tempDir . '/packages/demo/src/Untracked.php', "<?php\nGovernedNeedle::run();\n");

        $paths = array_column($this->scan(), 'path');
        sort($paths);

        $this->assertSame(['packages/demo/src/Tracked.php', 'packages/demo/src/Untracked.php'], $paths);
    }

    #[Test]
    public function nested_worktrees_and_nested_vendor_never_contribute_candidates(): void
    {
        // The #2925 fixture: one tracked candidate proves the scan is not
        // vacuous, and every other match sits in a tree that exists only in a
        // developer clone — registered nested git worktrees (.worktrees/ and
        // .claude/worktrees/), a populated packages/<pkg>/vendor/, gitignored
        // build/runtime dirs, the .git/ directory itself, and a nested
        // repository that is NOT gitignored (git never descends into another
        // repository's work tree, so it is excluded by construction too).
        file_put_contents($this->tempDir . '/packages/demo/src/Real.php', "<?php\nGovernedNeedle::run();\n");
        $this->git('add', 'packages/demo/src/Real.php');
        $this->git('commit', '-q', '-m', 'real candidate');

        $this->git('worktree', 'add', '-q', $this->tempDir . '/.worktrees/wf1', '-b', 'wf1');
        $this->git('worktree', 'add', '-q', $this->tempDir . '/.claude/worktrees/wf2', '-b', 'wf2');
        mkdir($this->tempDir . '/nested-repo', 0o755, true);
        new Process(['git', '-C', $this->tempDir . '/nested-repo', 'init', '-q'])->mustRun();

        foreach ([
            '.worktrees/wf1/packages/demo/src',
            '.worktrees/wf1/tmp/phpstan',
            '.claude/worktrees/wf2/packages/demo/src',
            'packages/demo/vendor/dep/src',
            'vendor/lib',
            'packages/demo/node_modules/dep',
            'storage/cache',
            'tmp/scratch',
            '.git/hooks',
            'nested-repo/src',
        ] as $dir) {
            if (!is_dir($this->tempDir . '/' . $dir)) {
                mkdir($this->tempDir . '/' . $dir, 0o755, true);
            }
            file_put_contents($this->tempDir . '/' . $dir . '/Poison.php', "<?php\nGovernedNeedle::run();\n");
        }

        $entries = $this->scan(['']);

        $this->assertSame(
            [['path' => 'packages/demo/src/Real.php']],
            array_map(static fn(array $entry): array => ['path' => $entry['path']], $entries),
            'Only repository content may contribute candidates: nested worktrees, nested vendor/, ignored trees, .git/, and nested repositories must all be invisible to the scan.',
        );
        // The roster write path is canonicalize(scan), so an untracked path
        // that never enters the scan can never be recorded (#2925).
        $this->assertSame(['packages/demo/src/Real.php'], array_column(s1RosterCanonicalize($entries), 'path'));
    }

    #[Test]
    public function tracked_claude_content_is_still_scanned(): void
    {
        // #2865 guards both directions: .claude/worktrees/ is a separate
        // checkout and excluded, but .claude/ itself holds tracked repository
        // content (.claude/rules/*.md, .claude/settings.json), so an
        // exclusion widened to all of .claude/ is a regression, not a fix.
        mkdir($this->tempDir . '/.claude/rules', 0o755, true);
        file_put_contents($this->tempDir . '/.claude/rules/Tracked.php', "<?php\nGovernedNeedle::run();\n");

        $entries = $this->scan(['']);

        $this->assertCount(1, $entries, 'Tracked .claude/ content must remain scannable.');
        $this->assertSame('.claude/rules/Tracked.php', $entries[0]['path']);
        $this->assertSame([], $this->scan(['packages']), 'A scan root scopes the enumeration to that subtree.');
    }

    #[Test]
    public function scan_fails_closed_when_the_root_is_not_a_git_repository(): void
    {
        $plainDir = sys_get_temp_dir() . '/waaseyaa_s1roster_plain_' . uniqid('', true);
        mkdir($plainDir . '/packages/demo/src', 0o755, true);
        file_put_contents($plainDir . '/packages/demo/src/A.php', "<?php\nGovernedNeedle::run();\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('git');
            s1RosterScan(
                $plainDir,
                ['packages'],
                ['needle' => '/\bGovernedNeedle\b/'],
                static fn(string $relative, string $contents): bool => true,
                static fn(string $relative, string $patternId): string => 'demo-class',
            );
        } finally {
            new Filesystem()->remove($plainDir);
        }
    }

    #[Test]
    public function diff_names_new_candidates_with_live_line_numbers_and_the_repair_command(): void
    {
        file_put_contents($this->tempDir . '/packages/demo/src/A.php', "<?php\nGovernedNeedle::run();\n");
        $live = $this->scan();

        $lines = s1RosterDiff([], $live, '--write-roster');

        $this->assertNotSame([], $lines);
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('packages/demo/src/A.php:2', $joined, 'New candidates must be reported with their live line number (derived at report time, never stored).');
        $this->assertStringContainsString('--write-roster', $joined, 'The failure output must name the repair command.');
    }

    #[Test]
    public function diff_names_recorded_entries_that_no_longer_exist(): void
    {
        $recorded = [[
            'path' => 'packages/demo/src/Gone.php',
            'pattern' => 'needle',
            'class' => 'demo-class',
            'match_sha256' => str_repeat('a', 64),
            'occurrence' => 1,
        ]];

        $lines = s1RosterDiff($recorded, [], '--write-roster');

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('packages/demo/src/Gone.php', $joined);
    }
}
