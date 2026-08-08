<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Routing\Controller;
use Waaseyaa\Routing\RedirectResponse;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(Controller::class)]
final class ControllerTest extends TestCase
{
    #[Test]
    public function thin_base_delegates_direct_and_named_redirects(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $router->addRoute(
            'todo.show',
            RouteBuilder::create('/todos/{todo}')->methods('GET')->build(),
        );
        $controller = new RedirectFixtureController(new Redirector($router));

        $direct = $controller->direct('/todos');
        $named = $controller->named('todo.show', ['todo' => 7], 303);

        self::assertSame('/todos', $direct->getTargetUrl());
        self::assertSame('/todos/7', $named->getTargetUrl());
        self::assertSame(303, $named->getStatusCode());
    }
}

final class RedirectFixtureController extends Controller
{
    public function direct(string $path): RedirectResponse
    {
        return $this->redirect($path);
    }

    /** @param array<string, mixed> $parameters */
    public function named(string $route, array $parameters, int $status): RedirectResponse
    {
        return $this->redirectToRoute($route, $parameters, $status);
    }
}
