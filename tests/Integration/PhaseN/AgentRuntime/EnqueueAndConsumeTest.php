<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Waaseyaa\AI\Agent\Account\StubInitiatorAccountLoader;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Message\RunAgent;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\Provider\NullLlmProvider;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Service\AgentRunDraft;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * End-to-end smoke test for the WP-04 enqueue surface (FR-001, NFR-002).
 *
 * Boots an in-memory SQLite kernel, wires the agent-executor entity
 * surface plus the {@see AgentRunService} / {@see RunAgentHandler}
 * pipeline through a synchronous Messenger bus, and asserts:
 *
 *  - `enqueue()` lands a row at `Queued`, dispatches a {@see RunAgent},
 *    and the handler drives the row to `Completed` against
 *    {@see NullLlmProvider}.
 *  - The wall-clock budget for a `NullLlmProvider` run is well inside
 *    NFR-002's 30s budget.
 *  - `runInline()` produces identical persistence state to `enqueue()`
 *    when both are pointed at the sync bus (FR-008).
 *  - `runInline()` with `HitlMode::Interactive` throws.
 *
 * @api
 */
#[CoversNothing]
final class EnqueueAndConsumeTest extends TestCase
{
    use \Waaseyaa\Tests\Support\AgentRunOpenApiAssertions;

    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;
    private CapturingBroadcaster $broadcaster;
    private \Waaseyaa\Api\Controller\BroadcastStorage $broadcastStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();

