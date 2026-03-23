<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

#[AsMiddleware(pipeline: 'http', priority: 60)]
final class RequestLoggingMiddleware implements HttpMiddlewareInterface
{
    /** @var \Closure(string): void */
    private readonly \Closure $logger;

    /**
     * @param ?\Closure(string): void $logger Logging callback. Defaults to error_log().
     */
    public function __construct(?\Closure $logger = null)
    {
        $this->logger = $logger ?? static fn (string $message): bool => error_log($message);
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $startNs = hrtime(true);

        $response = $next->handle($request);

        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        ($this->logger)(sprintf(
            '[Waaseyaa] %s %s %d %.2fms',
            $request->getMethod(),
            $request->getPathInfo(),
            $response->getStatusCode(),
            $durationMs,
        ));

        return $response;
    }
}
