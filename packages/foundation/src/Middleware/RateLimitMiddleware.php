<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\RateLimit\RateLimiterInterface;

#[AsMiddleware(pipeline: 'http', priority: 80)]
final class RateLimitMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $key = $request->getClientIp() ?? 'unknown';
        $result = $this->limiter->attempt($key, $this->maxAttempts, $this->windowSeconds);

        if (!$result['allowed']) {
            $response = new JsonResponse(
                [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [
                        [
                            'status' => '429',
                            'title' => 'Too Many Requests',
                        ],
                    ],
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
            $response->headers->set('Retry-After', (string) $result['retryAfter']);
            $response->headers->set('X-RateLimit-Limit', (string) $this->maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', '0');

            return $response;
        }

        $response = $next->handle($request);
        $response->headers->set('X-RateLimit-Limit', (string) $this->maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $result['remaining']);

        return $response;
    }
}
