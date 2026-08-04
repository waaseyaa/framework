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

**Capability model.** `bimaaji.read` exposes privileged architectural
introspection and is granted only to authenticated accounts that explicitly
need it; it is not part of the anonymous read tier. `bimaaji.mutate` is opt-in
per role/account. Spec search is disabled unless the application explicitly
configures a non-empty `bimaaji.specs_directory`; results expose logical file
names, never absolute server paths. The integrating application's permission
stack grants both capabilities.

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
Audit that intersection for your install. Bimaaji introspection is excluded
from the anonymous defaults because it describes application architecture.

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

The write tier resolves `WriteTierAuthInterface`. Its production default is
`DurableBearerTokenAuth` over the framework bearer-token store: a fresh
installation has no issued credentials, so every request is HTTP 401 until an
operator issues an audience- and scope-bound token. If durable auth cannot be
wired, the endpoint falls back to `BearerTokenAuth([])`, which also matches
nothing.

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
> Durable outcome-honest auditing, destructive-tool human approval, expiring
> and revocable scoped bearer tokens, atomic default-on rate limiting, bounded
> authenticated introspection, deterministic duplicate-name refusal, and a
> curated write-tool boundary are now framework-owned. The remaining
> enterprise-readiness work is protocol and transport conformance.
>
> Until those remaining items land, treat the write tier as suitable for supervised or
> trusted-operator use only, behind a curated capability allowlist
> (`mcp.write_tier.capabilities`). The canonical editorial surface is
> `waaseyaa/publishing`'s `ContentToolSet`: it is bundle-scoped, draft-first,
> and enforces optimistic locking and idempotency.

The generic `entity.create`, `entity.update`, `entity.delete`,
`entity.rollback`, and `entity.set_current_revision` tools are structurally
absent from `/mcp/write` by default even if their broad `tool.entity.*`
capability is allowlisted. They remain available to the embedded agent runtime.
An application accepting the cross-bundle risk can opt in explicitly:

```php
return [
    'mcp' => [
        'write_tier' => [
            'allow_generic_entity_mutations' => true,
        ],
    ],
];
```

The flag is a strict boolean security control; malformed values fail boot.

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

## Audit trail

Every MCP request emits one record per pipeline **stage**, with the outcome
derived from the stage rather than hardcoded: `authentication_rejected`,
`rate_limited`, `request_accepted`, `tool_lookup_refused`,
`input_validation_refused`, `authorization_refused`, `execution_succeeded`,
`execution_failed`, and — on a gated write tier — `approval_required` /
`approval_refused` (the F1 human-approval gate, below).

Records carry the tool name, the acting principal, the tier, a per-request
correlation id, and the tool's **own redacted arguments** via
`argumentsForAudit()` — never the raw JSON-RPC params. Bearer tokens, secrets,
unredacted content and exception detail are never recorded. An unknown tool
cannot redact its own arguments, so only the requested name and an argument
count are stored.

### Write tier: durable, fail-closed

`/mcp/write` additionally writes a **reserve/finalize** pair to the append-only
`strict_audit_ledger`:

```
reserve(intent)  →  [tool executes]  →  finalize(real outcome)
```

**Guaranteed: no write tool is invoked without a durable record of the
attempt.** If the reservation cannot be persisted the tool is never called and
the caller gets JSON-RPC `-32002` (`Request refused: the audit trail is
unavailable.`) with no exception detail.

**Not guaranteed: atomicity between the mutation and the outcome record.** The
tool owns its own transaction and commits internally, so the two are separate
commits. A crash in between leaves a *dangling reservation* — a `reserved` row
with no `finalized` row. Treat it as "outcome unknown, side effect may have
committed", and **never** retry or roll back on that basis. Find them with:

```sql
SELECT r.receipt_id, r.correlation_id, r.operation, r.created_at
FROM strict_audit_ledger r
LEFT JOIN strict_audit_ledger f
  ON f.receipt_id = r.receipt_id AND f.event_type = 'finalized'
WHERE r.event_type = 'reserved' AND f.id IS NULL;
```

Configure with `mcp.write_tier.durable_audit` (default **true**). It fails
closed: if durable auditing is on and no `StrictAuditLedgerInterface` is bound,
the provider throws at setup rather than silently degrading to no-op auditing.
Set it to `false` to accept best-effort auditing explicitly.

The public `/mcp` tier keeps its documented best-effort auditing — it mutates
nothing, so a durable pre-record buys no safety.

### Write tier: human approval for destructive tools (#2177 F1)

On the write tier, every tool declared `destructive: true` is additionally
gated behind a **durable human approval** (`mcp.write_tier.approval.enabled`,
default **true**). The gate is server-enforced from the tool's declared
metadata — the advisory `annotations.destructiveHint` in `tools/list` is
display metadata and is never consulted by enforcement.

The protocol, from the agent's side:

1. Call the destructive tool normally. The server durably opens an approval
   request bound to *exactly this* principal × surface × tool × arguments and
   answers JSON-RPC error `-32003` with `error.data.approval_request_id`,
   `expires_at`, and `correlation_id`. The tool did not run.
2. A human operator approves or denies the request out-of-band.
3. Retry the *identical* call with
   `params._meta["waaseyaa/approval_request_id"] = "<apr_…>"`. An approved,
   unexpired, unconsumed exact match is **consumed atomically (once ever)** and
   the tool executes; the strict-ledger reservation and finalization carry the
   approval id and deciding operator uid, and the `consumed` approval event
   carries the executing reservation's receipt id.

Everything else — unknown id, denied, expired, already consumed, or *any*
drift in principal/tool/arguments — returns one **identical** `-32004` body
(`Approval refused.` plus a correlation id only), so the response cannot be
used to probe approval state; the refused axis is recorded operator-side in
the durable ledger. A pending id is re-challenged with the same `-32003`.
While a request is pending, identical retries converge on the same approval
request rather than fanning out.

Order of operations is fixed: **reserve → consume → execute → finalize**. A
reservation failure leaves the approval unconsumed; a lost consume race
finalizes the reservation as `approval_refused` and the tool never runs; an
approval-store failure at any point fails closed (`-32002`, correlation id
only, safe log metadata). `tools/list` marks gated tools with
`_meta["ai.waaseyaa.mcp/approval"] = "required"`.

Wiring: `AuditServiceProvider` binds `OperationApprovalStoreInterface` (lazily
creating `mcp_approval_event`; TTL from `mcp.write_tier.approval.ttl_seconds`,
strict positive integer, default 900). The gate fails closed at setup when
enabled but unwireable, and requires `durable_audit` (consume joins the
strict-ledger receipt); disabling durable audit while the gate is on is
refused. The public tier never gets the gate. Non-destructive tools are
untouched.

> **Not yet operationally complete.** The admin decision surface (HTTP routes
> and UI for operators to approve/deny) is **not part of this slice** — a
> decision currently requires calling
> `OperationApprovalStoreInterface::decide()` server-side. Until that surface
> lands, treat the gate as fail-closed protection: destructive calls without a
> standing approval are challenged and will expire unless decided out-of-band.

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
