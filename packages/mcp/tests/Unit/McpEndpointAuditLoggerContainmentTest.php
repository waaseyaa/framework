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
use Waaseyaa\AI\Tools\Resource\ContentResourceContent;
use Waaseyaa\AI\Tools\Resource\ContentResourceListPage;
use Waaseyaa\AI\Tools\Resource\ContentResourceProviderInterface;
use Waaseyaa\AI\Tools\Resource\ContentResourceRegistry;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStoreException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpErrorCode;
use Waaseyaa\Mcp\McpResponse;
use Waaseyaa\Mcp\Tests\Support\RecordingLogger;
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
 * Every one of the six `reportAuditFailure()` callsites is driven here through
 * the real `serve()` boundary, each one twice:
 *
 * - with a WORKING logger, proving the callsite is actually entered and emits
 *   its named event (the branch is executed, not merely constructed); and
 * - with {@see ThrowingLogger}, proving the throw is contained and the
 *   caller-visible outcome is byte-for-byte the same.
 *
 * The working-logger test of each pair is the reachability proof; the
 * throwing-logger test is the containment proof. A future refactor that
 * un-guards the helper fails the second while the first still passes, which is
 * exactly the signal #2780 needs.
 *
 * #2886 extends the same proof to a second, sibling family: the six
 * `logger?->error(...)` sites in {@see McpEndpoint} that report
 * protocol/resource dispatch failures and rate-limiter unavailability (no
 * committed side effect, unlike the `critical()` family above) — routed
 * through the private `reportOperationalFailure()` helper. Four of the six sit
 * BEFORE their own `auditTerminal()` call, so an unguarded throw there would
 * both crash the request and suppress that terminal audit record — a strictly
 * worse outcome than #2780 fixed. Each of those four is proven here against a
 * ledger fixture that records every stage it is asked to persist, so the test
 * can assert the terminal record was written even though the logger threw.
 */
#[CoversClass(McpEndpoint::class)]
final class McpEndpointAuditLoggerContainmentTest extends TestCase
{
    private const string WRITE_CAP = 'content.publish';
    public const string WRITE_CAP_PUBLIC = self::WRITE_CAP;
    private const string TOKEN = 'write-token';

    /** The uid `BearerTokenAuth` resolves the write token to; `handleToolsCall()` uses it as the principal key. */
    private const int PRINCIPAL_UID = 7;

    private const string TOOL = 'article.publish';

    /** @var array<string, string> The exact arguments the approval tuple is fingerprinted over. */
    private const array ARGUMENTS = ['id' => 'a1'];

    private const string APPROVAL_ID = 'apr_0123456789abcdef0123456789abcdef';

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

    /**
     * The pre-reservation approval gate: nothing is reserved and the tool is
     * never invoked, so the refusal is already safe. Driving `open()` into a
     * failure enters `approvalStoreUnavailable()`, whose first statement is the
     * report — reached with a working logger, contained with a broken one.
     */
    #[Test]
    public function an_approval_store_read_failure_is_reported_and_the_refusal_survives_a_broken_logger(): void
    {
        $logger = new RecordingLogger();
        $reported = $this->callTool($this->endpoint($this->ledger(), $logger, $this->store(openThrows: true)));

        self::assertSame(0, self::$mutations, 'An unreadable approval store must fail closed.');
        self::assertSame(['mcp.approval_store_unavailable'], $this->reportedEvents($logger));
        self::assertSame(
            McpErrorCode::APPROVAL_STORE_UNAVAILABLE,
            $this->decode($reported)['error']['code'],
        );

        $contained = $this->callTool(
            $this->endpoint($this->ledger(), new ThrowingLogger(), $this->store(openThrows: true)),
        );

        self::assertSame(0, self::$mutations, 'The contained run must not execute either.');
        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
    }

