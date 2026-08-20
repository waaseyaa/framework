<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(EntitySchemaSync::class)]
final class EntitySchemaSyncTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    #[Test]
    public function it_creates_tables_for_each_entity_type(): void
    {
        $widget = $this->makeEntityType('widget', 'Widget');
        $gadget = $this->makeEntityType('gadget', 'Gadget');

        $sync = new EntitySchemaSync($this->database);
        $sync->syncAll([$widget, $gadget]);

        $schema = $this->database->schema();
        $this->assertTrue($schema->tableExists('widget'));
        $this->assertTrue($schema->tableExists('gadget'));
    }

    #[Test]
    public function sync_all_is_idempotent(): void
    {
        $widget = $this->makeEntityType('widget', 'Widget');

        $sync = new EntitySchemaSync($this->database);
        $sync->syncAll([$widget]);
        $sync->syncAll([$widget]);

        $this->assertTrue($this->database->schema()->tableExists('widget'));
    }

    #[Test]
    public function a_one_shot_generator_is_replayed_when_the_plan_finds_a_change(): void
    {
        $definitions = (function (): \Generator {
            yield $this->makeEntityType('widget', 'Widget');
        })();

        (new EntitySchemaSync($this->database))->syncAll($definitions);

        $this->assertTrue($this->database->schema()->tableExists('widget'));
        $this->assertSame(0, (int) $this->database->getConnection()->fetchOne('PRAGMA query_only'));
    }

    #[Test]
    public function an_unchanged_plan_preserves_a_callers_query_only_mode(): void
    {
        $widget = $this->makeEntityType('widget', 'Widget');
        $sync = new EntitySchemaSync($this->database);
        $sync->syncAll([$widget]);
        $this->database->getConnection()->executeStatement('PRAGMA query_only = ON');

        $sync->syncAll([$widget]);

        $this->assertSame(1, (int) $this->database->getConnection()->fetchOne('PRAGMA query_only'));
    }

    #[Test]
    public function a_synchronization_that_replays_reports_each_condition_once(): void
    {
        $transWidget = $this->makeTranslatableEntityType('trans_widget', 'Trans Widget');
        (new EntitySchemaSync($this->database))->syncAll([$transWidget]);
        $this->seedNonEmptyTranslationSibling();

        // A second, genuinely new type means the plan is refused only *after*
        // the sibling has already been examined and reported, so both the plan
        // and the replayed apply traversal reach that report.
        $logger = new RecordingSchemaSyncLogger();
        (new EntitySchemaSync($this->database, logger: $logger))->syncAll([
            $transWidget,
            $this->makeEntityType('late_widget', 'Late Widget'),
        ]);

        $this->assertTrue($this->database->schema()->tableExists('late_widget'), 'the change still applies');
        $this->assertSame(
            1,
            $logger->countContaining('is non-empty'),
            'a synchronization that replays through the coordinator must report a condition once, not once per traversal',
        );
    }

    #[Test]
    public function an_unchanged_synchronization_still_reports_what_its_plan_finds(): void
    {
        $transWidget = $this->makeTranslatableEntityType('trans_widget', 'Trans Widget');
        (new EntitySchemaSync($this->database))->syncAll([$transWidget]);
        $this->seedNonEmptyTranslationSibling();

        $logger = new RecordingSchemaSyncLogger();
        $sync = new EntitySchemaSync($this->database, logger: $logger);
        $sync->syncAll([$transWidget]);

        // The plan is the only traversal here, so suppressing its records would
        // silence the diagnostic entirely on an install that needs no change.
        $this->assertSame(
            1,
            $logger->countContaining('is non-empty'),
            'an unchanged synchronization must still report what its read-only plan found',
        );
    }

    #[Test]
    public function it_does_not_create_a_translations_sibling_for_sql_blob_translatable(): void
    {
        $article = $this->makeTranslatableEntityType('trans_widget', 'Trans Widget');

        (new EntitySchemaSync($this->database))->syncAll([$article]);

        $schema = $this->database->schema();
        $this->assertTrue($schema->tableExists('trans_widget'), 'base table is created');
        $this->assertFalse(
            $schema->tableExists('trans_widget_translations'),
            'sql-blob translatable types keep per-langcode rows in the base table and must not get a _translations sibling (b2)',
        );
    }

    #[Test]
    public function it_drops_a_stale_empty_translations_sibling_on_sync(): void
    {
        $schema = $this->database->schema();
        // Simulate the pre-alpha.200 state: an empty `<entity>_translations`
        // sibling a buggy kernel boot materialised for a sql-blob translatable type.
        $schema->createTable('trans_widget_translations', [
            'fields' => [
                'entity_id' => ['type' => 'varchar', 'length' => 128],
                'langcode' => ['type' => 'varchar', 'length' => 12],
            ],
        ]);
        $this->assertTrue($schema->tableExists('trans_widget_translations'));

        $article = $this->makeTranslatableEntityType('trans_widget', 'Trans Widget');
        (new EntitySchemaSync($this->database))->syncAll([$article]);

        $this->assertFalse(
            $schema->tableExists('trans_widget_translations'),
            'a stale EMPTY translation sibling must be dropped on sync (b2 cleanup)',
        );
        $this->assertTrue($schema->tableExists('trans_widget'));
    }

    #[Test]
    public function it_keeps_a_non_empty_translations_sibling(): void
    {
        $schema = $this->database->schema();
        $schema->createTable('trans_widget_translations', [
            'fields' => [
                'entity_id' => ['type' => 'varchar', 'length' => 128],
                'langcode' => ['type' => 'varchar', 'length' => 12],
            ],
        ]);
        $this->database->insert('trans_widget_translations')
            ->values(['entity_id' => 'e1', 'langcode' => 'oj'])
            ->execute();

        $article = $this->makeTranslatableEntityType('trans_widget', 'Trans Widget');
        (new EntitySchemaSync($this->database))->syncAll([$article]);

        $this->assertTrue(
            $schema->tableExists('trans_widget_translations'),
            'a NON-empty translation sibling is left intact — the cleanup never risks data loss (b2)',
        );
    }

    /** A sql-blob translatable type's non-empty sibling is reported, never written to. */
    private function seedNonEmptyTranslationSibling(): void
    {
        $this->database->schema()->createTable('trans_widget_translations', [
            'fields' => [
                'entity_id' => ['type' => 'varchar', 'length' => 128],
                'langcode' => ['type' => 'varchar', 'length' => 12],
            ],
        ]);
        $this->database->insert('trans_widget_translations')
            ->values(['entity_id' => 'e1', 'langcode' => 'oj'])
            ->execute();
    }

    private function makeEntityType(string $id, string $label): EntityType
    {
        return new EntityType(
            id: $id,
            label: $label,
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }

    private function makeTranslatableEntityType(string $id, string $label): EntityType
    {
        return new EntityType(
            id: $id,
            label: $label,
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            translatable: true,
        );
    }
}

/** Counts what a synchronization reported, regardless of which traversal produced it. */
final class RecordingSchemaSyncLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<string> */
    private array $messages = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function countContaining(string $needle): int
    {
        return \count(array_filter(
            $this->messages,
            static fn(string $message): bool => str_contains($message, $needle),
        ));
    }
}
