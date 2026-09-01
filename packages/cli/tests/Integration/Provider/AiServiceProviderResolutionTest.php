<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\AI\Agent\AiAgentEntityServiceProvider;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\CLI\Command\Ai\AiPurgeRunsCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Provider\AiServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/** Production-bus regression coverage for architecture-integrity issue #2771. */
#[CoversClass(AiServiceProvider::class)]
final class AiServiceProviderResolutionTest extends TestCase
{
    #[Test]
    public function an_invalid_manager_binding_fails_closed(): void
    {
        $provider = new AiServiceProvider();
        $provider->setKernelServices(new readonly class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return $abstract === \Waaseyaa\Entity\EntityTypeManagerInterface::class
                    ? new \stdClass()
                    : null;
            }
        });
        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('invalid entity type manager');
        $provider->resolve(AiPurgeRunsCommand::class);
    }

    #[Test]
    public function provider_bound_purge_resolves_and_preserves_live_or_recent_runs(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $fieldRegistry = new FieldDefinitionRegistry();
        $logger = new NullLogger();
        $manager = new EntityTypeManagerFactory()->build(
            database: $database,
            dispatcher: $dispatcher,
            fieldRegistry: $fieldRegistry,
            logger: $logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => null,
            accountContextAttacher: static function (): void {},
            fieldReadScope: new AccountFieldReadScope(),
        );

        $entityProvider = new AiAgentEntityServiceProvider();
        $cliProvider = new AiServiceProvider();
        $providers = [$entityProvider, $cliProvider];
        $services = new ProviderRegistryKernelServices(
            entityTypeManager: $manager,
            database: $database,
            dispatcher: $dispatcher,
            logger: $logger,
            providersAccessor: static fn(): array => $providers,
        );
        foreach ($providers as $provider) {
            $provider->setKernelContext(sys_get_temp_dir(), [
                'environment' => 'testing',
                'ai' => ['run_retention_days' => 30],
            ], []);
            $provider->setKernelServices($services);
            $provider->register();
            foreach ($provider->getEntityTypes() as $entityType) {
                $manager->registerEntityType($entityType);
            }
        }

        self::assertNull(
            $services->get(EntityRepositoryInterface::class),
            'The kernel must not invent an arbitrary context-free repository binding.',
        );
        self::assertSame(
            PrimaryStorageBackend::SQL_COLUMN,
            $manager->getDefinition('agent_run')->getPrimaryStorageBackend(),
            'agent_run has a column-oriented migration and direct-query repository contract.',
        );
        self::assertSame(
            PrimaryStorageBackend::SQL_COLUMN,
            $manager->getDefinition('agent_audit_log')->getPrimaryStorageBackend(),
            'agent_audit_log has the same column-oriented persistence contract.',
        );

        $migration = require \dirname(__DIR__, 4)
            . '/ai-agent/migrations/2026_05_18_000001_create_agent_run.php';
        $baseColumnsMigration = require \dirname(__DIR__, 4)
            . '/ai-agent/migrations/2026_09_01_000001_add_entity_base_columns.php';
        \assert($migration instanceof Migration);
        \assert($baseColumnsMigration instanceof Migration);
        $migration->up(new SchemaBuilder($database->getConnection()));
        $baseColumnsMigration->up(new SchemaBuilder($database->getConnection()));
        new EntitySchemaSyncRunner($database, $fieldRegistry, $logger)
            ->run($manager->getDefinitions());
        EntityMutationAuthoritySchema::ensure($database);

        $runRepository = $entityProvider->resolve(AgentRunRepository::class);
        $now = new \DateTimeImmutable('now');
        $runRepository->save($this->makeRun('old-terminal', $now->modify('-40 days'), RunStatus::Completed));
        $runRepository->save($this->makeRun('old-live', $now->modify('-40 days'), RunStatus::Running));
        $runRepository->save($this->makeRun('recent-terminal', $now->modify('-1 day'), RunStatus::Completed));

        $command = $cliProvider->resolve(AiPurgeRunsCommand::class);
        self::assertInstanceOf(AiPurgeRunsCommand::class, $command);
        self::assertSame($command, $cliProvider->resolve(AiPurgeRunsCommand::class));
        $runEntityRepository = new \ReflectionProperty(AiPurgeRunsCommand::class, 'runEntityRepository');
        self::assertSame($manager->getRepository('agent_run'), $runEntityRepository->getValue($command));

        $tester = CliTester::for($this->commandDefinition(), new class($command) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly AiPurgeRunsCommand $command) {}

            public function get(string $id): mixed
            {
                if ($id === AiPurgeRunsCommand::class) {
                    return $this->command;
                }
                throw new \RuntimeException("Not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === AiPurgeRunsCommand::class;
            }
        });
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Deleted 1 runs', $tester->getStdout());
        self::assertNull($runRepository->find('old-terminal'));
        self::assertNotNull($runRepository->find('old-live'));
        self::assertNotNull($runRepository->find('recent-terminal'));
    }

    private function makeRun(string $id, \DateTimeImmutable $queuedAt, RunStatus $status): AgentRun
    {
        $run = new AgentRun([
            'id' => $id,
            'account_id' => 0,
            'agent_definition_id' => null,
            'bundle_json' => '{}',
            'status' => $status->value,
            'destructive_approval' => HitlMode::None->value,
            'pending_approval_call_id' => null,
            'approval_expires_at' => null,
            'prompt' => 'provider composition proof',
            'response' => null,
            'transcript_json' => '[]',
            'token_usage_in' => 0,
            'token_usage_out' => 0,
            'cost_cents' => null,
            'tool_call_count' => 0,
            'queued_at' => $queuedAt->format('Y-m-d H:i:s.uP'),
            'started_at' => $status === RunStatus::Running ? $queuedAt->format('Y-m-d H:i:s.uP') : null,
            'finished_at' => $status->isTerminal() ? $queuedAt->format('Y-m-d H:i:s.uP') : null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $run->enforceIsNew(true);

        return $run;
    }

    private function commandDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'ai:purge-runs',
            description: 'Purge old AgentRun + AgentAuditLog rows.',
            options: [new HandlerOption(
                name: 'retention-days',
                mode: HandlerOptionMode::Required,
                default: '',
            )],
            handler: [AiPurgeRunsCommand::class, 'execute'],
        );
    }
}