        $migrationFile = \dirname(__DIR__, 4)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);

        $this->runRepository = $this->buildRunRepository();
        $this->auditRepository = $this->buildAuditRepository();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($this->database);
        $this->broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($this->database);
        $this->broadcaster = new CapturingBroadcaster(
            new \Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcaster($this->broadcastStorage),
        );
    }

    #[Test]
    public function enqueueDrivesNullProviderRunToCompletedUnderBudget(): void
    {
        $service = $this->buildService();

        $started = microtime(true);

        $run = $service->enqueue(new AgentRunDraft(
            accountId: 42,
            agentDefinitionId: null,
            bundle: ['id' => 'smoke', 'label' => 'Smoke', 'description' => '', 'prompt' => 'hi'],
            prompt: 'hello',
            destructiveApproval: HitlMode::None,
        ));

        $elapsed = microtime(true) - $started;

        self::assertSame(RunStatus::Completed, $run->getStatus(), 'NullLlmProvider run should land Completed.');
        self::assertLessThan(30.0, $elapsed, \sprintf('NFR-002: enqueue + consume took %.2fs > 30s budget.', $elapsed));

        $events = $this->broadcaster->eventsFor((string) $run->get('id'));
        self::assertContains('run_started', $events);
        self::assertContains('run_completed', $events);
        foreach ($this->broadcastStorage->poll(0, ['agent.run.' . $run->get('id')]) as $row) {
            self::assertTrue($this->openApiAccepts('AgentRunEvent', $row), 'Actual worker SSE row must match OpenAPI.');
        }
    }

    #[Test]
    public function eventSchemaPreservesOtherProducersAndRejectsMalformedApproval(): void
    {
        $runId = 'aebfef20-ae10-4a15-b54c-4eb234ec6a66';
        // Representative failure/cancellation shapes from handler, controller and reaper.
        foreach ([
            ['run_failed', ['error_code' => null, 'error_message' => null]],
            ['run_cancelled', ['cancelled_at' => '2026-10-30T12:00:00+00:00']],
            ['run_cancelled', ['error_code' => 'cancelled', 'error_message' => 'Cancelled']],
            ['approval_resolved', ['call_id' => 'call_1', 'decision' => 'approve']],
            ['approval_resolved', ['call_id' => 'call_2', 'decision' => 'deny']],
        ] as [$event, $data]) {
            $this->broadcaster->push($runId, $event, $data);
        }
        $rows = $this->broadcastStorage->poll(0, ['agent.run.' . $runId]);
        self::assertCount(5, $rows);
        foreach ($rows as $row) {
            self::assertTrue($this->openApiAccepts('AgentRunEvent', $row));
        }

        $approval = $rows[3];
        unset($approval['data']['call_id']);
        self::assertFalse($this->openApiAccepts('AgentRunEvent', $approval));
        $approval = $rows[3];
        $approval['data']['decision'] = 'allow';
        self::assertFalse($this->openApiAccepts('AgentRunEvent', $approval));
        $approval = $rows[3];
        unset($approval['data']['run_id']);
        self::assertFalse($this->openApiAccepts('AgentRunEvent', $approval));
        self::assertFalse($this->openApiAccepts('AgentRunEvent', [
            'run_id' => $runId, 'event' => 'pending_approval', 'payload' => [],
        ]));
    }

    #[Test]
    public function approvalSchemaAgreesWithRuntimeDecisionValidation(): void
    {
        $validator = new \Waaseyaa\AI\Agent\Controller\AgentRunRequestValidator();
        foreach ([
            ['call_id' => 'call_1', 'decision' => 'approve'],
            ['call_id' => 'call_1', 'decision' => 'deny'],
            ['call_id' => 'call_1'],
            ['call_id' => '', 'decision' => 'approve'],
            ['call_id' => '   ', 'decision' => 'approve'],
            ['call_id' => "\0\x0B\t\r\n ", 'decision' => 'approve'],
            ['call_id' => "\f", 'decision' => 'approve'],
            ['call_id' => 'call_1', 'decision' => 'allow'],
        ] as $body) {
            self::assertSame($validator->validateApprove($body) === null, $this->openApiAccepts('ApprovalRequest', $body));
        }
    }

    #[Test]
    public function inlineAndAsyncProduceIdenticalPersistenceShape(): void
    {
        $service = $this->buildService();

        $async = $service->enqueue(new AgentRunDraft(
            accountId: 7,
            agentDefinitionId: null,
            bundle: ['id' => 'smoke', 'label' => 'Smoke', 'description' => '', 'prompt' => 'hi'],
            prompt: 'hello async',
        ));

        $inline = $service->runInline(new AgentRunDraft(
            accountId: 7,
            agentDefinitionId: null,
            bundle: ['id' => 'smoke', 'label' => 'Smoke', 'description' => '', 'prompt' => 'hi'],
            prompt: 'hello inline',
        ));

        self::assertSame(RunStatus::Completed, $async->getStatus());
        self::assertSame(RunStatus::Completed, $inline->getStatus());
        $workerReader = new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture();
        self::assertSame($workerReader->read($async)->accountId, $workerReader->read($inline)->accountId);
        self::assertSame($async->get('destructive_approval'), $inline->get('destructive_approval'));

        // Both paths route through RunAgentHandler, so the audit-row
        // sequence shape (kind + ordering) is identical.
        $asyncEvents = $this->eventTypesForRun((string) $async->get('id'));
        $inlineEvents = $this->eventTypesForRun((string) $inline->get('id'));
        self::assertSame($asyncEvents, $inlineEvents, 'Inline and async runs must produce identical audit sequences.');
    }

    #[Test]
    public function runInlineRefusesInteractiveHitl(): void
    {
        $service = $this->buildService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Interactive/');

        $service->runInline(new AgentRunDraft(
            accountId: 1,
            agentDefinitionId: null,
            bundle: ['id' => 'smoke', 'label' => 'Smoke', 'description' => '', 'prompt' => 'hi'],
            prompt: 'hello',
            destructiveApproval: HitlMode::Interactive,
        ));
    }

    /**
     * @return list<string>
     */
    private function eventTypesForRun(string $runId): array
    {
        $rows = $this->auditRepository->findByRunId($runId);
        $types = [];
        $reader = new \Waaseyaa\Tests\Support\AgentAuditEventTypeReaderFixture();
        foreach ($rows as $row) {
            $types[] = $reader->read($row)->value;
        }

        return $types;
    }

    private function buildService(): AgentRunService
    {
        $manifest = new PackageManifest(agentDefinitions: []);
        $registry = new AgentDefinitionRegistry($manifest);
        $toolRegistry = new class implements ToolRegistryInterface {
            public function register(AgentTool $tool): void
            {
                unset($tool);
            }

            public function get(string $name): AgentTool
            {
                throw new ToolNotFoundException(\sprintf('No tools registered (%s).', $name));
            }

            public function has(string $name): bool
            {
                unset($name);

                return false;
            }

            public function all(): iterable
            {
                return [];
            }
        };

        $executor = new AgentExecutor(
            toolRegistry: $toolRegistry,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            sleepMs: static function (int $ms): void {
                // Skip sleeping to keep the suite fast.
                unset($ms);
            },
        );

        $handler = new RunAgentHandler(
            runRepository: $this->runRepository,
            executor: $executor,
            definitionRegistry: $registry,
            toolRegistry: $toolRegistry,
            broadcaster: $this->broadcaster,
            provider: new NullLlmProvider(),
            accountLoader: new StubInitiatorAccountLoader(),
            workerReader: new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture(),
        );

        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                RunAgent::class => [new HandlerDescriptor($handler)],
            ])),
        ]);

        return new AgentRunService(
            messageBus: $bus,
            runRepository: $this->runRepository,
            inlineHandler: $handler,
        );
    }

    private function buildRunRepository(): AgentRunRepository
    {
        $entityType = new EntityType(
            id: 'agent_run',
            label: 'Agent run',
            class: AgentRun::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'id'],
        );
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return new AgentRunRepository($entityRepo, $this->database);
    }

    private function buildAuditRepository(): AgentAuditLogRepository
    {
        $entityType = new EntityType(
            id: 'agent_audit_log',
            label: 'Agent audit log entry',
            class: AgentAuditLog::class,
            keys: ['id' => 'id', 'uuid' => 'id', 'label' => 'event_type'],
        );
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return new AgentAuditLogRepository($entityRepo, $this->database);
    }
}

/**
 * In-memory broadcaster that records the event names per run for assertions.
 */
final class CapturingBroadcaster implements AgentRunBroadcasterInterface
{
    public function __construct(private readonly AgentRunBroadcasterInterface $delegate) {}

    /** @var array<string, list<string>> */
    private array $events = [];

    public function push(string $runId, string $event, array $data): void
    {
        $this->delegate->push($runId, $event, $data);
        $this->events[$runId][] = $event;
    }

    /**
     * @return list<string>
     */
    public function eventsFor(string $runId): array
    {
        return $this->events[$runId] ?? [];
    }
}
