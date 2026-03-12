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
| `src/McpEndpoint.php` | Thin HTTP handler: auth, JSON-RPC dispatch for `initialize`/`ping`/`tools/list`/`tools/call` via Bridge interfaces |
| `src/McpController.php` | Rich tool controller: manifest, `tools/introspect`, `tools/call` dispatch to tool classes, read-cache orchestration |
| `src/McpResponse.php` | Value object wrapping response body, status code, content type |
| `src/McpRouteProvider.php` | Registers `/mcp` and `/.well-known/mcp.json` routes |
| `src/McpServerCard.php` | Generates the `/.well-known/mcp.json` server card |
| `src/Auth/McpAuthInterface.php` | Pluggable authentication contract |
| `src/Auth/BearerTokenAuth.php` | MVP auth: opaque bearer token to account mapping |
| `src/Bridge/ToolRegistryInterface.php` | Interface for accessing MCP tool definitions |
| `src/Bridge/ToolExecutorInterface.php` | Interface for executing MCP tool calls |
| `src/Cache/ReadCache.php` | Read-path cache: TTL, key generation, tag building, invalidation support |
| `src/Rpc/ResponseFormatter.php` | JSON-RPC response/error formatting, stable contract meta injection, alias canonicalization |
| `src/Rpc/ToolIntrospector.php` | `tools/introspect` diagnostics: per-tool descriptors, extension registration matching |
| `src/Tools/McpTool.php` | Abstract base for tool classes: entity loading, access checks, traversal row collection |
| `src/Tools/DiscoveryTools.php` | `search_entities`, `search_teachings`, `ai_discover` implementations |
| `src/Tools/EditorialTools.php` | `editorial_transition`, `editorial_validate`, `editorial_publish`, `editorial_archive` implementations |
| `src/Tools/EntityTools.php` | `get_entity`, `list_entity_types` implementations |
| `src/Tools/TraversalTools.php` | `traverse_relationships`, `get_related_entities`, `get_knowledge_graph` implementations |

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

## McpController Class

`McpController` is the rich tool controller that handles `tools/list`, `tools/introspect`, and `tools/call` for first-party MCP tools. It is a `final class` that composes extracted tool classes and support services.

### Constructor Dependencies

```php
public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ResourceSerializer $serializer,
    EntityAccessHandler $accessHandler,
    AccountInterface $account,
    EmbeddingStorageInterface $embeddingStorage,
    ?EmbeddingProviderInterface $embeddingProvider = null,
    ?RelationshipTraversalService $relationshipTraversal = null,
    ?CacheBackendInterface $readCache = null,
    array $extensionRegistrations = [],
)
```

The constructor wires up internal collaborators:
- `ResponseFormatter` -- JSON-RPC response/error formatting
- `ToolIntrospector` -- `tools/introspect` diagnostics with extension registrations
- `ReadCache` -- read-path cache handler
- `EntityTools`, `DiscoveryTools`, `TraversalTools`, `EditorialTools` -- tool class instances

### handleRpc() Dispatch

```php
public function handleRpc(array $rpc): array
```

Dispatches JSON-RPC methods:
- `tools/list` -- returns the tool manifest via `ResponseFormatter::result()`
- `tools/introspect` -- delegates to `ToolIntrospector` for per-tool diagnostics
- `tools/call` -- resolves tool name, checks read-cache, dispatches to the appropriate tool class, applies stable contract meta, caches result
- Unknown methods return `-32601`

### Tool Manifest

`McpController::manifest()` returns a static tool list with 12 tools across four categories (discovery, entity, traversal, editorial).

## Tool Classes (`Tools/` Namespace)

Tool logic is extracted from `McpController` into dedicated classes extending `McpTool`.

### McpTool (Abstract Base)

```php
abstract class McpTool
{
    public function __construct(
        protected readonly EntityTypeManagerInterface $entityTypeManager,
        protected readonly ResourceSerializer $serializer,
        protected readonly EntityAccessHandler $accessHandler,
        protected readonly AccountInterface $account,
    ) {}
}
```

Provides shared helpers:
- `loadEntityByTypeAndId()` -- loads a single entity by type ID and entity ID
- `assertTraversalSourceVisible()` -- verifies entity exists and passes view access check; throws on failure
- `collectTraversalRows()` -- gathers relationship traversal rows with visibility filtering, direction/status/type filtering, temporal (`at`) filtering, and deterministic sorting

### EntityTools

- `getEntity(array $arguments)` -- loads and serializes a single entity with access checking
- `listEntityTypes()` -- returns all registered entity type definitions

### DiscoveryTools

Additional constructor dependencies: `EmbeddingStorageInterface`, `?EmbeddingProviderInterface`, `WorkflowVisibility`.

- `searchEntities(array $arguments)` -- semantic/keyword search with workflow-aware visibility
- `searchTeachings(array $arguments)` -- deprecated alias for `searchEntities`
- `aiDiscover(array $arguments)` -- blended discovery combining search, graph context, and scored recommendations

### TraversalTools

Additional constructor dependency: `?RelationshipTraversalService`.

- `traverse(array $arguments)` -- relationship traversal from a source entity
- `getRelated(array $arguments)` -- related entities for a source
- `knowledgeGraph(array $arguments)` -- knowledge graph subgraph from a source entity

### EditorialTools

Additional constructor dependencies: `EditorialWorkflowStateMachine`, `EditorialTransitionAccessResolver`.

- `transition(array $arguments)` -- apply an editorial workflow transition to a node
- `validate(array $arguments)` -- validate transition eligibility without mutating state
- `publish(array $arguments)` -- publish a node through editorial workflow rules
- `archive(array $arguments)` -- archive a node through editorial workflow rules

