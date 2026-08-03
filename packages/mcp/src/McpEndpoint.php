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
use Waaseyaa\Foundation\Audit\AuditStage;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerException;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
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
    /**
     * @param ?EventDispatcherInterface $dispatcher     Optional — when absent the
     *                                                  `waaseyaa.mcp.dispatch` event is silently
     *                                                  not fired (best-effort audit semantics).
     * @param ?AccountContextInterface        $accountContext Optional acting-account holder.
     * @param ?AccountFieldReadScopeInterface $fieldReadScope Optional guarded-read scope. Authenticated
     *                                                        MCP dispatch runs as the bearer principal,
     *                                                        independently of the HTTP session account.
     * @param ?\Waaseyaa\Auth\RateLimiterInterface $rateLimiter Optional per-principal
     *        rate limiting (#2136 WP3). Enabled only when a limiter is supplied AND
     *        `$rateLimitMaxRequests > 0` (default off). Keys are
     *        `mcp:<tier>:<principal id>`; exceeding the budget yields JSON-RPC
     *        error -32029 with `retry_after_seconds` (HTTP 429). The limiter is
     *        consulted only AFTER successful authentication (anonymous 401s never
     *        consume budget) and fails OPEN on limiter infrastructure errors —
     *        limiter availability must never take down the endpoint.
     * @param ?\Waaseyaa\Foundation\Log\LoggerInterface $logger Destination for the detail
     *        of an unhandled tool exception, forwarded to the per-request bridge. The
     *        caller-visible response is sanitized either way; the logger only decides
     *        whether an operator can still diagnose the failure.
     */
    public function __construct(
        private McpAuthInterface $auth,
        private AgentToolRegistryInterface $agentRegistry,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?AccountContextInterface $accountContext = null,
        private ?\Waaseyaa\Auth\RateLimiterInterface $rateLimiter = null,
        private int $rateLimitMaxRequests = 0,
        private int $rateLimitWindowSeconds = 60,
        private string $rateLimitTier = 'public',
        private ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private ?\Waaseyaa\Foundation\Log\LoggerInterface $logger = null,
        private ?StrictAuditLedgerInterface $auditLedger = null,
        private bool $durableAudit = false,
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
        $mcp = $this->handle($account, $request);

        return new HttpResponse(
            $mcp->body,
            $mcp->statusCode,
            ['Content-Type' => $mcp->contentType],
        );
    }

    private function dispatch(
        string $body,
        ?string $authorizationHeader,
    ): McpResponse {
        // One id per request, shared by every record and returned to the caller.
        $correlationId = \bin2hex(\random_bytes(8));

        // Authenticate.
        $authenticated = $this->auth->authenticate($authorizationHeader);
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
            );
        }

        $actorUid = self::stableAccountUid($authenticated->id());

        // Per-principal rate limiting (post-auth so 401s never consume budget).
        if ($this->rateLimiter !== null && $this->rateLimitMaxRequests > 0) {
            try {
                $key = sprintf('mcp:%s:%s', $this->rateLimitTier, (string) $principal->id());
                if ($this->rateLimiter->tooManyAttempts($key, $this->rateLimitMaxRequests)) {
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
                $this->rateLimiter->hit($key, $this->rateLimitWindowSeconds);
            } catch (\Throwable) {
                // Fail open: limiter availability is not endpoint availability.
            }
        }

        // Construct the per-request bridge with the auth-resolved account.
        // The bridge forwards $authenticated into every tool->execute() call,
        // so per-tool capability gates run against the correct identity.
        $bridge = new AgentToolRegistryBridge($this->agentRegistry, $principal, $this->logger);

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
                return $this->jsonRpcError(-32700, 'Parse error', null);
            }

            // JSON-RPC 2.0 requires `method` to be a string. A non-string
            // method cannot be honestly named in any audit record, so it is an
            // Invalid Request refused BEFORE acceptance — nothing is admitted,
            // so no `request_accepted` is left unpaired.
            if (!\is_array($request) || !\is_string($request['method'] ?? null)) {
                return $this->jsonRpcError(-32600, 'Invalid Request', $request['id'] ?? null);
            }

            $method = $request['method'];
            $id = $request['id'] ?? null;
            $params = $request['params'] ?? [];

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
            if (!\is_array($params)) {
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

            $route = fn(): McpResponse => match ($method) {
                'initialize' => $this->protocolExecute(fn(): McpResponse => $this->handleInitialize($id), $id, $correlationId, $actorUid, $method),
                'ping' => $this->protocolExecute(fn(): McpResponse => $this->handlePing($id), $id, $correlationId, $actorUid, $method),
                'tools/list' => $this->protocolExecute(fn(): McpResponse => $this->handleToolsList($id, $bridge), $id, $correlationId, $actorUid, $method),
                'tools/call' => $this->handleToolsCall($id, $params, $bridge, $correlationId, $actorUid),
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

    private function handleInitialize(mixed $id): McpResponse
    {
        return $this->jsonRpcResult($id, [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'Waaseyaa',
                'version' => '0.1.0',
            ],
        ]);
    }

    private function handlePing(mixed $id): McpResponse
    {
        return $this->jsonRpcResult($id, []);
    }

    private function handleToolsList(mixed $id, AgentToolRegistryBridge $bridge): McpResponse
    {
        $tools = [];
        foreach ($bridge->getTools() as $tool) {
            $tools[] = $tool->toMcpDescriptor();
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

        // Durable pre-execution reservation. On the write tier this is
        // fail-closed: if the attempt cannot be made durable, the tool is never
        // invoked, so no mutation can occur without evidence that it was tried.
        $receipt = null;
        if ($this->durableAudit) {
            try {
                $receipt = $this->auditLedger()->reserve(new StrictAuditReservation(
                    correlationId: $correlationId,
                    surface: $this->auditSurface(),
                    operation: $toolName,
                    actorUid: $actorUid,
                    safeArguments: $safeArguments,
                    metadata: ['tier' => $this->rateLimitTier],
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

        $outcome = $bridge->executeClassified($toolName, $arguments);

        if ($receipt !== null) {
            try {
                $this->auditLedger()->finalize($receipt, $outcome->stage, ['tier' => $this->rateLimitTier]);
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
    private function jsonRpcError(int $code, string $message, mixed $id, array $data = []): McpResponse
    {
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
