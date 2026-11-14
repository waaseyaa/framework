<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

/** Applies private response caching to every canonical engagement route result. */
#[AsMiddleware(pipeline: 'http', priority: 120)]
final class EngagementResponsePrivacyMiddleware implements HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $route = $request->attributes->get('_route_object');
        $isEngagementRoute = $route instanceof Route && $route->getOption('_engagement') === true;

        $response = $next->handle($request);

        if ($isEngagementRoute) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
