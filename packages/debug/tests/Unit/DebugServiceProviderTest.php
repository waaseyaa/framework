<?php

declare(strict_types=1);

namespace Waaseyaa\Debug\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Debug\DebugServiceProvider;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(DebugServiceProvider::class)]
final class DebugServiceProviderTest extends TestCase
{
    private string|false $originalAppDebug;

    protected function setUp(): void
    {
        $this->originalAppDebug = getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        if ($this->originalAppDebug === false) {
            putenv('APP_DEBUG');
        } else {
            putenv('APP_DEBUG='.$this->originalAppDebug);
        }
    }

    public function test_malformed_environment_value_fails_closed_even_when_config_enables_debug(): void
    {
        putenv('APP_DEBUG=definitely-not-a-boolean');
        $provider = new DebugServiceProvider();
        $provider->setKernelContext('', ['debug' => true], []);
        $manager = new EntityTypeManager(new EventDispatcher());
        $router = new WaaseyaaRouter();

        $provider->routes($router, $manager);

        self::assertSame([], $provider->middleware($manager));
        $this->expectException(RouteNotFoundException::class);
        $router->match('/_error/500');
    }

    public function test_absent_environment_value_uses_server_side_config(): void
    {
        putenv('APP_DEBUG');
        $provider = new DebugServiceProvider();
        $provider->setKernelContext('', ['debug' => true], []);
        $manager = new EntityTypeManager(new EventDispatcher());
        $router = new WaaseyaaRouter();

        $provider->routes($router, $manager);

        self::assertCount(1, $provider->middleware($manager));
        self::assertSame('debug.error_preview', $router->match('/_error/500')['_route']);
    }
}
