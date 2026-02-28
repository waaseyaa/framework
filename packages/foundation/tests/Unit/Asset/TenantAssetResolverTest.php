<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Asset;

use Aurora\Foundation\Asset\AssetManagerInterface;
use Aurora\Foundation\Asset\TenantAssetResolver;
use Aurora\Foundation\Asset\ViteAssetManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantAssetResolver::class)]
final class TenantAssetResolverTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/aurora_tenant_test_' . uniqid();
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureDir);
    }

    #[Test]
    public function implements_asset_manager_interface(): void
    {
        $resolver = new TenantAssetResolver($this->fixtureDir);

        $this->assertInstanceOf(AssetManagerInterface::class, $resolver);
    }

    #[Test]
    public function without_tenant_builds_two_resolvers(): void
    {
        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist');

        // Without tenant: SSR + admin = 2 resolvers.
        $this->assertCount(2, $resolver->getResolvers());
    }

    #[Test]
    public function with_tenant_builds_three_resolvers(): void
    {
        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist', 'agency');

        // With tenant: theme + SSR + admin = 3 resolvers.
        $this->assertCount(3, $resolver->getResolvers());
    }

    #[Test]
    public function resolves_tenant_theme_asset_when_file_exists(): void
    {
        // Create tenant theme dist with a manifest and actual file.
        $themeDist = $this->fixtureDir . '/themes/agency/dist';
        mkdir($themeDist . '/admin/.vite', 0777, true);
        $this->writeManifest($themeDist . '/admin', [
            'css/main.css' => ['file' => 'assets/main-tenant.css'],
        ]);
        // Create the actual file so the resolver finds it.
        mkdir($themeDist . '/admin/assets', 0777, true);
        file_put_contents($themeDist . '/admin/assets/main-tenant.css', 'body{}');

        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist', 'agency');

        $url = $resolver->url('css/main.css', 'admin');

        $this->assertStringContainsString('main-tenant.css', $url);
    }

    #[Test]
    public function falls_back_to_base_ssr_when_tenant_file_missing(): void
    {
        // Create SSR dist with manifest and actual file.
        mkdir($this->fixtureDir . '/ssr/ssr/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/ssr/ssr', [
            'css/main.css' => ['file' => 'assets/main-ssr.css'],
        ]);
        mkdir($this->fixtureDir . '/ssr/ssr/assets', 0777, true);
        file_put_contents($this->fixtureDir . '/ssr/ssr/assets/main-ssr.css', 'body{}');

        // No tenant theme dir exists, so fall through.
        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist', 'nonexistent');

        $url = $resolver->url('css/main.css', 'ssr');

        $this->assertStringContainsString('main-ssr.css', $url);
    }

    #[Test]
    public function returns_fallback_url_when_no_file_found(): void
    {
        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist');

        $url = $resolver->url('css/missing.css', 'admin');

        // Should return a URL from the first resolver (SSR), even though file doesn't exist.
        $this->assertStringContainsString('css/missing.css', $url);
    }

    #[Test]
    public function preload_links_from_primary_resolver(): void
    {
        // Create SSR dist with manifest (first resolver without tenant).
        mkdir($this->fixtureDir . '/ssr/admin/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/ssr/admin', [
            'src/main.ts' => [
                'file' => 'assets/main-abc.js',
                'isEntry' => true,
            ],
        ]);

        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist');

        $links = $resolver->preloadLinks('admin');

        $this->assertCount(1, $links);
        $this->assertStringContainsString('main-abc.js', $links[0]['href']);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $bundleDir, array $manifest): void
    {
        $viteDir = $bundleDir . '/.vite';
        if (!is_dir($viteDir)) {
            mkdir($viteDir, 0777, true);
        }
        file_put_contents($viteDir . '/manifest.json', json_encode($manifest));
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
