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
use Waaseyaa\EntityStorage\CoordinatedEntitySchemaExecutor;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * #2478: coordinated schema:sync must observe and apply AttachmentSchema
 * against a legacy base-only table. apply() must not swallow the planner's
 * read-only signal or genuine apply failures.
 */
#[CoversClass(AttachmentSchema::class)]
#[CoversClass(EntitySchemaSync::class)]
final class AttachmentSchemaCoordinatedSyncTest extends TestCase
{
    private const TRANSITION_COLUMNS = [
        'parent_entity_type',
        'parent_entity_id',
        'is_active',
        'created_at',
        'updated_at',
    ];

    #[Test]
    public function apply_rejects_a_different_database_instance(): void
    {
        $constructed = DBALDatabase::createSqlite();
        $other = DBALDatabase::createSqlite();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('database it was constructed with');
        new AttachmentSchema($constructed)->apply($other, 'attachment');
    }

    #[Test]
    public function apply_rejects_the_wrong_table(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot transition table');
        new AttachmentSchema($database)->apply($database, 'other');
    }

    #[Test]
    public function planning_detects_mutation_on_a_legacy_base_only_attachment_table(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createBaseOnlyAttachmentTable($database);

        $requiresMutation = new CoordinatedEntitySchemaExecutor($database)->requiresMutation(
            function () use ($database): void {
                new AttachmentSchema($database)->apply($database, 'attachment');
            },
        );

        self::assertTrue(
            $requiresMutation,
            'Query-only planning must see the attachment transition as a required mutation.',
        );
        self::assertFalse($database->schema()->fieldExists('attachment', 'is_active'));
    }

    #[Test]
    public function sync_all_applies_transition_columns_indexes_and_backfill(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createBaseOnlyAttachmentTable($database);
        $row = [
            'uuid' => 'uuid-legacy-1',
            'bundle' => 'attachment',
            'filename' => 'legacy.pdf',
            'langcode' => 'en',
            '_data' => json_encode([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '42',
                'is_active' => 1,
                'created_at' => 1_111,
                'updated_at' => 1_111,
            ], \JSON_THROW_ON_ERROR),
        ];
        $database->insert('attachment')->fields(array_keys($row))->values($row)->execute();

        new EntitySchemaSync($database)->syncAll([EntityType::fromClass(Attachment::class)]);

        $schema = $database->schema();
        foreach (self::TRANSITION_COLUMNS as $column) {
            self::assertTrue($schema->fieldExists('attachment', $column), "Missing transition column {$column}");
        }

        $healed = iterator_to_array($database->select('attachment', 'a')
            ->fields('a', self::TRANSITION_COLUMNS)
            ->execute());
        self::assertCount(1, $healed);
        self::assertSame('node', (string) $healed[0]['parent_entity_type']);
        self::assertSame('42', (string) $healed[0]['parent_entity_id']);
        self::assertSame(1, (int) $healed[0]['is_active']);
        self::assertSame(1_111, (int) $healed[0]['created_at']);

        $indexes = [];
        foreach ($database->query("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'attachment'") as $indexRow) {
            $indexes[(string) $indexRow['name']] = (string) ($indexRow['sql'] ?? '');
        }
        foreach (
            [
                'attachment_uuid',
                'attachment_bundle',
                'attachment_parent',
                'attachment_parent_active',
                'attachment_one_active_per_parent',
            ] as $expected
        ) {
            self::assertArrayHasKey($expected, $indexes, "Missing index {$expected}");
        }
        self::assertStringContainsString('WHERE', $indexes['attachment_one_active_per_parent']);
    }

    #[Test]
    public function a_genuine_transition_failure_does_not_return_false_success_from_sync_all(): void
    {
        $database = DBALDatabase::createSqlite();
        $this->createBaseOnlyAttachmentTable($database);
        $row = [
            'uuid' => 'uuid-fail-1',
            'bundle' => 'attachment',
            'filename' => 'fail.pdf',
            'langcode' => 'en',
            '_data' => json_encode([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '7',
                'is_active' => 1,
            ], \JSON_THROW_ON_ERROR),
        ];
        $database->insert('attachment')->fields(array_keys($row))->values($row)->execute();
        $database->query("CREATE TRIGGER fail_attachment_backfill BEFORE UPDATE ON attachment BEGIN SELECT RAISE(ABORT, 'backfill UPDATE killed mid-heal'); END");

        $threw = false;
        try {
            new EntitySchemaSync($database)->syncAll([EntityType::fromClass(Attachment::class)]);
        } catch (\Throwable $exception) {
            $threw = true;
            self::assertStringContainsString('backfill UPDATE killed mid-heal', $exception->getMessage());
        }
        self::assertTrue($threw, 'Coordinated sync must propagate a genuine attachment transition failure.');
        self::assertFalse(
            $database->schema()->fieldExists('attachment', 'is_active'),
            'A failed coordinated apply must not leave a false-success healed table.',
        );
    }

    private function createBaseOnlyAttachmentTable(DBALDatabase $database): void
    {
        $handler = new SqlSchemaHandler(EntityType::fromClass(Attachment::class), $database);
        $spec = new \ReflectionMethod(SqlSchemaHandler::class, 'buildTableSpec')->invoke($handler);
        self::assertIsArray($spec);
        $database->schema()->createTable('attachment', $spec);
    }
}
