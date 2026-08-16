<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\ResponseCacheControlMiddleware;

#[CoversClass(ResponseCacheControlMiddleware::class)]
final class ResponseCacheControlMiddlewareTest extends TestCase
{
    #[Test]
    public function cookieBearingResponseReplacesContradictoryPublicPolicies(): void
    {
        $response = new Response('ok');
        $response->headers->set('Cache-Control', [
            'public, max-age=300, s-maxage=300',
            'no-cache, private',
        ]);
        $response->headers->setCookie(Cookie::create('XSRF-TOKEN', 'token'));

        $result = new ResponseCacheControlMiddleware()->process(
            Request::create('/'),
            $this->handlerReturning($response),
        );

        $this->assertSame(['no-store, private'], $result->headers->all('Cache-Control'));
    }

    #[Test]
    public function sessionBoundResponseIsPrivateEvenWhenTheCookieComesFromPhp(): void
    {
        $request = Request::create('/');
        $request->attributes->set(ResponseCacheControlMiddleware::SESSION_BOUND_ATTRIBUTE, true);
        $response = new Response('ok', 200, [
            'Cache-Control' => 'public, max-age=300, s-maxage=300',
        ]);

        $result = new ResponseCacheControlMiddleware()->process(
            $request,
            $this->handlerReturning($response),
        );

        $this->assertSame('no-store, private', $result->headers->get('Cache-Control'));
    }

    #[Test]
    public function cookieFreeStatelessPublicResponseKeepsItsSharedCachePolicy(): void
    {
        $public = 'public, max-age=300, s-maxage=300, stale-while-revalidate=60';
        $response = new Response('ok', 200, ['Cache-Control' => $public]);

        $result = new ResponseCacheControlMiddleware()->process(
            Request::create('/'),
            $this->handlerReturning($response),
        );

        $this->assertSame(
            'max-age=300, public, s-maxage=300, stale-while-revalidate=60',
            $result->headers->get('Cache-Control'),
        );
    }

    private function handlerReturning(Response $response): HttpHandlerInterface
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
