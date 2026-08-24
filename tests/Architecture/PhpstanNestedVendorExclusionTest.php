<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the nested-vendor exclusion added for #2128.
 *
 * `phpstan.neon` analyses the whole `packages` root. A package-local
 * `composer install` leaves a gitignored `packages/<pkg>/vendor/` — sometimes
 * doubly nested, e.g. `packages/genealogy/vendor/waaseyaa/foundation/vendor/…`
 * — whose own transitive dependencies are absent. Analysing it aborts the run
 * with non-ignorable "class not found" errors, breaking local `composer phpstan`
 * and `bin/check-dead-code`. CI never reproduces it because a clean checkout has
 * no such tree, so only this test stands between the repo and a silent
 * regression if the exclusion is dropped.
 *
 * The matching here mirrors PHPStan's own `FileExcluder`, which uses plain
 * `fnmatch()` with flags `0` on POSIX (no `FNM_PATHNAME`, so `*` crosses
 * directory separators) after normalising both pattern and file to absolute
 * paths.
 */
#[CoversNothing]
final class PhpstanNestedVendorExclusionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * The exact shape from the #2128 report: a vendor tree inside a vendor tree.
     */
    private const DOUBLY_NESTED = 'packages/genealogy/vendor/waaseyaa/foundation/vendor/symfony/routing/Loader/AttributeClassLoader.php';

    #[Test]
    public function phpstan_excludes_a_doubly_nested_vendor_tree_under_packages(): void
    {
        $patterns = $this->analyseAndScanExcludes();

        self::assertNotSame([], $patterns, 'phpstan.neon has no excludePaths.analyseAndScan block.');

        self::assertTrue(
            $this->isExcluded(self::DOUBLY_NESTED, $patterns),
            sprintf(
                "No excludePaths.analyseAndScan pattern in phpstan.neon matches\n  %s\n"
                . "Add a nested-vendor pattern (see #2128) — without it a stray package-local\n"
                . "composer install breaks local phpstan and bin/check-dead-code.\nCurrent patterns: %s",
                self::DOUBLY_NESTED,
                implode(', ', $patterns),
            ),
        );
    }

    #[Test]
    public function phpstan_excludes_a_singly_nested_vendor_tree_under_packages(): void
    {
        $patterns = $this->analyseAndScanExcludes();

        self::assertTrue(
            $this->isExcluded('packages/genealogy/vendor/symfony/routing/Router.php', $patterns),
            'A package-local vendor/ tree must be excluded from PHPStan analysis.',
        );
    }

    #[Test]
    public function the_exclusion_does_not_swallow_package_source(): void
    {
        $patterns = $this->analyseAndScanExcludes();

        foreach ([
            'packages/genealogy/src/Entity/GenealogyPerson.php',
            'packages/entity/src/EntityBase.php',
        ] as $source) {
            self::assertFalse(
                $this->isExcluded($source, $patterns),
                sprintf('%s must stay in PHPStan analysis scope.', $source),
            );
        }
    }

    #[Test]
    public function the_dead_code_config_inherits_the_exclusion_from_phpstan_neon(): void
    {
        $deadCode = $this->read('phpstan-dead-code.neon');

        self::assertMatchesRegularExpression(
            '~^includes:\s*\n\s*-\s*phpstan\.neon\s*$~m',
            $deadCode,
            'phpstan-dead-code.neon must include phpstan.neon so it inherits excludePaths.',
        );

        self::assertStringNotContainsString(
            'excludePaths',
            $deadCode,
            'phpstan-dead-code.neon must not redeclare excludePaths — it inherits them from phpstan.neon.',
        );
    }

    /**
     * @return list<string> Patterns listed under `excludePaths: analyseAndScan:` in phpstan.neon.
     */
    private function analyseAndScanExcludes(): array
    {
        $patterns = [];
        $inBlock = false;

        foreach (explode("\n", $this->read('phpstan.neon')) as $line) {
            if (preg_match('~^\s*analyseAndScan:\s*$~', $line) === 1) {
                $inBlock = true;
                continue;
            }

            if (!$inBlock) {
                continue;
            }

            if (preg_match('~^\s*-\s*(\S+)\s*$~', $line, $matches) === 1) {
                $patterns[] = $matches[1];
                continue;
            }

            // Blank lines and comments stay inside the block; anything else ends it.
            if (trim($line) !== '' && !str_starts_with(ltrim($line), '#')) {
                break;
            }
        }

        return $patterns;
    }

    /**
     * @param list<string> $patterns
     */
    private function isExcluded(string $relativeFile, array $patterns): bool
    {
        $root = realpath(self::ROOT);
        self::assertIsString($root);

        $file = $root . '/' . $relativeFile;

        foreach ($patterns as $pattern) {
            if (fnmatch($root . '/' . $pattern, $file, 0)) {
                return true;
            }
        }

        return false;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $relativePath);
        self::assertIsString($contents, $relativePath . ' is unreadable.');

        return $contents;
    }
}
