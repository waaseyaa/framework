<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

/**
 * Regression tests for #1309 — column-vs-_data storage drift detection.
 *
 * A field registered with FieldStorage::Data must NOT have a backing column
 * on disk. When it does, new writes route to the _data JSON blob while old
 * data lingers in the column — the column wins for column-existence-based
 * reads until query routing becomes symmetric (mission #1257 WP04 / WP05),
 * and stays silently stale afterward.
 */
#[CoversClass(HealthChecker::class)]
final class HealthCheckerColumnDataDriftTest extends TestCase
{
    private DBALDatabase $database;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_column_data_drift_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    #[Test]
    public function coreFieldWithDataStorageButLingeringColumnIsReportedAsWarning(): void
    {
        $type = $this->singleBundleType();
        // 'status' is registered with FieldStorage::Data but the base table
        // still has a 'status' column — that's drift.
        $this->createBaseTable('thing', extraColumns: ['status']);
        $registry = $this->registry(coreFields: ['status' => FieldStorage::Data]);

        $results = $this->checker($type, $registry)->checkColumnDataStorageDrift();

        $drift = $this->findByCode($results, DiagnosticCode::COLUMN_DATA_STORAGE_DRIFT);
        self::assertNotNull($drift, 'Expected a COLUMN_DATA_STORAGE_DRIFT result.');
        self::assertSame('warn', $drift->status);
        self::assertSame('thing', $drift->context['entity_type'] ?? null);
        self::assertSame('thing', $drift->context['table'] ?? null);
        self::assertSame('status', $drift->context['column'] ?? null);
        self::assertArrayNotHasKey('bundle', $drift->context);
    }

    #[Test]
    public function coreFieldWithDataStorageAndNoColumnIsClean(): void
    {
        $type = $this->singleBundleType();
        // Base table has no 'status' column — registering 'status' as Data is fine.
        $this->createBaseTable('thing');
        $registry = $this->registry(coreFields: ['status' => FieldStorage::Data]);

        $results = $this->checker($type, $registry)->checkColumnDataStorageDrift();

        self::assertSame([], $results, 'No drift expected when no backing column exists.');
    }

    #[Test]
    public function coreColumnFieldWithBackingColumnIsClean(): void
    {
        $type = $this->singleBundleType();
        $this->createBaseTable('thing', extraColumns: ['status']);
        // Field is FieldStorage::Column — column is expected, no drift.
        $registry = $this->registry(coreFields: ['status' => FieldStorage::Column]);

        $results = $this->checker($type, $registry)->checkColumnDataStorageDrift();

        self::assertSame([], $results, 'Column-stored fields with backing columns are not drift.');
    }

    #[Test]
    public function bundleFieldWithDataStorageButLingeringColumnIsReportedWithBundle(): void
    {
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        // Subtable has a lingering 'tagline' column even though the field is Data-stored.
        $this->createSubtable('group__business', ['tagline']);
        $registry = $this->registry(
            coreFields: [],
            bundleFields: ['business' => ['tagline' => FieldStorage::Data]],
        );

        $results = $this->checker($type, $registry)->checkColumnDataStorageDrift();

        $drift = $this->findByCode($results, DiagnosticCode::COLUMN_DATA_STORAGE_DRIFT);
        self::assertNotNull($drift, 'Expected a COLUMN_DATA_STORAGE_DRIFT result for the bundle field.');
        self::assertSame('group__business', $drift->context['table'] ?? null);
        self::assertSame('tagline', $drift->context['column'] ?? null);
        self::assertSame('business', $drift->context['bundle'] ?? null);
    }

    #[Test]
    public function missingFieldRegistryIsSkippedSilently(): void
    {
        $type = $this->singleBundleType();
        $this->createBaseTable('thing', extraColumns: ['status']);

        // No registry — checker constructed with $fieldRegistry === null.
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([$type->id() => $type]);
        $checker = new HealthChecker(
            bootReport: new BootDiagnosticReport(
                registeredTypes: [$type->id() => $type],
                disabledTypeIds: [],
                schemaCompatibility: [],
            ),
            database: $this->database,
            entityTypeManager: $manager,
            projectRoot: $this->projectRoot,
        );

        self::assertSame([], $checker->checkColumnDataStorageDrift());
    }

