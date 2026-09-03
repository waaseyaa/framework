<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Mcp\Stdio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatchOutcome;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatcherInterface;
use Waaseyaa\CLI\Mcp\Stdio\StdioJsonRpcErrorCode;
use Waaseyaa\CLI\Mcp\Stdio\StdioMcpProtocol;
use Waaseyaa\CLI\Mcp\Stdio\StdioMcpServer;
use Waaseyaa\Foundation\Audit\AuditStage;

/**
 * Protocol-level conformance for {@see StdioMcpServer} in isolation: a stub
 * catalogue and a scriptable dispatch closure, real `php://memory` streams,
 * no ai-agent / audit / kernel machinery. The real subprocess round trip
 * against the wired command lives in
 * `tests/Integration/Mcp/StdioMcpConformanceTest.php`.
 */
#[CoversClass(StdioMcpServer::class)]
final class StdioMcpServerTest extends TestCase
{
    #[Test]
    public function malformed_json_yields_a_parse_error_with_a_null_id(): void
    {
        $frames = $this->roundTrip("not json at all\n");

        self::assertCount(1, $frames);
        self::assertSame(StdioJsonRpcErrorCode::PARSE_ERROR, $frames[0]['error']['code']);
        self::assertNull($frames[0]['id']);
    }

    #[Test]
    public function a_batch_array_is_rejected_as_invalid_request(): void
    {
        $frames = $this->roundTrip("[1,2,3]\n");

        self::assertCount(1, $frames);
        self::assertSame(StdioJsonRpcErrorCode::INVALID_REQUEST, $frames[0]['error']['code']);
    }

