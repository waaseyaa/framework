<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Inertia\Inertia;
use Waaseyaa\Inertia\InertiaMiddleware;

#[CoversClass(InertiaMiddleware::class)]
final class InertiaMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Inertia::reset();
    }

    protected function tearDown(): void
    {
        Inertia::reset();
    }

    public function testNonInertiaRequestPassesThrough(): void
    {
        $middleware = new InertiaMiddleware('v1');
        $request = Request::create('/users', 'GET');

        $handler = $this->createMockHandler(new Response('plain html', 200));
        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('plain html', $response->getContent());
    }

    public function testVersionMismatchReturns409(): void
    {
        $middleware = new InertiaMiddleware('v2');
        $request = Request::create('/users', 'GET');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Version', 'v1');

        $handler = $this->createMockHandler(new Response());
        $response = $middleware->process($request, $handler);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('/users', $response->headers->get('X-Inertia-Location'));
    }

    public function testVersionMatchPassesThrough(): void
    {
        $middleware = new InertiaMiddleware('v1');
        $request = Request::create('/users', 'GET');
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Version', 'v1');

        $handler = $this->createMockHandler(new Response('ok', 200));
        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function testNoVersionHeaderPassesThrough(): void
    {
        $middleware = new InertiaMiddleware('v1');
        $request = Request::create('/users', 'GET');
        $request->headers->set('X-Inertia', 'true');

        $handler = $this->createMockHandler(new Response('ok', 200));
        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSharedPropsDoNotSurviveIntoTheNextRequestInTheSameProcess(): void
    {
        $middleware = new InertiaMiddleware('v1');
        $handler = new class implements HttpHandlerInterface {
            /** @var list<array<string, mixed>> */
            public array $pages = [];

            private int $requestCount = 0;

            public function handle(Request $request): Response
            {
                if ($this->requestCount++ === 0) {
                    Inertia::share('auth', ['user' => ['name' => 'Alice']]);
                }

                $this->pages[] = Inertia::render('Dashboard', [])->toPageObject();

                return new Response('ok');
            }
        };

        $middleware->process(Request::create('/first'), $handler);
        $middleware->process(Request::create('/second'), $handler);

        $this->assertSame(['user' => ['name' => 'Alice']], $handler->pages[0]['props']['auth']);
        $this->assertArrayNotHasKey('auth', $handler->pages[1]['props']);
    }

    private function createMockHandler(Response $response): HttpHandlerInterface
    {
        return new class ($response) implements HttpHandlerInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };
    }
}
