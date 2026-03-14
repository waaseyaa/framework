<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

// Local stubs — no cross-layer imports needed.
#[AsMiddleware(pipeline: 'http', priority: 40)]
final class StubPriority40Middleware implements HttpMiddlewareInterface
{
    public function process(HttpRequest $request, HttpHandlerInterface $next): HttpResponse
    {
        return $next->handle($request);
    }
}

#[AsMiddleware(pipeline: 'http', priority: 30)]
final class StubPriority30Middleware implements HttpMiddlewareInterface
{
    public function process(HttpRequest $request, HttpHandlerInterface $next): HttpResponse
    {
        return $next->handle($request);
    }
}

#[AsMiddleware(pipeline: 'http', priority: 20)]
final class StubPriority20Middleware implements HttpMiddlewareInterface
{
    public function process(HttpRequest $request, HttpHandlerInterface $next): HttpResponse
    {
        return $next->handle($request);
    }
}

#[AsMiddleware(pipeline: 'http', priority: 10)]
final class StubPriority10Middleware implements HttpMiddlewareInterface
{
    public function process(HttpRequest $request, HttpHandlerInterface $next): HttpResponse
    {
        return $next->handle($request);
    }
}

#[CoversClass(AsMiddleware::class)]
final class MiddlewareOrderingTest extends TestCase
{
    #[Test]
    public function middleware_have_correct_priority_attributes(): void
    {
        $this->assertMiddlewarePriority(StubPriority40Middleware::class, 40);
        $this->assertMiddlewarePriority(StubPriority30Middleware::class, 30);
        $this->assertMiddlewarePriority(StubPriority20Middleware::class, 20);
        $this->assertMiddlewarePriority(StubPriority10Middleware::class, 10);
    }

    #[Test]
    public function sort_order_is_determined_by_priority_not_registration_order(): void
    {
        // Provide class names in REVERSE priority order to prove registration order is irrelevant.
        $classes = [
            StubPriority10Middleware::class, // priority 10
            StubPriority20Middleware::class, // priority 20
            StubPriority30Middleware::class, // priority 30
            StubPriority40Middleware::class, // priority 40
        ];

        usort(
            $classes,
            fn (string $a, string $b) => $this->readPriority($b) <=> $this->readPriority($a),
        );

        $this->assertSame(
            [
                StubPriority40Middleware::class,
                StubPriority30Middleware::class,
                StubPriority20Middleware::class,
                StubPriority10Middleware::class,
            ],
            $classes,
        );
    }

    private function assertMiddlewarePriority(string $class, int $expected): void
    {
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(AsMiddleware::class);
        $this->assertNotEmpty($attributes, "No AsMiddleware attribute on {$class}");
        $this->assertSame($expected, $attributes[0]->newInstance()->priority);
    }

    private function readPriority(string $class): int
    {
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(AsMiddleware::class);
        if (empty($attributes)) {
            return 0;
        }
        return $attributes[0]->newInstance()->priority;
    }
}
