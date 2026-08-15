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
    private const string SUCCESS = 'success';

    private const string SERVER_UNAVAILABLE = 'server-unavailable';

    private const string REMOTE_ERROR = 'remote-error';

    /** @param T|null $value */
    private function __construct(
        private readonly mixed $value,
        private readonly string $state,
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
            return new self($operation(), self::SUCCESS, $url);
        } catch (McpServerUnavailableException) {
            return new self(null, self::SERVER_UNAVAILABLE, $url);
        } catch (McpRemoteErrorException) {
            return new self(null, self::REMOTE_ERROR, $url);
        }
    }

    /** @return T */
    public function unwrap(): mixed
    {
        if ($this->state === self::SERVER_UNAVAILABLE) {
            throw new McpServerUnavailableException($this->url, 'MCP server unavailable.');
        }
        if ($this->state === self::REMOTE_ERROR) {
            throw new McpRemoteErrorException();
        }

        return $this->value;
    }
}
