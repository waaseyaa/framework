<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Agent\Mcp\McpCapabilitiesSource;
use Waaseyaa\AI\Agent\Mcp\McpServiceProvider;
use Waaseyaa\AI\Tools\AiToolsServiceProvider;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Config\StorageInterface as ConfigStorageInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\InMemoryConfigStorage;
use Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture\StubMcpServerHttpClient;

#[CoversNothing]
final class McpServiceProviderManifestTest extends TestCase
{
    #[Test]
    public function packageManifestDiscoversAndBootsOutboundMcpProvider(): void
    {
        $packageRoot = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($packageRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertContains(McpServiceProvider::class, $manifest['extra']['waaseyaa']['providers']);

        $provider = new McpServiceProvider();
        $provider->setKernelContext(dirname($packageRoot, 2), ['environment' => 'testing'], []);
        $provider->register();
        $provider->boot();

        self::assertInstanceOf(McpCapabilitiesSource::class, $provider->resolve(McpCapabilitiesSource::class));
    }

    #[Test]
    public function productionProfileCannotSilentlyFallBackToNullConfiguration(): void
    {
        $provider = new McpServiceProvider();
        $provider->setKernelContext(dirname(__DIR__, 5), ['environment' => 'production'], []);
        $provider->register();

        $this->expectException(ConfigurationAuthorityUnavailableException::class);
        $this->expectExceptionMessage('NullConfigStorage is permitted only');
        $provider->resolve(McpCapabilitiesSource::class);
    }

    #[Test]
    public function productionProviderOrderRegistersRemoteTools(): void
    {
        $http = new StubMcpServerHttpClient();
        $http->registerTool('echo', 'Echo input', ['type' => 'object'], static fn(array $args): array => [
            'isError' => false,
            'content' => [['type' => 'text', 'text' => (string) ($args['message'] ?? '')]],
        ]);
        $storage = new InMemoryConfigStorage();
        $storage->write(McpServersConfig::CONFIG_NAME, [
            McpServersConfig::ITEMS_KEY => [[
                'alias' => 'stub',
                'url' => 'https://stub.invalid/mcp',
                'auth_header_env_var' => '',
                'enabled' => true,
                'capability_prefix' => 'tool.mcp.stub',
            ]],
        ]);
        McpHostServicesProvider::$http = $http;
        McpHostServicesProvider::$storage = $storage;

        $dispatcher = new EventDispatcher();
        $registry = new ProviderRegistry(new NullLogger());
        $providers = $registry->discoverAndRegister(
            manifest: new PackageManifest(providers: [
                McpServiceProvider::class,
                AiToolsServiceProvider::class,
                McpHostServicesProvider::class,
            ]),
            projectRoot: dirname(__DIR__, 5),
            config: [],
            entityTypeManager: new EntityTypeManager($dispatcher),
            database: DBALDatabase::createSqlite(':memory:'),
            dispatcher: $dispatcher,
        );
        $registry->boot($providers);

        $toolsProvider = $providers[1];
        self::assertInstanceOf(AiToolsServiceProvider::class, $toolsProvider);
        $tools = $toolsProvider->resolve(ToolRegistryInterface::class);
        self::assertTrue($tools->has('stub.echo'));
    }
}

final class McpHostServicesProvider extends ServiceProvider
{
    public static ?HttpClientInterface $http = null;
    public static ?ConfigStorageInterface $storage = null;

    public function register(): void
    {
        $this->singleton(HttpClientInterface::class, static fn(): HttpClientInterface => self::$http ?? throw new \LogicException('HTTP fixture missing.'));
        $this->singleton(ConfigStorageInterface::class, static fn(): ConfigStorageInterface => self::$storage ?? throw new \LogicException('Config fixture missing.'));
    }
}
