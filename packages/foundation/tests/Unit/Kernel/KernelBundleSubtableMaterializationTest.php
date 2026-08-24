<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

/**
 * End-to-end assertion that kernel-registered definitions are materialized
 * only by the explicit coordinated schema-sync boundary.
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
    new \Waaseyaa\Entity\EntityType(
        id: 'kernel_test_child',
        label: 'Child',
        class: \stdClass::class,
        keys: ['id' => 'cid', 'uuid' => 'uuid', 'bundle' => 'parent_id', 'label' => 'name'],
        _foreignKeys: [[
            'name' => 'kernel_test_child_parent_fk',
            'columns' => ['parent_id'],
            'table' => 'kernel_test_parent',
            'references' => ['parent_id'],
            'options' => ['onDelete' => 'RESTRICT'],
        ]],
    ),
    new \Waaseyaa\Entity\EntityType(
        id: 'kernel_test_parent',
        label: 'Parent',
        class: \stdClass::class,
        keys: ['id' => 'parent_id', 'label' => 'name'],
    ),
];
PHP,
        );
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        (new Filesystem())->remove($this->projectRoot);
    }

    #[Test]
    public function registered_bundle_fields_materialize_only_during_explicit_schema_sync(): void
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

        new EntitySchemaSync(
            $kernel->getDatabase(),
            $etm->getFieldRegistry(),
        )->syncAll($etm->getDefinitions());

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
        new EntitySchemaSync(
            $kernel->getDatabase(),
            $etm->getFieldRegistry(),
        )->syncAll($etm->getDefinitions());
        $etm->getRepository('kernel_test_widget');

        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $connection = $database->getConnection();

        $unwantedSubtables = $connection->fetchAllAssociative(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name GLOB 'kernel_test_widget__*'",
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

    #[Test]
    public function explicit_schema_sync_retries_deferred_foreign_keys(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();

        $manager = $kernel->getEntityTypeManager();
        new EntitySchemaSync(
            $kernel->getDatabase(),
            $manager->getFieldRegistry(),
        )->syncAll($manager->getDefinitions());
        $manager->getRepository('kernel_test_child');
        $manager->getRepository('kernel_test_parent');

        $database = $kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $foreignKeys = $database->getConnection()->fetchAllAssociative('PRAGMA foreign_key_list(kernel_test_child)');

        self::assertContains('kernel_test_parent', array_column($foreignKeys, 'table'));
    }
}
