<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

#[CoversClass(AgentAuditLogRepository::class)]
#[CoversClass(AgentAuditLog::class)]
final class AgentAuditLogRepositoryTest extends TestCase
{
    private DBALDatabase $database;
    private AgentAuditLogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();

        $migrationFile = \dirname(__DIR__, 3) . '/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof \Waaseyaa\Foundation\Migration\Migration);

        $schema = new \Waaseyaa\Foundation\Migration\SchemaBuilder($this->database->getConnection());
        $migration->up($schema);

        $entityType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log entry',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        $this->repository = new AgentAuditLogRepository($entityRepo, $this->database);
    }

    #[Test]
    public function appendAndReadBackByRunIdOrdersByOccurredAt(): void
    {
        $base = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        $first = AgentAuditLog::for(
            id: 'evt-1',
            runId: 'run-1',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: $base,
        );
        $second = AgentAuditLog::for(
            id: 'evt-2',
            runId: 'run-1',
            iteration: 0,
            eventType: EventType::ToolCall,
            occurredAt: $base->modify('+1 second'),
            toolName: 'entity.read',
        );

        $this->repository->append($second); // intentionally inserted out of order
        $this->repository->append($first);

        $rows = $this->repository->findByRunId('run-1');

        self::assertCount(2, $rows);
        self::assertSame('evt-1', $rows[0]->id());
        self::assertSame('evt-2', $rows[1]->id());
    }

    #[Test]
    public function findByRunIdReturnsEmptyForUnknownRun(): void
    {
        $log = AgentAuditLog::for(
            id: 'evt-x',
            runId: 'run-a',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: new \DateTimeImmutable(),
        );
        $this->repository->append($log);

        self::assertSame([], $this->repository->findByRunId('run-b'));
    }

    #[Test]
    public function purgeOlderThanRemovesRowsAndReturnsCount(): void
    {
        $old = AgentAuditLog::for(
            id: 'evt-old',
            runId: 'run-1',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $fresh = AgentAuditLog::for(
            id: 'evt-fresh',
            runId: 'run-1',
            iteration: 0,
            eventType: EventType::IterationStart,
            occurredAt: new \DateTimeImmutable('2026-05-18T00:00:00+00:00'),
        );

        $this->repository->append($old);
        $this->repository->append($fresh);

        $purged = $this->repository->purgeOlderThan(new \DateTimeImmutable('2026-03-01T00:00:00+00:00'));

        self::assertSame(1, $purged);
        $rows = $this->repository->findByRunId('run-1');
        self::assertCount(1, $rows);
        self::assertSame('evt-fresh', $rows[0]->id());
    }

    #[Test]
    public function purgeOlderThanProtectsAuditRowsForLiveRuns(): void
    {
        $this->database->getConnection()->insert('agent_run', [
            'id' => 'run-live',
            'account_id' => 1,
            'bundle_json' => '{}',
            'status' => 'awaiting_approval',
            'destructive_approval' => 'interactive',
            'prompt' => 'waiting',
            'transcript_json' => '[]',
            'queued_at' => '2026-01-01 00:00:00.000000+00:00',
        ]);
        $this->database->getConnection()->insert('agent_run', [
            'id' => 'run-terminal',
            'account_id' => 1,
            'bundle_json' => '{}',
            'status' => 'completed',
            'destructive_approval' => 'none',
            'prompt' => 'done',
            'transcript_json' => '[]',
            'queued_at' => '2026-01-01 00:00:00.000000+00:00',
        ]);

        foreach (['run-live' => 'evt-live', 'run-terminal' => 'evt-terminal'] as $runId => $eventId) {
            $this->repository->append(AgentAuditLog::for(
                id: $eventId,
                runId: $runId,
                iteration: 0,
                eventType: EventType::IterationStart,
                occurredAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ));
        }

        self::assertSame(1, $this->repository->purgeOlderThan(
            new \DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        ));
        self::assertCount(1, $this->repository->findByRunId('run-live'));
        self::assertSame([], $this->repository->findByRunId('run-terminal'));
    }
}
