<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

/**
 * Reconciles the final response cache policy after session/cookie middleware.
 */
#[AsMiddleware(pipeline: 'http', priority: 110)]
final class ResponseCacheControlMiddleware implements HttpMiddlewareInterface
{
    public const string SESSION_BOUND_ATTRIBUTE = '_waaseyaa_session_bound';

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (
            $request->attributes->get(self::SESSION_BOUND_ATTRIBUTE) === true
            || $response->headers->getCookies() !== []
        ) {
            // One final authority: replacing the complete field removes any
            // public/s-maxage directive emitted by an inner renderer.
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
