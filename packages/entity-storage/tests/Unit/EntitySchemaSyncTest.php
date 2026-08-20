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
