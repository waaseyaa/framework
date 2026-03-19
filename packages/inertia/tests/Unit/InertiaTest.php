<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Inertia\Inertia;
use Waaseyaa\Inertia\InertiaResponse;

#[CoversClass(Inertia::class)]
final class InertiaTest extends TestCase
{
    protected function setUp(): void
    {
        Inertia::reset();
    }

    public function testRenderCreatesInertiaResponse(): void
    {
        Inertia::setVersion('v1');
        $response = Inertia::render('Users/Index', ['users' => [1, 2, 3]]);

        $this->assertInstanceOf(InertiaResponse::class, $response);
        $page = $response->toPageObject();
        $this->assertSame('Users/Index', $page['component']);
        $this->assertSame([1, 2, 3], $page['props']['users']);
    }

    public function testSharedPropsAreMerged(): void
    {
        Inertia::setVersion('v1');
        Inertia::share('auth', ['user' => ['name' => 'Alice']]);
        Inertia::share('flash', ['success' => 'Saved!']);

        $response = Inertia::render('Dashboard', ['stats' => 42]);

        $page = $response->toPageObject();
        $this->assertSame(['name' => 'Alice'], $page['props']['auth']['user']);
        $this->assertSame(['success' => 'Saved!'], $page['props']['flash']);
        $this->assertSame(42, $page['props']['stats']);
    }

    public function testPagePropsOverrideSharedProps(): void
    {
        Inertia::setVersion('v1');
        Inertia::share('title', 'Default');

        $response = Inertia::render('Home', ['title' => 'Custom']);

        $page = $response->toPageObject();
        $this->assertSame('Custom', $page['props']['title']);
    }

    public function testSharedClosuresAreResolvedAtRenderTime(): void
    {
        Inertia::setVersion('v1');
        $counter = 0;
        Inertia::share('count', function () use (&$counter) {
            return ++$counter;
        });

        $response1 = Inertia::render('Page1', []);
        $response2 = Inertia::render('Page2', []);

        $this->assertSame(1, $response1->toPageObject()['props']['count']);
        $this->assertSame(2, $response2->toPageObject()['props']['count']);
    }

    public function testVersionIsIncludedInResponse(): void
    {
        Inertia::setVersion('abc123');
        $response = Inertia::render('Home', []);

        $this->assertSame('abc123', $response->toPageObject()['version']);
    }

    public function testRenderWithOptions(): void
    {
        Inertia::setVersion('v1');
        $response = Inertia::render('Settings', ['user' => 'Alice'], encryptHistory: true);

        $page = $response->toPageObject();
        $this->assertTrue($page['encryptHistory']);
    }
}
