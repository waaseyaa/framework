# Inertia Vite Asset Injection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Waaseyaa Inertia SPAs render in the browser by auto-injecting Vite asset tags into the root HTML template.

**Architecture:** `ViteAssetManager` gets an `assetTags()` method that generates `<script>` and `<link>` HTML. `RootTemplateRenderer` accepts the asset manager and injects those tags. `Inertia` static class holds a configured renderer. `InertiaServiceProvider` auto-configures everything with convention defaults. `ControllerDispatcher` uses the configured renderer.

**Tech Stack:** PHP 8.4, PHPUnit 10.5+, Waaseyaa framework (inertia + foundation packages)

---

## File Structure

### Modified files

```
packages/foundation/src/Asset/ViteAssetManager.php    — Add assetTags() method + devServerUrl param
packages/inertia/src/RootTemplateRenderer.php          — Accept ViteAssetManager, inject tags in default template
packages/inertia/src/Inertia.php                       — Add setRenderer/getRenderer statics
packages/inertia/src/InertiaServiceProvider.php        — Auto-configure renderer with asset manager
packages/foundation/src/Http/ControllerDispatcher.php  — Use Inertia::getRenderer()
```

### Modified test files

```
packages/foundation/tests/Unit/Asset/ViteAssetManagerTest.php  — Test assetTags() + dev mode
packages/inertia/tests/Unit/RootTemplateRendererTest.php       — Test asset injection
packages/inertia/tests/Unit/InertiaTest.php                    — Test setRenderer/getRenderer
packages/inertia/tests/Unit/InertiaServiceProviderTest.php     — Test auto-config
```

---

## Task 1: ViteAssetManager.assetTags()

**Files:**
- Modify: `packages/foundation/src/Asset/ViteAssetManager.php`
- Create: `packages/foundation/tests/Unit/Asset/ViteAssetManagerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `packages/foundation/tests/Unit/Asset/ViteAssetManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Asset;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Asset\ViteAssetManager;

