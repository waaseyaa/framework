<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Inertia\Inertia;
use Waaseyaa\Inertia\InertiaServiceProvider;
use Waaseyaa\Inertia\RootTemplateRenderer;

#[CoversClass(InertiaServiceProvider::class)]
final class InertiaServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Inertia::reset();
    }

    protected function tearDown(): void
    {
        Inertia::reset();
    }

    #[Test]
    public function register_configures_renderer_with_asset_manager(): void
    {
        $tmpDir = sys_get_temp_dir() . '/waaseyaa-sp-' . uniqid();
        mkdir($tmpDir . '/public/build/.vite', 0777, true);
        file_put_contents($tmpDir . '/public/build/.vite/manifest.json', json_encode([
            'resources/js/app.ts' => [
                'file' => 'assets/app-test.js',
                'css' => ['assets/app-test.css'],
                'isEntry' => true,
            ],
        ]));

        $provider = new InertiaServiceProvider();
        $provider->registerWithRoot($tmpDir);

        $renderer = Inertia::getRenderer();
        $html = $renderer->render([
            'component' => 'Test',
            'props' => ['errors' => []],
            'url' => '/',
            'version' => '',
        ]);

        self::assertStringContainsString('app-test.js', $html);
        self::assertStringContainsString('app-test.css', $html);

        // Cleanup
        unlink($tmpDir . '/public/build/.vite/manifest.json');
        rmdir($tmpDir . '/public/build/.vite');
        rmdir($tmpDir . '/public/build');
        rmdir($tmpDir . '/public');
        rmdir($tmpDir);
    }

    /**
     * End-to-end wiring pin for the WP5 observability seam: when the kernel
     * bus serves the logger under the foundation LoggerInterface FQCN (the
     * key ProviderRegistryKernelServices actually serves in production),
     * registerWithRoot() threads it into ViteAssetManager, so a missing
     * manifest surfaces as an ERROR record instead of dying silently. Guards
     * against the WP4 bug class (a re-keyed resolveOptional() silently
     * resolving null and dropping the logger).
     */
    #[Test]
    public function register_threads_the_kernel_logger_into_the_asset_manager(): void
    {
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string}> */
            public array $records = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $bus = new class ($logger) implements KernelServicesInterface {
            public function __construct(private readonly LoggerInterface $logger) {}

            public function get(string $abstract): ?object
            {
                return $abstract === LoggerInterface::class ? $this->logger : null;
            }
        };

        $tmpDir = sys_get_temp_dir() . '/waaseyaa-sp-' . uniqid();
        mkdir($tmpDir, 0777, true);

        try {
            $provider = new InertiaServiceProvider();
            $provider->setKernelServices($bus);
            $provider->registerWithRoot($tmpDir);

            // No manifest exists anywhere under $tmpDir and no dev server is
            // configured — rendering must trip the missing-manifest ERROR.
            Inertia::getRenderer()->render([
                'component' => 'Test',
                'props' => ['errors' => []],
                'url' => '/',
                'version' => '',
            ]);

            $errors = array_values(array_filter(
                $logger->records,
                static fn(array $r): bool => $r['level'] === LogLevel::ERROR,
            ));
            self::assertNotEmpty($errors, 'missing-manifest ERROR must reach the kernel-bus logger');
            self::assertStringContainsString('manifest', strtolower($errors[0]['message']));
        } finally {
            rmdir($tmpDir);
        }
    }
}
