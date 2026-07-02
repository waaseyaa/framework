<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Asset;

use Waaseyaa\Foundation\Asset\AssetManagerInterface;
use Waaseyaa\Foundation\Asset\TenantAssetResolver;
use Waaseyaa\Foundation\Asset\ViteAssetManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantAssetResolver::class)]
final class TenantAssetResolverTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/waaseyaa_tenant_test_' . uniqid();
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

    #[Test]
    public function ssr_and_admin_entries_have_distinct_url_namespaces(): void
    {
        // WP5: before the fix, both entries shared the bare baseUrl
        // (e.g. '/dist') with no distinguishing suffix, so a candidate URL
        // could not be traced back to which entry it came from. Pin that the
        // two base entries now resolve into disjoint URL prefixes.
        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist');
        [$ssrResolver, $adminResolver] = $resolver->getResolvers();

        // No manifest exists, so url() falls back to the raw path — this
        // cleanly exposes each entry's baseUrl prefix.
        $ssrUrl = $ssrResolver->url('missing.js', 'x');
        $adminUrl = $adminResolver->url('missing.js', 'x');

        $this->assertStringStartsWith('/dist/ssr/', $ssrUrl);
        $this->assertStringStartsWith('/dist/admin/', $adminUrl);
        $this->assertNotSame($ssrUrl, $adminUrl);
    }

    #[Test]
    public function existence_check_never_serves_a_url_from_the_wrong_entrys_root(): void
    {
        // Two entries can independently map the same logical bundle+path to a
        // DIFFERENT hashed filename (their manifests are unrelated files on
        // disk). Only the admin entry's hashed file actually exists. Pin that
        // the resolver falls through past the ssr entry (whose manifest
        // points at a file that does not exist) to the admin entry, and that
        // the returned URL's namespace (/dist/admin/...) matches the root the
        // file was actually found under (basePath/admin/admin/...) — the
        // pre-fix bug was that a shared baseUrl made this pairing
        // ambiguous.
        mkdir($this->fixtureDir . '/ssr/admin/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/ssr/admin', [
            'x.js' => ['file' => 'assets/ssr-hash.js'],
        ]);
        // Deliberately do NOT create ssr/admin/assets/ssr-hash.js on disk.

        mkdir($this->fixtureDir . '/admin/admin/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/admin/admin', [
            'x.js' => ['file' => 'assets/admin-hash.js'],
        ]);
        mkdir($this->fixtureDir . '/admin/admin/assets', 0777, true);
        file_put_contents($this->fixtureDir . '/admin/admin/assets/admin-hash.js', 'body{}');

        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist');

        $url = $resolver->url('x.js', 'admin');

        $this->assertSame('/dist/admin/admin/assets/admin-hash.js', $url);
    }

    #[Test]
    public function resolution_order_is_tenant_theme_then_ssr_then_admin(): void
    {
        // All three tiers have a matching file for the same logical path;
        // pin that the highest-priority tier (tenant theme) wins.
        $themeDist = $this->fixtureDir . '/themes/agency/dist';
        mkdir($themeDist . '/admin/.vite', 0777, true);
        $this->writeManifest($themeDist . '/admin', [
            'x.js' => ['file' => 'assets/theme-hash.js'],
        ]);
        mkdir($themeDist . '/admin/assets', 0777, true);
        file_put_contents($themeDist . '/admin/assets/theme-hash.js', 'body{}');

        mkdir($this->fixtureDir . '/ssr/admin/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/ssr/admin', [
            'x.js' => ['file' => 'assets/ssr-hash.js'],
        ]);
        mkdir($this->fixtureDir . '/ssr/admin/assets', 0777, true);
        file_put_contents($this->fixtureDir . '/ssr/admin/assets/ssr-hash.js', 'body{}');

        mkdir($this->fixtureDir . '/admin/admin/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/admin/admin', [
            'x.js' => ['file' => 'assets/admin-hash.js'],
        ]);
        mkdir($this->fixtureDir . '/admin/admin/assets', 0777, true);
        file_put_contents($this->fixtureDir . '/admin/admin/assets/admin-hash.js', 'body{}');

        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist', 'agency');

        $this->assertStringContainsString('theme-hash.js', $resolver->url('x.js', 'admin'));
    }

    #[Test]
    public function get_resolvers_and_preload_links_unaffected_by_namespace_fix(): void
    {
        $resolver = new TenantAssetResolver($this->fixtureDir, '/dist', 'agency');

        $this->assertCount(3, $resolver->getResolvers());
        foreach ($resolver->getResolvers() as $entryResolver) {
            $this->assertInstanceOf(AssetManagerInterface::class, $entryResolver);
        }

        // preloadLinks() still delegates to the primary (highest-priority)
        // resolver only — unaffected by the baseUrl namespace fix.
        mkdir($this->fixtureDir . '/themes/agency/dist/admin/.vite', 0777, true);
        $this->writeManifest($this->fixtureDir . '/themes/agency/dist/admin', [
            'src/main.ts' => [
                'file' => 'assets/main-theme.js',
                'isEntry' => true,
            ],
        ]);

        $links = $resolver->preloadLinks('admin');

        $this->assertCount(1, $links);
        $this->assertStringContainsString('main-theme.js', $links[0]['href']);
        $this->assertStringStartsWith('/dist/themes/agency/', $links[0]['href']);
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
