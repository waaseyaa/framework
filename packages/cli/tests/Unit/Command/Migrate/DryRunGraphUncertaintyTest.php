<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Migrate;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\Migrate\DryRunNode;
use Waaseyaa\CLI\Command\Migrate\DryRunPlanner;
use Waaseyaa\CLI\Command\Migrate\DryRunResult;
use Waaseyaa\Foundation\Migration\Executor\OpPreconditionResolver;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Diff\RenameTable;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * Uncertainty must accumulate across the whole ordered migration graph, not
 * within a single migration.
 *
 * These cases attack the model rather than replay a reported example: an opaque
 * legacy migration ahead of a v2 node, the same migration behind it, a node that
 * is already applied and therefore changes nothing, a rename in one migration
 * that a later migration builds on, and unrelated tables that must NOT infect
 * one another.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(DryRunPlanner::class)]
#[CoversClass(DryRunNode::class)]
#[CoversClass(DryRunResult::class)]
final class DryRunGraphUncertaintyTest extends TestCase
{
    #[Test]
    public function a_pending_legacy_migration_ahead_of_a_v2_node_makes_it_uncertain(): void
    {
        // The legacy up() body is imperative and opaque, so nothing after it can
        // be resolved against the live snapshot.
        $nodes = $this->plan(
            legacy: ['aaa/legacy' => ['001_legacy' => self::legacyMigration()]],
            v2: [self::v2Adding('zzz/app', 'widgets', 'archived_at')],
            seed: 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, archived_at TEXT)',
        );

        self::assertTrue($this->node($nodes, 'zzz/app:v2:add')->stateDependent);
        self::assertCount(
            1,
            $this->node($nodes, 'zzz/app:v2:add')->steps,
            'an uncertain operation is preserved, never filtered away',
        );
    }

    #[Test]
    public function a_pending_legacy_migration_behind_a_v2_node_leaves_it_certain(): void
    {
        $nodes = $this->plan(
            legacy: ['zzz/legacy' => ['999_legacy' => self::legacyMigration()]],
            v2: [self::v2Adding('aaa/app', 'widgets', 'archived_at')],
            seed: 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, archived_at TEXT)',
        );

        $node = $this->node($nodes, 'aaa/app:v2:add');
        self::assertFalse($node->stateDependent, 'ordering matters: it runs before the opaque migration');
        self::assertSame([], $node->steps, 'the column already exists, so nothing would run');
    }

    #[Test]
    public function an_already_applied_legacy_migration_does_not_make_later_nodes_uncertain(): void
    {
        // Its effects are already in the live database the snapshot describes.
        $nodes = $this->plan(
            legacy: ['aaa/legacy' => ['001_legacy' => self::legacyMigration()]],
            v2: [self::v2Adding('zzz/app', 'widgets', 'archived_at')],
            seed: 'CREATE TABLE widgets (id INTEGER PRIMARY KEY, archived_at TEXT)',
            applied: ['001_legacy'],
        );

        self::assertFalse($this->node($nodes, 'zzz/app:v2:add')->stateDependent);
    }

    #[Test]
    public function a_rename_in_an_earlier_migration_makes_a_later_one_uncertain(): void
    {
        // The second migration targets a table the first produces. Judged against
        // the live snapshot alone it would be resolved against a table that does
        // not exist yet.
        $nodes = $this->plan(
            legacy: [],
            v2: [
                self::v2Renaming('aaa/app', 'old_widgets', 'widgets'),
                self::v2Adding('zzz/app', 'widgets', 'archived_at'),
            ],
            seed: 'CREATE TABLE old_widgets (id INTEGER PRIMARY KEY)',
        );

        self::assertFalse($this->node($nodes, 'aaa/app:v2:rename')->stateDependent);
        self::assertTrue($this->node($nodes, 'zzz/app:v2:add')->stateDependent);
    }

    #[Test]
    public function migrations_touching_unrelated_tables_do_not_infect_one_another(): void
    {
        // Uncertainty is per-table, not global. Over-marking would make every
        // multi-migration plan unpreviewable.
        $nodes = $this->plan(
            legacy: [],
            v2: [
                self::v2Adding('aaa/app', 'widgets', 'archived_at'),
                self::v2Adding('zzz/app', 'gadgets', 'archived_at'),
            ],
            seed: 'CREATE TABLE widgets (id INTEGER PRIMARY KEY); CREATE TABLE gadgets (id INTEGER PRIMARY KEY)',
        );

        self::assertFalse($this->node($nodes, 'aaa/app:v2:add')->stateDependent);
        self::assertFalse($this->node($nodes, 'zzz/app:v2:add')->stateDependent);
        self::assertCount(1, $this->node($nodes, 'aaa/app:v2:add')->steps);
        self::assertCount(1, $this->node($nodes, 'zzz/app:v2:add')->steps);
    }

    /**
     * @param array<string, array<string, Migration>> $legacy
     * @param list<MigrationInterfaceV2>              $v2
     * @param list<string>                            $applied
     * @return list<DryRunNode>
     */
    private function plan(array $legacy, array $v2, string $seed, array $applied = []): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (explode(';', $seed) as $statement) {
            if (trim($statement) !== '') {
                $connection->executeStatement($statement);
            }
        }
        $repository = new MigrationRepository($connection);
        $repository->installOrUpgradeLedger();
        foreach ($applied as $id) {
            $repository->record($id, 'aaa/legacy', 1);
        }

        return new DryRunPlanner(
            $repository,
            SqliteCompiler::forVersion('3.40.0'),
            new PlanPolicy(),
            new OpPreconditionResolver($connection),
        )->plan($legacy, $v2)->nodes;
    }

    /** @param list<DryRunNode> $nodes */
    private function node(array $nodes, string $id): DryRunNode
    {
        foreach ($nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        self::fail(sprintf('node "%s" is absent from the plan', $id));
    }

    private static function legacyMigration(): Migration
    {
        return new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                // Intentionally opaque: preview cannot know what this does.
            }
        };
    }

    private static function v2Adding(string $package, string $table, string $column): MigrationInterfaceV2
    {
        return new class ($package, $table, $column) implements MigrationInterfaceV2 {
            public function __construct(
                private string $package,
                private string $table,
                private string $column,
            ) {}

            public function migrationId(): string
            {
                return $this->package . ':v2:add';
            }

            public function package(): string
            {
                return $this->package;
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package,
                    dependencies: [],
                    root: new CompositeDiff([
                        new AddColumn($this->table, $this->column, new ColumnSpec(type: 'text', nullable: true)),
                    ]),
                );
            }
        };
    }

    private static function v2Renaming(string $package, string $from, string $to): MigrationInterfaceV2
    {
        return new class ($package, $from, $to) implements MigrationInterfaceV2 {
            public function __construct(
                private string $package,
                private string $from,
                private string $to,
            ) {}

            public function migrationId(): string
            {
                return $this->package . ':v2:rename';
            }

            public function package(): string
            {
                return $this->package;
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package,
                    dependencies: [],
                    root: new CompositeDiff([new RenameTable($this->from, $this->to)]),
                );
            }
        };
    }
}
