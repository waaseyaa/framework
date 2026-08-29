---
name: waaseyaa:mcp-endpoint
description: Use when working with the MCP server endpoint, JSON-RPC handling, tool registry, authentication, or files in packages/mcp/
---

# MCP Endpoint Specialist

## Scope

This skill covers the MCP (Model Context Protocol) package:

- `packages/mcp/src/` -- McpEndpoint, McpResponse, McpRouteProvider, McpServerCard
- `packages/mcp/src/Auth/` -- McpAuthInterface, BearerTokenAuth
- `packages/mcp/src/Bridge/` -- ToolRegistryInterface, ToolExecutorInterface

Use this skill when:
- Adding or modifying the MCP HTTP endpoint
- Working with JSON-RPC protocol handling
- Changing MCP authentication
- Adding new JSON-RPC methods or tool capabilities
- Wiring MCP routes (`McpServiceProvider::routes()`, not the front controller)
- Working with the tool registry or executor bridge

## Key Interfaces

### McpEndpoint (packages/mcp/src/McpEndpoint.php)

`__construct` takes `McpAuthInterface $auth` and
`Waaseyaa\AI\Tools\ToolRegistryInterface $agentRegistry` followed by ~20
optional, named-only collaborators (dispatcher, rate limiter, audit ledger,
approval store, allowed origins, OAuth metadata, content resources…). **Always
construct it with named arguments** — positional construction is unreadable and
breaks whenever a parameter is inserted:

```php
$endpoint = new McpEndpoint(
    auth: $auth,
    agentRegistry: $registry,
    unauthorizedChallenge: $challenge,   // optional
);
```

Two entry points, both taking the resolved account and the HTTP request:

```php
public function handle(AccountInterface $account, HttpRequest $request): McpResponse;
public function serve(AccountInterface $account, HttpRequest $request): HttpResponse;
```

`serve()` is the route controller: it runs `StreamableHttpTransportGuard`, then
wraps `handle()` in a Symfony response (the kernel's dispatcher cannot render a
bare `McpResponse`). `handle()` is the JSON-RPC core — use it in unit tests.

Note the account argument is the *request principal* resolved by the kernel;
the `Authorization` header is read off `$request` and passed to
`McpAuthInterface`. The two are not the same thing, and on the public tier
`PublicAnonymousAuth` authenticates every request without a header.

### McpResponse (packages/mcp/src/McpResponse.php)

```php
final readonly class McpResponse
{
    public function __construct(
        public string $body,
        public int $statusCode = 200,
        public string $contentType = 'application/json',
        /** @var array<string, string> */
        public array $headers = [],
    ) {}
}
```

### McpAuthInterface (packages/mcp/src/Auth/McpAuthInterface.php)

```php
interface McpAuthInterface
{
    public function authenticate(?string $authorizationHeader): ?AuthorizationPrincipalInterface;
}
```

Returns an `AuthorizationPrincipalInterface` on success, `null` on failure — a
principal, not a bare `AccountInterface`. Implementations: `PublicAnonymousAuth`
(the public tier — admits everyone), `BearerTokenAuth` (static token map),
`DurableBearerTokenAuth` (hashed, expiring, revocable operator-issued tokens —
the framework default for the write tier), and `OAuthMcpAuth`.
`ScopedMcpAuthInterface` extends it with `authenticateWithScopes()` so a
credential can also narrow the visible tool surface.

### Tool registry — `Waaseyaa\AI\Tools\ToolRegistryInterface`

There is **no** `packages/mcp/src/Bridge/ToolRegistryInterface.php` or
`ToolExecutorInterface.php`, and no `McpToolDefinition`. Those MCP-local bridge
interfaces were removed. The endpoint consumes the Layer-5 agent-tool registry
directly:

```php
interface ToolRegistryInterface   // Waaseyaa\AI\Tools
{
    public function register(AgentTool $tool): void;
    public function get(string $name): AgentTool;   // throws ToolNotFoundException
    public function has(string $name): bool;
    /** @return iterable<AgentTool> */
    public function all(): iterable;
}
```

