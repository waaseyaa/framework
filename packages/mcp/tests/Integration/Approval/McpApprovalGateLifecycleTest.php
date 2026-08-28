<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration\Approval;

use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Audit\Storage\ApprovalEventSchema;
use Waaseyaa\Audit\Storage\StrictAuditLedgerSchema;
use Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore;
use Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStoreException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\Event\McpDispatchEvent;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpErrorCode;

/**
 * #2177 F1 slice B, end to end through the real JSON-RPC boundary against a
 * real SQLite `mcp_approval_event` store and `strict_audit_ledger`.
 *
 * The guarantee under test: **a destructive tool on a gated endpoint never
 * executes without a matching, approved, unexpired, unconsumed durable
 * approval for exactly this principal × surface × tool × arguments — and every
 * other outcome is refused with no state oracle and no execution.**
 */
#[CoversNothing]
final class McpApprovalGateLifecycleTest extends TestCase
{
    private const string WRITE_CAP = 'content.publish';
    public const string WRITE_CAP_PUBLIC = self::WRITE_CAP;
    private const string TOKEN = 'write-token';
    private const string OTHER_TOKEN = 'other-write-token';

    /** Incremented every time the destructive fixture actually mutates. */
    public static int $mutations = 0;

    /** The exact arguments array the destructive fixture last received. */
    public static ?array $lastArguments = null;

    /** When set, the destructive fixture appends 'execute' on each real run. */
    public static ?SequenceLog $sequenceLog = null;

    private DBALDatabase $db;
    private TestClock $clock;
    private DatabaseOperationApprovalStore $store;

