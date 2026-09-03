<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Mcp\Stdio;

/**
 * The standard JSON-RPC 2.0 error codes {@see StdioMcpServer} emits.
 *
 * The local stdio transport is a single-caller, no-HTTP surface: it has no
 * rate limiter, no bearer auth, and no origin to reject, so it has no need for
 * the `-31xxx` band `Waaseyaa\Mcp\McpErrorCode` allocates for those HTTP-tier
 * concerns (and depending on `waaseyaa/mcp` to borrow four constants would
 * pull in the package whose route registrar this transport exists to avoid —
 * ADR-022 C-4, D-1.4, D-9.3). Every failure this server can produce is one of
 * the five JSON-RPC 2.0 base codes below.
 *
 * @api
 */
final class StdioJsonRpcErrorCode
{
    /** The request body was not valid JSON. */
    public const int PARSE_ERROR = -32700;

    /** The request was valid JSON but not a conformant JSON-RPC 2.0 object. */
    public const int INVALID_REQUEST = -32600;

    /** No handler is registered for the requested method. */
    public const int METHOD_NOT_FOUND = -32601;

    /** The method exists, but `params` failed validation. */
    public const int INVALID_PARAMS = -32602;

    /** An unhandled failure inside this server, sanitized before it reaches the wire. */
    public const int INTERNAL_ERROR = -32603;
}