`Bridge/AgentToolRegistryBridge` is the request-scoped adapter over it. Two
decorators shape what a tier can see, and both are fail-closed:

- `ReadOnlyToolRegistry` — the public tier; destructive tools are structurally
  absent, not merely refused.
- `CapabilityScopedToolRegistry` — the write tier; admits only capabilities on
  the `mcp.write_tier.capabilities` allowlist, minus the generic
  entity-mutation blocklist.

## Architecture

### JSON-RPC Dispatch Flow

```
POST /mcp
  -> McpEndpoint::serve($account, $request)
    -> StreamableHttpTransportGuard  -> Origin/Accept/Content-Type/size refusals
  -> McpEndpoint::handle($account, $request)
    -> auth->authenticate($request Authorization) -> principal | null (-32001, 401)
       NOTE: on the public tier this is PublicAnonymousAuth, which admits a
       request with NO Authorization header. Anonymous /mcp does not 401.
    -> json_decode($body) -> parse error (-32700) | invalid request (-32600)
    -> match $request['method']:
        'initialize' -> protocol version, capabilities, server info
        'ping'       -> empty result
        'tools/list' -> ToolRegistryInterface::getTools() -> toArray()
        'tools/call' -> ToolExecutorInterface::execute($name, $arguments)
        default      -> method not found (-32601)
```

### JSON-RPC Error Codes

