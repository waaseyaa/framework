<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Asset;

use Waaseyaa\Foundation\Asset\AssetManagerInterface;
use Waaseyaa\Foundation\Asset\ViteAssetManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViteAssetManager::class)]
final class ViteAssetManagerTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/waaseyaa_vite_test_' . uniqid();
        mkdir($this->fixtureDir . '/admin/.vite', 0777, true);
        mkdir($this->fixtureDir . '/ssr/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureDir);
    }

    #[Test]
    public function implements_asset_manager_interface(): void
    {
        $manager = new ViteAssetManager($this->fixtureDir);

        $this->assertInstanceOf(AssetManagerInterface::class, $manager);
    }

    #[Test]
    public function url_resolves_from_manifest(): void
    {
        $this->writeManifest('admin', [
            'src/main.ts' => [
                'file' => 'assets/main-abc123.js',
                'isEntry' => true,
            ],
        ]);

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $url = $manager->url('src/main.ts', 'admin');

        $this->assertSame('/dist/admin/assets/main-abc123.js', $url);
    }

    #[Test]
    public function url_falls_back_to_raw_path_when_not_in_manifest(): void
    {
        $this->writeManifest('admin', []);

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $url = $manager->url('css/custom.css', 'admin');

        $this->assertSame('/dist/admin/css/custom.css', $url);
    }

    #[Test]
    public function url_falls_back_when_no_manifest_exists(): void
    {
        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $url = $manager->url('js/app.js', 'missing_bundle');

        $this->assertSame('/dist/missing_bundle/js/app.js', $url);
    }

    #[Test]
    public function url_resolves_different_bundles(): void
    {
        $this->writeManifest('admin', [
            'src/admin.ts' => ['file' => 'assets/admin-111.js', 'isEntry' => true],
        ]);
        $this->writeManifest('ssr', [
            'src/ssr.ts' => ['file' => 'assets/ssr-222.js', 'isEntry' => true],
        ]);

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $this->assertSame('/dist/admin/assets/admin-111.js', $manager->url('src/admin.ts', 'admin'));
        $this->assertSame('/dist/ssr/assets/ssr-222.js', $manager->url('src/ssr.ts', 'ssr'));
    }

    #[Test]
    public function preload_links_returns_entry_files(): void
    {
        $this->writeManifest('admin', [
            'src/main.ts' => [
                'file' => 'assets/main-abc.js',
                'isEntry' => true,
                'css' => ['assets/main-def.css'],
            ],
            '_vendor.js' => [
                'file' => 'assets/vendor-ghi.js',
            ],
        ]);

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $links = $manager->preloadLinks('admin');

        $this->assertCount(2, $links);
        $this->assertSame('/dist/admin/assets/main-abc.js', $links[0]['href']);
        $this->assertSame('script', $links[0]['as']);
        $this->assertSame('/dist/admin/assets/main-def.css', $links[1]['href']);
        $this->assertSame('style', $links[1]['as']);
    }

    #[Test]
    public function preload_links_empty_when_no_manifest(): void
    {
        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $links = $manager->preloadLinks('nonexistent');

        $this->assertSame([], $links);
    }

    #[Test]
    public function preload_links_skips_non_entry_files(): void
    {
        $this->writeManifest('admin', [
            '_vendor.js' => [
                'file' => 'assets/vendor-abc.js',
                // No isEntry flag
            ],
        ]);

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $links = $manager->preloadLinks('admin');

        $this->assertSame([], $links);
    }

    #[Test]
    public function manifests_are_cached_across_calls(): void
    {
        $this->writeManifest('admin', [
            'src/main.ts' => ['file' => 'assets/main-abc.js', 'isEntry' => true],
        ]);

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        // First call loads manifest.
        $url1 = $manager->url('src/main.ts', 'admin');
        // Second call uses cache.
        $url2 = $manager->url('src/main.ts', 'admin');

        $this->assertSame($url1, $url2);
    }

    #[Test]
    public function reads_legacy_manifest_location(): void
    {
        // Write manifest in legacy location (without .vite subdirectory).
        $bundleDir = $this->fixtureDir . '/legacy';
        mkdir($bundleDir, 0777, true);
        file_put_contents($bundleDir . '/manifest.json', json_encode([
            'src/app.ts' => ['file' => 'assets/app-legacy.js', 'isEntry' => true],
        ]));

        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $url = $manager->url('src/app.ts', 'legacy');

        $this->assertSame('/dist/legacy/assets/app-legacy.js', $url);
    }

    #[Test]
    public function custom_base_url(): void
    {
        $this->writeManifest('admin', [
            'src/main.ts' => ['file' => 'assets/main-abc.js', 'isEntry' => true],
        ]);

        $manager = new ViteAssetManager($this->fixtureDir, 'https://cdn.example.com/assets');

        $url = $manager->url('src/main.ts', 'admin');

        $this->assertSame('https://cdn.example.com/assets/admin/assets/main-abc.js', $url);
    }

    #[Test]
    public function asset_tags_returns_script_and_link_for_manifest_entries(): void
    {
        $this->writeManifest('build', [
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app-def456.css'],
                'isEntry' => true,
            ],
        ]);

        $manager = new ViteAssetManager(basePath: $this->fixtureDir, baseUrl: '/build');
        $tags = $manager->assetTags('build');

        self::assertStringContainsString('<script type="module" src="/build/build/assets/app-abc123.js"></script>', $tags);
        self::assertStringContainsString('<link rel="stylesheet" href="/build/build/assets/app-def456.css">', $tags);
    }

    #[Test]
    public function asset_tags_returns_empty_string_when_no_manifest(): void
    {
        $manager = new ViteAssetManager(basePath: '/nonexistent', baseUrl: '/build');
        $tags = $manager->assetTags('build');

        self::assertSame('', $tags);
    }

    #[Test]
    public function asset_tags_returns_dev_server_tags_when_no_manifest_and_dev_url_set(): void
    {
        $manager = new ViteAssetManager(
            basePath: '/nonexistent',
            baseUrl: '/build',
            devServerUrl: 'http://localhost:5173',
        );
        $tags = $manager->assetTags('build', 'resources/js/app.ts');

        self::assertStringContainsString('<script type="module" src="http://localhost:5173/@vite/client"></script>', $tags);
        self::assertStringContainsString('<script type="module" src="http://localhost:5173/resources/js/app.ts"></script>', $tags);
    }

    #[Test]
    public function asset_tags_prefers_manifest_over_dev_server(): void
    {
        $this->writeManifest('build', [
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'isEntry' => true,
            ],
        ]);

        $manager = new ViteAssetManager(
            basePath: $this->fixtureDir,
            baseUrl: '/build',
            devServerUrl: 'http://localhost:5173',
        );
        $tags = $manager->assetTags('build');

        self::assertStringContainsString('assets/app-abc123.js', $tags);
        self::assertStringNotContainsString('localhost:5173', $tags);
    }

    #[Test]
    public function asset_tags_handles_multiple_entries(): void
    {
        $this->writeManifest('build', [
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc.js',
                'css' => ['assets/app-abc.css'],
                'isEntry' => true,
            ],
            'resources/js/vendor.ts' => [
                'file' => 'assets/vendor-def.js',
                'isEntry' => true,
            ],
            '_shared-ghi.js' => [
                'file' => 'assets/shared-ghi.js',
                'isEntry' => false,
            ],
        ]);

        $manager = new ViteAssetManager(basePath: $this->fixtureDir, baseUrl: '/build');
        $tags = $manager->assetTags('build');

        self::assertStringContainsString('app-abc.js', $tags);
        self::assertStringContainsString('vendor-def.js', $tags);
        self::assertStringContainsString('app-abc.css', $tags);
        self::assertStringNotContainsString('shared-ghi.js', $tags);
    }

    #[Test]
    public function missing_manifest_logs_error_when_no_dev_server(): void
    {
        $logger = $this->recordingLogger();

        $manager = new ViteAssetManager($this->fixtureDir, '/dist', devServerUrl: null, logger: $logger);
        $manager->url('src/main.ts', 'missing_bundle');

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame('missing', $logger->records[0]['context']['kind']);
        $this->assertSame('missing_bundle', $logger->records[0]['context']['bundle']);
        $this->assertCount(2, $logger->records[0]['context']['probed_paths']);
    }

    #[Test]
    public function missing_manifest_is_quiet_in_dev_mode(): void
    {
        $logger = $this->recordingLogger();

        $manager = new ViteAssetManager(
            $this->fixtureDir,
            '/dist',
            devServerUrl: 'http://localhost:5173',
            logger: $logger,
        );
        $manager->url('src/main.ts', 'missing_bundle');

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
        $this->assertSame('missing', $logger->records[0]['context']['kind']);
    }

    #[Test]
    public function corrupt_json_manifest_always_logs_error_even_in_dev_mode(): void
    {
        $dir = $this->fixtureDir . '/admin/.vite';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/manifest.json', '{not valid json');

        $logger = $this->recordingLogger();

        $manager = new ViteAssetManager(
            $this->fixtureDir,
            '/dist',
            devServerUrl: 'http://localhost:5173',
            logger: $logger,
        );
        $manager->url('src/main.ts', 'admin');

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame('corrupt-json', $logger->records[0]['context']['kind']);
        $this->assertSame('admin', $logger->records[0]['context']['bundle']);
    }

    #[Test]
    public function unreadable_manifest_logs_error_with_kind(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('chmod 0000 does not block reads for root.');
        }

        // A chmod 0000 file makes file_get_contents() return false — the only
        // reliable cross-filesystem way to hit that branch (an existing-but-
        // unopenable directory at the same path returns "" on this platform,
        // which would land in the corrupt-json branch instead).
        $dir = $this->fixtureDir . '/admin/.vite';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $manifestPath = $dir . '/manifest.json';
        file_put_contents($manifestPath, '{}');
        chmod($manifestPath, 0000);

        // Expect (and swallow) the native E_WARNING file_get_contents() raises for
        // the permission-denied read — that's the exact condition under test, not
        // an unexpected failure; PHPUnit would otherwise convert it into a test
        // warning and fail the run.
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $logger = $this->recordingLogger();

            $manager = new ViteAssetManager($this->fixtureDir, '/dist', logger: $logger);
            $manager->url('src/main.ts', 'admin');

            $this->assertCount(1, $logger->records);
            $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
            $this->assertSame('unreadable', $logger->records[0]['context']['kind']);
        } finally {
            restore_error_handler();
            chmod($manifestPath, 0644);
        }
    }

    #[Test]
    public function non_array_manifest_logs_error_with_kind(): void
    {
        $dir = $this->fixtureDir . '/admin/.vite';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/manifest.json', json_encode('just a string'));

        $logger = $this->recordingLogger();

        $manager = new ViteAssetManager($this->fixtureDir, '/dist', logger: $logger);
        $manager->url('src/main.ts', 'admin');

        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame('non-array', $logger->records[0]['context']['kind']);
    }

    #[Test]
    public function valid_manifest_logs_nothing(): void
    {
        $this->writeManifest('admin', [
            'src/main.ts' => ['file' => 'assets/main-abc.js', 'isEntry' => true],
        ]);

        $logger = $this->recordingLogger();

        $manager = new ViteAssetManager($this->fixtureDir, '/dist', logger: $logger);
        $manager->url('src/main.ts', 'admin');

        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function manifest_failure_logs_exactly_once_across_repeated_calls(): void
    {
        $logger = $this->recordingLogger();

        $manager = new ViteAssetManager($this->fixtureDir, '/dist', logger: $logger);
        $manager->url('src/main.ts', 'missing_bundle');
        $manager->url('other/path.ts', 'missing_bundle');
        $manager->preloadLinks('missing_bundle');

        $this->assertCount(1, $logger->records);
    }

    #[Test]
    public function null_logger_default_does_not_crash_when_unwired(): void
    {
        $manager = new ViteAssetManager($this->fixtureDir, '/dist');

        $url = $manager->url('src/main.ts', 'missing_bundle');

        $this->assertSame('/dist/missing_bundle/src/main.ts', $url);
    }

    /**
     * @return LoggerInterface&object{records: list<array{level: LogLevel, message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): object
    {
        return new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $bundle, array $manifest): void
    {
        $dir = $this->fixtureDir . '/' . $bundle . '/.vite';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/manifest.json', json_encode($manifest));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
