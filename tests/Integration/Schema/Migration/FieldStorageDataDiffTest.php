<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Schema\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Schema\EntityDiffFactory;
use Waaseyaa\EntityStorage\Schema\SchemaSnapshot;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * Mission #529 / WP08 / T048: K2 invariant lock.
 *
 * Per mission #1257 K2: `FieldStorage::Data`-stored fields are NOT
 * materialised as columns. Verifies the diff factory and the apply
 * pipeline both honour this end-to-end — a regression here would
 * silently start writing column DDL for every `_data` field.
 */
#[CoversNothing]
final class FieldStorageDataDiffTest extends TestCase
{
    #[Test]
    public function dataStoredFieldProducesNoColumnOpInDiff(): void
    {
        $factory = new EntityDiffFactory();
        $type = new EntityType(id: 'node', label: 'Node', class: \stdClass::class, keys: ['id' => 'id']);

        $diff = $factory->forEntityType(
            $type,
            'node',
            [
                new FieldDefinition(name: 'title', type: 'string'),
                new FieldDefinition(name: 'audit_blob', type: 'text', stored: FieldStorage::Data),
            ],
            [],
            new SchemaSnapshot(),
        );

        self::assertCount(1, $diff->composite->ops);
        self::assertSame('title', $diff->composite->ops[0]->column);
    }

    #[Test]
    public function applyOnlyMaterialisesColumnStoredFields(): void
    {
        $factory = new EntityDiffFactory();
        $type = new EntityType(id: 'node', label: 'Node', class: \stdClass::class, keys: ['id' => 'id']);

        $diff = $factory->forEntityType(
            $type,
            'node',
            [
                new FieldDefinition(name: 'title', type: 'string'),
                new FieldDefinition(name: 'audit_blob', type: 'text', stored: FieldStorage::Data),
                new FieldDefinition(name: 'flag_count', type: 'integer'),
            ],
            [],
            new SchemaSnapshot(),
        );

        [$connection, , $migrator] = self::harness();
        $connection->executeStatement('CREATE TABLE node (id INTEGER PRIMARY KEY)');

        $migrator->run([], [self::wrapCompositeAsV2('waaseyaa/test:v2:k2', $diff->composite)]);

        $cols = array_column(
            $connection->executeQuery('PRAGMA table_info(node)')->fetchAllAssociative(),
            'name',
        );
        self::assertContains('title', $cols);
        self::assertContains('flag_count', $cols);
        self::assertNotContains('audit_blob', $cols, 'K2 invariant: _data fields must NOT become columns.');
    }

    /**
     * @return array{0: \Doctrine\DBAL\Connection, 1: MigrationRepository, 2: Migrator}
     */
    private static function harness(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repo = new MigrationRepository($connection);
        $repo->createTable();
        $migrator = new Migrator(
            $connection,
            $repo,
            new V2PlanExecutor($connection, SqliteCompiler::forVersion('3.40.0')),
        );
        return [$connection, $repo, $migrator];
    }

    private static function wrapCompositeAsV2(string $id, CompositeDiff $root): MigrationInterfaceV2
    {
        return new class ($id, $root) implements MigrationInterfaceV2 {
            public function __construct(private readonly string $id, private readonly CompositeDiff $root) {}
            public function migrationId(): string
            {
                return $this->id;
            }
            public function package(): string
            {
                return 'waaseyaa/test';
            }
            public function dependencies(): array
            {
                return [];
            }
            public function plan(): MigrationPlan
            {
                return new MigrationPlan(migrationId: $this->id, package: 'waaseyaa/test', dependencies: [], root: $this->root);
            }
        };
    }
}
