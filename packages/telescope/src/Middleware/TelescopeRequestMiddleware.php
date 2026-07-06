<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Telescope\TelescopeServiceProvider;

/**
 * Records HTTP request timing and metadata via Telescope's RequestRecorder.
 *
 * Sits at the outermost layer of the middleware pipeline (priority 100) so it
 * captures total request duration including all inner middleware processing.
 */
#[AsMiddleware(pipeline: 'http', priority: 100)]
final class TelescopeRequestMiddleware implements HttpMiddlewareInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TelescopeServiceProvider $telescope,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $startTime = hrtime(true);

        $response = $next->handle($request);

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        // Telemetry is a non-critical side effect: a storage failure (full
        // disk, locked SQLite file, JSON-encode error) must never turn an
        // already-successful response into a 500. Best-effort only.
        try {
            $this->recordRequest(
                method: $request->getMethod(),
                uri: $request->getPathInfo(),
                statusCode: $response->getStatusCode(),
                durationMs: $durationMs,
                controller: $this->resolveController($request),
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Telescope request recording failed: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        return $response;
    }

    public function recordRequest(
        string $method,
        string $uri,
        int $statusCode,
        float $durationMs,
        string $controller = '',
    ): void {
        $recorder = $this->telescope->getRequestRecorder();

        if ($recorder === null) {
            return;
        }

        $recorder->record(
            method: $method,
            uri: $uri,
            statusCode: $statusCode,
            duration: $durationMs,
            controller: $controller,
        );
    }

    private function resolveController(Request $request): string
    {
        $controller = $request->attributes->get('_controller', '');

        return is_string($controller) ? $controller : '';
    }
}
