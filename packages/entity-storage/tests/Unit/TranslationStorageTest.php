<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSchemaHandler::class)]
final class TranslationStorageTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            translatable: true,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }

    #[Test]
    public function ensureTranslationTableCreatesTable(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable();

        $this->assertTrue(
            $this->database->schema()->tableExists('test_entity_translations'),
        );
    }

    #[Test]
    public function ensureTranslationTableIdempotent(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        // Call twice -- should not throw.
        $handler->ensureTranslationTable();
        $handler->ensureTranslationTable();

        $this->assertTrue(
            $this->database->schema()->tableExists('test_entity_translations'),
        );
    }

    #[Test]
    public function translationTableHasCorrectColumns(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable();

        $schema = $this->database->schema();
        $table = 'test_entity_translations';

        $this->assertTrue($schema->fieldExists($table, 'entity_id'));
        $this->assertTrue($schema->fieldExists($table, 'langcode'));
        $this->assertTrue($schema->fieldExists($table, 'translation_status'));
        $this->assertTrue($schema->fieldExists($table, 'translation_source'));
        $this->assertTrue($schema->fieldExists($table, 'translation_created'));
        $this->assertTrue($schema->fieldExists($table, 'translation_changed'));
        $this->assertTrue($schema->fieldExists($table, '_data'));
    }

    #[Test]
    public function translationTableWithCustomFieldSchemas(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable([
            'title' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => false,
            ],
            'body' => [
                'type' => 'text',
                'not null' => false,
            ],
        ]);

        $schema = $this->database->schema();
        $table = 'test_entity_translations';

        $this->assertTrue($schema->fieldExists($table, 'title'));
        $this->assertTrue($schema->fieldExists($table, 'body'));
    }

    #[Test]
    public function getTranslationTableName(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);

        $this->assertSame('test_entity_translations', $handler->getTranslationTableName());
    }

    #[Test]
    public function addTranslationFieldColumns(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable();

        $handler->addTranslationFieldColumns([
            'summary' => [
                'type' => 'text',
                'not null' => false,
            ],
        ]);

        $this->assertTrue(
            $this->database->schema()->fieldExists('test_entity_translations', 'summary'),
        );
    }

    #[Test]
    public function insertAndQueryTranslation(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable([
            'title' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => false,
            ],
        ]);

        // Insert base entity.
        $this->database->insert('test_entity')
            ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
            ->values([
                'uuid' => 'entity-uuid-1',
                'label' => 'Hello',
                'bundle' => 'article',
                'langcode' => 'en',
                '_data' => '{}',
            ])
            ->execute();

        // Insert English translation.
        $this->database->insert('test_entity_translations')
            ->fields(['entity_id', 'langcode', 'title', 'translation_status', '_data'])
            ->values([
                'entity_id' => '1',
                'langcode' => 'en',
                'title' => 'Hello World',
                'translation_status' => 'published',
                '_data' => '{}',
            ])
            ->execute();

        // Insert French translation.
        $this->database->insert('test_entity_translations')
            ->fields(['entity_id', 'langcode', 'title', 'translation_status', 'translation_source', '_data'])
            ->values([
                'entity_id' => '1',
                'langcode' => 'fr',
                'title' => 'Bonjour le monde',
                'translation_status' => 'published',
                'translation_source' => 'en',
                '_data' => '{}',
            ])
            ->execute();

        // Query English translation.
        $enResult = $this->database->select('test_entity_translations')
            ->fields('test_entity_translations')
            ->condition('entity_id', '1')
            ->condition('langcode', 'en')
            ->execute();

        $enRow = null;
        foreach ($enResult as $row) {
            $enRow = (array) $row;
            break;
        }

        $this->assertNotNull($enRow);
        $this->assertSame('Hello World', $enRow['title']);

        // Query French translation.
        $frResult = $this->database->select('test_entity_translations')
            ->fields('test_entity_translations')
            ->condition('entity_id', '1')
            ->condition('langcode', 'fr')
            ->execute();

        $frRow = null;
        foreach ($frResult as $row) {
            $frRow = (array) $row;
            break;
        }

        $this->assertNotNull($frRow);
        $this->assertSame('Bonjour le monde', $frRow['title']);
        $this->assertSame('en', $frRow['translation_source']);
    }

    #[Test]
    public function getAvailableLanguagesViaQuery(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable();

        // Insert base entity.
        $this->database->insert('test_entity')
            ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
            ->values([
                'uuid' => 'entity-uuid-1',
                'label' => 'Hello',
                'bundle' => 'article',
                'langcode' => 'en',
                '_data' => '{}',
            ])
            ->execute();

        // Insert translations.
        foreach (['en', 'fr', 'de'] as $lang) {
            $this->database->insert('test_entity_translations')
                ->fields(['entity_id', 'langcode', 'translation_status', '_data'])
                ->values([
                    'entity_id' => '1',
                    'langcode' => $lang,
                    'translation_status' => 'published',
                    '_data' => '{}',
                ])
                ->execute();
        }

        // Query available languages.
        $result = $this->database->select('test_entity_translations')
            ->fields('test_entity_translations', ['langcode'])
            ->condition('entity_id', '1')
            ->execute();

        $languages = [];
        foreach ($result as $row) {
            $row = (array) $row;
            $languages[] = $row['langcode'];
        }

        $this->assertCount(3, $languages);
        $this->assertContains('en', $languages);
        $this->assertContains('fr', $languages);
        $this->assertContains('de', $languages);
    }

    #[Test]
    public function deleteTranslation(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->ensureTranslationTable();

        // Insert base entity.
        $this->database->insert('test_entity')
            ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
            ->values([
                'uuid' => 'entity-uuid-1',
                'label' => 'Hello',
                'bundle' => 'article',
                'langcode' => 'en',
                '_data' => '{}',
            ])
            ->execute();

        // Insert translation.
        $this->database->insert('test_entity_translations')
            ->fields(['entity_id', 'langcode', 'translation_status', '_data'])
            ->values([
                'entity_id' => '1',
                'langcode' => 'fr',
                'translation_status' => 'published',
                '_data' => '{}',
            ])
            ->execute();

        // Delete the French translation.
        $this->database->delete('test_entity_translations')
            ->condition('entity_id', '1')
            ->condition('langcode', 'fr')
            ->execute();

        // Verify it's gone.
        $result = $this->database->select('test_entity_translations')
            ->fields('test_entity_translations')
            ->condition('entity_id', '1')
            ->condition('langcode', 'fr')
            ->execute();

        $rowCount = 0;
        foreach ($result as $row) {
            $rowCount++;
        }

        $this->assertSame(0, $rowCount);
    }
}
