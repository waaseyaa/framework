<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\CoordinatedEntitySchemaExecutor;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\SchemaSyncReport;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

#[CoversClass(EntitySchemaSyncRunner::class)]
#[CoversClass(SchemaSyncReport::class)]
#[CoversClass(CoordinatedEntitySchemaExecutor::class)]
final class EntitySchemaSyncRunnerTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    #[Test]
    public function it_creates_missing_tables_and_reports_them(): void
    {
        $defs = [
            $this->makeEntityType('widget', 'Widget'),
            $this->makeEntityType('gadget', 'Gadget'),
        ];

        $report = new EntitySchemaSyncRunner($this->database)->run($defs);

        $this->assertSame(['gadget', 'widget'], $report->created, 'both tables reported created (sorted)');
        $this->assertSame([], $report->existing);
        $this->assertTrue($report->changed());
        $this->assertSame(2, $report->total());
        $this->assertFalse($report->dryRun);
        $this->assertTrue($this->database->schema()->tableExists('widget'));
        $this->assertTrue($this->database->schema()->tableExists('gadget'));
    }

    #[Test]
    public function a_second_run_is_an_idempotent_no_op(): void
    {
        $defs = [$this->makeEntityType('widget', 'Widget')];
        $runner = new EntitySchemaSyncRunner($this->database);

        $runner->run($defs);
        $report = $runner->run($defs);

        $this->assertSame([], $report->created, 'nothing created on the second run');
        $this->assertSame(['widget'], $report->existing);
        $this->assertFalse($report->changed());
        $this->assertTrue($this->database->schema()->tableExists('widget'));
    }

    #[Test]
    public function dry_run_reports_what_would_be_created_without_writing(): void
    {
        $defs = [$this->makeEntityType('widget', 'Widget')];

        $report = new EntitySchemaSyncRunner($this->database)->run($defs, dryRun: true);

        $this->assertSame(['widget'], $report->created);
        $this->assertTrue($report->dryRun);
        $this->assertFalse(
            $this->database->schema()->tableExists('widget'),
            'dry run must not create the table',
        );
    }

    #[Test]
    public function mixed_run_separates_created_from_existing(): void
    {
        $runner = new EntitySchemaSyncRunner($this->database);
        $runner->run([$this->makeEntityType('widget', 'Widget')]);

        $report = $runner->run([
            $this->makeEntityType('widget', 'Widget'),
            $this->makeEntityType('gadget', 'Gadget'),
        ]);

        $this->assertSame(['gadget'], $report->created);
        $this->assertSame(['widget'], $report->existing);
    }

    /**
     * #2732 — the reported repro: an entity type gets a new non-key field
     * (with an index) registered against an already-materialized table.
     */
    #[Test]
    public function dry_run_reports_a_column_and_index_that_would_be_added_to_an_existing_table(): void
    {
        new EntitySchemaSyncRunner($this->database)->run([$this->makeSqlColumnEntityType('report_probe', [])]);

        $evolved = $this->makeSqlColumnEntityType('report_probe', [
            'facet' => new FieldDefinition(
                name: 'facet',
                type: 'string',
                targetEntityTypeId: 'report_probe',
                fieldIndexed: true,
            ),
        ]);

        $report = new EntitySchemaSyncRunner($this->database)->run([$evolved], dryRun: true);

        $this->assertSame([], $report->created, 'the table already exists; it is not "created"');
        $this->assertSame(['report_probe'], $report->existing);
        $this->assertSame(['report_probe'], $report->altered, 'the additive column+index is real work, not a no-op');
        $this->assertSame([], $report->unchanged());
        $this->assertTrue($report->changed(), 'a dry run that would add a column must report changed() === true');
        $this->assertFalse(
            $this->database->schema()->fieldExists('report_probe', 'facet'),
            'dry run must not write the column',
        );
    }

    #[Test]
    public function apply_adds_the_column_and_index_and_reports_the_table_as_altered(): void
    {
        new EntitySchemaSyncRunner($this->database)->run([$this->makeSqlColumnEntityType('report_probe', [])]);

        $evolved = $this->makeSqlColumnEntityType('report_probe', [
            'facet' => new FieldDefinition(
                name: 'facet',
                type: 'string',
                targetEntityTypeId: 'report_probe',
                fieldIndexed: true,
            ),
        ]);

        $report = new EntitySchemaSyncRunner($this->database)->run([$evolved]);

        $this->assertSame([], $report->created);
        $this->assertSame(['report_probe'], $report->existing);
        $this->assertSame(['report_probe'], $report->altered);
        $this->assertTrue($report->changed());
        $this->assertTrue(
            $this->database->schema()->fieldExists('report_probe', 'facet'),
            'apply must actually add the column',
        );

        // A subsequent run against the now-satisfied definition is a true no-op.
        $noop = new EntitySchemaSyncRunner($this->database)->run([$evolved]);
        $this->assertSame([], $noop->altered);
        $this->assertFalse($noop->changed());
    }

    /**
     * #2732 follow-up — the review defect: `requiresMutation()` returns `true`
     * unconditionally off SQLite (production MySQL/MariaDB/PostgreSQL), which
     * used to make every pre-existing table report as `altered` even when
     * nothing changed. Forced deterministically here via a plain
     * `DatabaseInterface` wrapper that is not a `DBALDatabase` — the same
     * "cannot introspect read-only" state `CoordinatedEntitySchemaExecutor`
     * falls back to for a real non-SQLite connection.
     */
    #[Test]
    public function dry_run_reports_existing_tables_as_indeterminate_when_the_platform_cannot_preview(): void
    {
        new EntitySchemaSyncRunner($this->database)->run([$this->makeEntityType('widget', 'Widget')]);

        $opaque = $this->nonDbalProxy($this->database);
        $report = new EntitySchemaSyncRunner($opaque)->run(
            [$this->makeEntityType('widget', 'Widget')],
            dryRun: true,
        );

        $this->assertSame([], $report->created);
        $this->assertSame(['widget'], $report->existing);
        $this->assertSame([], $report->altered, 'an indeterminate id must not be folded into altered');
        $this->assertSame(['widget'], $report->indeterminate);
        $this->assertSame([], $report->unchanged(), 'an indeterminate id is not a confirmed no-op either');
        $this->assertFalse($report->changed(), 'indeterminacy alone must not report changed() === true');
    }

    /**
     * The mirror case for a real (non-dry-run) apply: forced here via an
     * already-active {@see SchemaMutationCoordinator} on the connection —
     * another state `requiresMutation()` conservatively treats as "assume
     * mutation" because it cannot safely introspect mid-transition. Unlike
     * the non-DBAL wrapper above, this keeps a real {@see DBALDatabase}, so
     * the sync genuinely executes — proving the run still completes and the
     * report still tells the truth about what could and could not be
     * confirmed, rather than crashing or lying.
     */
    #[Test]
    public function apply_still_runs_for_real_and_reports_indeterminate_when_a_mutation_is_already_active(): void
    {
        $runner = new EntitySchemaSyncRunner($this->database);
        $runner->run([$this->makeEntityType('widget', 'Widget')]);

        $connection = $this->database->getConnection();
        $coordinator = new SchemaMutationCoordinator($connection, new MigrationRepository($connection));

        $report = $coordinator->execute(fn() => $runner->run([$this->makeEntityType('widget', 'Widget')]));

        $this->assertFalse($report->dryRun);
        $this->assertSame([], $report->created);
        $this->assertSame(['widget'], $report->existing);
        $this->assertSame([], $report->altered);
        $this->assertSame(['widget'], $report->indeterminate);
        $this->assertFalse($report->changed(), 'indeterminacy alone must not report changed() === true');
        $this->assertTrue(
            $this->database->schema()->tableExists('widget'),
            'the run must actually execute against the real database, not merely preview',
        );
    }

    private function nonDbalProxy(DatabaseInterface $inner): DatabaseInterface
    {
        return new class ($inner) implements DatabaseInterface {
            public function __construct(private readonly DatabaseInterface $inner) {}

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $this->inner->select($table, $alias);
            }

            public function insert(string $table): InsertInterface
            {
                return $this->inner->insert($table);
            }

            public function update(string $table): UpdateInterface
            {
                return $this->inner->update($table);
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }

            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->inner->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };
    }

    /** @param array<string, FieldDefinition> $fields */
    private function makeSqlColumnEntityType(string $id, array $fields): EntityType
    {
        return new EntityType(
            id: $id,
            label: 'Probe',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            primaryStorageBackend: 'sql-column',
            _fieldDefinitions: $fields,
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
}
