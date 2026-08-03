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
  `PublicAnonymousAuth`, overridable by binding `McpAuthInterface` and
  disableable via `mcp.public.enabled` — see
  [Controlling the public endpoint](#controlling-the-public-endpoint). The
  `/mcp/write` surface validates `Authorization: Bearer <token>` through a
  fail-closed `BearerTokenAuth(tokens: [])` default that applications replace
  via `WriteTierAuthInterface`.
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

## Controlling the public endpoint

> **Installing this package serves an anonymous endpoint.** Adding
> `waaseyaa/mcp` registers `McpServiceProvider` through package discovery,
> which routes `POST /mcp` and `GET /.well-known/mcp.json` with no
> credential required. That is the designed default — a public read-only
> tool surface — but it is a deliberate decision, not a neutral one. Decide
> which of the three shapes below you want before deploying.

### 1. Anonymous read-only (default)

No configuration. Every request resolves to an `AnonymousUser` holding only
`PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES`, and `ReadOnlyToolRegistry`
makes destructive tools structurally absent. What an anonymous caller can
reach is the intersection of *non-destructive* and *on that capability list*.
Audit that intersection for your install — it includes the Bimaaji
introspection tools, which describe your application's architecture.

### 2. Authenticated public tier

Bind `McpAuthInterface` in your own service provider. The framework binds no
default, so yours is what `/mcp` uses:

```php
final class AppMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(
            McpAuthInterface::class,
            fn(): McpAuthInterface => new BearerTokenAuth([
                $_ENV['MCP_READ_TOKEN'] => $this->resolve(AccountRepository::class)->load(42),
            ]),
        );
    }
}
```

`BearerTokenAuth` fails closed: an absent, unknown, or inactive-account token
yields HTTP 401. To keep anonymous access *and* recognise tokens, wrap it —
`new PublicAnonymousAuth(delegate: $bearerAuth)` tries the token first and
falls back to anonymous.

Provider ordering does not matter. The package deliberately does not bind
`McpAuthInterface` locally, because `ServiceProvider::resolve()` consults a
provider's own bindings before the cross-provider kernel-services bus — a
package default there would silently beat your binding. This is the same
precedence rule already relied on by `WriteTierAuthInterface`.

### 3. No public tier

```php
// config/mcp.php  (or the equivalent config-sync key)
return [
    'public' => ['enabled' => false],
];
```

`mcp.public.enabled` defaults to **true**; set it false and neither `/mcp`
nor `/.well-known/mcp.json` is registered — both return **404**. The routes
are withdrawn rather than left answering 401, so the surface does not confirm
that an MCP server is present. The discovery card is withdrawn with the
endpoint on purpose: a card advertising a 404 is worse than no card.

`/mcp/write` is unaffected by this flag — see below.

#### A malformed value is a boot failure, not a default

| `mcp.public.enabled` | Result |
|---|---|
| key absent | enabled (historical default) |
| `true`, `1`, `"1"`, `"true"`, `"on"`, `"yes"` | enabled |
| `false`, `0`, `"0"`, `"false"`, `"off"`, `"no"` | disabled |
| **anything else** — including `null`, `""`, `"flase"`, floats, arrays, objects | **throws `ConfigException` during provider setup** |

Strings are matched case-insensitively and trimmed. Anything outside that set
raises during route registration, so the deployment fails at boot rather than
serving a misconfigured surface.

This is deliberate. There is no safe way to guess at a typo in a control that
governs a public network surface: reading `"flase"` as enabled silently
publishes the endpoint the operator meant to close, and reading it as disabled
silently withdraws a surface someone depends on. **Absent means default;
present means it must parse.**

Note that PHP's own `filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)`
returns `false` for both `null` and `""` — the obvious implementation would
have silently *disabled* the endpoint for a key left blank. The explicit
allowlist exists to avoid exactly that.

`mcp.public` itself must be a map. Writing `mcp.public: false` — a realistic
way to express the intent — also throws rather than being read as "enabled",
with `mcp.public` named in the message.

Configuration routinely holds credentials, so the exception names the **key
and the value's type only** — never the value.

## Why `/mcp/write` is fail-closed by default

The write tier resolves `WriteTierAuthInterface`, and the framework ships
**no usable default credential**: absent an application binding it falls back
to `BearerTokenAuth([])`, an empty token map that matches nothing, so every
request is HTTP 401. Token-to-account mapping is inherently
application-specific — there is no safe value for the framework to guess.

It is not gated by `mcp.public.enabled` because an authenticated write tier
with no anonymous read tier is a supported production shape, and turning off
public reads should not silently disable a surface an operator configured
separately.

> ### Write-tier readiness warning
>
> **Do not use `/mcp/write` for unattended production content editing yet.**
> The transport and authorization plumbing is sound, but the safety milestone
> around it is incomplete. As of this release:
>
> - **No human-approval gate.** A tool declared `destructive: true` executes
>   immediately over MCP. The HITL gate in `AgentExecutor` covers agent
>   *runs*, not MCP calls, so `destructive` is descriptive metadata on this
>   surface.
> - **Bearer tokens have no expiry, revocation, rotation, audience, or
>   scopes.** Rotation means changing configuration and redeploying.
> - **Auditing is best-effort.** The dispatch event fires once per request
>   *before* routing, records `outcome: 'allowed'` unconditionally, and does
>   not record which tool ran. Authentication failures and rate-limit
>   rejections return before it fires and are not recorded at all.
> - **Rate limiting is off by default** (`mcp.rate_limit.max_requests`), and
>   its check-then-increment is not atomic under concurrency.
>
> Until those land, treat the write tier as suitable for supervised or
> trusted-operator use only, behind a curated capability allowlist
> (`mcp.write_tier.capabilities`) — and prefer `waaseyaa/publishing`'s
> `ContentToolSet` over the generic `tool.entity.*` tools, since it is
> bundle-scoped, draft-first, and enforces optimistic locking and
> idempotency.

## Tool error contract

Tool failures come back inside the MCP result envelope with `isError: true`
and a JSON body carrying a machine-readable `code`:

| `code` | Meaning |
|---|---|
| `TOOL_NOT_FOUND` | No tool of that name is visible on this tier. |
| `VALIDATION_FAILED` | Arguments violate the tool's advertised `inputSchema`; `errors` lists `{field, message}`. |
| `INTERNAL_ERROR` | The tool raised an unhandled exception. |
| *(domain codes)* | Authored by the tool — e.g. `REVISION_CONFLICT`, `ASSET_REJECTED`, Content Publishing's field errors. Passed through unchanged. |

`INTERNAL_ERROR` never carries the exception. Its `message` is a fixed literal
and its `meta.correlation_id` is a random 16-hex-character id.

**The log receives safe diagnostic metadata, not exception detail.** Under
`mcp.tool_execution_failed` (or `agent_tool.execution_failed` for a failure a
tool caught itself) the framework logger gets exactly:

| Key | Value |
|---|---|
| `correlation_id` | the same id the caller received — the join between the two sides |
| `tool` | the tool name |
| `exception` | the exception class |
| `file` / `line` | the throw site |
| `code` | only when `getCode()` is an **integer** |

Deliberately **excluded**: the exception message, the stack trace, the bearer
token, the call arguments, and the `Throwable` object itself. A log store is
not a private channel — it is shipped to aggregators, indexed, retained, and
read far more widely than one operator debugging one failure. Copying a DSN or
a password out of the response and into the log relocates the disclosure
rather than fixing it. The trace is excluded for the same reason and more
strongly: it carries argument *values* frame by frame. A non-integer
`getCode()` (PDO's SQLSTATE string, or anything a custom exception
interpolated) is dropped rather than inspected — a "does this look sensitive?"
heuristic is the guesswork this design avoids.

To diagnose a failure, take the correlation id from the caller's response,
find the log line, and reproduce under a debugger — an access decision someone
actually made.

Response sanitization does not depend on a logger being configured. Without
one the metadata is simply discarded; the caller-visible bytes are identical.
A logging gap can cost diagnosability, never open a leak.

## Key classes

- `McpEndpoint` — JSON-RPC dispatcher; constructs the per-request
  bridge with the auth-resolved account.
- `McpServerCard` — server-card route controller (`/.well-known/mcp.json`).
- `McpRouteProvider` — registers the routes through the package's
  `McpServiceProvider`; the public pair is conditional on
  `mcp.public.enabled`.
- `McpServiceProvider` — resolves public-tier auth (application
  `McpAuthInterface` override, else `PublicAnonymousAuth`) and write-tier auth
  (application `WriteTierAuthInterface`, else a fail-closed empty opaque-bearer
  map). Binds neither locally, so neither can shadow an application.
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
