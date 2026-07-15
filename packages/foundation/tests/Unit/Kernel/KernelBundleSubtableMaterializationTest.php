<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

/**
 * End-to-end assertion that an AbstractKernel-booted application
 * materializes `{base}__{bundle}` subtables for registered bundle fields.
 *
 * The bundle substrate has two wire-ups: a FieldDefinitionRegistry on the
 * EntityTypeManager (drives addBundleFields) and a SqlSchemaHandler that
 * enumerates registered bundles when ensureTable() runs. This test boots
 * the production kernel path (no hand-wired test harness), registers a
 * bundle field via the kernel's EntityTypeManager, triggers storage
 * resolution, and asserts the subtable physically exists in the database.
 *
 * Lives at this layer because the assertion is "kernel wiring delivers
 * end-to-end bundle substrate" — sibling to AbstractKernelTest /
 * HttpKernelTest, where other kernel-boot guarantees are codified.
 *
 * Deliberate mutation recipe (Phase 1 exit criterion 2). To prove the
 * assertion is load-bearing, temporarily edit
 * packages/entity-storage/src/SqlSchemaHandler.php::registeredBundlesFor()
 * so its null-bundleEnumerator branch returns `[]` instead of calling
 * `$this->fieldRegistry->bundleNamesFor($type->id())`. Re-run this
 * test: `registeredBundleFieldsMaterializeSubtableViaKernelPath` must
 * fail with "kernel_test_widget__gizmo must be materialized". This is
 * the alpha.148 shape; a unit-level companion
 * (SqlSchemaHandlerRegistryFallbackTest) pins the same branch so the
 * kernel-path test is not the sole guard.
 */
#[CoversNothing]
final class KernelBundleSubtableMaterializationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_kernel_bundle_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            <<<'PHP'
<?php
return [
    new \Waaseyaa\Entity\EntityType(
        id: 'kernel_test_widget',
        label: 'Widget',
        class: \stdClass::class,
        keys: ['id' => 'wid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name'],
        bundleEntityType: 'kernel_test_widget_type',
    ),
    new \Waaseyaa\Entity\EntityType(
        id: 'kernel_test_widget_type',
        label: 'Widget type',
        class: \stdClass::class,
        keys: ['id' => 'id', 'label' => 'label'],
    ),
];
PHP,
        );
    }

    protected function tearDown(): void
    {
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);

        if (!is_dir($this->projectRoot)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function registeredBundleFieldsMaterializeSubtableViaKernelPath(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();

        $etm = $kernel->getEntityTypeManager();
        $etm->addBundleFields('kernel_test_widget', 'gizmo', [
            'gizmo_code' => new FieldDefinition(
                name: 'gizmo_code',
                type: 'string',
                targetEntityTypeId: 'kernel_test_widget',
                targetBundle: 'gizmo',
            ),
            'gizmo_weight' => new FieldDefinition(
                name: 'gizmo_weight',
                type: 'integer',
                targetEntityTypeId: 'kernel_test_widget',
                targetBundle: 'gizmo',
            ),
        ]);

        $etm->getRepository('kernel_test_widget');

        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $connection = $database->getConnection();

        $subtableExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => 'kernel_test_widget__gizmo'],
        );
        self::assertSame(
            1,
            $subtableExists,
            'Kernel-path bundle subtable kernel_test_widget__gizmo must be materialized by ensureTable() when the registry is populated.',
        );

        $columns = $connection->fetchAllAssociative('PRAGMA table_info(kernel_test_widget__gizmo)');
        $columnNames = array_column($columns, 'name');

        self::assertContains('wid', $columnNames, 'Subtable must include the base PK column for FK linkage.');
        self::assertContains('gizmo_code', $columnNames);
        self::assertContains('gizmo_weight', $columnNames);
    }

    #[Test]
    public function emptyBundleRegistrationCreatesNoSubtable(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();

        $etm = $kernel->getEntityTypeManager();
        $etm->getRepository('kernel_test_widget');

        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $connection = $database->getConnection();

        $unwantedSubtables = $connection->fetchAllAssociative(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'kernel_test_widget__%'",
        );
        self::assertSame(
            [],
            $unwantedSubtables,
            'No subtables should be created for an entity type whose registered bundle set is empty.',
        );

        $baseExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'kernel_test_widget'",
        );
        self::assertSame(1, $baseExists, 'Base table must still be created independent of any bundle registration.');
    }
}
