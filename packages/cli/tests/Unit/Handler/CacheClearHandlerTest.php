<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\CacheFactoryInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Runtime\StableRuntimeEpoch;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * @internal Every real-backend fixture is scoped to this test's own SQLite
 * connection; nothing here touches the framework's own cache tables.
 */
#[CoversClass(CacheClearHandler::class)]
final class CacheClearHandlerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Unit-level tests against a hand-built handler + mocked factory: fast
    // coverage of message/exit-code shape and the unchanged --bin/--tags
    // surface. The discriminating "does it actually clear real state" proof
    // lives in the real-composition tests below (#3025 quality bar).
    // ------------------------------------------------------------------

    #[Test]
    public function clearsAllConfiguredBins(): void
    {
        $mockBackend = $this->createMock(CacheBackendInterface::class);
        $mockBackend->expects($this->exactly(2))->method('deleteAll');

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);
        $cacheConfiguration->setBackendForBin('discovery', MemoryBackend::class);

        $mockFactory = $this->createStub(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Cache bin "render" cleared.', $tester->getStdout());
        $this->assertStringContainsString('Cache bin "discovery" cleared.', $tester->getStdout());
        $this->assertStringContainsString('All cache bins cleared.', $tester->getStdout());
    }

    #[Test]
    public function noConfiguredBinsReportsNothingToClear(): void
    {
        $mockFactory = $this->createMock(CacheFactoryInterface::class);
        $mockFactory->expects($this->never())->method('get');

        $tester = $this->createTester($mockFactory, new CacheConfiguration());
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No cache bins are configured.', $tester->getStdout());
    }

    #[Test]
    public function clearsSpecificConfiguredBin(): void
    {
        $mockBackend = $this->createMock(CacheBackendInterface::class);
        $mockBackend->expects($this->once())->method('deleteAll');

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);

        $mockFactory = $this->createMock(CacheFactoryInterface::class);
        $mockFactory->expects($this->once())
            ->method('get')
            ->with('render')
            ->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->executeMap(['--bin' => 'render']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Cache bin "render" cleared.', $tester->getStdout());
    }

    #[Test]
    public function explicitUnconfiguredBinIsNotReportedAsCleared(): void
    {
        $mockFactory = $this->createMock(CacheFactoryInterface::class);
        $mockFactory->expects($this->never())->method('get');

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->executeMap(['--bin' => 'nonexistent']);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('Cache bin "nonexistent" is not configured; nothing to clear.', $tester->getStdout());
        $this->assertStringNotContainsString('cleared.', $tester->getStdout());
    }

    #[Test]
    public function invalidatesByTagsForTagAwareConfiguredBins(): void
    {
        $mockBackend = $this->createMock(TagAwareCacheInterface::class);
        $mockBackend->expects($this->exactly(2))
            ->method('invalidateByTags')
            ->with(['render']);

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);
        $cacheConfiguration->setBackendForBin('discovery', MemoryBackend::class);

        $mockFactory = $this->createStub(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->executeMap(['--tags' => 'render']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('invalidated by tags: render', $tester->getStdout());
    }

    #[Test]
    public function tagInvalidationSkipsNonTagAwareBinAndReportsWhenNoneQualify(): void
    {
        $mockBackend = $this->createMock(CacheBackendInterface::class); // not tag-aware
        $mockBackend->expects($this->never())->method('deleteAll');

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);

        $mockFactory = $this->createStub(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturn($mockBackend);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->executeMap(['--bin' => 'render', '--tags' => 'foo']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Cache bin "render" is not tag-aware; skipping.', $tester->getStdout());
        $this->assertStringContainsString('No selected cache bins support tag invalidation.', $tester->getStdout());
    }

    #[Test]
    public function backendThatThrowsIsReportedAsFailureWithoutAbortingOtherBins(): void
    {
        $goodBackend = $this->createMock(CacheBackendInterface::class);
        $goodBackend->expects($this->once())->method('deleteAll');

        $throwingBackend = $this->createStub(CacheBackendInterface::class);
        $throwingBackend->method('deleteAll')->willThrowException(new \RuntimeException('backend refuses to clear'));

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);
        $cacheConfiguration->setBackendForBin('discovery', MemoryBackend::class);

        $mockFactory = $this->createStub(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturnMap([
            ['render', $goodBackend],
            ['discovery', $throwingBackend],
        ]);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->execute([]);

        // Partial failure: non-zero exit, both outcomes are named truthfully,
        // and the good bin is never claimed to have failed nor vice versa.
        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('Cache bin "render" cleared.', $tester->getStdout());
        $this->assertStringContainsString('Cache bin "discovery" failed to clear: backend refuses to clear', $tester->getStdout());
        $this->assertStringContainsString('Partially cleared: 1 of 2 cache bins failed (discovery).', $tester->getStdout());
        $this->assertStringNotContainsString('All cache bins cleared.', $tester->getStdout());
    }

    #[Test]
    public function allBinsThrowingIsTotalFailure(): void
    {
        $throwingBackend = $this->createStub(CacheBackendInterface::class);
        $throwingBackend->method('deleteAll')->willThrowException(new \RuntimeException('nope'));

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setBackendForBin('render', MemoryBackend::class);

        $mockFactory = $this->createStub(CacheFactoryInterface::class);
        $mockFactory->method('get')->willReturn($throwingBackend);

        $tester = $this->createTester($mockFactory, $cacheConfiguration);
        $tester->execute([]);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('No cache bins were cleared.', $tester->getStdout());
        $this->assertStringNotContainsString('All cache bins cleared.', $tester->getStdout());
    }

    private function createTester(CacheFactoryInterface $factory, CacheConfiguration $cacheConfiguration): CliTester
    {
        $handler = new CacheClearHandler($factory, $cacheConfiguration);
        $definition = new HandlerCommand(
            name: 'cache:clear',
            description: 'Clear one or all cache bins',
            options: [
                new HandlerOption(name: 'bin', shortcut: 'b', mode: HandlerOptionMode::Required, description: 'Clear a specific cache bin instead of all bins'),
                new HandlerOption(name: 'tags', mode: HandlerOptionMode::Required, description: 'Invalidate cache entries by comma-separated tags'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }
            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($definition, $container);
    }

    // ------------------------------------------------------------------
    // Real-composition tests (#3025 quality bar): the actual registered
    // `cache:clear` command, resolved through the production
    // KernelHandlerContainer, over a real CacheFactory/CacheConfiguration
    // and real backends (DatabaseBackend over an in-memory SQLite
    // connection, MemoryBackend). Assertions read backend state directly —
    // not stdout alone — so a bin merely being *named* in output can never
    // pass for it having been genuinely cleared.
    // ------------------------------------------------------------------

    #[Test]
    public function realCommandClearsStandardAndApplicationContributedBinsButLeavesUnconfiguredAndUnrelatedStateIntact(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        RuntimeSchemaMigrations::cachePdo($pdo);

        $cacheConfiguration = new CacheConfiguration();
        // Standard framework-shipped bins.
        $cacheConfiguration->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend($pdo, 'cache_render'));
        $cacheConfiguration->setFactoryForBin('discovery', fn(): DatabaseBackend => new DatabaseBackend($pdo, 'cache_discovery'));
        // An application-contributed bin an app service provider might add
        // via its own setFactoryForBin() call — not framework-shipped, and
        // registered under a name the handler has never seen before. Its
        // discovery and clearing proves getConfiguredBins() is genuine
        // dynamic enumeration, not a second hardcoded list wearing a new name.
        $cacheConfiguration->setFactoryForBin('app_widget_catalog', fn(): DatabaseBackend => new DatabaseBackend($pdo, 'cache_app_widget_catalog'));

        $cacheFactory = new CacheFactory($cacheConfiguration);

        // Seed real entries in every configured bin, plus an entry in a bin
        // the application does NOT configure (proving an absent bin is
        // never touched) via a second, independent CacheFactory pointed at
        // the same underlying table — distinguishing "not enumerated" from
        // "enumerated but the entry happened to survive".
        $renderBackend = $cacheFactory->get('render');
        $discoveryBackend = $cacheFactory->get('discovery');
        $appBinBackend = $cacheFactory->get('app_widget_catalog');
        $renderBackend->set('home-page', '<html>rendered</html>');
        $discoveryBackend->set('api-catalog', ['routes' => ['/a', '/b']]);
        $appBinBackend->set('widget-42', ['title' => 'Weather']);

        $unconfiguredFactory = new CacheFactory();
        $unconfiguredBackend = $unconfiguredFactory->get('search_index'); // never registered on $cacheConfiguration
        $unconfiguredBackend->set('doc-1', 'unrelated application data');

        $this->assertNotFalse($renderBackend->get('home-page'));
        $this->assertNotFalse($discoveryBackend->get('api-catalog'));
        $this->assertNotFalse($appBinBackend->get('widget-42'));
        $this->assertNotFalse($unconfiguredBackend->get('doc-1'));

        $projectRoot = $this->createMinimalProjectRoot();
        ApplicationCacheProvider::install($cacheFactory);
        try {
            $kernel = new class ($projectRoot) extends AbstractKernel {
                public function publicBoot(): void
                {
                    $this->boot();
                }

                public function cacheFactoryForHttp(RuntimeEpochInterface $runtimeEpoch): CacheFactory
                {
                    return $this->buildCacheFactory($runtimeEpoch);
                }

                protected function compileManifest(): void
                {
                    $this->manifest = new PackageManifest(providers: [ApplicationCacheProvider::class]);
                }
            };
            $kernel->publicBoot();

            // The provider-discovered application factory is the one canonical
            // composition returned to the HTTP boot path and to CLI handlers.
            // No test-only kernel binding can conceal provider precedence here.
            $this->assertSame($cacheFactory, $kernel->cacheFactoryForHttp(new StableRuntimeEpoch()));
            $container = $kernel->buildHandlerContainer();
            $this->assertSame($cacheFactory, $container->get(CacheFactoryInterface::class));
            $this->assertSame($cacheConfiguration, $container->get(CacheConfiguration::class));

            $definition = $this->findCacheClearDefinition();
            $tester = CliTester::for($definition, $container);
            $tester->execute([]);

            $this->assertSame(0, $tester->getExitCode());
            $output = $tester->getStdout();
            $this->assertStringContainsString('Cache bin "render" cleared.', $output);
            $this->assertStringContainsString('Cache bin "discovery" cleared.', $output);
            $this->assertStringContainsString('Cache bin "app_widget_catalog" cleared.', $output);
            $this->assertStringContainsString('All cache bins cleared.', $output);
            // The unconfigured bin was never enumerated, so it is never named.
            $this->assertStringNotContainsString('search_index', $output);

            // The discriminating assertion: real state is gone, not merely a
            // line of stdout claiming it is.
            $this->assertFalse($renderBackend->get('home-page'));
            $this->assertFalse($discoveryBackend->get('api-catalog'));
            $this->assertFalse($appBinBackend->get('widget-42'));

            // Absent/unrelated application data is untouched.
            $this->assertNotFalse($unconfiguredBackend->get('doc-1'));
        } finally {
            ApplicationCacheProvider::reset();
            new Filesystem()->remove($projectRoot);
        }
    }

    #[Test]
    public function realCommandReportsBackendFailureTruthfullyAndLeavesItsEntriesIntact(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        RuntimeSchemaMigrations::cachePdo($pdo);

        $refusingBackend = new class implements CacheBackendInterface {
            public bool $stillHasData = true;

            public function get(string $cid): \Waaseyaa\Cache\CacheItem|false
            {
                return $this->stillHasData
                    ? new \Waaseyaa\Cache\CacheItem($cid, 'undeletable', time(), self::PERMANENT, [], true)
                    : false;
            }
            public function getMultiple(array &$cids): array
            {
                return [];
            }
            public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void {}
            public function delete(string $cid): void {}
            public function deleteMultiple(array $cids): void {}
            public function deleteAll(): void
            {
                throw new \RuntimeException('storage backend unreachable');
            }
            public function invalidate(string $cid): void {}
            public function invalidateMultiple(array $cids): void {}
            public function invalidateAll(): void {}
            public function removeBin(): void {}
        };

        $cacheConfiguration = new CacheConfiguration();
        $cacheConfiguration->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend($pdo, 'cache_render'));
        $cacheConfiguration->setFactoryForBin('broken_bin', static fn() => $refusingBackend);

        $cacheFactory = new CacheFactory($cacheConfiguration);
        $renderBackend = $cacheFactory->get('render');
        $renderBackend->set('home-page', 'value');
        $this->assertNotFalse($renderBackend->get('home-page'));
        $this->assertTrue($refusingBackend->get('anything') !== false);

        $container = new KernelHandlerContainer(
            providers: [],
            kernelBindings: [
                CacheFactoryInterface::class => static fn(): CacheFactory => $cacheFactory,
                CacheConfiguration::class => static fn(): CacheConfiguration => $cacheConfiguration,
            ],
        );

        $definition = $this->findCacheClearDefinition();
        $tester = CliTester::for($definition, $container);
        $tester->execute([]);

        // One failing backend does not abort the run, and the exit status
        // and output both truthfully reflect the partial failure.
        $this->assertSame(1, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('Cache bin "render" cleared.', $output);
        $this->assertStringContainsString('Cache bin "broken_bin" failed to clear: storage backend unreachable', $output);
        $this->assertStringContainsString('Partially cleared: 1 of 2 cache bins failed (broken_bin).', $output);
        $this->assertStringNotContainsString('All cache bins cleared.', $output);

        // The bin that actually cleared lost its data; the bin that refused
        // to clear still has it.
        $this->assertFalse($renderBackend->get('home-page'));
        $this->assertNotFalse($refusingBackend->get('anything'));
    }

    private function findCacheClearDefinition(): HandlerCommand
    {
        $provider = new ConfigCacheDbAuditServiceProvider();
        foreach ($provider->consoleCommands() as $command) {
            if ($command->name === 'cache:clear') {
                $this->assertSame(CacheClearHandler::class, $command->sourceClass());

                return $command;
            }
        }

        $this->fail('cache:clear is not registered by ConfigCacheDbAuditServiceProvider.');
    }

    private function createMinimalProjectRoot(): string
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_cache_test_' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/config', 0o755, true);
        mkdir($projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents(
            $projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n"
            . "        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n"
            . "        keys: ['id' => 'id'],\n    ),\n];",
        );

        return $projectRoot;
    }

}


final class ApplicationCacheProvider extends ServiceProvider
{
    private static ?CacheFactory $factory = null;

    public static function install(CacheFactory $factory): void
    {
        self::$factory = $factory;
    }

    public static function reset(): void
    {
        self::$factory = null;
    }

    public function register(): void
    {
        $this->singleton(
            CacheFactoryInterface::class,
            static fn(): CacheFactory => self::$factory
                ?? throw new \LogicException('Application cache provider fixture is not installed.'),
        );
        $this->singleton(RuntimeEpochInterface::class, StableRuntimeEpoch::class);
    }
}
