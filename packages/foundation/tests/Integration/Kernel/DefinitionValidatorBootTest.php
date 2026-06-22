<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Integration\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendInterface;
use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsInterface;
use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderInterface;
use Waaseyaa\EntityStorage\Exception\UnsupportedQueryException;
use Waaseyaa\EntityStorage\Query\DefinitionValidator;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

/**
 * Integration tests verifying that FR-021's fail-fast contract is enforced
 * at kernel boot time, not at first query execution.
 *
 * These tests exercise the FULL kernel boot path: compileManifest() →
 * discoverAndRegisterProviders() → … → validateQueryDefinitions() must run
 * before $this->booted = true, so a broken entity-type declaration prevents
 * the kernel from ever reaching a serving state.
 *
 * @see AbstractKernel::validateQueryDefinitions()
 * @see DefinitionValidator::validateAll()
 */
#[CoversNothing]
final class DefinitionValidatorBootTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createMinimalProjectRoot();

        // Reset the static backend slot between tests.
        BootTestBackendRegistry::reset();
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    // -------------------------------------------------------------------------
    // Happy path: kernel boots when all indexed fields have supporting backends
    // -------------------------------------------------------------------------

    #[Test]
    public function kernel_boots_when_indexed_field_backend_supports_queries(): void
    {
        BootTestBackendRegistry::register(new BootTestBackend(backendId: 'test-column', querySupported: true));

        $kernel = $this->buildKernel(
            entityTypeConfig: $this->indexedFieldEntityTypeConfig(backendId: 'test-column'),
        );

        // Should not throw — backend supports querying.
        $kernel->publicBoot();

        $this->assertNotNull($kernel->getEntityTypeManager());
    }

    // -------------------------------------------------------------------------
    // Boot-failure path: kernel boot throws when a backend rejects declared query
    // -------------------------------------------------------------------------

    #[Test]
    public function kernel_boot_throws_when_indexed_field_backend_rejects_queries(): void
    {
        BootTestBackendRegistry::register(new BootTestBackend(backendId: 'test-blob', querySupported: false));

        $kernel = $this->buildKernel(
            entityTypeConfig: $this->indexedFieldEntityTypeConfig(backendId: 'test-blob'),
        );

        $this->expectException(UnsupportedQueryException::class);
        $kernel->publicBoot();
    }

    // -------------------------------------------------------------------------
    // Non-indexed fields do not trigger boot failure even on rejecting backends
    // -------------------------------------------------------------------------

    #[Test]
    public function kernel_boots_when_non_indexed_field_backend_rejects_queries(): void
    {
        BootTestBackendRegistry::register(new BootTestBackend(backendId: 'test-blob', querySupported: false));

        $kernel = $this->buildKernel(
            entityTypeConfig: $this->nonIndexedFieldEntityTypeConfig(backendId: 'test-blob'),
        );

        // Non-indexed field → no query-need probe → boot succeeds.
        $kernel->publicBoot();

        $this->assertNotNull($kernel->getEntityTypeManager());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildKernel(string $entityTypeConfig): object
    {
        $projectRoot = $this->projectRoot;

        file_put_contents(
            $projectRoot . '/config/entity-types.php',
            $entityTypeConfig,
        );

        return new class($projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            /**
             * Override manifest compilation to inject the test backend provider
             * while still running the real boot path for everything else.
             */
            protected function compileManifest(): void
            {
                parent::compileManifest();

                // Append the test backend provider to the manifest's provider list
                // so BackendRegistrarFactory discovers it during validateQueryDefinitions().
                $this->manifest = new PackageManifest(
                    providers: array_merge(
                        $this->manifest->providers,
                        [BootTestBackendProvider::class],
                    ),
                    migrations: $this->manifest->migrations,
                    fieldTypes: $this->manifest->fieldTypes,
                    formatters: $this->manifest->formatters,
                    middleware: $this->manifest->middleware,
                    permissions: $this->manifest->permissions,
                    policies: $this->manifest->policies,
                    packageDeclarations: $this->manifest->packageDeclarations,
                    attributeEntityTypes: $this->manifest->attributeEntityTypes,
                    consoleCommandProviders: $this->manifest->consoleCommandProviders,
                );
            }
        };
    }

    private function createMinimalProjectRoot(): string
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_dvboot_test_' . uniqid();
        mkdir($projectRoot . '/config', 0755, true);
        mkdir($projectRoot . '/storage', 0755, true);

        file_put_contents(
            $projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:'];",
        );

        // entity-types.php is written per test via buildKernel().
        file_put_contents(
            $projectRoot . '/config/entity-types.php',
            "<?php return [];",
        );

        return $projectRoot;
    }

    private function indexedFieldEntityTypeConfig(string $backendId): string
    {
        return <<<PHP
<?php
return [
    new \Waaseyaa\Entity\EntityType(
        id: 'article',
        label: 'Article',
        class: \stdClass::class,
        _fieldDefinitions: [
            'status' => (new \Waaseyaa\Field\FieldDefinition(
                name: 'status',
                type: 'string',
                targetEntityTypeId: 'article',
            ))->storedIn('{$backendId}')->indexed(),
        ],
    ),
];
PHP;
    }

    private function nonIndexedFieldEntityTypeConfig(string $backendId): string
    {
        return <<<PHP
<?php
return [
    new \Waaseyaa\Entity\EntityType(
        id: 'article',
        label: 'Article',
        class: \stdClass::class,
        _fieldDefinitions: [
            'body' => (new \Waaseyaa\Field\FieldDefinition(
                name: 'body',
                type: 'text',
                targetEntityTypeId: 'article',
            ))->storedIn('{$backendId}'),
        ],
    ),
];
PHP;
    }
}

// ---------------------------------------------------------------------------
// Test-only fixture classes — defined here (not under src/) for autoload-dev.
// ---------------------------------------------------------------------------

/**
 * Static slot so BackendRegistrar can instantiate BootTestBackendProvider
 * with no args, while the test controls which backend is returned.
 */
final class BootTestBackendRegistry
{
    private static ?FieldStorageBackendInterface $backend = null;

    public static function register(FieldStorageBackendInterface $backend): void
    {
        self::$backend = $backend;
    }

    public static function get(): FieldStorageBackendInterface
    {
        if (self::$backend === null) {
            throw new \LogicException('BootTestBackendRegistry: no backend registered.');
        }

        return self::$backend;
    }

    public static function reset(): void
    {
        self::$backend = null;
    }
}

/**
 * Framework-owned provider that fetches its backend from the static registry.
 * Must be no-arg-constructable (BackendRegistrar::build() requirement).
 */
final class BootTestBackendProvider implements IsFrameworkBackendProviderInterface
{
    public function fieldStorageBackends(): array
    {
        return [BootTestBackendRegistry::get()];
    }
}

/**
 * Minimal backend implementation for kernel boot validation tests.
 */
final class BootTestBackend implements FieldStorageBackendInterface
{
    public function __construct(
        private readonly string $backendId,
        private readonly bool $querySupported,
    ) {}

    public function id(): string
    {
        return $this->backendId;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return null;
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void {}

    public function delete(EntityInterface $entity): void {}

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return $this->querySupported;
    }
}
