<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

/** @internal Fixed non-secret signal for missing MCP resolver composition. */
final class McpCredentialUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('MCP credential custody is unavailable.');
    }
}
