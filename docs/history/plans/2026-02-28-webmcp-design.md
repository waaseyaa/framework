# WebMCP Package Design — `aurora/mcp`

**Date:** 2026-02-28
**Status:** Approved
**Layer:** 6 (Interfaces)

## Summary

Add a new `aurora/mcp` package that exposes Aurora's entity system as a remote MCP (Model Context Protocol) server over Streamable HTTP. External AI assistants (Claude, Cursor, etc.) and custom AI agents connect to a single `/mcp` endpoint to discover and invoke CRUD tools for all registered entity types.

The package uses `symfony/mcp-sdk` for spec-compliant protocol handling and bridges it to Aurora's existing `ai-schema` tool definitions and `ai-agent` execution layer.

## Use Cases

1. **AI assistants** — Claude Desktop, Cursor, or Windsurf connect to Aurora and manage content via MCP tools (create nodes, query taxonomy, update media, etc.).
2. **Custom AI agents** — Internal or external agents built with Aurora's `ai-agent` package (or any MCP client) connect programmatically to orchestrate content workflows.

## Approach

**SDK Core + Aurora Extensions (Approach C):**

- `symfony/mcp-sdk` handles Streamable HTTP transport, JSON-RPC 2.0, session management.
- Aurora adds: bridge adapters, pluggable auth, server card, audit integration.
- Spec compliance comes from the SDK; Aurora-specific value comes from the extensions.

## Package Structure

```
packages/mcp/
  src/
    McpEndpoint.php                         # Main HTTP handler
    McpServerCard.php                       # /.well-known/mcp.json generator
    McpRouteProvider.php                    # Registers /mcp and /.well-known routes
    Auth/
      McpAuthInterface.php                  # Pluggable auth contract
      BearerTokenAuth.php                   # MVP: bearer token validation
    Bridge/
      AuroraToolAdapter.php                 # McpToolDefinition → SDK MetadataInterface
      AuroraToolExecutorAdapter.php         # McpToolExecutor → SDK ToolExecutorInterface
  tests/
    Unit/
      McpEndpointTest.php
      McpServerCardTest.php
      McpRouteProviderTest.php
      Auth/
        BearerTokenAuthTest.php
      Bridge/
        AuroraToolAdapterTest.php
  composer.json
```

## Dependencies

```json
{
  "name": "aurora/mcp",
  "require": {
    "php": ">=8.3",
    "aurora/ai-schema": "@dev",
    "aurora/ai-agent": "@dev",
    "aurora/routing": "@dev",
    "symfony/mcp-sdk": "^0.1"
  }
}
```

## Architecture

### Layer Placement

```
Layer 6  Interfaces     cli · ssr · admin · mcp
Layer 5  AI             ai-schema · ai-agent · ai-vector · ai-pipeline
```

The `mcp` package sits in Layer 6 alongside other interface packages. It depends downward on Layer 5 (AI) for tool definitions and execution, and on Layer 2 (Services) for routing.

### Request Flow

```
HTTP Request → McpRouteProvider → McpEndpoint → McpAuthInterface
                                                       ↓
                                              JsonRpcHandler (symfony/mcp-sdk)
                                                       ↓
                                        ToolListHandler / ToolCallHandler
                                                       ↓
                                          AuroraToolAdapter (bridge)
                                                       ↓
                                    SchemaRegistry → McpToolExecutor → Entity Storage
                                                       ↓
                                              JSON-RPC Response
```

### Transport

The MCP spec (protocol version 2025-03-26) defines Streamable HTTP as the remote transport:

- **Single endpoint** at `/mcp` accepts POST and GET.
- **POST** — client sends JSON-RPC messages. Server responds with `application/json` (simple) or `text/event-stream` (long-running).
- **GET** — client opens SSE stream for server-initiated messages.
- **Sessions** — server assigns `Mcp-Session-Id` header; clients include it on subsequent requests. SDK handles this.

## Class Designs

### McpEndpoint

Main HTTP handler. Receives PSR-7 request, runs auth, delegates to SDK.

```php
final readonly class McpEndpoint
{
    public function __construct(
        private JsonRpcHandler $jsonRpcHandler,
        private McpAuthInterface $auth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface;
}
```

- Auth failure returns 401 JSON-RPC error: `{"jsonrpc": "2.0", "error": {"code": -32001, "message": "Unauthorized"}, "id": null}`
- Valid requests delegate to `JsonRpcHandler` which dispatches to tool handlers.
- Returns PSR-7 response (Symfony HTTP Foundation bridge converts for the framework).

### McpAuthInterface

Pluggable authentication contract. One method.

```php
interface McpAuthInterface
{
    /**
     * Authenticate the request. Returns the account if valid, null otherwise.
     */
    public function authenticate(ServerRequestInterface $request): ?AccountInterface;
}
```

### BearerTokenAuth

MVP implementation. Maps opaque tokens to user accounts.

```php
final readonly class BearerTokenAuth implements McpAuthInterface
{
    /** @param array<string, AccountInterface> $tokens Token → account mapping */
    public function __construct(
        private array $tokens,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?AccountInterface;
}
```

