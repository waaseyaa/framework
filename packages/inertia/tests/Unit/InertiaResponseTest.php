<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Inertia\InertiaResponse;

#[CoversClass(InertiaResponse::class)]
final class InertiaResponseTest extends TestCase
{
    public function testBasicPageObject(): void
    {
        $response = new InertiaResponse(
            component: 'Users/Index',
            props: ['users' => [['id' => 1, 'name' => 'Alice']]],
            url: '/users',
            version: 'abc123',
        );

        $page = $response->toPageObject();

        $this->assertSame('Users/Index', $page['component']);
        $this->assertSame('/users', $page['url']);
        $this->assertSame('abc123', $page['version']);
        $this->assertSame([['id' => 1, 'name' => 'Alice']], $page['props']['users']);
        $this->assertArrayNotHasKey('encryptHistory', $page);
        $this->assertArrayNotHasKey('clearHistory', $page);
    }

    public function testPageObjectWithEncryptHistory(): void
    {
        $response = new InertiaResponse(
            component: 'Dashboard',
            props: [],
            url: '/dashboard',
            version: 'v1',
            encryptHistory: true,
        );

        $page = $response->toPageObject();

        $this->assertTrue($page['encryptHistory']);
    }

    public function testPageObjectWithClearHistory(): void
    {
        $response = new InertiaResponse(
            component: 'Login',
            props: [],
            url: '/login',
            version: 'v1',
            clearHistory: true,
        );

        $page = $response->toPageObject();

        $this->assertTrue($page['clearHistory']);
    }

    public function testPageObjectWithDeferredProps(): void
    {
        $response = new InertiaResponse(
            component: 'Posts/Index',
            props: ['posts' => []],
            url: '/posts',
            version: 'v1',
            deferredProps: ['default' => ['comments', 'analytics']],
        );

        $page = $response->toPageObject();

        $this->assertSame(['default' => ['comments', 'analytics']], $page['deferredProps']);
    }

    public function testPageObjectWithMergeProps(): void
    {
        $response = new InertiaResponse(
            component: 'Feed/Index',
            props: ['posts' => []],
            url: '/feed',
            version: 'v1',
            mergeProps: ['posts'],
            prependProps: ['notifications'],
        );

        $page = $response->toPageObject();

        $this->assertSame(['posts'], $page['mergeProps']);
        $this->assertSame(['notifications'], $page['prependProps']);
    }

    public function testPageObjectOmitsEmptyOptionalFields(): void
    {
        $response = new InertiaResponse(
            component: 'Home',
            props: [],
            url: '/',
            version: 'v1',
        );

        $page = $response->toPageObject();

        $this->assertArrayNotHasKey('deferredProps', $page);
        $this->assertArrayNotHasKey('mergeProps', $page);
        $this->assertArrayNotHasKey('prependProps', $page);
        $this->assertArrayNotHasKey('deepMergeProps', $page);
        $this->assertArrayNotHasKey('onceProps', $page);
        $this->assertArrayNotHasKey('encryptHistory', $page);
        $this->assertArrayNotHasKey('clearHistory', $page);
    }

    public function testPropsIncludeErrorsDefault(): void
    {
        $response = new InertiaResponse(
            component: 'Home',
            props: ['title' => 'Welcome'],
            url: '/',
            version: 'v1',
        );

        $page = $response->toPageObject();

        $this->assertSame([], $page['props']['errors']);
        $this->assertSame('Welcome', $page['props']['title']);
    }
}
