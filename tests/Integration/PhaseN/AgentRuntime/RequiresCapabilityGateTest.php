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
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Agent\Account\InitiatorAccountLoaderInterface;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Message\RunAgent;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\NullLlmProvider;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Service\AgentRunDraft;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
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
 * Regression coverage for audit A7 finding F2 (R10 WP2): {@see
 * \Waaseyaa\AI\Agent\AgentDefinition::$requiresCapability} was plumbed
 * end-to-end (attribute → manifest → registry → definition) but never
 * enforced — an agent declaring `requiresCapability: 'agent.translate'`
 * would run for any caller regardless of permissions, reachable via
 * both `ai:run` (CLI) and `POST /api/ai/agent/run` (API).
 *
 * Both entry points funnel through {@see AgentRunService::enqueue()} /
 * {@see AgentRunService::runInline()}, which both dispatch to the SAME
 * {@see RunAgentHandler} instance (see {@see EnqueueAndConsumeTest} for
 * the identical-persistence-shape proof). This test drives the run
 * through that shared handler via a real {@see AgentExecutor} +
 * {@see NullLlmProvider} + SQLite-backed repositories, so a positive
 * result here demonstrates neither CLI nor API can bypass the gate.
 *
 * The "executor never ran" assertion uses the audit log rather than a
 * mock: {@see AgentExecutor::executeRun()} is `final` (cannot be
 * mocked/subclassed per the `createMock()` limitations in the project
 * conventions) and unconditionally appends an `IterationStart` audit
 * row as the very first action inside its run loop. A refused run must
 * therefore have zero audit rows.
 *
 * @api
 */
#[CoversNothing]
final class RequiresCapabilityGateTest extends TestCase
{
    public const CAPABILITY = 'agent.translate';

    /**
     * Account ids are typed `int` on {@see AgentRun::getAccountId()}, so
     * the fixture loader keys on fixed integer ids rather than semantic
     * strings.
     */
    public const ACCOUNT_HAS_PERMISSION = 100;
    public const ACCOUNT_NO_PERMISSION = 200;
    public const ACCOUNT_ANONYMOUS = 300;

