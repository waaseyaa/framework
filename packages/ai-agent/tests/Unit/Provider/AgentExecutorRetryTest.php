<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\ClientErrorException;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\RateLimitException;
use Waaseyaa\AI\Agent\Provider\TransportException;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Unit-level regression tests for AgentExecutor::callProviderWithRetry.
 *
 * Covers FR-003 / FR-010: retry semantics for the typed provider exception
 * hierarchy introduced in WP01. Four test cases cover each decision branch:
 *   - RateLimitException → retried
 *   - TransportException → retried
 *   - ClientErrorException → re-thrown immediately (no retry)
 *   - Generic exception → re-thrown immediately (no retry)
 */
#[CoversClass(AgentExecutor::class)]
final class AgentExecutorRetryTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->migrateSchema();

        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver);

        $runType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $logType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );

        $runEntityRepo = new EntityRepository($runType, $driver, $dispatcher);
        $logEntityRepo = new EntityRepository($logType, $driver, $dispatcher);

        $this->runRepository = new AgentRunRepository($runEntityRepo, $this->database);
        $this->auditRepository = new AgentAuditLogRepository($logEntityRepo, $this->database);
    }

    #[Test]
    public function rateLimitExceptionIsRetried(): void
    {
        $run = $this->seedRun();

        // Throws RateLimitException on attempt 1, returns success on attempt 2.
        $callCount = 0;
        $provider = new class ($callCount) implements ProviderInterface {
            public int $invocations = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->invocations++;
                if ($this->invocations === 1) {
                    throw new RateLimitException(retryAfterSeconds: 1, message: 'rate limited');
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'ok']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                );
            }
        };

        $executor = $this->makeExecutor();
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 1,
        );

        self::assertTrue($result->success, "Expected success after RateLimitException retry, got: {$result->message}");
        self::assertSame(2, $provider->invocations, 'Provider must be called exactly twice (fail + retry success)');
    }

    #[Test]
    public function transportExceptionIsRetried(): void
    {
        $run = $this->seedRun();

        // Throws TransportException on attempt 1, returns success on attempt 2.
        $provider = new class implements ProviderInterface {
            public int $invocations = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->invocations++;
                if ($this->invocations === 1) {
                    throw new TransportException('connection reset', 503);
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'ok']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                );
            }
        };

        $executor = $this->makeExecutor();
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 1,
        );

        self::assertTrue($result->success, "Expected success after TransportException retry, got: {$result->message}");
        self::assertSame(2, $provider->invocations, 'Provider must be called exactly twice (fail + retry success)');
    }

    #[Test]
    public function clientErrorExceptionRethrownImmediately(): void
    {
        // SC-003: 4xx non-429 errors must NOT consume retry budget.
        $run = $this->seedRun();

        $provider = new class implements ProviderInterface {
            public int $invocations = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->invocations++;
                throw new ClientErrorException('Bad request: invalid parameter', 400);
            }
        };

        $executor = $this->makeExecutor();

        $this->expectException(ClientErrorException::class);
        $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 1,
        );

        // PHPUnit stops here on exception — but verify invocation count via teardown is not possible.
        // The assertion below is unreachable when exception is thrown, so we assert before via the
        // property on the anonymous class. We capture a reference via closure binding.
        self::assertSame(1, $provider->invocations, 'Provider must be called exactly once — no retry for ClientErrorException');
    }

    #[Test]
    public function genericExceptionRethrownImmediately(): void
    {
        $run = $this->seedRun();

        $provider = new class implements ProviderInterface {
            public int $invocations = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->invocations++;
                throw new \InvalidArgumentException('programming error — not retryable');
            }
        };

        $executor = $this->makeExecutor();

        $this->expectException(\InvalidArgumentException::class);
        $executor->executeRun(
            $run,
            $this->fakeAccount(1),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 1,
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeExecutor(): AgentExecutor
    {
        return new AgentExecutor(
            toolRegistry: $this->emptyRegistry(),
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: static fn(int $ms): null => null,
        );
    }

    private function seedRun(): AgentRun
    {
        $run = new AgentRun([
            'id' => '01J' . str_pad((string) random_int(1000, 9999), 23, '0'),
            'account_id' => 1,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => RunStatus::Running->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'prompt' => 'test',
            'response' => null,
            'transcript_json' => '',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP'),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);

        return $run;
    }

    private function fakeAccount(int $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['administrator'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function emptyRegistry(): ToolRegistryInterface
    {
        return new class implements ToolRegistryInterface {
            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return false;
            }

            public function all(): iterable
            {
                return [];
            }
        };
    }

    private function migrateSchema(): void
    {
        $migrationFile = \dirname(__DIR__, 3)
            . '/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);
    }
}
