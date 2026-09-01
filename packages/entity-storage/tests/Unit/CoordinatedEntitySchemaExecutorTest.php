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
use Waaseyaa\EntityStorage\CoordinatedEntitySchemaExecutor;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/**
 * Planning puts the connection into `PRAGMA query_only` and marks it as
 * planning. A refusal is the expected way out, but a transition can also fail
 * for its own reasons, and that must leave nothing behind: a connection stuck
 * in query-only mode would make every later write fail, and a stale planning
 * mark would let nested schema work skip the coordinator. (#2452)
 */
#[CoversClass(CoordinatedEntitySchemaExecutor::class)]
final class CoordinatedEntitySchemaExecutorTest extends TestCase
{
    #[Test]
    public function a_plan_that_fails_for_its_own_reasons_leaves_a_writable_connection(): void
    {
        $database = DBALDatabase::createSqlite();
        $executor = new CoordinatedEntitySchemaExecutor($database);

        $caught = null;
        try {
            $executor->requiresMutation(static function (): void {
                throw new \RuntimeException('the transition itself failed');
            });
        } catch (\Throwable $failure) {
            $caught = $failure;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught, 'the failure must reach the caller unchanged');
        $this->assertSame('the transition itself failed', $caught->getMessage());
        $this->assertFalse($executor->isActive(), 'a failed plan must not leave the connection marked as planning');
        $this->assertSame(
            0,
            (int) $database->getConnection()->fetchOne('PRAGMA query_only'),
            'a failed plan must restore the connection it borrowed',
        );

        $database->schema()->createTable('written_after_a_failed_plan', ['fields' => ['id' => ['type' => 'int']]]);
        $this->assertTrue($database->schema()->tableExists('written_after_a_failed_plan'));
    }

    #[Test]
    public function a_plan_that_fails_preserves_a_callers_query_only_mode(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('PRAGMA query_only = ON');
        $executor = new CoordinatedEntitySchemaExecutor($database);

        try {
            $executor->requiresMutation(static function (): void {
                throw new \LogicException('the transition itself failed');
            });
        } catch (\LogicException) {
            // Asserted by the state checks below.
        }

        $this->assertSame(
            1,
            (int) $database->getConnection()->fetchOne('PRAGMA query_only'),
            'a caller that was already query-only must stay query-only',
        );
        $this->assertFalse($executor->isActive());
    }

    /**
     * #2732 — `canPreviewMutation()` must agree with `requiresMutation()`'s own
     * fallback conditions: true only for a DBAL-backed SQLite connection with
     * no mutation already active. Every branch that makes `requiresMutation()`
     * conservatively return `true` without running the transition must make
     * `canPreviewMutation()` return `false`.
     */
    #[Test]
    public function can_preview_mutation_is_true_only_for_an_idle_sqlite_dbal_connection(): void
    {
        $database = DBALDatabase::createSqlite();
        $executor = new CoordinatedEntitySchemaExecutor($database);

        $this->assertTrue($executor->canPreviewMutation());
    }

    #[Test]
    public function can_preview_mutation_is_false_for_a_non_dbal_database(): void
    {
        $database = DBALDatabase::createSqlite();
        $opaque = new class ($database) implements DatabaseInterface {
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

        $executor = new CoordinatedEntitySchemaExecutor($opaque);

        $this->assertFalse($executor->canPreviewMutation());
        $this->assertTrue(
            $executor->requiresMutation(static fn() => null),
            'the conservative requiresMutation() fallback must still hold for a non-DBAL database',
        );
    }

    #[Test]
    public function can_preview_mutation_is_false_while_a_mutation_is_already_active(): void
    {
        $database = DBALDatabase::createSqlite();
        $executor = new CoordinatedEntitySchemaExecutor($database);
        $connection = $database->getConnection();
        $coordinator = new SchemaMutationCoordinator($connection, new MigrationRepository($connection));

        $coordinator->execute(function () use ($executor): void {
            $this->assertFalse($executor->canPreviewMutation());
        });
    }
}
