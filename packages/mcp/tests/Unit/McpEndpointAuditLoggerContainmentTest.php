<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpErrorCode;
use Waaseyaa\Mcp\Tests\Support\ThrowingLogger;

/**
 * #2780 containment: a broken LOGGER must never change a caller-visible outcome.
 *
 * Every `critical()` report in {@see McpEndpoint} that concerns the audit
 * infrastructure is made AFTER the outcome is already decided — a refusal that
 * stands, or a `tools/call` whose side effect has already committed. They all
 * route through one private `reportAuditFailure()` helper that wraps the logger
 * call, so a logger that throws is swallowed rather than substituted for the
 * response.
 *
 * The three request shapes below drive that helper from its three distinct
 * ledger-failure entry points through the real `serve()` boundary. The three
 * remaining callers (`mcp.approval_store_unavailable`,
 * `mcp.approval_consume_failed`, and the refusal path's `finalizeQuietly()`)
 * reach the same single helper; their surrounding approval semantics are
 * covered by `Tests\Integration\Approval\McpApprovalGateLifecycleTest`.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointAuditLoggerContainmentTest extends TestCase
{
    private const string WRITE_CAP = 'content.publish';
    public const string WRITE_CAP_PUBLIC = self::WRITE_CAP;
    private const string TOKEN = 'write-token';

    /** Incremented every time the destructive fixture actually mutates. */
    public static int $mutations = 0;

    protected function setUp(): void
    {
        self::$mutations = 0;
    }

    /**
     * The substantive gap: `tools/call` is dispatched directly from
     * `handle()`, so nothing downstream catches for it. An unguarded
     * `critical()` throw here reached the HTTP client as a transport failure
     * for a write that had ALREADY committed — inviting the caller to retry an
     * action that already happened.
     */
    #[Test]
    public function a_post_execution_finalize_failure_reported_to_a_broken_logger_still_returns_the_result(): void
    {
        $response = $this->callTool($this->endpoint($this->ledger(finalizeThrows: true)));

        self::assertSame(1, self::$mutations, 'The mutation committed exactly once.');

        $body = $this->decode($response);
        self::assertArrayHasKey('result', $body, 'The completed tool result must still reach the caller.');
        self::assertArrayNotHasKey('error', $body);
    }

    /**
     * The reservation path: fail-closed is already decided (the tool is never
     * invoked), so a broken logger must not replace the `-31001` refusal with
     * an uncaught exception.
     */
    #[Test]
    public function a_reservation_failure_reported_to_a_broken_logger_still_returns_the_refusal(): void
    {
        $response = $this->callTool($this->endpoint($this->ledger(reserveThrows: true)));

        self::assertSame(0, self::$mutations, 'The tool must not run when the attempt cannot be recorded.');

        $body = $this->decode($response);
        self::assertSame(McpErrorCode::AUDIT_TRAIL_UNAVAILABLE, $body['error']['code']);
        self::assertStringNotContainsString('ledger offline', (string) $response->getContent());
    }

    /**
     * The terminal-record path: the refusal performs no side effect and is
     * already safe, so it is returned even when the record fails — and stays
     * returned when the report of that failure fails too.
     */
    #[Test]
    public function a_terminal_record_failure_reported_to_a_broken_logger_still_returns_the_refusal(): void
    {
        $response = $this->callMethod($this->endpoint($this->ledger(recordThrows: true)), 'bogus/method');

        $body = $this->decode($response);
        self::assertSame(-32601, $body['error']['code']);
        self::assertStringNotContainsString('ledger offline', (string) $response->getContent());
    }

    // ------------------------------------------------------------- fixtures

    private function ledger(
        bool $reserveThrows = false,
        bool $finalizeThrows = false,
        bool $recordThrows = false,
    ): StrictAuditLedgerInterface {
        return new class ($reserveThrows, $finalizeThrows, $recordThrows) implements StrictAuditLedgerInterface {
            public function __construct(
                private readonly bool $reserveThrows,
                private readonly bool $finalizeThrows,
                private readonly bool $recordThrows,
            ) {}

            public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
            {
                if ($this->reserveThrows) {
                    throw new StrictAuditLedgerException('ledger offline');
                }

                return new StrictAuditReceipt('r1', $reservation->correlationId);
            }

            public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
            {
                if ($this->finalizeThrows) {
                    throw new StrictAuditLedgerException('ledger offline');
                }
            }

            public function record(StrictAuditReservation $reservation, AuditStage $stage): void
            {
                if ($this->recordThrows) {
                    throw new StrictAuditLedgerException('ledger offline');
                }
            }
        };
    }

    private function endpoint(StrictAuditLedgerInterface $ledger): McpEndpoint
    {
        return new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => $this->account(7)]),
            agentRegistry: new CapabilityScopedToolRegistry($this->registry(), [self::WRITE_CAP]),
            rateLimitTier: 'write',
            logger: new ThrowingLogger(),
            auditLedger: $ledger,
            durableAudit: true,
        );
    }

    private function callTool(McpEndpoint $endpoint): HttpResponse
    {
        return $this->callMethod($endpoint, 'tools/call', [
            'name' => 'article.publish',
            'arguments' => ['id' => 'a1'],
        ]);
    }

    private function callMethod(McpEndpoint $endpoint, string $method, mixed $params = []): HttpResponse
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR);
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], $body);

        return $endpoint->serve($this->account(99), $request);
    }

    /** @return array<string, mixed> */
    private function decode(HttpResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function account(int $id): AccountInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $p): bool => $p === self::WRITE_CAP,
        );

        return $account;
    }

    private function registry(): ToolRegistryInterface
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability(McpEndpointAuditLoggerContainmentTest::WRITE_CAP_PUBLIC, $account);
                if ($denied !== null) {
                    return $denied;
                }
                // Stands in for a committed mutation.
                McpEndpointAuditLoggerContainmentTest::$mutations++;

                return AgentToolResult::success([['type' => 'text', 'text' => 'published']]);
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string']],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ];
            }

            public function description(): string
            {
                return 'publishes';
            }
        };

        $tool = new AgentTool('article.publish', self::WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);

        return new class ($tool) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            public function __construct(AgentTool $tool)
            {
                $this->map[$tool->name] = $tool;
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->map[$name] ?? throw ToolNotFoundException::forName($name);
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
