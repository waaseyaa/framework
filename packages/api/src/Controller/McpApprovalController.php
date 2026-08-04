<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\Api\McpAdmin\McpApprovalDecisionRecorded;
use Waaseyaa\Foundation\Audit\Approval\ApprovalAlreadyDecidedException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Admin decision surface for the MCP write-tier human-approval gate
 * (#2177 F1 C1b).
 *
 * Two actions behind `/api/mcp/approvals`:
 *   - `index`  — `GET /api/mcp/approvals`: one bounded page of live pending
 *     requests via {@see OperationApprovalStoreInterface::listPending()}.
 *   - `decide` — `POST /api/mcp/approvals/{id}/decision`: durably approve or
 *     deny a pending request.
 *
 * Access control lives on the routes (`_authenticated`, `_session
 * ['waaseyaa_uid']`, `_permission`, `_csrf`) and is NOT re-checked here
 * (NFR-001 / DIR-004) — with two deliberate controller-level exceptions that
 * the route layer cannot express:
 *
 *   - **Origin gate (decide only).** Defense-in-depth on top of the CSRF
 *     token, exactly as `docs/specs/mcp-endpoint.md` defers it to this
 *     controller: the `Origin` header must be present and byte-identical to
 *     either the request's own origin or one entry of the deployment's
 *     `cors_origins` allowlist. No substring, suffix, wildcard, regex, or
 *     host-only matching.
 *   - **Separation of duties (decide only).** The server-derived operator
 *     must not be the approval request's requesting principal. Sameness is
 *     the exact identity semantics the tuple was built with — the MCP
 *     endpoint sets `principalKey = (string) $principal->id()` — so the
 *     comparison is `(string) $operatorUid === $tuple->principalKey`, never a
 *     display-string heuristic. Overridable only by the strict-boolean
 *     `mcp.write_tier.approval.allow_self_approval` (default false).
 *
 * The operator uid is derived exclusively server-side through
 * {@see DecisionAccountResolver} from the request's middleware-set
 * attributes; request JSON is never consulted for identity, and a body
 * carrying identity-shaped members is refused outright.
 *
 * The store is resolved lazily per request (a deployment that never uses the
 * write tier pays nothing at boot) and fail-closed: both "not bound" and
 * "bound but failing" yield the same sanitized 503, distinguished only in the
 * log. Responses never carry exception detail or database messages.
 */
final class McpApprovalController
{
    private const REQUEST_ID_PATTERN = '/^apr_[0-9a-f]{32}$/';

    /** The only members a decision body may carry. */
    private const ALLOWED_BODY_KEYS = ['decision', 'reason'];

    private readonly LoggerInterface $logger;

    /**
     * @param \Closure $storeResolver lazy accessor producing the
     *        OperationApprovalStoreInterface; throws a `RuntimeException` whose
     *        message starts with "No binding registered for" when the port is
     *        unbound (the container's sentinel), anything else when a bound
     *        store fails. Deliberately untyped so the controller re-checks the
     *        produced value at runtime and fails closed on a nonconforming
     *        resolver.
     * @param list<string> $allowedOrigins exact-match cross-origin allowlist
     *        (the deployment's `cors_origins`); the request's own origin is
     *        always additionally accepted
     * @param bool $allowSelfApproval `mcp.write_tier.approval.allow_self_approval`
     */
    public function __construct(
        private readonly \Closure $storeResolver,
        private readonly array $allowedOrigins = [],
        private readonly bool $allowSelfApproval = false,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * `GET /api/mcp/approvals` — one bounded page of pending requests.
     */
    public function index(Request $request): Response
    {
        $limitParam = $request->query->get('limit');
        if ($limitParam === null) {
            $limit = OperationApprovalStoreInterface::PENDING_PAGE_DEFAULT_LIMIT;
        } elseif (preg_match('/^[0-9]{1,4}$/', $limitParam) === 1
            && (int) $limitParam >= 1
            && (int) $limitParam <= OperationApprovalStoreInterface::PENDING_PAGE_MAX_LIMIT
        ) {
            $limit = (int) $limitParam;
        } else {
            // The 1..100 range is validated here, so an out-of-range limit
            // never reaches the store.
            return self::invalidPagination();
        }

        $cursorParam = $request->query->get('cursor');
        $cursor = \is_string($cursorParam) && $cursorParam !== '' ? $cursorParam : null;

        $store = $this->resolveStore();
        if ($store === null) {
            return self::storeUnavailable();
        }

        try {
            $page = $store->listPending($limit, $cursor);
        } catch (\InvalidArgumentException) {
            // Per the port contract this is pre-query cursor validation (the
            // limit was range-checked above). The response is STATIC — a
            // nonconforming or hostile adapter's exception text must never
            // reach the caller.
            return self::invalidPagination();
        } catch (\Throwable $e) {
            $this->logStoreFailure('mcp.approval_store_unavailable', $e);

            return self::storeUnavailable();
        }

        return new JsonResponse(
            [
                'data' => array_map(self::serializeRequest(...), $page->requests),
                'meta' => [
                    'limit' => $limit,
                    'nextCursor' => $page->nextCursor,
                ],
            ],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    /**
     * `POST /api/mcp/approvals/{id}/decision` — durably approve or deny.
     */
    public function decide(Request $request, string $id): Response
    {
        if (!$this->originAllowed($request)) {
            return self::error(403, 'Forbidden', 'The request origin is not allowed to decide approvals.');
        }

        $operatorUid = $this->operatorUid($request);
        if ($operatorUid === null) {
            return self::error(403, 'Forbidden', 'A decision requires a resolvable operator identity.');
        }

        $body = $this->decisionBody($request);
        if ($body instanceof Response) {
            return $body;
        }
        [$approved, $reason] = $body;

        if (preg_match(self::REQUEST_ID_PATTERN, $id) !== 1) {
            // The id shape is public in every challenge, so refusing a
            // malformed id without a store roundtrip reveals nothing — and the
            // response is byte-identical to an unknown id.
            return self::unknownRequest();
        }

        $store = $this->resolveStore();
        if ($store === null) {
            return self::storeUnavailable();
        }

        try {
            $current = $store->find($id);
        } catch (\Throwable $e) {
            $this->logStoreFailure('mcp.approval_store_unavailable', $e);

            return self::storeUnavailable();
        }
        if ($current === null) {
            return self::unknownRequest();
        }
        if ($current->status !== ApprovalStatus::Pending) {
            return self::notPending();
        }

        if (!$this->allowSelfApproval && (string) $operatorUid === $current->tuple->principalKey) {
            return self::error(
                403,
                'Forbidden',
                'Separation of duties: the requesting principal cannot decide its own approval.',
            );
        }

        try {
            $decided = $store->decide($id, $approved, $operatorUid, $reason);
        } catch (ApprovalAlreadyDecidedException) {
            return self::notPending();
        } catch (\Throwable $e) {
            // The reason and operator uid — the only inputs decide() may
            // reject with \InvalidArgumentException per the port contract —
            // were both validated above, so an InvalidArgumentException here
            // can only come from a nonconforming adapter: it takes the same
            // fail-closed sanitized path as any other store failure. Nothing
            // an adapter throws is ever echoed to the caller.
            return $this->classifyDecideFailure($store, $id, $e);
        }

        $this->projectDecision($decided, $approved, $operatorUid, $reason);

        return new Response(status: 204);
    }

    // ------------------------------------------------------------------
    // Origin + operator derivation
    // ------------------------------------------------------------------

    /**
     * Exact-match origin gate: the `Origin` header must be present and equal
     * (strict string identity) to the request's own scheme://host[:port] or
     * to one configured allowlist entry.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (!\is_string($origin) || $origin === '') {
            return false;
        }

        return $origin === $request->getSchemeAndHttpHost()
            || \in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * Server-derived operator uid, or null when no safe identity exists.
     *
     * Session identities carry positive integer uids; anything else — no
     * resolvable principal, a non-integer (service/opaque) id, or a
     * non-positive sentinel — fails closed. Request JSON is never consulted.
     */
    private function operatorUid(Request $request): ?int
    {
        $principal = DecisionAccountResolver::resolve(
            $request->attributes->get('_authorization_principal'),
            $request->attributes->get('_account'),
        );
        if (!$principal instanceof AuthorizationPrincipalInterface) {
            return null;
        }

        $uid = $principal->id();

        return \is_int($uid) && $uid > 0 ? $uid : null;
    }

    // ------------------------------------------------------------------
    // Body validation
    // ------------------------------------------------------------------

    /**
     * Validate the decision body into `[bool $approved, ?string $reason]`,
     * or the 400 response refusing it.
     *
     * @return array{0: bool, 1: ?string}|Response
     */
    private function decisionBody(Request $request): array|Response
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error(400, 'Bad Request', 'The request body must be a JSON object.');
        }
        if (!\is_array($body)) {
            return self::error(400, 'Bad Request', 'The request body must be a JSON object.');
        }

        // Only `decision` and `reason` are recognised. Anything else —
        // including any client-supplied operator identity — is refused, not
        // silently ignored, so a spoofing attempt is visible to its author.
        $unknown = array_diff(array_keys($body), self::ALLOWED_BODY_KEYS);
        if ($unknown !== []) {
            return self::error(400, 'Bad Request', 'The request body carries unrecognised members.');
        }

        $decision = $body['decision'] ?? null;
        if ($decision !== 'approve' && $decision !== 'deny') {
            return self::error(400, 'Bad Request', 'The decision must be exactly "approve" or "deny".');
        }

        $rawReason = $body['reason'] ?? null;
        if ($rawReason !== null && !\is_string($rawReason)) {
            return self::error(400, 'Bad Request', 'The reason must be a string when present.');
        }
        try {
            $reason = ApprovalRequest::normalizeDecisionReason($rawReason);
        } catch (\InvalidArgumentException) {
            // Static text mirroring the normalizer's contract — no exception
            // message is ever echoed, even framework-owned ones.
            return self::error(400, 'Bad Request', sprintf(
                'The reason must be a single line of at most %d characters with no control characters.',
                ApprovalRequest::MAX_DECISION_REASON_LENGTH,
            ));
        }

        return [$decision === 'approve', $reason];
    }

    // ------------------------------------------------------------------
    // Store access + failure classification
    // ------------------------------------------------------------------

    /**
     * Resolve the approval store lazily, classifying "not bound" apart from
     * "bound but failing" in the log while both fail closed identically.
     */
    private function resolveStore(): ?OperationApprovalStoreInterface
    {
        try {
            $store = ($this->storeResolver)();
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'No binding registered for')) {
                $this->logger->warning('mcp.approval_store_unbound', [
                    'detail' => 'OperationApprovalStoreInterface is not bound; is waaseyaa/audit installed?',
                ]);
            } else {
                $this->logStoreFailure('mcp.approval_store_unavailable', $e);
            }

            return null;
        }

        if (!$store instanceof OperationApprovalStoreInterface) {
            $this->logger->warning('mcp.approval_store_unbound', [
                'detail' => 'The store resolver produced a non-store value.',
            ]);

            return null;
        }

        return $store;
    }

    /**
     * A `decide()` store failure after the pending pre-check: re-read once to
     * distinguish a lost race (now decided/expired → 409, gone → 404) from a
     * genuine store outage (→ 503). The re-read failing too is an outage.
     */
    private function classifyDecideFailure(OperationApprovalStoreInterface $store, string $id, \Throwable $e): Response
    {
        try {
            $current = $store->find($id);
        } catch (\Throwable) {
            $this->logStoreFailure('mcp.approval_store_unavailable', $e);

            return self::storeUnavailable();
        }

        if ($current === null) {
            return self::unknownRequest();
        }
        if ($current->status !== ApprovalStatus::Pending) {
            return self::notPending();
        }

        $this->logStoreFailure('mcp.approval_store_unavailable', $e);

        return self::storeUnavailable();
    }

    /** Safe log metadata only: exception class, never message or trace. */
    private function logStoreFailure(string $event, \Throwable $e): void
    {
        $this->logger->warning($event, ['exception_class' => $e::class]);
    }

    // ------------------------------------------------------------------
    // Audit projection
    // ------------------------------------------------------------------

    /**
     * Best-effort projection AFTER the durable decision: a failure here is
     * logged and swallowed — the `decided` row is already the evidence of
     * record, and the response must keep reporting the decision as made.
     */
    private function projectDecision(ApprovalRequest $decided, bool $approved, int $operatorUid, ?string $reason): void
    {
        if ($this->dispatcher === null) {
            return;
        }

        try {
            $this->dispatcher->dispatch(
                new McpApprovalDecisionRecorded(
                    requestId: $decided->id,
                    operatorUid: $operatorUid,
                    approved: $approved,
                    reason: $reason,
                    correlationId: $decided->correlationId,
                ),
                McpApprovalDecisionRecorded::EVENT_NAME,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('mcp.approval_audit_projection_failed', [
                'exception_class' => $e::class,
                'request_id' => $decided->id,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Response shaping — deterministic, non-secret-bearing
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function serializeRequest(ApprovalRequest $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'principal' => $request->tuple->principalKey,
            'surface' => $request->tuple->surface,
            'operation' => $request->tuple->operation,
            'argumentsFingerprint' => $request->tuple->argumentsFingerprint,
            'correlationId' => $request->correlationId,
            'safeArguments' => $request->safeArguments,
            'requestedAt' => $request->requestedAt->format(\DateTimeInterface::ATOM),
            'expiresAt' => $request->expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** One static body for every pagination-input refusal — never adapter text. */
    private static function invalidPagination(): JsonResponse
    {
        return self::error(400, 'Bad Request', sprintf(
            'The pagination parameters are invalid: limit must be an integer between 1 and %d, '
            . 'and cursor must be exactly a previous page\'s nextCursor.',
            OperationApprovalStoreInterface::PENDING_PAGE_MAX_LIMIT,
        ));
    }

    /** Byte-identical for malformed and unknown ids — not a store oracle. */
    private static function unknownRequest(): JsonResponse
    {
        return self::error(404, 'Not Found', 'The approval request does not exist.');
    }

    /** One deterministic conflict body for decided, expired and consumed. */
    private static function notPending(): JsonResponse
    {
        return self::error(409, 'Conflict', 'The approval request is not pending.');
    }

    private static function storeUnavailable(): JsonResponse
    {
        return self::error(503, 'Service Unavailable', 'The approval store is unavailable.');
    }

    private static function error(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            [
                'errors' => [[
                    'status' => (string) $status,
                    'title' => $title,
                    'detail' => $detail,
                ]],
            ],
            $status,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }
}
