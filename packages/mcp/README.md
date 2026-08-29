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

- **Endpoint:** `POST /mcp` (JSON-RPC). Modern MCP `2026-07-28` exposes
  `server/discover`, `tools/list`, `tools/call`, and the three resource methods
  with per-request metadata and required HTTP mirrors. Legacy `2025-11-25`,
  `2025-06-18`, and `2025-03-26` retain `initialize`, `ping`, notifications,
  tool methods, and the same opt-in resources; the latest legacy revision is
  preferred.
- **Transport profile:** stateless Streamable HTTP with JSON responses. POST
  requires `Content-Type: application/json` and an `Accept` header listing both
  `application/json` and `text/event-stream`. GET returns 405 because this
  server does not offer SSE; sessions and resumability are not advertised.
- **Browser boundary:** absent Origin is valid for native clients. A present
  Origin must be same-origin or appear in `mcp.transport.allowed_origins`;
  invalid origins return 403 before authentication or dispatch.
- **Resource boundary:** request bodies are capped before authentication and
  JSON decoding (`mcp.transport.max_request_bytes`, 10 MiB by default).
- **Server card:** `GET /.well-known/mcp.json` (MCP discovery).
- **Authentication:** the public read-only `/mcp` surface defaults to
  `PublicAnonymousAuth`. When durable bearer services are available, it also
  accepts audience-bound `mcp:public` tokens while retaining anonymous
  fallback. Applications can override the strategy by binding
  `McpAuthInterface`; the endpoint is disableable via `mcp.public.enabled` — see
  [Controlling the public endpoint](#controlling-the-public-endpoint). The
  `/mcp/write` surface validates `Authorization: Bearer <token>` through a
  fail-closed `BearerTokenAuth(tokens: [])` default that applications replace
  via `WriteTierAuthInterface`.
- **Authorization models:** durable opaque tokens are hashed at rest, revealed
  only at issue/rotation time, audience- and scope-bound, expiring, revocable,
  and resolved to an active real account. Standard OAuth resource-server mode
  adds RFC 9728 discovery, `WWW-Authenticate` scope challenges, and a validator
  port for an OAuth 2.1 authorization server or enterprise IdP.
- **Tool surface:** every class carrying `#[AsAgentTool]` and
  implementing `AgentToolInterface` becomes a callable MCP tool with
  no per-tool MCP code.
- **Tool results:** content tools advertise titles, complete behavior hints,
  and output schemas. Successful results include both backwards-compatible
  JSON text and schema-validated `structuredContent`.
- **Public content search:** installing the optional `waaseyaa/search` package
  makes `content.search` available, but anonymous callers see it only after the
  application explicitly enables `mcp.public.content_search_enabled`. It
  returns only results, counts, and facets visible to the exact request
  principal. `is_complete: false` reports exhaustion of Search's raw bounded
  candidate window; totals/pages/facets are then lower bounds and filters or
  non-relevance sorts cover only that window. The flag may accompany an empty
  visible result when every inspected candidate is denied or filtered.
- **Public content resources:** `resources/list`, `resources/templates/list`,
  and `resources/read` are available only after the strict default-off
  `mcp.public.content_resources_enabled` flag is enabled with an installed
  provider. The server advertises `subscribe: false` and `listChanged: false`.

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
`tool.content.search` is default-off even when Search is installed. Enable it
without widening any other public capability:

```php
return [
    'mcp' => [
        'public' => [
            'content_search_enabled' => true,
        ],
    ],
];
```

The flag uses the same strict boolean parser as `mcp.public.enabled`; a typo
fails boot rather than guessing. Search remains responsible for entity, field,
workflow, tenant, and community visibility.

Search hit text is CMS-authored, untrusted content. MCP clients must not treat
titles, excerpts, URLs, or metadata as instructions.

Enable bounded public resources independently of the search tool:

```php
return [
    'mcp' => [
        'public' => [
            'content_resources_enabled' => true,
        ],
    ],
];
```

The flag grants `resource.content.read` to the fallback anonymous principal and
structurally enables the three resource methods. Missing providers fail closed;
malformed URIs are `-32602`, while denied and nonexistent well-formed resources
share one sanitized response (`-32602` in every protocol era — MCP 2026-07-28
names it as the replacement for the retired `-32002`, see #2561). Modern resource reads require the specification's exact
`Mcp-Name` mirror of `params.uri`; provider parsing occurs only after capability
authorization. Complete modern list/read results are principal-private and
immediately stale (`cacheScope: private`, `ttlMs: 0`, plus HTTP `no-store`).
Listing is one deterministic bounded window
with no `nextCursor`; safe pagination awaits the AEAD cursor primitive in
#2220. CMS resource text is untrusted data, not agent instruction.
The normal per-principal MCP rate limiter runs before all three methods, so
enabling anonymous resources does not bypass request budgeting. Listing is a
discovery window, not an inventory; callers must use the canonical template for
known paths rather than treating omission as nonexistence.

### 2. Authenticated public tier

The framework's durable token path needs no application auth binding. Issue a
token for a real active account with the `mcp:public` audience and only the
required public tool scopes:

```console
vendor/bin/waaseyaa bearer-token:issue 42 \
  --audience=mcp:public --scope=tool.content.search --ttl=3600
```

Token scopes narrow the public registry; they never grant account permissions.
The owner must also hold each called tool capability (for example,
`tool.content.search`) through the application's declarative role model. A
missing, invalid, expired, revoked, or wrong-audience credential remains an
anonymous request and cannot elevate access.

To replace that composed default entirely, bind `McpAuthInterface` in your own
service provider:

```php
final class AppMcpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(
            McpAuthInterface::class,
            fn(): McpAuthInterface => new BearerTokenAuth([
                $_ENV['MCP_READ_TOKEN'] => $this->resolve(AuthorizationPrincipalInterface::class),
            ]),
        );
    }
}
```

Every `McpAuthInterface` implementation returns an immutable
`AuthorizationPrincipalInterface`. Legacy identity providers can migrate with
`DelegatingAuthorizationPrincipal`, supplying their own claims generation and
optional tenant/community claims while permission checks continue to delegate
verbatim. `BearerTokenAuth` fails closed: an absent, unknown, or inactive-account token
yields HTTP 401. To keep anonymous access *and* recognise tokens, wrap it —
`new PublicAnonymousAuth(delegate: $bearerAuth)` tries the token first and
falls back to anonymous. Scoped delegates retain their exact narrower scopes;
legacy unscoped delegates are constrained to the public tier capabilities.

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

> ### Write-tier safety posture
>
> Durable outcome-honest auditing, destructive-tool human approval, expiring
> and revocable scoped bearer tokens, atomic default-on rate limiting, bounded
> authenticated introspection, deterministic duplicate-name refusal, and a
> curated write-tool boundary are framework-owned. The endpoint implements the
> dual-era stateless JSON-response profile of MCP Streamable HTTP: current
> per-request MCP 2026-07-28 plus the legacy initialization lifecycle. It
> validates media negotiation, protocol header/body coherence, and Origin.
> SSE, server-initiated requests, transport sessions, and resumability are
> intentionally absent and advertised as such.
>
> Keep the tier behind a curated capability allowlist
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

## OAuth 2.1 resource-server mode

Opaque operator tokens remain the zero-infrastructure local option. For MCP
clients that perform standard authorization discovery, configure the write
tier as an OAuth protected resource:

```php
return [
    'mcp' => [
        'write_tier' => [
            'oauth_resource' => [
                'enabled' => true,
                'resource' => 'https://cms.example/mcp/write',
                'authorization_servers' => ['https://identity.example'],
                'scopes_supported' => ['tool.entity.read', 'tool.content.search'],
                'resource_documentation' => 'https://cms.example/docs/mcp',
            ],
        ],
    ],
];
```

The framework then serves RFC 9728 metadata at the path-specific well-known
URI and includes that absolute URI plus scope guidance on every 401 challenge.
Plain HTTP is accepted only for loopback development; malformed, duplicate, or
insecure metadata fails application boot.

**`scopes_supported` entries are capability ids, not invented scope names.** A
token is admitted only for the intersection of the write-tier allowlist and the
capabilities its scopes name, so a scope no tool declares silently yields a
caller who authenticates and then sees an empty tool list. Advertise ids that
exist — `tool.entity.read`, `tool.content.search`, `tool.entity.update` — which
you can enumerate from the `#[AsAgentTool]` declarations the deployment
installs. Note that a capability id containing a space is **not** a valid OAuth
scope token and is rejected at boot; the shipped default write-tier capability,
`present guided content`, is one such id. #1640 tracks that reconciliation.

Bind `WriteTierAuthInterface` to `OAuthMcpAuth`, constructed with the same
`OAuthProtectedResourceMetadataConfig` and an application implementation of
`OAuthAccessTokenValidatorInterface`. The validator is the authorization-server
trust boundary: it must validate issuer/integrity or introspection, expiry,
revocation, exact resource audience, active-account mapping, and granted
scopes. The framework never passes the incoming token to another service.

An authorization server is deliberately not embedded in `waaseyaa/mcp`.
Deployments may use Waaseyaa's OIDC issuer, a corporate IdP, or another OAuth
2.1 server without coupling the transport package to one identity product.

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
the caller gets JSON-RPC `-31001` / `McpErrorCode::AUDIT_TRAIL_UNAVAILABLE`
(`Request refused: the audit trail is unavailable.`) with no exception detail.

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
approval-store failure at any point fails closed (`-31002` /
`McpErrorCode::APPROVAL_STORE_UNAVAILABLE`, correlation id only, safe log
metadata — a distinct code from the audit-trail outage above, which shared
`-32002` with it before #2561). `tools/list` marks gated tools with
`_meta["ai.waaseyaa.mcp/approval"] = "required"`.

Wiring: `AuditServiceProvider` binds `OperationApprovalStoreInterface` (lazily
creating `mcp_approval_event`; TTL from `mcp.write_tier.approval.ttl_seconds`,
strict positive integer, default 900). The gate fails closed at setup when
enabled but unwireable, and requires `durable_audit` (consume joins the
strict-ledger receipt); disabling durable audit while the gate is on is
refused. The public tier never gets the gate. Non-destructive tools are
untouched.

The admin application provides the corresponding pending queue and decision
dialog at `/mcp/approvals`. Its API enforces separate decision capabilities,
CSRF/session authentication, separation of duties, expiry, and atomic
single-use consumption; decision events are written to the audit trail.

## Key classes

- `McpEndpoint` — JSON-RPC dispatcher; constructs the per-request
  bridge with the auth-resolved account.
- `McpServerCard` — server-card route controller (`/.well-known/mcp.json`).
- `McpRouteProvider` — registers the routes through the package's
  `McpServiceProvider`; the public pair is conditional on
  `mcp.public.enabled`.
- `McpServiceProvider` — resolves public-tier auth (application
  `McpAuthInterface` override, else scope-aware `PublicAnonymousAuth` composed
  with the durable `mcp:public` bearer path when wireable) and write-tier auth
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
