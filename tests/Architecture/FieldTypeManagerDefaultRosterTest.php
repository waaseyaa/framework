<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `FieldTypeManager::default()` is the process-static, built-ins-only registry.
 * Since #2786 admission is fail-closed against the registry, every kernel-wired
 * schema consumer must receive the kernel's boot-scoped instance (built-ins plus
 * the package manifest's downstream plugins). The static default may survive
 * only for isolated construction — bare value objects and unit tests — and each
 * surviving call site must say so where the fallback is written.
 */
#[CoversNothing]
final class FieldTypeManagerDefaultRosterTest extends TestCase
{
    private const MARKER = 'Isolated construction';

    /**
     * Kernel-wired construction sites: they compose the boot-scoped registry
     * into the schema consumers and must never reach for the static default.
     */
    private const KERNEL_WIRING_ROSTER = [
        'packages/foundation/src/Kernel/AbstractKernel.php',
        'packages/foundation/src/Kernel/HttpKernel.php',
        'packages/foundation/src/Kernel/ConsoleKernel.php',
        'packages/foundation/src/Kernel/EntityTypeManagerFactory.php',
        'packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php',
        'packages/foundation/src/Kernel/Bootstrap/ProviderRegistryKernelServices.php',
        'packages/foundation/src/Http/Router/SchemaRouter.php',
        'packages/field/src/FieldServiceProvider.php',
        'packages/entity-storage/src/EntitySchemaSync.php',
        'packages/entity-storage/src/EntitySchemaSyncRunner.php',
        'packages/entity-storage/src/EntitySchemaTableMaterializer.php',
        'packages/entity-storage/src/Hydration/SqlColumnTranslationHydrator.php',
        'packages/graphql/src/GraphQlServiceProvider.php',
        'packages/graphql/src/Http/Router/GraphQlRouter.php',
        'packages/graphql/src/GraphQlEndpoint.php',
        'packages/graphql/src/Schema/SchemaFactory.php',
        'packages/admin-surface/src/AdminSurfaceServiceProvider.php',
        'packages/wayfinding/src/WayfindingServiceProvider.php',
        'packages/api/src/ApiServiceProvider.php',
        'packages/migration/src/ContentModel/ContentModelRegistrar.php',
        'packages/migration/src/ServiceProvider.php',
        'packages/cli/src/Handler/DbInitHandler.php',
        'packages/cli/src/Handler/SchemaSyncHandler.php',
        'packages/cli/src/Handler/RevisionsEnableHandler.php',
        'packages/cli/src/Provider/MigrateServiceProvider.php',
    ];

    /**
     * The closed roster of surviving static-default call sites, each with the
     * reason it is not kernel wiring. A new entry needs a design decision, not
     * a baseline edit: kernel-wired code receives the registry explicitly.
     */
    private const ISOLATED_CONSTRUCTION_ROSTER = [
        'packages/field/src/FieldDefinition.php' => 'bare value object; toJsonSchema() on a definition constructed outside any registry',
        'packages/field/src/FieldDefinitionRegistry.php' => 'registry constructed without a kernel (unit tests, bare bootstraps)',
        'packages/api/src/Schema/SchemaPresenter.php' => 'presenter constructed without a kernel (unit tests, consumer scripts)',
        'packages/ai-schema/src/EntityJsonSchemaGenerator.php' => 'generator constructed without a kernel; no first-party provider composes it',
        'packages/entity-storage/src/SqlSchemaHandler.php' => 'handler constructed without a registry (unit tests) and the static diff-spec deriver',
        'packages/entity-storage/src/Schema/TranslationSchemaHandler.php' => 'handler constructed without a registry (unit tests)',
        'packages/entity-storage/src/Schema/RevisionTableBuilder.php' => 'builder constructed without a registry (unit tests)',
        'packages/entity-storage/src/Backend/SqlColumnSchemaBuilder.php' => 'builder constructed without a registry (unit tests)',
        'packages/graphql/src/Schema/FieldTypeMapper.php' => 'leaf wire-type adapter constructed without a registry (unit tests)',
    ];

