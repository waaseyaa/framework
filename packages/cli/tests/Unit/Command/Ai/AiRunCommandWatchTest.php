<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Ai;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
use Waaseyaa\AI\Agent\Message\RunAgent;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\Provider\NullLlmProvider;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\CLI\Command\Ai\AiRunCommand;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\SseLineStreamInterface;

#[CoversClass(AiRunCommand::class)]
final class AiRunCommandWatchTest extends TestCase
{
    private DBALDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = DBALDatabase::createSqlite();
        $migrationFile = \dirname(__DIR__, 6)
            . '/packages/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $migration = require $migrationFile;
        \assert($migration instanceof Migration);
        $schema = new SchemaBuilder($this->database->getConnection());
        $migration->up($schema);
    }

    // ------------------------------------------------------------------
    // Test 1 — watch connects and prints events
    // ------------------------------------------------------------------

    #[Test]
    public function watchConnectsAndPrintsEvents(): void
    {
        $sseLines = [
            'event: agent.run.started',
            'data: {"run_id":"abc-123"}',
            '',
            'event: agent.run.terminated',
            'data: {"run_id":"abc-123","outcome":"success"}',
            '',
        ];

        $tester = $this->makeTester(fakeSseLines: $sseLines);
        $tester->execute(['hello world', '--watch']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());

        $stdout = $tester->getStdout();
        self::assertStringContainsString('agent.run.started', $stdout);
        self::assertStringContainsString('agent.run.terminated', $stdout);
        self::assertStringContainsString('abc-123', $stdout);
    }

    // ------------------------------------------------------------------
    // Test 2 — command exits after terminated event (does not block)
    // ------------------------------------------------------------------

    #[Test]
    public function watchExitsOnTerminatedEvent(): void
    {
        $sseLines = [
            'event: agent.run.started',
            'data: {"run_id":"x1"}',
            '',
            'event: agent.run.iteration',
            'data: {"step":1}',
            '',
            'event: agent.run.terminated',
            'data: {"run_id":"x1","outcome":"success"}',
            '',
            // Lines after terminated — should NOT be processed.
            'event: agent.run.extra',
            'data: {"unexpected":true}',
            '',
        ];

        $tester = $this->makeTester(fakeSseLines: $sseLines);
        $tester->execute(['test prompt', '--watch']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());

        $stdout = $tester->getStdout();
        self::assertStringContainsString('[agent.run.started]', $stdout);
        self::assertStringContainsString('[agent.run.terminated]', $stdout);
        // The extra event after terminated must not appear.
        self::assertStringNotContainsString('unexpected', $stdout);
    }

    // ------------------------------------------------------------------
    // Test 3 — watch handles stream connection failure
    // ------------------------------------------------------------------

    #[Test]
    public function watchHandlesStreamConnectionFailure(): void
    {
        $failingClient = new class implements SseLineStreamInterface {
            public function lines(string $url, array $headers = []): \Generator
            {
                throw new HttpRequestException(
                    'SSE connection failed: GET ' . $url,
                    $url,
                    'GET',
                );
                // @phpstan-ignore-next-line — yield required to make this a generator
                yield '';
            }
        };

        $tester = $this->makeTester(sseClient: $failingClient);
        $tester->execute(['my prompt', '--watch']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('SSE connection failed', $tester->getStderr());
    }

    // ------------------------------------------------------------------
    // Test 4 — without --watch, no SSE connection is attempted (regression)
    // ------------------------------------------------------------------

    #[Test]
    public function watchWithoutFlagRunsNormally(): void
    {
        $neverCalledClient = new class implements SseLineStreamInterface {
            public bool $called = false;

            public function lines(string $url, array $headers = []): \Generator
            {
                $this->called = true;
                yield '';
            }
        };

        $tester = $this->makeTester(sseClient: $neverCalledClient);
        $tester->execute(['hello world']); // no --watch

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertFalse($neverCalledClient->called, 'streamGet must not be called without --watch');
        self::assertStringContainsString('run_id: ', $tester->getStdout());
        self::assertStringNotContainsString('Watching', $tester->getStdout());
    }

    #[Test]
    public function repeatedInvocationClearsPriorInterruptState(): void
    {
        $tester = $this->makeTester(
            fakeSseLines: [
                'event: agent.run.terminated',
                'data: {"outcome":"success"}',
                '',
            ],
            interrupted: true,
        );

        $tester->execute(['second invocation', '--watch']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('[agent.run.terminated]', $tester->getStdout());
        self::assertStringNotContainsString('Interrupted', $tester->getStdout());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string>|null $fakeSseLines
     */
    private function makeTester(
        ?SseLineStreamInterface $sseClient = null,
        ?array $fakeSseLines = null,
        bool $interrupted = false,
    ): CliTester {
        if ($sseClient === null) {
            $lines = $fakeSseLines ?? [];
            $sseClient = new class ($lines) implements SseLineStreamInterface {
                /** @param list<string> $lines */
                public function __construct(private readonly array $lines) {}

                public function lines(string $url, array $headers = []): \Generator
                {
                    foreach ($this->lines as $line) {
                        yield $line;
                    }
                }
            };
        }

        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();
        $service = $this->buildService($runRepo, $auditRepo);
        $registry = new AgentDefinitionRegistry(new PackageManifest());

        $command = new AiRunCommand(
            runService: $service,
            definitionRegistry: $registry,
            workerReader: new \Waaseyaa\Tests\Support\AgentRunWorkerReaderFixture(),
            aiConfig: ['providers' => [['id' => 'null', 'model_default' => 'noop']]],
            sseClient: $sseClient,
            baseUrl: 'http://localhost:8000',
        );
        if ($interrupted) {
            $property = new \ReflectionProperty($command, 'interrupted');
            $property->setValue($command, true);
        }

        return CliTester::for(
            $this->commandDefinition(),
            new class ($command) implements ContainerInterface {
                public function __construct(private readonly AiRunCommand $cmd) {}

                public function get(string $id): mixed
                {
                    if ($id === AiRunCommand::class) {
                        return $this->cmd;
                    }
                    throw new \RuntimeException("Not bound: {$id}");
                }

                public function has(string $id): bool
                {
                    return $id === AiRunCommand::class;
                }
            },
        );
    }

    private function buildService(
        AgentRunRepository $runRepo,
        AgentAuditLogRepository $auditRepo,
    ): AgentRunService {
        $toolRegistry = new class implements ToolRegistryInterface {
            public function register(AgentTool $tool): void
            {
                unset($tool);
            }
            public function get(string $name): AgentTool
            {
                throw new ToolNotFoundException("no tools: {$name}");
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
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            sleepMs: static function (int $ms): void {
                unset($ms);
            },
        );

        $registry = new AgentDefinitionRegistry(new PackageManifest());
        $handler = new RunAgentHandler(
            runRepository: $runRepo,
            executor: $executor,
            definitionRegistry: $registry,
            toolRegistry: $toolRegistry,
            broadcaster: new InertBroadcasterForWatchTest(),
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
            runRepository: $runRepo,
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

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'ai:run',
            description: 'Run an AI agent (FR-005).',
            arguments: [
                new HandlerArgument(name: 'prompt', mode: HandlerArgumentMode::Required),
            ],
            options: [
                new HandlerOption(name: 'inline', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'agent', mode: HandlerOptionMode::Required, default: ''),
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None),
                new HandlerOption(name: 'watch', mode: HandlerOptionMode::None),
                new HandlerOption(
                    name: 'destructive-approval',
                    mode: HandlerOptionMode::Required,
                    default: 'none',
                ),
                new HandlerOption(name: 'account', mode: HandlerOptionMode::Required, default: ''),
            ],
            handler: [AiRunCommand::class, 'execute'],
        );
    }
}

/**
 * @internal
 */
final class InertBroadcasterForWatchTest implements AgentRunBroadcasterInterface
{
    public function push(string $runId, string $event, array $data): void
    {
        unset($runId, $event, $data);
    }
}
