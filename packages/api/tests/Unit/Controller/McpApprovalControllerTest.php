<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\Controller\McpApprovalController;
use Waaseyaa\Api\McpAdmin\McpApprovalDecisionRecorded;
use Waaseyaa\Foundation\Audit\Approval\ApprovalAlreadyDecidedException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStoreException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;

#[CoversClass(McpApprovalController::class)]
final class McpApprovalControllerTest extends TestCase
{
    private const REQUEST_ID = 'apr_0123456789abcdef0123456789abcdef';
    private const ALLOWED_ORIGIN = 'https://admin.example.test';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function pendingRequest(
        string $principalKey = '9',
        ApprovalStatus $status = ApprovalStatus::Pending,
        ?int $decidedByUid = null,
    ): ApprovalRequest {
        $requestedAt = new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone('UTC'));

        return new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: new ApprovalTuple($principalKey, 'mcp.write', 'delete_page', str_repeat('a', 64)),
            status: $status,
            correlationId: 'corr-1',
            safeArguments: ['page' => 'redacted-title'],
            requestedAt: $requestedAt,
            expiresAt: $requestedAt->modify('+15 minutes'),
            decidedByUid: $decidedByUid,
            decidedAt: $decidedByUid !== null ? $requestedAt->modify('+1 minute') : null,
        );
    }

    /**
     * @param array<string, mixed> $behaviour keys: find, decide, listPending — closures
     */
    private function store(array $behaviour = []): OperationApprovalStoreInterface
    {
        return new class ($behaviour) implements OperationApprovalStoreInterface {
            /** @var list<array{0: string, 1: array<int, mixed>}> */
            public array $calls = [];

            public function __construct(private readonly array $behaviour) {}

            public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
            {
                throw new \LogicException('open() is not part of the admin surface.');
            }

            public function find(string $requestId): ?ApprovalRequest
            {
                $this->calls[] = ['find', [$requestId]];
                $fn = $this->behaviour['find'] ?? static fn(): ?ApprovalRequest => null;

                return $fn($requestId);
            }

            public function listPending(int $limit = self::PENDING_PAGE_DEFAULT_LIMIT, ?string $cursor = null): ApprovalRequestPage
            {
                $this->calls[] = ['listPending', [$limit, $cursor]];
                $fn = $this->behaviour['listPending'] ?? static fn(): ApprovalRequestPage => new ApprovalRequestPage([]);

                return $fn($limit, $cursor);
            }

            public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
            {
                $this->calls[] = ['decide', [$requestId, $approved, $operatorUid, $reason]];
                $fn = $this->behaviour['decide'] ?? static function (): ApprovalRequest {
                    throw new \LogicException('decide behaviour not configured');
                };

                return $fn($requestId, $approved, $operatorUid, $reason);
            }

            public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
            {
                throw new \LogicException('consume() is not part of the admin surface.');
            }
        };
    }

    private function controller(
        ?OperationApprovalStoreInterface $store = null,
        bool $allowSelfApproval = false,
        ?EventDispatcher $dispatcher = null,
        ?\Closure $storeResolver = null,
    ): McpApprovalController {
        $resolver = $storeResolver ?? static function () use ($store): OperationApprovalStoreInterface {
            if ($store === null) {
                throw new \RuntimeException('No binding registered for ' . OperationApprovalStoreInterface::class . '.');
            }

            return $store;
        };

        return new McpApprovalController(
            storeResolver: $resolver,
            allowedOrigins: [self::ALLOWED_ORIGIN],
            allowSelfApproval: $allowSelfApproval,
            dispatcher: $dispatcher,
        );
    }

    private function operator(int $uid = 42): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($uid, true, ['editor'], ['mcp.approval.view', 'mcp.approval.decide'], 'g1');
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    private function decisionRequest(
        array|string|null $body = ['decision' => 'approve'],
        ?string $origin = 'http://localhost',
        ?AuthorizationPrincipal $principal = null,
    ): Request {
        $content = \is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;
        $request = Request::create(
            'http://localhost/api/mcp/approvals/' . self::REQUEST_ID . '/decision',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );
        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }
        $request->attributes->set('_authorization_principal', $principal ?? $this->operator());

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------
    // GET /api/mcp/approvals — pending queue
    // ------------------------------------------------------------------

    #[Test]
    public function index_returns_a_serialized_page_of_pending_requests(): void
    {
        $store = $this->store([
            'listPending' => fn(): ApprovalRequestPage => new ApprovalRequestPage([$this->pendingRequest()], 'cursor-2'),
        ]);
        $response = $this->controller($store)->index(Request::create('/api/mcp/approvals', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        self::assertSame(self::REQUEST_ID, $row['id']);
        self::assertSame('pending', $row['status']);
        self::assertSame('mcp.write', $row['surface']);
        self::assertSame('delete_page', $row['operation']);
        self::assertSame('9', $row['principal']);
        self::assertSame(['page' => 'redacted-title'], $row['safeArguments']);
        self::assertArrayHasKey('requestedAt', $row);
        self::assertArrayHasKey('expiresAt', $row);
        self::assertSame('cursor-2', $payload['meta']['nextCursor']);
    }

    #[Test]
    public function index_passes_validated_limit_and_cursor_to_the_store(): void
    {
        $store = $this->store();
        $request = Request::create('/api/mcp/approvals?limit=5&cursor=abc', 'GET');

        $this->controller($store)->index($request);

        self::assertSame([['listPending', [5, 'abc']]], $store->calls);
    }

    #[Test]
    public function index_defaults_the_limit_when_absent(): void
    {
        $store = $this->store();

        $this->controller($store)->index(Request::create('/api/mcp/approvals', 'GET'));

        self::assertSame([['listPending', [OperationApprovalStoreInterface::PENDING_PAGE_DEFAULT_LIMIT, null]]], $store->calls);
    }

    #[Test]
    public function index_rejects_a_non_integer_limit_without_touching_the_store(): void
    {
        $store = $this->store();

        $response = $this->controller($store)->index(Request::create('/api/mcp/approvals?limit=abc', 'GET'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $store->calls);
    }

    #[Test]
    public function index_rejects_an_out_of_range_limit_without_touching_the_store(): void
    {
        // The 1..100 range is the controller's own validation now — an
        // out-of-range limit never reaches the store.
        $store = $this->store();

        foreach (['limit=0', 'limit=101', 'limit=9999'] as $query) {
            $response = $this->controller($store)->index(Request::create('/api/mcp/approvals?' . $query, 'GET'));
            self::assertSame(400, $response->getStatusCode(), $query);
        }
        self::assertSame([], $store->calls);
    }

    #[Test]
    public function index_maps_a_malformed_cursor_to_a_static_400_body(): void
    {
        $store = $this->store([
            'listPending' => static function (): ApprovalRequestPage {
                throw new \InvalidArgumentException('The pending-approval cursor is malformed.');
            },
        ]);

        $response = $this->controller($store)->index(Request::create('/api/mcp/approvals?cursor=%%%', 'GET'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('pagination parameters are invalid', (string) $response->getContent());
    }

    #[Test]
    public function index_never_echoes_a_hostile_store_validation_message(): void
    {
        // A nonconforming or malicious adapter may put arbitrary text in an
        // InvalidArgumentException; the caller must receive the static
        // pagination refusal, never the adapter's message.
        $store = $this->store([
            'listPending' => static function (): ApprovalRequestPage {
                throw new \InvalidArgumentException('SECRET-DSN mysql://root:hunter2@db/prod');
            },
        ]);

        $response = $this->controller($store)->index(Request::create('/api/mcp/approvals?cursor=abc', 'GET'));

        self::assertSame(400, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringNotContainsString('SECRET-DSN', $content);
        self::assertStringNotContainsString('hunter2', $content);
    }

    #[Test]
    public function index_sanitizes_a_store_failure_to_503(): void
    {
        $store = $this->store([
            'listPending' => static function (): ApprovalRequestPage {
                throw new ApprovalStoreException('SQLSTATE[HY000]: secret dsn user password');
            },
        ]);

        $response = $this->controller($store)->index(Request::create('/api/mcp/approvals', 'GET'));

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        self::assertStringNotContainsString('password', (string) $response->getContent());
    }

    #[Test]
    public function index_fails_closed_when_the_store_is_not_bound(): void
    {
        $response = $this->controller(store: null)->index(Request::create('/api/mcp/approvals', 'GET'));

        self::assertSame(503, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // POST /api/mcp/approvals/{id}/decision — origin gate
    // ------------------------------------------------------------------

    #[Test]
    public function decide_refuses_a_missing_origin(): void
    {
        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(origin: null), self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function decide_refuses_a_foreign_origin(): void
    {
        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(origin: 'https://evil.example'), self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function decide_refuses_a_suffix_matched_origin(): void
    {
        // Exact match only: a origin that merely ends with the allowlisted
        // origin must be refused.
        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(origin: 'https://evil-admin.example.test'), self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function decide_refuses_a_prefix_extended_allowlisted_origin(): void
    {
        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(origin: self::ALLOWED_ORIGIN . '.evil.example'), self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function decide_accepts_the_exact_request_origin(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Approved, decidedByUid: 42),
        ]);

        $response = $this->controller($store)
            ->decide($this->decisionRequest(origin: 'http://localhost'), self::REQUEST_ID);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function decide_accepts_an_exact_allowlisted_cross_origin(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Approved, decidedByUid: 42),
        ]);

        $response = $this->controller($store)
            ->decide($this->decisionRequest(origin: self::ALLOWED_ORIGIN), self::REQUEST_ID);

        self::assertSame(204, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Operator identity — server-derived only
    // ------------------------------------------------------------------

    #[Test]
    public function decide_fails_closed_without_a_resolvable_principal(): void
    {
        $request = $this->decisionRequest();
        $request->attributes->remove('_authorization_principal');

        $response = $this->controller($this->store())->decide($request, self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function decide_fails_closed_for_a_non_integer_principal_id(): void
    {
        $principal = new AuthorizationPrincipal('svc-token-1', true, [], ['mcp.approval.decide'], 'g1');

        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(principal: $principal), self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function decide_uses_the_server_derived_operator_uid(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Approved, decidedByUid: 42),
        ]);

        $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        $decideCalls = array_values(array_filter($store->calls, static fn(array $c): bool => $c[0] === 'decide'));
        self::assertCount(1, $decideCalls);
        self::assertSame(42, $decideCalls[0][1][2], 'Operator uid must come from the resolved principal.');
    }

    #[Test]
    public function decide_rejects_a_body_carrying_a_client_supplied_operator_identity(): void
    {
        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(body: ['decision' => 'approve', 'operator_uid' => 1]), self::REQUEST_ID);

        self::assertSame(400, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Body validation
    // ------------------------------------------------------------------

    #[Test]
    public function decide_rejects_malformed_json(): void
    {
        $response = $this->controller($this->store())
            ->decide($this->decisionRequest(body: '{not json'), self::REQUEST_ID);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function decide_rejects_a_decision_that_is_not_exactly_approve_or_deny(): void
    {
        foreach (['Approve', 'DENY', 'yes', '', 1, true, null] as $decision) {
            $response = $this->controller($this->store())
                ->decide($this->decisionRequest(body: ['decision' => $decision]), self::REQUEST_ID);

            self::assertSame(400, $response->getStatusCode(), var_export($decision, true));
        }
    }

    #[Test]
    public function decide_rejects_an_invalid_reason_before_touching_the_store(): void
    {
        $store = $this->store();

        $response = $this->controller($store)
            ->decide($this->decisionRequest(body: ['decision' => 'deny', 'reason' => "line\nbreak"]), self::REQUEST_ID);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $store->calls);
    }

    #[Test]
    public function decide_normalizes_the_reason_before_deciding(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Denied, decidedByUid: 42),
        ]);

        $this->controller($store)
            ->decide($this->decisionRequest(body: ['decision' => 'deny', 'reason' => '  spaced out  ']), self::REQUEST_ID);

        $decideCalls = array_values(array_filter($store->calls, static fn(array $c): bool => $c[0] === 'decide'));
        self::assertSame('spaced out', $decideCalls[0][1][3]);
        self::assertFalse($decideCalls[0][1][1], 'deny must map to approved=false');
    }

    // ------------------------------------------------------------------
    // Request state mapping
    // ------------------------------------------------------------------

    #[Test]
    public function decide_maps_a_malformed_request_id_to_404_without_a_store_roundtrip(): void
    {
        $store = $this->store();

        $response = $this->controller($store)->decide($this->decisionRequest(), 'not-an-id');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $store->calls);
    }

    #[Test]
    public function decide_maps_an_unknown_request_to_404(): void
    {
        $store = $this->store(['find' => static fn(): ?ApprovalRequest => null]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function decide_maps_an_already_decided_request_to_409(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Approved, decidedByUid: 7),
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function decide_maps_an_expired_request_to_409(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Expired),
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function decide_maps_a_decision_race_to_409(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => static function (): ApprovalRequest {
                throw new ApprovalAlreadyDecidedException('The approval request already carries a decision.');
            },
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function decide_treats_an_adapter_invalid_argument_exception_as_a_sanitized_outage(): void
    {
        // The controller validates the reason and derives the uid BEFORE
        // decide(), so per the contract a store InvalidArgumentException here
        // can only come from a nonconforming adapter. It must fail closed as
        // unavailable and its message — potentially hostile — must not leak.
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => static function (): ApprovalRequest {
                throw new \InvalidArgumentException('SECRET-DSN pgsql://svc:hunter2@db/audit');
            },
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(503, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringNotContainsString('SECRET-DSN', $content);
        self::assertStringNotContainsString('hunter2', $content);
    }

    #[Test]
    public function decide_sanitizes_a_store_failure_to_503(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => static function (): ApprovalRequest {
                throw new ApprovalStoreException('SQLSTATE[HY000] driver detail with dsn');
            },
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    #[Test]
    public function decide_fails_closed_when_the_store_is_not_bound(): void
    {
        $response = $this->controller(store: null)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function decide_treats_a_bound_but_failing_resolver_as_unavailable_not_unbound(): void
    {
        // A BOUND dependency whose factory throws must not be misreported as
        // "not bound" — same 503, but the resolver exception must not leak.
        $response = $this->controller(storeResolver: static function (): OperationApprovalStoreInterface {
            throw new ApprovalStoreException('bootstrap failure with connection string');
        })->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('connection string', (string) $response->getContent());
    }

    // ------------------------------------------------------------------
    // Separation of duties
    // ------------------------------------------------------------------

    #[Test]
    public function decide_refuses_self_approval_by_default(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(principalKey: '42'),
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(403, $response->getStatusCode());
        $decideCalls = array_filter($store->calls, static fn(array $c): bool => $c[0] === 'decide');
        self::assertSame([], $decideCalls, 'A refused self-approval must never reach decide().');
    }

    #[Test]
    public function decide_allows_self_approval_when_explicitly_configured(): void
    {
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(principalKey: '42'),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(
                principalKey: '42',
                status: ApprovalStatus::Approved,
                decidedByUid: 42,
            ),
        ]);

        $response = $this->controller($store, allowSelfApproval: true)
            ->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function decide_allows_a_distinct_operator_for_a_numeric_principal(): void
    {
        // principalKey '9' vs operator uid 42 — distinct identities.
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(principalKey: '9'),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(
                principalKey: '9',
                status: ApprovalStatus::Approved,
                decidedByUid: 42,
            ),
        ]);

        $response = $this->controller($store)->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(204, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Audit projection — best-effort, after the durable decision
    // ------------------------------------------------------------------

    #[Test]
    public function decide_dispatches_the_audit_projection_event_after_success(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = [];
        $dispatcher->addListener(
            McpApprovalDecisionRecorded::EVENT_NAME,
            static function (object $event) use (&$captured): void {
                $captured[] = $event;
            },
        );
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Approved, decidedByUid: 42),
        ]);

        $response = $this->controller($store, dispatcher: $dispatcher)
            ->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $captured);
        self::assertSame(self::REQUEST_ID, $captured[0]->requestId);
        self::assertSame(42, $captured[0]->operatorUid);
        self::assertTrue($captured[0]->approved);
        self::assertSame('corr-1', $captured[0]->correlationId);
    }

    #[Test]
    public function decide_still_succeeds_when_the_audit_projection_throws(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            McpApprovalDecisionRecorded::EVENT_NAME,
            static function (): void {
                throw new \RuntimeException('projection store on fire');
            },
        );
        $store = $this->store([
            'find' => fn(): ApprovalRequest => $this->pendingRequest(),
            'decide' => fn(): ApprovalRequest => $this->pendingRequest(status: ApprovalStatus::Approved, decidedByUid: 42),
        ]);

        $response = $this->controller($store, dispatcher: $dispatcher)
            ->decide($this->decisionRequest(), self::REQUEST_ID);

        self::assertSame(204, $response->getStatusCode());
    }
}