    #[Test]
    public function kernel_wiring_never_reaches_for_the_static_default(): void
    {
        $offenders = [];
        foreach ($this->kernelWiringFiles() as $relative) {
            if ($this->staticDefaultCallLines($relative) !== []) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Kernel-wired schema consumers must receive the boot-scoped FieldTypeManager, never FieldTypeManager::default().');
    }

    #[Test]
    public function every_surviving_static_default_call_site_is_a_justified_isolated_construction(): void
    {
        $found = [];
        foreach ($this->packageSourceFiles() as $relative) {
            $lines = $this->staticDefaultCallLines($relative);
            if ($lines !== []) {
                $found[$relative] = $lines;
            }
        }

        $expected = array_keys(self::ISOLATED_CONSTRUCTION_ROSTER);
        sort($expected);
        $actual = array_keys($found);
        sort($actual);
        self::assertSame(
            $expected,
            $actual,
            'FieldTypeManager::default() call sites drifted from the isolated-construction roster; thread the boot-scoped registry instead of extending the roster.',
        );

        foreach ($found as $relative => $lines) {
            $source = file($this->root() . '/' . $relative, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($source);
            foreach ($lines as $line) {
                $window = implode("\n", array_slice($source, max(0, $line - 5), 5));
                self::assertStringContainsString(
                    self::MARKER,
                    $window,
                    sprintf('%s:%d must justify its static default with an "%s" comment within the five preceding lines.', $relative, $line, self::MARKER),
                );
            }
        }
    }

    #[Test]
    public function the_rosters_only_name_files_that_exist(): void
    {
        foreach ([...self::KERNEL_WIRING_ROSTER, ...array_keys(self::ISOLATED_CONSTRUCTION_ROSTER)] as $relative) {
            self::assertFileExists($this->root() . '/' . $relative, "Stale roster entry: {$relative}");
        }
    }

    /** @return list<string> */
    private function kernelWiringFiles(): array
    {
        $files = self::KERNEL_WIRING_ROSTER;
        foreach (glob($this->root() . '/packages/*/src/Kernel', GLOB_ONLYDIR) ?: [] as $kernelDirectory) {
            foreach ($this->phpFilesUnder($kernelDirectory) as $file) {
                $files[] = substr($file, strlen($this->root()) + 1);
            }
        }

        return array_values(array_unique($files));
    }

    /** @return list<string> */
    private function packageSourceFiles(): array
    {
        $files = [];
        foreach (glob($this->root() . '/packages/*/src', GLOB_ONLYDIR) ?: [] as $sourceDirectory) {
            foreach ($this->phpFilesUnder($sourceDirectory) as $file) {
                $files[] = substr($file, strlen($this->root()) + 1);
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Lines carrying a `FieldTypeManager::default(` call, comments excluded.
     *
     * @return list<int>
     */
    private function staticDefaultCallLines(string $relative): array
    {
        $source = file_get_contents($this->root() . '/' . $relative);
        self::assertIsString($source);

        $lines = [];
        $previous = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $previous = [];
                continue;
            }
            [$id, $text, $line] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
                continue;
            }
            // `default` tokenizes as the T_DEFAULT keyword, not T_STRING.
            if (($id === T_STRING || $id === T_DEFAULT) && $text === 'default'
                && ($previous['id'] ?? null) === T_DOUBLE_COLON
                && str_ends_with((string) ($previous['owner'] ?? ''), 'FieldTypeManager')
            ) {
                $lines[] = $line;
            }
            $previous = [
                'id' => $id,
                'owner' => $id === T_DOUBLE_COLON ? ($previous['text'] ?? '') : null,
                'text' => $text,
            ];
        }

        return $lines;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
