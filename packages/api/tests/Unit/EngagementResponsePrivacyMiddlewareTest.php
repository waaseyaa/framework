<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Api\Middleware\EngagementResponsePrivacyMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareStackComposer;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Middleware\ResponseCacheControlMiddleware;

#[CoversClass(EngagementResponsePrivacyMiddleware::class)]
final class EngagementResponsePrivacyMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    #[Test]
    public function csrfRejectionGetsPrivateNoStoreAndReplacesPublicDirectives(): void
    {
        $request = $this->engagementRequest('POST');
        $request->attributes->get('_route_object')->setOption('_csrf', true);

        $response = $this->pipeline()->handle($request, $this->handler(
            new Response('unexpected', 200, ['Cache-Control' => 'public, s-maxage=600']),
        ));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function validJsonCsrfRequestGetsPrivateNoStore(): void
    {
        $this->startSession();
        $_SESSION['_csrf_token'] = 'csrf-secret';

        $request = $this->engagementRequest('POST');
        $request->attributes->get('_route_object')->setOption('_csrf', true);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-CSRF-Token', 'csrf-secret');

        $response = $this->pipeline()->handle($request, $this->handler(
            new JsonResponse(['ok' => true], 204, ['Cache-Control' => 'public, s-maxage=600']),
        ));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function anonymousReadErrorGetsPrivateNoStoreWithoutStartingSession(): void
    {
        $sessionStatus = session_status();
        $request = $this->engagementRequest('GET');

        $response = $this->pipeline()->handle($request, $this->handler(
            new JsonResponse(['errors' => [['status' => '401']]], 401),
        ));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame($sessionStatus, session_status());
        self::assertSame([], $response->headers->getCookies());
    }

    #[Test]
    public function nonEngagementRouteKeepsItsCachePolicy(): void
    {
        $request = Request::create('/api/public', 'GET');
        $request->attributes->set('_route_object', new Route('/api/public'));

        $response = $this->pipeline()->handle($request, $this->handler(
            new Response('public', 200, ['Cache-Control' => 'public, s-maxage=600']),
        ));

        self::assertSame('public, s-maxage=600', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function userSuppliedQueryCannotMarkRouteAsEngagement(): void
    {
        $request = Request::create('/api/public?_engagement=1', 'GET');
        $request->attributes->set('_route_object', new Route('/api/public'));

        $response = $this->pipeline()->handle($request, $this->handler(
            new Response('public', 200, ['Cache-Control' => 'public, s-maxage=600']),
        ));

        self::assertSame('public, s-maxage=600', $response->headers->get('Cache-Control'));
    }

    private function pipeline(): HttpPipeline
    {
        $middleware = (new HttpMiddlewareStackComposer())->compose([
            new ResponseCacheControlMiddleware(),
            new CsrfMiddleware(),
        ], [[
            'middleware' => new EngagementResponsePrivacyMiddleware(),
            'provider' => self::class,
        ]]);

        return new HttpPipeline($middleware);
    }

    private function engagementRequest(string $method): Request
    {
        $request = Request::create('/api/engagement/comments', $method);
        $route = new Route('/api/engagement/comments');
        $route->setOption('_engagement', true);
        $request->attributes->set('_route_object', $route);

        return $request;
    }

    private function handler(Response $response): HttpHandlerInterface
    {
        return new class($response) implements HttpHandlerInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };
    }

    private function startSession(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
