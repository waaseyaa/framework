<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\Http\Router\MercureMonitorApiRouter;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorRow;
use Waaseyaa\Api\MercureMonitor\EventStreamFilter;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberRow;

/**
 * Unit tests for MercureMonitorApiRouter (M5D WP01 T004).
 *
 * Mirrors the shape of NotificationAdminApiRouter tests.
 */
#[CoversClass(MercureMonitorApiRouter::class)]
final class MercureMonitorApiRouterTest extends TestCase
{
    private MercureMonitorController $controller;
    private MercureMonitorApiRouter $router;

    protected function setUp(): void
    {
        $inspector = new class implements ChannelInspectorInterface {
            public function listChannels(): array
            {
                return [new ChannelInspectorRow('admin', 1, 1748000000.0, 'entity.saved')];
            }
        };

        $stream = new class implements EventStreamReadModelInterface {
            public function recentEvents(EventStreamFilter $filter, int $limit = 100): array
            {
                return [];
            }
        };

        $observer = new class implements SubscriberObserverInterface {
            public function currentSubscribers(): array
            {
                return [new SubscriberRow(1, 'Alice', ['admin'], 1748000000.0)];
            }
        };

        $this->controller = new MercureMonitorController($inspector, $stream, $observer);
        $this->router = new MercureMonitorApiRouter($this->controller);
    }

    #[Test]
    public function supportsTrueForMercureMonitorControllerRef(): void
    {
        $request = Request::create('/api/mercure/channels', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::channels');

        self::assertTrue($this->router->supports($request));
    }

    #[Test]
    public function supportsFalseForUnrelatedController(): void
    {
        $request = Request::create('/api/something', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::index');

        self::assertFalse($this->router->supports($request));
    }

    #[Test]
    public function dispatchesChannelsAction(): void
    {
        $request = Request::create('/api/mercure/channels', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::channels');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('rows', $body['data']);
    }

    #[Test]
    public function dispatchesSubscribersAction(): void
    {
        $request = Request::create('/api/mercure/subscribers', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::subscribers');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
    }

    #[Test]
    public function dispatchesEventsActionReturnsSse(): void
    {
        $request = Request::create('/api/mercure/events', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::events');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function returns404ForUnknownAction(): void
    {
        $request = Request::create('/api/mercure/unknown', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::unknown');

        $response = $this->router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
    }
}
