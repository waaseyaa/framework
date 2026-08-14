<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\Config\ConfigDiffCommand;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\CLI\Command\Config\ConfigResetCommand;
use Waaseyaa\CLI\Command\Config\ConfigStatusCommand;
use Waaseyaa\CLI\Command\Config\ConfigValidateCommand;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\Sync\ConfigDiffer;
use Waaseyaa\Config\Sync\ConfigExporter;
use Waaseyaa\Config\Sync\ConfigImporter;
use Waaseyaa\Config\Sync\ConfigResetter;
use Waaseyaa\Config\Sync\ConfigStatusReporter;
use Waaseyaa\Config\Sync\ConfigSyncRepository;
use Waaseyaa\Config\Sync\ConfigSyncValidator;
use Waaseyaa\EntityStorage\Config\TestingActiveConfigurationBridge;
use Waaseyaa\EntityStorage\Config\TestingConfigurationGenerationResolver;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

#[CoversClass(ConfigCacheDbAuditServiceProvider::class)]
final class ConfigCacheDbAuditServiceProviderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cli_config_provider_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function db_init_factory_preserves_the_public_command_contract(): void
    {
        $command = ConfigCacheDbAuditServiceProvider::dbInitCommand('/project');

        self::assertSame('db:init', $command->getName());
        self::assertSame(
            'Initialize the database on first deploy: apply pending migrations and materialize every registered entity type\'s schema.',
            $command->getDescription(),
        );

        $options = $command->handlerOptions();
        self::assertSame(['dry-run', 'sync-schema', 'no-sync-schema'], array_column($options, 'name'));
        self::assertSame(
            [HandlerOptionMode::None, HandlerOptionMode::None, HandlerOptionMode::None],
            array_column($options, 'mode'),
        );
    }

    #[Test]
    public function itPublishesExactlyOneModernAdapterForEveryReservedConfigVerb(): void
    {
        $provider = new ConfigCacheDbAuditServiceProvider();
        $commands = [];
        $importOptions = [];
        foreach ($provider->consoleCommands() as $command) {
            if (str_starts_with((string) $command->name, 'config:')) {
                $commands[(string) $command->name] = $command->sourceClass();
            }
            if ($command->name === 'config:import') {
                $importOptions = $command->handlerOptions();
            }
        }

        self::assertSame([
            'config:export' => \Waaseyaa\CLI\Command\Config\ConfigExportCommand::class,
            'config:import' => \Waaseyaa\CLI\Command\Config\ConfigImportCommand::class,
            'config:diff' => \Waaseyaa\CLI\Command\Config\ConfigDiffCommand::class,
            'config:status' => \Waaseyaa\CLI\Command\Config\ConfigStatusCommand::class,
            'config:validate' => \Waaseyaa\CLI\Command\Config\ConfigValidateCommand::class,
            'config:reset' => \Waaseyaa\CLI\Command\Config\ConfigResetCommand::class,
        ], $commands);
        self::assertSame([
            'dry-run',
            'delete-orphans',
            'halt-on-error',
            'no-dependency-check',
            'activation-request-id',
            'expected-generation',
            'expected-sequence',
        ], array_column($importOptions, 'name'));
        self::assertSame([
            HandlerOptionMode::None,
            HandlerOptionMode::None,
            HandlerOptionMode::None,
            HandlerOptionMode::None,
            HandlerOptionMode::Required,
            HandlerOptionMode::Required,
            HandlerOptionMode::Required,
        ], array_column($importOptions, 'mode'));
    }

    #[Test]
    public function itRegistersAndResolvesTheCompleteConfigurationCommandGraph(): void
    {
        $base = new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:test',
            syncPath: $this->tempDir,
            selectorProvenance: ['testing'],
        );
        $context = new ConfigurationAuthorityContext(
            authorityId: $base->authorityId,
            databaseIdentity: $base->databaseIdentity,
            syncPath: $base->syncPath,
            selectorProvenance: $base->selectorProvenance,
            activeGenerationId: TestingConfigurationGenerationResolver::generationId($base),
            activationSequence: 1,
        );
        $bridge = new TestingActiveConfigurationBridge($context);
        $services = new class ($context, $bridge) implements KernelServicesInterface {
            public function __construct(
                private readonly ConfigurationAuthorityContext $context,
                private readonly ActiveConfigurationBridgeInterface $bridge,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ConfigurationAuthorityContext::class => $this->context,
                    ActiveConfigurationBridgeInterface::class => $this->bridge,
                    default => null,
                };
            }
        };

        $provider = new ConfigCacheDbAuditServiceProvider();
        $provider->setKernelContext($this->tempDir, ['environment' => 'testing'], []);
        $provider->setKernelServices($services);
        $provider->register();

        $expected = [
            ConfigSyncRepository::class,
            ConfigExporter::class,
            ConfigImporter::class,
            ConfigDiffer::class,
            ConfigStatusReporter::class,
            ConfigSyncValidator::class,
            ConfigResetter::class,
            ConfigExportCommand::class,
            ConfigImportCommand::class,
            ConfigDiffCommand::class,
            ConfigStatusCommand::class,
            ConfigValidateCommand::class,
            ConfigResetCommand::class,
        ];
        foreach ($expected as $abstract) {
            self::assertInstanceOf($abstract, $provider->resolve($abstract));
            self::assertSame($provider->resolve($abstract), $provider->resolve($abstract));
        }

        $requirements = iterator_to_array($provider->capabilityRequirements());
        self::assertCount(1, $requirements);
        self::assertSame('configuration.authority.v1', $requirements[0]->id);
        self::assertSame(1, $requirements[0]->minimumVersion);
        self::assertSame(1, $requirements[0]->maximumVersion);
    }

    #[Test]
    public function productionImporterRefusesWhenTransactionalActivationAuthorityIsMissing(): void
    {
        $base = new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:production-refusal',
            syncPath: $this->tempDir,
            selectorProvenance: ['production'],
        );
        $context = new ConfigurationAuthorityContext(
            authorityId: $base->authorityId,
            databaseIdentity: $base->databaseIdentity,
            syncPath: $base->syncPath,
            selectorProvenance: $base->selectorProvenance,
            activeGenerationId: TestingConfigurationGenerationResolver::generationId($base),
            activationSequence: 1,
        );
        $bridge = new TestingActiveConfigurationBridge($context);
        $services = new class ($context, $bridge) implements KernelServicesInterface {
            public function __construct(
                private readonly ConfigurationAuthorityContext $context,
                private readonly ActiveConfigurationBridgeInterface $bridge,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ConfigurationAuthorityContext::class => $this->context,
                    ActiveConfigurationBridgeInterface::class => $this->bridge,
                    default => null,
                };
            }
        };

        $provider = new ConfigCacheDbAuditServiceProvider();
        $provider->setKernelContext($this->tempDir, ['environment' => 'production'], []);
        $provider->setKernelServices($services);
        $provider->register();

        $this->expectException(ConfigurationAuthorityUnavailableException::class);
        $this->expectExceptionMessage('transactional configuration activation authority');
        $provider->resolve(ConfigImporter::class);
    }
}
