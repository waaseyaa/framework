<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * Schema-parity investigation (WP3 audit-remediation, 2026-07-01).
 *
 * Coordinated schema sync materializes `attachment` through
 * {@see SqlSchemaHandler} plus the `#[StorageSchemaTransition]` declared on
 * {@see Attachment}. Production HTTP must not CREATE this table (#2478).
 * This file locks in:
 *
 *   1. The base-column SUBSET AttachmentSchema hand-builds matches what
 *      SqlSchemaHandler generates for the same entity type.
 *   2. AttachmentSchema's own build includes every documented index.
 *   3. schema:sync applies AttachmentSchema, so the coordinated path has the
 *      attachment-specific columns.
 *   4. AttachmentSchema::ensureTable() still self-heals a legacy base-only
 *      table (pre-transition installs).
 *
 * Data-preservation and platform-robustness of the heal (value backfill
 * from `_data`, non-SQLite catalog probes, mid-heal failure posture) are
 * covered by the sibling {@see AttachmentSchemaSelfHealTest}.
 */
#[CoversClass(AttachmentSchema::class)]
final class AttachmentSchemaParityTest extends TestCase
{
    private const ATTACHMENT_SPECIFIC_COLUMNS = [
        'parent_entity_type',
        'parent_entity_id',
        'is_active',
        'created_at',
        'updated_at',
    ];

    private const BASE_COLUMNS = ['id', 'uuid', 'bundle', 'filename', 'langcode', '_data'];

    #[Test]
    public function baseColumnsMatchWhatSqlSchemaHandlerAutoGenerates(): void
    {
        $genericDb = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Attachment::class);
        new SqlSchemaHandler($entityType, $genericDb)->ensureTable();

        $attachmentDb = DBALDatabase::createSqlite();
        new AttachmentSchema($attachmentDb)->ensureTable();

        $genericColumns = $this->tableInfo($genericDb, 'attachment');
        $attachmentColumns = $this->tableInfo($attachmentDb, 'attachment');

        foreach (self::BASE_COLUMNS as $column) {
            self::assertArrayHasKey($column, $genericColumns, "Generic path missing base column {$column}");
            self::assertArrayHasKey($column, $attachmentColumns, "AttachmentSchema missing base column {$column}");

            self::assertSame(
                $genericColumns[$column]['type'],
                $attachmentColumns[$column]['type'],
                "Column '{$column}' type diverges between the two schema-build paths.",
            );
            self::assertSame(
                $genericColumns[$column]['notnull'],
                $attachmentColumns[$column]['notnull'],
                "Column '{$column}' NOT NULL diverges between the two schema-build paths.",
            );
            self::assertSame(
                $genericColumns[$column]['dflt_value'],
                $attachmentColumns[$column]['dflt_value'],
                "Column '{$column}' default diverges between the two schema-build paths.",
            );
        }
    }

    #[Test]
    public function schemaSyncAppliesDeclaredAttachmentTransition(): void
    {
        $database = DBALDatabase::createSqlite();
        new SqlSchemaHandler(EntityType::fromClass(Attachment::class), $database)->ensureTable();

        $schema = $database->schema();
        foreach (self::ATTACHMENT_SPECIFIC_COLUMNS as $column) {
            self::assertTrue(
                $schema->fieldExists('attachment', $column),
                "Coordinated schema:sync must apply AttachmentSchema and create '{$column}'.",
            );
        }
        $this->assertHasAllDocumentedIndexes($database);
    }

    #[Test]
    public function attachmentSchemaBuildIncludesAllDocumentedIndexes(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();

        $this->assertHasAllDocumentedIndexes($database);
    }

    /**
     * A legacy base-only table (pre-transition install) must still converge
     * when AttachmentSchema::ensureTable() runs during schema:sync.
     */
    #[Test]
    public function ensureTableSelfHealsWhenGenericPathCreatesTheBaseTableFirst(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createBaseOnlyAttachmentTable($database);

        $schema = $database->schema();
        self::assertTrue($schema->tableExists('attachment'));
        self::assertFalse($schema->fieldExists('attachment', 'is_active'));

        new AttachmentSchema($database)->ensureTable();

        foreach ([...self::BASE_COLUMNS, ...self::ATTACHMENT_SPECIFIC_COLUMNS] as $column) {
            self::assertTrue(
                $schema->fieldExists('attachment', $column),
                "Column '{$column}' missing after self-healing ensureTable().",
            );
        }
        $this->assertHasAllDocumentedIndexes($database);
    }

    private function createBaseOnlyAttachmentTable(DBALDatabase $database): void
    {
        $handler = new SqlSchemaHandler(EntityType::fromClass(Attachment::class), $database);
        $spec = new \ReflectionMethod(SqlSchemaHandler::class, 'buildTableSpec')->invoke($handler);
        self::assertIsArray($spec);
        $database->schema()->createTable('attachment', $spec);
    }

    /**
     * @return array<string, array{type: string, notnull: int, dflt_value: mixed}>
     */
    private function tableInfo(DBALDatabase $database, string $table): array
    {
        $columns = [];
        foreach ($database->query("PRAGMA table_info({$table})") as $row) {
            $columns[(string) $row['name']] = [
                'type' => (string) $row['type'],
                'notnull' => (int) $row['notnull'],
                'dflt_value' => $row['dflt_value'],
            ];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function indexNames(DBALDatabase $database, string $table): array
    {
        $names = [];
        foreach ($database->query("PRAGMA index_list({$table})") as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }

    private function assertHasAllDocumentedIndexes(DBALDatabase $database): void
    {
        $indexes = $this->indexNames($database, 'attachment');

        foreach (
            [
                'attachment_uuid',
                'attachment_bundle',
                'attachment_parent',
                'attachment_parent_active',
                'attachment_one_active_per_parent',
            ] as $expected
        ) {
            self::assertContains($expected, $indexes, "Missing documented index '{$expected}'.");
        }
    }
}