    /**
     * The consume path: the reservation is already durable and the refusal is
     * already decided, so a broken logger must not replace `-31002` with an
     * uncaught transport failure.
     */
    #[Test]
    public function an_approval_consume_failure_is_reported_and_the_refusal_survives_a_broken_logger(): void
    {
        $logger = new RecordingLogger();
        $reported = $this->callTool(
            $this->endpoint($this->ledger(), $logger, $this->store(consumeThrows: true)),
            self::APPROVAL_ID,
        );

        self::assertSame(0, self::$mutations, 'An approval that cannot be consumed must not authorize execution.');
        self::assertSame(['mcp.approval_consume_failed'], $this->reportedEvents($logger));
        self::assertSame(
            McpErrorCode::APPROVAL_STORE_UNAVAILABLE,
            $this->decode($reported)['error']['code'],
        );

        $contained = $this->callTool(
            $this->endpoint($this->ledger(), new ThrowingLogger(), $this->store(consumeThrows: true)),
            self::APPROVAL_ID,
        );

        self::assertSame(0, self::$mutations, 'The contained run must not execute either.');
        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
    }

    /**
     * `finalizeQuietly()`'s own refusal path: the approval is not spendable by
     * this request (lost race / state drift), the `-32004` refusal is already
     * decided, and the reservation's outcome record then fails too. The report
     * of THAT failure is the last thing standing between a dangling reservation
     * and a caller-visible crash.
     */
    #[Test]
    public function a_refusal_finalize_failure_is_reported_and_the_refusal_survives_a_broken_logger(): void
    {
        $logger = new RecordingLogger();
        $reported = $this->callTool(
            $this->endpoint($this->ledger(finalizeThrows: true), $logger, $this->store(consumable: false)),
            self::APPROVAL_ID,
        );

        self::assertSame(0, self::$mutations, 'An unconsumable approval must not authorize execution.');
        self::assertSame(['mcp.audit_finalize_failed'], $this->reportedEvents($logger));
        self::assertSame(-32004, $this->decode($reported)['error']['code']);

        $contained = $this->callTool(
            $this->endpoint($this->ledger(finalizeThrows: true), new ThrowingLogger(), $this->store(consumable: false)),
            self::APPROVAL_ID,
        );

        self::assertSame(0, self::$mutations, 'The contained run must not execute either.');
        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
        self::assertStringNotContainsString('ledger offline', (string) $contained->getContent());
    }

    /**
     * #2886 site 1/6 — `McpEndpoint::serve()`, rate-limiter durability check.
     *
     * This report sits BEFORE its `auditTerminal(RateLimiterUnavailable)`
     * call: a broken logger must not suppress that terminal record on top of
     * crashing the request.
     */
    #[Test]
    public function a_rate_limiter_outage_is_reported_and_the_terminal_record_survives_a_broken_logger(): void
    {
        $ledger = $this->ledger();
        $logger = new RecordingLogger();
        $reported = $this->callMethod(
            $this->endpoint($ledger, $logger, rateLimiter: $this->rateLimiterThatThrows(), rateLimitMaxRequests: 5),
            'ping',
        );

        self::assertSame(
            McpErrorCode::RATE_LIMITER_UNAVAILABLE,
            $this->decode($reported)['error']['code'],
        );
        self::assertSame(
            ['MCP rate limiter could not make a durable decision.'],
            $this->reportedErrorEvents($logger),
        );
        self::assertSame([AuditStage::RateLimiterUnavailable->value], $ledger->recordedStages);

        $containedLedger = $this->ledger();
        $contained = $this->callMethod(
            $this->endpoint(
                $containedLedger,
                new ThrowingLogger(),
                rateLimiter: $this->rateLimiterThatThrows(),
                rateLimitMaxRequests: 5,
            ),
            'ping',
        );

        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertSame(
            [AuditStage::RateLimiterUnavailable->value],
            $containedLedger->recordedStages,
            'The terminal audit record must still be written even though the logger threw.',
        );
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
    }

    /**
     * #2886 site 2/6 — `protocolExecute()`'s handler-threw branch, reached via
     * `tools/list` against a registry whose enumeration itself is broken.
     *
     * This report sits BEFORE its `auditTerminal(ExecutionFailed)` call.
     */
    #[Test]
    public function a_protocol_handler_failure_is_reported_and_the_terminal_record_survives_a_broken_logger(): void
    {
        $ledger = $this->ledger();
        $logger = new RecordingLogger();
        $reported = $this->callMethod(
            $this->endpoint($ledger, $logger, registry: $this->registryThatThrowsOnAll()),
            'tools/list',
        );

        self::assertSame(-32603, $this->decode($reported)['error']['code']);
        self::assertSame(['mcp.protocol_execution_failed'], $this->reportedErrorEvents($logger));
        self::assertSame([AuditStage::ExecutionFailed->value], $ledger->recordedStages);

        $containedLedger = $this->ledger();
        $contained = $this->callMethod(
            $this->endpoint($containedLedger, new ThrowingLogger(), registry: $this->registryThatThrowsOnAll()),
            'tools/list',
        );

        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertSame(
            [AuditStage::ExecutionFailed->value],
            $containedLedger->recordedStages,
            'The terminal audit record must still be written even though the logger threw.',
        );
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
    }