    #[Test]
    public function checkRuntimeIncludesColumnDataStorageDriftResults(): void
    {
        $type = $this->singleBundleType();
        $this->createBaseTable('thing', extraColumns: ['status']);
        $registry = $this->registry(coreFields: ['status' => FieldStorage::Data]);

        $results = $this->checker($type, $registry)->checkRuntime();

        $drift = $this->findByCode($results, DiagnosticCode::COLUMN_DATA_STORAGE_DRIFT);
        self::assertNotNull($drift, 'checkRuntime() must surface COLUMN_DATA_STORAGE_DRIFT.');
    }

    // --- Helpers ---

    private function singleBundleType(): EntityType
    {
        return new EntityType(
            id: 'thing',
            label: 'Thing',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );
    }

    private function multiBundleType(): EntityType
    {
        return new EntityType(
            id: 'group',
            label: 'Group',
            class: \stdClass::class,
            keys: ['id' => 'gid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            bundleEntityType: 'group_type',
        );
    }

    /**
     * @param list<string> $extraColumns Columns to add beyond the canonical entity-table set.
     */
    private function createBaseTable(string $name, array $extraColumns = []): void
    {
        $idKey = $name === 'group' ? 'gid' : 'id';
        $labelKey = $name === 'group' ? 'title' : 'name';

        $fields = [
            $idKey => ['type' => 'serial', 'not null' => true],
            'uuid' => ['type' => 'varchar', 'length' => 36, 'not null' => true, 'default' => ''],
            $labelKey => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
            'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
            '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
        ];
        if ($name === 'group') {
            $fields['type'] = ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''];
        }
        foreach ($extraColumns as $col) {
            $fields[$col] = ['type' => 'varchar', 'length' => 255, 'not null' => false];
        }

        $this->database->schema()->createTable($name, [
            'fields' => $fields,
            'primary key' => [$idKey],
        ]);
    }

    /**
     * @param list<string> $columnNames
     */
    private function createSubtable(string $name, array $columnNames): void
    {
        $fields = ['gid' => ['type' => 'int', 'not null' => true]];
        foreach ($columnNames as $col) {
            $fields[$col] = ['type' => 'varchar', 'length' => 255, 'not null' => false];
        }
        $this->database->schema()->createTable($name, [
            'fields' => $fields,
            'primary key' => ['gid'],
        ]);
    }

    /**
     * @param array<string, FieldStorage> $coreFields
     * @param array<string, array<string, FieldStorage>> $bundleFields
     */
    private function registry(array $coreFields = [], array $bundleFields = []): FieldDefinitionRegistryInterface
    {
        $registry = $this->createStub(FieldDefinitionRegistryInterface::class);

        $coreFieldObjects = [];
        foreach ($coreFields as $name => $stored) {
            $coreFieldObjects[$name] = $this->fakeField($name, $stored);
        }

        $bundleFieldObjects = [];
        $bundleNames = [];
        foreach ($bundleFields as $bundle => $fields) {
            $bundleFieldObjects[$bundle] = [];
            foreach ($fields as $name => $stored) {
                $bundleFieldObjects[$bundle][$name] = $this->fakeField($name, $stored);
            }
            $bundleNames[] = $bundle;
        }

        $registry->method('coreFieldsFor')->willReturn($coreFieldObjects);
        $registry->method('bundleNamesFor')->willReturn($bundleNames);
        $registry->method('bundleFieldsFor')->willReturnCallback(
            static fn(string $_entityTypeId, string $bundle): array => $bundleFieldObjects[$bundle] ?? [],
        );

        return $registry;
    }

    private function fakeField(string $name, FieldStorage $stored): object
    {
        return new class ($name, $stored) {
            public function __construct(private readonly string $name, private readonly FieldStorage $stored) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getStored(): FieldStorage
            {
                return $this->stored;
            }
        };
    }

    private function checker(EntityTypeInterface $type, FieldDefinitionRegistryInterface $registry): HealthChecker
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([$type->id() => $type]);

        $bootReport = new BootDiagnosticReport(
            registeredTypes: [$type->id() => $type],
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        return new HealthChecker(
            bootReport: $bootReport,
            database: $this->database,
            entityTypeManager: $manager,
            projectRoot: $this->projectRoot,
            fieldRegistry: $registry,
        );
    }

    /** @param list<HealthCheckResult> $results */
    private function findByCode(array $results, DiagnosticCode $code): ?HealthCheckResult
    {
        foreach ($results as $r) {
            if ($r->code === $code) {
                return $r;
            }
        }
        return null;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
