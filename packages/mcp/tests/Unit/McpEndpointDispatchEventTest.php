<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Event\McpDispatchEvent;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpProtocol;
use Waaseyaa\Mcp\McpResponse;
use Waaseyaa\Mcp\Tests\Support\RecordingLogger;

/**
 * FR-007 / contract clauses 16-19, as amended by #2177 F4: the
 * `waaseyaa.mcp.dispatch` event fires once per PIPELINE STAGE — every
 * authenticated, parsed, accepted request emits `request_accepted` and then
 * exactly one honest terminal stage — carries the bearer account, never alters
 * the RPC response, and its name literal is pinned to the audit listener's
 * subscription constant (clause 18).
 */
#[CoversClass(McpEndpoint::class)]
#[CoversClass(McpDispatchEvent::class)]
final class McpEndpointDispatchEventTest extends TestCase
{
    private McpAuthInterface $auth;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->auth = $this->createStub(McpAuthInterface::class);
        $this->account = $this->createStub(AuthorizationPrincipalInterface::class);
        $this->account->method('id')->willReturn(7);
        $this->account->method('hasPermission')->willReturn(true);
    }

    /**
     * #2177 F4 acceptance blocker: EVERY authenticated, parsed, accepted
     * request must end in a terminal stage — `request_accepted` alone is a
     * record of an admission, not of what happened. A successful protocol
     * method's honest terminal stage is `execution_succeeded`.
     */
    #[Test]
    public function authenticatedToolsListFiresAnAcceptedThenTerminalSuccessPair(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        self::assertSame(200, $response->statusCode);
        self::assertCount(2, $spy->dispatched, 'Accepted request => accepted + terminal pair');

        [$accepted, $name] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $accepted);
        self::assertSame(McpDispatchEvent::NAME, $name, 'Dispatched under the string event name');
        self::assertSame('tools/list', $accepted->method);
        self::assertSame('request_accepted', $accepted->stage);
        self::assertSame([], $accepted->params);
        self::assertSame(7, $accepted->accountUid, 'accountUid is the bearer-auth account id');

        [$terminal] = $spy->dispatched[1];
        self::assertInstanceOf(McpDispatchEvent::class, $terminal);
        self::assertSame('execution_succeeded', $terminal->stage);
        self::assertNull($terminal->toolName, 'A protocol method names no tool');
        self::assertSame($accepted->correlationId, $terminal->correlationId);
    }

    #[Test]
    public function initializeAndPingFireAcceptedThenTerminalSuccessPairs(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        foreach (['initialize', 'ping'] as $method) {
            $spy = new RecordingSymfonyDispatcher();
            $endpoint = $this->makeEndpoint(dispatcher: $spy);
            $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];
            if ($method === 'initialize') {
                $request['params'] = [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                ];
            }
            $body = \json_encode($request, \JSON_THROW_ON_ERROR);

            $response = $this->dispatch($endpoint, $body, 'Bearer valid');

            self::assertSame(200, $response->statusCode, $method);
            $stages = array_map(
                static fn(array $pair): ?string => $pair[0] instanceof McpDispatchEvent ? $pair[0]->stage : null,
                $spy->dispatched,
            );
            self::assertSame(['request_accepted', 'execution_succeeded'], $stages, $method);
        }
    }

    #[Test]
    public function invalidInitializeParamsEndInARefusalRatherThanExecutionSuccess(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch(
            $endpoint,
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            'Bearer valid',
        );

        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(-32602, $decoded['error']['code']);
        $events = array_column($spy->dispatched, 0);
        self::assertSame(
            ['request_accepted', 'invalid_params_refused'],
            array_map(static fn(McpDispatchEvent $event): ?string => $event->stage, $events),
        );
        self::assertSame($events[0]->correlationId, $events[1]->correlationId);
        self::assertSame(
            ['reason' => 'protocol_error_returned', 'error_code' => -32602],
            $events[1]->metadata,
        );
    }

    #[Test]
    public function malformedInternalProtocolResponseCannotBeAuditedAsSuccess(): void
    {
        $spy = new RecordingSymfonyDispatcher();
        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $execute = new \ReflectionMethod($endpoint, 'protocolExecute');

        $response = $execute->invoke(
            $endpoint,
            static fn(): McpResponse => new McpResponse('not-json sk-internal-response-secret'),
            1,
            'correlation-malformed',
            7,
            'ping',
        );

        self::assertInstanceOf(McpResponse::class, $response);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(-32603, $decoded['error']['code']);
        self::assertSame('correlation-malformed', $decoded['error']['data']['correlation_id']);
        self::assertStringNotContainsString('sk-internal-response-secret', $response->body);
        self::assertCount(1, $spy->dispatched);
        [$terminal] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $terminal);
        self::assertSame('execution_failed', $terminal->stage);
        self::assertSame(
            ['reason' => 'protocol_response_malformed'],
            $terminal->metadata,
        );
    }

    #[Test]
    public function rateLimiterOutageEmitsOneSanitizedTerminalStage(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();
        $secret = 'limiter-dsn-password=sk-stage-secret';
        $broken = new class ($secret) implements AtomicRateLimiterInterface {
            public function __construct(private readonly string $secret) {}

            public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                throw new \RuntimeException($this->secret);
            }

            public function hit(string $key, int $decaySeconds): void {}

            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                return false;
            }

            public function attempts(string $key): int
            {
                return 0;
            }

            public function remaining(string $key, int $maxAttempts): int
            {
                return $maxAttempts;
            }

            public function clear(string $key): void {}
        };

        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry([]),
            dispatcher: $spy,
            rateLimiter: $broken,
            rateLimitMaxRequests: 1,
            rateLimitWindowSeconds: 60,
            rateLimitTier: 'write',
        );
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"ping"}', 'Bearer valid');

        self::assertSame(503, $response->statusCode);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(-32030, $decoded['error']['code']);
        self::assertCount(1, $spy->dispatched, 'The request was refused before acceptance.');
        [$event] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        self::assertSame('rate_limiter_unavailable', $event->stage);
        self::assertSame('unknown', $event->method);
        self::assertSame('rate_limit', $event->toolName);
        self::assertSame(7, $event->accountUid);
        self::assertSame($decoded['error']['data']['correlation_id'], $event->correlationId);
        self::assertSame(['reason' => 'unavailable'], $event->metadata);
        self::assertStringNotContainsString('sk-stage-secret', $response->body);
        self::assertStringNotContainsString(
            'sk-stage-secret',
            \json_encode($event, \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function dispatchEventPreservesOpaqueStringAccountId(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn('acct-anishinaabe-7');
        $account->method('hasPermission')->willReturn(true);
        $this->auth->method('authenticate')->willReturn($account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        self::assertCount(2, $spy->dispatched, 'accepted + terminal pair');
        [$event] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        $expectedStableUid = (int) hexdec(substr(hash('sha256', 'acct-anishinaabe-7'), 0, 15));
        self::assertSame($expectedStableUid, $event->accountUid);
        self::assertNotSame(0, $event->accountUid, 'Opaque ids must never collide with the anonymous sentinel.');
    }

    /**
     * #2177 F4 changed this contract. `tools/call` now fires a PAIR — the
     * request being accepted, then the stage that states what actually happened
     * — and `params` is no longer populated: `safeArguments` carries the tool's
     * own redacted arguments instead, which is both safer and actually usable.
     */
    #[Test]
    public function toolsCallFiresAnAcceptedThenTerminalStagePair(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy, tools: [$this->makeEchoTool('read_node')]);
        $this->dispatch($endpoint, \json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'read_node', 'arguments' => ['id' => 42]],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid');

        self::assertCount(2, $spy->dispatched);

        [$accepted] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $accepted);
        self::assertSame('tools/call', $accepted->method);
        self::assertSame('request_accepted', $accepted->stage);
        self::assertSame(7, $accepted->accountUid);

        [$terminal] = $spy->dispatched[1];
        self::assertInstanceOf(McpDispatchEvent::class, $terminal);
        self::assertSame('execution_succeeded', $terminal->stage);
        self::assertSame('read_node', $terminal->toolName);

        // Both halves of the pair correlate.
        self::assertNotSame('', $accepted->correlationId);
        self::assertSame($accepted->correlationId, $terminal->correlationId);

        // Raw params are deliberately no longer carried.
        self::assertSame([], $terminal->params);
    }

    /**
     * An unknown method is an accepted-then-refused request, so it must not be
     * left as a bare `request_accepted`. Its honest terminal stage is
     * `method_lookup_refused` — NOT `tool_lookup_refused`, which means "no tool
     * of that name on this tier".
     */
    #[Test]
    public function unknownMethodFiresAnAcceptedThenMethodLookupRefusedPair(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"resources/list"}', 'Bearer valid');

        self::assertSame(-32601, \json_decode($response->body, true)['error']['code']);
        self::assertCount(2, $spy->dispatched);

        [$accepted] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $accepted);
        self::assertSame('resources/list', $accepted->method);
        self::assertSame('request_accepted', $accepted->stage);

        [$terminal] = $spy->dispatched[1];
        self::assertInstanceOf(McpDispatchEvent::class, $terminal);
        self::assertSame('method_lookup_refused', $terminal->stage);
        self::assertSame($accepted->correlationId, $terminal->correlationId);
    }

    #[Test]
    public function nonObjectParamsFireAnAcceptedThenInvalidParamsRefusedPair(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch(
            $endpoint,
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":"raw-string-payload"}',
            'Bearer valid',
        );

        self::assertSame(-32602, \json_decode($response->body, true)['error']['code']);

        $stages = array_map(
            static fn(array $pair): ?string => $pair[0] instanceof McpDispatchEvent ? $pair[0]->stage : null,
            $spy->dispatched,
        );
        self::assertSame(['request_accepted', 'invalid_params_refused'], $stages);

        // The malformed params value is raw caller input and must not ride out.
        self::assertStringNotContainsString(
            'raw-string-payload',
            \json_encode(array_column($spy->dispatched, 0), \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function modern_header_refusal_closes_the_accepted_audit_pair_without_recording_header_values(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();
        $body = \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [
                    McpProtocol::VERSION_META_KEY => McpProtocol::CURRENT,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $response = $this->dispatch($this->makeEndpoint(dispatcher: $spy), $body, 'Bearer valid', [
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => 'sk-raw-header-value',
        ]);

        self::assertSame(-32020, \json_decode($response->body, true)['error']['code']);
        $stages = \array_map(
            static fn(array $pair): ?string => $pair[0] instanceof McpDispatchEvent ? $pair[0]->stage : null,
            $spy->dispatched,
        );
        self::assertSame(['request_accepted', 'invalid_params_refused'], $stages);
        self::assertStringNotContainsString(
            'sk-raw-header-value',
            \json_encode(\array_column($spy->dispatched, 0), \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The three early malformed `tools/call` envelope shapes — missing `name`,
     * non-string `name`, non-object `arguments` — are refusals of accepted
     * requests and must each end in a terminal stage, without leaking the raw
     * malformed values.
     */
    #[Test]
    public function malformedToolsCallShapesFireTerminalInvalidParamsRefusals(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $shapes = [
            'missing name' => ['arguments' => []],
            'non-string name' => ['name' => ['sk-raw-name-secret']],
            'non-object arguments' => ['name' => 'read_node', 'arguments' => ['sk-raw-arg-secret', 'b']],
        ];

        foreach ($shapes as $label => $params) {
            $spy = new RecordingSymfonyDispatcher();
            $endpoint = $this->makeEndpoint(dispatcher: $spy, tools: [$this->makeEchoTool('read_node')]);
            $body = \json_encode(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params],
                \JSON_THROW_ON_ERROR,
            );

            $response = $this->dispatch($endpoint, $body, 'Bearer valid');

            self::assertSame(-32602, \json_decode($response->body, true)['error']['code'], $label);
            $stages = array_map(
                static fn(array $pair): ?string => $pair[0] instanceof McpDispatchEvent ? $pair[0]->stage : null,
                $spy->dispatched,
            );
            self::assertSame(['request_accepted', 'invalid_params_refused'], $stages, $label);

            $bytes = \json_encode(array_column($spy->dispatched, 0), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('sk-raw-name-secret', $bytes, $label);
            self::assertStringNotContainsString('sk-raw-arg-secret', $bytes, $label);
        }
    }

    /**
     * JSON-RPC 2.0 requires `method` to be a string. A non-string method cannot
     * be honestly named in any audit record, so it is refused as an Invalid
     * Request BEFORE acceptance — no `request_accepted` to leave unpaired.
     */
    #[Test]
    public function aNonStringMethodIsRefusedAsInvalidRequestBeforeAcceptance(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":{"a":1}}', 'Bearer valid');

        self::assertSame(-32600, \json_decode($response->body, true)['error']['code']);
        self::assertCount(0, $spy->dispatched, 'Nothing was accepted, so nothing is recorded');
    }

    /**
     * #2177 acceptance blocker: the `-32002` audit-unavailable refusal must
     * hand the caller a correlation id (operator support has nothing to search
     * for otherwise) and must itself be a terminal projection — the one path
     * that cannot write a durable record still gets the best-effort one.
     */
    #[Test]
    public function anAuditUnavailableRefusalCarriesTheCorrelationIdAndATerminalProjection(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $downLedger = new class implements \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface {
            public function reserve(
                \Waaseyaa\Foundation\Audit\StrictAuditReservation $reservation,
            ): \Waaseyaa\Foundation\Audit\StrictAuditReceipt {
                throw new \Waaseyaa\Foundation\Audit\StrictAuditLedgerException('ledger down');
            }

            public function finalize(
                \Waaseyaa\Foundation\Audit\StrictAuditReceipt $receipt,
                \Waaseyaa\Foundation\Audit\AuditStage $stage,
                array $metadata = [],
            ): void {
                throw new \Waaseyaa\Foundation\Audit\StrictAuditLedgerException('ledger down');
            }

            public function record(
                \Waaseyaa\Foundation\Audit\StrictAuditReservation $reservation,
                \Waaseyaa\Foundation\Audit\AuditStage $stage,
            ): void {
                throw new \Waaseyaa\Foundation\Audit\StrictAuditLedgerException('ledger down');
            }
        };

        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry([$this->makeEchoTool('read_node')]),
            dispatcher: $spy,
            auditLedger: $downLedger,
            durableAudit: true,
        );
        $response = $this->dispatch(
            $endpoint,
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"read_node","arguments":{}}}',
            'Bearer valid',
        );

        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(-32002, $decoded['error']['code']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{16}$/',
            (string) ($decoded['error']['data']['correlation_id'] ?? ''),
            'The refusal must give the caller a correlation id to hand to an operator.',
        );

        $stages = array_map(
            static fn(array $pair): ?string => $pair[0] instanceof McpDispatchEvent ? $pair[0]->stage : null,
            $spy->dispatched,
        );
        self::assertSame(['request_accepted', 'audit_unavailable_refused'], $stages);

        [$terminal] = $spy->dispatched[1];
        self::assertInstanceOf(McpDispatchEvent::class, $terminal);
        self::assertSame($decoded['error']['data']['correlation_id'], $terminal->correlationId);
    }

    /**
     * #2177 F4 last acceptance gap: the protocol success stage was emitted for
     * a PRECOMPUTED response, so a protocol handler that threw — here
     * `tools/list` over a registry whose `all()` explodes — escaped AFTER
     * `request_accepted` with no terminal stage, violating the exactly-one-
     * terminal invariant and surfacing as an uncontrolled HTTP failure carrying
     * the exception. The honest closure is a `request_accepted` /
     * `execution_failed` pair, a sanitized JSON-RPC internal error whose
     * `correlation_id` matches the pair, and a log line holding safe metadata
     * only — never the exception message.
     */
    #[Test]
    public function aThrowingProtocolHandlerFiresExecutionFailedAndReturnsASanitizedError(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();
        $logger = new RecordingLogger();

        // The shape of a real registry failure: an operator-facing message
        // carrying a DSN with an embedded credential.
        $secret = 'pgsql://ledger:sk-live-secret-9f7@10.0.0.7/audit';
        $explodingRegistry = new class ($secret) implements AgentToolRegistryInterface {
            public function __construct(private readonly string $secret) {}

            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return false;
            }

            public function all(): iterable
            {
                throw new \RuntimeException($this->secret);
            }
        };

        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $explodingRegistry,
            dispatcher: $spy,
            logger: $logger,
        );

        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        // Exactly one terminal stage — the accepted request is not left as a
        // bare admission, and no double-terminal either.
        $stages = array_map(
            static fn(array $pair): ?string => $pair[0] instanceof McpDispatchEvent ? $pair[0]->stage : null,
            $spy->dispatched,
        );
        self::assertSame(['request_accepted', 'execution_failed'], $stages);

        // Controlled, sanitized JSON-RPC internal error joined to the pair.
        self::assertSame(200, $response->statusCode);
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(-32603, $decoded['error']['code']);
        [$accepted] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $accepted);
        [$terminal] = $spy->dispatched[1];
        self::assertInstanceOf(McpDispatchEvent::class, $terminal);
        self::assertSame($accepted->correlationId, $terminal->correlationId);
        self::assertSame(
            $accepted->correlationId,
            $decoded['error']['data']['correlation_id'] ?? null,
            'The sanitized refusal must hand the caller the SAME correlation id the audit pair carries.',
        );

        // The secret leaves in no direction: response, events, or log.
        self::assertStringNotContainsString('sk-live-secret-9f7', $response->body);
        self::assertStringNotContainsString(
            'sk-live-secret-9f7',
            \json_encode(array_column($spy->dispatched, 0), \JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('sk-live-secret-9f7', $logger->allContextAsString());
        self::assertStringNotContainsString(
            'sk-live-secret-9f7',
            \json_encode(array_column($logger->records, 1), \JSON_THROW_ON_ERROR),
        );

        // The log carries safe metadata only: exception class, method,
        // correlation id — never message, trace, or params.
        self::assertCount(1, $logger->records);
        [, $logMessage, $context] = $logger->records[0];
        self::assertSame('mcp.protocol_execution_failed', $logMessage);
        self::assertSame(\RuntimeException::class, $context['exception'] ?? null);
        self::assertSame('tools/list', $context['method'] ?? null);
        self::assertSame($accepted->correlationId, $context['correlation_id'] ?? null);
        self::assertArrayNotHasKey('message', $context);
        self::assertArrayNotHasKey('trace', $context);
        self::assertArrayNotHasKey('params', $context);
    }

    /**
     * #2177 F4 REVERSES the former clause-16 rule that "401 requests fire
     * nothing". That silence was the defect: credential probing and brute-force
     * attempts left no trace whatsoever. A rejection is now audited with a NULL
     * actor and no credential material.
     */
    #[Test]
    public function failedAuthIsAuditedWithoutTheCredential(): void
    {
        $this->auth->method('authenticate')->willReturn(null);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer bad');

        self::assertSame(401, $response->statusCode);
        self::assertCount(1, $spy->dispatched);

        [$event] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        self::assertSame('authentication_rejected', $event->stage);
        self::assertNull($event->accountUid, 'No principal was resolved, so the actor stays null.');
        self::assertSame([], $event->params);
        self::assertStringNotContainsString('bad', \json_encode($event->safeArguments, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function parseErrorFiresNoEvent(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{invalid json', 'Bearer valid');

        self::assertSame(-32700, \json_decode($response->body, true)['error']['code']);
        self::assertCount(0, $spy->dispatched, 'Parse-error requests fire nothing (clause 16)');
    }

    #[Test]
    public function missingMethodFiresNoEvent(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1}', 'Bearer valid');

        self::assertSame(-32600, \json_decode($response->body, true)['error']['code']);
        self::assertCount(0, $spy->dispatched, 'Invalid-Request envelopes (no method) fire nothing');
    }

    #[Test]
    public function throwingDispatcherLeavesResponseByteIdentical(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';

        $baseline = $this->dispatch($this->makeEndpoint(dispatcher: null), $body, 'Bearer valid');

        $throwing = new class implements EventDispatcherInterface {
            public function dispatch(object $event, ?string $eventName = null): object
            {
                throw new \RuntimeException('audit listener exploded');
            }
        };
        $withThrowing = $this->dispatch($this->makeEndpoint(dispatcher: $throwing), $body, 'Bearer valid');

        // Clause 19: a dispatcher failure never alters the JSON-RPC response.
        self::assertSame($baseline->body, $withThrowing->body);
        self::assertSame($baseline->statusCode, $withThrowing->statusCode);
        self::assertSame($baseline->contentType, $withThrowing->contentType);
    }

    #[Test]
    public function legacyConstructionWithoutDispatcherStillWorks(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        // Pre-provenance construction shape: no dispatcher, no context.
        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry([]),
        );
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"ping"}', 'Bearer valid');

        self::assertSame(200, $response->statusCode);
        self::assertArrayHasKey('result', \json_decode($response->body, true));
    }

    #[Test]
    public function accountContextIsSetToBearerAccountAndRestored(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $previousActor = $this->createStub(AccountInterface::class);
        $context = new RecordingMcpAccountContext($previousActor);

        $endpoint = $this->makeEndpoint(accountContext: $context);
        $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        // set(bearer account) during dispatch, then restored to the PREVIOUS
        // value (not blindly null).
        self::assertSame([$this->account, $previousActor], $context->setCalls);
        self::assertSame($previousActor, $context->current());
    }

    #[Test]
    public function accountContextIsRestoredWhenTheRoutedHandlerThrows(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $previousActor = $this->createStub(AccountInterface::class);
        $context = new RecordingMcpAccountContext($previousActor);

        // Registry whose enumeration throws. Since the F4 closure fix the
        // protocol wrapper catches this and answers with a sanitized -32603
        // instead of letting it cross the context scope boundary — the
        // `finally` pin must hold on this path all the same.
        $explodingRegistry = new class implements AgentToolRegistryInterface {
            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return false;
            }

            public function all(): iterable
            {
                throw new \RuntimeException('registry exploded');
            }
        };

        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $explodingRegistry,
            dispatcher: null,
            accountContext: $context,
        );

        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        self::assertSame(-32603, \json_decode($response->body, true)['error']['code']);

        // The `finally` pin: no stale bearer actor leaks past the request.
        self::assertSame([$this->account, $previousActor], $context->setCalls);
        self::assertSame($previousActor, $context->current());
    }

    #[Test]
    public function accountContextIsUntouchedOnAuthFailure(): void
    {
        $this->auth->method('authenticate')->willReturn(null);
        $context = new RecordingMcpAccountContext(null);

        $endpoint = $this->makeEndpoint(accountContext: $context);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer bad');

        self::assertSame(401, $response->statusCode);
        self::assertSame([], $context->setCalls, 'No context writes for unauthenticated requests');
    }

    #[Test]
    public function toolsRunInsideTheBearerPrincipalsFieldReadScopeAndRestoreIt(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $scope = new AccountFieldReadScope();
        $impl = new class ($scope) implements AgentToolInterface {
            public function __construct(private readonly AccountFieldReadScopeInterface $scope) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([[
                    'type' => 'text',
                    'text' => json_encode([
                        'scope_id' => $this->scope->current()?->id(),
                        'tool_id' => $account->id(),
                    ], JSON_THROW_ON_ERROR),
                ]]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Field-read scope probe.';
            }
        };
        $tool = new AgentTool(
            name: 'scope_probe',
            capability: 'tool.test',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
        $endpoint = $this->makeEndpoint(fieldReadScope: $scope, tools: [$tool]);

        $response = $this->dispatch(
            $endpoint,
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"scope_probe","arguments":[]}}',
            'Bearer valid',
        );
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $payload = json_decode($decoded['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['scope_id' => 7, 'tool_id' => 7], $payload);
        self::assertNull($scope->current(), 'Bearer authority must not leak past the request.');
    }

    #[Test]
    public function eventNameIsPinnedToTheAuditListenerSubscriptionConstant(): void
    {
        // Clause 18: the literal is duplicated by design (mcp must not
        // require audit at runtime); this cross-package pin — comparing the
        // two class constants by FQCN, not string literals — is the only
        // guard against silent divergence.
        self::assertSame(McpDispatchAuditListener::EVENT_NAME, McpDispatchEvent::NAME);
    }

    // ------------------------------------------------------------------
    // Helpers (mirrors McpEndpointTest bootstrap)
    // ------------------------------------------------------------------

    /**
     * @param list<AgentTool> $tools
     */
    private function makeEndpoint(
        ?EventDispatcherInterface $dispatcher = null,
        ?AccountContextInterface $accountContext = null,
        ?AccountFieldReadScopeInterface $fieldReadScope = null,
        array $tools = [],
    ): McpEndpoint {
        return new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry($tools),
            dispatcher: $dispatcher,
            accountContext: $accountContext,
            fieldReadScope: $fieldReadScope,
        );
    }

    /** @param array<string, string> $protocolHeaders */
    private function dispatch(
        McpEndpoint $endpoint,
        string $body,
        ?string $authorizationHeader,
        array $protocolHeaders = [],
    ): McpResponse
    {
        $headers = $protocolHeaders;
        if ($authorizationHeader !== null) {
            $headers['HTTP_AUTHORIZATION'] = $authorizationHeader;
        }

        $request = HttpRequest::create('/_mcp', 'POST', [], [], [], $headers, $body);

        return $endpoint->handle($this->account, $request);
    }

    private function makeEchoTool(string $name): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([
                    ['type' => 'text', 'text' => \json_encode(['operation' => 'echo', ...$arguments], \JSON_THROW_ON_ERROR)],
                ]);
            }

            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }

            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'Test tool fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.test',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /**
     * @param list<AgentTool> $tools
     */
    private function stubAgentRegistry(array $tools): AgentToolRegistryInterface
    {
        return new class ($tools) implements AgentToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            /** @param list<AgentTool> $tools */
            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                if (!isset($this->map[$name])) {
                    throw ToolNotFoundException::forName($name);
                }
                return $this->map[$name];
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->map);
            }
        };
    }
}

/**
 * Spy Symfony-contracts dispatcher recording `[event, name]` pairs.
 */
final class RecordingSymfonyDispatcher implements EventDispatcherInterface
{
    /** @var list<array{0: object, 1: ?string}> */
    public array $dispatched = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->dispatched[] = [$event, $eventName];

        return $event;
    }
}

/**
 * Recording {@see AccountContextInterface} stub pinning set/restore order.
 */
final class RecordingMcpAccountContext implements AccountContextInterface
{
    /** @var list<?AccountInterface> */
    public array $setCalls = [];

    public function __construct(private ?AccountInterface $current = null) {}

    public function current(): ?AccountInterface
    {
        return $this->current;
    }

    public function set(?AccountInterface $account): void
    {
        $this->setCalls[] = $account;
        $this->current = $account;
    }
}