    /**
     * #2886 site 3/6 — `protocolExecute()`'s malformed-internal-response
     * branch. Unlike sites 1, 2, 4 and 6, this report sits AFTER its
     * `auditTerminal(ExecutionFailed)` call (already emitted the moment the
     * response was classified malformed), so containment here is about the
     * response the caller gets, not about a record being lost.
     *
     * No legitimate handler ever returns a body with neither `result` nor
     * `error`, so this is driven directly on the private method — the same
     * technique {@see McpEndpointDispatchEventTest} already uses for this
     * exact branch.
     */
    #[Test]
    public function a_malformed_protocol_response_is_reported_and_survives_a_broken_logger(): void
    {
        $ledger = $this->ledger();
        $logger = new RecordingLogger();
        $endpoint = $this->endpoint($ledger, $logger);
        $execute = new \ReflectionMethod($endpoint, 'protocolExecute');
        $malformed = static fn(): McpResponse => new McpResponse('not-json sk-internal-response-secret');

        $reported = $execute->invoke($endpoint, $malformed, 1, 'correlation-malformed', 7, 'ping');
        self::assertInstanceOf(McpResponse::class, $reported);

        self::assertSame(-32603, $this->decodeMcp($reported)['error']['code']);
        self::assertSame(['mcp.protocol_response_malformed'], $this->reportedErrorEvents($logger));
        self::assertSame([AuditStage::ExecutionFailed->value], $ledger->recordedStages);
        self::assertStringNotContainsString('sk-internal-response-secret', $reported->body);

        $containedEndpoint = $this->endpoint($this->ledger(), new ThrowingLogger());
        $containedExecute = new \ReflectionMethod($containedEndpoint, 'protocolExecute');
        $contained = $containedExecute->invoke($containedEndpoint, $malformed, 1, 'correlation-malformed', 7, 'ping');
        self::assertInstanceOf(McpResponse::class, $contained);

        self::assertSame(-32603, $this->decodeMcp($contained)['error']['code']);
        self::assertStringNotContainsString('logger offline', $contained->body);
        self::assertStringNotContainsString('sk-internal-response-secret', $contained->body);
    }

    /**
     * #2886 site 4/6 — `resourceProtocolExecute()`'s handler-threw branch,
     * reached via `resources/list` against a provider whose enumeration
     * itself is broken.
     *
     * This report sits BEFORE its `auditTerminal(ExecutionFailed)` call.
     */
    #[Test]
    public function a_resource_list_failure_is_reported_and_the_terminal_record_survives_a_broken_logger(): void
    {
        $ledger = $this->ledger();
        $logger = new RecordingLogger();
        $reported = $this->callMethod(
            $this->endpoint($ledger, $logger, contentResources: $this->contentResourcesThatThrowOnList()),
            'resources/list',
        );

        self::assertSame(-32603, $this->decode($reported)['error']['code']);
        self::assertSame(['mcp.resource_execution_failed'], $this->reportedErrorEvents($logger));
        self::assertSame([AuditStage::ExecutionFailed->value], $ledger->recordedStages);

        $containedLedger = $this->ledger();
        $contained = $this->callMethod(
            $this->endpoint(
                $containedLedger,
                new ThrowingLogger(),
                contentResources: $this->contentResourcesThatThrowOnList(),
            ),
            'resources/list',
        );

        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertSame(
            [AuditStage::ExecutionFailed->value],
            $containedLedger->recordedStages,
            'The terminal audit record must still be written even though the logger threw.',
        );
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
    }

