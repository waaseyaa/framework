<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
        $this->assertStringContainsString('<script type="application/json" data-page="true">', $html);
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
}
