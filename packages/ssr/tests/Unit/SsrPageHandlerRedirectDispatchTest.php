<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Routing\Controller;
use Waaseyaa\Routing\RedirectResponse;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\User\AnonymousUser;

final class BaseRedirectController extends Controller
{
    public function store(): RedirectResponse
    {
        return $this->redirectToRoute('todo.show', ['todo' => 11], 303);
    }
}

final class PlainRedirectController
{
    public function store(Redirector $redirector): RedirectResponse
    {
        return $redirector->to('/todos', 303);
    }
}

#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerRedirectDispatchTest extends TestCase
{
    #[Test]
    public function dispatches_an_optional_base_controller_with_a_named_route_redirect(): void
    {
        $response = $this->dispatch(BaseRedirectController::class . '::store');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/todos/11', $response->getTargetUrl());
    }

    #[Test]
    public function dispatches_a_plain_final_controller_with_redirector_action_injection(): void
    {
        $response = $this->dispatch(PlainRedirectController::class . '::store');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/todos', $response->getTargetUrl());
    }

    private function dispatch(string $controller): RedirectResponse
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = DBALDatabase::createSqlite();
        $handler = new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver(),
            discoveryHandler: new DiscoveryApiHandler($entityTypeManager, $database),
            projectRoot: dirname(__DIR__, 4),
            config: [],
        );
        $router = new WaaseyaaRouter();
        $router->addRoute('todo.show', RouteBuilder::create('/todos/{todo}')->methods('GET')->build());

        $route = RouteBuilder::create('/todos')->controller($controller)->methods('POST')->build();
        $request = Request::create('/todos', 'POST');
        $request->attributes->set('_route', 'todo.store');
        $request->attributes->set('_route_object', $route);
        $request->attributes->set(Redirector::REQUEST_ATTRIBUTE, new Redirector($router));

        $response = $handler->dispatchAppController($controller, new AnonymousUser(), $request);
        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response;
    }
}
