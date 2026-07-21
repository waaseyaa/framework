<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

#[CoversClass(ControllerDispatcher::class)]
final class ControllerDispatcherTest extends TestCase
{
    #[Test]
    public function delegates_to_first_supporting_router(): void
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', 'test.action');

        $expected = new Response('router handled', 200);

        $router = $this->createStub(DomainRouterInterface::class);
        $router->method('supports')->willReturn(true);
        $router->method('handle')->willReturn($expected);

        $dispatcher = new ControllerDispatcher([$router]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('router handled', $response->getContent());
    }

    #[Test]
    public function returns_404_when_no_router_supports(): void
    {
        $request = Request::create('/unknown');
        $request->attributes->set('_controller', 'unknown.action');

        $router = $this->createStub(DomainRouterInterface::class);
        $router->method('supports')->willReturn(false);

        $dispatcher = new ControllerDispatcher([$router]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('unknown.action', $response->getContent());
    }

    #[Test]
    public function callable_controller_returns_response_directly(): void
    {
        $request = Request::create('/callable');
        $request->attributes->set('_controller', fn(Request $r) => new Response('from callable', 200));

        $dispatcher = new ControllerDispatcher([]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from callable', $response->getContent());
    }

    #[Test]
    public function callable_controller_wraps_array_result(): void
    {
        $request = Request::create('/callable-array');
        $request->attributes->set('_controller', fn(Request $r) => ['statusCode' => 201, 'body' => ['id' => 'abc']]);

        $dispatcher = new ControllerDispatcher([]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(201, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertSame('abc', $decoded['id']);
    }

    #[Test]
    public function first_match_wins_in_router_chain(): void
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', 'test.action');

        $first = $this->createStub(DomainRouterInterface::class);
        $first->method('supports')->willReturn(true);
        $first->method('handle')->willReturn(new Response('first', 200));

        $second = $this->createStub(DomainRouterInterface::class);
        $second->method('supports')->willReturn(true);
        $second->method('handle')->willReturn(new Response('second', 200));

        $dispatcher = new ControllerDispatcher([$first, $second]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame('first', $response->getContent());
    }

    #[Test]
    public function callable_controller_exception_returns_500(): void
    {
        $request = Request::create('/callable-error');
        $request->attributes->set('_controller', fn(Request $r) => throw new \RuntimeException('callable boom'));

        $dispatcher = new ControllerDispatcher([]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(500, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertSame('500', $decoded['errors'][0]['status']);
        $this->assertSame('Internal Server Error', $decoded['errors'][0]['title']);
    }

    #[Test]
    public function router_exception_returns_500(): void
    {
        $request = Request::create('/error');
        $request->attributes->set('_controller', 'error.action');

        $router = $this->createStub(DomainRouterInterface::class);
        $router->method('supports')->willReturn(true);
        $router->method('handle')->willThrowException(new \RuntimeException('boom'));

        $dispatcher = new ControllerDispatcher([$router]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(500, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        $this->assertSame('500', $decoded['errors'][0]['status']);
    }

    #[Test]
    public function debug_error_response_never_discloses_exception_or_stack_trace(): void
    {
        $request = Request::create('/debug-error');
        $request->attributes->set('_controller', fn(Request $r) => throw new \RuntimeException('sensitive failure'));

        $dispatcher = new ControllerDispatcher([]);
        $response = $dispatcher->dispatch($request);

        $this->assertSame(500, $response->getStatusCode());
        $body = (string) $response->getContent();
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $error = $decoded['errors'][0];
        $this->assertArrayNotHasKey('meta', $error);
        $this->assertSame('An unexpected error occurred.', $error['detail']);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString(__FILE__, $body);
        $this->assertStringNotContainsString('sensitive failure', $body);
    }
}