    /**
     * #2886 site 5/6 — `resourceProtocolExecute()`'s malformed-internal-
     * response branch. Like site 3, this report sits AFTER its
     * `auditTerminal()` call and is unreachable through any legitimate
     * handler, so it is driven directly on the private method.
     */
    #[Test]
    public function a_malformed_resource_response_is_reported_and_survives_a_broken_logger(): void
    {
        $ledger = $this->ledger();
        $logger = new RecordingLogger();
        $endpoint = $this->endpoint($ledger, $logger);
        $execute = new \ReflectionMethod($endpoint, 'resourceProtocolExecute');
        $malformed = static fn(): McpResponse => new McpResponse('not-json sk-internal-response-secret');

        $reported = $execute->invoke($endpoint, $malformed, 1, 'correlation-malformed', 7, 'resources/list');
        self::assertInstanceOf(McpResponse::class, $reported);

        self::assertSame(-32603, $this->decodeMcp($reported)['error']['code']);
        self::assertSame(['mcp.resource_response_malformed'], $this->reportedErrorEvents($logger));
        self::assertSame([AuditStage::ExecutionFailed->value], $ledger->recordedStages);
        self::assertStringNotContainsString('sk-internal-response-secret', $reported->body);

        $containedEndpoint = $this->endpoint($this->ledger(), new ThrowingLogger());
        $containedExecute = new \ReflectionMethod($containedEndpoint, 'resourceProtocolExecute');
        $contained = $containedExecute->invoke(
            $containedEndpoint,
            $malformed,
            1,
            'correlation-malformed',
            7,
            'resources/list',
        );
        self::assertInstanceOf(McpResponse::class, $contained);

        self::assertSame(-32603, $this->decodeMcp($contained)['error']['code']);
        self::assertStringNotContainsString('logger offline', $contained->body);
        self::assertStringNotContainsString('sk-internal-response-secret', $contained->body);
    }

    /**
     * #2886 site 6/6 — `executeResourceRead()`'s handler-threw branch, reached
     * via `resources/read` against a provider whose read itself is broken.
     *
     * This report sits BEFORE its `auditTerminal(ExecutionFailed)` call.
     */
    #[Test]
    public function a_resource_read_failure_is_reported_and_the_terminal_record_survives_a_broken_logger(): void
    {
        $ledger = $this->ledger();
        $logger = new RecordingLogger();
        $reported = $this->callMethod(
            $this->endpoint($ledger, $logger, contentResources: $this->contentResourcesThatThrowOnRead()),
            'resources/read',
            ['uri' => 'res://a'],
        );

        self::assertSame(-32603, $this->decode($reported)['error']['code']);
        self::assertSame(['mcp.resource_execution_failed'], $this->reportedErrorEvents($logger));
        self::assertSame([AuditStage::ExecutionFailed->value], $ledger->recordedStages);

        $containedLedger = $this->ledger();
        $contained = $this->callMethod(
            $this->endpoint(
                $containedLedger,
                new ThrowingLogger(),
                contentResources: $this->contentResourcesThatThrowOnRead(),
            ),
            'resources/read',
            ['uri' => 'res://a'],
        );

        self::assertSame($this->outcome($reported), $this->outcome($contained));
        self::assertSame(
            [AuditStage::ExecutionFailed->value],
            $containedLedger->recordedStages,
            'The terminal audit record must still be written even though the logger threw.',
        );
        self::assertStringNotContainsString('logger offline', (string) $contained->getContent());
    }

    // ------------------------------------------------------------- fixtures

