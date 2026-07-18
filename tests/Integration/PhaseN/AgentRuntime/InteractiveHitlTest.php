<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcaster;
use Waaseyaa\AI\Agent\Controller\AgentRunController;
use Waaseyaa\AI\Agent\Controller\AgentRunRequestValidator;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * FR-019/FR-022: Interactive HITL approval flow over HTTP.
 *
 * The worker pause logic (writing `AwaitingApproval` + `pending_approval_call_id`)
 * lands in WP-06. This test simulates that state so WP-05's approve endpoint
 * contract is exercised end-to-end:
 *
 *  - approve → 204, row flips back to `Running`, audit row of type
 *    `approval_granted`, SSE `approval_resolved` with `decision=approve`.
 *  - deny    → 204, row flips to `Failed/approval_denied`, audit row of
 *    type `approval_denied`, SSE `approval_resolved` with `decision=deny`.
 *  - wrong call_id → 409, row unchanged.
 */
#[CoversNothing]
final class InteractiveHitlTest extends TestCase
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

        $this->broadcastStorage = new BroadcastStorage($this->database);
        $this->runRepository = $this->buildRunRepository();
        $this->auditRepository = $this->buildAuditRepository();
        $this->controller = $this->buildController();
    }

    #[Test]
    public function approveResumesAndEmitsResolvedEvent(): void
    {
        $callId = 'call_' . Uuid::v4()->toRfc4122();
        $runId = $this->seedAwaitingApprovalRun(callerId: 42, callId: $callId);

        $request = $this->buildRequest('POST', "/api/ai/agent/run/{$runId}/approve", \json_encode([
            'call_id' => $callId,
            'decision' => 'approve',
        ], \JSON_THROW_ON_ERROR));

        $response = $this->controller->approve($request, $runId);

        self::assertSame(204, $response->getStatusCode());

        $run = $this->runRepository->find($runId);
        self::assertNotNull($run);
        self::assertSame(RunStatus::Running, $run->getStatus());
        $account = $request->attributes->get('_account');
        self::assertInstanceOf(\Waaseyaa\Access\AccountInterface::class, $account);
        self::assertNull(new \Waaseyaa\Tests\Support\AgentRunAccountProjectionReaderFixture()->read($run, $account)->pendingApprovalCallId);

        $events = $this->channelEvents('agent.run.' . $runId);
        self::assertContains('approval_resolved', $events);

        $audits = $this->auditRepository->findByRunId($runId);
        $reader = new \Waaseyaa\Tests\Support\AgentAuditEventTypeReaderFixture();
        $eventTypes = \array_map(static fn(AgentAuditLog $row): string => $reader->read($row)->value, $audits);
        self::assertContains(EventType::ApprovalGranted->value, $eventTypes);
    }

    #[Test]
    public function denyMovesToFailedWithApprovalDenied(): void
    {
        $callId = 'call_' . Uuid::v4()->toRfc4122();
        $runId = $this->seedAwaitingApprovalRun(callerId: 42, callId: $callId);

        $request = $this->buildRequest('POST', "/api/ai/agent/run/{$runId}/approve", \json_encode([
            'call_id' => $callId,
            'decision' => 'deny',
        ], \JSON_THROW_ON_ERROR));

        $response = $this->controller->approve($request, $runId);

        self::assertSame(204, $response->getStatusCode());

        $run = $this->runRepository->find($runId);
        self::assertNotNull($run);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('approval_denied', new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture()->read($run)->errorCode);

        $events = $this->channelEvents('agent.run.' . $runId);
        self::assertContains('approval_resolved', $events);
    }

    #[Test]
    public function terminalRunNeverSerializesStalePendingApprovalMetadata(): void
    {
        $callId = 'call_' . Uuid::v4()->toRfc4122();
        $runId = $this->seedAwaitingApprovalRun(callerId: 42, callId: $callId);
        $this->database->update('agent_run')
            ->fields(['status' => RunStatus::Failed->value])
            ->condition('id', $runId)
            ->execute();

        $response = $this->controller->show($this->buildRequest('GET', "/api/ai/agent/run/{$runId}", ''), $runId);
        self::assertSame(200, $response->getStatusCode());
        $payload = \json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertNull($payload['pending_approval']);
    }

    #[Test]
    public function mismatchedCallIdReturns409(): void
    {
        $expected = 'call_' . Uuid::v4()->toRfc4122();
        $wrong = 'call_' . Uuid::v4()->toRfc4122();
        $runId = $this->seedAwaitingApprovalRun(callerId: 42, callId: $expected);

        $request = $this->buildRequest('POST', "/api/ai/agent/run/{$runId}/approve", \json_encode([
            'call_id' => $wrong,
            'decision' => 'approve',
        ], \JSON_THROW_ON_ERROR));

        $response = $this->controller->approve($request, $runId);

        self::assertSame(409, $response->getStatusCode());

        // Row unchanged.
        $run = $this->runRepository->find($runId);
        self::assertNotNull($run);
        self::assertSame(RunStatus::AwaitingApproval, $run->getStatus());
        $account = $request->attributes->get('_account');
        self::assertInstanceOf(\Waaseyaa\Access\AccountInterface::class, $account);
        self::assertSame($expected, new \Waaseyaa\Tests\Support\AgentRunAccountProjectionReaderFixture()->read($run, $account)->pendingApprovalCallId);
    }

    #[Test]
    public function denyCasLossReturnsStateChangedWithoutAuditOrBroadcast(): void
    {
        $callId = 'call_' . Uuid::v4()->toRfc4122();
        $replacementCallId = 'call_' . Uuid::v4()->toRfc4122();
        $runId = $this->seedAwaitingApprovalRun(callerId: 42, callId: $callId);
        $request = $this->buildRequest('POST', "/api/ai/agent/run/{$runId}/approve", \json_encode([
            'call_id' => $callId,
            'decision' => 'deny',
        ], \JSON_THROW_ON_ERROR));

        $database = $this->database;
        $request->attributes->set('_account', new class ($database, $runId, $replacementCallId) implements AccountInterface {
            private bool $raced = false;

            public function __construct(
                private readonly DBALDatabase $database,
                private readonly string $runId,
                private readonly string $replacementCallId,
            ) {}

            public function id(): int|string
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                if (!$this->raced) {
                    $this->raced = true;
                    $this->database->update('agent_run')
                        ->fields(['pending_approval_call_id' => $this->replacementCallId])
                        ->condition('id', $this->runId)
                        ->execute();
                }

                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        });

        $response = $this->controller->approve($request, $runId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('run_status_changed', \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR)['error_code']);
        $account = $request->attributes->get('_account');
        $fresh = $this->runRepository->find($runId);
        self::assertInstanceOf(\Waaseyaa\Access\AccountInterface::class, $account);
        self::assertNotNull($fresh);
        self::assertSame($replacementCallId, new \Waaseyaa\Tests\Support\AgentRunAccountProjectionReaderFixture()->read($fresh, $account)->pendingApprovalCallId);
        self::assertSame([], $this->auditRepository->findByRunId($runId));
        self::assertNotContains('approval_resolved', $this->channelEvents('agent.run.' . $runId));
    }

    private function seedAwaitingApprovalRun(int $callerId, string $callId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $now = new \DateTimeImmutable('now')->format('Y-m-d H:i:s.uP');

        $run = new AgentRun([
            'id' => $id,
            'account_id' => $callerId,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => RunStatus::AwaitingApproval->value,
            'destructive_approval' => HitlMode::Interactive->value,
            'pending_approval_call_id' => $callId,
            'prompt' => 'pretend a destructive tool just paused',
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => $now,
            'started_at' => $now,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);
        $this->runRepository->save($run);

        return $id;
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
        return new class ($id) implements AccountInterface {
            public function __construct(private readonly int $accountId) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function hasPermission(string $permission): bool
            {
                return \in_array($permission, ['agent.run', 'agent.run.approve'], strict: true);
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
        $registry = new AgentDefinitionRegistry(new PackageManifest(agentDefinitions: []));

        // Build a no-op service — we never call create() in this suite, so a
        // bare-bones bus + a no-op inline handler suffices.
        $service = new AgentRunService(
            messageBus: new MessageBus(),
            runRepository: $this->runRepository,
            inlineHandler: $this->noopHandler(),
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
     * A stub inline handler — never called in this suite.
     */
    private function noopHandler(): RunAgentHandler
    {
        $reflection = new \ReflectionClass(RunAgentHandler::class);

        // newInstanceWithoutConstructor lets us hand the service a sentinel
        // without wiring all of RunAgentHandler's deps; we never invoke it.
        $handler = $reflection->newInstanceWithoutConstructor();
        \assert($handler instanceof RunAgentHandler);

        return $handler;
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