    protected function setUp(): void
    {
        self::$mutations = 0;
        self::$lastArguments = null;
        self::$sequenceLog = null;
        $this->db = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($this->db);
        RuntimeSchemaMigrations::audit($this->db);
        $this->clock = new TestClock(new \DateTimeImmutable('2026-08-03 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new DatabaseOperationApprovalStore($this->db, $this->clock, 900);
    }

    // ------------------------------------------------------------- fixtures

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        $rows = [];
        $sql = 'SELECT receipt_id, correlation_id, event_type, surface, operation, stage, outcome, '
             . 'actor_uid, descriptor FROM strict_audit_ledger ORDER BY id';
        foreach ($this->db->query($sql) as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function approvalEvents(): array
    {
        $rows = [];
        foreach ($this->db->query('SELECT * FROM mcp_approval_event ORDER BY id') as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
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

    private function destructiveTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability(McpApprovalGateLifecycleTest::WRITE_CAP_PUBLIC, $account);
                if ($denied !== null) {
                    return $denied;
                }
                McpApprovalGateLifecycleTest::$mutations++;
                McpApprovalGateLifecycleTest::$lastArguments = $arguments;
                McpApprovalGateLifecycleTest::$sequenceLog?->append('execute');

                return AgentToolResult::success([['type' => 'text', 'text' => 'published']]);
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string'], 'token' => ['type' => 'string']],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ];
            }

            public function description(): string
            {
                return 'publishes';
            }
        };

        return new AgentTool('article.publish', self::WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);
    }

    private function readTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'previewed']]);
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]];
            }

            public function description(): string
            {
                return 'previews';
            }
        };

        return new AgentTool('article.preview', self::WRITE_CAP, false, false, 'content', $impl->inputSchema(), $impl);
    }

    private function registry(): ToolRegistryInterface
    {
        $tools = [$this->destructiveTool(), $this->readTool()];

        return new class ($tools) implements ToolRegistryInterface {
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

    private function endpoint(
        ?OperationApprovalStoreInterface $store = null,
        ?StrictAuditLedgerInterface $ledger = null,
        bool $gate = true,
        ?EventDispatcher $dispatcher = null,
    ): McpEndpoint {
        return new McpEndpoint(
            auth: new BearerTokenAuth([
                self::TOKEN => $this->account(7),
                self::OTHER_TOKEN => $this->account(8),
            ]),
            agentRegistry: new CapabilityScopedToolRegistry($this->registry(), [self::WRITE_CAP]),
            dispatcher: $dispatcher,
            rateLimitTier: 'write',
            auditLedger: $ledger ?? new DatabaseStrictAuditLedger($this->db),
            durableAudit: true,
            approvalStore: $gate ? ($store ?? $this->store) : null,
            approvalGate: $gate,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(
        McpEndpoint $endpoint,
        array $arguments = ['id' => 'a1'],
        mixed $meta = null,
        string $tool = 'article.publish',
        string $token = self::TOKEN,
    ): HttpResponse {
        $params = ['name' => $tool, 'arguments' => $arguments];
        if ($meta !== null) {
            $params['_meta'] = $meta;
        }

        return $this->callMethod($endpoint, 'tools/call', $params, $token);
    }

    private function callMethod(
        McpEndpoint $endpoint,
        string $method,
        mixed $params = [],
        string $token = self::TOKEN,
    ): HttpResponse {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR);
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
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

    /** Open + approve one request for the canonical call, returning its id. */
    private function approvedRequestId(array $arguments = ['id' => 'a1'], int $operatorUid = 42): string
    {
        $challenge = $this->decode($this->callTool($this->endpoint(), $arguments));
        $requestId = $challenge['error']['data']['approval_request_id'];
        $this->store->decide($requestId, true, $operatorUid);

        return $requestId;
    }

    /** @return array<string, string> */
    private static function meta(string $requestId): array
    {
        return ['waaseyaa/approval_request_id' => $requestId];
    }

    // ---------------------------------------------------------- the challenge

    #[Test]
    public function a_destructive_call_without_an_approval_id_is_challenged_not_executed(): void
    {
        $body = $this->decode($this->callTool($this->endpoint()));

        self::assertSame(0, self::$mutations, 'The tool MUST NOT run before a human decision.');
        self::assertSame(-32003, $body['error']['code']);

        $data = $body['error']['data'];
        self::assertMatchesRegularExpression('/^apr_[0-9a-f]{32}$/', $data['approval_request_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['correlation_id']);
        self::assertSame('2026-08-03T12:15:00+00:00', $data['expires_at'], 'TTL 900s from the injected clock.');
    }

    #[Test]
    public function the_challenge_durably_opens_the_request_and_records_approval_required(): void
    {
        $body = $this->decode($this->callTool($this->endpoint(), ['id' => 'a1', 'token' => 'sk-raw-secret']));
        $requestId = $body['error']['data']['approval_request_id'];

        // The approval request row: requested, safe args only.
        $events = $this->approvalEvents();
        self::assertCount(1, $events);
        self::assertSame('requested', $events[0]['event_type']);
        self::assertSame($requestId, $events[0]['request_id']);
        self::assertSame('7', $events[0]['principal_key']);
        self::assertSame('mcp.write', $events[0]['surface']);
        self::assertSame('article.publish', $events[0]['operation']);

        // The durable audit record: a single terminal approval_required row.
        $rows = $this->ledger();
        self::assertCount(1, $rows);
        self::assertSame('recorded', $rows[0]['event_type']);
        self::assertSame(AuditStage::ApprovalRequired->value, $rows[0]['stage']);
        self::assertSame('denied', $rows[0]['outcome']);
        self::assertSame('article.publish', $rows[0]['operation']);
        self::assertStringContainsString($requestId, (string) $rows[0]['descriptor']);
        self::assertStringContainsString('expires_at', (string) $rows[0]['descriptor']);

        // Raw params never land anywhere — only the tool's own redaction does.
        $allBytes = json_encode([$events, $rows], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sk-raw-secret', $allBytes);
        self::assertStringNotContainsString(self::TOKEN, $allBytes);
    }

    #[Test]
    public function a_pending_retry_is_rechallenged_with_the_same_request_id(): void
    {
        $first = $this->decode($this->callTool($this->endpoint()));
        $requestId = $first['error']['data']['approval_request_id'];

        $second = $this->decode($this->callTool($this->endpoint(), meta: self::meta($requestId)));

        self::assertSame(0, self::$mutations);
        self::assertSame(-32003, $second['error']['code']);
        self::assertSame($requestId, $second['error']['data']['approval_request_id']);

        // Still exactly one open request — polling must not fan out.
        $requested = array_filter($this->approvalEvents(), static fn(array $e): bool => $e['event_type'] === 'requested');
        self::assertCount(1, $requested);
    }

    /** A retry WITHOUT the id converges on the same pending request too. */
    #[Test]
    public function an_identical_retry_without_the_id_reuses_the_pending_request(): void
    {
        $first = $this->decode($this->callTool($this->endpoint()));
        $second = $this->decode($this->callTool($this->endpoint()));

        self::assertSame(
            $first['error']['data']['approval_request_id'],
            $second['error']['data']['approval_request_id'],
        );
    }

    // ------------------------------------------------------ the approved path

    #[Test]
    public function an_approved_retry_executes_exactly_once_with_full_durable_joins(): void
    {
        $requestId = $this->approvedRequestId();

        $response = $this->decode($this->callTool($this->endpoint(), meta: self::meta($requestId)));

        self::assertSame(1, self::$mutations);
        self::assertArrayHasKey('result', $response);
        self::assertFalse($response['result']['isError'] ?? false);

        // Ledger: challenge record, then the executing pair.
        $rows = $this->ledger();
        self::assertCount(3, $rows);
        self::assertSame(AuditStage::ApprovalRequired->value, $rows[0]['stage']);
        self::assertSame('reserved', $rows[1]['event_type']);
        self::assertSame('finalized', $rows[2]['event_type']);
        self::assertSame(AuditStage::ExecutionSucceeded->value, $rows[2]['stage']);
        self::assertSame($rows[1]['receipt_id'], $rows[2]['receipt_id']);

        // Reservation metadata joins the approval and the deciding operator.
        self::assertStringContainsString($requestId, (string) $rows[1]['descriptor']);
        self::assertStringContainsString('42', (string) $rows[1]['descriptor']);
        // Finalization carries the approval join too.
        self::assertStringContainsString($requestId, (string) $rows[2]['descriptor']);

        // The consumed event joins back to the executing reservation's receipt
        // and carries the consuming retry's correlation id.
        $consumed = array_values(array_filter(
            $this->approvalEvents(),
            static fn(array $e): bool => $e['event_type'] === 'consumed',
        ));
        self::assertCount(1, $consumed);
        self::assertSame($rows[1]['receipt_id'], $consumed[0]['receipt_id']);
        self::assertSame($rows[1]['correlation_id'], $consumed[0]['correlation_id']);

        self::assertSame(ApprovalStatus::Consumed, $this->store->find($requestId)?->status);
    }

    #[Test]
    public function the_gate_orders_reserve_then_consume_then_execute_then_finalize(): void
    {
        $requestId = $this->approvedRequestId();

        $log = new SequenceLog();
        self::$sequenceLog = $log;
        $ledger = new SequenceRecordingLedger(new DatabaseStrictAuditLedger($this->db), $log);
        $store = new SequenceRecordingStore($this->store, $log);

        $this->callTool($this->endpoint(store: $store, ledger: $ledger), meta: self::meta($requestId));

        self::assertSame(['reserve', 'consume', 'execute', 'finalize'], $log->all());
    }

    #[Test]
    public function a_consumed_approval_is_never_reusable(): void
    {
        $requestId = $this->approvedRequestId();
        $this->callTool($this->endpoint(), meta: self::meta($requestId));
        self::assertSame(1, self::$mutations);

        $replay = $this->decode($this->callTool($this->endpoint(), meta: self::meta($requestId)));

        self::assertSame(1, self::$mutations, 'A consumed approval must never authorize a second run.');
        self::assertSame(-32004, $replay['error']['code']);
    }

    // ------------------------------------------------- refusals, no state oracle

    /**
     * Every non-approved axis returns the SAME body — unknown, denied, expired,
     * consumed, and every tuple-mismatch axis are indistinguishable to the
     * caller, so the refusal cannot be used to probe approval state.
     */
    #[Test]
    public function every_refusal_axis_returns_an_identical_no_oracle_body(): void
    {
        // denied
        $denied = $this->decode($this->callTool($this->endpoint()))['error']['data']['approval_request_id'];
        $this->store->decide($denied, false, 42, 'not today');

        // expired (approved, then the window passes)
        $expiredArgs = ['id' => 'expiring'];
        $expired = $this->decode($this->callTool($this->endpoint(), $expiredArgs))['error']['data']['approval_request_id'];
        $this->store->decide($expired, true, 42);
        $this->clock->advance(901);

        // arguments mismatch (fresh, approved, but called with other args)
        $mismatch = $this->approvedRequestId(['id' => 'approved-args']);

        $bodies = [
            'unknown' => $this->decode($this->callTool($this->endpoint(), meta: self::meta('apr_' . str_repeat('0', 32)))),
            'denied' => $this->decode($this->callTool($this->endpoint(), meta: self::meta($denied))),
            'expired' => $this->decode($this->callTool($this->endpoint(), $expiredArgs, meta: self::meta($expired))),
            'argument mismatch' => $this->decode($this->callTool($this->endpoint(), ['id' => 'OTHER'], meta: self::meta($mismatch))),
            'tool mismatch' => $this->decode($this->callTool($this->endpoint(), ['id' => 'approved-args'], meta: self::meta($mismatch), tool: 'article.preview')),
        ];
        // The tool-mismatch axis targets a non-destructive tool, which is not
        // gated at all — drop it from the identical-body set but keep the
        // invariant that it never consumed the approval (asserted below).
        unset($bodies['tool mismatch']);

        self::assertSame(0, self::$mutations);

        $normalized = [];
        foreach ($bodies as $axis => $body) {
            self::assertSame(-32004, $body['error']['code'], $axis);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['error']['data']['correlation_id'], $axis);
            $body['error']['data']['correlation_id'] = 'X';
            $normalized[$axis] = $body['error'];
        }

        self::assertCount(1, array_unique(array_map(
            static fn(array $error): string => json_encode($error, JSON_THROW_ON_ERROR),
            $normalized,
        )), 'All refusal axes must be byte-identical apart from the correlation id.');

        // The mismatched-arguments call must not have consumed the approval.
        self::assertSame(ApprovalStatus::Approved, $this->store->find($mismatch)?->status);
    }

    #[Test]
    public function a_principal_mismatch_is_refused_and_leaves_the_approval_unconsumed(): void
    {
        // Approved for principal 7; replayed by principal 8 with identical args.
        $requestId = $this->approvedRequestId();

        $body = $this->decode($this->callTool($this->endpoint(), meta: self::meta($requestId), token: self::OTHER_TOKEN));

        self::assertSame(0, self::$mutations);
        self::assertSame(-32004, $body['error']['code']);
        self::assertSame(ApprovalStatus::Approved, $this->store->find($requestId)?->status);
    }

    #[Test]
    public function refusals_are_durably_recorded_with_operator_side_detail(): void
    {
        $denied = $this->decode($this->callTool($this->endpoint()))['error']['data']['approval_request_id'];
        $this->store->decide($denied, false, 42);

        $this->callTool($this->endpoint(), meta: self::meta($denied));

        $refused = array_values(array_filter(
            $this->ledger(),
            static fn(array $r): bool => $r['stage'] === AuditStage::ApprovalRefused->value,
        ));
        self::assertCount(1, $refused);
        self::assertSame('recorded', $refused[0]['event_type']);
        self::assertSame('denied', $refused[0]['outcome']);
        // Operator-side detail names the axis; the caller's body never does.
        self::assertStringContainsString('denied', (string) $refused[0]['descriptor']);
        self::assertStringContainsString($denied, (string) $refused[0]['descriptor']);
    }

    // --------------------------------------------------- _meta envelope rules

    #[Test]
    public function a_non_object_meta_is_an_invalid_params_refusal(): void
    {
        $body = $this->decode($this->callTool($this->endpoint(), meta: 'not-an-object'));

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame(0, self::$mutations);
        self::assertSame([], $this->approvalEvents(), 'A malformed envelope must never open an approval.');
    }

    /**
     * `"_meta": null` is PRESENT and not an object — the same envelope defect
     * as any other non-object `_meta`, distinct from the member being absent.
     */
    #[Test]
    public function an_explicitly_null_meta_is_an_invalid_params_refusal(): void
    {
        $params = ['name' => 'article.publish', 'arguments' => ['id' => 'a1'], '_meta' => null];

        $body = $this->decode($this->callMethod($this->endpoint(), 'tools/call', $params));

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame(0, self::$mutations);
        self::assertSame([], $this->approvalEvents(), 'A malformed envelope must never open an approval.');
    }

    /** `{}` decodes to an empty PHP array and is a valid (empty) envelope. */
    #[Test]
    public function an_empty_object_meta_is_a_valid_empty_envelope(): void
    {
        $body = $this->decode($this->callTool($this->endpoint(), meta: []));

        self::assertSame(-32003, $body['error']['code'], 'An empty _meta carries no approval id: challenged, not refused.');
    }

    #[Test]
    public function a_nonempty_list_meta_is_an_invalid_params_refusal(): void
    {
        $body = $this->decode($this->callTool($this->endpoint(), meta: ['a', 'b']));

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame(0, self::$mutations);
        self::assertSame([], $this->approvalEvents());
    }

    #[Test]
    public function a_non_string_approval_request_id_is_an_invalid_params_refusal(): void
    {
        $body = $this->decode($this->callTool($this->endpoint(), meta: ['waaseyaa/approval_request_id' => 7]));

        self::assertSame(-32602, $body['error']['code']);
        self::assertSame(0, self::$mutations);
    }

    #[Test]
    public function meta_never_reaches_schema_validation_audit_or_execution(): void
    {
        $requestId = $this->approvedRequestId();

        // The tool's schema has additionalProperties: false — if _meta leaked
        // into the arguments, validation would refuse the call.
        $response = $this->decode($this->callTool($this->endpoint(), meta: self::meta($requestId)));
        self::assertArrayHasKey('result', $response);

        self::assertSame(['id' => 'a1'], self::$lastArguments, '_meta must never reach the tool.');

        $bytes = json_encode([$this->ledger(), $this->approvalEvents()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('waaseyaa/approval_request_id', $bytes, '_meta is not an argument and must not be audited as one.');
    }

    // ----------------------------------------------- non-destructive behaviour

    #[Test]
    public function a_non_destructive_tool_is_never_gated(): void
    {
        $response = $this->decode($this->callTool($this->endpoint(), tool: 'article.preview'));

        self::assertArrayHasKey('result', $response);
        self::assertSame([], $this->approvalEvents());
    }

    #[Test]
    public function a_destructive_tool_on_an_ungated_endpoint_keeps_slice_a_behaviour(): void
    {
        $response = $this->decode($this->callTool($this->endpoint(gate: false)));

        self::assertSame(1, self::$mutations);
        self::assertArrayHasKey('result', $response);
        self::assertSame([], $this->approvalEvents());

        // The F4 pair, exactly as before the gate existed.
        $rows = $this->ledger();
        self::assertCount(2, $rows);
        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertSame('finalized', $rows[1]['event_type']);
    }

    // -------------------------------------------------------- tools/list _meta

    #[Test]
    public function tools_list_marks_only_destructive_tools_on_a_gated_endpoint(): void
    {
        $body = $this->decode($this->callMethod($this->endpoint(), 'tools/list'));

        $byName = [];
        foreach ($body['result']['tools'] as $descriptor) {
            $byName[$descriptor['name']] = $descriptor;
        }

        self::assertSame(
            ['ai.waaseyaa.mcp/approval' => 'required'],
            $byName['article.publish']['_meta'] ?? null,
        );
        self::assertTrue($byName['article.publish']['annotations']['destructiveHint']);

        self::assertArrayNotHasKey('_meta', $byName['article.preview']);
        self::assertFalse($byName['article.preview']['annotations']['destructiveHint']);
    }

    #[Test]
    public function tools_list_carries_no_approval_meta_on_an_ungated_endpoint(): void
    {
        $body = $this->decode($this->callMethod($this->endpoint(gate: false), 'tools/list'));

        foreach ($body['result']['tools'] as $descriptor) {
            self::assertArrayNotHasKey('_meta', $descriptor, $descriptor['name']);
        }
    }

    // ------------------------------------------------------------- fail closed

    #[Test]
    public function an_unavailable_approval_store_fails_closed_before_any_execution(): void
    {
        $tableless = DBALDatabase::createSqlite(); // schema deliberately not ensured
        $store = new DatabaseOperationApprovalStore($tableless, $this->clock);

        $response = $this->callTool($this->endpoint(store: $store));
        $body = $this->decode($response);

        self::assertSame(0, self::$mutations, 'The tool MUST NOT run when approval state cannot be read.');
        self::assertSame(McpErrorCode::APPROVAL_STORE_UNAVAILABLE, $body['error']['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['error']['data']['correlation_id']);

        // Sanitized: no driver detail, no exception class, no table name.
        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('mcp_approval_event', $raw);
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('Exception', $raw);

        // One honest infrastructure-refusal record; never a double terminal.
        $rows = $this->ledger();
        self::assertCount(1, $rows);
        self::assertSame('recorded', $rows[0]['event_type']);
        self::assertSame(AuditStage::AuditUnavailableRefused->value, $rows[0]['stage']);
    }

    #[Test]
    public function a_reserve_failure_refuses_the_call_and_leaves_the_approval_unconsumed(): void
    {
        $requestId = $this->approvedRequestId();

        $tableless = DBALDatabase::createSqlite();
        $body = $this->decode($this->callTool(
            $this->endpoint(ledger: new DatabaseStrictAuditLedger($tableless)),
            meta: self::meta($requestId),
        ));

        self::assertSame(0, self::$mutations);
        // The injected failure is the AUDIT ledger, not the approval store.
        // While both refusals shared -32002 the distinction was unobservable
        // from the wire; the two codes now say which subsystem failed (#2561).
        self::assertSame(McpErrorCode::AUDIT_TRAIL_UNAVAILABLE, $body['error']['code']);
        self::assertSame(
            ApprovalStatus::Approved,
            $this->store->find($requestId)?->status,
            'Reserve failed before consume, so the approval must remain spendable.',
        );
    }

    #[Test]
    public function losing_the_consume_race_refuses_execution_and_finalizes_honestly(): void
    {
        $requestId = $this->approvedRequestId();

        // Deterministic race: a concurrent winner consumes between this
        // request's find() and its consume().
        $store = new ConsumeRaceStore($this->store, $requestId);

        $dispatcher = new EventDispatcher();
        $terminalStages = [];
        $dispatcher->addListener(McpDispatchEvent::NAME, static function (McpDispatchEvent $event) use (&$terminalStages): void {
            if ($event->stage !== null && $event->stage !== AuditStage::RequestAccepted->value) {
                $terminalStages[] = $event->stage;
            }
        });

        $body = $this->decode($this->callTool($this->endpoint(store: $store, dispatcher: $dispatcher), meta: self::meta($requestId)));

        self::assertSame(0, self::$mutations, 'A lost consume race must never execute.');
        self::assertSame(-32004, $body['error']['code']);

        // The reservation is finalized as approval_refused — a pair, not a
        // dangling reservation and not an extra single record.
        $rows = $this->ledger();
        $last = array_slice($rows, -2);
        self::assertSame('reserved', $last[0]['event_type']);
        self::assertSame('finalized', $last[1]['event_type']);
        self::assertSame(AuditStage::ApprovalRefused->value, $last[1]['stage']);

        self::assertSame(
            [AuditStage::ApprovalRefused->value],
            $terminalStages,
            'Exactly one terminal projection.',
        );
    }

    #[Test]
    public function a_store_failure_during_consume_fails_closed_with_an_honest_finalization(): void
    {
        $requestId = $this->approvedRequestId();

        $store = new ThrowingConsumeStore($this->store);

        $response = $this->callTool($this->endpoint(store: $store), meta: self::meta($requestId));
        $body = $this->decode($response);

        self::assertSame(0, self::$mutations);
        self::assertSame(McpErrorCode::APPROVAL_STORE_UNAVAILABLE, $body['error']['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['error']['data']['correlation_id']);
        self::assertStringNotContainsString('Exception', (string) $response->getContent());

        // The reservation exists and is finalized as an infrastructure refusal.
        $rows = $this->ledger();
        $last = array_slice($rows, -2);
        self::assertSame('reserved', $last[0]['event_type']);
        self::assertSame('finalized', $last[1]['event_type']);
        self::assertSame(AuditStage::AuditUnavailableRefused->value, $last[1]['stage']);

        // The store never consumed, so the approval remains spendable.
        self::assertSame(ApprovalStatus::Approved, $this->store->find($requestId)?->status);
    }

    // ------------------------------------- nonconforming third-party stores

    /**
     * The interface promises typed {@see ApprovalStoreException}s, but a
     * third-party adapter that breaks that contract and throws anything else
     * must STILL fail closed: no execution, the sanitized refusal body, and
     * exactly one honest terminal record — never an uncaught crash.
     */
    #[Test]
    public function a_nonconforming_store_error_at_open_fails_closed_before_any_execution(): void
    {
        $store = new NonconformingThrowingStore($this->store, 'open');

        $response = $this->callTool($this->endpoint(store: $store));
        $body = $this->decode($response);

        self::assertSame(0, self::$mutations, 'The tool MUST NOT run when the store misbehaves.');
        self::assertSame(McpErrorCode::APPROVAL_STORE_UNAVAILABLE, $body['error']['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['error']['data']['correlation_id']);

        // Sanitized: the exception's message, class, and trace never leave.
        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('third_party_approvals', $raw);
        self::assertStringNotContainsString('Exception', $raw);
        self::assertStringNotContainsString('#0', $raw);

        // One honest single-record terminal — nothing was reserved yet.
        $rows = $this->ledger();
        self::assertCount(1, $rows);
        self::assertSame('recorded', $rows[0]['event_type']);
        self::assertSame(AuditStage::AuditUnavailableRefused->value, $rows[0]['stage']);
    }

    #[Test]
    public function a_nonconforming_store_error_at_find_fails_closed_with_one_terminal_stage(): void
    {
        $store = new NonconformingThrowingStore($this->store, 'find');

        $dispatcher = new EventDispatcher();
        $terminalStages = [];
        $dispatcher->addListener(McpDispatchEvent::NAME, static function (McpDispatchEvent $event) use (&$terminalStages): void {
            if ($event->stage !== null && $event->stage !== AuditStage::RequestAccepted->value) {
                $terminalStages[] = $event->stage;
            }
        });

        $body = $this->decode($this->callTool(
            $this->endpoint(store: $store, dispatcher: $dispatcher),
            meta: self::meta('apr_' . str_repeat('0', 32)),
        ));

        self::assertSame(0, self::$mutations);
        self::assertSame(McpErrorCode::APPROVAL_STORE_UNAVAILABLE, $body['error']['code']);

        $rows = $this->ledger();
        self::assertCount(1, $rows);
        self::assertSame('recorded', $rows[0]['event_type']);
        self::assertSame(AuditStage::AuditUnavailableRefused->value, $rows[0]['stage']);

        self::assertSame(
            [AuditStage::AuditUnavailableRefused->value],
            $terminalStages,
            'Exactly one terminal projection.',
        );
    }

    #[Test]
    public function a_nonconforming_store_error_at_consume_fails_closed_with_an_honest_finalization(): void
    {
        $requestId = $this->approvedRequestId();
        $store = new NonconformingThrowingStore($this->store, 'consume');

        $dispatcher = new EventDispatcher();
        $terminalStages = [];
        $dispatcher->addListener(McpDispatchEvent::NAME, static function (McpDispatchEvent $event) use (&$terminalStages): void {
            if ($event->stage !== null && $event->stage !== AuditStage::RequestAccepted->value) {
                $terminalStages[] = $event->stage;
            }
        });

        $response = $this->callTool($this->endpoint(store: $store, dispatcher: $dispatcher), meta: self::meta($requestId));
        $body = $this->decode($response);

        self::assertSame(0, self::$mutations);
        self::assertSame(McpErrorCode::APPROVAL_STORE_UNAVAILABLE, $body['error']['code']);

        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('Exception', $raw);

        // The reservation exists and is finalized honestly — a pair, plus
        // exactly one terminal projection.
        $rows = $this->ledger();
        $last = array_slice($rows, -2);
        self::assertSame('reserved', $last[0]['event_type']);
        self::assertSame('finalized', $last[1]['event_type']);
        self::assertSame(AuditStage::AuditUnavailableRefused->value, $last[1]['stage']);
        self::assertSame([AuditStage::AuditUnavailableRefused->value], $terminalStages);

        // The store never consumed, so the approval remains spendable.
        self::assertSame(ApprovalStatus::Approved, $this->store->find($requestId)?->status);
    }
}

/** Mutable UTC test clock. */
final class TestClock implements EntityClockInterface
{
    public function __construct(private \DateTimeImmutable $now) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}

/** Shared, append-only call-order log. */
final class SequenceLog
{
    /** @var list<string> */
    private array $entries = [];

    public function append(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->entries;
    }
}

/** Records ledger call order into a shared sequence. */
final class SequenceRecordingLedger implements StrictAuditLedgerInterface
{
    public function __construct(
        private readonly StrictAuditLedgerInterface $inner,
        private readonly SequenceLog $log,
    ) {}

    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt
    {
        $this->log->append('reserve');

        return $this->inner->reserve($reservation);
    }

    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void
    {
        $this->log->append('finalize');
        $this->inner->finalize($receipt, $stage, $metadata);
    }

    public function record(StrictAuditReservation $reservation, AuditStage $stage): void
    {
        $this->inner->record($reservation, $stage);
    }
}

/** Records consume order into a shared sequence. */
final class SequenceRecordingStore implements OperationApprovalStoreInterface
{
    public function __construct(
        private readonly OperationApprovalStoreInterface $inner,
        private readonly SequenceLog $log,
    ) {}

    public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
    {
        return $this->inner->open($tuple, $correlationId, $safeArguments);
    }

    public function find(string $requestId): ?ApprovalRequest
    {
        return $this->inner->find($requestId);
    }

    public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
    {
        return $this->inner->decide($requestId, $approved, $operatorUid, $reason);
    }

    public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
    {
        $this->log->append('consume');

        return $this->inner->consume($requestId, $receiptId, $retryCorrelationId);
    }

    public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
    {
        return $this->inner->listPending($limit, $cursor);
    }
}

/** A concurrent winner consumes the approval just before this request can. */
final class ConsumeRaceStore implements OperationApprovalStoreInterface
{
    public function __construct(
        private readonly OperationApprovalStoreInterface $inner,
        private readonly string $racedRequestId,
    ) {}

    public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
    {
        return $this->inner->open($tuple, $correlationId, $safeArguments);
    }

    public function find(string $requestId): ?ApprovalRequest
    {
        return $this->inner->find($requestId);
    }

    public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
    {
        return $this->inner->decide($requestId, $approved, $operatorUid, $reason);
    }

    public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
    {
        if ($requestId === $this->racedRequestId) {
            // The concurrent winner lands first, through the REAL store.
            $this->inner->consume($requestId, 'race-winner-receipt', 'beefbeefbeefbeef');
        }

        return $this->inner->consume($requestId, $receiptId, $retryCorrelationId);
    }

    public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
    {
        return $this->inner->listPending($limit, $cursor);
    }
}

/**
 * A third-party store that VIOLATES the interface's typed-exception contract:
 * it throws a raw RuntimeException (driver detail in the message) instead of
 * {@see ApprovalStoreException} at the configured call.
 */
final class NonconformingThrowingStore implements OperationApprovalStoreInterface
{
    public function __construct(
        private readonly OperationApprovalStoreInterface $inner,
        private readonly string $failAt,
    ) {}

    public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
    {
        if ($this->failAt === 'open') {
            throw new \RuntimeException('SQLSTATE[42S02]: no such table: third_party_approvals');
        }

        return $this->inner->open($tuple, $correlationId, $safeArguments);
    }

    public function find(string $requestId): ?ApprovalRequest
    {
        if ($this->failAt === 'find') {
            throw new \RuntimeException('SQLSTATE[42S02]: no such table: third_party_approvals');
        }

        return $this->inner->find($requestId);
    }

    public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
    {
        return $this->inner->decide($requestId, $approved, $operatorUid, $reason);
    }

    public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
    {
        if ($this->failAt === 'consume') {
            throw new \RuntimeException('SQLSTATE[42S02]: no such table: third_party_approvals');
        }

        return $this->inner->consume($requestId, $receiptId, $retryCorrelationId);
    }

    public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
    {
        return $this->inner->listPending($limit, $cursor);
    }
}

/** The approval store's own infrastructure fails exactly at consume time. */
final class ThrowingConsumeStore implements OperationApprovalStoreInterface
{
    public function __construct(private readonly OperationApprovalStoreInterface $inner) {}

    public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
    {
        return $this->inner->open($tuple, $correlationId, $safeArguments);
    }

    public function find(string $requestId): ?ApprovalRequest
    {
        return $this->inner->find($requestId);
    }

    public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
    {
        return $this->inner->decide($requestId, $approved, $operatorUid, $reason);
    }

    public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
    {
        throw new ApprovalStoreException('The approval consumption could not be made durable; the operation must not proceed.');
    }

    public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
    {
        return $this->inner->listPending($limit, $cursor);
    }
}
