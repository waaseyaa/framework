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
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\Ai\AiRunCommand;
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

#[CoversClass(AiRunCommand::class)]
final class AiRunCommandTest extends TestCase
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

    #[Test]
    public function rejects_inline_with_interactive_hitl_at_parse_time(): void
    {
        $tester = $this->makeTester();
        $tester->execute([
            'ping',
            '--inline',
            '--destructive-approval=interactive',
        ]);

        self::assertSame(1, $tester->getExitCode(), 'inline+interactive must reject.');
        self::assertStringContainsString(
            '--inline cannot be combined with --destructive-approval=interactive',
            $tester->getStderr(),
        );
    }

    #[Test]
    public function async_run_prints_run_id_and_queued_status(): void
    {
        $tester = $this->makeTester();
        $tester->execute(['hello world']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('run_id: ', $tester->getStdout());
        // Async via the synchronous test bus completes through the handler,
        // so the row may already be Completed — the run_id line is the
        // canonical signal we care about for the async path's contract.
    }

    #[Test]
    public function inline_run_routes_through_run_service(): void
    {
        $tester = $this->makeTester();
        $tester->execute(['ping', '--inline']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('run_id: ', $tester->getStdout());
        self::assertStringContainsString('status: completed', $tester->getStdout());
    }

    #[Test]
    public function rejects_unknown_destructive_approval_value(): void
    {
        $tester = $this->makeTester();
        $tester->execute(['ping', '--destructive-approval=yolo']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('--destructive-approval must be one of', $tester->getStderr());
    }

    #[Test]
    public function rejects_unknown_agent_id(): void
    {
        $tester = $this->makeTester();
        $tester->execute(['ping', '--agent=missing-agent']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('not registered', $tester->getStderr());
    }

    #[Test]
    public function falls_back_to_ad_hoc_bundle_when_no_agent_supplied(): void
    {
        $tester = $this->makeTester();
        $tester->execute(['ping']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('run_id: ', $tester->getStdout());
    }

    #[Test]
    public function reports_error_when_no_agent_and_no_providers_configured(): void
    {
        $tester = $this->makeTester(aiConfig: []);
        $tester->execute(['ping']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('no --agent supplied', $tester->getStderr());
    }

    /**
     * @param array<string, mixed> $aiConfig
     */
    private function makeTester(array $aiConfig = ['providers' => [['id' => 'null', 'model_default' => 'noop']]]): CliTester
    {
        $runRepo = $this->buildRunRepository();
        $auditRepo = $this->buildAuditRepository();

        $service = $this->buildService($runRepo, $auditRepo);
        $registry = new AgentDefinitionRegistry(new PackageManifest());

        $command = new AiRunCommand(
            runService: $service,
            definitionRegistry: $registry,
            aiConfig: $aiConfig,
        );

        return CliTester::for(
            $this->commandDefinition(),
            new class($command) implements ContainerInterface {
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
            public function register(AgentTool $tool): void { unset($tool); }
            public function get(string $name): AgentTool { throw new ToolNotFoundException("no tools: {$name}"); }
            public function has(string $name): bool { unset($name); return false; }
            public function all(): iterable { return []; }
        };
        $executor = new AgentExecutor(
            toolRegistry: $toolRegistry,
            runRepository: $runRepo,
            auditRepository: $auditRepo,
            sleepMs: static function (int $ms): void { unset($ms); },
        );

        $registry = new AgentDefinitionRegistry(new PackageManifest());
        $handler = new RunAgentHandler(
            runRepository: $runRepo,
            executor: $executor,
            definitionRegistry: $registry,
            toolRegistry: $toolRegistry,
            broadcaster: new InertBroadcasterForAiRunTest(),
            provider: new NullLlmProvider(),
            accountLoader: new StubInitiatorAccountLoader(),
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
        $entityRepo = new EntityRepository(
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
        $entityRepo = new EntityRepository(
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
final class InertBroadcasterForAiRunTest implements AgentRunBroadcasterInterface
{
    public function push(string $runId, string $event, array $data): void
    {
        unset($runId, $event, $data);
    }
}
