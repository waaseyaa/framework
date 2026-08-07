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
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

/**
 * Subtable-aware schema drift and foreign-key enforcement checks.
 *
 * See docs/specs/bundle-scoped-storage.md §Drift diagnostic.
 */
#[CoversClass(HealthChecker::class)]
final class HealthCheckerBundleSubtableDriftTest extends TestCase
{
    private DBALDatabase $database;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_subtable_drift_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    #[Test]
    public function freshInstallWithTwoBundlesPassesWhenSubtablesPresent(): void
    {
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        $this->createSubtable('group__business', ['email']);
        // 'organization' bundle has no registered fields — no subtable expected.

        $registry = $this->registry(['business' => ['email'], 'organization' => []]);

        $results = $this->checker($type, $registry)->checkSchemaDrift();

        foreach ($results as $result) {
            self::assertNotSame('fail', $result->status, sprintf(
                'Unexpected drift reported: %s — %s',
                $result->name,
                $result->message,
            ));
        }
    }

    #[Test]
    public function missingBundleSubtableIsReportedAsError(): void
    {
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        // Intentionally do NOT create group__business even though 'business' has fields.
        $registry = $this->registry(['business' => ['email']]);

        $results = $this->checker($type, $registry)->checkSchemaDrift();

        $missing = $this->findResult($results, 'Schema: group__business');
        self::assertNotNull($missing, 'Expected a HealthCheckResult for missing subtable group__business.');
        self::assertSame('fail', $missing->status);
        self::assertSame(DiagnosticCode::MISSING_BUNDLE_SUBTABLE, $missing->code);
        self::assertSame('group__business', $missing->context['table'] ?? null);
    }

    #[Test]
    public function columnDriftOnSubtableIsReportedUnderSubtableName(): void
    {
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        // Create subtable WITHOUT the 'email' column that the registry expects.
        $this->createSubtable('group__business', []);
        $registry = $this->registry(['business' => ['email']]);

        $results = $this->checker($type, $registry)->checkSchemaDrift();

        $drift = $this->findResult($results, 'Schema: group__business');
        self::assertNotNull($drift, 'Expected drift HealthCheckResult for group__business.');
        self::assertSame('fail', $drift->status);
        self::assertSame(DiagnosticCode::DATABASE_SCHEMA_DRIFT, $drift->code);
        self::assertSame('group__business', $drift->context['table'] ?? null);
        $columns = array_column($drift->context['drift'] ?? [], 'column');
        self::assertContains('email', $columns, 'Expected "email" column to be reported as missing on the subtable.');
    }

    #[Test]
    public function orphanSubtableIsReportedAsInformational(): void
    {
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        // Subtable present for a bundle that no longer has registered fields.
        $this->createSubtable('group__legacy', ['old_column']);
        $registry = $this->registry(['business' => ['email']]);
        $this->createSubtable('group__business', ['email']);

        $results = $this->checker($type, $registry)->checkSchemaDrift();

        $orphan = $this->findResult($results, 'Schema: group__legacy');
        self::assertNotNull($orphan, 'Expected orphan HealthCheckResult for group__legacy.');
        self::assertSame('warn', $orphan->status);
        self::assertSame(DiagnosticCode::ORPHAN_BUNDLE_SUBTABLE, $orphan->code);
    }

    #[Test]
    public function singleBundleEntityTypeRegressionStillPasses(): void
    {
        // Entity type with no bundleEntityType — drift detection must behave exactly
        // as before (no subtable enumeration, no missing-subtable noise).
        $type = new EntityType(
            id: 'user',
            label: 'User',
            class: \stdClass::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        // Materialize the real expected schema instead of hand-rolling columns.
        (new SqlSchemaHandler($type, $this->database))->ensureTable();
        $registry = $this->createStub(FieldDefinitionRegistryInterface::class);
        $registry->method('bundleNamesFor')->willReturn([]);

        $results = $this->checker($type, $registry)->checkSchemaDrift();

        foreach ($results as $result) {
            self::assertNotSame('fail', $result->status, sprintf(
                'Single-bundle entity type must not report drift: %s — %s',
                $result->name,
                $result->message,
            ));
        }
    }

    #[Test]
    public function foreignKeysDisabledIsReportedAsError(): void
    {
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = OFF');
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        $registry = $this->registry([]);

        $results = $this->checker($type, $registry)->checkRuntime();

        $fk = $this->findResult($results, 'Foreign key enforcement');
        self::assertNotNull($fk, 'Expected a Foreign key enforcement HealthCheckResult.');
        self::assertSame('fail', $fk->status);
        self::assertSame(DiagnosticCode::FK_ENFORCEMENT_DISABLED, $fk->code);
    }

    #[Test]
    public function foreignKeysEnabledPasses(): void
    {
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $type = $this->multiBundleType();
        $this->createBaseTable('group');
        $registry = $this->registry([]);

        $results = $this->checker($type, $registry)->checkRuntime();

        $fk = $this->findResult($results, 'Foreign key enforcement');
        self::assertNotNull($fk);
        self::assertSame('pass', $fk->status);
    }

    // --- Helpers ---

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

    private function createBaseTable(string $name, string $idKey = 'gid', string $labelKey = 'title'): void
    {
        $this->database->schema()->createTable($name, [
            'fields' => [
                $idKey => ['type' => 'serial', 'not null' => true],
                'uuid' => ['type' => 'varchar', 'length' => 36, 'not null' => true, 'default' => ''],
                'type' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                $labelKey => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
                '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
            ],
            'primary key' => [$idKey],
        ]);
    }

    /**
     * @param list<string> $fieldNames
     */
    private function createSubtable(string $name, array $fieldNames): void
    {
        $fields = ['gid' => ['type' => 'int', 'not null' => true]];
        foreach ($fieldNames as $fieldName) {
            $fields[$fieldName] = ['type' => 'varchar', 'length' => 255, 'not null' => false];
        }
        $this->database->schema()->createTable($name, [
            'fields' => $fields,
            'primary key' => ['gid'],
        ]);
    }

    /**
     * @param array<string, list<string>> $bundleToFieldNames
     */
    private function registry(array $bundleToFieldNames): FieldDefinitionRegistryInterface
    {
        $registry = $this->createStub(FieldDefinitionRegistryInterface::class);

        $nonEmpty = [];
        $fieldsMap = [];
        foreach ($bundleToFieldNames as $bundle => $names) {
            $fieldsMap[$bundle] = [];
            foreach ($names as $name) {
                // The registry returns opaque FieldDefinition objects; the drift
                // detector only reads array keys (field names).
                $fieldsMap[$bundle][$name] = new \stdClass();
            }
            if ($names !== []) {
                $nonEmpty[] = $bundle;
            }
        }

        $registry->method('bundleNamesFor')->willReturn($nonEmpty);
        $registry->method('bundleFieldsFor')->willReturnCallback(
            static fn(string $_entityTypeId, string $bundle): array => $fieldsMap[$bundle] ?? [],
        );

        return $registry;
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
    private function findResult(array $results, string $name): ?HealthCheckResult
    {
        foreach ($results as $r) {
            if ($r->name === $name) {
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
