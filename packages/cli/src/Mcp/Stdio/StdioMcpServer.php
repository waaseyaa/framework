<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Mcp\Stdio;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatcherInterface;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatchOutcome;

/**
 * A conformant JSON-RPC 2.0 MCP server speaking newline-delimited JSON over a
 * pair of byte streams (ADR-022 D-9.2).
 *
 * **The stream contract is the whole point of this class.** Nothing is ever
 * written to `$out` except a complete, single-line JSON-RPC frame terminated
 * by `"\n"`; every diagnostic — a parse failure, an unhandled exception, an
 * operator-facing note — goes through the injected `$diagnostic` closure
 * instead, which the caller wires to STDERR. A stray `echo`, a warning PHP
 * prints to STDOUT, or a library that writes a banner on `$out` would corrupt
 * every frame after it for a client reading line-by-line; keeping this class
 * the ONLY writer of `$out`, and giving it no other reason to write to it, is
 * how that is prevented rather than merely hoped for.
 *
 * **Reuses the transport-neutral dispatch, does not fork it.** Tool listing
 * goes through the injected {@see ToolDispatcherInterface} exactly as the HTTP
 * tiers use it (`tools()` is already name-ordered, `toMcpDescriptor()` is
 * already the wire shape) — this class adds no parallel listing logic. Tool
 * *execution* is delegated to the `$dispatch` closure the caller supplies,
 * which is deliberately not typed to a concrete dispatcher: {@see
 * \Waaseyaa\CLI\Command\Mcp\McpServeCommand} closes over an audited dispatcher
 * built fresh per call (a correlation id is per-request, ADR-022 D-5.C.6, so
 * it cannot be a single constructed instance), while this class stays
 * audit-agnostic and unit-testable with a bare stub.
 *
 * **Scope.** This is a handshake-era (legacy) MCP server — see
 * {@see StdioMcpProtocol} for why, and for the revisions it will negotiate.
 * Only `initialize`, `ping`, `tools/list`, and `tools/call` are implemented —
 * the only capability this transport advertises is `tools`, so there is no
 * `resources/*` or `prompts/*` surface to answer for. Every other method,
 * `server/discover` explicitly included, is `-32601 Method not found`: that
 * is the specified signal a dual-era client probes for before falling back to
 * `initialize`. Batched requests (a JSON array of request objects) are
 * rejected as `-32600 Invalid Request`; this transport has exactly one caller
 * per process and no need for batching.
 *
 * @api
 */
final class StdioMcpServer
{
    private const string JSONRPC_VERSION = '2.0';

    /** Maximum request bytes before the terminating newline. */
    public const int MAX_FRAME_BYTES = 1_048_576;

    /**
     * @param ToolDispatcherInterface $catalogue Consulted ONLY for `tools()` /
     *        `tool()` — an unaudited, name-ordered listing. `initialize`,
     *        `ping`, and `tools/list` mutate nothing, so per ADR-022 D-5.B they
     *        are never reserved in the audit ledger; only `tools/call` is.
     * @param \Closure(string, array<string, mixed>, string): ToolDispatchOutcome $dispatch
     *        Executes ONE `tools/call`. Receives the tool name, its arguments,
     *        and a correlation id this server mints per request — the closure
     *        owns everything audit-shaped (surface, actor, safe arguments).
     * @param resource $in  A readable stream, newline-delimited JSON-RPC requests in.
     * @param resource $out A writable stream, newline-delimited JSON-RPC responses out.
     *        Written to ONLY by {@see writeFrame()} — see the class docblock.
     * @param ?\Closure(string): void $diagnostic Where every non-protocol line
     *        goes. `null` discards diagnostics silently rather than writing
     *        them to `$out` — silence here is always safe; a byte on `$out`
     *        that is not a frame is not.
     * @param ?\Closure(): string $correlationIdFactory Overrides the default
     *        16-hex-digit random id, for deterministic test assertions.
     */
    public function __construct(
        private readonly ToolDispatcherInterface $catalogue,
        private readonly \Closure $dispatch,
        private readonly string $serverName,
        private readonly string $serverVersion,
        $in = STDIN,
        $out = STDOUT,
        private readonly ?\Closure $diagnostic = null,
        private readonly ?\Closure $correlationIdFactory = null,
    ) {
        $this->in = $in;
        $this->out = $out;
    }

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /**
     * Read requests until the input stream reaches EOF, then return `0`.
     *
     * Every failure this server can produce while a session is running —
     * malformed JSON, an unknown method, an exception a tool dispatch let
     * escape — resolves to a JSON-RPC error FRAME on `$out`, never a non-zero
     * return from this method and never a PHP exception. The one place this
     * server distinguishes "clean session end" from "the caller misbehaved" is
     * at construction, one layer up in `McpServeCommand::execute()`, where a
     * bad or absent local-operator attestation refuses before the loop starts.
     */
    public function run(): int
    {
        // The extra two bytes allow a maximum-size payload plus its newline.
        // `fgets()` otherwise grows until newline/EOF and lets one caller
        // exhaust the process before the protocol can return a typed refusal.
        while (($line = fgets($this->in, self::MAX_FRAME_BYTES + 2)) !== false) {
            if (!$this->isCompleteBoundedFrame($line)) {
                $this->discardRemainderOfOversizedFrame($line);
                $this->writeError(
                    null,
                    StdioJsonRpcErrorCode::INVALID_REQUEST,
                    \sprintf('Invalid Request: frame exceeds %d bytes.', self::MAX_FRAME_BYTES),
                );

                continue;
            }

            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') {
                continue;
            }
            $this->handleLine($trimmed);
        }

