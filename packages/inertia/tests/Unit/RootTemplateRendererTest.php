<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Inertia\RootTemplateRenderer;

#[CoversClass(RootTemplateRenderer::class)]
final class RootTemplateRendererTest extends TestCase
{
    public function testRendersHtmlWithPageObject(): void
    {
        $renderer = new RootTemplateRenderer();
        $pageObject = [
            'component' => 'Home',
            'props' => ['errors' => []],
            'url' => '/',
            'version' => 'v1',
        ];

        $html = $renderer->render($pageObject);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<div id="app">', $html);
        $this->assertStringContainsString('<script type="application/json" data-page="app">', $html);
        $this->assertStringContainsString('"component":"Home"', $html);
    }

    public function testEscapesHtmlInPageObject(): void
    {
        $renderer = new RootTemplateRenderer();
        $pageObject = [
            'component' => 'Home',
            'props' => ['errors' => [], 'html' => '<script>alert("xss")</script>'],
            'url' => '/',
            'version' => 'v1',
        ];

        $html = $renderer->render($pageObject);

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('\u003Cscript\u003E', $html);
    }

    public function testCustomTemplateCallback(): void
    {
        $renderer = new RootTemplateRenderer(
            template: fn(string $pageJson) => "<html><body><div id=\"app\"></div>{$pageJson}</body></html>",
        );
        $pageObject = [
            'component' => 'Test',
            'props' => ['errors' => []],
            'url' => '/test',
            'version' => 'v1',
        ];

        $html = $renderer->render($pageObject);

        $this->assertStringContainsString('<div id="app"></div>', $html);
        $this->assertStringContainsString('"component":"Test"', $html);
    }

    public function testRendersAssetTagsFromViteAssetManager(): void
    {
        $tmpDir = $this->createTmpManifestDir([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'css' => ['assets/app-def456.css'],
                'isEntry' => true,
            ],
        ]);

        try {
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
        } finally {
            (new Filesystem())->remove($tmpDir);
        }
    }

    public function testCustomTemplateOverridesAssetManager(): void
    {
        $tmpDir = $this->createTmpManifestDir([
            'resources/js/app.ts' => [
                'file' => 'assets/app-abc123.js',
                'isEntry' => true,
            ],
        ]);

        try {
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

            $this->assertStringNotContainsString('app-abc123.js', $html);
            $this->assertStringContainsString('"component":"Test"', $html);
        } finally {
            (new Filesystem())->remove($tmpDir);
        }
    }

    private function createTmpManifestDir(array $manifest): string
    {
        $tmpDir = sys_get_temp_dir() . '/vite-renderer-' . uniqid();
        mkdir($tmpDir . '/build/.vite', 0777, true);
        file_put_contents($tmpDir . '/build/.vite/manifest.json', json_encode($manifest));
        return $tmpDir;
    }

}
