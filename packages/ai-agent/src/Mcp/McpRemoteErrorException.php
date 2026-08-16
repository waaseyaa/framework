<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

/** @api Fixed non-secret signal for a JSON-RPC error returned by an MCP server. */
final class McpRemoteErrorException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('MCP server returned a remote JSON-RPC error.');
    }
}
