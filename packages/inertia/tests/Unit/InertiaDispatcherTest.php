<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Inertia\Inertia;
use Waaseyaa\Inertia\InertiaResponse;
use Waaseyaa\Inertia\RootTemplateRenderer;

#[CoversClass(InertiaResponse::class)]
final class InertiaDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        Inertia::reset();
        Inertia::setVersion('v1');
    }

    public function testInertiaResponsePageObjectForXhr(): void
    {
        $response = Inertia::render('Users/Index', ['users' => [1, 2, 3]]);
        $pageObject = $response->toPageObject();
        $pageObject['url'] = '/users';

        $this->assertSame('Users/Index', $pageObject['component']);
        $this->assertSame([1, 2, 3], $pageObject['props']['users']);
        $this->assertSame([], $pageObject['props']['errors']);
        $this->assertSame('/users', $pageObject['url']);
        $this->assertSame('v1', $pageObject['version']);
    }

    public function testInertiaResponseHtmlForInitialLoad(): void
    {
        $response = Inertia::render('Dashboard', ['stats' => 42]);
        $pageObject = $response->toPageObject();
        $pageObject['url'] = '/dashboard';

        $renderer = new RootTemplateRenderer();
        $html = $renderer->render($pageObject);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('"component":"Dashboard"', $html);
        $this->assertStringContainsString('"stats":42', $html);
    }

    public function testControllerPatternWithSharedProps(): void
    {
        Inertia::share('auth', fn () => ['user' => ['name' => 'Alice']]);

        $response = Inertia::render('Dashboard', ['stats' => 42]);
        $page = $response->toPageObject();

        $this->assertSame('Dashboard', $page['component']);
        $this->assertSame(42, $page['props']['stats']);
        $this->assertSame(['name' => 'Alice'], $page['props']['auth']['user']);
    }

    public function testInertiaResponseIsDetectable(): void
    {
        $response = Inertia::render('Home', []);
        $this->assertInstanceOf(InertiaResponse::class, $response);
    }
}
