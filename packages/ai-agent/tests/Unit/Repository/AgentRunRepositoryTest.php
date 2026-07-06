<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

#[CoversClass(AgentRunRepository::class)]
#[CoversClass(AgentRun::class)]
final class AgentRunRepositoryTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();

        // Apply the package migration so the test mirrors production DDL.
        $migrationFile = \dirname(__DIR__, 3) . '/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof \Waaseyaa\Foundation\Migration\Migration);

        $schema = new \Waaseyaa\Foundation\Migration\SchemaBuilder($this->database->getConnection());
        $migration->up($schema);

        $entityType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = new EntityRepository(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        $this->repository = new AgentRunRepository($entityRepo, $this->database);
    }

    #[Test]
    public function saveAndFindRoundTripsTheCanonicalFields(): void
    {
        $run = $this->makeQueuedRun('run-1', 42, 'hello world');
        $this->repository->save($run);

        $loaded = $this->repository->find('run-1');

        self::assertNotNull($loaded);
        self::assertSame('run-1', $loaded->id());
        self::assertSame(42, $loaded->getAccountId());
        self::assertSame(RunStatus::Queued, $loaded->getStatus());
        self::assertSame(HitlMode::None, $loaded->getDestructiveApproval());
        self::assertSame('hello world', $loaded->get('prompt'));
    }

    #[Test]
    public function findReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->find('nope'));
    }

    #[Test]
    public function markRunningFlipsQueuedToRunningOnce(): void
    {
        $run = $this->makeQueuedRun('run-2', 1, 'go');
        $this->repository->save($run);

        $startedAt = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        self::assertTrue($this->repository->markRunning('run-2', $startedAt));
        // Second worker racing in must lose.
        self::assertFalse($this->repository->markRunning('run-2', $startedAt));

        $loaded = $this->repository->find('run-2');
        self::assertNotNull($loaded);
        self::assertSame(RunStatus::Running, $loaded->getStatus());
    }

    #[Test]
    public function markTerminalRefusesWhenStatusAlreadyTerminal(): void
    {
        $run = $this->makeQueuedRun('run-3', 1, 'work');
        $this->repository->save($run);

        $started = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');
        $finished = new \DateTimeImmutable('2026-05-18T12:01:00+00:00');

        self::assertTrue($this->repository->markRunning('run-3', $started));
        self::assertTrue($this->repository->markTerminal('run-3', RunStatus::Completed, $finished));

        // C-014: any further terminal attempt is rejected.
        self::assertFalse($this->repository->markTerminal('run-3', RunStatus::Failed, $finished, 'late', 'too late'));

        $loaded = $this->repository->find('run-3');
        self::assertNotNull($loaded);
        self::assertSame(RunStatus::Completed, $loaded->getStatus());
    }

    #[Test]
    public function markTerminalRejectsNonTerminalStatuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repository->markTerminal(
            'whatever',
            RunStatus::Running,
            new \DateTimeImmutable(),
        );
    }

    #[Test]
    public function markTerminalPersistsErrorMetadata(): void
    {
        $run = $this->makeQueuedRun('run-4', 1, 'doomed');
        $this->repository->save($run);

        self::assertTrue($this->repository->markRunning('run-4', new \DateTimeImmutable('2026-05-18T12:00:00+00:00')));
        self::assertTrue($this->repository->markTerminal(
            'run-4',
            RunStatus::Failed,
            new \DateTimeImmutable('2026-05-18T12:00:30+00:00'),
            errorCode: 'provider_rate_limited',
            errorMessage: 'Anthropic 429',
        ));

        $loaded = $this->repository->find('run-4');
        self::assertNotNull($loaded);
        self::assertSame(RunStatus::Failed, $loaded->getStatus());
        self::assertSame('provider_rate_limited', $loaded->get('error_code'));
        self::assertSame('Anthropic 429', $loaded->get('error_message'));
    }

    #[Test]
    public function findStuckRunningReturnsRunsStartedBeforeThreshold(): void
    {
        $oldStart = new \DateTimeImmutable('2026-05-18T10:00:00+00:00');
        $freshStart = new \DateTimeImmutable('2026-05-18T11:59:50+00:00');
        $threshold = new \DateTimeImmutable('2026-05-18T11:00:00+00:00');

        $old = $this->makeQueuedRun('stuck-old', 1, 'p');
        $fresh = $this->makeQueuedRun('stuck-fresh', 1, 'p');
        $this->repository->save($old);
        $this->repository->save($fresh);
        self::assertTrue($this->repository->markRunning('stuck-old', $oldStart));
        self::assertTrue($this->repository->markRunning('stuck-fresh', $freshStart));

        $stuck = $this->repository->findStuckRunning($threshold);

        self::assertCount(1, $stuck);
        self::assertSame('stuck-old', $stuck[0]->id());
    }

    #[Test]
    public function findOldByQueuedAtReturnsTerminalRunsQueuedBeforeThreshold(): void
    {
        $old = $this->makeQueuedRun(
            'old',
            1,
            'p',
            queuedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            status: RunStatus::Completed,
        );
        $fresh = $this->makeQueuedRun(
            'fresh',
            1,
            'p',
            queuedAt: new \DateTimeImmutable('2026-05-18T00:00:00+00:00'),
            status: RunStatus::Completed,
        );

        $this->repository->save($old);
        $this->repository->save($fresh);

        $result = $this->repository->findOldByQueuedAt(new \DateTimeImmutable('2026-03-01T00:00:00+00:00'));

        self::assertCount(1, $result);
        self::assertSame('old', $result[0]->id());
    }

    #[Test]
    public function findOldByQueuedAtExcludesNonTerminalRunsRegardlessOfAge(): void
    {
        // Derive the non-terminal set from the enum itself so a future case
        // added to RunStatus is covered here automatically.
        $nonTerminals = array_values(array_filter(
            RunStatus::cases(),
            static fn(RunStatus $status): bool => !$status->isTerminal(),
        ));
        self::assertNotEmpty($nonTerminals);

        foreach ($nonTerminals as $status) {
            $run = $this->makeQueuedRun(
                'old-' . $status->value,
                1,
                'p',
                queuedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                status: $status,
            );
            $this->repository->save($run);
        }

        $result = $this->repository->findOldByQueuedAt(new \DateTimeImmutable('2026-03-01T00:00:00+00:00'));

        self::assertSame([], $result);
    }

    private function makeQueuedRun(
        string $id,
        int $accountId,
        string $prompt,
        ?\DateTimeImmutable $queuedAt = null,
        RunStatus $status = RunStatus::Queued,
    ): AgentRun {
        $queuedAt ??= new \DateTimeImmutable('2026-05-18T11:30:00+00:00');

        $run = new AgentRun([
            'id' => $id,
            'account_id' => $accountId,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => $status->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => $prompt,
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => $queuedAt->format('Y-m-d H:i:s.uP'),
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);

        return $run;
    }
}
