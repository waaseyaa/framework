<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ai-schema is a standalone utility, not an ai-agent runtime dependency.
 */
#[CoversNothing]
final class AiAgentDependencyBoundaryTest extends TestCase
{
    #[Test]
    public function ai_agent_does_not_install_the_standalone_schema_utility(): void
    {
        $root = dirname(__DIR__, 2);
        $agent = self::json($root . '/packages/ai-agent/composer.json');

        self::assertArrayNotHasKey('waaseyaa/ai-schema', $agent['require'] ?? []);
        self::assertNotContains(
            '../ai-schema',
            array_column($agent['repositories'] ?? [], 'url'),
            'An unused path repository preserves the same false ownership edge as require.',
        );

        $lock = self::json($root . '/composer.lock');
        $lockedAgent = null;
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (($package['name'] ?? null) === 'waaseyaa/ai-agent') {
                $lockedAgent = $package;
                break;
            }
        }

        self::assertIsArray($lockedAgent, 'Root lock must contain the ai-agent path package.');
        self::assertArrayNotHasKey('waaseyaa/ai-schema', $lockedAgent['require'] ?? []);
        $manifestRequires = $agent['require'] ?? [];
        $lockedRequires = $lockedAgent['require'] ?? [];
        ksort($manifestRequires);
        ksort($lockedRequires);
        self::assertSame(
            $manifestRequires,
            $lockedRequires,
            'The isolated package manifest and generated root lock metadata must remain identical.',
        );
    }

    #[Test]
    public function ai_schema_remains_an_independently_shipped_public_utility(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertArrayHasKey(
            'waaseyaa/ai-schema',
            self::json($root . '/composer.json')['require'] ?? [],
        );
        self::assertArrayHasKey(
            'waaseyaa/ai-schema',
            self::json($root . '/packages/full/composer.json')['require'] ?? [],
        );
        self::assertSame(
            'waaseyaa/ai-schema',
            self::json($root . '/packages/ai-schema/composer.json')['name'] ?? null,
        );
    }

    #[Test]
    public function no_other_package_source_or_tests_use_ai_schema_symbols(): void
    {
        $packages = dirname(__DIR__, 2) . '/packages';
        $matches = [];

        foreach (glob($packages . '/*', GLOB_ONLYDIR) ?: [] as $package) {
            if (basename($package) === 'ai-schema') {
                continue;
            }
            foreach (['src', 'tests'] as $tree) {
                $path = $package . '/' . $tree;
                if (!is_dir($path)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $source = (string) file_get_contents($file->getPathname());
                    if (
                        str_contains($source, 'Waaseyaa\\AI\\Schema\\')
                        || str_contains($source, 'Waaseyaa\\\\AI\\\\Schema\\\\')
                        || str_contains($source, 'EntityJsonSchemaGenerator')
                    ) {
                        $matches[] = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
                    }
                }
            }
        }

        self::assertSame([], $matches, 'Any future ai-schema integration must restore a deliberate, tested dependency edge.');
    }

    #[Test]
    public function specs_do_not_advertise_retired_ai_schema_runtime_surfaces(): void
    {
        $root = dirname(__DIR__, 2);
        $integration = (string) file_get_contents($root . '/docs/specs/ai-integration.md');
        $schema = (string) file_get_contents($root . '/docs/specs/ai-schema.md');

        self::assertStringNotContainsString('packages/ai-schema/src/Mcp/', $integration);
        self::assertStringNotContainsString('packages/ai-schema/src/SchemaRegistry.php', $integration);
        self::assertStringContainsString('There is no current runtime consumer.', $schema);
    }

    /** @return array<string, mixed> */
    private static function json(string $path): array
    {
        self::assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
