<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\Account\StubInitiatorAccountLoader;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcaster;
use Waaseyaa\AI\Agent\Controller\AgentRunController;
use Waaseyaa\AI\Agent\Controller\AgentRunRequestValidator;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Message\RunAgent;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\Provider\NullLlmProvider;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * FR-001 + NFR-002: POST /api/ai/agent/run enqueues a run, the worker
 * picks it up, and the SSE channel sees `run_started` + `run_completed`
 * within the 30s wall-clock budget for a {@see NullLlmProvider} run.
 *
 * The test wires the controller against the canonical
 * {@see AgentRunBroadcaster} (writing through {@see BroadcastStorage})
 * to exercise the persistence path the real `/broadcast?channels=`
 * subscriber will read.
 */
#[CoversNothing]
final class AsyncHttpRunTest extends TestCase
{
    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;
    private BroadcastStorage $broadcastStorage;
    private AgentRunController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();

        // Run-table migration
        $migrationFile = \dirname(__DIR__, 4)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);

        // Broadcast-storage table; BroadcastStorage::ensureTableExists() is private,
        // so seed by calling push() once with a sentinel — it lazily creates the table
        // on first write. We avoid that pattern here by relying on BroadcastStorage's
        // own lazy ensureTable inside push() — the first real push from the worker
        // hits the same path.
        $this->broadcastStorage = new BroadcastStorage($this->database);

        $this->runRepository = $this->buildRunRepository();
        $this->auditRepository = $this->buildAuditRepository();

        $this->controller = $this->buildController();
    }

    #[Test]
    public function postRunReturns202AndDrivesRunToCompletedUnderBudget(): void
    {
        $body = \json_encode([
            'bundle' => [
                'id' => 'smoke',
                'label' => 'Smoke',
                'description' => '',
                'prompt' => 'hi',
                'tools' => [],
                'model' => 'null:smoke',
            ],
            'destructive_approval' => 'none',
        ], \JSON_THROW_ON_ERROR);

        $request = $this->buildRequest('POST', '/api/ai/agent/run', $body);

        $started = \microtime(true);
        $response = $this->controller->create($request);

        self::assertSame(202, $response->getStatusCode(), 'POST /run must return 202.');
        $payload = \json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('run_id', $payload);
        self::assertArrayHasKey('stream_url', $payload);
        self::assertArrayHasKey('status_url', $payload);
        self::assertArrayHasKey('approve_url', $payload);

        $runId = (string) $payload['run_id'];
        self::assertNotSame('', $runId);

        $elapsed = \microtime(true) - $started;
        self::assertLessThan(
            30.0,
            $elapsed,
            \sprintf('NFR-002: POST /run + sync consume took %.2fs > 30s budget.', $elapsed),
        );

        $run = $this->runRepository->find($runId);
        self::assertNotNull($run);
        self::assertSame(RunStatus::Completed, $run->getStatus());

        // SSE: run_started + run_completed must be present in the broadcast log.
        $events = $this->channelEvents('agent.run.' . $runId);
        self::assertContains('run_started', $events, 'SSE log missing run_started.');
        self::assertContains('run_completed', $events, 'SSE log missing run_completed.');

        // stream URL points at the broadcast channel.
        self::assertSame(
            "/broadcast?channels=agent.run.{$runId}",
            (string) $payload['stream_url'],
        );
    }

    #[Test]
    public function getRunReturnsSnapshotAfterCompletion(): void
    {
        $body = \json_encode([
            'bundle' => [
                'id' => 'smoke',
                'label' => 'Smoke',
                'description' => '',
                'prompt' => 'hi',
                'tools' => [],
                'model' => 'null:smoke',
            ],
        ], \JSON_THROW_ON_ERROR);

        $create = $this->controller->create($this->buildRequest('POST', '/api/ai/agent/run', $body));
        self::assertSame(202, $create->getStatusCode());
        $createPayload = \json_decode((string) $create->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        $runId = (string) $createPayload['run_id'];

        $show = $this->controller->show($this->buildRequest('GET', "/api/ai/agent/run/{$runId}", ''), $runId);
        self::assertSame(200, $show->getStatusCode());
        $payload = \json_decode((string) $show->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        self::assertSame($runId, $payload['run_id']);
        self::assertSame('completed', $payload['status']);
    }

    /**
     * Build a Symfony Request and pre-populate the `_account` attribute
     * (constitution: `_account`, not `account`).
     */
    private function buildRequest(string $method, string $uri, string $body): Request
    {
        $request = Request::create(
            uri: $uri,
            method: $method,
            content: $body !== '' ? $body : null,
            server: ['CONTENT_TYPE' => 'application/json'],
        );
        $request->attributes->set('_account', $this->account(42));

        return $request;
    }

    private function account(int $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'agent.run' || $permission === 'agent.run.approve';
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function buildController(): AgentRunController
    {
        $broadcaster = new AgentRunBroadcaster($this->broadcastStorage);

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
                unset($ms);
            },
        );

        $handler = new RunAgentHandler(
            runRepository: $this->runRepository,
            executor: $executor,
            definitionRegistry: $registry,
            toolRegistry: $toolRegistry,
            broadcaster: $broadcaster,
            provider: new NullLlmProvider(),
            accountLoader: new StubInitiatorAccountLoader(),
            workerReader: new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture(),
        );

        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                RunAgent::class => [new HandlerDescriptor($handler)],
            ])),
        ]);

        $service = new AgentRunService(
            messageBus: $bus,
            runRepository: $this->runRepository,
            inlineHandler: $handler,
        );

        return new AgentRunController(
            runService: $service,
            runRepository: $this->runRepository,
            auditRepository: $this->auditRepository,
            definitionRegistry: $registry,
            broadcaster: $broadcaster,
            accessPolicy: new AgentRunAccessPolicy($this->runRepository),
            validator: new AgentRunRequestValidator(),
            accountProjectionReader: new \Waaseyaa\Tests\Support\AgentRunAccountProjectionReaderFixture(),
        );
    }

    /**
     * Pull all event names recorded on a broadcast channel by reading
     * the underlying SQLite log directly.
     *
     * @return list<string>
     */
    private function channelEvents(string $channel): array
    {
        $events = [];
        foreach ($this->broadcastStorage->poll(0, [$channel]) as $row) {
            $events[] = (string) ($row['event'] ?? '');
        }

        return $events;
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
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
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
        $entityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            $driver,
            new EventDispatcher(),
            null,
            $this->database,
        );

        return new AgentAuditLogRepository($entityRepo, $this->database);
    }
}
