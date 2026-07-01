<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\SSR\Http\Router\AppControllerRouter;
use Waaseyaa\SSR\Http\Router\SsrRouter;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;

#[CoversClass(SsrServiceProvider::class)]
final class SsrServiceProviderHttpRoutersTest extends TestCase
{
    #[Test]
    public function httpDomainRouters_returns_empty_when_handler_not_configured(): void
    {
        $provider = new SsrServiceProvider();
        $kernel = new HttpKernel('/tmp');
        self::assertSame([], iterator_to_array($provider->httpDomainRouters($kernel)));
    }

    #[Test]
    public function httpDomainRouters_returns_ssr_then_app_controller_router(): void
    {
        $provider = new SsrServiceProvider();
        $provider->register();
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = DBALDatabase::createSqlite();
        $handler = new SsrPageHandler(
            entityTypeManager: $etm,
            database: $db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver(),
            discoveryHandler: new DiscoveryApiHandler($etm, $db),
            projectRoot: '/tmp',
            config: [],
        );

        $ref = new \ReflectionClass($provider);
        $pageHandlerProp = $ref->getProperty('ssrPageHandler');
        $pageHandlerProp->setValue($provider, $handler);

        $kernel = new HttpKernel('/tmp');
        $routers = array_values(iterator_to_array($provider->httpDomainRouters($kernel)));
        self::assertCount(2, $routers);
        self::assertInstanceOf(SsrRouter::class, $routers[0]);
        self::assertInstanceOf(AppControllerRouter::class, $routers[1]);
    }
}
