# MCP Endpoint

## Overview

The `waaseyaa/mcp` package exposes Waaseyaa's entity system as a remote MCP (Model Context Protocol) server over Streamable HTTP. External AI assistants (Claude Desktop, Cursor, etc.) and custom AI agents connect to a single `/mcp` endpoint to discover and invoke CRUD tools for all registered entity types. The package sits in Layer 6 (Interfaces) alongside CLI, SSR, and Admin.

## Package

- **Location:** `packages/mcp/`
- **Namespace:** `Waaseyaa\Mcp\`
- **Dependencies:** `waaseyaa/ai-schema`, `waaseyaa/ai-agent`, `waaseyaa/routing`, `waaseyaa/access`

### Source Files

| File | Purpose |
|------|---------|
| `src/McpEndpoint.php` | Main HTTP handler: auth, JSON-RPC dispatch, tool list/call |
| `src/McpResponse.php` | Value object wrapping response body, status code, content type |
| `src/McpRouteProvider.php` | Registers `/mcp` and `/.well-known/mcp.json` routes |
| `src/McpServerCard.php` | Generates the `/.well-known/mcp.json` server card |
| `src/Auth/McpAuthInterface.php` | Pluggable authentication contract |
| `src/Auth/BearerTokenAuth.php` | MVP auth: opaque bearer token to account mapping |
| `src/Bridge/ToolRegistryInterface.php` | Interface for accessing MCP tool definitions |
| `src/Bridge/ToolExecutorInterface.php` | Interface for executing MCP tool calls |

## McpEndpoint Class

`McpEndpoint` is the main HTTP handler. It is a `final readonly class` that receives three dependencies via constructor injection:

- `McpAuthInterface $auth` -- authenticates the request.
- `ToolRegistryInterface $registry` -- provides tool definitions.
- `ToolExecutorInterface $executor` -- executes tool calls.

### handle() Method

```php
public function handle(
    string $method,
    string $body,
    ?string $authorizationHeader,
): McpResponse
```

The method processes requests in this order:

1. **Authenticate** -- calls `$this->auth->authenticate($authorizationHeader)`. If null is returned, responds with HTTP 401 and a JSON-RPC error (code `-32001`, message "Unauthorized").
2. **Parse JSON-RPC** -- decodes the body with `json_decode()`. On `JsonException`, returns parse error (code `-32700`). On missing `method` field, returns invalid request (code `-32600`).
3. **Dispatch** -- matches the JSON-RPC method to an internal handler:
   - `initialize` -- returns protocol version (`2025-03-26`), capabilities, and server info.
   - `ping` -- returns an empty result.
   - `tools/list` -- iterates `$registry->getTools()` and returns tool definitions.
   - `tools/call` -- validates `params.name`, looks up the tool, and delegates to `$executor->execute()`.
   - Any other method returns a "Method not found" error (code `-32601`).

### McpResponse

A `final readonly class` value object:

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

All endpoint responses are wrapped in `McpResponse`. The front controller converts this to a proper HTTP response.

## Authentication

### McpAuthInterface

```php
interface McpAuthInterface
{
    public function authenticate(?string $authorizationHeader): ?AccountInterface;
}
```

Takes the raw `Authorization` header value. Returns the authenticated `AccountInterface` or `null` on failure. The interface is deliberately minimal so implementations can be swapped without changing the endpoint.

### BearerTokenAuth

MVP implementation that maps opaque bearer tokens to user accounts:

```php
final readonly class BearerTokenAuth implements McpAuthInterface
{
    /** @param array<string, AccountInterface> $tokens */
    public function __construct(private array $tokens) {}
}
```

Behavior:
- Returns `null` if the header is missing or empty.
- Returns `null` if the header does not start with `Bearer ` (case-insensitive check).
- Extracts the token (characters after `Bearer `) and looks it up in the `$tokens` map.
- Each token maps to a specific user account, so MCP tool calls respect entity access control.
- No token expiry in MVP. OAuth 2.1 adapter replaces this later.

### Authentication Roadmap

| Phase | Implementation | Notes |
|-------|---------------|-------|
| MVP (v0.1.0) | `BearerTokenAuth` | Opaque tokens, no expiry |
| v0.2.0 | OAuth 2.1 adapter | PKCE, resource indicators, RFC 9728 |
| v0.3.0+ | Scoped permissions | Per-tool authorization, rate limiting |

## Tool Registry

### ToolRegistryInterface

```php
interface ToolRegistryInterface
{
    /** @return McpToolDefinition[] */
    public function getTools(): array;

