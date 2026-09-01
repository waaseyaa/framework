<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\SchemaSyncHandler;
use Waaseyaa\CLI\Provider\HealthSchemaServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/**
 * Regression coverage for #2732: `schema:sync --dry-run` and the real command
 * used to describe a table that already existed as fully in sync, even when
 * synchronizing it against the current definition would add — or, on apply,
 * did add — a column and an index. The report's `changed()`/`created` model
 * only tracked brand-new tables; additive work on an existing table was
 * silently folded into "already exist(s)".
 *
 * Every assertion here drives the real, wired-up `schema:sync` command
 * (definition pulled from {@see HealthSchemaServiceProvider} itself, so this
 * cannot drift from production registration) against a real SQLite database
 * and a real {@see SqlColumnSchemaBuilder}-backed entity type — the same path
 * the issue's repro exercised.
 */
#[CoversClass(SchemaSyncHandler::class)]
final class SchemaSyncHandlerTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
    }

    #[Test]
    public function dry_run_reports_a_pending_column_and_index_on_an_existing_table_as_changed(): void
    {
        $this->runSchemaSync($this->managerWithProbe([]));

        $tester = $this->runSchemaSync(
            $this->managerWithProbe(['facet' => $this->facetField()]),
            ['--dry-run'],
        );

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('would alter', $stdout);
        self::assertStringContainsString('report_probe', $stdout);
        self::assertStringNotContainsString(
            'Nothing to change',
            $stdout,
            'a dry run with real pending work must not claim nothing to do',
        );
        self::assertFalse(
            $this->database->schema()->fieldExists('report_probe', 'facet'),
            'dry run must not write the column',
        );
    }

    #[Test]
    public function apply_adds_the_column_and_reports_the_table_as_altered_not_untouched(): void
    {
        $this->runSchemaSync($this->managerWithProbe([]));

        $tester = $this->runSchemaSync($this->managerWithProbe(['facet' => $this->facetField()]));

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $stdout = $tester->getStdout();
        self::assertStringContainsString('Altered', $stdout);
        self::assertStringContainsString('report_probe', $stdout);
        self::assertStringNotContainsString(
            'Schema in sync',
            $stdout,
            'an apply that actually added a column must not claim the schema was already in sync',
        );
        self::assertTrue($this->database->schema()->fieldExists('report_probe', 'facet'));
    }

    #[Test]
    public function a_true_no_op_run_still_reports_in_sync(): void
    {
        $manager = $this->managerWithProbe(['facet' => $this->facetField()]);
        $this->runSchemaSync($manager);

        $tester = $this->runSchemaSync($this->managerWithProbe(['facet' => $this->facetField()]));

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Schema in sync', $tester->getStdout());
    }

    /**
     * #2732 follow-up — the review defect: an existing table on a platform
     * that cannot preview pending column/index work used to print "would
     * alter", claiming certainty that does not exist. Forced deterministically
     * via a plain `DatabaseInterface` wrapper that is not a `DBALDatabase` —
     * the same "cannot introspect read-only" state a real MySQL/MariaDB/
     * PostgreSQL connection is in.
     */
    #[Test]
    public function dry_run_reports_pending_work_as_indeterminate_when_the_platform_cannot_preview(): void
    {
        $this->runSchemaSync($this->managerWithProbe([]));

        $tester = $this->runSchemaSync(
            $this->managerWithProbe(['facet' => $this->facetField()]),
            ['--dry-run'],
            $this->nonDbalProxy($this->database),
        );

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $stdout = $tester->getStdout();
        self::assertStringContainsString(
            'cannot be previewed on this database platform',
            $stdout,
        );
        self::assertStringContainsString('report_probe', $stdout);
        self::assertStringNotContainsString(
            'would alter',
            $stdout,
            'an unpreviewable platform must not be reported as a confirmed alteration',
        );
        self::assertStringNotContainsString(
            'Nothing to change',
            $stdout,
            'a dry run that cannot rule out pending work must not claim nothing to do',
        );
        self::assertFalse(
            $this->database->schema()->fieldExists('report_probe', 'facet'),
            'dry run must not write the column',
        );
    }

    /**
     * The mirror case for a real (non-dry-run) apply: forced here via an
     * already-active {@see SchemaMutationCoordinator} on the connection.
     * Unlike the non-DBAL wrapper above, this keeps a real {@see DBALDatabase},
     * so the command genuinely runs — proving the apply still completes and
     * honestly reports what it could and could not confirm, instead of
     * claiming the table was "Altered" or the schema was already "in sync".
     */
    #[Test]
    public function apply_still_runs_and_reports_indeterminate_when_a_mutation_is_already_active(): void
    {
        $this->runSchemaSync($this->managerWithProbe([]));

        $connection = $this->database->getConnection();
        $coordinator = new SchemaMutationCoordinator($connection, new MigrationRepository($connection));

        $tester = $coordinator->execute(
            fn() => $this->runSchemaSync($this->managerWithProbe(['facet' => $this->facetField()])),
        );

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $stdout = $tester->getStdout();
        self::assertStringContainsString(
            'could not be previewed on this database platform',
            $stdout,
        );
        self::assertStringContainsString('report_probe', $stdout);
        self::assertStringNotContainsString('Altered', $stdout);
        self::assertStringNotContainsString(
            'Schema in sync',
            $stdout,
            'a run that could not confirm pending work must not claim the schema was already in sync',
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

    private function facetField(): FieldDefinition
    {
        return new FieldDefinition(
            name: 'facet',
            type: 'string',
            targetEntityTypeId: 'report_probe',
            fieldIndexed: true,
        );
    }

    /** @param array<string, FieldDefinition> $fields */
    private function managerWithProbe(array $fields): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $manager->registerEntityType(new EntityType(
            id: 'report_probe',
            label: 'Probe',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            primaryStorageBackend: 'sql-column',
            _fieldDefinitions: $fields,
        ));

        return $manager;
    }

    /** @param list<string> $argv */
    private function runSchemaSync(
        EntityTypeManager $manager,
        array $argv = [],
        ?DatabaseInterface $database = null,
    ): CliTester {
        $handler = new SchemaSyncHandler($manager, $database ?? $this->database);

        $definition = null;
        foreach (new HealthSchemaServiceProvider()->consoleCommands() as $command) {
            if ($command->name === 'schema:sync') {
                $definition = $command;
                break;
            }
        }
        self::assertInstanceOf(HandlerCommand::class, $definition, 'schema:sync command not found in provider');

        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly SchemaSyncHandler $handler) {}

            public function get(string $id): mixed
            {
                if ($id === SchemaSyncHandler::class) {
                    return $this->handler;
                }
                throw new \RuntimeException('Not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === SchemaSyncHandler::class;
            }
        };

        return CliTester::for($definition, $container)->execute($argv);
    }
}
