<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

#[AsMiddleware(pipeline: 'http', priority: 100)]
final class SecurityHeadersMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly string $csp = "default-src 'self'",
        private readonly bool $hstsEnabled = true,
        private readonly int $hstsMaxAge = 31_536_000,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp);
        }

        if (!$response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        if ($this->hstsEnabled && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set(
                'Strict-Transport-Security',
                sprintf('max-age=%d; includeSubDomains', $this->hstsMaxAge),
            );
        }

        return $response;
    }
}
