<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
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
}