    public function getTool(string $name): ?McpToolDefinition;
}
```

Abstracts the `SchemaRegistry` so the MCP endpoint can be tested independently. Each `McpToolDefinition` (from `waaseyaa/ai-schema`) provides a name, description, and JSON Schema input definition. Tool definitions are auto-discovered from all registered entity types -- each entity type gets 5 CRUD tools (create, read, update, delete, list).

### Tool Discovery Flow

```
EntityType registration
    -> SchemaRegistry builds McpToolDefinition[]
    -> ToolRegistryInterface exposes them
    -> McpEndpoint::handleToolsList() serializes via toArray()
```

## Bridge Adapters

The `Bridge/` namespace contains interfaces that decouple the MCP endpoint from concrete AI-layer classes.

### ToolRegistryInterface

Bridges `SchemaRegistry::getTools()` to the endpoint. See Tool Registry section above.

### ToolExecutorInterface

```php
interface ToolExecutorInterface
{
    /**
     * @param string $toolName e.g. "create_node", "read_user"
     * @param array<string, mixed> $arguments Tool input arguments.
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function execute(string $toolName, array $arguments): array;
}
```

Delegates to `McpToolExecutor` which routes through `AgentExecutor::executeTool()` into entity storage. The return format follows the MCP tool result specification: an array of content blocks, each with a `type` (typically `"text"`) and `text` value.

### Execution Flow

```
McpEndpoint::handleToolsCall()
    -> ToolExecutorInterface::execute($toolName, $arguments)
    -> McpToolExecutor -> AgentExecutor::executeTool()
    -> Entity Storage (CRUD)
    -> Result as {content: [{type: "text", text: "..."}]}
```

## JSON-RPC Protocol

All communication uses JSON-RPC 2.0 over HTTP.

### Supported Methods

| Method | Description |
|--------|-------------|
| `initialize` | Returns protocol version, capabilities, server info |
| `ping` | Health check, returns empty result |
| `tools/list` | Returns all registered tool definitions |
| `tools/call` | Executes a tool by name with arguments |

### Discovery Blend Tool Contract (v0.9 extension)

Waaseyaa's MCP server also exposes first-party discovery tools from `Waaseyaa\Mcp\McpController`, including `ai_discover`.

`ai_discover` combines:
- semantic/keyword search output from `SearchController`,
- relationship graph context summaries for optional anchor entities,
- deterministic explanation payloads per recommendation.

Contract guarantees:
- workflow-correct public results (`node` recommendations are published-only),
- stable JSON shape for recommendation explanations,
- stable error paths:
  - invalid argument contract violations => JSON-RPC `-32602`,
  - unauthorized/non-public anchor execution failures => JSON-RPC `-32000`.

### Request Format

```json
{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "params": {
        "name": "create_node",
        "arguments": {"title": "Hello", "body": "World"}
    },
    "id": 1
}
```

### Success Response

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "content": [{"type": "text", "text": "{\"id\": 42}"}]
    }
}
```

### Error Response

```json
{
    "jsonrpc": "2.0",
    "error": {"code": -32601, "message": "Method not found: resources/list"},
    "id": 1
}
```

### Error Codes

| Code | Meaning |
|------|---------|
| `-32700` | Parse error (invalid JSON) |
| `-32600` | Invalid request (missing `method` field) |
| `-32601` | Method not found |
| `-32602` | Invalid params (missing tool name, unknown tool) |
| `-32001` | Unauthorized (auth failure) |

### Transport

The MCP spec (protocol version 2025-03-26) defines Streamable HTTP as the remote transport:

- Single endpoint at `/mcp` accepts POST and GET.
- POST sends JSON-RPC messages. Server responds with `application/json`.
- GET opens SSE stream for server-initiated messages (future).
- Sessions use `Mcp-Session-Id` header.

## Routes

`McpRouteProvider` registers two routes:

| Route Name | Path | Methods | Auth |
|------------|------|---------|------|
| `mcp.endpoint` | `/mcp` | POST, GET | Required |
| `mcp.server_card` | `/.well-known/mcp.json` | GET | Public (`allowAll()`) |

### Server Card

`McpServerCard` generates the `/.well-known/mcp.json` response:

```json
{
    "name": "Waaseyaa",
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

## MCP Feature Scope

| Feature | MVP | Future |
|---------|-----|--------|
| `tools/list` | Yes | -- |
| `tools/call` | Yes | -- |
| `resources/list` | No | v0.2.0+ |
| `resources/read` | No | v0.2.0+ |
| `prompts/list` | No | v0.3.0+ |
| Server card | Yes | Evolves with spec |
| SSE streaming | No | Via SDK |
| Session management | No | Via SDK |

## File Reference

```
packages/mcp/
  src/
    McpEndpoint.php
    McpResponse.php
    McpRouteProvider.php
    McpServerCard.php
    Auth/
      McpAuthInterface.php
      BearerTokenAuth.php
    Bridge/
      ToolRegistryInterface.php
      ToolExecutorInterface.php
  composer.json
```
