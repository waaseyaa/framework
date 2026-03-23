<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Handles CORS preflight and header resolution.
 *
 * Origins are configurable via the constructor. Defaults to Nuxt dev
 * server ports. If the dev server binds to a different port, pass
 * the origins in the config array.
 */
final class CorsHandler
{
    private readonly LoggerInterface $logger;

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private readonly array $allowedOrigins = ['http://localhost:3000', 'http://127.0.0.1:3000'],
        private readonly bool $allowDevLocalhostPorts = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return list<string>
     */
    public function resolveCorsHeaders(string $origin): array
    {
        if ($this->isOriginAllowed($origin)) {
            return [
                "Access-Control-Allow-Origin: {$origin}",
                'Vary: Origin',
                'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers: Content-Type, Accept, Authorization',
                'Access-Control-Max-Age: 86400',
            ];
        }

        if ($origin !== '') {
            $this->logger->warning(sprintf(
                'CORS: origin "%s" not in allowed list (%s). '
                . 'If using Nuxt dev server on a non-standard port, update cors_origins in config/waaseyaa.php.',
                $origin,
                implode(', ', $this->allowedOrigins),
            ));
        }

        return [];
    }

    public function isOriginAllowed(string $origin): bool
    {
        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        if (!$this->allowDevLocalhostPorts) {
            return false;
        }

        return preg_match('#^https?://(localhost|127\.0\.0\.1):\d+$#', $origin) === 1;
    }

    public function isCorsPreflightRequest(string $method): bool
    {
        return strtoupper($method) === 'OPTIONS';
    }
}
