<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\SurfaceMap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PublicSurfaceVerificationTest extends TestCase
{
    private const SURFACE_MAP_PATH = __DIR__ . '/../../../docs/public-surface-map.php';

    #[Test]
    public function every_public_element_has_a_disposition(): void
    {
        $surfaceMap = require self::SURFACE_MAP_PATH;
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
                "%d public API element(s) have no disposition in surface map:\n%s",
                count($unmapped),
                implode("\n", $unmapped),
            ),
        );
    }

    #[Test]
    public function surface_map_contains_no_stale_entries(): void
    {
        $surfaceMap = require self::SURFACE_MAP_PATH;
        $discoveredElements = $this->discoverPublicElements();

        $stale = [];
        foreach (array_keys($surfaceMap) as $fqn) {
            if (!in_array($fqn, $discoveredElements, true)) {
                $stale[] = $fqn;
            }
        }

        self::assertSame(
            [],
            $stale,
            sprintf(
                "%d surface map entry(ies) reference elements that no longer exist:\n%s",
                count($stale),
                implode("\n", $stale),
            ),
        );
    }

    #[Test]
    public function no_public_element_lacks_internal_annotation_unless_mapped_public(): void
    {
        $surfaceMap = require self::SURFACE_MAP_PATH;
        $publicElements = array_keys(array_filter($surfaceMap, fn(string $disposition) => $disposition === 'public'));

        $discoveredElements = $this->discoverPublicElements();
        $missingAnnotation = [];

        foreach ($discoveredElements as $fqn) {
            if (in_array($fqn, $publicElements, true)) {
                continue;
            }
            $disposition = $surfaceMap[$fqn] ?? null;
            if ($disposition === 'internal' || $disposition === 'remove') {
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
                "%d element(s) marked 'internal' in surface map lack @internal annotation:\n%s",
                count($missingAnnotation),
                implode("\n", $missingAnnotation),
            ),
        );
    }

    /**
     * @return list<class-string>
     */
    private function discoverPublicElements(): array
    {
        $packagesDir = __DIR__ . '/../../../packages';
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
                if (preg_match('/^(interface|abstract class|trait)\s+(\w+)/m', $content, $match)) {
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
