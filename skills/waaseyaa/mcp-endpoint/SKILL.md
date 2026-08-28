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
- Wiring MCP routes into the front controller
- Working with the tool registry or executor bridge

## Key Interfaces

### McpEndpoint (packages/mcp/src/McpEndpoint.php)

```php
final readonly class McpEndpoint
{
    public function __construct(
        private McpAuthInterface $auth,
        private ToolRegistryInterface $registry,
        private ToolExecutorInterface $executor,
    ) {}

    public function handle(
        string $method,
        string $body,
        ?string $authorizationHeader,
    ): McpResponse;
}
```

Single entry point for all MCP traffic. Authenticates, parses JSON-RPC, dispatches to internal handlers.

### McpResponse (packages/mcp/src/McpResponse.php)

```php
final readonly class McpResponse
{
    public function __construct(
        public string $body,
        public int $statusCode = 200,
        public string $contentType = 'application/json',
    ) {}
}
```

### McpAuthInterface (packages/mcp/src/Auth/McpAuthInterface.php)

```php
interface McpAuthInterface
{
    public function authenticate(?string $authorizationHeader): ?AccountInterface;
}
```

Returns `AccountInterface` on success, `null` on failure. `BearerTokenAuth` is the MVP implementation mapping opaque tokens to accounts.

### ToolRegistryInterface (packages/mcp/src/Bridge/ToolRegistryInterface.php)

```php
interface ToolRegistryInterface
{
    /** @return McpToolDefinition[] */
    public function getTools(): array;
    public function getTool(string $name): ?McpToolDefinition;
}
```

### ToolExecutorInterface (packages/mcp/src/Bridge/ToolExecutorInterface.php)

```php
interface ToolExecutorInterface
{
    public function execute(string $toolName, array $arguments): array;
}
```

Returns MCP tool result format: `{content: [{type: "text", text: "..."}], isError?: bool}`.

## Architecture

### JSON-RPC Dispatch Flow

```
POST /mcp
  -> McpEndpoint::handle()
    -> authenticate($authorizationHeader) -> AccountInterface | null (401)
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
| `mcp.endpoint.write` | `/mcp/write` | POST, GET | Always | Fail-closed: 401 without a `WriteTierAuthInterface` |
| `mcp.oauth_protected_resource` | Configured metadata path | GET | OAuth resource metadata configured | Public |

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

```php
$auth = new BearerTokenAuth(['tok' => $account]);
$registry = new class implements ToolRegistryInterface {
    public function getTools(): array { return []; }
    public function getTool(string $name): ?McpToolDefinition { return null; }
};
$executor = new class implements ToolExecutorInterface {
    public function execute(string $toolName, array $arguments): array {
        return ['content' => [['type' => 'text', 'text' => 'ok']]];
    }
};

$endpoint = new McpEndpoint($auth, $registry, $executor);

// Test auth failure
$response = $endpoint->handle('POST', '{}', null);
assert($response->statusCode === 401);

// Test ping
$body = json_encode(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1]);
$response = $endpoint->handle('POST', $body, 'Bearer tok');
assert($response->statusCode === 200);
```

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
