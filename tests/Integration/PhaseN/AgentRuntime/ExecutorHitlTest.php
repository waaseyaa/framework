<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\RateLimitException;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
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
 * Integration test covering the WP03 executor surface:
 *
 *  - HITL gates (`None` denies, `All` synth-grants).
 *  - Cancellation polling honours `Cancelling` within bounded iterations.
 *  - Provider retry exhaustion terminates with `provider_rate_limited`.
 *  - `AgentResult` carries token usage telemetry.
 *
 * The test harness wires the canonical entity-storage pipeline against an
 * in-memory SQLite, builds an in-memory {@see ToolRegistryInterface},
 * and drives the executor with deterministic provider + sleep stubs.
 *
 * @api
 */
#[CoversNothing]
final class ExecutorHitlTest extends TestCase
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
            class: \Waaseyaa\AI\Agent\Entity\AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );

        $runEntityRepo = new EntityRepository($runType, $driver, $dispatcher);
        $logEntityRepo = new EntityRepository($logType, $driver, $dispatcher);

        $this->runRepository = new AgentRunRepository($runEntityRepo, $this->database);
        $this->auditRepository = new AgentAuditLogRepository($logEntityRepo, $this->database);
    }

    #[Test]
    public function hitlNoneDeniesDestructiveTool(): void
    {
        $run = $this->seedRun(HitlMode::None);
        $tool = $this->makeDestructiveTool();
        $registry = $this->registryWith([$tool]);

        $provider = $this->providerEmittingToolUse($tool->name);

        $executor = $this->makeExecutor($registry);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: [$tool->name],
            maxIterations: 3,
        );

        self::assertFalse($result->success);
        self::assertSame('destructive_denied', $result->data['error_code']);
        $fresh = $this->runRepository->find((string) $run->get('id'));
        self::assertSame(RunStatus::Failed, $fresh?->getStatus());
    }

    #[Test]
    public function hitlAllSynthGrantsAndProceeds(): void
    {
        $run = $this->seedRun(HitlMode::All);
        $tool = $this->makeDestructiveTool();
        $registry = $this->registryWith([$tool]);

        $provider = $this->providerToolUseThenEnd($tool->name);

        $executor = $this->makeExecutor($registry);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: [$tool->name],
            maxIterations: 3,
        );

        self::assertTrue($result->success, sprintf('result message=%s', $result->message));
        self::assertGreaterThan(0, $result->tokenUsageOut);
    }

    #[Test]
    public function cancellationHonouredWithinBoundedIterations(): void
    {
        $run = $this->seedRun(HitlMode::None);
        $registry = $this->registryWith([]);

        // Provider emits an `end_turn` response — never reached because the
        // iteration-entry poll detects the pre-marked `Cancelling` status.
        $provider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'thinking...']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                );
            }
        };

        $database = $this->database;
        $runId = (string) $run->get('id');
        // Pre-mark Cancelling so the very first iteration entry detects it.
        $database->update('agent_run')
            ->fields(['status' => RunStatus::Cancelling->value])
            ->condition('id', $runId)
            ->execute();

        $executor = $this->makeExecutor($registry);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 5,
        );

        self::assertFalse($result->success);
        self::assertTrue($result->data['cancelled'] ?? false);
        self::assertSame(RunStatus::Cancelled, $this->runRepository->find($runId)?->getStatus());
    }

    #[Test]
    public function hitlInteractiveApprovalGrantedResumesWithStoredArgs(): void
    {
        $run = $this->seedRun(HitlMode::Interactive);
        // Tool that records the arguments it was called with so the test
        // can pin the resume invariant: args are held in outer scope, not
        // refetched after the approval gate.
        $recorder = new class {
            /** @var list<array<string, mixed>> */
            public array $invocations = [];
        };
        $tool = $this->makeRecordingDestructiveTool($recorder);
        $registry = $this->registryWith([$tool]);

        $expectedArgs = ['target' => 'node:42', 'reason' => 'cleanup'];
        $provider = $this->providerToolUseThenEndWithArgs($tool->name, $expectedArgs);

        // Sleeper that flips status to Running on the first poll —
        // simulating an external approve via the WP-05 endpoint.
        $database = $this->database;
        $runId = (string) $run->get('id');
        $sleepCalls = 0;
        $sleeper = static function (int $ms) use (&$sleepCalls, $database, $runId): void {
            $sleepCalls++;
            if ($sleepCalls === 1) {
                $database->update('agent_run')
                    ->fields(['status' => RunStatus::Running->value])
                    ->condition('id', $runId)
                    ->execute();
            }
        };

        $executor = $this->makeExecutorWithSleeper($registry, $sleeper);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: [$tool->name],
            maxIterations: 3,
        );

        self::assertTrue($result->success, sprintf('result message=%s', $result->message));
        // Tool executed exactly once, with the SAME args provided by the
        // LLM — no refetch from the row or elsewhere.
        self::assertCount(1, $recorder->invocations);
        self::assertSame($expectedArgs, $recorder->invocations[0]);

        // Exactly one ApprovalRequired and one ApprovalGranted row for
        // this call_id, plus no stray ApprovalDenied / approval_timeout.
        $events = $this->auditEventsForRun($runId);
        $required = array_filter($events, static fn(array $e): bool => $e['event_type'] === 'approval_required');
        $granted = array_filter($events, static fn(array $e): bool => $e['event_type'] === 'approval_granted');
        $denied = array_filter($events, static fn(array $e): bool => $e['event_type'] === 'approval_denied');
        self::assertCount(1, $required, 'expected exactly one ApprovalRequired audit row');
        self::assertCount(1, $granted, 'expected exactly one ApprovalGranted audit row');
        self::assertCount(0, $denied, 'expected no ApprovalDenied audit rows on approve path');
    }

    #[Test]
    public function hitlInteractiveTimeoutTerminatesRunWithApprovalTimeout(): void
    {
        $run = $this->seedRun(HitlMode::Interactive);
        $tool = $this->makeDestructiveTool();
        $registry = $this->registryWith([$tool]);

        $provider = $this->providerEmittingToolUse($tool->name);

        // Stub clock: advance by 5 seconds on every poll so the deadline
        // (now + hitlTimeoutSeconds=1) passes after the first sleep.
        $tick = 0;
        $start = new \DateTimeImmutable('2026-01-01 00:00:00');
        $clock = static function () use (&$tick, $start): \DateTimeImmutable {
            $here = $start->add(new \DateInterval('PT' . ($tick * 5) . 'S'));
            $tick++;

            return $here;
        };

        $executor = $this->makeExecutorWithClock(
            $registry,
            sleeper: static fn(int $ms): null => null,
            clock: $clock,
        );
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: [$tool->name],
            maxIterations: 3,
        );

        self::assertFalse($result->success);
        self::assertSame('approval_timeout', $result->data['error_code']);
        $fresh = $this->runRepository->find((string) $run->get('id'));
        self::assertSame(RunStatus::Failed, $fresh?->getStatus());
        self::assertSame('approval_timeout', $fresh?->get('error_code'));

        // Audit trail: ApprovalRequired present, ApprovalDenied (timeout
        // marker) present, no spurious ApprovalGranted row.
        $events = $this->auditEventsForRun((string) $run->get('id'));
        $eventTypes = array_map(static fn(array $e): string => $e['event_type'], $events);
        self::assertContains('approval_required', $eventTypes);
        self::assertContains('approval_denied', $eventTypes);
        self::assertNotContains('approval_granted', $eventTypes);
    }

    #[Test]
    public function hitlInteractiveDenialTerminatesRunWithApprovalDenied(): void
    {
        $run = $this->seedRun(HitlMode::Interactive);
        $tool = $this->makeDestructiveTool();
        $registry = $this->registryWith([$tool]);

        $provider = $this->providerEmittingToolUse($tool->name);

        // Sleeper that flips the row to Failed/approval_denied on the
        // first poll — the HITL_DENIED_INTERACTIVE path in the executor.
        $database = $this->database;
        $runId = (string) $run->get('id');
        $sleeper = static function (int $ms) use ($database, $runId): void {
            $database->update('agent_run')
                ->fields([
                    'status' => RunStatus::Failed->value,
                    'error_code' => 'approval_denied',
                ])
                ->condition('id', $runId)
                ->execute();
        };

        $executor = $this->makeExecutorWithSleeper($registry, $sleeper);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: [$tool->name],
            maxIterations: 3,
        );

        self::assertFalse($result->success);
        self::assertSame('approval_denied', $result->data['error_code']);

        $events = $this->auditEventsForRun($runId);
        $eventTypes = array_map(static fn(array $e): string => $e['event_type'], $events);
        // Denial path must NOT emit a granted row, and must end with a
        // denied row — distinct terminal from cancellation.
        self::assertContains('approval_required', $eventTypes);
        self::assertContains('approval_denied', $eventTypes);
        self::assertNotContains('approval_granted', $eventTypes);
    }

    #[Test]
    public function transcriptTruncationMarkerAppendedOnceAtCap(): void
    {
        $run = $this->seedRun(HitlMode::None);
        $registry = $this->registryWith([]);

        // Two-turn provider that emits a >64 byte payload on EVERY turn
        // (tool_use -> ends after the tool result comes back). With
        // transcriptMaxBytes=64 the first append overflows and writes
        // `[truncated]`; subsequent appends MUST be dropped — appending
        // is idempotent past the cap.
        $bigText = str_repeat('x', 200);
        $tool = $this->makeToolReturningText($bigText);
        $registry = $this->registryWith([$tool]);
        $provider = $this->providerToolUseThenEndWithBigText($tool->name, $bigText);

        $executor = new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 64,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: static fn(int $ms): null => null,
        );
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            allowedToolNames: [$tool->name],
            maxIterations: 3,
        );

        self::assertTrue($result->success, sprintf('result message=%s', $result->message));
        $transcript = $result->data['transcript'] ?? '';
        self::assertIsString($transcript);
        // Marker is appended exactly once even though `appendTranscript`
        // was called multiple times (response, tool result, response).
        self::assertStringEndsWith("\n[truncated]", $transcript);
        self::assertSame(1, substr_count($transcript, '[truncated]'));
    }

    #[Test]
    public function providerRetryTwoFailuresThenSuccessCompletes(): void
    {
        $run = $this->seedRun(HitlMode::None);
        $registry = $this->registryWith([]);

        // 1st + 2nd call → RateLimitException; 3rd call → success.
        $provider = new class implements ProviderInterface {
            private int $attempt = 0;

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->attempt++;
                if ($this->attempt < 3) {
                    throw new RateLimitException(retryAfterSeconds: 1, message: 'transient');
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'recovered']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                );
            }
        };

        $executor = $this->makeExecutor($registry);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 1,
        );

        self::assertTrue($result->success, sprintf('result message=%s', $result->message));
        $fresh = $this->runRepository->find((string) $run->get('id'));
        self::assertSame(RunStatus::Completed, $fresh?->getStatus());

        // Exactly three provider_call audit rows: failure, failure, success.
        $events = $this->auditEventsForRun((string) $run->get('id'));
        $providerCalls = array_values(array_filter(
            $events,
            static fn(array $e): bool => $e['event_type'] === 'provider_call',
        ));
        self::assertCount(3, $providerCalls);
        self::assertSame(0, (int) $providerCalls[0]['success']);
        self::assertSame(0, (int) $providerCalls[1]['success']);
        self::assertSame(1, (int) $providerCalls[2]['success']);
    }

    #[Test]
    public function providerRetryExhaustionTerminatesWithRateLimited(): void
    {
        $run = $this->seedRun(HitlMode::None);
        $registry = $this->registryWith([]);

        $provider = new class implements ProviderInterface {
            public function sendMessage(MessageRequest $request): MessageResponse
            {
                throw new RateLimitException(retryAfterSeconds: 1, message: 'limit');
            }
        };

        $executor = $this->makeExecutor($registry);
        $result = $executor->executeRun(
            $run,
            $this->fakeAccount(99),
            $provider,
            messages: [['role' => 'user', 'content' => 'go']],
            maxIterations: 1,
        );

        self::assertFalse($result->success);
        $fresh = $this->runRepository->find((string) $run->get('id'));
        self::assertSame('provider_rate_limited', $fresh?->get('error_code'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeExecutor(ToolRegistryInterface $registry): AgentExecutor
    {
        return new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: static fn(int $ms): null => null,
        );
    }

    private function makeExecutorWithSleeper(
        ToolRegistryInterface $registry,
        \Closure $sleeper,
    ): AgentExecutor {
        return new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: $sleeper,
        );
    }

    private function makeExecutorWithClock(
        ToolRegistryInterface $registry,
        \Closure $sleeper,
        \Closure $clock,
    ): AgentExecutor {
        return new AgentExecutor(
            toolRegistry: $registry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            transcriptMaxBytes: 65536,
            hitlPollIntervalMs: 1,
            hitlTimeoutSeconds: 1,
            sleepMs: $sleeper,
            now: $clock,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditEventsForRun(string $runId): array
    {
        $iter = $this->database->select('agent_audit_log')
            ->fields('agent_audit_log', ['event_type', 'success', 'tool_name', 'tool_result_summary'])
            ->condition('run_id', $runId)
            ->orderBy('occurred_at', 'ASC')
            ->execute();

        return array_values(iterator_to_array($iter, preserve_keys: false));
    }

    private function seedRun(HitlMode $approval): AgentRun
    {
        $run = new AgentRun([
            'id' => '01J' . str_pad((string) random_int(1000, 9999), 23, '0'),
            'account_id' => 99,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => RunStatus::Running->value,
            'destructive_approval' => $approval->value,
            'pending_approval_call_id' => null,
            'prompt' => 'test',
            'response' => null,
            'transcript_json' => '',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP'),
            'started_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.uP'),
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

    private function registryWith(array $tools): ToolRegistryInterface
    {
        return new class ($tools) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                if (!isset($this->map[$name])) {
                    throw ToolNotFoundException::forName($name);
                }
                return $this->map[$name];
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->map);
            }
        };
    }

    private function makeDestructiveTool(): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'done']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Destructive test tool.';
            }
        };

        return new AgentTool(
            name: 'fixture.destructive',
            capability: 'tool.fixture.destructive',
            destructive: true,
            dryRunSupported: false,
            category: 'fixture',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /**
     * Destructive tool whose impl records every invocation's args into
     * `$recorder->invocations` for resume-invariant assertions.
     */
    private function makeRecordingDestructiveTool(object $recorder): AgentTool
    {
        $impl = new class ($recorder) implements AgentToolInterface {
            public function __construct(private readonly object $recorder) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $this->recorder->invocations[] = $arguments;

                return AgentToolResult::success([['type' => 'text', 'text' => 'done']]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Recording destructive test tool.';
            }
        };

        return new AgentTool(
            name: 'fixture.destructive.recording',
            capability: 'tool.fixture.destructive.recording',
            destructive: true,
            dryRunSupported: false,
            category: 'fixture',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /**
     * Two-turn provider that emits a `tool_use` block with concrete
     * arguments, then an `end_turn` text response after the tool result
     * comes back. Used to pin the resume invariant in CR2.
     *
     * @param array<string, mixed> $toolArgs
     */
    private function providerToolUseThenEndWithArgs(string $toolName, array $toolArgs): ProviderInterface
    {
        return new class ($toolName, $toolArgs) implements ProviderInterface {
            private int $turn = 0;

            /**
             * @param array<string, mixed> $toolArgs
             */
            public function __construct(
                private readonly string $toolName,
                private readonly array $toolArgs,
            ) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->turn++;
                if ($this->turn === 1) {
                    return new MessageResponse(
                        content: [[
                            'type' => 'tool_use',
                            'id' => 'tu_rec',
                            'name' => $this->toolName,
                            'input' => $this->toolArgs,
                        ]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'finished']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 5, 'output_tokens' => 2],
                );
            }
        };
    }

    /**
     * Non-destructive tool whose impl returns a fixed text block — used
     * to drive multiple `appendTranscript()` calls within a single run
     * for transcript-truncation idempotency assertions.
     */
    private function makeToolReturningText(string $text): AgentTool
    {
        $impl = new class ($text) implements AgentToolInterface {
            public function __construct(private readonly string $text) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => $this->text]]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Returns a fixed text block.';
            }
        };

        return new AgentTool(
            name: 'fixture.echo.big',
            capability: 'tool.fixture.echo.big',
            destructive: false,
            dryRunSupported: false,
            category: 'fixture',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /**
     * Two-turn provider that calls a tool, then on resume emits a big
     * text block as the final assistant message. Drives 3 transcript
     * appends per run.
     */
    private function providerToolUseThenEndWithBigText(string $toolName, string $bigText): ProviderInterface
    {
        return new class ($toolName, $bigText) implements ProviderInterface {
            private int $turn = 0;

            public function __construct(
                private readonly string $toolName,
                private readonly string $bigText,
            ) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->turn++;
                if ($this->turn === 1) {
                    return new MessageResponse(
                        content: [['type' => 'tool_use', 'id' => 'tu_big', 'name' => $this->toolName, 'input' => []]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 50],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => $this->bigText]],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 5, 'output_tokens' => 50],
                );
            }
        };
    }

    private function providerEmittingToolUse(string $toolName): ProviderInterface
    {
        return new class ($toolName) implements ProviderInterface {
            public function __construct(private readonly string $toolName) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                return new MessageResponse(
                    content: [['type' => 'tool_use', 'id' => 'tu_x', 'name' => $this->toolName, 'input' => []]],
                    stopReason: 'tool_use',
                    usage: ['input_tokens' => 5, 'output_tokens' => 1],
                );
            }
        };
    }

    private function providerToolUseThenEnd(string $toolName): ProviderInterface
    {
        return new class ($toolName) implements ProviderInterface {
            private int $turn = 0;

            public function __construct(private readonly string $toolName) {}

            public function sendMessage(MessageRequest $request): MessageResponse
            {
                $this->turn++;
                if ($this->turn === 1) {
                    return new MessageResponse(
                        content: [['type' => 'tool_use', 'id' => 'tu_a', 'name' => $this->toolName, 'input' => []]],
                        stopReason: 'tool_use',
                        usage: ['input_tokens' => 5, 'output_tokens' => 2],
                    );
                }

                return new MessageResponse(
                    content: [['type' => 'text', 'text' => 'finished']],
                    stopReason: 'end_turn',
                    usage: ['input_tokens' => 5, 'output_tokens' => 2],
                );
            }
        };
    }

    private function migrateSchema(): void
    {
        $migrationFile = \dirname(__DIR__, 4)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);

        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);
    }
}
