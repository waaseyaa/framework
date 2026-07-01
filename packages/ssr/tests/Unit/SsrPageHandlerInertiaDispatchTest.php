<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Inertia\InertiaFullPageRendererInterface;
use Waaseyaa\Foundation\Http\Inertia\InertiaPageResultInterface;
use Waaseyaa\SSR\SsrPageHandler;

#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerInertiaDispatchTest extends TestCase
{
    private function createHandler(?InertiaFullPageRendererInterface $renderer = null): SsrPageHandler
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = DBALDatabase::createSqlite();
        $discoveryHandler = new DiscoveryApiHandler($entityTypeManager, $database);

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: $discoveryHandler,
            projectRoot: '/tmp/ssr-inertia-test',
            config: [],
            inertiaFullPageRenderer: $renderer,
        );
    }

    private function attachRoute(HttpRequest $request, string $path = '/'): void
    {
        $route = new Route($path);
        $request->attributes->set('_route_object', $route);
        $request->attributes->set('_route', 'test.inertia');
    }

    #[Test]
    public function dispatch_app_controller_renders_inertia_full_page_when_renderer_configured(): void
    {
        $renderer = new class implements InertiaFullPageRendererInterface {
            public function render(array $pageObject): string
            {
                return '<html><body data-page="' . htmlspecialchars((string) ($pageObject['component'] ?? ''), ENT_QUOTES, 'UTF-8') . '"></body></html>';
            }
        };

        $handler = $this->createHandler($renderer);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/my-community');
        $this->attachRoute($request, '/{communitySlug}');
        $request->attributes->set('communitySlug', 'my-community');

        $result = $handler->dispatchAppController(
            InertiaReturningController::class . '::page',
            $account,
            $request,
        );

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $result);
        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('Discovery/Index', (string) $result->getContent());
        self::assertStringContainsString('text/html', (string) $result->headers->get('Content-Type'));
    }

    #[Test]
    public function dispatch_app_controller_returns_inertia_json_for_xhr(): void
    {
        $handler = $this->createHandler(new class implements InertiaFullPageRendererInterface {
            public function render(array $pageObject): string
            {
                return '';
            }
        });
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/my-community');
        $this->attachRoute($request);
        $request->headers->set('X-Inertia', 'true');

        $result = $handler->dispatchAppController(
            InertiaReturningController::class . '::page',
            $account,
            $request,
        );

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $result);
        self::assertSame(200, $result->getStatusCode());
        self::assertSame('true', $result->headers->get('X-Inertia'));
        $decoded = json_decode((string) $result->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Discovery/Index', $decoded['component'] ?? null);
        self::assertSame('/my-community', $decoded['url'] ?? null);
    }

    #[Test]
    public function dispatch_app_controller_500_when_inertia_full_page_but_no_renderer(): void
    {
        $handler = $this->createHandler(null);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/x');
        $this->attachRoute($request);

        $result = $handler->dispatchAppController(
            InertiaReturningController::class . '::page',
            $account,
            $request,
        );

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $result);
        self::assertSame(500, $result->getStatusCode());
        $decoded = json_decode((string) $result->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $decoded);
    }
}

final class InertiaReturningController
{
    public function page(
        AccountInterface $account,
        HttpRequest $httpRequest,
    ): InertiaPageResultInterface {
        return new class implements InertiaPageResultInterface {
            public function toPageObject(): array
            {
                return [
                    'component' => 'Discovery/Index',
                    'props' => ['community' => null],
                    'version' => 'test',
                ];
            }
        };
    }
}
