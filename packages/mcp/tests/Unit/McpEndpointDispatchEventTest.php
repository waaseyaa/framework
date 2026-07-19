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
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Event\McpDispatchEvent;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpResponse;

/**
 * FR-007 / contract clauses 16-19: the `waaseyaa.mcp.dispatch` event fires
 * exactly once per authenticated well-formed JSON-RPC request, carries the
 * bearer account, never alters the RPC response, and its name literal is
 * pinned to the audit listener's subscription constant (clause 18).
 */
#[CoversClass(McpEndpoint::class)]
#[CoversClass(McpDispatchEvent::class)]
final class McpEndpointDispatchEventTest extends TestCase
{
    private McpAuthInterface $auth;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(McpAuthInterface::class);
        $this->account = $this->createMock(AuthorizationPrincipalInterface::class);
        $this->account->method('id')->willReturn(7);
        $this->account->method('hasPermission')->willReturn(true);
    }

    #[Test]
    public function authenticatedToolsListFiresExactlyOneEventWithBearerAccount(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        self::assertSame(200, $response->statusCode);
        self::assertCount(1, $spy->dispatched, 'Exactly one dispatch event per request (clause 16)');

        [$event, $name] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        self::assertSame(McpDispatchEvent::NAME, $name, 'Dispatched under the string event name');
        self::assertSame('tools/list', $event->method);
        self::assertSame([], $event->params);
        self::assertSame(7, $event->accountUid, 'accountUid is the bearer-auth account id');
    }

    #[Test]
    public function dispatchEventPreservesOpaqueStringAccountId(): void
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn('acct-anishinaabe-7');
        $account->method('hasPermission')->willReturn(true);
        $this->auth->method('authenticate')->willReturn($account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');

        self::assertCount(1, $spy->dispatched);
        [$event] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        $expectedStableUid = (int) hexdec(substr(hash('sha256', 'acct-anishinaabe-7'), 0, 15));
        self::assertSame($expectedStableUid, $event->accountUid);
        self::assertNotSame(0, $event->accountUid, 'Opaque ids must never collide with the anonymous sentinel.');
    }

    #[Test]
    public function toolsCallEventCarriesRawParams(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy, tools: [$this->makeEchoTool('read_node')]);
        $params = ['name' => 'read_node', 'arguments' => ['id' => 42]];
        $this->dispatch($endpoint, \json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => $params,
        ], \JSON_THROW_ON_ERROR), 'Bearer valid');

        self::assertCount(1, $spy->dispatched);
        [$event] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        self::assertSame('tools/call', $event->method);
        self::assertSame($params, $event->params, 'Params are carried RAW — hashing belongs to the listener (clause 17)');
        self::assertSame(7, $event->accountUid);
    }

    #[Test]
    public function unknownMethodStillFiresTheEvent(): void
    {
        // The listener's documented contract is "each JSON-RPC method
        // invocation" — no filtering by method (D5).
        $this->auth->method('authenticate')->willReturn($this->account);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"resources/list"}', 'Bearer valid');

        self::assertCount(1, $spy->dispatched);
        [$event] = $spy->dispatched[0];
        self::assertInstanceOf(McpDispatchEvent::class, $event);
        self::assertSame('resources/list', $event->method);
    }

    #[Test]
    public function failedAuthFiresNoEvent(): void
    {
        $this->auth->method('authenticate')->willReturn(null);
        $spy = new RecordingSymfonyDispatcher();

        $endpoint = $this->makeEndpoint(dispatcher: $spy);
        $response = $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer bad');

        self::assertSame(401, $response->statusCode);
        self::assertCount(0, $spy->dispatched, '401 requests fire nothing (clause 16)');
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

        $previousActor = $this->createMock(AccountInterface::class);
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

        $previousActor = $this->createMock(AccountInterface::class);
        $context = new RecordingMcpAccountContext($previousActor);

        // Registry whose enumeration throws — tools/list propagates the
        // exception across the context scope boundary.
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

        try {
            $this->dispatch($endpoint, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer valid');
            self::fail('Handler exception expected to propagate');
        } catch (\RuntimeException) {
            // expected
        }

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
        array $tools = [],
    ): McpEndpoint {
        return new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry($tools),
            dispatcher: $dispatcher,
            accountContext: $accountContext,
        );
    }

    private function dispatch(McpEndpoint $endpoint, string $body, ?string $authorizationHeader): McpResponse
    {
        $headers = [];
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