    #[Test]
    public function a_notification_produces_no_frame_at_all_known_or_unknown(): void
    {
        $lines = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => new \stdClass()], JSON_THROW_ON_ERROR)
            . "\n"
            . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/some_unknown_thing'], JSON_THROW_ON_ERROR)
            . "\n";

        $frames = $this->roundTrip($lines);

        self::assertSame([], $frames, 'A notification (no "id") must never receive a response line, known method or not.');
    }

    #[Test]
    public function wrong_jsonrpc_version_with_an_id_is_invalid_request(): void
    {
        $frames = $this->roundTrip(json_encode(['jsonrpc' => '1.0', 'id' => 7, 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n");

        self::assertSame(7, $frames[0]['id']);
        self::assertSame(StdioJsonRpcErrorCode::INVALID_REQUEST, $frames[0]['error']['code']);
    }

    #[Test]
    public function an_unknown_method_is_method_not_found(): void
    {
        $frames = $this->roundTrip(json_encode(['jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'resources/list'], JSON_THROW_ON_ERROR) . "\n");

        self::assertSame('abc', $frames[0]['id']);
        self::assertSame(StdioJsonRpcErrorCode::METHOD_NOT_FOUND, $frames[0]['error']['code']);
    }

    #[Test]
    public function ping_returns_an_empty_result(): void
    {
        $frames = $this->roundTrip(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n");

        self::assertSame(1, $frames[0]['id']);
        self::assertArrayHasKey('result', $frames[0]);
        self::assertSame([], $frames[0]['result']);
    }

    #[Test]
    public function initialize_negotiates_the_requested_supported_version_and_advertises_tools_only(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'probe', 'version' => '9.9.9'],
            ],
        ];
        $frames = $this->roundTrip(json_encode($request, JSON_THROW_ON_ERROR) . "\n");

        $result = $frames[0]['result'];
        self::assertSame('2025-06-18', $result['protocolVersion'], 'A supported requested version is echoed back verbatim.');
        self::assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        self::assertSame('waaseyaa', $result['serverInfo']['name']);
        self::assertSame('1.2.3', $result['serverInfo']['version']);
    }

    #[Test]
    public function initialize_falls_back_to_the_latest_handshake_revision_for_an_unsupported_version(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '1999-01-01', 'capabilities' => [], 'clientInfo' => ['name' => 'p', 'version' => '1']],
        ];
        $frames = $this->roundTrip(json_encode($request, JSON_THROW_ON_ERROR) . "\n");

        self::assertSame(StdioMcpProtocol::LATEST_HANDSHAKE_REVISION, $frames[0]['result']['protocolVersion']);
    }

    #[Test]
    public function initialize_never_advertises_a_modern_era_revision_it_does_not_implement(): void
    {
        // A modern client asking for 2026-07-28 gets the newest handshake-era
        // revision back, not its own request echoed: this server has no
        // `server/discover` and reads no per-request `_meta`, so claiming the
        // modern era would be a lie the client would immediately act on.
        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2026-07-28', 'capabilities' => [], 'clientInfo' => ['name' => 'p', 'version' => '1']],
        ];
        $frames = $this->roundTrip(json_encode($request, JSON_THROW_ON_ERROR) . "\n");

        self::assertSame(StdioMcpProtocol::LATEST_HANDSHAKE_REVISION, $frames[0]['result']['protocolVersion']);
        self::assertNotSame('2026-07-28', $frames[0]['result']['protocolVersion']);
    }

    #[Test]
    public function server_discover_is_method_not_found_which_is_how_a_dual_era_client_detects_a_legacy_server(): void
    {
        // MCP 2026-07-28 "stdio" §Backward Compatibility: a dual-era client
        // probes `server/discover` first and falls back to `initialize` on
        // "any other error" — anything that is NOT a recognized modern error.
        // -32601 is that signal. Answering the probe, or returning -32022
        // UnsupportedProtocolVersionError, would strand the client in the
        // modern era against a server that has no modern surface.
        $frames = $this->roundTrip(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => [
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
            ]], JSON_THROW_ON_ERROR) . "\n",
        );

        self::assertSame(StdioJsonRpcErrorCode::METHOD_NOT_FOUND, $frames[0]['error']['code']);
        self::assertNotSame(-32022, $frames[0]['error']['code']);
    }

    #[Test]
    public function initialize_rejects_missing_required_fields_as_invalid_params(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]];
        $frames = $this->roundTrip(json_encode($request, JSON_THROW_ON_ERROR) . "\n");

        self::assertSame(StdioJsonRpcErrorCode::INVALID_PARAMS, $frames[0]['error']['code']);
    }

    #[Test]
    public function tools_list_maps_the_catalogue_through_its_wire_descriptor_unmodified(): void
    {
        $tool = $this->fixtureTool('demo_tool', 'demo.capability');
        $frames = $this->roundTrip(
            json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], JSON_THROW_ON_ERROR) . "\n",
            catalogue: [$tool],
        );

        self::assertSame(5, $frames[0]['id']);
        self::assertSame([$tool->toMcpDescriptor()], $frames[0]['result']['tools']);
    }

    #[Test]
    public function tools_call_rejects_a_missing_name_without_invoking_dispatch(): void
    {
        $invoked = false;
        $frames = $this->roundTrip(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['arguments' => []]], JSON_THROW_ON_ERROR) . "\n",
            dispatch: function () use (&$invoked): never {
                $invoked = true;
                self::fail('dispatch must not be called for an invalid request.');
            },
        );

        self::assertFalse($invoked);
        self::assertSame(StdioJsonRpcErrorCode::INVALID_PARAMS, $frames[0]['error']['code']);
    }

    #[Test]
    public function tools_call_rejects_non_object_arguments(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'x', 'arguments' => 'not-an-object']];
        $frames = $this->roundTrip(json_encode($request, JSON_THROW_ON_ERROR) . "\n");

        self::assertSame(StdioJsonRpcErrorCode::INVALID_PARAMS, $frames[0]['error']['code']);
    }

    // ------------------------------------------- decoded-JSON shape validation
    //
    // `json_decode($line, true)` collapses JSON `{}` and `[]` onto the same
    // PHP `[]`, so a bare is_array() check cannot tell an object from an
    // array. These pin the one distinction that survives decoding — a
    // NON-EMPTY PHP list is unambiguously a JSON array — and pin that the
    // ambiguous empty case keeps working, because `{}` is how a conformant
    // client says "no arguments".

    #[Test]
    public function a_non_empty_json_list_as_params_is_invalid_params_and_the_session_survives_it(): void
    {
        $frames = $this->roundTrip(
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":[1,2,3]}' . "\n"
            . '{"jsonrpc":"2.0","id":2,"method":"ping"}' . "\n",
        );

        self::assertCount(2, $frames);
        self::assertSame(1, $frames[0]['id']);
        self::assertSame(StdioJsonRpcErrorCode::INVALID_PARAMS, $frames[0]['error']['code']);
        self::assertSame(2, $frames[1]['id'], 'A malformed frame must not end the session.');
        self::assertArrayHasKey('result', $frames[1]);
    }

    #[Test]
    public function a_decoded_empty_json_object_is_still_accepted_as_params(): void
    {
        $frames = $this->roundTrip('{"jsonrpc":"2.0","id":1,"method":"ping","params":{}}' . "\n");

        self::assertArrayHasKey('result', $frames[0], '`"params": {}` is the conformant way to send no parameters.');
    }

    #[Test]
    public function tools_call_rejects_a_non_empty_json_list_as_arguments_without_invoking_dispatch(): void
    {
        $invoked = false;
        $frames = $this->roundTrip(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":["positional"]}}' . "\n",
            dispatch: function () use (&$invoked): never {
                $invoked = true;
                self::fail('dispatch must never see a JSON array where an arguments object belongs.');
            },
        );

        self::assertFalse($invoked);
        self::assertSame(StdioJsonRpcErrorCode::INVALID_PARAMS, $frames[0]['error']['code']);
    }

    #[Test]
    public function tools_call_accepts_a_decoded_empty_json_object_as_arguments(): void
    {
        $seen = null;
        $this->roundTrip(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"echo","arguments":{}}}' . "\n",
            dispatch: function (string $name, array $arguments) use (&$seen): ToolDispatchOutcome {
                $seen = $arguments;

                return new ToolDispatchOutcome(['content' => []], AuditStage::ExecutionSucceeded);
            },
        );

        self::assertSame([], $seen);
    }

    #[Test]
    public function initialize_rejects_a_non_empty_json_list_where_an_object_belongs(): void
    {
        foreach ([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":["tools"],"clientInfo":{"name":"p","version":"1"}}}',
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":["p","1"]}}',
        ] as $line) {
            $frames = $this->roundTrip($line . "\n");

            self::assertSame(StdioJsonRpcErrorCode::INVALID_PARAMS, $frames[0]['error']['code'], $line);
        }
    }

    // ------------------------------------------------------- request id shapes

    /**
     * JSON-RPC 2.0 allows String, Number, or Null; MCP narrows that to a
     * string or an integer, never null. Anything else is rejected BEFORE it
     * can be echoed back into a response frame.
     */
    #[Test]
    public function a_non_scalar_or_disallowed_id_is_invalid_request_answered_with_a_null_id(): void
    {
        foreach ([
            'object' => '{"jsonrpc":"2.0","id":{"nested":"object"},"method":"ping"}',
            'array' => '{"jsonrpc":"2.0","id":[1,2],"method":"ping"}',
            'null' => '{"jsonrpc":"2.0","id":null,"method":"ping"}',
            'bool' => '{"jsonrpc":"2.0","id":true,"method":"ping"}',
            'float' => '{"jsonrpc":"2.0","id":1.5,"method":"ping"}',
        ] as $label => $line) {
            $frames = $this->roundTrip($line . "\n");

            self::assertCount(1, $frames, $label);
            self::assertSame(StdioJsonRpcErrorCode::INVALID_REQUEST, $frames[0]['error']['code'], $label);
            self::assertNull($frames[0]['id'], $label . ': an unusable id must never be echoed back onto the wire.');
        }
    }

    #[Test]
    public function a_disallowed_id_is_rejected_before_any_other_field_is_judged(): void
    {
        // Same frame, two defects: an object id AND a wrong jsonrpc version.
        // The id is rejected first and deterministically, because the
        // alternative — reporting the version error — would have to name the
        // offending id in the response frame to be correlatable at all.
        $frames = $this->roundTrip('{"jsonrpc":"1.0","id":{"a":1},"method":"nope"}' . "\n");

        self::assertCount(1, $frames);
        self::assertNull($frames[0]['id']);
        self::assertStringContainsString('"id"', $frames[0]['error']['message']);
    }

    #[Test]
    public function string_and_integer_ids_are_answered_and_correlated_verbatim(): void
    {
        $frames = $this->roundTrip(
            '{"jsonrpc":"2.0","id":0,"method":"ping"}' . "\n"
            . '{"jsonrpc":"2.0","id":-7,"method":"ping"}' . "\n"
            . '{"jsonrpc":"2.0","id":"req-abc","method":"ping"}' . "\n",
        );

        self::assertSame([0, -7, 'req-abc'], array_column($frames, 'id'));
    }

    #[Test]
    public function a_rejected_id_does_not_end_the_session(): void
    {
        $frames = $this->roundTrip(
            '{"jsonrpc":"2.0","id":[1],"method":"ping"}' . "\n"
            . '{"jsonrpc":"2.0","id":9,"method":"ping"}' . "\n",
        );

        self::assertCount(2, $frames);
        self::assertNull($frames[0]['id']);
        self::assertSame(9, $frames[1]['id']);
        self::assertArrayHasKey('result', $frames[1]);
    }

    #[Test]
    public function tools_call_forwards_name_arguments_and_a_freshly_minted_correlation_id_to_the_dispatch_closure(): void
    {
        $seen = [];
        $request = ['jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi']]];

        $frames = $this->roundTrip(
            json_encode($request, JSON_THROW_ON_ERROR) . "\n",
            dispatch: function (string $name, array $arguments, string $correlationId) use (&$seen): ToolDispatchOutcome {
                $seen = [$name, $arguments, $correlationId];

                return new ToolDispatchOutcome(['content' => [['type' => 'text', 'text' => 'ok']]], AuditStage::ExecutionSucceeded);
            },
            correlationIdFactory: static fn(): string => 'fixed-correlation-id',
        );

        self::assertSame(['echo', ['msg' => 'hi'], 'fixed-correlation-id'], $seen);
        self::assertSame(42, $frames[0]['id']);
        self::assertSame(['content' => [['type' => 'text', 'text' => 'ok']]], $frames[0]['result']);
    }

    #[Test]
    public function a_tool_level_error_is_still_a_jsonrpc_success_carrying_iserror_true(): void
    {
        // MCP tool errors ("the tool ran and reported a failure") are NOT
        // JSON-RPC protocol errors; they are ordinary `result` payloads with
        // `isError: true`, exactly like the HTTP tiers' AgentToolDispatcher.
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'boom']];
        $frames = $this->roundTrip(
            json_encode($request, JSON_THROW_ON_ERROR) . "\n",
            dispatch: static fn(): ToolDispatchOutcome => new ToolDispatchOutcome(
                ['content' => [['type' => 'text', 'text' => 'nope']], 'isError' => true],
                AuditStage::ExecutionFailed,
            ),
        );

        self::assertArrayNotHasKey('error', $frames[0]);
        self::assertTrue($frames[0]['result']['isError']);
    }

    #[Test]
    public function an_exception_escaping_the_dispatch_closure_becomes_an_internal_error_frame_and_never_reaches_stdout_raw(): void
    {
        $diagnostics = [];
        $frames = $this->roundTrip(
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'boom']], JSON_THROW_ON_ERROR) . "\n",
            dispatch: static function (): never {
                throw new \RuntimeException('credentials=super-secret should never reach stdout');
            },
            diagnostic: function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            },
        );

        self::assertSame(StdioJsonRpcErrorCode::INTERNAL_ERROR, $frames[0]['error']['code']);
        self::assertSame('Internal error.', $frames[0]['error']['message'], 'The raw exception message must not leak onto the wire.');
        self::assertNotSame([], $diagnostics, 'The exception detail must still be reported — to the diagnostic sink, not stdout.');
        self::assertStringContainsString('super-secret', implode("\n", $diagnostics));
    }

    #[Test]
    public function run_returns_zero_at_end_of_input_regardless_of_what_happened_mid_session(): void
    {
        $in = fopen('php://memory', 'r+');
        fwrite($in, "not json\n");
        rewind($in);
        $out = fopen('php://memory', 'r+');

        $server = new StdioMcpServer(
            catalogue: $this->stubCatalogue([]),
            dispatch: static fn(): never => self::fail('unused'),
            serverName: 'waaseyaa',
            serverVersion: '0.0.0',
            in: $in,
            out: $out,
        );

        self::assertSame(0, $server->run());
    }

    #[Test]
    public function blank_lines_between_requests_are_skipped(): void
    {
        $lines = "\n\n" . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n\n";
        $frames = $this->roundTrip($lines);

        self::assertCount(1, $frames);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<AgentTool> $catalogue
     * @param ?\Closure(string, array<string, mixed>, string): ToolDispatchOutcome $dispatch
     * @param ?\Closure(string): void $diagnostic
     * @param ?\Closure(): string $correlationIdFactory
     *
     * @return list<array<string, mixed>>
     */
    private function roundTrip(
        string $input,
        array $catalogue = [],
        ?\Closure $dispatch = null,
        ?\Closure $diagnostic = null,
        ?\Closure $correlationIdFactory = null,
    ): array {
        $in = fopen('php://memory', 'r+');
        fwrite($in, $input);
        rewind($in);
        $out = fopen('php://memory', 'r+');

        $server = new StdioMcpServer(
            catalogue: $this->stubCatalogue($catalogue),
            dispatch: $dispatch ?? static fn(): never => self::fail('dispatch was not expected to be called in this test.'),
            serverName: 'waaseyaa',
            serverVersion: '1.2.3',
            in: $in,
            out: $out,
            diagnostic: $diagnostic,
            correlationIdFactory: $correlationIdFactory,
        );

        self::assertSame(0, $server->run());

        rewind($out);
        $raw = trim((string) stream_get_contents($out));

        // Purity assertion baked into the harness itself: EVERY non-empty line
        // on $out must be valid JSON, or the test that produced it fails here
        // rather than downstream — a stray byte on the wire is a bug in
        // whatever test allowed it, not something later assertions should have
        // to notice on their own.
        $frames = [];
        foreach (($raw === '' ? [] : explode("\n", $raw)) as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, 'Every line on stdout must be a JSON object: ' . $line);
            $frames[] = $decoded;
        }

        return $frames;
    }

    /** @param list<AgentTool> $tools */
    private function stubCatalogue(array $tools): ToolDispatcherInterface
    {
        return new class ($tools) implements ToolDispatcherInterface {
            /** @param list<AgentTool> $tools */
            public function __construct(private readonly array $tools) {}

            public function tools(): array
            {
                return $this->tools;
            }

            public function tool(string $name): ?AgentTool
            {
                foreach ($this->tools as $tool) {
                    if ($tool->name === $name) {
                        return $tool;
                    }
                }

                return null;
            }

            public function dispatch(string $toolName, array $arguments): ToolDispatchOutcome
            {
                throw new \LogicException('StdioMcpServer must never call dispatch() on the catalogue — only tools()/tool().');
            }
        };
    }

    private function fixtureTool(string $name, string $capability): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }

            public function dryRun(array $arguments, \Waaseyaa\Access\AuthorizationPrincipalInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            }

            public function description(): string
            {
                return 'A fixture tool.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: $capability,
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            impl: $impl,
        );
    }
}