        return 0;
    }

    private function isCompleteBoundedFrame(string $line): bool
    {
        $hasTerminator = str_ends_with($line, "\n");
        $payloadLength = \strlen(rtrim($line, "\r\n"));

        return $payloadLength <= self::MAX_FRAME_BYTES
            && ($hasTerminator || feof($this->in));
    }

    private function discardRemainderOfOversizedFrame(string $firstChunk): void
    {
        $chunk = $firstChunk;
        while (!str_ends_with($chunk, "\n") && !feof($this->in)) {
            $next = fgets($this->in, 8192);
            if ($next === false) {
                return;
            }
            $chunk = $next;
        }
    }

    private function handleLine(string $line): void
    {
        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->writeError(null, StdioJsonRpcErrorCode::PARSE_ERROR, 'Parse error: ' . $e->getMessage());

            return;
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            // A JSON-RPC request is an object; this transport does not support
            // batched (array) requests — see the class docblock.
            $this->writeError(null, StdioJsonRpcErrorCode::INVALID_REQUEST, 'Invalid Request: expected a JSON-RPC request object.');

            return;
        }

        $hasId = \array_key_exists('id', $decoded);
        if ($hasId && !self::isValidRequestId($decoded['id'])) {
            // Answered with a null id rather than by echoing the offending
            // value: a client that sent `{"id": {"a": 1}}` cannot correlate
            // the reply anyway, and echoing an arbitrary structure back would
            // put an unbounded, unvalidated fragment of the request onto the
            // wire inside a frame the client will parse as a JSON-RPC id.
            $this->writeError(null, StdioJsonRpcErrorCode::INVALID_REQUEST, 'Invalid Request: "id" must be a string or an integer.');

            return;
        }
        $id = $hasId ? $decoded['id'] : null;

        if (($decoded['jsonrpc'] ?? null) !== self::JSONRPC_VERSION) {
            if ($hasId) {
                $this->writeError($id, StdioJsonRpcErrorCode::INVALID_REQUEST, 'Invalid Request: "jsonrpc" must be "2.0".');
            }

            return;
        }

        $method = $decoded['method'] ?? null;
        if (!\is_string($method) || $method === '') {
            if ($hasId) {
                $this->writeError($id, StdioJsonRpcErrorCode::INVALID_REQUEST, 'Invalid Request: "method" must be a non-empty string.');
            }

            return;
        }

        $params = $decoded['params'] ?? [];
        if (!self::isDecodedJsonObject($params)) {
            if ($hasId) {
                $this->writeError($id, StdioJsonRpcErrorCode::INVALID_PARAMS, 'Invalid params: "params" must be an object.');
            }

            return;
        }

        if (!$hasId) {
            // A JSON-RPC notification: no response is ever sent for one,
            // successful or not. This server recognises none by name — there
            // is no server-held state a notification like
            // `notifications/initialized` or `notifications/cancelled` would
            // change here — so every notification is simply, silently a no-op.
            return;
        }

        try {
            $this->routeRequest($id, $method, $params);
        } catch (\Throwable $e) {
            // This is an emergency boundary for a Throwable that escaped the
            // dispatcher itself. Its message can contain credentials, raw
            // arguments, or absolute machine paths, so stderr receives only
            // fixed structural metadata. Ordinary tool failures are sanitized
            // and correlation-bound inside AgentToolDispatcher before they can
            // reach this catch.
            $this->emitDiagnostic(\sprintf('waaseyaa mcp:serve: unhandled %s dispatching "%s".', $e::class, $method));
            $this->writeError($id, StdioJsonRpcErrorCode::INTERNAL_ERROR, 'Internal error.');
        }
    }

    /** @param array<string, mixed> $params */
    private function routeRequest(mixed $id, string $method, array $params): void
    {
        match ($method) {
            'initialize' => $this->handleInitialize($id, $params),
            'ping' => $this->writeResult($id, new \stdClass()),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params),
            default => $this->writeError($id, StdioJsonRpcErrorCode::METHOD_NOT_FOUND, \sprintf('Method not found: "%s".', $method)),
        };
    }

    /** @param array<string, mixed> $params */
    private function handleInitialize(mixed $id, array $params): void
    {
        $requestedVersion = $params['protocolVersion'] ?? null;
        $capabilities = $params['capabilities'] ?? null;
        $clientInfo = $params['clientInfo'] ?? null;

        if (
            !\is_string($requestedVersion)
            || !self::isDecodedJsonObject($capabilities)
            || !self::isDecodedJsonObject($clientInfo)
            || !isset($clientInfo['name'], $clientInfo['version'])
            || !\is_string($clientInfo['name'])
            || !\is_string($clientInfo['version'])
        ) {
            $this->writeError(
                $id,
                StdioJsonRpcErrorCode::INVALID_PARAMS,
                'Invalid initialize params: protocolVersion, capabilities, and clientInfo{name,version} are required.',
            );

            return;
        }

        $this->writeResult($id, [
            'protocolVersion' => StdioMcpProtocol::negotiate($requestedVersion),
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => $this->serverName, 'version' => $this->serverVersion],
        ]);
    }

    private function handleToolsList(mixed $id): void
    {
        $tools = array_map(
            static fn(AgentTool $tool): array => $tool->toMcpDescriptor(),
            $this->catalogue->tools(),
        );

        $this->writeResult($id, ['tools' => $tools]);
    }

    /** @param array<string, mixed> $params */
    private function handleToolsCall(mixed $id, array $params): void
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            $this->writeError($id, StdioJsonRpcErrorCode::INVALID_PARAMS, 'Invalid params: "name" must be a non-empty string.');

            return;
        }

        $arguments = $params['arguments'] ?? [];
        if (!self::isDecodedJsonObject($arguments)) {
            $this->writeError($id, StdioJsonRpcErrorCode::INVALID_PARAMS, 'Invalid params: "arguments" must be an object.');

            return;
        }

        $correlationId = $this->correlationIdFactory !== null ? ($this->correlationIdFactory)() : bin2hex(random_bytes(8));

        $outcome = ($this->dispatch)($name, $arguments, $correlationId);

        $this->writeResult($id, $outcome->envelope);
    }

    /**
     * Is this decoded value a JSON *object*, as every `params`-shaped field on
     * this wire must be?
     *
     * `json_decode($line, true)` is lossy in exactly one way that matters
     * here: it erases the JSON `{}` / `[]` distinction, handing both back as
     * the same PHP `[]`. A bare `\is_array()` check therefore accepts
     * `"params": [1, 2, 3]` — a JSON *array* — as if it were an object, and
     * the request then flows on to a handler that reads `$params['name']`
     * off it and finds nothing, producing a misleading downstream error for
     * what is really a malformed frame.
     *
     * A NON-EMPTY PHP list is unambiguously a JSON array and is rejected. The
     * empty array is genuinely ambiguous — it is what BOTH `{}` and `[]`
     * decode to — and is accepted, because `{}` is the common, correct way a
     * client sends "no arguments" and there is no way to tell the two apart
     * after decoding. Erring towards accepting `[]` costs nothing: every
     * handler treats an empty params object and an absent one identically.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isDecodedJsonObject(mixed $value): bool
    {
        return \is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Is this a JSON-RPC id this server will answer to?
     *
     * JSON-RPC 2.0 allows a String, a Number, or Null; MCP narrows that to
     * "a string or integer" that "MUST NOT be `null`". This server takes the
     * narrower rule, which also disposes of the shapes that would otherwise
     * be echoed straight back into a response frame: an array, an object, a
     * bool, or a float. Fractional ids are rejected rather than coerced —
     * JSON-RPC itself says a Number id SHOULD NOT contain fractional parts,
     * and silently answering `1.0` as `1` would break correlation on any
     * client that compares ids strictly.
     */
    private static function isValidRequestId(mixed $id): bool
    {
        return \is_int($id) || \is_string($id);
    }

    private function writeResult(mixed $id, mixed $result): void
    {
        $this->writeFrame(['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'result' => $result]);
    }

    private function writeError(mixed $id, int $code, string $message): void
    {
        $this->writeFrame(['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * The ONLY method in this class that writes to `$out` — see the class
     * docblock. Every response, success or error, funnels through here as one
     * complete JSON value plus exactly one trailing `"\n"`.
     *
     * @param array<string, mixed> $frame
     */
    private function writeFrame(array $frame): void
    {
        $encoded = json_encode($frame, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        fwrite($this->out, $encoded . "\n");
        fflush($this->out);
    }

    private function emitDiagnostic(string $line): void
    {
        if ($this->diagnostic !== null) {
            ($this->diagnostic)($line);
        }
    }
}
