<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\MediaDeliveryService;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Media\Http\MediaDownloadResponderInterface;
use Waaseyaa\Media\Http\MediaDownloadSourceReaderInterface;
use Waaseyaa\Media\MediaServiceProvider;
use Waaseyaa\Tests\Support\ComposerProjectFixture;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

final class MediaDownloadProviderLifecycleTest extends TestCase
{
    #[Test]
    public function registration_is_lazy_until_canonical_services_are_ready(): void
    {
        $services = $this->createMock(KernelServicesInterface::class);
        $services->expects(self::never())->method('get');
        $provider = new MediaServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();
        self::assertArrayHasKey(MediaDownloadResponderInterface::class, $provider->getBindings());
    }

    #[Test]
    public function completed_http_kernel_shares_canonical_router_and_reader_and_root_precedence(): void
    {
        $repo = dirname(__DIR__, 3);
        $root = sys_get_temp_dir() . '/media_service_' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0o700, true);
        ComposerProjectFixture::installMetadata($repo, $root);
        file_put_contents($root . '/composer.json', '{"name":"test/media-service"}');
        file_put_contents($root . '/config/entity-types.php', '<?php return [];');
        try {
            foreach ([['files_root' => $root . '/primary', 'files_dir' => $root . '/secondary'], ['files_dir' => $root . '/secondary'], []] as $config) {
                file_put_contents($root . '/config/waaseyaa.php', '<?php return ' . var_export($config + ['database' => $root . '/storage/waaseyaa.sqlite', 'environment' => 'testing'], true) . ';');
                if (!is_file($root . '/storage/waaseyaa.sqlite')) {
                    $this->initializeDatabase($root);
                }
                $kernel = new HttpKernel($root);
                $kernel->manifestCacheWitness();
                $providers = array_values(array_filter($kernel->getProviders(), static fn($provider): bool => $provider instanceof MediaServiceProvider));
                self::assertCount(1, $providers);
                $provider = $providers[0];
                $service = $provider->resolve(MediaDownloadResponderInterface::class);
                self::assertSame($service, $provider->resolve(MediaDownloadResponderInterface::class));
                $routers = [...$provider->httpDomainRouters($kernel)];
                self::assertSame($service, $routers[1]);
                self::assertSame($provider->resolve(MediaDownloadSourceReaderInterface::class), $provider->resolve(MediaDownloadSourceReaderInterface::class));
                self::assertSame($provider->resolve(MediaDownloadSourceReaderInterface::class), new \ReflectionProperty($service, 'sourceReader')->getValue($service));
                self::assertSame($config['files_root'] ?? $config['files_dir'] ?? $root . '/storage/files', new \ReflectionProperty($service, 'filesRoot')->getValue($service));
                self::assertSame($kernel->getAccessHandler(), new \ReflectionProperty($service, 'accessHandler')->getValue($service));
                ProcessFieldReadRuntime::reset();
            }
        } finally {
            ProcessFieldReadRuntime::reset();
            new Filesystem()->remove($root);
        }
    }
    private function initializeDatabase(string $root): void
    {
        $handler = new DbInitHandler($root);
        $command = new HandlerCommand(
            name: 'db:init',
            description: 'Initialize media regression database.',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Dry run.'),
                new HandlerOption(name: 'no-sync-schema', mode: HandlerOptionMode::None, description: 'Skip schema sync.'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $tester = CliTester::for($command, $container);
        $tester->execute([]);
        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }


}
