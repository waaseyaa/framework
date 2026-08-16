<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

/** @api Non-sensitive refusal when a required outbound MCP server is not ready. */
final class McpReadinessException extends \RuntimeException
{
    public function __construct(
        public readonly string $alias,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            '[MCP_READINESS_BLOCKED] Required MCP server %s is not ready (%s).',
            $alias,
            $reason,
        ));
    }
}