    private DBALDatabase $database;
    private AgentRunRepository $runRepository;
    private AgentAuditLogRepository $auditRepository;
    private CapturingCapabilityBroadcaster $broadcaster;

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
        $this->broadcaster = new CapturingCapabilityBroadcaster();
    }

    #[Test]
    public function refusesRunWhenInitiatorLacksRequiredCapability(): void
    {
        $service = $this->buildService();

        $run = $service->runInline(new AgentRunDraft(
            accountId: self::ACCOUNT_NO_PERMISSION,
            agentDefinitionId: null,
            bundle: [
                'id' => 'gated',
                'label' => 'Gated',
                'description' => '',
                'prompt' => 'hi',
                'requires_capability' => self::CAPABILITY,
            ],
            prompt: 'translate this',
        ));

        self::assertSame(
            RunStatus::Failed,
            $run->getStatus(),
            'A run whose initiator lacks the required capability must land Failed, not Completed.',
        );
        self::assertSame('missing_capability', new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture()->read($run)->errorCode);

        // Prove the executor/agent loop never ran: IterationStart is the
        // first audit row AgentExecutor::doExecuteRun() writes.
        self::assertSame(
            [],
            $this->auditRepository->findByRunId((string) $run->get('id')),
            'No audit rows should exist — the executor must never have started.',
        );

        $events = $this->broadcaster->eventsFor((string) $run->get('id'));
        self::assertContains('run_failed', $events);
        self::assertNotContains('run_completed', $events);
    }

    #[Test]
    public function runsNormallyWhenInitiatorHasRequiredCapability(): void
    {
        $service = $this->buildService();

        $run = $service->runInline(new AgentRunDraft(
            accountId: self::ACCOUNT_HAS_PERMISSION,
            agentDefinitionId: null,
            bundle: [
                'id' => 'gated',
                'label' => 'Gated',
                'description' => '',
                'prompt' => 'hi',
                'requires_capability' => self::CAPABILITY,
            ],
            prompt: 'translate this',
        ));

        self::assertSame(
            RunStatus::Completed,
            $run->getStatus(),
            'An authorized initiator must not be blocked by the capability gate.',
        );

        self::assertNotSame(
            [],
            $this->auditRepository->findByRunId((string) $run->get('id')),
            'The executor should have run and produced audit rows.',
        );

        $events = $this->broadcaster->eventsFor((string) $run->get('id'));
        self::assertContains('run_completed', $events);
    }

    #[Test]
    public function runsNormallyWhenDefinitionHasNoCapabilityRequirement(): void
    {
        $service = $this->buildService();

        // No `requires_capability` key at all — the gate must be a no-op.
        $run = $service->runInline(new AgentRunDraft(
            accountId: self::ACCOUNT_NO_PERMISSION,
            agentDefinitionId: null,
            bundle: ['id' => 'ungated', 'label' => 'Ungated', 'description' => '', 'prompt' => 'hi'],
            prompt: 'hello',
        ));

        self::assertSame(RunStatus::Completed, $run->getStatus());
    }

    #[Test]
    public function failsClosedForAnonymousInitiatorWithoutPermission(): void
    {
        $service = $this->buildService();

        $run = $service->runInline(new AgentRunDraft(
            accountId: self::ACCOUNT_ANONYMOUS,
            agentDefinitionId: null,
            bundle: [
                'id' => 'gated',
                'label' => 'Gated',
                'description' => '',
                'prompt' => 'hi',
                'requires_capability' => self::CAPABILITY,
            ],
            prompt: 'translate this',
        ));

        self::assertSame(
            RunStatus::Failed,
            $run->getStatus(),
            'An anonymous/permission-less initiator must be refused, not silently skipped.',
        );
        self::assertSame(
            [],
            $this->auditRepository->findByRunId((string) $run->get('id')),
        );
    }

    #[Test]
    public function definitionAllowlistAdvertisesAndExecutesOnlyItsNamedTool(): void
    {
        $allowedImpl = new R18CountingTool();
        $blockedImpl = new R18CountingTool();
        $provider = new R18ToolUseProvider('allowed_tool');
        $service = $this->buildService([
            $this->tool('allowed_tool', $allowedImpl),
            $this->tool('blocked_tool', $blockedImpl),
        ], $provider);

        $run = $service->runInline(new AgentRunDraft(
            accountId: self::ACCOUNT_HAS_PERMISSION,
            agentDefinitionId: null,
            bundle: ['id' => 'tool-agent', 'label' => 'Tool agent', 'description' => '', 'prompt' => 'go', 'tools' => ['allowed_tool']],
            prompt: 'go',
        ));

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame(['allowed_tool'], array_column($provider->advertisedTools, 'name'));
        self::assertSame(1, $allowedImpl->calls);
        self::assertSame(0, $blockedImpl->calls);
    }

    #[Test]
    public function adversarialProviderCannotInvokeGloballyRegisteredOffListTool(): void
    {
        $allowedImpl = new R18CountingTool();
        $blockedImpl = new R18CountingTool();
        $provider = new R18ToolUseProvider('blocked_tool');
        $service = $this->buildService([
            $this->tool('allowed_tool', $allowedImpl),
            $this->tool('blocked_tool', $blockedImpl),
        ], $provider);

        $run = $service->runInline(new AgentRunDraft(
            accountId: self::ACCOUNT_HAS_PERMISSION,
            agentDefinitionId: null,
            bundle: ['id' => 'tool-agent', 'label' => 'Tool agent', 'description' => '', 'prompt' => 'go', 'tools' => ['allowed_tool']],
            prompt: 'go',
        ));

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame(['allowed_tool'], array_column($provider->advertisedTools, 'name'));
        self::assertSame(0, $blockedImpl->calls);
    }

    /** @param list<AgentTool> $tools */
    private function buildService(array $tools = [], ?ProviderInterface $provider = null): AgentRunService
    {
        $manifest = new PackageManifest(agentDefinitions: []);
        $registry = new AgentDefinitionRegistry($manifest);
        $toolRegistry = new class ($tools) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $tools = [];

            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->tools[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->tools[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->tools[$name] ?? throw new ToolNotFoundException(\sprintf('No tool registered (%s).', $name));
            }

            public function has(string $name): bool
            {
                return isset($this->tools[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->tools);
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
            broadcaster: $this->broadcaster,
            provider: $provider ?? new NullLlmProvider(),
            accountLoader: new CapabilityTestAccountLoader(),
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

    private function tool(string $name, R18CountingTool $impl): AgentTool
    {
        return new AgentTool(
            name: $name,
            capability: self::CAPABILITY,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
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

final class R18CountingTool extends AbstractAgentTool
{
    public int $calls = 0;

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        ++$this->calls;

        return AgentToolResult::text('ok');
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function description(): string
    {
        return 'R18 test tool';
    }
}

final class R18ToolUseProvider implements ProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $advertisedTools = [];

    private int $calls = 0;

    public function __construct(private readonly string $requestedTool) {}

    public function sendMessage(MessageRequest $request): MessageResponse
    {
        ++$this->calls;
        if ($this->calls === 1) {
            $this->advertisedTools = $request->tools;

            return new MessageResponse(
                content: [['type' => 'tool_use', 'id' => 'call-1', 'name' => $this->requestedTool, 'input' => []]],
                stopReason: 'tool_use',
                usage: ['input_tokens' => 1, 'output_tokens' => 1],
            );
        }

        return new MessageResponse(
            content: [['type' => 'text', 'text' => 'done']],
            stopReason: 'end_turn',
            usage: ['input_tokens' => 1, 'output_tokens' => 1],
        );
    }
}

/**
 * Maps fixed account ids to permission states for the gate tests.
 */
final class CapabilityTestAccountLoader implements InitiatorAccountLoaderInterface
{
    public function load(int|string $accountId): AuthorizationPrincipalInterface
    {
        if ($accountId === RequiresCapabilityGateTest::ACCOUNT_HAS_PERMISSION) {
            return new AuthorizationPrincipal(
                RequiresCapabilityGateTest::ACCOUNT_HAS_PERMISSION,
                true,
                ['authenticated'],
                [RequiresCapabilityGateTest::CAPABILITY],
                'test-capable',
            );
        }

        if ($accountId === RequiresCapabilityGateTest::ACCOUNT_ANONYMOUS) {
            return new AuthorizationPrincipal(0, false, ['anonymous'], [], 'test-anonymous');
        }

        // ACCOUNT_NO_PERMISSION and any other id: authenticated but with no
        // permissions — mirrors StubInitiatorAccountLoader.
        return new AuthorizationPrincipal($accountId, true, ['authenticated'], [], 'test-' . (string) $accountId);
    }
}

/**
 * In-memory broadcaster that records the event names per run for assertions.
 */
final class CapturingCapabilityBroadcaster implements AgentRunBroadcasterInterface
{
    /** @var array<string, list<string>> */
    private array $events = [];

    public function push(string $runId, string $event, array $data): void
    {
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
