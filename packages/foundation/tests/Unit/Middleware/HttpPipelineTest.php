<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HttpPipeline::class)]
final class HttpPipelineTest extends TestCase
{
    #[Test]
    public function empty_pipeline_delegates_to_final_handler(): void
    {
        $pipeline = new HttpPipeline();
        $request = Request::create('/test');

        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('final');
            }
        };

        $response = $pipeline->handle($request, $handler);
        $this->assertSame('final', $response->getContent());
    }

    #[Test]
    public function middleware_wraps_in_onion_order(): void
    {
        $log = [];

        $mw1 = new class($log) implements HttpMiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(Request $request, HttpHandlerInterface $next): Response
            {
                $this->log[] = 'mw1-before';
                $response = $next->handle($request);
                $this->log[] = 'mw1-after';
                return $response;
            }
        };

        $mw2 = new class($log) implements HttpMiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(Request $request, HttpHandlerInterface $next): Response
            {
                $this->log[] = 'mw2-before';
                $response = $next->handle($request);
                $this->log[] = 'mw2-after';
                return $response;
            }
        };

        $finalHandler = new class($log) implements HttpHandlerInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $request): Response
            {
                $this->log[] = 'handler';
                return new Response('ok');
            }
        };

        $pipeline = new HttpPipeline([$mw1, $mw2]);
        $pipeline->handle(Request::create('/test'), $finalHandler);

        $this->assertSame(['mw1-before', 'mw2-before', 'handler', 'mw2-after', 'mw1-after'], $log);
    }

    #[Test]
    public function middleware_can_short_circuit(): void
    {
        $shortCircuit = new class implements HttpMiddlewareInterface {
            public function process(Request $request, HttpHandlerInterface $next): Response
            {
                return new Response('blocked', 403);
            }
        };

        $pipeline = new HttpPipeline([$shortCircuit]);

        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach');
            }
        };

        $response = $pipeline->handle(Request::create('/test'), $handler);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $response->getContent());
    }

    #[Test]
    public function middleware_can_modify_request(): void
    {
        $addHeader = new class implements HttpMiddlewareInterface {
            public function process(Request $request, HttpHandlerInterface $next): Response
            {
                $request->headers->set('X-Custom', 'added');
                return $next->handle($request);
            }
        };

        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response($request->headers->get('X-Custom', 'missing'));
            }
        };

        $pipeline = new HttpPipeline([$addHeader]);
        $response = $pipeline->handle(Request::create('/test'), $handler);
        $this->assertSame('added', $response->getContent());
    }
}
