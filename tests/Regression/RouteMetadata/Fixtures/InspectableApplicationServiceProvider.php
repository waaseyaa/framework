<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Regression\RouteMetadata\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class InspectableApplicationServiceProvider extends ServiceProvider
{
    // During remediation this fixture must opt into the accepted declarative
    // contribution API. Turning the legacy routes() call into an inspection
    // path would preserve the defect and must not make the acceptance green.
    public static int $routeContributions = 0;
    public static int $executionServicesConstructed = 0;
    public static int $handlersConstructed = 0;
    public static int $handlersInvoked = 0;

    public function register(): void {}

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        ++self::$routeContributions;

        // This is deliberately representative of today's application hook:
        // describing the route constructs its request-time execution graph.
        $executionService = new RouteExecutionService();
        $handler = new InspectableRouteHandler($executionService);

        $router->addRoute(
            'application.inspectable',
            RouteBuilder::create('/application/inspectable')
                ->controller($handler)
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }

    /** @return array<string, int> */
    public static function counters(): array
    {
        return [
            'route_contributions' => self::$routeContributions,
            'execution_services_constructed' => self::$executionServicesConstructed,
            'handlers_constructed' => self::$handlersConstructed,
            'handlers_invoked' => self::$handlersInvoked,
        ];
    }
}

final class RouteExecutionService
{
    public function __construct()
    {
        ++InspectableApplicationServiceProvider::$executionServicesConstructed;
    }

    public function payload(): string
    {
        return 'application-handler-invoked';
    }
}

final class InspectableRouteHandler
{
    public function __construct(private readonly RouteExecutionService $service)
    {
        ++InspectableApplicationServiceProvider::$handlersConstructed;
    }

    public function __invoke(Request $request): Response
    {
        ++InspectableApplicationServiceProvider::$handlersInvoked;

        return new Response($this->service->payload(), 200, ['Content-Type' => 'text/plain']);
    }
}
