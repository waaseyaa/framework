<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpUnit13ConformanceTest extends TestCase
{
    private const EXPECTED_CONFIGURATION_COUNT = 20;

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_test_manifest_requires_the_supported_phpunit_major(): void
    {
        foreach ($this->trackedFiles('composer.json') as $path) {
            $manifest = json_decode(
                (string) file_get_contents($this->repoRoot . '/' . $path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (!isset($manifest['require-dev']['phpunit/phpunit'])) {
                continue;
            }

            self::assertSame(
                '^13.0',
                $manifest['require-dev']['phpunit/phpunit'],
                sprintf('%s must use the supported PHPUnit major.', $path),
            );
        }
    }

    #[Test]
    public function every_tracked_phpunit_configuration_uses_the_canonical_schema_and_cache(): void
    {
        $configurations = array_values(array_filter(
            $this->trackedFiles(),
            static fn(string $path): bool => preg_match('#(?:^|/)phpunit\.xml(?:\.dist)?$#', $path) === 1,
        ));

        self::assertCount(self::EXPECTED_CONFIGURATION_COUNT, $configurations);

        foreach ($configurations as $path) {
            $xml = simplexml_load_file($this->repoRoot . '/' . $path);
            self::assertNotFalse($xml, sprintf('%s must contain valid XML.', $path));

            $schema = (string) $xml->attributes('xsi', true)->noNamespaceSchemaLocation;
            self::assertMatchesRegularExpression(
                '#^(?:\.\./\.\./)?vendor/phpunit/phpunit/phpunit\.xsd$#',
                $schema,
                sprintf('%s must use the installed PHPUnit schema.', $path),
            );
            self::assertSame(
                '.phpunit.cache',
                (string) $xml['cacheDirectory'],
                sprintf('%s must isolate PHPUnit cache output.', $path),
            );
        }
    }

    #[Test]
    public function removed_docblock_execution_metadata_and_conflicting_coverage_attributes_are_absent(): void
    {
        foreach ($this->trackedFiles() as $path) {
            if (!str_ends_with($path, 'Test.php')) {
                continue;
            }

            $source = (string) file_get_contents($this->repoRoot . '/' . $path);
            foreach (['dataProvider', 'depends', 'requires ', 'testWith', 'test '] as $metadataName) {
                $removedMetadata = '@' . $metadataName;
                self::assertStringNotContainsString(
                    $removedMetadata,
                    $source,
                    sprintf('%s must use PHPUnit attributes for execution metadata.', $path),
                );
            }

            $classDeclarationPrefix = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_CLASS) {
                    break;
                }
                $classDeclarationPrefix .= is_array($token) ? $token[1] : $token;
            }

            self::assertFalse(
                str_contains($classDeclarationPrefix, '#[CoversNothing]')
                    && str_contains($classDeclarationPrefix, '#[CoversClass('),
                sprintf('%s cannot declare both CoversNothing and CoversClass.', $path),
            );
        }
    }

    #[Test]
    public function root_test_suites_do_not_discover_the_same_file_twice(): void
    {
        $xml = simplexml_load_file($this->repoRoot . '/phpunit.xml.dist');
        self::assertNotFalse($xml);

        $owners = [];
        foreach ($xml->testsuites->testsuite as $suite) {
            $suiteName = (string) $suite['name'];
            foreach ($suite->directory as $directory) {
                foreach (glob($this->repoRoot . '/' . (string) $directory, GLOB_ONLYDIR) ?: [] as $root) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    );
                    foreach ($iterator as $file) {
                        if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
                            continue;
                        }

                        $path = $file->getPathname();
                        self::assertArrayNotHasKey(
                            $path,
                            $owners,
                            sprintf('%s is discovered by both %s and %s.', $path, $owners[$path] ?? '', $suiteName),
                        );
                        $owners[$path] = $suiteName;
                    }
                }
            }

            foreach ($suite->file as $file) {
                $path = $this->repoRoot . '/' . (string) $file;
                self::assertArrayNotHasKey(
                    $path,
                    $owners,
                    sprintf('%s is discovered by multiple test suites.', $path),
                );
                $owners[$path] = $suiteName;
            }
        }

        self::assertNotEmpty($owners);
    }

    /** @return list<string> */
    private function trackedFiles(?string $basename = null): array
    {
        $command = escapeshellarg($this->repoRoot . '/bin/git')
            . ' -C '
            . escapeshellarg($this->repoRoot)
            . ' ls-files -z';
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, 'Unable to enumerate tracked files through the repository git guard.');

        $files = array_values(array_filter(explode("\0", implode("\n", $output))));
        if ($basename === null) {
            return $files;
        }

        return array_values(array_filter(
            $files,
            static fn(string $path): bool => basename($path) === $basename,
        ));
    }
}
