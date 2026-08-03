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
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
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
    ) {}

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
        // Authenticate.
        $authenticated = $this->auth->authenticate($authorizationHeader);
        if ($authenticated === null) {
            return new McpResponse(
                body: \json_encode([
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                    'id' => null,
                ], \JSON_THROW_ON_ERROR),
                statusCode: 401,
            );
        }
        $principal = DecisionAccountResolver::resolve($authenticated, $authenticated);
        if ($principal === null) {
            return new McpResponse(
                body: \json_encode([
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                    'id' => null,
                ], \JSON_THROW_ON_ERROR),
                statusCode: 401,
            );
        }

        // Per-principal rate limiting (post-auth so 401s never consume budget).
        if ($this->rateLimiter !== null && $this->rateLimitMaxRequests > 0) {
            try {
                $key = sprintf('mcp:%s:%s', $this->rateLimitTier, (string) $principal->id());
                if ($this->rateLimiter->tooManyAttempts($key, $this->rateLimitMaxRequests)) {
                    return new McpResponse(
                        body: \json_encode([
                            'jsonrpc' => '2.0',
                            'error' => [
                                'code' => -32029,
                                'message' => 'Rate limit exceeded',
                                'data' => ['retry_after_seconds' => $this->rateLimitWindowSeconds],
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

            if (!\is_array($request) || !isset($request['method'])) {
                return $this->jsonRpcError(-32600, 'Invalid Request', $request['id'] ?? null);
            }

            $id = $request['id'] ?? null;
            $params = $request['params'] ?? [];

            // Fire the dispatch event exactly once per authenticated,
            // well-formed request — post-auth, post-parse, pre-routing
            // (research D5, contract clause 16). Params are carried RAW;
            // the audit listener hashes them (clause 17).
            try {
                $this->dispatcher?->dispatch(
                    new McpDispatchEvent(
                        method: (string) $request['method'],
                        params: \is_array($params) ? $params : [],
                        accountUid: self::stableAccountUid($authenticated->id()),
                    ),
                    McpDispatchEvent::NAME,
                );
            } catch (\Throwable) {
                // Best-effort: an audit/dispatcher failure must never alter
                // the JSON-RPC response (contract clause 19).
            }

            // A `params` member that is not a JSON object cannot address any
            // method's parameters; treat it as an invalid-params envelope
            // rather than silently substituting an empty bag.
            if (!\is_array($params)) {
                return $this->jsonRpcError(-32602, 'Invalid params: must be an object', $id);
            }

            $route = fn(): McpResponse => match ($request['method']) {
                'initialize' => $this->handleInitialize($id),
                'ping' => $this->handlePing($id),
                'tools/list' => $this->handleToolsList($id, $bridge),
                'tools/call' => $this->handleToolsCall($id, $params, $bridge),
                default => $this->jsonRpcError(-32601, "Method not found: {$request['method']}", $id),
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
    private function handleToolsCall(mixed $id, array $params, AgentToolRegistryBridge $bridge): McpResponse
    {
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if ($toolName === null) {
            return $this->jsonRpcError(-32602, 'Missing required parameter: name', $id);
        }

        if (!\is_string($toolName)) {
            return $this->jsonRpcError(-32602, 'Invalid parameter: name must be a string', $id);
        }

        // `{}` and `[]` both decode to []; anything else is not a JSON object.
        if (!\is_array($arguments) || (array_is_list($arguments) && $arguments !== [])) {
            return $this->jsonRpcError(-32602, 'Invalid parameter: arguments must be an object', $id);
        }

        $tool = $bridge->getTool($toolName);
        if ($tool === null) {
            return $this->jsonRpcError(-32602, "Unknown tool: {$toolName}", $id);
        }

        $result = $bridge->execute($toolName, $arguments);

        return $this->jsonRpcResult($id, $result);
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

    private function jsonRpcError(int $code, string $message, mixed $id): McpResponse
    {
        return new McpResponse(
            body: \json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => $code, 'message' => $message],
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
