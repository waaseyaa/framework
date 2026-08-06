<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Protects package suites that CI proves runnable outside the root autoloader. */
#[CoversNothing]
final class SplitPackageTestDependencyBoundaryTest extends TestCase
{
    private const array ISOLATED_PACKAGES = ['access'];

    #[Test]
    public function isolated_package_tests_use_only_declared_sibling_packages(): void
    {
        $root = dirname(__DIR__, 2);
        $namespaceOwners = self::namespaceOwners($root . '/packages');

        foreach (self::ISOLATED_PACKAGES as $package) {
            $manifest = self::manifest($root, $package);
            $declared = array_fill_keys(array_merge(
                array_keys($manifest['require'] ?? []),
                array_keys($manifest['require-dev'] ?? []),
            ), true);
            $violations = [];

            foreach (self::phpFiles($root . '/packages/' . $package . '/tests') as $file) {
                $source = (string) file_get_contents($file);
                preg_match_all('/^\s*use\s+(Waaseyaa\\\\[^;]+);/m', $source, $matches);
                foreach ($matches[1] as $import) {
                    $owner = self::ownerOf($import, $namespaceOwners);
                    if ($owner === null || $owner === $package) {
                        continue;
                    }

                    $relative = str_replace($root . '/', '', $file);
                    if (str_contains($import, '\\Tests\\')) {
                        $violations[] = "{$relative}: {$import} consumes another package's autoload-dev namespace";
                        continue;
                    }

                    if (!isset($declared['waaseyaa/' . $owner])) {
                        $violations[] = "{$relative}: {$import} requires undeclared waaseyaa/{$owner}";
                    }
                }
            }

            self::assertSame([], $violations, implode("\n", $violations));
        }
    }

    #[Test]
    public function every_isolated_package_suite_is_exercised_by_ci(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        foreach (self::ISOLATED_PACKAGES as $package) {
            self::assertStringContainsString(
                'bash bin/test-isolated-package ' . $package,
                $workflow,
                "The {$package} split-package suite must remain an executable CI contract.",
            );
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(string $root, string $package): array
    {
        return json_decode(
            (string) file_get_contents($root . '/packages/' . $package . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, string> namespace prefix => package */
    private static function namespaceOwners(string $packagesDirectory): array
    {
        $owners = [];
        foreach (glob($packagesDirectory . '/*/composer.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (preg_match('#^waaseyaa/([a-z0-9-]+)$#', (string) ($manifest['name'] ?? ''), $match) !== 1) {
                continue;
            }
            foreach (array_keys($manifest['autoload']['psr-4'] ?? []) as $prefix) {
                $owners[rtrim((string) $prefix, '\\')] = $match[1];
            }
        }
        uksort($owners, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $owners;
    }

    /** @param array<string, string> $namespaceOwners */
    private static function ownerOf(string $class, array $namespaceOwners): ?string
    {
        foreach ($namespaceOwners as $prefix => $owner) {
            if ($class === $prefix || str_starts_with($class, $prefix . '\\')) {
                return $owner;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
