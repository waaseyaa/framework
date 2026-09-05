<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\SurfaceMap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tooling\SurfaceDeclarations;
use Waaseyaa\Tooling\SurfaceScanner;

require_once __DIR__ . '/../../../tools/lib/SurfaceScanner.php';
require_once __DIR__ . '/../../../tools/lib/SurfaceDeclarations.php';

/**
 * Reads the COMPOSED package-local declarations (FW-DELIVERY-SURFACE-01 /
 * #2901, docs/specs/public-surface-declarations.md), not the generated
 * docs/public-surface-map.php aggregate — the declarations are the single
 * editable authority, and the aggregate may legitimately lag behind them
 * between releases (§6).
 */
#[CoversNothing]
final class PublicSurfaceVerificationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    #[Test]
    public function every_public_element_has_a_disposition(): void
    {
        $surfaceMap = $this->composedMap();
        $discoveredElements = $this->discoverPublicElements();

        $unmapped = [];
        foreach ($discoveredElements as $fqn) {
            if (!isset($surfaceMap[$fqn])) {
                $unmapped[] = $fqn;
            }
        }

        self::assertSame(
            [],
            $unmapped,
            sprintf(
                "%d public API element(s) have no disposition in any packages/<pkg>/public-surface.php declaration:\n%s",
                count($unmapped),
                implode("\n", $unmapped),
            ),
        );
    }

    #[Test]
    public function surface_map_contains_no_stale_entries(): void
    {
        $surfaceMap = $this->composedMap();
        $discoveredElements = $this->discoverPublicElements();

        $stale = [];
        foreach (array_keys($surfaceMap) as $fqn) {
            $exists = class_exists($fqn)
                || interface_exists($fqn)
                || trait_exists($fqn)
                || enum_exists($fqn);
            if (!$exists) {
                $stale[] = $fqn;
            }
        }

        self::assertSame(
            [],
            $stale,
            sprintf(
                "%d declared entry(ies) reference elements that no longer exist:\n%s",
                count($stale),
                implode("\n", $stale),
            ),
        );
    }

    #[Test]
    public function no_public_element_lacks_internal_annotation_unless_mapped_public(): void
    {
        $surfaceMap = $this->composedMap();
        $publicElements = array_keys(array_filter($surfaceMap, fn(string $disposition) => $disposition === 'public'));

        $discoveredElements = $this->discoverPublicElements();
        $missingAnnotation = [];

        foreach ($discoveredElements as $fqn) {
            if (in_array($fqn, $publicElements, true)) {
                continue;
            }
            $disposition = $surfaceMap[$fqn] ?? null;
            if ($disposition !== null && $disposition !== 'public') {
                $rc = new \ReflectionClass($fqn);
                $doc = $rc->getDocComment();
                if ($doc === false || !str_contains($doc, '@internal')) {
                    $missingAnnotation[] = $fqn;
                }
            }
        }

        self::assertSame(
            [],
            $missingAnnotation,
            sprintf(
                "%d non-public element(s) lack @internal annotation:\n%s",
                count($missingAnnotation),
                implode("\n", $missingAnnotation),
            ),
        );
    }

    /**
     * Replaces the old source-regex "duplicate key" test (which caught a
     * hand-edited aggregate re-listing one FQCN, silently collapsed by
     * `require`). The declaration plane's duplicate check is stronger: it
     * fails on a duplicate WITHIN one package file (§4 duplicate) and on the
     * SAME FQCN declared by two different packages (§4 contradictory) — both
     * are exactly the "one FQCN listed more than once" failure mode, now
     * caught at the editable source instead of the generated aggregate.
     */
    #[Test]
    public function declarations_contain_no_duplicate_or_contradictory_fqcn(): void
    {
        $declarations = SurfaceDeclarations::load(self::ROOT);
        $scanner = SurfaceScanner::scan(self::ROOT);
        $errors = array_values(array_filter(
            $declarations->validate($scanner),
            static fn(string $error): bool => str_starts_with($error, 'duplicate:') || str_starts_with($error, 'contradictory:'),
        ));

        self::assertSame(
            [],
            $errors,
            "Duplicate or contradictory public-surface declaration(s):\n" . implode("\n\n", $errors),
        );
    }

    /** @return array<string, string> */
    private function composedMap(): array
    {
        return SurfaceDeclarations::load(self::ROOT)->compose();
    }

    /**
     * @return list<class-string>
     */
    private function discoverPublicElements(): array
    {
        $packagesDir = self::ROOT . '/packages';
        $elements = [];

        foreach (new \DirectoryIterator($packagesDir) as $package) {
            if ($package->isDot() || !$package->isDir()) {
                continue;
            }
            $srcDir = $package->getPathname() . '/src';
            if (!is_dir($srcDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if (preg_match('/^(interface|abstract class|trait|enum)\s+(\w+)/m', $content, $match)) {
                    if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
                        $fqn = $nsMatch[1] . '\\' . $match[2];
                        $elements[] = $fqn;
                    }
                }
            }
        }

        sort($elements);
        return $elements;
    }
}