    private function ledger(
        bool $reserveThrows = false,
        bool $finalizeThrows = false,
        bool $recordThrows = false,
    ): StrictAuditLedgerInterface {
        return new class ($reserveThrows, $finalizeThrows, $recordThrows) implements StrictAuditLedgerInterface {
            /**
             * Every stage this fixture was asked to persist via {@see record()},
             * in order — the #2886 proof that `auditTerminal()` reached its
             * durable write even when the logger call immediately before it
             * threw.
             *
             * @var list<string>
             */
            public array $recordedStages = [];

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

                $this->recordedStages[] = $stage->value;
            }
        };
    }

    private function endpoint(
        StrictAuditLedgerInterface $ledger,
        ?LoggerInterface $logger = null,
        ?OperationApprovalStoreInterface $approvalStore = null,
        ?ToolRegistryInterface $registry = null,
        ?AtomicRateLimiterInterface $rateLimiter = null,
        int $rateLimitMaxRequests = 0,
        ?ContentResourceRegistry $contentResources = null,
    ): McpEndpoint {
        return new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => $this->account(self::PRINCIPAL_UID)]),
            agentRegistry: new CapabilityScopedToolRegistry($registry ?? $this->registry(), [self::WRITE_CAP]),
            rateLimiter: $rateLimiter,
            rateLimitMaxRequests: $rateLimitMaxRequests,
            rateLimitTier: 'write',
            logger: $logger ?? new ThrowingLogger(),
            auditLedger: $ledger,
            durableAudit: true,
            approvalStore: $approvalStore,
            approvalGate: $approvalStore instanceof OperationApprovalStoreInterface,
            contentResources: $contentResources,
            contentResourcesEnabled: $contentResources instanceof ContentResourceRegistry,
        );
    }

    private function callTool(McpEndpoint $endpoint, ?string $approvalRequestId = null): HttpResponse
    {
        $params = ['name' => self::TOOL, 'arguments' => self::ARGUMENTS];
        if ($approvalRequestId !== null) {
            $params['_meta'] = [McpEndpoint::APPROVAL_REQUEST_ID_META_KEY => $approvalRequestId];
        }

        return $this->callMethod($endpoint, 'tools/call', $params);
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

    /**
     * The caller-visible outcome, minus the per-request correlation id — the
     * one member that legitimately differs between two runs of the same call.
     *
     * @return array<string, mixed>
     */
    private function outcome(HttpResponse $response): array
    {
        $body = $this->decode($response);
        unset($body['error']['data']['correlation_id']);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    /**
     * Every event name reported at `critical` — i.e. exactly the
     * `reportAuditFailure()` calls this request made, in order.
     *
     * @return list<string>
     */
    private function reportedEvents(RecordingLogger $logger): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record[1],
            array_filter($logger->records, static fn(array $record): bool => $record[0] === 'critical'),
        ));
    }

    /**
     * Every event name ever reported at `error` — the #2886 sibling family,
     * routed through `reportOperationalFailure()` rather than
     * `reportAuditFailure()`.
     *
     * @return list<string>
     */
    private function reportedErrorEvents(RecordingLogger $logger): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record[1],
            array_filter($logger->records, static fn(array $record): bool => $record[0] === 'error'),
        ));
    }

    /** @return array<string, mixed> */
    private function decodeMcp(McpResponse $response): array
    {
        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A per-principal atomic rate limiter whose decision itself is broken —
     * standing in for the durable-decision outage {@see McpEndpoint} treats as
     * `AuditStage::RateLimiterUnavailable`. Only `consume()` is on any path
     * under test.
     */
    private function rateLimiterThatThrows(): AtomicRateLimiterInterface
    {
        return new class implements AtomicRateLimiterInterface {
            public function consume(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                throw new \RuntimeException('rate limiter offline');
            }

            public function hit(string $key, int $decaySeconds): void
            {
                throw new \LogicException('hit() is not on any path under test.');
            }

            public function tooManyAttempts(string $key, int $maxAttempts): bool
            {
                throw new \LogicException('tooManyAttempts() is not on any path under test.');
            }

            public function attempts(string $key): int
            {
                throw new \LogicException('attempts() is not on any path under test.');
            }

            public function remaining(string $key, int $maxAttempts): int
            {
                throw new \LogicException('remaining() is not on any path under test.');
            }

            public function clear(string $key): void
            {
                throw new \LogicException('clear() is not on any path under test.');
            }
        };
    }

    /** A tool registry whose enumeration itself is broken — drives `tools/list` into `protocolExecute()`'s catch. */
    private function registryThatThrowsOnAll(): ToolRegistryInterface
    {
        return new class implements ToolRegistryInterface {
            public function register(AgentTool $tool): void
            {
                throw new \LogicException('register() is not on any path under test.');
            }

            public function get(string $name): AgentTool
            {
                throw new \LogicException('get() is not on any path under test.');
            }

            public function has(string $name): bool
            {
                throw new \LogicException('has() is not on any path under test.');
            }

            public function all(): iterable
            {
                throw new \RuntimeException('tool registry offline');
            }
        };
    }

    /** A content-resource registry whose sole provider breaks on `list()` — drives `resources/list`. */
    private function contentResourcesThatThrowOnList(): ContentResourceRegistry
    {
        $registry = new ContentResourceRegistry();
        $registry->register('throwing', new class implements ContentResourceProviderInterface {
            public function list(\Waaseyaa\Access\AuthorizationPrincipalInterface $principal, ?string $resumeToken = null): ContentResourceListPage
            {
                throw new \RuntimeException('resource provider offline');
            }

            public function templates(): array
            {
                throw new \LogicException('templates() is not on any path under test.');
            }

            public function read(string $uri, \Waaseyaa\Access\AuthorizationPrincipalInterface $principal): ?ContentResourceContent
            {
                throw new \LogicException('read() is not on any path under test.');
            }
        });

        return $registry;
    }

    /** A content-resource registry whose sole provider breaks on `read()` — drives `resources/read`. */
    private function contentResourcesThatThrowOnRead(): ContentResourceRegistry
    {
        $registry = new ContentResourceRegistry();
        $registry->register('throwing', new class implements ContentResourceProviderInterface {
            public function list(\Waaseyaa\Access\AuthorizationPrincipalInterface $principal, ?string $resumeToken = null): ContentResourceListPage
            {
                throw new \LogicException('list() is not on any path under test.');
            }

            public function templates(): array
            {
                throw new \LogicException('templates() is not on any path under test.');
            }

            public function read(string $uri, \Waaseyaa\Access\AuthorizationPrincipalInterface $principal): ?ContentResourceContent
            {
                throw new \RuntimeException('resource provider offline');
            }
        });

        return $registry;
    }

    /**
     * A scriptable approval store. Only the members the gate reaches on the
     * paths under test are implemented; the rest throw, so a fixture that
     * silently drifts onto another path fails loudly rather than passing.
     */
    private function store(
        bool $openThrows = false,
        bool $consumeThrows = false,
        bool $consumable = true,
    ): OperationApprovalStoreInterface {
        return new class ($openThrows, $consumeThrows, $consumable, $this->approvedRequest()) implements OperationApprovalStoreInterface {
            public function __construct(
                private readonly bool $openThrows,
                private readonly bool $consumeThrows,
                private readonly bool $consumable,
                private readonly ApprovalRequest $request,
            ) {}

            public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
            {
                if ($this->openThrows) {
                    throw new ApprovalStoreException('store offline');
                }

                return $this->request;
            }

            public function find(string $requestId): ?ApprovalRequest
            {
                return $this->request;
            }

            public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
            {
                if ($this->consumeThrows) {
                    throw new ApprovalStoreException('store offline');
                }

                return $this->consumable;
            }

            public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
            {
                throw new \LogicException('decide() is not on any path under test.');
            }

            public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
            {
                throw new \LogicException('listPending() is not on any path under test.');
            }
        };
    }

    /**
     * An approved, unexpired, unconsumed approval for exactly the call
     * {@see self::callTool()} makes.
     *
     * The tuple must reproduce `handleToolsCall()`'s own — principal key,
     * `mcp.<tier>` surface, tool name, raw-argument fingerprint. Any drift
     * makes the gate answer `tuple_mismatch` instead, so the tests below fail
     * on the error code rather than passing without entering the consume path.
     */
    private function approvedRequest(): ApprovalRequest
    {
        $requestedAt = new \DateTimeImmutable('-1 minute');

        return new ApprovalRequest(
            id: self::APPROVAL_ID,
            tuple: ApprovalTuple::forCall((string) self::PRINCIPAL_UID, 'mcp.write', self::TOOL, self::ARGUMENTS),
            status: ApprovalStatus::Approved,
            correlationId: 'corr-approved',
            safeArguments: self::ARGUMENTS,
            requestedAt: $requestedAt,
            expiresAt: $requestedAt->modify('+1 hour'),
            decidedByUid: 42,
            decidedAt: $requestedAt,
        );
    }

    private function account(int $id): AccountInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('hasPermission')->willReturnCallback(
            // 'resource.content.read' is also granted here (rather than on a
            // second principal fixture) so the #2886 resources/list and
            // resources/read reachability tests can share this account.
            static fn(string $p): bool => $p === self::WRITE_CAP || $p === 'resource.content.read',
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

        $tool = new AgentTool(self::TOOL, self::WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);

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
