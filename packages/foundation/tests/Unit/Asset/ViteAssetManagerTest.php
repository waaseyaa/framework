<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Asset;

use Aurora\Foundation\Asset\AssetManagerInterface;
use Aurora\Foundation\Asset\ViteAssetManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViteAssetManager::class)]
final class ViteAssetManagerTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/aurora_vite_test_' . uniqid();
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
