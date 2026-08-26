<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase13\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Minimal service provider for CSRF integration tests.
 *
 * Registers three routes:
 *
 *   GET  /test/protected     — HTML response (causes XSRF-TOKEN cookie to be set)
 *   POST /test/protected     — CSRF-protected multipart endpoint (returns 200 OK)
 *   POST /test/api/json-route — JSON-exempt endpoint (returns 200 always)
 */
final class CsrfTestServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface, HasMiddlewareInterface
{
    public function register(): void
    {
        // No bindings needed for CSRF integration test routes.
    }

    /**
     * @return list<HttpMiddlewareInterface>
     */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [
            new FinalResponseProbeMiddleware(),
        ];
    }

    /** @return iterable<DomainRouterInterface> */
    public function httpDomainRouters(HttpKernel $kernel): iterable
    {
        if (($_SERVER['REQUEST_URI'] ?? '') === '/test/throws') {
            throw new \RuntimeException('fixture router construction exploded');
        }

        return [];
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        // GET /test/protected — HTML response triggers cookie attachment.
        $router->addRoute(
            'test.csrf.get',
            RouteBuilder::create('/test/protected')
                ->controller(static fn(Request $r): Response => new Response(
                    '<!DOCTYPE html><html><body><p>CSRF test page</p></body></html>',
                    200,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // POST /test/protected — CSRF-protected render route; middleware validates
        // token before this runs. Marked as render so the 403 error uses the
        // HTML "Invalid Security Token" body (matching contract §3 / §5).
        $router->addRoute(
            'test.csrf.post',
            RouteBuilder::create('/test/protected')
                ->controller(static fn(Request $r): Response => new Response(
                    '<!DOCTYPE html><html><body><p>OK</p></body></html>',
                    200,
                    ['Content-Type' => 'text/html; charset=UTF-8'],
                ))
                ->allowAll()
                ->render()
                ->methods('POST')
                ->build(),
        );

        // POST /test/api/json-route — application/json is exempt from CSRF.
        $router->addRoute(
            'test.csrf.json',
            RouteBuilder::create('/test/api/json-route')
                ->controller(static fn(Request $r): Response => new Response(
                    '{"ok":true}',
                    200,
                    ['Content-Type' => 'application/json'],
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'test.controller.throws',
            RouteBuilder::create('/test/throws')
                ->controller(static function (): never {
                    throw new \RuntimeException('fixture controller exploded');
                })
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }
}

/**
 * Field-reproduction probe: an app/provider middleware response mutation must
 * decorate the actual controller response, not the kernel's empty 200 sentinel.
 */
final class FinalResponseProbeMiddleware implements HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);
        $body = (string) $response->getContent();
        $response->headers->set('X-App-Observed-Status', (string) $response->getStatusCode());
        $response->headers->set('X-App-Observed-Body-Sha256', hash('sha256', $body));

        return $response;
    }
}
