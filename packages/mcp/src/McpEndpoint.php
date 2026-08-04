<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Audit\StrictAuditReceipt;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\Event\McpDispatchEvent;

/**
 * Streamable-HTTP MCP endpoint. Authenticates the incoming request via
 * {@see McpAuthInterface}, constructs an {@see AgentToolRegistryBridge}
 * over the framework-wide {@see AgentToolRegistryInterface} with the
 * auth-resolved {@see AccountInterface}, and dispatches the JSON-RPC
 * payload against the bridge.
 *
 * Per-request bridge construction is the WP03 closing fix for the
 * WP02 caveat (placeholder account at boot leaked into every
 * `tools/call`). Now each request gets a bridge bound to the account
 * `McpAuthInterface::authenticate()` resolved from the Authorization
 * header, so per-tool capability enforcement (`AbstractAgentTool::requireCapability`)
 * runs against the correct account.
 *
 * @api
 */
final readonly class McpEndpoint
{
    /** `tools/list` descriptor `_meta` key: this tool requires a human approval here. */
    public const string APPROVAL_LIST_META_KEY = 'ai.waaseyaa.mcp/approval';

    /** `tools/call` `params._meta` member carrying the approval request id on a retry. */
    public const string APPROVAL_REQUEST_ID_META_KEY = 'waaseyaa/approval_request_id';

    /**
     * Opaque approval request-id shape (mirrors ApprovalRequest's own id
     * contract). The shape is public — every challenge response shows it — so
     * refusing a malformed id without a store roundtrip reveals nothing.
     */
    private const string APPROVAL_REQUEST_ID_PATTERN = '/^apr_[0-9a-f]{32}$/';

    /**
     * @param ?EventDispatcherInterface $dispatcher     Optional — when absent the
     *                                                  `waaseyaa.mcp.dispatch` event is silently
     *                                                  not fired (best-effort audit semantics).
     * @param ?AccountContextInterface        $accountContext Optional acting-account holder.
     * @param ?AccountFieldReadScopeInterface $fieldReadScope Optional guarded-read scope. Authenticated
     *                                                        MCP dispatch runs as the bearer principal,
     *                                                        independently of the HTTP session account.
     * @param ?\Waaseyaa\Auth\AtomicRateLimiterInterface $rateLimiter Per-principal
     *        atomic rate limiting (#2136 WP3, #2177 F7). Enabled when a limiter
     *        is supplied and `$rateLimitMaxRequests > 0`. Keys are
     *        `mcp:<tier>:<principal id>`; exceeding the budget yields JSON-RPC
     *        error -32029 with `retry_after_seconds` (HTTP 429). The limiter is
     *        consulted only AFTER successful authentication (anonymous 401s never
     *        consume budget). A limiter that cannot make a durable decision
     *        fails closed with a sanitized 503; requests are never admitted on
     *        an assumed budget.
     * @param ?\Waaseyaa\Foundation\Log\LoggerInterface $logger Destination for the detail
     *        of an unhandled tool exception, forwarded to the per-request bridge. The
     *        caller-visible response is sanitized either way; the logger only decides
     *        whether an operator can still diagnose the failure.
     * @param ?OperationApprovalStoreInterface $approvalStore The durable human-approval
     *        store the gate enforces against. Required (with `$durableAudit`) whenever
     *        `$approvalGate` is true — see the constructor guards below.
     * @param bool $approvalGate Human-approval gate for destructive tools (#2177 F1).
     *        When true, a tool declared `destructive: true` executes only against a
     *        matching, approved, unexpired, unconsumed approval — see
     *        {@see self::approvalGateDecision()}. The public read-only tier never
     *        enables this.
     * @param list<string> $allowedOrigins Additional browser origins permitted
     *        by the Streamable HTTP DNS-rebinding guard. Same-origin requests
     *        and non-browser requests without Origin remain valid.
     * @param ?string $unauthorizedChallenge Standards-compliant
     *        `WWW-Authenticate` value for protected deployments.
     * @param int $maxRequestBytes Hard transport cap applied before authentication
     *        and JSON decoding.
     * @param ?Auth\OAuthProtectedResourceMetadata $oauthProtectedResourceMetadata
     *        Framework-neutral metadata producer used only by the existing HTTP
     *        adapter entry point. When absent, that entry point fails closed.
     */
    public function __construct(
        private McpAuthInterface $auth,
        private AgentToolRegistryInterface $agentRegistry,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?AccountContextInterface $accountContext = null,
        private ?\Waaseyaa\Auth\AtomicRateLimiterInterface $rateLimiter = null,
        private int $rateLimitMaxRequests = 0,
        private int $rateLimitWindowSeconds = 60,
        private string $rateLimitTier = 'public',
        private ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private ?\Waaseyaa\Foundation\Log\LoggerInterface $logger = null,
        private ?StrictAuditLedgerInterface $auditLedger = null,
        private bool $durableAudit = false,
        private ?OperationApprovalStoreInterface $approvalStore = null,
        private bool $approvalGate = false,
        private array $allowedOrigins = [],
        private ?string $unauthorizedChallenge = null,
        private int $maxRequestBytes = StreamableHttpTransportGuard::DEFAULT_MAX_REQUEST_BYTES,
        private ?Auth\OAuthProtectedResourceMetadata $oauthProtectedResourceMetadata = null,
    ) {
        // The endpoint's OWN fail-closed contract, independent of provider
        // wiring: a durable-audit endpoint with no ledger — or with the
        // record-nothing NullStrictAuditLedger — is a write surface that LOOKS
        // durably audited and records nothing. That construction IS the wiring
        // error, so it is refused here rather than discovered at mutation time.
        if ($this->durableAudit
            && (!$this->auditLedger instanceof StrictAuditLedgerInterface
                || $this->auditLedger instanceof NullStrictAuditLedger)
        ) {
            throw new \LogicException(
                'McpEndpoint: durableAudit requires a real StrictAuditLedgerInterface. '
                . 'Refusing to construct a durable-audit endpoint whose ledger is absent or '
                . 'NullStrictAuditLedger — it would mutate without durable evidence. '
                . 'Supply a real ledger, or set durableAudit to false to accept best-effort auditing.',
            );
        }

        // Same reasoning for the human-approval gate (#2177 F1): a gate with no
        // store to enforce against LOOKS enforced and enforces nothing, and a
        // gate without durable audit has no strict-ledger receipt for the
        // once-only consume() to join to. Both constructions ARE the wiring
        // error, so both are refused here.
        if ($this->approvalGate && !$this->approvalStore instanceof OperationApprovalStoreInterface) {
            throw new \LogicException(
                'McpEndpoint: approvalGate requires an OperationApprovalStoreInterface. '
                . 'Refusing to construct a gated endpoint with no approval store — destructive '
                . 'tools would run unapproved. Supply a store, or set approvalGate to false.',
            );
        }
        if ($this->approvalGate && !$this->durableAudit) {
            throw new \LogicException(
                'McpEndpoint: approvalGate requires durableAudit. Consuming an approval joins it '
                . 'to the strict-ledger receipt of the executing reservation, which only exists '
                . 'under durable auditing. Enable durableAudit, or set approvalGate to false.',
            );
        }
    }

    /**
     * Standard controller entry point — called by AppControllerRouter with typed injection.
     *
     * Note: the typed `$account` parameter comes from the session middleware (set on the
     * `_account` request attribute), but `/mcp` is itself an authentication surface — the
     * bearer token in the Authorization header determines the MCP user via
     * {@see McpAuthInterface::authenticate()}, not the session account. The typed parameter
     * is retained for `AppControllerRouter` contract compliance; the auth-resolved account
     * is the one forwarded to the bridge.
     */
    public function handle(
        AccountInterface $account,
        HttpRequest $request,
    ): McpResponse {
        return $this->dispatch(
            $request->getContent(),
            $request->headers->get('Authorization'),
        );
    }

    /**
     * HTTP controller entry point. Wraps {@see self::handle()} in a Symfony
     * {@see HttpResponse} so the kernel's controller dispatcher can send it
     * (the dispatcher only understands HttpResponse / Inertia results — a bare
     * {@see McpResponse} value object would otherwise be unrenderable).
     */
    public function serve(
        AccountInterface $account,
        HttpRequest $request,
    ): HttpResponse {
        $transportRequest = new StreamableHttpRequestSnapshot(
            method: $request->getMethod(),
            origin: $request->headers->get('Origin'),
            protocolVersion: $request->headers->get('MCP-Protocol-Version'),
            contentLength: $request->headers->get('Content-Length'),
            contentType: $request->headers->get('Content-Type'),
            accept: $request->headers->get('Accept'),
            schemeAndHttpHost: $request->getSchemeAndHttpHost(),
            body: $request->getContent(),
        );
        $transportRefusal = new StreamableHttpTransportGuard(
            $this->allowedOrigins,
            $this->maxRequestBytes,
        )->validate($transportRequest);
        if ($transportRefusal !== null) {
            return $this->toHttpResponse($transportRefusal);
        }

        $mcp = $this->handle($account, $request);

        return $this->toHttpResponse($mcp);
    }

    /** HTTP adapter for the RFC 9728 protected-resource metadata route. */
    public function serveProtectedResourceMetadata(): HttpResponse
    {
        if ($this->oauthProtectedResourceMetadata === null) {
            return $this->toHttpResponse(new McpResponse('', 404));
        }

        return $this->toHttpResponse($this->oauthProtectedResourceMetadata->response());
    }

    private function toHttpResponse(McpResponse $mcp): HttpResponse
    {
        return new HttpResponse(
            $mcp->body,
            $mcp->statusCode,
            ['Content-Type' => $mcp->contentType] + $mcp->headers,
        );
    }

    private function dispatch(
        string $body,
        ?string $authorizationHeader,
    ): McpResponse {
        // One id per request, shared by every record and returned to the caller.
        $correlationId = \bin2hex(\random_bytes(8));

        // Authenticate. A scope-aware strategy (#2177 F3) also states which
        // capability scopes the presented credential is limited to; a plain
        // strategy grants the tier surface unchanged (scopes stay null).
        $tokenScopes = null;
        if ($this->auth instanceof Auth\ScopedMcpAuthInterface) {
            $scoped = $this->auth->authenticateWithScopes($authorizationHeader);
            $authenticated = $scoped?->account;
            $tokenScopes = $scoped?->scopes;
        } else {
            $authenticated = $this->auth->authenticate($authorizationHeader);
        }
        $principal = $authenticated !== null
            ? DecisionAccountResolver::resolve($authenticated, $authenticated)
            : null;

        if ($principal === null) {
            // Audited with a NULL actor and no credential material. An absent,
            // unknown, malformed, or inactive-principal token all land here and
            // are recorded identically — the audit trail must not become the
            // account-existence oracle the response deliberately is not.
            $this->auditTerminal(
                AuditStage::AuthenticationRejected,
                $correlationId,
                null,
                'unknown',
                'authenticate',
                [],
                ['reason' => 'rejected'],
            );

            return new McpResponse(
                body: \json_encode([
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => -32001,
                        'message' => 'Unauthorized',
                        'data' => ['correlation_id' => $correlationId],
                    ],
                    'id' => null,
                ], \JSON_THROW_ON_ERROR),
                statusCode: 401,
                headers: $this->unauthorizedChallenge !== null
                    ? ['WWW-Authenticate' => $this->unauthorizedChallenge]
                    : [],
            );
        }

        $actorUid = self::stableAccountUid($authenticated->id());

        // Per-principal rate limiting (post-auth so 401s never consume budget).
        if ($this->rateLimiter !== null && $this->rateLimitMaxRequests > 0) {
            try {
                $key = sprintf('mcp:%s:%s', $this->rateLimitTier, (string) $principal->id());
                if (!$this->rateLimiter->consume(
                    $key,
                    $this->rateLimitMaxRequests,
                    $this->rateLimitWindowSeconds,
                )) {
                    // Audited OUTSIDE the limiter's own try/catch semantics: the
                    // algorithm is untouched (atomicity remains F7), only the
                    // decision is recorded. Recording happens once per refused
                    // request, so it cannot amplify the very traffic being
                    // limited.
                    $this->auditTerminal(
                        AuditStage::RateLimited,
                        $correlationId,
                        $actorUid,
                        'unknown',
                        'rate_limit',
                        [],
                        ['retry_after_seconds' => $this->rateLimitWindowSeconds],
                    );

                    return new McpResponse(
                        body: \json_encode([
                            'jsonrpc' => '2.0',
                            'error' => [
                                'code' => -32029,
                                'message' => 'Rate limit exceeded',
                                'data' => [
                                    'retry_after_seconds' => $this->rateLimitWindowSeconds,
                                    'correlation_id' => $correlationId,
                                ],
                            ],
                            'id' => null,
                        ], \JSON_THROW_ON_ERROR),
                        statusCode: 429,
                    );
                }
            } catch (\Throwable $e) {
                $this->logger?->error('MCP rate limiter could not make a durable decision.', [
                    'exception_class' => $e::class,
                    'tier' => $this->rateLimitTier,
                ]);

                return new McpResponse(
                    body: \json_encode([
                        'jsonrpc' => '2.0',
                        'error' => [
                            'code' => -32030,
                            'message' => 'Rate limiter unavailable',
                            'data' => ['correlation_id' => $correlationId],
                        ],
                        'id' => null,
                    ], \JSON_THROW_ON_ERROR),
                    statusCode: 503,
                );
            }
        }

        // Construct the per-request bridge with the auth-resolved account.
        // The bridge forwards $authenticated into every tool->execute() call,
        // so per-tool capability gates run against the correct identity.
        //
        // Token scopes narrow, never broaden (#2177 F3): when the credential
        // carries explicit scopes, the request's registry is the INTERSECTION
        // of the tier registry and those scopes — an empty scope list exposes
        // nothing (fail closed) — while per-tool account-capability
        // enforcement still runs underneath in the tools themselves.
        $requestRegistry = $tokenScopes === null
            ? $this->agentRegistry
            : new CapabilityScopedToolRegistry($this->agentRegistry, $tokenScopes);
        $bridge = new AgentToolRegistryBridge($requestRegistry, $principal, $this->logger);

        // Scope the acting-account context to the bearer-auth account
        // (research D1 writer 2, FR-002). The MCP account deliberately
        // differs from any session account (see class docblock), so the
        // prior value is captured and restored in `finally` — including
        // when a routed handler throws.
        $previousActor = $this->accountContext?->current();
        $this->accountContext?->set($principal);

        try {
            // Parse JSON-RPC request.
            try {
                $request = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->jsonRpcError(-32700, 'Parse error', null, statusCode: 400);
            }

            // Streamable HTTP accepts exactly one JSON-RPC message per POST;
            // JSON-RPC batch arrays are deliberately outside that transport
            // profile and are rejected as an invalid request.
            if (!\is_array($request) || \array_is_list($request)) {
                return $this->jsonRpcError(-32600, 'Invalid Request', null, statusCode: 400);
            }

            if (($request['jsonrpc'] ?? null) !== '2.0') {
                return $this->jsonRpcError(-32600, 'Invalid Request: jsonrpc must be "2.0"', null, statusCode: 400);
            }

            // A client response to a prior server request is a valid transport
            // message. This server advertises no server-to-client request
            // capability, so it has no pending response to correlate and safely
            // accepts/ignores the envelope.
            if (!\array_key_exists('method', $request)) {
                $hasResult = \array_key_exists('result', $request);
                $hasError = \array_key_exists('error', $request);
                $responseId = $request['id'] ?? null;
                $validId = \is_int($responseId) || \is_string($responseId);
                $error = $request['error'] ?? null;
                $validError = !$hasError || (\is_array($error)
                    && \is_int($error['code'] ?? null)
                    && \is_string($error['message'] ?? null));
                if (\array_key_exists('id', $request) && $validId && $hasResult !== $hasError && $validError) {
                    return $this->acceptedNoContent();
                }

                return $this->jsonRpcError(-32600, 'Invalid Request', null, statusCode: 400);
            }

            // JSON-RPC 2.0 requires `method` to be a string. A non-string
            // method cannot be honestly named in any audit record, so it is an
            // Invalid Request refused BEFORE acceptance — nothing is admitted,
            // so no `request_accepted` is left unpaired.
            if (!\is_string($request['method'])) {
                return $this->jsonRpcError(-32600, 'Invalid Request', null, statusCode: 400);
            }

            $method = $request['method'];
            $hasId = \array_key_exists('id', $request);
            $id = $hasId ? $request['id'] : null;
            $params = $request['params'] ?? [];

            if ($hasId && (!\is_int($id) && !\is_string($id))) {
                return $this->jsonRpcError(-32600, 'Invalid Request: id must be a string or integer', null, statusCode: 400);
            }

            // The request authenticated, parsed, and is admitted for routing.
            // This REPLACES the former single pre-routing event: that event was
            // the only record of the whole request and hardcoded
            // outcome=allowed, so a refusal or failure downstream was invisible.
            // It is the FIRST of a pair — every admitted request also gets
            // exactly one terminal stage below stating what actually happened.
            $this->emitAudit(AuditStage::RequestAccepted, $correlationId, $actorUid, $method);

            // A `params` member that is not a JSON object cannot address any
            // method's parameters; treat it as an invalid-params envelope
            // rather than silently substituting an empty bag. Terminal for this
            // request: only the offending TYPE is recorded — a malformed
            // `params` value is raw caller input.
            if (!\is_array($params) || (\array_is_list($params) && $params !== [])) {
                $this->auditTerminal(
                    AuditStage::InvalidParamsRefused,
                    $correlationId,
                    $actorUid,
                    $method,
                    $method,
                    [],
                    ['reason' => 'params_not_object', 'params_type' => \get_debug_type($params)],
                );

                return $this->jsonRpcError(-32602, 'Invalid params: must be an object', $id);
            }

            if (!$hasId) {
                return $this->handleNotification($method, $params, $correlationId, $actorUid);
            }

            $route = fn(): McpResponse => match ($method) {
                'initialize' => $this->protocolExecute(fn(): McpResponse => $this->handleInitialize($id, $params), $id, $correlationId, $actorUid, $method),
                'ping' => $this->protocolExecute(fn(): McpResponse => $this->handlePing($id), $id, $correlationId, $actorUid, $method),
                'tools/list' => $this->protocolExecute(fn(): McpResponse => $this->handleToolsList($id, $bridge), $id, $correlationId, $actorUid, $method),
                'tools/call' => $this->handleToolsCall($id, $params, $bridge, $correlationId, $actorUid, (string) $principal->id()),
                default => $this->refuseUnknownMethod($id, $method, $correlationId, $actorUid),
            };

            // The bearer principal is also the guarded entity-read principal.
            // The HTTP session account is deliberately irrelevant on both MCP
            // tiers, especially the authenticated write tier where a
            // production request commonly has an anonymous session.
            return $this->fieldReadScope !== null
                ? $this->fieldReadScope->run($principal, $route)
                : $route();
        } finally {
            $this->accountContext?->set($previousActor);
        }
    }

    /**
     * Run a mutation-free protocol method and emit its honest terminal stage.
     *
     * The handler arrives as a CLOSURE, invoked inside the try: a handler that
     * throws (e.g. a registry whose enumeration fails under `tools/list`) still
     * closes the accepted request with exactly one terminal stage —
     * `execution_failed` — instead of escaping as an uncontrolled HTTP failure
     * whose page carries the exception. The caller gets a sanitized JSON-RPC
     * internal error joined to the audit pair by the same correlation id; the
     * log gets safe metadata only (exception class, method, correlation id —
     * never message, trace, or params: F6, same reasoning as
     * {@see \Waaseyaa\AI\Tools\Error\SanitizedToolError::logContext()}).
     *
     * The success stage is projection-only by design: the strict ledger
     * evidences refusals and write ATTEMPTS, and `initialize`/`ping`/
     * `tools/list` mutate nothing, so a durable row per ping would be
     * amplification with no durability guarantee behind it. The FAILURE stage
     * goes through {@see self::auditTerminal()} like every other terminal
     * non-success of an accepted request.
     *
     * @param \Closure(): McpResponse $handler
     */
    private function protocolExecute(
        \Closure $handler,
        mixed $id,
        string $correlationId,
        ?int $actorUid,
        string $method,
    ): McpResponse {
        try {
            $response = $handler();
        } catch (\Throwable $e) {
            $this->logger?->error('mcp.protocol_execution_failed', [
                'correlation_id' => $correlationId,
                'method' => $method,
                'exception' => $e::class,
            ]);

            $this->auditTerminal(
                AuditStage::ExecutionFailed,
                $correlationId,
                $actorUid,
                $method,
                $method,
                [],
                ['reason' => 'protocol_handler_threw', 'exception' => $e::class],
            );

            return $this->jsonRpcError(
                -32603,
                'Internal error. Quote the correlation id to an operator to have it diagnosed.',
                $id,
                ['correlation_id' => $correlationId],
            );
        }

        $this->emitAudit(AuditStage::ExecutionSucceeded, $correlationId, $actorUid, $method);

        return $response;
    }

    private function refuseUnknownMethod(
        mixed $id,
        string $method,
        string $correlationId,
        ?int $actorUid,
    ): McpResponse {
        // The requested method name is the same class of safe structural
        // metadata as an unknown tool name — recorded so probing is visible.
        $this->auditTerminal(
            AuditStage::MethodLookupRefused,
            $correlationId,
            $actorUid,
            $method,
            $method,
            [],
            ['reason' => 'method_not_found'],
        );

        return $this->jsonRpcError(-32601, "Method not found: {$method}", $id);
    }

    /** @param array<mixed> $params */
    private function handleInitialize(mixed $id, array $params): McpResponse
    {
        $requestedVersion = $params['protocolVersion'] ?? null;
        $capabilities = $params['capabilities'] ?? null;
        $clientInfo = $params['clientInfo'] ?? null;
        if (!\is_string($requestedVersion)
            || !self::isJsonObject($capabilities)
            || !self::isJsonObject($clientInfo)
            || !\is_string($clientInfo['name'] ?? null)
            || !\is_string($clientInfo['version'] ?? null)
        ) {
            return $this->jsonRpcError(
                -32602,
                'Invalid initialize params: protocolVersion, capabilities, and clientInfo{name,version} are required',
                $id,
            );
        }

        return $this->jsonRpcResult($id, [
            'protocolVersion' => McpProtocol::negotiate($requestedVersion),
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'Waaseyaa',
                'version' => '0.1.0',
            ],
        ]);
    }

    /** @param array<mixed> $params */
    private function handleNotification(
        string $method,
        array $params,
        string $correlationId,
        ?int $actorUid,
    ): McpResponse {
        if (!\str_starts_with($method, 'notifications/')) {
            $this->auditTerminal(
                AuditStage::InvalidParamsRefused,
                $correlationId,
                $actorUid,
                $method,
                $method,
                [],
                ['reason' => 'request_method_without_id'],
            );

            return new McpResponse('', 400);
        }

        if ($method === 'notifications/cancelled') {
            $requestId = $params['requestId'] ?? null;
            if (!\is_int($requestId) && !\is_string($requestId)) {
                $this->auditTerminal(
                    AuditStage::InvalidParamsRefused,
                    $correlationId,
                    $actorUid,
                    $method,
                    $method,
                    [],
                    ['reason' => 'missing_or_invalid_request_id'],
                );

                return new McpResponse('', 400);
            }
        }

        $this->emitAudit(AuditStage::ExecutionSucceeded, $correlationId, $actorUid, $method);

        return $this->acceptedNoContent();
    }

    private function acceptedNoContent(): McpResponse
    {
        return new McpResponse('', 202);
    }

    private static function isJsonObject(mixed $value): bool
    {
        return \is_array($value) && (!\array_is_list($value) || $value === []);
    }

    private function handlePing(mixed $id): McpResponse
    {
        return $this->jsonRpcResult($id, []);
    }

    private function handleToolsList(mixed $id, AgentToolRegistryBridge $bridge): McpResponse
    {
        $tools = [];
        foreach ($bridge->getTools() as $tool) {
            $descriptor = $tool->toMcpDescriptor();
            // Advertise the gate so an agent can anticipate the -32003
            // challenge instead of discovering it. Advisory only: enforcement
            // in handleToolsCall reads $tool->destructive and the gate flag,
            // never this marker.
            if ($this->approvalGate && $tool->destructive) {
                $descriptor['_meta'] = [self::APPROVAL_LIST_META_KEY => 'required'];
            }
            $tools[] = $descriptor;
        }

        return $this->jsonRpcResult($id, ['tools' => $tools]);
    }

    /**
     * `params` shape is caller-controlled, so each member is checked before
     * use: a non-string `name` or a non-object `arguments` is a JSON-RPC
     * envelope defect (-32602), not something to coerce and pass along.
     * Argument *contents* are then enforced against the tool's declared schema
     * inside the bridge (#2145), so no malformed input reaches a handler.
     *
     * @param array<mixed> $params
     */
    private function handleToolsCall(
        mixed $id,
        array $params,
        AgentToolRegistryBridge $bridge,
        string $correlationId,
        ?int $actorUid,
        string $principalKey,
    ): McpResponse {
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        // Each early envelope refusal below is TERMINAL for an already-accepted
        // request, so each writes its honest stage. Malformed member VALUES are
        // raw caller input — only their type is ever recorded.
        if ($toolName === null) {
            $this->auditTerminal(
                AuditStage::InvalidParamsRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                'tools/call',
                [],
                ['reason' => 'missing_name'],
            );

            return $this->jsonRpcError(-32602, 'Missing required parameter: name', $id);
        }

        if (!\is_string($toolName)) {
            $this->auditTerminal(
                AuditStage::InvalidParamsRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                'tools/call',
                [],
                ['reason' => 'name_not_string', 'name_type' => \get_debug_type($toolName)],
            );

            return $this->jsonRpcError(-32602, 'Invalid parameter: name must be a string', $id);
        }

        // `{}` and `[]` both decode to []; anything else is not a JSON object.
        if (!\is_array($arguments) || (array_is_list($arguments) && $arguments !== [])) {
            $this->auditTerminal(
                AuditStage::InvalidParamsRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                $toolName,
                [],
                ['reason' => 'arguments_not_object', 'arguments_type' => \get_debug_type($arguments)],
            );

            return $this->jsonRpcError(-32602, 'Invalid parameter: arguments must be an object', $id);
        }

        // `params._meta` is the request-metadata envelope member (MCP spec). It
        // is validated and consumed HERE, as envelope: it never reaches schema
        // validation, argumentsForAudit(), or the tool — `arguments` is the
        // only member a tool ever sees.
        // Presence, not value: `"_meta": null` is PRESENT and non-object — the
        // same envelope defect as any other non-object — while an absent
        // member is simply no envelope metadata.
        $metaPresent = \array_key_exists('_meta', $params);
        $meta = $metaPresent ? $params['_meta'] : null;
        if ($metaPresent && (!\is_array($meta) || (array_is_list($meta) && $meta !== []))) {
            $this->auditTerminal(
                AuditStage::InvalidParamsRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                $toolName,
                [],
                ['reason' => 'meta_not_object', 'meta_type' => \get_debug_type($meta)],
            );

            return $this->jsonRpcError(-32602, 'Invalid parameter: _meta must be an object', $id);
        }

        $approvalRequestId = null;
        if (\is_array($meta) && \array_key_exists(self::APPROVAL_REQUEST_ID_META_KEY, $meta)) {
            $approvalRequestId = $meta[self::APPROVAL_REQUEST_ID_META_KEY];
            if (!\is_string($approvalRequestId)) {
                $this->auditTerminal(
                    AuditStage::InvalidParamsRefused,
                    $correlationId,
                    $actorUid,
                    'tools/call',
                    $toolName,
                    [],
                    ['reason' => 'approval_request_id_not_string', 'value_type' => \get_debug_type($approvalRequestId)],
                );

                return $this->jsonRpcError(
                    -32602,
                    \sprintf('Invalid parameter: _meta["%s"] must be a string', self::APPROVAL_REQUEST_ID_META_KEY),
                    $id,
                );
            }
        }

        $tool = $bridge->getTool($toolName);
        if ($tool === null) {
            // An unknown tool cannot supply argumentsForAudit(), so only the
            // requested name and safe structural metadata are recorded — never
            // the argument values, whose shape is entirely caller-controlled.
            $this->auditTerminal(
                AuditStage::ToolLookupRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                $toolName,
                [],
                ['argument_count' => \count($arguments)],
            );

            return $this->jsonRpcError(-32602, "Unknown tool: {$toolName}", $id);
        }

        // Schema validation runs BEFORE the reservation. A reservation means
        // "the tool is about to be invoked"; malformed input never gets that
        // far, so reserving first would both misdescribe the request and let a
        // caller write two durable rows per garbage payload — an amplification
        // path the audit trail must not open. The bridge validates again
        // internally: defence in depth, and direct bridge callers keep their
        // guarantee.
        $violations = ToolInputSchemaValidator::validate($tool->inputSchema, $arguments);
        if ($violations !== []) {
            $this->auditTerminal(
                AuditStage::InputValidationRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                $toolName,
                [],
                ['violation_count' => \count($violations)],
            );

            return $this->jsonRpcResult($id, $bridge->executeClassified($toolName, $arguments)->envelope);
        }

        // Redaction is the TOOL's own transform, never the raw JSON-RPC params.
        $safeArguments = $this->safeArguments($tool, $arguments);

        // Human-approval gate (#2177 F1): a destructive tool on a gated
        // endpoint runs only against a matching, approved, unexpired,
        // unconsumed durable approval. Enforcement keys off the tool's declared
        // `$destructive` — the advisory descriptor hints in tools/list are
        // never consulted. Anything but an approved exact-tuple match returns
        // here, before the reservation, with no execution.
        $approval = null;
        if ($this->approvalGate && $tool->destructive) {
            $gate = $this->approvalGateDecision(
                $id,
                $arguments,
                $safeArguments,
                $approvalRequestId,
                $correlationId,
                $actorUid,
                $principalKey,
                $toolName,
            );
            if ($gate instanceof McpResponse) {
                return $gate;
            }
            $approval = $gate;
        }

        // Joins the executing reservation (and its finalization) to the
        // approval that authorized it and to the operator who decided it.
        $approvalMetadata = $approval !== null
            ? ['approval_request_id' => $approval->id, 'approval_decided_by_uid' => $approval->decidedByUid]
            : [];

        // Durable pre-execution reservation. On the write tier this is
        // fail-closed: if the attempt cannot be made durable, the tool is never
        // invoked, so no mutation can occur without evidence that it was tried.
        // Reserve precedes consume, so a reservation failure leaves the
        // approval unconsumed and spendable by a later retry.
        $receipt = null;
        if ($this->durableAudit) {
            try {
                $receipt = $this->auditLedger()->reserve(new StrictAuditReservation(
                    correlationId: $correlationId,
                    surface: $this->auditSurface(),
                    operation: $toolName,
                    actorUid: $actorUid,
                    safeArguments: $safeArguments,
                    metadata: ['tier' => $this->rateLimitTier] + $approvalMetadata,
                ));
            } catch (StrictAuditLedgerException $e) {
                $this->logger?->critical('mcp.audit_reservation_failed', [
                    'correlation_id' => $correlationId,
                    'tool' => $toolName,
                    'tier' => $this->rateLimitTier,
                    'exception' => $e::class,
                ]);

                // Terminal PROJECTION only — the durable ledger is the thing
                // that just failed, so the best-effort audit_event row is the
                // only record this path can still produce.
                $this->emitAudit(
                    AuditStage::AuditUnavailableRefused,
                    $correlationId,
                    $actorUid,
                    'tools/call',
                    $toolName,
                    [],
                    ['reason' => 'audit_ledger_unavailable'],
                );

                // The caller learns only that the request was refused, plus the
                // correlation id to hand to an operator — it joins this refusal
                // to the critical log line above. No exception detail leaves (F6).
                return $this->jsonRpcError(
                    -32002,
                    'Request refused: the audit trail is unavailable.',
                    $id,
                    ['correlation_id' => $correlationId],
                );
            }
        }

        // Atomic once-only consume, strictly BEFORE execution: the storage
        // layer's unique index guarantees at most one consumer, so losing the
        // race (or any state change since the gate's read) refuses execution.
        if ($approval !== null) {
            $consumeRefusal = $this->consumeApproval(
                $id,
                $approval,
                $receipt,
                $correlationId,
                $actorUid,
                $toolName,
                $approvalMetadata,
            );
            if ($consumeRefusal !== null) {
                return $consumeRefusal;
            }
        }

        $outcome = $bridge->executeClassified($toolName, $arguments);

        if ($receipt !== null) {
            try {
                $this->auditLedger()->finalize($receipt, $outcome->stage, ['tier' => $this->rateLimitTier] + $approvalMetadata);
            } catch (\Throwable $e) {
                // The side effect has ALREADY happened. Retrying or rolling it
                // back would duplicate or silently undo a committed mutation, so
                // neither is attempted. The reservation stays unfinalized, which
                // is queryable ('reserved' with no 'finalized') and is the
                // documented crash-window signature. Loud, and never silent.
                $this->logger?->critical('mcp.audit_finalize_failed', [
                    'correlation_id' => $correlationId,
                    'receipt_id' => $receipt->id,
                    'tool' => $toolName,
                    'stage' => $outcome->stage->value,
                    'exception' => $e::class,
                    'note' => 'Dangling reservation: outcome unknown, side effect may have committed.',
                ]);
            }
        }

        $this->emitAudit($outcome->stage, $correlationId, $actorUid, 'tools/call', $toolName, $safeArguments);

        return $this->jsonRpcResult($id, $outcome->envelope);
    }

    /**
     * The tool's own redaction transform, defensively guarded.
     *
     * A tool that throws while redacting must not take down the request, but it
     * also must not cause raw arguments to be substituted — the fallback is
     * structural metadata only.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function safeArguments(\Waaseyaa\AI\Tools\AgentTool $tool, array $arguments): array
    {
        try {
            return $tool->impl->argumentsForAudit($arguments);
        } catch (\Throwable) {
            return ['_redaction_unavailable' => true, 'argument_count' => \count($arguments)];
        }
    }

    /**
     * Decide the approval gate for one destructive call: a challenge or a
     * refusal response, or the approved {@see ApprovalRequest} to consume.
     *
     * The tuple binds the approval to THIS principal (exact string account id),
     * THIS surface, THIS tool, and THESE exact raw validated arguments — any
     * drift is a different operation than the one the human saw. The caller's
     * refusal body is identical on every non-approved axis (unknown, mismatch,
     * denied, expired, consumed): the axis is recorded operator-side only, so
     * the response is not an approval-state oracle.
     *
     * @param array<array-key, mixed> $arguments     the raw, schema-validated call arguments
     * @param array<array-key, mixed> $safeArguments the tool's redacted projection of them
     */
    private function approvalGateDecision(
        mixed $id,
        array $arguments,
        array $safeArguments,
        ?string $approvalRequestId,
        string $correlationId,
        ?int $actorUid,
        string $principalKey,
        string $toolName,
    ): McpResponse|ApprovalRequest {
        // Deliberately OUTSIDE any store try/catch: a tuple-construction
        // failure is this endpoint's own defect, not a store outage, and must
        // not be misreported as one.
        $tuple = ApprovalTuple::forCall($principalKey, $this->auditSurface(), $toolName, $arguments);
        $store = $this->approvalStore();

        // Each try below wraps EXACTLY one store call, and catches \Throwable
        // rather than only the contract's ApprovalStoreException: a
        // nonconforming third-party adapter that throws anything else must
        // still fail closed, never escape as an uncontrolled crash.
        if ($approvalRequestId === null) {
            try {
                $request = $store->open($tuple, $correlationId, $safeArguments);
            } catch (\Throwable $e) {
                return $this->approvalStoreUnavailable($e, $id, $correlationId, $actorUid, $toolName);
            }

            return $this->approvalChallenge($id, $request, $correlationId, $actorUid, $toolName, $safeArguments);
        }

        $request = null;
        if (preg_match(self::APPROVAL_REQUEST_ID_PATTERN, $approvalRequestId) === 1) {
            try {
                $request = $store->find($approvalRequestId);
            } catch (\Throwable $e) {
                return $this->approvalStoreUnavailable($e, $id, $correlationId, $actorUid, $toolName);
            }
        }

        if ($request === null) {
            return $this->approvalRefusal($id, 'unknown', null, $correlationId, $actorUid, $toolName, $safeArguments);
        }

        // Constant-time: the caller controls the computed key via the call
        // arguments, so an early-exit comparison would leak how many
        // leading bytes of the stored key a probe reproduces.
        if (!\hash_equals($request->tuple->requestKey, $tuple->requestKey)) {
            return $this->approvalRefusal($id, 'tuple_mismatch', $request->id, $correlationId, $actorUid, $toolName, $safeArguments);
        }

        return match ($request->status) {
            ApprovalStatus::Pending => $this->approvalChallenge($id, $request, $correlationId, $actorUid, $toolName, $safeArguments),
            ApprovalStatus::Approved => $request,
            ApprovalStatus::Denied => $this->approvalRefusal($id, 'denied', $request->id, $correlationId, $actorUid, $toolName, $safeArguments),
            ApprovalStatus::Expired => $this->approvalRefusal($id, 'expired', $request->id, $correlationId, $actorUid, $toolName, $safeArguments),
            ApprovalStatus::Consumed => $this->approvalRefusal($id, 'consumed', $request->id, $correlationId, $actorUid, $toolName, $safeArguments),
        };
    }

    /**
     * Fail closed on a pre-reservation store failure: approval state that
     * cannot be read or made durable means the destructive call must not run.
     * Safe metadata only — class, correlation, tool — never message or trace
     * (F6). Nothing was reserved yet, so this is one honest single-record
     * terminal — never a second terminal for the same request.
     */
    private function approvalStoreUnavailable(
        \Throwable $e,
        mixed $id,
        string $correlationId,
        ?int $actorUid,
        string $toolName,
    ): McpResponse {
        $this->logger?->critical('mcp.approval_store_unavailable', [
            'correlation_id' => $correlationId,
            'tool' => $toolName,
            'exception' => $e::class,
        ]);

        $this->auditTerminal(
            AuditStage::AuditUnavailableRefused,
            $correlationId,
            $actorUid,
            'tools/call',
            $toolName,
            [],
            ['reason' => 'approval_store_unavailable'],
        );

        return $this->jsonRpcError(
            -32002,
            'Request refused: the approval store is unavailable.',
            $id,
            ['correlation_id' => $correlationId],
        );
    }

    /**
     * Consume the approved request, exactly once, before execution.
     *
     * Returns null when consumption succeeded and the tool may run; otherwise
     * the refusal response. Either failure path finalizes the already-durable
     * reservation with its honest stage — a pair, never a dangling reservation
     * and never a second single-record terminal.
     *
     * @param array<string, mixed> $approvalMetadata
     */
    private function consumeApproval(
        mixed $id,
        ApprovalRequest $approval,
        ?StrictAuditReceipt $receipt,
        string $correlationId,
        ?int $actorUid,
        string $toolName,
        array $approvalMetadata,
    ): ?McpResponse {
        // Non-null whenever the gate is on — approvalGate requires durableAudit
        // at construction, and the fail-closed reserve() already returned.
        if ($receipt === null) {
            throw new \LogicException('approvalGate without durableAudit is refused at construction.');
        }

        // \Throwable, not just the contract's ApprovalStoreException: a
        // nonconforming store must still fail closed with an honest
        // finalization, never escape mid-request (same rationale as the
        // pre-reservation gate). The try wraps only the store call.
        try {
            $consumed = $this->approvalStore()->consume($approval->id, $receipt->id, $correlationId);
        } catch (\Throwable $e) {
            $this->logger?->critical('mcp.approval_consume_failed', [
                'correlation_id' => $correlationId,
                'tool' => $toolName,
                'exception' => $e::class,
            ]);

            $this->finalizeQuietly(
                $receipt,
                AuditStage::AuditUnavailableRefused,
                ['reason' => 'approval_store_unavailable'] + $approvalMetadata,
                $correlationId,
                $toolName,
            );
            $this->emitAudit(
                AuditStage::AuditUnavailableRefused,
                $correlationId,
                $actorUid,
                'tools/call',
                $toolName,
                [],
                ['reason' => 'approval_store_unavailable'],
            );

            return $this->jsonRpcError(
                -32002,
                'Request refused: the approval store is unavailable.',
                $id,
                ['correlation_id' => $correlationId],
            );
        }

        if ($consumed) {
            return null;
        }

        // Lost the once-only race, or the state changed since the gate's read.
        // Either way the approval is not spendable by THIS request.
        $this->finalizeQuietly(
            $receipt,
            AuditStage::ApprovalRefused,
            ['reason' => 'not_consumable'] + $approvalMetadata,
            $correlationId,
            $toolName,
        );
        $this->emitAudit(
            AuditStage::ApprovalRefused,
            $correlationId,
            $actorUid,
            'tools/call',
            $toolName,
            [],
            ['reason' => 'not_consumable'] + $approvalMetadata,
        );

        return $this->approvalRefusedResponse($id, $correlationId);
    }

    /**
     * The -32003 challenge: durably record `approval_required`, then hand the
     * caller everything a compliant retry needs — and nothing else.
     *
     * @param array<array-key, mixed> $safeArguments
     */
    private function approvalChallenge(
        mixed $id,
        ApprovalRequest $request,
        string $correlationId,
        ?int $actorUid,
        string $toolName,
        array $safeArguments,
    ): McpResponse {
        $expiresAt = $request->expiresAt->format(\DateTimeInterface::ATOM);

        $this->auditTerminal(
            AuditStage::ApprovalRequired,
            $correlationId,
            $actorUid,
            'tools/call',
            $toolName,
            $safeArguments,
            ['approval_request_id' => $request->id, 'expires_at' => $expiresAt],
        );

        return $this->jsonRpcError(
            -32003,
            'Approval required: a human operator must approve this call before it runs. '
            . 'Retry with the approval request id in _meta["' . self::APPROVAL_REQUEST_ID_META_KEY . '"] once approved.',
            $id,
            [
                'approval_request_id' => $request->id,
                'expires_at' => $expiresAt,
                'correlation_id' => $correlationId,
            ],
        );
    }

    /**
     * A pre-reservation approval refusal: one durable `approval_refused`
     * record carrying the axis operator-side, and the fixed no-oracle body.
     *
     * @param array<array-key, mixed> $safeArguments
     */
    private function approvalRefusal(
        mixed $id,
        string $reason,
        ?string $requestId,
        string $correlationId,
        ?int $actorUid,
        string $toolName,
        array $safeArguments,
    ): McpResponse {
        $metadata = ['reason' => $reason];
        if ($requestId !== null) {
            $metadata['approval_request_id'] = $requestId;
        }

        $this->auditTerminal(
            AuditStage::ApprovalRefused,
            $correlationId,
            $actorUid,
            'tools/call',
            $toolName,
            $safeArguments,
            $metadata,
        );

        return $this->approvalRefusedResponse($id, $correlationId);
    }

    /** The single no-oracle refusal body, shared by every non-approved axis. */
    private function approvalRefusedResponse(mixed $id, string $correlationId): McpResponse
    {
        return $this->jsonRpcError(-32004, 'Approval refused.', $id, ['correlation_id' => $correlationId]);
    }

    /**
     * Finalize a reservation whose refusal response is already decided: the
     * response must not change if the outcome record fails, so the failure is
     * logged loudly instead (same rationale as the post-execution finalize).
     *
     * @param array<string, mixed> $metadata
     */
    private function finalizeQuietly(
        StrictAuditReceipt $receipt,
        AuditStage $stage,
        array $metadata,
        string $correlationId,
        string $toolName,
    ): void {
        try {
            $this->auditLedger()->finalize($receipt, $stage, ['tier' => $this->rateLimitTier] + $metadata);
        } catch (\Throwable $e) {
            $this->logger?->critical('mcp.audit_finalize_failed', [
                'correlation_id' => $correlationId,
                'receipt_id' => $receipt->id,
                'tool' => $toolName,
                'stage' => $stage->value,
                'exception' => $e::class,
                'note' => 'Dangling reservation: the refusal stands, its outcome record does not.',
            ]);
        }
    }

    private function approvalStore(): OperationApprovalStoreInterface
    {
        // Non-null whenever approvalGate is on — enforced by the constructor.
        return $this->approvalStore
            ?? throw new \LogicException('approvalGate without a store is refused at construction.');
    }

    private function auditLedger(): StrictAuditLedgerInterface
    {
        // Non-null whenever durableAudit is on — enforced by the constructor.
        return $this->auditLedger
            ?? throw new \LogicException('durableAudit without a ledger is refused at construction.');
    }

    /** The ledger `surface` for this tier, e.g. `mcp.write`. */
    private function auditSurface(): string
    {
        return 'mcp.' . $this->rateLimitTier;
    }

    /**
     * A terminal stage that never reaches execution: nothing will be finalized,
     * so it is one durable record rather than a reserve/finalize pair.
     *
     * **The exact guarantee, stated honestly:** terminal refusal records (auth
     * rejections, 429s, method/params/tool/schema refusals) are durable only
     * when the ledger accepts the write. If it does not, the refusal response
     * is STILL returned and the gap is logged at critical — refusals perform no
     * side effect, so the fail-closed rule ("no mutation without durable
     * evidence") is not weakened, and failing an already-safe refusal on ledger
     * availability would convert an audit outage into a wider denial of
     * service. Only the pre-execution `reserve()` in {@see self::handleToolsCall}
     * is fail-closed.
     *
     * @param array<array-key, mixed> $safeArguments
     * @param array<string, mixed>    $metadata
     */
    private function auditTerminal(
        AuditStage $stage,
        string $correlationId,
        ?int $actorUid,
        string $method,
        string $operation,
        array $safeArguments = [],
        array $metadata = [],
    ): void {
        if ($this->durableAudit) {
            try {
                $this->auditLedger()->record(
                    new StrictAuditReservation(
                        correlationId: $correlationId,
                        surface: $this->auditSurface(),
                        operation: $operation,
                        actorUid: $actorUid,
                        safeArguments: $safeArguments,
                        metadata: $metadata + ['tier' => $this->rateLimitTier],
                    ),
                    $stage,
                );
            } catch (\Throwable $e) {
                // A refusal was already going to be returned; failing to record
                // it cannot make the outcome less safe, so this does not change
                // the response. It is logged loudly rather than swallowed.
                // \Throwable, not just the contract exception: a ledger that
                // breaks its own exception contract must not convert a safe
                // refusal into an endpoint crash (same rationale as finalize).
                $this->logger?->critical('mcp.audit_terminal_record_failed', [
                    'correlation_id' => $correlationId,
                    'stage' => $stage->value,
                    'exception' => $e::class,
                ]);
            }
        }

        $this->emitAudit($stage, $correlationId, $actorUid, $method, $operation, $safeArguments, $metadata);
    }

    /**
     * Emit the best-effort `audit_event` projection for a stage.
     *
     * This is the OCAP-log side, deliberately best-effort per
     * {@see \Waaseyaa\Audit\Contract\AuditWriterInterface}'s contract. On the
     * write tier the DURABLE record is the strict ledger; this projection exists
     * so operators keep one queryable dashboard across both tiers.
     *
     * @param array<array-key, mixed> $safeArguments
     * @param array<string, mixed>    $metadata
     */
    private function emitAudit(
        AuditStage $stage,
        string $correlationId,
        ?int $actorUid,
        string $method,
        ?string $operation = null,
        array $safeArguments = [],
        array $metadata = [],
    ): void {
        try {
            $this->dispatcher?->dispatch(
                new McpDispatchEvent(
                    method: $method,
                    params: [],
                    accountUid: $actorUid,
                    correlationId: $correlationId,
                    tier: $this->rateLimitTier,
                    stage: $stage->value,
                    toolName: $operation,
                    safeArguments: $safeArguments,
                    metadata: $metadata,
                ),
                McpDispatchEvent::NAME,
            );
        } catch (\Throwable) {
            // Best-effort by contract: the projection must never alter the
            // JSON-RPC response. The durable guarantee lives in the ledger.
        }
    }

    private function jsonRpcResult(mixed $id, mixed $result): McpResponse
    {
        return new McpResponse(
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $data Optional safe `error.data` members
     *        (e.g. `correlation_id`). Never exception detail or raw params.
     */
    private function jsonRpcError(
        int $code,
        string $message,
        mixed $id,
        array $data = [],
        int $statusCode = 200,
    ): McpResponse {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== []) {
            $error['data'] = $data;
        }

        return new McpResponse(
            body: \json_encode([
                'jsonrpc' => '2.0',
                'error' => $error,
                'id' => $id,
            ], \JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
        );
    }

    /**
     * Preserve numeric account ids and map opaque ids to a stable, non-zero
     * integer that fits the audit store's actor_uid column. The first 60 bits
     * of SHA-256 are deterministic across processes and cannot collide with
     * AnonymousUser's reserved zero sentinel through PHP's string-to-int cast.
     */
    private static function stableAccountUid(int|string $accountId): int
    {
        if (is_int($accountId)) {
            return $accountId;
        }

        $stableUid = (int) hexdec(substr(hash('sha256', $accountId), 0, 15));

        return $stableUid === 0 ? 1 : $stableUid;
    }
}
