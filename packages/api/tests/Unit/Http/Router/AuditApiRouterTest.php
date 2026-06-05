<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Api\Http\Router\AuditApiRouter;

#[CoversClass(AuditApiRouter::class)]
final class AuditApiRouterTest extends TestCase
{
    private function makeRouter(): AuditApiRouter
    {
        return new AuditApiRouter(new AuditQueryController(null));
    }

    #[Test]
    public function supportsAuditQueryControllerRequests(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/audit/events', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AuditQueryController::index');

        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function doesNotSupportOtherControllers(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/scheduler/tasks', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchedulerController::index');

        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function dispatchesIndexAction(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/audit/events', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AuditQueryController::index');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
    }

    #[Test]
    public function unknownActionReturns404JsonApiError(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/audit/events', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AuditQueryController::unknown');

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
        self::assertSame('404', $body['errors'][0]['status']);
    }
}
