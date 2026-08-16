<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The drift detector is only a trustworthy coupling gate if its mapping table
 * is complete: every production package must couple to a contract document,
 * and every mapped document must exist. Both assertions parse the mapping data
 * out of tools/drift-detector.sh itself so the script stays the single source
 * of truth — this test carries no duplicate mapping table.
 */
#[CoversNothing]
final class DriftDetectorMappingCompletenessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_production_package_is_mapped_to_a_contract_document(): void
    {
        $primary = $this->parsePrimaryPatternTable();
        self::assertGreaterThan(50, count($primary), 'PATTERN_TO_SPEC parse produced implausibly few entries — the parser is broken, not the table.');

        $uncovered = [];
        foreach ($this->productionPackageDirs() as $package) {
            $prefix = 'packages/' . $package . '/';
            $covered = false;
            foreach (array_keys($primary) as $pattern) {
                if (str_starts_with($prefix, $pattern)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $uncovered[] = $package;
            }
        }

        self::assertSame(
            [],
            $uncovered,
            'Production packages with src/ or app/ but no PATTERN_TO_SPEC entry in tools/drift-detector.sh (their contract changes are not coupling-checked): ' . implode(', ', $uncovered),
        );
    }

    #[Test]
    public function every_mapped_contract_document_exists(): void
    {
        $documents = array_values($this->parsePrimaryPatternTable());
        foreach ($this->parseSecondaryRecordTargets() as $target) {
            $documents[] = $target;
        }
        self::assertNotSame([], $documents);

        $missing = [];
        foreach (array_unique($documents) as $document) {
            if (!is_file($this->root . '/' . $document)) {
                $missing[] = $document;
            }
        }

        self::assertSame(
            [],
            $missing,
            'tools/drift-detector.sh maps source to contract documents that do not exist: ' . implode(', ', $missing),
        );
    }

    /** @return list<string> package directory basenames having src/ or app/ */
    private function productionPackageDirs(): array
    {
        $packages = [];
        foreach (glob($this->root . '/packages/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir($dir . '/src') || is_dir($dir . '/app')) {
                $packages[] = basename($dir);
            }
        }
        self::assertNotSame([], $packages);

        return $packages;
    }

    /** @return array<string, string> pattern prefix => contract document */
    private function parsePrimaryPatternTable(): array
    {
        $script = $this->detectorSource();
        $tableStart = strpos($script, 'declare -A PATTERN_TO_SPEC=(');
        self::assertIsInt($tableStart, 'PATTERN_TO_SPEC table not found in tools/drift-detector.sh');
        $tableEnd = strpos($script, "\n)", $tableStart);
        self::assertIsInt($tableEnd);
        $table = substr($script, $tableStart, $tableEnd - $tableStart);

        preg_match_all('/^\s*\["([^"]+)"\]="([^"]+)"\s*$/m', $table, $matches, PREG_SET_ORDER);
        $primary = [];
        foreach ($matches as $match) {
            $primary[$match[1]] = $match[2];
        }

        return $primary;
    }

    /** @return list<string> literal record_spec targets from the secondary mapping rules */
    private function parseSecondaryRecordTargets(): array
    {
        preg_match_all('/record_spec "([^"$]+)"/', $this->detectorSource(), $matches);

        return $matches[1];
    }

    private function detectorSource(): string
    {
        return (string) file_get_contents($this->root . '/tools/drift-detector.sh');
    }
}
