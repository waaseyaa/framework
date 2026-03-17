<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(TableBuilder::class)]
#[CoversClass(Migration::class)]
final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->schema = new SchemaBuilder($connection);
    }

    #[Test]
    public function create_table_with_columns(): void
    {
        $this->schema->create('users', function (TableBuilder $table) {
            $table->id();
            $table->string('name');
            $table->string('mail')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasColumn('users', 'id'));
        $this->assertTrue($this->schema->hasColumn('users', 'name'));
        $this->assertTrue($this->schema->hasColumn('users', 'mail'));
        $this->assertTrue($this->schema->hasColumn('users', 'active'));
        $this->assertTrue($this->schema->hasColumn('users', 'created'));
        $this->assertTrue($this->schema->hasColumn('users', 'changed'));
    }

    #[Test]
    public function create_table_with_json_column(): void
    {
        $this->schema->create('nodes', function (TableBuilder $table) {
            $table->id();
            $table->json('_data')->nullable();
        });

        $this->assertTrue($this->schema->hasColumn('nodes', '_data'));
    }

    #[Test]
    public function drop_table(): void
    {
        $this->schema->create('temp', function (TableBuilder $table) {
            $table->id();
        });
        $this->assertTrue($this->schema->hasTable('temp'));

        $this->schema->drop('temp');
        $this->assertFalse($this->schema->hasTable('temp'));
    }

    #[Test]
    public function drop_if_exists_does_not_throw_for_missing(): void
    {
        $this->schema->dropIfExists('nonexistent');
        $this->assertFalse($this->schema->hasTable('nonexistent'));
    }

    #[Test]
    public function entity_base_convention(): void
    {
        $this->schema->create('nodes', function (TableBuilder $table) {
            $table->entityBase();
        });

        $this->assertTrue($this->schema->hasColumn('nodes', 'id'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'entity_type'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'bundle'));
        $this->assertTrue($this->schema->hasColumn('nodes', '_data'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'created'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'changed'));
    }

    #[Test]
    public function translation_columns_convention(): void
    {
        $this->schema->create('node_translations', function (TableBuilder $table) {
            $table->string('entity_id');
            $table->translationColumns();
        });

        $this->assertTrue($this->schema->hasColumn('node_translations', 'langcode'));
        $this->assertTrue($this->schema->hasColumn('node_translations', 'default_langcode'));
        $this->assertTrue($this->schema->hasColumn('node_translations', 'translation_source'));
    }

    #[Test]
    public function migration_class_has_up_and_down(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test', function (TableBuilder $table) {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('test');
            }
        };

        $migration->up($this->schema);
        $this->assertTrue($this->schema->hasTable('test'));

        $migration->down($this->schema);
        $this->assertFalse($this->schema->hasTable('test'));
    }

    #[Test]
    public function getConnectionReturnsTheDbalConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection);

        $this->assertSame($connection, $schema->getConnection());
    }

    #[Test]
    public function table_prefix_applied(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection, tablePrefix: 'acme_');

        $schema->create('nodes', function (TableBuilder $table) {
            $table->id();
        });

        $this->assertTrue($schema->hasTable('nodes'));
        // Underlying table is acme_nodes — verified via raw SQL
        $result = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='acme_nodes'");
        $this->assertNotFalse($result->fetchOne());
    }
}
