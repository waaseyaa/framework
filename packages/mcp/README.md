# waaseyaa/mcp

**Layer 6 — Interfaces**

Model Context Protocol (MCP) endpoint for Waaseyaa applications.

Exposes Waaseyaa's `#[AsAgentTool]`-registered tools to external MCP
clients (Claude Code, Cursor, Claude Desktop, custom AI agents) over
Streamable HTTP at `/mcp`. Authenticates requests via
`McpAuthInterface`, then dispatches JSON-RPC payloads against a
per-request `AgentToolRegistryBridge` constructed with the
auth-resolved `AccountInterface`. Per-tool capability gating runs at
the `AbstractAgentTool::requireCapability` boundary against the
account's permissions.

## Quick reference

- **Endpoint:** `POST /mcp` (JSON-RPC; methods: `initialize`, `ping`,
  `tools/list`, `tools/call`).
- **Server card:** `GET /.well-known/mcp.json` (MCP discovery).
- **Authentication:** the public read-only `/mcp` surface defaults to
  `PublicAnonymousAuth`. The `/mcp/write` surface validates
  `Authorization: Bearer <token>` through a fail-closed
  `BearerTokenAuth(tokens: [])` default that applications replace via
  `WriteTierAuthInterface`.
- **Bearer model:** tokens are opaque application-managed credentials. This
  package does not implement OAuth 2.1 scopes, audience checks, expiry, or
  revocation; server cards therefore advertise only `none` or `bearer`.
- **Tool surface:** every class carrying `#[AsAgentTool]` and
  implementing `AgentToolInterface` becomes a callable MCP tool with
  no per-tool MCP code.

## Bimaaji tool family

The first first-party tool family surfaced through this package is
[Bimaaji](../bimaaji/), exposed via five `#[AsAgentTool]` adapters in
`packages/ai-agent/src/Tool/Bimaaji/`:

| Tool name | Capability | Purpose |
|---|---|---|
| `bimaaji_introspect_graph` | `bimaaji.read` | Full application graph (six default sections + version). |
| `bimaaji_introspect_section` | `bimaaji.read` | Single section (admin, entities, jsonapi, public_surface, routing, sovereignty). |
| `bimaaji_propose_mutation` | `bimaaji.mutate` | Validate a proposed schema mutation against the application graph. |
| `bimaaji_generate_patch` | `bimaaji.mutate` | Generate a `PatchSet` from a validated mutation. **Never writes to disk** — the calling MCP client persists. |
| `bimaaji_search_specs` | `bimaaji.read` | Substring search over `docs/specs/*.md`; returns `{file, section_title, line_number, snippet}` per match. |

**Capability model.** `bimaaji.read` is intended to be broadly
granted to authenticated MCP clients; `bimaaji.mutate` is opt-in per
role/account. The framework does not grant either by default — the
integrating application's permission stack does.

**Example claude_desktop_config.json fragment** (replace `<token>`
with the bearer token your `McpAuthInterface` implementation expects):

```json
{
  "mcpServers": {
    "waaseyaa": {
      "url": "https://your-host.example/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

## Key classes

- `McpEndpoint` — JSON-RPC dispatcher; constructs the per-request
  bridge with the auth-resolved account.
- `McpServerCard` — server-card route controller (`/.well-known/mcp.json`).
- `McpRouteProvider` — registers both routes through the package's
  `McpServiceProvider`.
- `McpServiceProvider` — binds public reads to `PublicAnonymousAuth` and the
  write tier to a fail-closed empty opaque-bearer map unless an application
  supplies `WriteTierAuthInterface`.
- `Bridge\AgentToolRegistryBridge` — adapts
  `Waaseyaa\AI\Tools\ToolRegistryInterface` directly to MCP descriptors and
  calls; constructed per-request by `McpEndpoint::dispatch()`.

## Canonical spec

See `docs/specs/mcp-endpoint.md` for the authoritative architecture
documentation including the per-request bridge architecture, the
Bimaaji MCP bridge section (shipped tool inventory, capability model,
M-G → M3 transition rationale), and the post-WP01..WP03 file
reference.

## Legacy surface

The pre-M3 `McpController` + `Tools/*` + `Cache/` + `Rpc/*` files
remain in-place from the original `entity`/`discovery`/`traversal`/
`editorial` tool-class architecture, still test-covered via direct
instantiation in `tests/Integration/Phase14/AiMcpIntegrationTest.php`.
They are no longer reachable from HTTP routing (the foundation
`McpRouter` was retired in M3 WP01). A future cleanup mission may
delete them.