- Reads `Authorization: Bearer <token>` header.
- Looks up token in the map.
- Each token maps to a specific user account, so MCP tool calls respect entity access control.
- No token expiry in MVP. OAuth 2.1 adapter replaces this later without changes to McpEndpoint.

### AuroraToolAdapter

Bridges Aurora's `McpToolDefinition` to the SDK's `MetadataInterface`.

```php
final readonly class AuroraToolAdapter implements MetadataInterface
{
    public function __construct(
        private McpToolDefinition $definition,
    ) {}

    public function getName(): string;
    public function getDescription(): string;
    public function getInputSchema(): array;
}
```

- One adapter instance per tool definition.
- Auto-discovered from `SchemaRegistry::getTools()` at construction time.
- All registered entity types get their 5 CRUD tools exposed automatically.

### AuroraToolExecutorAdapter

Bridges Aurora's `McpToolExecutor` to the SDK's `ToolExecutorInterface`.

```php
final readonly class AuroraToolExecutorAdapter implements ToolExecutorInterface, IdentifierInterface
{
    public function __construct(
        private McpToolExecutor $executor,
        private string $toolName,
    ) {}

    public function getName(): string;
    public function call(ToolCall $input): ToolCallResult;
}
```

- Delegates `call()` to `McpToolExecutor::execute($toolName, $arguments)`.
- Wraps the result in SDK's `ToolCallResult`.

### McpServerCard

Value object for `/.well-known/mcp.json`. Ahead of the June 2026 spec.

```php
final readonly class McpServerCard
{
    public function __construct(
        private string $name = 'Aurora CMS',
        private string $version = '0.1.0',
        private string $endpoint = '/mcp',
    ) {}

    public function toArray(): array;
}
```

Output:

```json
{
  "name": "Aurora CMS",
  "version": "0.1.0",
  "description": "AI-native content management system",
  "endpoint": "/mcp",
  "transport": "streamable-http",
  "capabilities": {
    "tools": true,
    "resources": false,
    "prompts": false
  },
  "authentication": {
    "type": "bearer"
  }
}
```

### McpRouteProvider

Registers two routes following Aurora conventions.

```php
final readonly class McpRouteProvider
{
    public function registerRoutes(AuroraRouter $router): void;
}
```

Routes:
- `GET /.well-known/mcp.json` → server card (public, no auth) — `mcp.server_card`
- `POST|GET /mcp` → endpoint (auth required) — `mcp.endpoint`

## Audit Integration

No new audit infrastructure. MCP requests piggyback on existing systems:

- **Tool calls** already log via `McpToolExecutor` → `AgentExecutor::executeTool()` → `AgentAuditLog`.
- **MCP requests** add one new audit entry type: `mcp_request` — logs authenticated account, JSON-RPC method, and timestamp via `AgentAuditLog`.

## Authentication Roadmap

| Phase | Implementation | Notes |
|-------|---------------|-------|
| MVP (v0.1.0) | `BearerTokenAuth` | Opaque tokens, no expiry |
| v0.2.0 | OAuth 2.1 adapter | PKCE, resource indicators, protected resource metadata (RFC 9728) |
| v0.3.0+ | Scoped permissions | Per-tool authorization, rate limiting |

The `McpAuthInterface` contract does not change across phases. Only the implementation swaps.

## MCP Feature Scope

| Feature | MVP | Future |
|---------|-----|--------|
| `tools/list` | Yes | — |
| `tools/call` | Yes | — |
| `resources/list` | No | v0.2.0+ |
| `resources/read` | No | v0.2.0+ |
| `prompts/list` | No | v0.3.0+ |
| Server card | Yes | Evolves with spec |
| SSE streaming | Via SDK | — |
| Session management | Via SDK | — |

## Testing Strategy

### Unit Tests

**McpEndpointTest:**
- Valid `tools/list` request returns tool definitions.
- Valid `tools/call` request executes tool and returns result.
- Missing auth header returns 401 JSON-RPC error.
- Invalid token returns 401 JSON-RPC error.
- Invalid JSON-RPC payload returns parse error.

**BearerTokenAuthTest:**
- Valid token returns correct `AccountInterface`.
- Missing `Authorization` header returns null.
- Malformed header (no `Bearer ` prefix) returns null.
- Unknown token returns null.

**AuroraToolAdapterTest:**
- Adapts `McpToolDefinition` name, description, and inputSchema correctly.
- Tool execution delegates to `McpToolExecutor` with correct arguments.
- Execution errors wrap in SDK error format.

**McpServerCardTest:**
- `toArray()` produces valid server card structure.

**McpRouteProviderTest:**
- Registers both routes with correct paths and methods.

### Integration Test

Following Phase 10 smoke test pattern:
- Boot full stack → register entity types → POST `/mcp` with `tools/list` → verify all entity CRUD tools appear.
- POST `/mcp` with `tools/call` for `create_node` → verify entity created in storage.

## Spec Compatibility Notes

- **Current spec:** MCP protocol version 2025-03-26 (Streamable HTTP).
- **June 2026 spec** plans: stateless protocol, `/.well-known/mcp.json` server cards, HTTP-friendly routing. Our server card implementation positions us ahead of this. The stateless changes will simplify `McpEndpoint` (fewer sessions to manage), not complicate it.
- **Auth spec** is still in draft. Our pluggable interface insulates us from changes.
