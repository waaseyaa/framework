<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

/**
 * Carries only a successful result or a fixed MCP transport classification out
 * of guarded credential use. The original transport exception and message are
 * discarded inside custody.
 *
 * @template-covariant T
 * @internal
 */
final class McpCredentialOutcome
{
    /** @param T|null $value */
    private function __construct(
        private readonly mixed $value,
        private readonly bool $serverUnavailable,
        private readonly string $url,
    ) {}

    /**
     * @template R
     * @param \Closure(): R $operation
     * @return self<R>
     */
    public static function capture(\Closure $operation, string $url): self
    {
        try {
            return new self($operation(), false, $url);
        } catch (McpServerUnavailableException) {
            return new self(null, true, $url);
        }
    }

    /** @return T */
    public function unwrap(): mixed
    {
        if ($this->serverUnavailable) {
            throw new McpServerUnavailableException($this->url, 'MCP server unavailable.');
        }

        return $this->value;
    }
}
