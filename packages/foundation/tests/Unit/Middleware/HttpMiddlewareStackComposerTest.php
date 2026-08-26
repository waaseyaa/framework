<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareStackComposer;

#[AsMiddleware(pipeline: 'http', priority: 80)]
final class ComposerPriority80Middleware implements HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        return $next->handle($request);
    }
}

#[AsMiddleware(pipeline: 'http', priority: 70)]
final class ComposerFirstPriority70Middleware implements HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        return $next->handle($request);
    }
}

#[AsMiddleware(pipeline: 'http', priority: 70)]
final class ComposerSecondPriority70Middleware implements HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        return $next->handle($request);
    }
}

#[CoversClass(HttpMiddlewareStackComposer::class)]
final class HttpMiddlewareStackComposerTest extends TestCase
{
    #[Test]
    public function composes_one_stable_priority_order_from_built_ins_and_providers(): void
    {
        $builtIn70 = new ComposerFirstPriority70Middleware();
        $provider70 = new ComposerSecondPriority70Middleware();
        $provider80 = new ComposerPriority80Middleware();

        $ordered = new HttpMiddlewareStackComposer()->compose(
            [$builtIn70],
            [
                ['middleware' => $provider70, 'provider' => self::class],
                ['middleware' => $provider80, 'provider' => self::class],
            ],
        );

        self::assertSame([$provider80, $builtIn70, $provider70], $ordered);
    }

    #[Test]
    public function duplicate_concrete_middleware_fails_closed_with_both_sources(): void
    {
        $middleware = new ComposerPriority80Middleware();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(ComposerPriority80Middleware::class);
        $this->expectExceptionMessage('kernel built-ins');
        $this->expectExceptionMessage(self::class);

        new HttpMiddlewareStackComposer()->compose(
            [$middleware],
            [['middleware' => new ComposerPriority80Middleware(), 'provider' => self::class]],
        );
    }
}
