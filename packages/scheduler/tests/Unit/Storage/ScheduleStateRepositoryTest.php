<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Storage;

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
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

#[CoversClass(ScheduleStateRepository::class)]
final class ScheduleStateRepositoryTest extends TestCase
{
    private DBALDatabase $database;
    private ScheduleStateRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->query('
            CREATE TABLE waaseyaa_schedule_state (
                task_name VARCHAR(255) PRIMARY KEY,
                last_run_at VARCHAR(50) NOT NULL,
                last_result TEXT NOT NULL
            )
        ');
        $this->repository = new ScheduleStateRepository($this->database);
    }

    #[Test]
    public function recordRun_inserts_new_task(): void
    {
        $this->repository->recordRun('my_task', 'success');

        $state = $this->repository->getState('my_task');
        self::assertNotNull($state);
        self::assertSame('my_task', $state['task_name']);
        self::assertSame('success', $state['last_result']);
    }

    #[Test]
    public function recordRun_updates_existing_task(): void
    {
        $this->repository->recordRun('my_task', 'success');
        $this->repository->recordRun('my_task', 'failure');

        $state = $this->repository->getState('my_task');
        self::assertNotNull($state);
        self::assertSame('failure', $state['last_result']);
    }

    #[Test]
    public function recordRun_does_not_duplicate_rows(): void
    {
        $this->repository->recordRun('my_task', 'success');
        $this->repository->recordRun('my_task', 'failure');
        $this->repository->recordRun('my_task', 'success');

        $rows = iterator_to_array($this->database->query(
            'SELECT COUNT(*) as cnt FROM waaseyaa_schedule_state WHERE task_name = ?',
            ['my_task'],
        ));
        self::assertSame(1, (int) $rows[0]['cnt']);
    }

    #[Test]
    public function getState_returns_null_for_unknown_task(): void
    {
        self::assertNull($this->repository->getState('nonexistent'));
    }

    #[Test]
    public function recordRun_does_not_emit_sqlite_only_insert_or_replace(): void
    {
        // `INSERT OR REPLACE` is SQLite-only syntax that throws a syntax error
        // on MySQL/Postgres. The upsert must go through the portable query
        // builders, so no raw `INSERT OR REPLACE` statement is issued.
        $recorder = new RecordingDatabase($this->database);
        $repository = new ScheduleStateRepository($recorder);

        $repository->recordRun('portable_task', 'success');   // insert path
        $repository->recordRun('portable_task', 'failure');   // update path

        foreach ($recorder->rawQueries as $sql) {
            self::assertDoesNotMatchRegularExpression(
                '/INSERT\s+OR\s+REPLACE/i',
                $sql,
                'recordRun() must not emit SQLite-only INSERT OR REPLACE.',
            );
        }

        // The portable upsert still produces exactly one, up-to-date row.
        self::assertSame('failure', $repository->getState('portable_task')['last_result']);
    }
}

/**
 * Wraps a real database, delegating every operation, but recording the SQL of
 * any raw {@see DatabaseInterface::query()} call so a test can assert no
 * vendor-specific statement is emitted.
 */
final class RecordingDatabase implements DatabaseInterface
{
    /** @var list<string> */
    public array $rawQueries = [];

    public function __construct(private readonly DatabaseInterface $inner) {}

    public function query(string $sql, array $args = []): \Traversable
    {
        $this->rawQueries[] = $sql;

        return $this->inner->query($sql, $args);
    }

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

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }
}
