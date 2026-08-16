<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for bin/lib/s1-roster.php — the shared roster scanner whose
 * schema-v2 identity binds semantic content (path, pattern, class, normalized
 * match hash, occurrence index), never line numbers or whole-file hashes
 * (#2400 item 6, docs/specs/governed-gates.md §6).
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
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /** @return array<string, mixed> */
    private function scan(): array
    {
        return s1RosterScan(
            $this->tempDir,
            ['packages'],
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
    public function non_repository_content_is_never_scanned(): void
    {
        foreach ([
            '.git/hooks',
            'vendor/lib',
            'packages/demo/vendor/dep',
            'packages/demo/node_modules/dep',
            'storage/cache',
            'tmp/scratch',
        ] as $dir) {
            mkdir($this->tempDir . '/' . $dir, 0o755, true);
            file_put_contents($this->tempDir . '/' . $dir . '/Poison.php', "<?php\nGovernedNeedle::run();\n");
        }

        $entries = s1RosterScan(
            $this->tempDir,
            [''],
            ['needle' => '/\bGovernedNeedle\b/'],
            static fn(string $relative, string $contents): bool => str_ends_with($relative, '.php'),
            static fn(string $relative, string $patternId): string => 'demo-class',
        );

        $this->assertSame([], $entries, 'Excluded trees (.git, vendor at any depth, node_modules, storage, tmp) must not contribute candidates.');
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