## RPC Support (`Rpc/` Namespace)

### ResponseFormatter

`final class` that centralizes JSON-RPC response construction:

- `result(mixed $id, mixed $result): array` -- wraps a success result in JSON-RPC 2.0 envelope
- `error(mixed $id, int $code, string $message): array` -- wraps an error in JSON-RPC 2.0 envelope
- `withStableContractMeta(array $result, string $invokedTool): array` -- injects `meta.contract_version`, `meta.contract_stability`, `meta.tool_invoked`, and `meta.tool` (canonical name)
- `canonicalToolName(string $tool): string` -- resolves aliases (`search_teachings` -> `search_entities`)
- `formatToolContent(array $result): array` -- wraps result in MCP content block format (`{content: [{type: "text", text: "..."}]}`)

### ToolIntrospector

`final class` providing per-tool diagnostics for `tools/introspect`:

- `diagnosticsDescriptor(string $tool): array` -- returns handler, category, cache tags, visibility source, workflow policy, permission boundaries, execution path, and failure modes for each tool
- `extensionsForTool(string $requestedTool, string $canonicalTool): array` -- matches extension registrations against the tool (normalizing aliases), returns registered extension IDs, hooks, and execution-path hook markers

The introspection response includes contract metadata at protocol version `2024-11-05` (distinct from the `initialize` handler's `2025-03-26` protocol version in `McpEndpoint`).

## Read-Path Cache (`Cache/` Namespace)

### ReadCache

`final class` managing read-path caching for MCP tool responses:

- **Constructor:** `(AccountInterface $account, ?CacheBackendInterface $backend = null)` -- cache is disabled when backend is null
- **TTL:** 120 seconds (`MAX_AGE` constant)
- **Cache key generation:** `cacheKey(string $tool, array $arguments): ?string` -- SHA-256 hash of contract version + tool + normalized arguments + account context; returns null for non-cacheable tools or serialization failure
- **Cacheable tools:** `search_entities`, `search_teachings`, `ai_discover`, `traverse_relationships`, `get_related_entities`, `get_knowledge_graph`
- **Tag building:** tags include `mcp_read`, contract version, tool name, auth scope, plus entity-type/ID tags extracted from arguments and response payload
- **Key normalization:** arguments are recursively key-sorted for deterministic cache keys regardless of argument ordering
- **Error handling:** cache write failures are logged via `error_log()` (best-effort, never crashes the request)

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
| `tools/introspect` | Returns deterministic tool diagnostics, contract metadata, and extension hook visibility |
| `tools/call` | Executes a tool by name with arguments |

### Discovery Blend Tool Contract (v1.0 stable extension)

Waaseyaa's MCP server exposes 12 first-party tools via `Waaseyaa\Mcp\McpController`, organized into four tool classes:

- **Discovery:** `search_entities`, `search_teachings` (deprecated alias), `ai_discover`
- **Entity:** `get_entity`, `list_entity_types`
- **Traversal:** `traverse_relationships`, `get_related_entities`, `get_knowledge_graph`
- **Editorial:** `editorial_transition`, `editorial_validate`, `editorial_publish`, `editorial_archive`

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
- stable metadata envelope on tool payloads:
  - `meta.contract_version = v1.0`
  - `meta.contract_stability = stable`
  - `meta.tool` (canonical tool)
  - `meta.tool_invoked` (actual invoked tool name)

Canonical search naming:
- `search_entities` is the stable semantic/keyword search contract.
- `search_teachings` is maintained as a backward-compatible alias and is marked deprecated in tool metadata.

Traversal and graph permission boundaries (v1.0 hardening):
- `traverse_relationships`, `get_related_entities`, and `get_knowledge_graph` require a visible source entity.
- Rows referencing inaccessible related entities are filtered out before payload composition.
- Hidden source entities produce deterministic execution errors (`-32000`) instead of partial graph leakage.

Editorial workflow tools (v1.0 stable extension):
- `editorial_transition` applies a named editorial transition to a node entity,
- `editorial_validate` checks transition eligibility without mutating state (dry-run),
- `editorial_publish` and `editorial_archive` are convenience shortcuts for common transitions,
- all editorial tools require entity view+update access via `EntityAccessHandler`,
- transition eligibility is resolved by `EditorialTransitionAccessResolver` against `EditorialWorkflowStateMachine`,
- invalid transitions produce `-32602`, access failures produce `-32000`.

MCP read-path caching (v1.1 hardening):
- read-heavy tool responses are cached for 120 seconds (`search_entities`, `search_teachings`, `ai_discover`, traversal/graph reads),
- cache keys include contract-relevant arguments plus permission/visibility context (`authenticated`, account ID, roles),
- cache keys are deterministic under equivalent argument ordering,
- entity save/delete invalidates tagged MCP cache entries to avoid stale graph/discovery responses,
- payload contract remains stable; caching is transparent to tool consumers.

MCP extension registration diagnostics (v1.3 additive surface):
- `tools/introspect` includes extension registration diagnostics for applicable tools,
- extension diagnostics are additive and do not change `tools/call` result payload shape,
- introspection includes registered extension IDs, hook names, and execution-path hook markers,
- extension tool matching normalizes aliases to canonical tool names (`search_teachings` -> `search_entities`).

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
| `tools/introspect` | Yes | Expanded extension diagnostics |
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
    McpController.php
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
    Cache/
      ReadCache.php
    Rpc/
      ResponseFormatter.php
      ToolIntrospector.php
    Tools/
      McpTool.php
      DiscoveryTools.php
      EditorialTools.php
      EntityTools.php
      TraversalTools.php
  composer.json
```
