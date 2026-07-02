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
 * Two independent paths can materialize the `attachment` table:
 *
 *   - The GENERIC entity-storage schema-sync path — {@see SqlSchemaHandler},
 *     driven purely by the `Attachment` entity type definition. This is what
 *     `EntityTypeManagerFactory` (kernel boot) and `EntitySchemaSync` (CLI
 *     `db:init`/`schema:sync`) invoke for every entity type; NEITHER of them
 *     ever calls {@see AttachmentSchema} directly.
 *   - {@see AttachmentSchema} — this package's own hand-built schema class.
 *
 * Investigation finding: for a `sql-blob` backend entity type (what
 * `Attachment` uses — no `primaryStorageBackend` override), the GENERIC path
 * materializes ONLY the framework-standard base columns every content entity
 * gets (`id`, `uuid`, `bundle`, the label column, `langcode`, `_data`). It
 * has NO knowledge of `#[Field]`-declared entity-level columns for that
 * backend — that materialization (`SqlColumnSchemaBuilder`) exists only for
 * the `sql-column` backend. So the generic path alone NEVER produces
 * `parent_entity_type`, `parent_entity_id`, `is_active`, `created_at`,
 * `updated_at`, or any of the attachment-specific indexes — regardless of
 * how `Attachment`'s `#[Field]` attributes are declared.
 *
 * Before this WP, {@see AttachmentSchema::ensureTable()} was invoked from
 * NOWHERE in production code (verified: only test setUp() methods called
 * it) — `AttachmentServiceProvider::boot()` now wires it in (see that
 * class). This test file locks in:
 *
 *   1. The base-column SUBSET AttachmentSchema hand-builds is byte-for-byte
 *      identical (type/nullability/default) to what SqlSchemaHandler
 *      generates for the same entity type — the docblock claim
 *      "matching what SqlSchemaHandler would auto-generate" actually holds.
 *      (Regression pin: this behavior predates the WP and already passed
 *      before its fix — the value is preventing future drift.)
 *   2. AttachmentSchema's own build includes every documented index.
 *      (Regression pin, same caveat as 1.)
 *   3. AttachmentSchema::ensureTable() self-heals: run AFTER the generic
 *      path already created the (incomplete) base-only table — the ordering
 *      an out-of-order boot or a pre-fix install could produce — it
 *      converges to the exact same final shape as a from-scratch build.
 *      (The WP's red→green test: failed before the heal branch existed.)
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

    /**
     * Confirms the generic path alone does NOT produce the
     * attachment-specific columns — the root cause this WP fixes by wiring
     * AttachmentSchema into AttachmentServiceProvider::boot(). If this
     * assertion ever starts failing (i.e. the generic path starts producing
     * these columns on its own), the self-healing branch in
     * AttachmentSchema::ensureTable() becomes redundant, not wrong — revisit
     * this test file rather than deleting the assertion silently.
     */
    #[Test]
    public function genericPathAloneDoesNotProduceAttachmentSpecificColumns(): void
    {
        $genericDb = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Attachment::class);
        new SqlSchemaHandler($entityType, $genericDb)->ensureTable();

        $schema = $genericDb->schema();
        foreach (self::ATTACHMENT_SPECIFIC_COLUMNS as $column) {
            self::assertFalse(
                $schema->fieldExists('attachment', $column),
                "Expected the generic schema-sync path to NOT create '{$column}' "
                . '— if it now does, AttachmentSchema is no longer the sole source of this column.',
            );
        }
    }

    #[Test]
    public function attachmentSchemaBuildIncludesAllDocumentedIndexes(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();

        $this->assertHasAllDocumentedIndexes($database);
    }

    /**
     * The convergence test: when the GENERIC path creates the base-only
     * table FIRST (simulating an out-of-order kernel boot, or a pre-existing
     * install from before this WP wired AttachmentSchema into boot()),
     * AttachmentSchema::ensureTable() run afterward must additively backfill
     * every attachment-specific column and index rather than silently
     * no-op'ing because the table already exists.
     */
    #[Test]
    public function ensureTableSelfHealsWhenGenericPathCreatesTheBaseTableFirst(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Attachment::class);
        new SqlSchemaHandler($entityType, $database)->ensureTable();

        // Sanity: the incomplete shape this test starts from.
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
