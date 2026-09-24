<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the root `bin/` partition in docs/specs/native-host-support.md
 * complete (#2679).
 *
 * The contract promises that every Git-tracked top-level file under `bin/`
 * appears exactly once in its partition, with a stated total and a count per
 * row. Nothing checked that promise, and the partition drifted from 93 to 103
 * files unnoticed. This guard compares the partition with the tracked files,
 * enumerated through the repository's read-only Git helper, `repositoryGit()`.
 * The `bin/lib/` directory holds helpers, not entrypoints, and is not an
 * entry.
 */
#[CoversNothing]
final class NativeHostBinInventoryTest extends TestCase
{
    private const SPEC = 'docs/specs/native-host-support.md';
    private const SECTION = '### Root `bin/` commands';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/bin/lib/repository-files.php';
    }

    #[Test]
    public function every_tracked_top_level_bin_file_appears_exactly_once(): void
    {
        $tracked = $this->trackedTopLevelBinFiles();
        $listed = array_merge(...array_column($this->partitionRows(), 'entries'));

        $duplicates = array_keys(array_filter(array_count_values($listed), static fn(int $count): bool => $count > 1));
        sort($duplicates);
        $missing = array_values(array_diff($tracked, $listed));
        $unknown = array_values(array_unique(array_diff($listed, $tracked)));
        sort($unknown);

        self::assertSame([], $missing, 'Tracked bin/ files missing from the ' . self::SPEC . ' partition.');
        self::assertSame([], $duplicates, 'bin/ files listed more than once in the partition.');
        self::assertSame([], $unknown, 'Partition entries that are not Git-tracked top-level bin/ files.');
    }

    #[Test]
    public function the_stated_total_and_row_counts_match_the_entries(): void
    {
        $section = $this->section();
        self::assertSame(
            1,
            preg_match('/`bin\/` contains (\d+) Git-tracked top-level (?:files|entries)/', $section, $matches),
            'The partition must state its total.',
        );
        self::assertSame(count($this->trackedTopLevelBinFiles()), (int) $matches[1], 'The stated total must equal the tracked top-level bin/ files.');

        foreach ($this->partitionRows() as $row) {
            self::assertSame($row['declared'], count($row['entries']), 'Row count for: ' . $row['disposition']);
        }
    }

    /** @return list<string> */
    private function trackedTopLevelBinFiles(): array
    {
        [$exitCode, $stdout, $stderr] = repositoryGit($this->root, ['ls-files', '-z', '--', 'bin']);
        self::assertSame(0, $exitCode, $stderr);

        $files = [];
        foreach (explode("\0", $stdout) as $path) {
            if (preg_match('#^bin/([^/]+)$#', $path, $matches) === 1) {
                $files[] = $matches[1];
            }
        }
        sort($files);
        self::assertNotSame([], $files, 'git ls-files returned no top-level bin/ files.');

        return $files;
    }

    /** @return list<array{disposition: string, declared: int, entries: list<string>}> */
    private function partitionRows(): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $this->section()) ?: [] as $line) {
            if (!str_starts_with($line, '|') || str_starts_with($line, '|---') || str_starts_with($line, '| Current disposition')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            self::assertGreaterThanOrEqual(2, count($cells), 'Malformed partition row: ' . $line);
            self::assertSame(1, preg_match('/\((\d+)\s[^()]*\)\s*$/', $cells[0], $count), 'A row must state its entry count: ' . $cells[0]);
            preg_match_all('/`([^`]+)`/', $cells[1], $names);
            $rows[] = ['disposition' => $cells[0], 'declared' => (int) $count[1], 'entries' => $names[1]];
        }
        self::assertNotSame([], $rows, 'The partition table was not found.');

        return $rows;
    }

    private function section(): string
    {
        $spec = (string) file_get_contents($this->root . '/' . self::SPEC);
        $start = strpos($spec, self::SECTION);
        self::assertNotFalse($start, self::SPEC . ' must keep its "' . self::SECTION . '" section.');
        $end = strpos($spec, "\n### ", $start + strlen(self::SECTION));

        return substr($spec, $start, $end === false ? null : $end - $start);
    }
}
