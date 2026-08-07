<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Routing\RedirectResponse;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(Redirector::class)]
final class RedirectorTest extends TestCase
{
    #[Test]
    public function redirects_to_a_safe_local_path_with_status_and_headers(): void
    {
        $response = $this->redirector()->to(
            '/todos?status=open',
            303,
            ['X-Redirect-Reason' => 'created'],
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/todos?status=open', $response->getTargetUrl());
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('created', $response->headers->get('X-Redirect-Reason'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'relative without leading slash' => ['todos'];
        yield 'absolute HTTP URL' => ['http://example.com/todos'];
        yield 'absolute HTTPS URL' => ['https://example.com/todos'];
        yield 'protocol relative URL' => ['//example.com/todos'];
        yield 'backslash authority trick' => ['/\\example.com/todos'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'carriage return header injection' => ["/todos\rLocation: https://example.com"];
        yield 'line feed header injection' => ["/todos\nLocation: https://example.com"];
        yield 'null byte' => ["/todos\0hidden"];
        yield 'delete control character' => ["/todos\x7Fhidden"];
    }

    #[Test]
    #[DataProvider('unsafeTargets')]
    public function rejects_unsafe_direct_targets_without_echoing_them(string $target): void
    {
        try {
            $this->redirector()->to($target);
            self::fail('Unsafe redirect target was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Redirect target must be a safe local absolute path.', $exception->getMessage());
            if ($target !== '') {
                self::assertStringNotContainsString($target, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function redirects_to_a_named_route_with_parameters(): void
    {
        $router = $this->router();
        $router->addRoute(
            'todo.show',
            RouteBuilder::create('/todos/{todo}')->methods('GET')->build(),
        );

        $response = (new Redirector($router))->toRoute('todo.show', ['todo' => 42], 303);

        self::assertSame('/todos/42', $response->getTargetUrl());
        self::assertSame(303, $response->getStatusCode());
    }

    #[Test]
    public function preserves_unknown_route_failures(): void
    {
        $this->expectException(RouteNotFoundException::class);

        $this->redirector()->toRoute('todo.missing');
    }

    #[Test]
    public function preserves_missing_route_parameter_failures(): void
    {
        $router = $this->router();
        $router->addRoute(
            'todo.show',
            RouteBuilder::create('/todos/{todo}')->methods('GET')->build(),
        );

        $this->expectException(MissingMandatoryParametersException::class);

        (new Redirector($router))->toRoute('todo.show');
    }

    private function redirector(): Redirector
    {
        return new Redirector($this->router());
    }

    private function router(): WaaseyaaRouter
    {
        return new WaaseyaaRouter(new RequestContext('', 'GET'));
    }
}
