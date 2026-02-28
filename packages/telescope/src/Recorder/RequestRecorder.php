<?php

declare(strict_types=1);

namespace Aurora\Telescope\Recorder;

use Aurora\Telescope\Storage\TelescopeStoreInterface;

/**
 * Records HTTP requests with method, URI, status code, duration, controller, and middleware.
 */
final class RequestRecorder
{
    public const string TYPE = 'request';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
        private readonly array $ignorePaths = [],
    ) {}

    /**
     * Record an HTTP request.
     *
     * @param string $method HTTP method (GET, POST, etc.).
     * @param string $uri Request URI.
     * @param int $statusCode HTTP response status code.
     * @param float $duration Duration in milliseconds.
     * @param string $controller Controller that handled the request.
     * @param string[] $middleware Middleware stack applied.
     */
    public function record(
        string $method,
        string $uri,
        int $statusCode,
        float $duration,
        string $controller = '',
        array $middleware = [],
    ): void {
        if ($this->shouldIgnore($uri)) {
            return;
        }

        $this->store->store(self::TYPE, [
            'method' => $method,
            'uri' => $uri,
            'status_code' => $statusCode,
            'duration' => $duration,
            'controller' => $controller,
            'middleware' => $middleware,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @return string[]
     */
    public function getIgnorePaths(): array
    {
        return $this->ignorePaths;
    }

    private function shouldIgnore(string $uri): bool
    {
        foreach ($this->ignorePaths as $pattern) {
            if ($this->matchesPattern($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $uri, string $pattern): bool
    {
        // Convert glob-like pattern to regex.
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

        return (bool) preg_match($regex, $uri);
    }
}
