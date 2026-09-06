<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

/**
 * Provider-local resume token plus owning provider name for sealed MCP cursors.
 *
 * @api
 */
final readonly class ContentResourceListResume
{
    public function __construct(
        public string $provider,
        public string $token,
    ) {
        if ($provider === '' || $token === ''
            || strlen($provider) > 64
            || strlen($token) > 512
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $provider) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1
        ) {
            throw new \InvalidArgumentException('Content resource list resumes require bounded opaque tokens.');
        }
    }
}
