<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\ApiCatalogController;
use Waaseyaa\Api\Discovery\ApiCatalog;
use Waaseyaa\Api\Http\Router\ApiCatalogRouter;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;

#[CoversClass(ApiCatalogRouter::class)]
final class ApiCatalogRouterTest extends TestCase
{
    #[Test]
    public function handles_only_the_closed_catalog_controller_alias(): void
    {
        $router = new ApiCatalogRouter(new ApiCatalogController(new ApiCatalog('https://cms.example', [
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp')),
        ])));
        $request = Request::create('/.well-known/api-catalog');
        $request->attributes->set('_controller', 'api.catalog');

        self::assertTrue($router->supports($request));
        self::assertSame(200, $router->handle($request)->getStatusCode());

        $request->attributes->set('_controller', 'api.openapi');
        self::assertFalse($router->supports($request));
    }
}