#[CoversClass(ViteAssetManager::class)]
final class ViteAssetManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vite-test-' . uniqid();
        mkdir($this->tmpDir . '/build/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function asset_tags_returns_script_and_link_for_manifest_entries(): void
    {
        $this->writeManifest([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app-def456.css'],
                'isEntry' => true,
            ],
        ]);

        $manager = new ViteAssetManager(basePath: $this->tmpDir, baseUrl: '/build');
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
        $this->writeManifest([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'isEntry' => true,
            ],
        ]);

        $manager = new ViteAssetManager(
            basePath: $this->tmpDir,
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
        $this->writeManifest([
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

        $manager = new ViteAssetManager(basePath: $this->tmpDir, baseUrl: '/build');
        $tags = $manager->assetTags('build');

        self::assertStringContainsString('app-abc.js', $tags);
        self::assertStringContainsString('vendor-def.js', $tags);
        self::assertStringContainsString('app-abc.css', $tags);
        self::assertStringNotContainsString('shared-ghi.js', $tags);
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->tmpDir . '/build/.vite/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/foundation/tests/Unit/Asset/ViteAssetManagerTest.php`
Expected: FAIL — `assetTags` method not found

- [ ] **Step 3: Add devServerUrl parameter and assetTags() method**

In `packages/foundation/src/Asset/ViteAssetManager.php`, add the `devServerUrl` constructor parameter and the `assetTags()` method:

```php
    /**
     * @param string $basePath      Base path to the dist directory
     * @param string $baseUrl       Base URL prefix for asset URLs
     * @param string|null $devServerUrl  Vite dev server URL (e.g., 'http://localhost:5173')
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl = '/dist',
        private readonly ?string $devServerUrl = null,
    ) {}
```

Add the `assetTags()` method after the `preloadLinks()` method:

```php
    /**
     * Generate HTML script and link tags for a bundle's entry assets.
     *
     * In production (manifest exists): emits hashed asset tags.
     * In dev mode (no manifest, devServerUrl set): emits Vite dev server tags.
     * Otherwise: returns empty string.
     *
     * @param string $bundle    Asset bundle name (e.g., 'build')
     * @param string $entrypoint Source entry file for dev mode (e.g., 'resources/js/app.ts')
     */
    public function assetTags(string $bundle = 'build', string $entrypoint = 'resources/js/app.ts'): string
    {
        $manifest = $this->loadManifest($bundle);

        if ($manifest !== []) {
            return $this->productionTags($manifest, $bundle);
        }

        if ($this->devServerUrl !== null) {
            return $this->devTags($entrypoint);
        }

        return '';
    }

    private function productionTags(array $manifest, string $bundle): string
    {
        $tags = [];

        foreach ($manifest as $entry) {
            if (!isset($entry['isEntry']) || $entry['isEntry'] !== true) {
                continue;
            }

            if (isset($entry['css']) && is_array($entry['css'])) {
                foreach ($entry['css'] as $cssFile) {
                    $href = rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($cssFile, '/');
                    $tags[] = '<link rel="stylesheet" href="' . $href . '">';
                }
            }

            if (isset($entry['file'])) {
                $src = rtrim($this->baseUrl, '/') . '/' . $bundle . '/' . ltrim($entry['file'], '/');
                $tags[] = '<script type="module" src="' . $src . '"></script>';
            }
        }

        return implode("\n        ", $tags);
    }

    private function devTags(string $entrypoint): string
    {
        $base = rtrim($this->devServerUrl, '/');

        return '<script type="module" src="' . $base . '/@vite/client"></script>'
            . "\n        "
            . '<script type="module" src="' . $base . '/' . ltrim($entrypoint, '/') . '"></script>';
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/foundation/tests/Unit/Asset/ViteAssetManagerTest.php`
Expected: 5 tests, all PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa
git add packages/foundation/src/Asset/ViteAssetManager.php packages/foundation/tests/Unit/Asset/ViteAssetManagerTest.php
git commit -m "feat(foundation): add assetTags() to ViteAssetManager with dev mode support"
```

---

## Task 2: RootTemplateRenderer — accept ViteAssetManager

**Files:**
- Modify: `packages/inertia/src/RootTemplateRenderer.php`
- Modify: `packages/inertia/tests/Unit/RootTemplateRendererTest.php`

- [ ] **Step 1: Write the failing tests**

Add these tests to `packages/inertia/tests/Unit/RootTemplateRendererTest.php`:

```php
    public function testRendersAssetTagsFromViteAssetManager(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vite-renderer-' . uniqid();
        mkdir($tmpDir . '/build/.vite', 0777, true);
        file_put_contents($tmpDir . '/build/.vite/manifest.json', json_encode([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app-def456.css'],
                'isEntry' => true,
            ],
        ]));

        $assetManager = new \Waaseyaa\Foundation\Asset\ViteAssetManager(
            basePath: $tmpDir,
            baseUrl: '/build',
        );
        $renderer = new RootTemplateRenderer(assetManager: $assetManager);

        $html = $renderer->render([
            'component' => 'Home',
            'props' => ['errors' => []],
            'url' => '/',
            'version' => 'v1',
        ]);

        $this->assertStringContainsString('<script type="module" src="/build/build/assets/app-abc123.js"></script>', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/build/build/assets/app-def456.css">', $html);
        $this->assertStringContainsString('<div id="app">', $html);

        // Cleanup
        unlink($tmpDir . '/build/.vite/manifest.json');
        rmdir($tmpDir . '/build/.vite');
        rmdir($tmpDir . '/build');
        rmdir($tmpDir);
    }

    public function testCustomTemplateOverridesAssetManager(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vite-renderer-' . uniqid();
        mkdir($tmpDir . '/build/.vite', 0777, true);
        file_put_contents($tmpDir . '/build/.vite/manifest.json', json_encode([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'isEntry' => true,
            ],
        ]));

        $assetManager = new \Waaseyaa\Foundation\Asset\ViteAssetManager(
            basePath: $tmpDir,
            baseUrl: '/build',
        );
        $renderer = new RootTemplateRenderer(
            template: fn(string $pageJson) => "<html><body>{$pageJson}</body></html>",
            assetManager: $assetManager,
        );

        $html = $renderer->render([
            'component' => 'Test',
            'props' => ['errors' => []],
            'url' => '/test',
            'version' => 'v1',
        ]);

        // Custom template wins — no asset injection
        $this->assertStringNotContainsString('app-abc123.js', $html);
        $this->assertStringContainsString('"component":"Test"', $html);

        unlink($tmpDir . '/build/.vite/manifest.json');
        rmdir($tmpDir . '/build/.vite');
        rmdir($tmpDir . '/build');
        rmdir($tmpDir);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/Unit/RootTemplateRendererTest.php`
Expected: FAIL — constructor doesn't accept `assetManager` parameter

- [ ] **Step 3: Update RootTemplateRenderer**

Replace `packages/inertia/src/RootTemplateRenderer.php` with:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

use Waaseyaa\Foundation\Asset\ViteAssetManager;

final class RootTemplateRenderer
{
    public function __construct(
        private readonly ?\Closure $template = null,
        private readonly ?ViteAssetManager $assetManager = null,
    ) {}

    /** @param array<string, mixed> $pageObject */
    public function render(array $pageObject): string
    {
        $json = json_encode(
            $pageObject,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE,
        );

        $scriptTag = '<script type="application/json" data-page="true">' . $json . '</script>';

        if ($this->template !== null) {
            return ($this->template)($scriptTag);
        }

        return $this->defaultTemplate($scriptTag);
    }

    private function defaultTemplate(string $scriptTag): string
    {
        $assetTags = $this->assetManager?->assetTags() ?? '';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            {$assetTags}
        </head>
        <body>
            <div id="app"></div>
            {$scriptTag}
        </body>
        </html>
        HTML;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/Unit/RootTemplateRendererTest.php`
Expected: 5 tests (3 existing + 2 new), all PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa
git add packages/inertia/src/RootTemplateRenderer.php packages/inertia/tests/Unit/RootTemplateRendererTest.php
git commit -m "feat(inertia): inject Vite asset tags in RootTemplateRenderer"
```

---

## Task 3: Inertia static — setRenderer/getRenderer

**Files:**
- Modify: `packages/inertia/src/Inertia.php`
- Modify: `packages/inertia/tests/Unit/InertiaTest.php`

- [ ] **Step 1: Write the failing test**

Add to `packages/inertia/tests/Unit/InertiaTest.php` (read the file first to find the right place):

```php
    public function testSetAndGetRenderer(): void
    {
        $renderer = new \Waaseyaa\Inertia\RootTemplateRenderer();
        Inertia::setRenderer($renderer);

        self::assertSame($renderer, Inertia::getRenderer());
    }

    public function testGetRendererReturnsDefaultWhenNoneSet(): void
    {
        Inertia::reset();

        $renderer = Inertia::getRenderer();

        self::assertInstanceOf(\Waaseyaa\Inertia\RootTemplateRenderer::class, $renderer);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/Unit/InertiaTest.php --filter="testSetAndGetRenderer|testGetRendererReturnsDefault"`
Expected: FAIL — `setRenderer` method not found

- [ ] **Step 3: Add renderer statics to Inertia**

In `packages/inertia/src/Inertia.php`, add after the `$version` property:

```php
    private static ?RootTemplateRenderer $renderer = null;
```

Add after `getVersion()`:

```php
    public static function setRenderer(RootTemplateRenderer $renderer): void
    {
        self::$renderer = $renderer;
    }

    public static function getRenderer(): RootTemplateRenderer
    {
        return self::$renderer ?? new RootTemplateRenderer();
    }
```

Update the `reset()` method to also reset the renderer:

```php
    public static function reset(): void
    {
        self::$shared = [];
        self::$version = '';
        self::$renderer = null;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/Unit/InertiaTest.php`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa
git add packages/inertia/src/Inertia.php packages/inertia/tests/Unit/InertiaTest.php
git commit -m "feat(inertia): add setRenderer/getRenderer to Inertia static"
```

---

## Task 4: InertiaServiceProvider auto-configuration

**Files:**
- Modify: `packages/inertia/src/InertiaServiceProvider.php`
- Create: `packages/inertia/tests/Unit/InertiaServiceProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/inertia/tests/Unit/InertiaServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/Unit/InertiaServiceProviderTest.php`
Expected: FAIL — `registerWithRoot` method not found

- [ ] **Step 3: Update InertiaServiceProvider**

Replace `packages/inertia/src/InertiaServiceProvider.php` with:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Asset\ViteAssetManager;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class InertiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerWithRoot(null);
    }

    /**
     * Configure the Inertia renderer with Vite asset injection.
     *
     * @param string|null $root Project root directory. Defaults to getcwd().
     */
    public function registerWithRoot(?string $root): void
    {
        $root = $root ?? (string) getcwd();
        $devServerUrl = $_ENV['VITE_DEV_SERVER'] ?? getenv('VITE_DEV_SERVER') ?: null;

        $assetManager = new ViteAssetManager(
            basePath: $root . '/public',
            baseUrl: '',
            devServerUrl: $devServerUrl ?: null,
        );

        $renderer = new RootTemplateRenderer(assetManager: $assetManager);
        Inertia::setRenderer($renderer);
    }

    /** @return list<HttpMiddlewareInterface> */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [new InertiaMiddleware(Inertia::getVersion())];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/Unit/InertiaServiceProviderTest.php`
Expected: 1 test, PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa
git add packages/inertia/src/InertiaServiceProvider.php packages/inertia/tests/Unit/InertiaServiceProviderTest.php
git commit -m "feat(inertia): auto-configure renderer with ViteAssetManager in service provider"
```

---

## Task 5: ControllerDispatcher — use configured renderer

**Files:**
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php`

- [ ] **Step 1: Update ControllerDispatcher**

In `packages/foundation/src/Http/ControllerDispatcher.php`, find line ~90:

```php
            $renderer = new \Waaseyaa\Inertia\RootTemplateRenderer();
```

Replace with:

```php
            $renderer = \Waaseyaa\Inertia\Inertia::getRenderer();
```

- [ ] **Step 2: Run existing ControllerDispatcher tests**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php`
Expected: All existing tests PASS

- [ ] **Step 3: Run all inertia package tests**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit packages/inertia/tests/`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
cd /home/jones/dev/waaseyaa
git add packages/foundation/src/Http/ControllerDispatcher.php
git commit -m "fix(foundation): use Inertia::getRenderer() instead of hardcoded new"
```

---

## Task 6: Integration verification

- [ ] **Step 1: Run all framework tests**

Run: `cd /home/jones/dev/waaseyaa && ./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Update Giiken's composer dependencies**

Run: `cd /home/jones/dev/giiken && composer update waaseyaa/inertia waaseyaa/foundation`
Expected: Packages updated

- [ ] **Step 3: Verify Giiken works with the fix**

Run: `cd /home/jones/dev/giiken && php -S localhost:8000 -t public &` then check the output.
Expected: Server starts. Verify the HTML output includes Vite asset tags.

- [ ] **Step 4: Commit Giiken's updated lock file if changed**

```bash
cd /home/jones/dev/giiken && git add composer.lock && git commit -m "chore: update waaseyaa packages with Vite asset injection fix"
```
