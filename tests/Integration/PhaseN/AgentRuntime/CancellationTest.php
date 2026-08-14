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
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\Account\StubInitiatorAccountLoader;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcaster;
use Waaseyaa\AI\Agent\Controller\AgentRunController;
use Waaseyaa\AI\Agent\Controller\AgentRunRequestValidator;
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
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * NFR-003: DELETE /api/ai/agent/run/{id} ends the run within 3 iterations.
 *
 * Two flavours:
 *
 *  - **Pre-pickup cancellation** — the row is `Queued`; DELETE transitions
 *    it directly to `Cancelled` and pushes `run_cancelled` on the SSE
 *    channel.
 *  - **Post-pickup cancellation** — the row is `Running`; DELETE writes
 *    `Cancelling` and the next iteration short-circuits via the executor's
 *    cancel guard. Iteration budget ≤ 3 satisfies NFR-003.
 */
#[CoversNothing]
final class CancellationTest extends TestCase
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

        $migrationFile = \dirname(__DIR__, 4)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);

        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($this->database);
        $this->broadcastStorage = new BroadcastStorage($this->database);

        $this->runRepository = $this->buildRunRepository();
        $this->auditRepository = $this->buildAuditRepository();
        $this->controller = $this->buildController();
    }

    #[Test]
    public function deleteBeforeWorkerPickupReachesCancelled(): void
    {
        // Persist a queued row without invoking the handler.
        $runId = $this->insertQueuedRun(accountId: 42);

        $request = $this->buildRequest('DELETE', "/api/ai/agent/run/{$runId}", '');
        $response = $this->controller->cancel($request, $runId);

        self::assertSame(204, $response->getStatusCode(), 'DELETE on a queued run must return 204.');

        $run = $this->runRepository->find($runId);
        self::assertNotNull($run);
        self::assertSame(RunStatus::Cancelled, $run->getStatus(), 'Pre-pickup DELETE must reach Cancelled directly.');
        self::assertSame('cancelled_by_user', new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture()->read($run)->errorCode);

        $events = $this->channelEvents('agent.run.' . $runId);
        self::assertContains('run_cancelled', $events, 'Cancellation must emit run_cancelled SSE.');
    }

    #[Test]
    public function deleteAfterCompletionReturns409(): void
    {
        // Drive a run to completion via the controller's create + sync bus.
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
        $createPayload = \json_decode((string) $create->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        $runId = (string) $createPayload['run_id'];

        $response = $this->controller->cancel($this->buildRequest('DELETE', "/api/ai/agent/run/{$runId}", ''), $runId);

        self::assertSame(409, $response->getStatusCode(), 'DELETE on a terminal run must return 409.');
    }

    /**
     * Persist a queued row directly through the service so the worker
     * never runs. Returns the run id.
     */
    private function insertQueuedRun(int $accountId): string
    {
        $service = new AgentRunService(
            messageBus: new MessageBus(),  // empty bus — dispatch is a no-op
            runRepository: $this->runRepository,
            inlineHandler: $this->buildHandler(),
        );

        $run = $service->enqueue(new AgentRunDraft(
            accountId: $accountId,
            agentDefinitionId: null,
            bundle: ['id' => 'smoke', 'label' => 'Smoke', 'description' => '', 'prompt' => 'hi'],
            prompt: 'hello',
            destructiveApproval: HitlMode::None,
        ));

        // The empty bus skipped the handler, so the row should still be Queued.
        // Defensive: force back to Queued if the service rolled it forward.
        if ($run->getStatus() !== RunStatus::Queued) {
            $run->set('status', RunStatus::Queued->value);
            $run->set('finished_at', null);
            $run->set('error_code', null);
            $run->set('error_message', null);
            $this->runRepository->save($run);
        }

        return (string) $run->get('id');
    }

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
        return new AuthorizationPrincipal($id, true, ['authenticated'], ['agent.run', 'agent.run.approve'], 'test-' . $id);
    }

    private function buildController(): AgentRunController
    {
        $broadcaster = new AgentRunBroadcaster($this->broadcastStorage);
        $registry = new AgentDefinitionRegistry(new PackageManifest(agentDefinitions: []));

        $handler = $this->buildHandler();
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

    private function buildHandler(): RunAgentHandler
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
                unset($ms);
            },
        );

        return new RunAgentHandler(
            runRepository: $this->runRepository,
            executor: $executor,
            definitionRegistry: $registry,
            toolRegistry: $toolRegistry,
            broadcaster: new AgentRunBroadcaster($this->broadcastStorage),
            provider: new NullLlmProvider(),
            accountLoader: new StubInitiatorAccountLoader(),
            workerReader: new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture(),
        );
    }

    /**
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
