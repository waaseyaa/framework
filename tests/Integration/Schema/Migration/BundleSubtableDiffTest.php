<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Schema\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Schema\BundleLevelDiff;
use Waaseyaa\EntityStorage\Schema\EntityDiffFactory;
use Waaseyaa\EntityStorage\Schema\SchemaSnapshot;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * Mission #529 / WP08 / T047: bundle subtable diff scenarios.
 *
 * Locks `{base}__{bundle}` naming + bundle-scope rules from
 * `bundle-scoped-storage.md`. Verifies the EntityDiffFactory produces
 * a BundleLevelDiff that the Migrator can apply through the standard
 * compiler pipeline, materialising the subtable on disk.
 */
#[CoversNothing]
final class BundleSubtableDiffTest extends TestCase
{
    #[Test]
    public function bundleFieldsAddedToSubtableThroughFactoryAndMigrator(): void
    {
        $factory = new EntityDiffFactory();
        $type = new EntityType(id: 'group', label: 'Group', class: \stdClass::class, keys: ['id' => 'id']);

        $diff = $factory->forEntityType(
            $type,
            'group',
            [],
            [
                'team' => [
                    new FieldDefinition(name: 'name', type: 'string'),
                    new FieldDefinition(name: 'mascot', type: 'string'),
                    new FieldDefinition(name: 'member_count', type: 'integer'),
                ],
            ],
            new SchemaSnapshot(),
        );

        self::assertCount(1, $diff->bundleDiffs);
        $bundle = $diff->bundleDiffs[0];
        self::assertInstanceOf(BundleLevelDiff::class, $bundle);
        self::assertSame('group__team', $bundle->subtableName());
        self::assertCount(3, $bundle->composite->ops);

        // Apply through the Migrator on a fresh in-memory SQLite.
        [$connection, , $migrator] = self::harness();
        $connection->executeStatement('CREATE TABLE "group__team" (id INTEGER PRIMARY KEY)');

        $migrator->run([], [self::wrapCompositeAsV2('waaseyaa/test:v2:group-team', $bundle->composite)]);

        $cols = array_column(
            $connection->executeQuery('PRAGMA table_info("group__team")')->fetchAllAssociative(),
            'name',
        );
        self::assertContains('name', $cols);
        self::assertContains('mascot', $cols);
        self::assertContains('member_count', $cols);
    }

    #[Test]
    public function addingFourthBundleFieldProducesSingleAddColumnOpForNewField(): void
    {
        $factory = new EntityDiffFactory();
        $type = new EntityType(id: 'group', label: 'Group', class: \stdClass::class, keys: ['id' => 'id']);

        // Snapshot reflects the 3 fields already materialised on the
        // subtable from a prior apply.
        $snapshot = new SchemaSnapshot([
            'group__team' => [
                'name' => new ColumnSpec(type: 'varchar', nullable: true, length: 255),
                'mascot' => new ColumnSpec(type: 'varchar', nullable: true, length: 255),
                'member_count' => new ColumnSpec(type: 'int', nullable: true),
            ],
        ]);

        $diff = $factory->forEntityType(
            $type,
            'group',
            [],
            [
                'team' => [
                    new FieldDefinition(name: 'name', type: 'string'),
                    new FieldDefinition(name: 'mascot', type: 'string'),
                    new FieldDefinition(name: 'member_count', type: 'integer'),
                    new FieldDefinition(name: 'founded_at', type: 'integer'),
                ],
            ],
            $snapshot,
        );

        self::assertCount(1, $diff->bundleDiffs);
        self::assertCount(1, $diff->bundleDiffs[0]->composite->ops, 'Idempotency: only the new field should produce an op.');
        $op = $diff->bundleDiffs[0]->composite->ops[0];
        self::assertInstanceOf(AddColumn::class, $op);
        self::assertSame('founded_at', $op->column);
    }

    #[Test]
    public function emptyBundleProducesNoBundleLevelDiff(): void
    {
        $factory = new EntityDiffFactory();
        $type = new EntityType(id: 'group', label: 'Group', class: \stdClass::class, keys: ['id' => 'id']);

        $diff = $factory->forEntityType(
            $type,
            'group',
            [],
            ['empty_team' => []],
            new SchemaSnapshot(),
        );

        self::assertSame([], $diff->bundleDiffs, 'Empty subtable must NOT produce a BundleLevelDiff.');
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