`Waaseyaa\Mcp\McpErrorCode` is the single allocation point and states the
policy (#2561). The revision this server advertises reserves `-32020..-32099`
for the MCP specification itself, so refusals this specification does not define
live in a `-31xxx` band outside the JSON-RPC reserved range — do not add a new
literal in a reserved band, the Architecture suite fails on it.

| Code | Meaning |
|------|---------|
| `-32700` | Parse error (invalid JSON) |
| `-32600` | Invalid request (missing `method` field, unparseable `Content-Length`) |
| `-32601` | Method not found |
| `-32602` | Invalid params (missing tool name, unknown tool, unknown resource URI) |
| `-32001` | Unauthorized (auth failure) |
| `-32003` | Approval required — write-tier human-approval challenge; retry with the approval request id |
| `-32004` | Approval refused — terminal half of the `-32003` handshake |
| `-32020` | `HeaderMismatch` (MCP-defined) |
| `-32021` | `MissingRequiredClientCapability` (MCP-defined) |
| `-32022` | `UnsupportedProtocolVersion` (MCP-defined) |
| `-31040` | Forbidden origin |
| `-31041` | `Accept` lacks a required media type |
| `-31042` | `Content-Type` is not `application/json` |
| `-31043` | Request body exceeds the transport maximum |
| `-31029` | Rate limit exceeded (`data.retry_after_seconds`) |
| `-31030` | Rate limiter unavailable (fails closed) |
| `-31001` | Audit trail unavailable |
| `-31002` | Approval store unavailable |

See `docs/upgrade-notes/mcp-error-code-allocation.md` for the migration from
the retired `-32002`/`-3204x` codes.

### Routes

`McpRouteProvider` registers up to four routes, wired by
`McpServiceProvider::routes()`:

| Route | Path | Methods | Registered when | Auth |
|-------|------|---------|-----------------|------|
| `mcp.endpoint` | `/mcp` | POST, GET | `mcp.public.enabled` | Anonymous read-only tier |
| `mcp.server_card` | `/.well-known/mcp.json` | GET | `mcp.public.enabled` | Public |
| `mcp.endpoint.write` | `/mcp/write` | POST, GET | Always | See `resolveWriteTierAuth()` below |
| `mcp.oauth_protected_resource` | Configured metadata path | GET | OAuth resource metadata configured | Public |

`resolveWriteTierAuth()` resolves in this order — an application binding is an
**override, not a requirement**:

1. an application-bound `WriteTierAuthInterface`, if any;
2. otherwise `DurableBearerTokenAuth`, whenever the kernel can supply the bearer
   token store and user repository. This is the normal case: a stock kernel
   authenticates operator-issued `mbt_*` tokens with no application auth code.
   Do **not** bind a static `BearerTokenAuth` map "to enable the tier" — it
   shadows this durable default;
3. otherwise `BearerTokenAuth([])`, which 401s every request.

Every branch is fail-closed: a fresh deployment has no tokens, so the tier 401s
until an operator issues one.

The public tier is removed outright when `mcp.public.enabled` is false, so the
surface answers 404 rather than confirming an MCP server behind a half-disabled
endpoint. Both endpoint routes are `csrfExempt()` and declare a JSON-RPC
refusal transport, so a kernel-level refusal (oversize body, malformed JSON)
reaches the client as JSON-RPC rather than JSON:API (#2594).

### Package Dependencies

- **Layer 6** (Interfaces) -- can import from layers 0-5
- Depends on: `waaseyaa/ai-schema` (McpToolDefinition), `waaseyaa/ai-agent` (AgentExecutor), `waaseyaa/routing`, `waaseyaa/access` (AccountInterface)

## Common Mistakes

### JSON symmetry

Always pair `json_encode(..., JSON_THROW_ON_ERROR)` with `json_decode(..., JSON_THROW_ON_ERROR)`. The endpoint already does this correctly -- maintain it.

### AccountInterface, not concrete User

The auth interface returns `?AccountInterface` (from `waaseyaa/access`), not a concrete `User` class (from `waaseyaa/user`). The MCP package must not depend on `waaseyaa/user`.

### Tool result format

Tool executor must return `{content: [{type: "text", text: "..."}]}`. The `isError` key is optional (defaults to false). Don't return raw strings or arrays -- wrap in the content block format.

### php://input is single-read

`HttpRequest::createFromGlobals()` consumes `php://input`. The front controller must pass the body via `$httpRequest->getContent()`, not re-read `php://input`.

### Final classes cannot be mocked

All concrete classes are `final readonly class`. Use real instances in tests:

```php
// Auth: use BearerTokenAuth with known tokens
$auth = new BearerTokenAuth(['test-token' => $account]);

// Registry/Executor: use anonymous classes implementing the interfaces
$registry = new class implements ToolRegistryInterface { ... };
$executor = new class implements ToolExecutorInterface { ... };

$endpoint = new McpEndpoint($auth, $registry, $executor);
$response = $endpoint->handle('POST', $body, 'Bearer test-token');
```

## Testing Patterns

### Unit Testing McpEndpoint

Construct with named arguments and drive `handle()` with a real `HttpRequest`.
`serve()` additionally runs the transport guard, so use it when the transport
contract is what is under test.

```php
$endpoint = new McpEndpoint(
    auth: new PublicAnonymousAuth(),
    agentRegistry: $registry,   // Waaseyaa\AI\Tools\ToolRegistryInterface
);

$request = Request::create('/mcp', 'POST', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json, text/event-stream',
], content: json_encode(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1]));

$response = $endpoint->handle($account, $request);
self::assertSame(200, $response->statusCode);
```

Assert refusals as `[status, error code]` together, never the status alone — a
status cannot tell a forbidden `Origin` from a malformed one, and 406 is shared
by two distinct `Accept` refusals. See `StreamableHttpTransportGuardTest`.

### Testing BearerTokenAuth

```php
$auth = new BearerTokenAuth(['secret' => $account]);

assert($auth->authenticate(null) === null);
assert($auth->authenticate('Bearer wrong') === null);
assert($auth->authenticate('Bearer secret') === $account);
assert($auth->authenticate('bearer secret') === $account); // case-insensitive
```

### Testing McpServerCard

```php
$card = new McpServerCard(name: 'Test', version: '1.0.0', endpoint: '/mcp');
$json = $card->toJson();
$decoded = json_decode($json, true);
assert($decoded['endpoint'] === '/mcp');
assert($decoded['transport'] === 'streamable-http');
```

## Related Specs

- `docs/specs/mcp-endpoint.md` -- Full MCP endpoint specification
- `docs/specs/ai-integration.md` -- AI layer that provides McpToolDefinition and AgentExecutor
- `CLAUDE.md` -- Project-wide gotchas including JSON symmetry and AccountInterface vs User
