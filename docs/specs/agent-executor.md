<!-- Spec reviewed 2026-05-20 - M-A audit follow-ups: exception hierarchy, lifecycle events, broadcaster consolidation, --watch SSE -->

# Agent Executor

<!-- Spec reviewed 2026-08-04 - #2191: the external MCP endpoint owner is McpEndpoint through the canonical AI tool registry; the removed McpController is not a production surface. -->

Status: **Design — pending implementation mission.**

This spec defines what "running an agent" means in Waaseyaa. It locks the
consumer surface, the run lifecycle, the tool model, the identity and
authorization story, the human-in-the-loop mechanic, the observability hooks,
and the breaking changes required to deliver an enterprise-grade agent
runtime in v1. The implementation lives across `packages/ai-agent`,
`packages/ai-tools` (new), `packages/api`, `packages/routing`, `packages/cli`,
`packages/scheduler`, `packages/mcp`, `packages/ai-observability`, and
`packages/config`.

## Why this spec exists

`packages/ai-agent/src/` ships a coherent scaffold (`AgentInterface`,
`AgentExecutor`, `AgentContext`, `ToolRegistry`, `Provider/*`) with no
consumer. Specs reference these classes, but no Waaseyaa application can
actually run an agent — there is no CLI command, no HTTP route, no worker,
no run-state entity, no tool catalogue, no auth surface. The companion
orphan `Waaseyaa\AI\Agent\McpServer` was deleted in PR #1508; the
production MCP endpoint is `Waaseyaa\Mcp\McpEndpoint` at `/mcp`, which
handles `tools/list` + `tools/call` for external clients but does not
expose a path for internal agent execution.

This spec closes that gap. The locked answers in this document determine
the WP outline for the follow-up implementation mission.

## Consumer shape — worker-native hybrid

v1 ships three consumer surfaces, all backed by the same `AgentRunService`:

- **CLI** (`bin/waaseyaa ai:run "<prompt>" [--inline] [--agent=<id>]
  [--dry-run] [--watch]`): operator / scripting surface. Default behaviour
  is to enqueue and tail SSE. `--inline` runs synchronously in-process,
  skipping Messenger (development and constrained environments).
- **HTTP** (`POST /api/ai/agent/run`): admin SPA and external automation.
  Always enqueues; never runs inline.
- **Queue** (`messenger:consume`): the only path that actually executes
  `AgentExecutor` in production. CLI and HTTP both dispatch a `RunAgent`
  Messenger message that this worker consumes.

Streaming is mediated by `BroadcastStorage` on channel `agent.run.<id>`.
The existing `/broadcast` SSE endpoint is the streaming surface; no new
SSE transport is introduced.

## Architecture & layer map

| Layer | Package | New / Modified | Responsibility |
|---|---|---|---|
| 0 Foundation | `packages/queue` | unchanged | `symfony/messenger` transport |
| 0 Foundation | `packages/foundation` | unchanged | `BroadcastStorage` (SSE log) |
| 1 Core Data | `packages/config` | +entities | `config.ai.providers`, `config.ai.mcp_servers`, `config.ai.run_retention_days`, `config.ai.hitl_timeout_seconds` |
| 4 API | `packages/routing` | +1 file | `AgentRouteServiceProvider` — registers `/api/ai/agent/run*` routes |
| 4 API | `packages/api` | +controller | `AgentRunController` (POST / GET / DELETE / approve) |
| 5 AI | **`packages/ai-tools` (NEW)** | +package | `AgentTool` VO, `#[AsAgentTool]` attribute, framework-shipped tool classes; consumed by both `packages/mcp` and `packages/ai-agent` |
| 5 AI | `packages/ai-agent` | major edit | `AgentDefinition` VO + registry, `AgentRunService`, `RunAgent` message + `RunAgentHandler`, `AgentRun` + `AgentAuditLog` entities + repositories, modified `AgentExecutor`, `McpClientToolSource`, retained `AgentInterface` (procedural escape hatch), retained `Provider/*` |
| 5 AI | `packages/ai-observability` | +listeners | Subscribes to `AgentRun` lifecycle events; captures token/cost/tool-count/latency |
| 6 Interfaces | `packages/cli` | +commands | `AiRunCommand`, `AiPurgeRunsCommand`, `AiReapStalledRunsCommand` |
| 6 Interfaces | `packages/mcp` | breaking edit | `packages/mcp/src/Tools/*` deleted; `McpController` consumes the `packages/ai-tools` registry |
| 6 Interfaces | `packages/scheduler` | +entries | Daily `ai:purge-runs`; every-5-minute `ai:reap-stalled-runs` |

Layer rules:

- `packages/ai-tools` (Layer 5) depends on `packages/entity`, `packages/access`,
  `packages/ai-schema` (Layers 1/5). All downward.
- `packages/mcp` (Layer 6) depends on `packages/ai-tools` (Layer 5).
  Downward. Same direction as its existing `ai-schema` dependency.
- `packages/ai-agent` (Layer 5) depends on `packages/ai-tools` (Layer 5).
  Same-layer. Allowed.

## Component diagram

```
                        ┌─────────────────────┐
   CLI ─────────────────│  AgentRunService    │──── dispatches RunAgent ───┐
   HTTP POST ───────────│  (packages/ai-agent)│                            │
                        └──────────┬──────────┘                            ▼
                                   │ persists AgentRun                     ┌─────────────────┐
                                   ▼                                       │ Symfony         │
                        ┌─────────────────────┐                            │ Messenger       │
                        │ AgentRunRepository  │                            │ (waaseyaa/queue)│
                        └─────────────────────┘                            └────────┬────────┘
                                   ▲                                                │
                                   │ status poll                                    ▼
                                   │ + writes                              ┌─────────────────┐
                                   │                                       │ RunAgentHandler │
                                   │                                       │ (worker)        │
                                   │                                       └────────┬────────┘
                                   │                                                │
                                   │                                resolves AgentDefinition
                                   │                                builds  AgentContext
                                   │                                assembles ToolRegistry
                                   │                                  (local + remote MCP)
                                   │                                                │
                                   │                                                ▼
                                   │                                       ┌─────────────────┐
                                   │                                       │ AgentExecutor   │
                                   │                                       │ .executeWith    │
                                   │                                       │  Provider()     │
                                   │                                       └────────┬────────┘
                                   │                                                │ per iteration
                                   │                                                ▼
                                   │                                       ┌─────────────────┐
                                   │ ◄──── cancel poll                     │ check cancel    │
                                   │       + audit writes                  │ enforce HITL    │
                                   │                                       │ call provider   │
                                   │                                       │ execute tool    │
                                   │                                       │ push SSE        │
                                   │                                       └────────┬────────┘
                                   ▼                                                │
                              [SQL tables]                                          ▼
                                                                          BroadcastStorage
                                                                       channel: agent.run.<id>
                                                                                    │
                                                                                    ▼
                                                                          GET /broadcast
                                                                          (existing endpoint)
```

## Run lifecycle — async (default)

1. Client `POST /api/ai/agent/run` with body:
   ```json
   { "agent_id": "authoring_assist", "params": {...}, "destructive_approval": "interactive" }
   ```
   OR an ad-hoc bundle:
   ```json
   { "bundle": { "prompt": "...", "tools": [...], "model": "anthropic:claude-sonnet-4-6",
                 "system": "...", "max_iterations": 10 },
     "destructive_approval": "none" }
   ```
2. `AuthorizationMiddleware` enforces the `agent.run` route capability.
3. `AgentRunController::create()` delegates to `AgentRunService::enqueue($request, $account)`:
   - Resolves the `AgentDefinition` (named lookup OR ad-hoc bundle validation).
   - Verifies each tool in the resolved bundle is in the `AgentTool` registry
     and the initiator's account holds the per-tool capability
     (`tool.<name>`).
   - Persists an `AgentRun` row (`status='queued'`, `account_id=$account->id`).
   - Dispatches `RunAgent { run_id }` onto the Messenger transport.
   - Returns HTTP 202 with `{ run_id, stream_url, status_url, approve_url }`.
4. A worker process consumes the message via `RunAgentHandler`:
   - Loads `AgentRun` + resolves `AgentDefinition`.
   - Builds an `AgentContext` carrying the initiator's `AccountInterface`.
   - Assembles the `ToolRegistry` from local `#[AsAgentTool]` classes plus
     remote tools surfaced by `McpClientToolSource`. Filtered to the
     bundle's `tools[]` whitelist.
   - Flips status to `running`, emits SSE `run_started`.
   - Invokes `AgentExecutor::executeWithProvider(...)`. Per iteration:
     - Re-poll `AgentRun.status` — if `cancelling`, break, flip to
       `cancelled`, emit `run_cancelled`, return.
     - Call the provider; on `RateLimitException` honour `retryAfterSeconds`
       and retry up to 3× with exponential backoff (cap 30 s).
     - For each tool call:
       - Write `AgentAuditLog` row (`event_type='tool_call'`).
       - If the tool is `destructive`, apply the HITL gate (see below).
       - Execute the tool callable, wrapped in `try`/`catch`. Exceptions
         become `ToolResultBlock(isError: true)` returned to the LLM.
       - Emit SSE `tool_call_completed`.
     - Push SSE `iteration` with running token totals.
   - On success: persist `response`, set `status='completed'`, emit
     `run_completed`.
   - On `Throwable`: catch, set `status='failed'` with `error_code`, emit
     `run_failed`.

All `BroadcastStorage::push` calls in the worker are wrapped in `try`/`catch`
and logged via `LoggerInterface` — SSE delivery failures never crash a run.

## Run lifecycle — inline (CLI)

`bin/waaseyaa ai:run "<prompt>" --inline` skips Messenger entirely:

- `AgentRunService::runInline()` creates the `AgentRun` row, instantiates
  `RunAgentHandler` in-process, and drives the same loop synchronously.
- `StreamingProviderInterface` chunks are forwarded directly to stdout
  (rather than only to SSE).
- SSE events still fire (observers in other processes can subscribe).
- `--inline` rejects `destructive_approval=interactive` — no human can
  respond mid-script. CLI surface returns an error before starting the run.

## Agents: the bundle paradigm

An **agent** in Waaseyaa is a **bundle** of:

```
AgentDefinition (final readonly)
  id                  string         e.g. "authoring_assist"
  label               string
  description         string
  prompt              string         user-facing template
  system              string         system message
  tools               string[]       tool name whitelist
  model               string         e.g. "anthropic:claude-sonnet-4-6"
  max_iterations      int            default 10
  destructive_default ?HitlMode      override default approval mode per agent
  requires_capability string         capability gate beyond agent.run
```

Two paths bring a bundle into play:

1. **Registered definition** — class carrying
   `#[AsAgentDefinition(id: 'authoring_assist', ...)]` is discovered by
   `PackageManifestCompiler` and registered with `AgentDefinitionRegistry`.
   Clients reference by `agent_id`. Extension packages may register their
   own (e.g. `packages/bimaaji` may register `bimaaji.respond`).
2. **Ad-hoc inline bundle** — caller passes the full bundle inline. Same
   `AgentExecutor` loop, same audit and tool semantics; no registry hit.

`AgentInterface` is **deleted** in v1. The bundle paradigm replaces it
end-to-end. Apps needing PHP logic outside the LLM loop compose at the
service-provider layer — wrap `AgentRunService::runInline()` from a
domain service — rather than implement a separate agent interface. This
is a breaking change against the existing scaffold; there are no
consumers.

## Tool model

A tool is a class implementing `Waaseyaa\AI\Tools\AgentToolInterface` with:

```php
#[AsAgentTool(
    name: 'entity.read',
    capability: 'tool.entity.read',
    destructive: false,
    dry_run_supported: true,
    category: 'entity',
)]
final class EntityReadTool implements AgentToolInterface
{
    public function inputSchema(): array { ... }
    public function execute(array $arguments, AgentContext $context): AgentToolResult { ... }
    public function dryRun(array $arguments, AgentContext $context): AgentToolResult { ... }
}
```

The `AgentTool` value object is the runtime shape:

```php
final readonly class AgentTool
{
    public string $name;
    public string $capability;        // required capability on initiator
    public bool   $destructive;       // triggers HITL gate
    public bool   $dryRunSupported;
    public string $category;
    public array  $inputSchema;       // JSON Schema draft 2020-12
    public AgentToolInterface $impl;  // injected
}
```

Discovery is via `PackageManifestCompiler`'s attribute scan (same mechanism
as `#[PolicyAttribute]`, `#[AsMiddleware]`). The compiler instantiates the
class via DI and registers the `AgentTool` in `ToolRegistry`.

### Stock tools (framework-shipped in `packages/ai-tools`)

- `EntityReadTool` (`entity.read`)
- `EntityListTool` (`entity.list`)
- `EntityCreateTool` (`entity.create`) — **destructive**
- `EntityUpdateTool` (`entity.update`) — **destructive**
- `EntityDeleteTool` (`entity.delete`) — **destructive**
- `EntitySearchTool` (`entity.search`) — full-text
- `RelationshipTraverseTool` (`relationship.traverse`)
- `VectorSearchTool` (`vector.search`) — semantic

`packages/mcp`'s `McpController` is rewired to consume this same registry,
removing the duplicated tool implementations currently in
`packages/mcp/src/Tools/`.

### Remote MCP servers as a tool source

`packages/ai-agent/src/McpClientToolSource.php` implements
`ToolSourceInterface` (a new interface returning `AgentTool[]`). At boot,
it reads `config.ai.mcp_servers`, calls `tools/list` against each enabled
remote, and surfaces every remote tool prefixed by the server's alias
(e.g. `github.create_issue`, `slack.post_message`). `tools/call` is
proxied to the remote at execution time.

- Capability check: tools surfaced through this source receive capability
  `tool.mcp.<server_alias>.<tool_name>`.
- Authentication: every server declares `auth_mode` as either `none` or
  `secret-reference`. A referenced credential is resolved through the frozen
  kernel `SecretResolverRegistry`, pinned for one startup or call boundary,
  and exposed only inside the registered MCP credential consumer that creates
  the outbound authorization header. Missing credentials never downgrade to
  unauthenticated requests.
- Remote unavailability: every server declares `availability` as `required` or
  `optional`. An optional startup failure contributes no tools and records a
  degraded health state; a required startup failure blocks readiness. Both
  paths send no request when credential resolution fails. Per-call network or
  credential failures yield `ToolResultBlock(isError: true)` without retaining
  or logging secret bytes.
- Streamable HTTP transport only; stdio MCP servers are out of scope for v1.

## Identity & permissions

Agents run as the **initiator's account**. `AgentContext::account` is the
user who triggered the run.

Authorization happens at five gates:

1. **Route capability** — `_permission: 'agent.run'` on every
   `/api/ai/agent/run*` route, evaluated by `AccessChecker`.
2. **Per-agent-definition capability** — `AgentDefinition.requires_capability`
   (when non-null) is enforced in `RunAgentHandler::__invoke()`, after the
   bundle and initiator account are resolved and before
   `AgentExecutor::executeRun()` is called. Both the CLI (`ai:run`) and the
   API (`POST /api/ai/agent/run`) dispatch through this same handler, so the
   check cannot be bypassed by either entry point. Fail-closed: a missing or
   permission-less initiator (including an anonymous account) is refused, not
   silently skipped, because `AccountInterface::hasPermission()` returns
   `false` in both cases. A refused run never reaches the executor — it is
   marked terminal `failed` with `error_code='missing_capability'` and no
   `AgentAuditLog` rows are written (audit A7 F2 / R10 WP2 — this gate was
   plumbed attribute → manifest → registry → definition but left unenforced
   until this fix).
3. **Per-agent tool allowlist** — `RunAgentHandler` advertises only the tool
   names in `AgentDefinition.tools`, and `AgentExecutor` independently rejects
   any model-emitted or direct invocation whose name is absent from that list
   before consulting the global registry. An empty list is fail-closed.
4. **Per-tool capability** — every tool the agent invokes requires the
   initiator to hold `tool.<name>` (or `tool.mcp.<server>.<name>`). Enforced
   at request-validation time AND defensively at tool-execution time.
5. **Entity-level access** — tools that touch entities go through
   `EntityAccessHandler` against the initiator's account. The previous
   `accessCheck(false)` bypass in `McpToolExecutor` is removed.

Audit attribution: `AgentAuditLog.account_id` records the initiator. The
`AgentDefinition.id` (when present) provides the "what" of the run.

## Human-in-the-loop (HITL)

The `destructive` flag on `AgentTool` triggers the HITL gate. The
per-run `destructive_approval` mode determines what happens:

| Mode | Behaviour |
|---|---|
| `none` (default) | First destructive tool call fails the run with `error_code='destructive_denied'`. |
| `all` | Blanket approval. Audit row written (`event_type='approval_granted'`, `tool_name=...`, source=`blanket`). |
| `interactive` | Worker pauses: sets `status='awaiting_approval'`, `pending_approval_call_id=<id>`, emits SSE `approval_required { call_id, tool_name, arguments, expires_at }`. Polls `AgentRun.pending_approval_call_id` every 1 s. Resumes when cleared (granted) or fails with `error_code='approval_denied'` (denied / timeout). Default timeout `config.ai.hitl_timeout_seconds` (300 s). |

`AgentDefinition.destructive_default` may override per agent; the request's
`destructive_approval` overrides per run.

`--inline` rejects `interactive` mode at parse time.

## SSE event vocabulary

Channel: `agent.run.<id>`. Each event payload includes `run_id`.

| Event | When | Payload |
|---|---|---|
| `run_started` | After `status` flips to `running` | `{ run_id, agent_id?, started_at }` |
| `iteration` | Per loop iteration | `{ iteration, tokens_used_so_far }` |
| `tool_call_started` | Before tool callable runs | `{ call_id, tool_name, arguments_redacted }` |
| `tool_call_completed` | After tool callable returns | `{ call_id, success, duration_ms }` |
| `approval_required` | Interactive HITL gate hit | `{ call_id, tool_name, arguments, expires_at }` |
| `approval_resolved` | After approve endpoint POST | `{ call_id, decision: 'approve' \| 'deny' }` |
| `provider_chunk` | When streaming provider yields a `StreamChunk` | `{ text }` (or other chunk shape) |
| `run_completed` | Run succeeds | `{ response, token_usage, cost_cents, summary }` |
| `run_failed` | Run errors | `{ error_code, error_message }` |
| `run_cancelled` | Run cancelled | `{ cancelled_at }` |

Delivery is at-least-once with `BroadcastStorage` sequence ids. Clients
dedupe by event id and may use `Last-Event-ID` to resume.

## Status state machine

```
queued ──► running ──► completed
   │          │
   │          ├─► failed
   │          ├─► awaiting_approval ──► running  (approved)
   │          │                    ╰─► failed   (denied/timeout)
   │          ╰─► cancelling ──► cancelled
   ╰─► cancelled  (cancel before worker pickup)
```

The reaper terminalizes abandoned work without regressing concurrent state
changes: `running` rows age from `started_at`, `queued` rows from `queued_at`,
`cancelling` rows from `started_at`, and `awaiting_approval` rows from their
persisted `approval_expires_at` deadline. Each terminal update compares the
exact source status and lifecycle fields captured by candidate selection.
Queued/running/cancelling candidates retain their selected timestamps;
approval candidates additionally retain the call id and deadline, so a worker
claim or renewed approval cycle that wins the race is preserved. Successful
terminalization clears pending approval metadata. Upgrade-era approval rows
with no persisted deadline retain the former `started_at` age fallback.

## Entities

### `AgentRun`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | run identifier |
| `account_id` | bigint | initiator |
| `agent_definition_id` | text NULL | named agent OR null for ad-hoc |
| `bundle_json` | text | frozen snapshot of resolved bundle |
| `status` | enum | see state machine above |
| `destructive_approval` | enum | `none` / `all` / `interactive`, default `none` |
| `pending_approval_call_id` | text NULL | set when `status='awaiting_approval'` |
| `approval_expires_at` | datetime NULL | persisted HITL deadline; set when entering `awaiting_approval` |
| `prompt` | text | resolved user prompt |
| `response` | text NULL | final LLM response |
| `transcript_json` | text | full conversation snapshot, truncated at `config.ai.transcript_max_bytes` (default 256 KB); overflow recorded as a single `[truncated]` marker. Full message history remains reconstructable from `AgentAuditLog` rows. |
| `token_usage_in` | int | sum across provider calls |
| `token_usage_out` | int | sum across provider calls |
| `cost_cents` | int NULL | derived from provider+model price table |
| `tool_call_count` | int | total tool invocations |
| `queued_at` | datetime | persisted at enqueue |
| `started_at` | datetime NULL | when worker picked up |
| `finished_at` | datetime NULL | terminal-status timestamp |
| `error_code` | text NULL | see error taxonomy |
| `error_message` | text NULL | human-readable detail |

Indexes: `(status, queued_at)`, `(account_id, queued_at DESC)`.

### Field-read boundary

`AgentRun.account_id` is a Protected authorization input. The frozen bundle is
Internal; prompt, response, transcript, accounting, approval, lifecycle, and
error fields are Protected; structural identifiers and state-machine selectors
are Public. Account-facing HTTP reads use one fixed projection inside an
explicit immutable principal scope, while queue workers and the sessionless
`ai:run` CLI use a fixed-shape system-job capability whose exact field set is
durably reserved before any value is obtained. Neither boundary accepts caller-
selected field names, and an ordinary protected read with no account context
fails closed.

`AgentAuditLog.run_id` is the exact Protected parent authorization input; event
payload fields are Internal and remain available only to closed audited readers.
Owner decisions for both entities consume compiled immutable subject views, so
authorization never performs an ordinary protected field read.

### `AgentAuditLog`

Replaces the in-memory list inside the current `AgentExecutor`.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | event id |
| `run_id` | uuid FK | → `agent_run.id` |
| `iteration` | int | loop iteration number |
| `event_type` | enum | see invariants |
| `tool_name` | text NULL | for tool events |
| `tool_arguments_json` | text NULL | result of `AgentToolInterface::argumentsForAudit(array $arguments): array`. Default implementation in `AbstractAgentTool` passes arguments through unchanged. Tools that carry secrets (e.g. inline API keys) override to redact. |
| `tool_result_summary` | text NULL | short summary (full result in transcript) |
| `success` | bool | event outcome |
| `duration_ms` | int NULL | for timed events |
| `occurred_at` | datetime | event time |

Index: `(run_id, occurred_at)`. Append-only outside the purge job.

`event_type` values: `iteration_start`, `tool_call`, `tool_result`,
`provider_call`, `approval_required`, `approval_granted`, `approval_denied`,
`error`.

### Audit invariants

1. Every provider call yields exactly one `provider_call` row.
2. Every tool call yields exactly one `tool_call` row **and** one
   `tool_result` row (or `error`).
3. Every interactive approval yields exactly one `approval_required` and
   exactly one of `approval_granted` / `approval_denied`.
4. `AgentAuditLog` is append-only outside the purge job.
5. `AgentRun.transcript_json` is a complete conversation snapshot;
   `AgentAuditLog` is the structured event log.
6. Reaper cannot regress a terminal status.

## Config entities

| Key | Shape | Default |
|---|---|---|
| `config.ai.providers` | `[{ id, type, model_default, timeout_ms, rate_limit_per_min, credential_reference }, …]` | empty — at least one required for non-Null runs |
| `config.ai.mcp_servers` | `[{ alias, url, auth_mode, availability, credential_reference, enabled, capability_prefix }, …]` | empty |
| `config.ai.run_retention_days` | int | 30 |
| `config.ai.hitl_timeout_seconds` | int | 300 |
| `config.ai.max_runtime_seconds` | int | 600 |
| `config.ai.transcript_max_bytes` | int | 262144 (256 KB) |
| `config.ai.hitl_poll_interval_ms` | int | 1000 |

Credential configuration contains typed `SecretReference` metadata, never
secret bytes. Legacy v1 environment-variable-name fields migrate
deterministically to references for the central environment provider; they are
not read by the schema and unresolved references fail closed. Provider and MCP
adapters retain only guarded `SecretHandle` values and resolve a fresh version
for each outbound request. Hot-reload follows the existing `config:*` CLI / CMI
sync model.

## Error taxonomy

`AgentRun.error_code` values:

| Code | When |
|---|---|
| `destructive_denied` | Destructive tool reached under `destructive_approval='none'`. |
| `approval_denied` | Interactive HITL: user denied. |
| `approval_timeout` | Interactive HITL: no decision within `hitl_timeout_seconds`. |
| `cancelled_by_user` | DELETE during run. |
| `max_iterations_exceeded` | `MaxIterationsException` from `AgentExecutor`. |
| `provider_rate_limited` | 429 after retry-with-backoff exhausted (3 retries, 30 s cap). |
| `provider_unavailable` | Network / 5xx after 3 retries. |
| `provider_invalid_response` | Two consecutive malformed provider responses. |
| `tool_unauthorized` | Defence-in-depth: capability check failed mid-run. |
| `tool_not_found` | Agent referenced an undeclared tool. |
| `worker_crashed` | Reaper transitioned stuck `running` row. |
| `internal_error` | Last-resort catch in `RunAgentHandler`. |

## Provider Exception Hierarchy

All AI provider exceptions extend `Waaseyaa\AI\Agent\Provider\ProviderException`
(abstract, extends `\RuntimeException`):

| Class | HTTP trigger | Retry behaviour |
|---|---|---|
| `RateLimitException` | 429 | Retried with backoff per FR-025 budget (honour `retryAfterSeconds`, up to 3×, cap 30 s). Exhausted → `provider_rate_limited`. |
| `TransportException` | 5xx, network errors | Retried per FR-025 budget (up to 3×, exponential backoff). Exhausted → `provider_unavailable`. |
| `ClientErrorException` | 4xx non-429 | Re-thrown immediately, no retry — these errors indicate a malformed request or auth failure, retrying will not help. |

`AnthropicProvider` and `OpenAiCompatibleProvider` throw these typed exceptions
for all HTTP outcomes. Bare `\RuntimeException` is not used for HTTP status codes.
`RateLimitException` extends `ProviderException` directly; `TransportException`
and `ClientErrorException` are siblings. Callers may catch any of the three typed
subclasses or the abstract base `ProviderException` to handle all provider errors.

See also: `packages/api/openapi.yaml` — the `pending_approval` schema shape is
documented there and aligns with what `AgentRunBroadcaster` emits on the SSE channel.

## Lifecycle Event Dispatch

`AgentExecutor` dispatches these events via `EventDispatcherInterface` (L0):

| Event | Dispatch point | Owner |
|---|---|---|
| `AgentRunStarted` | Entry of run loop | `AgentExecutor` |
| `AgentRunIterationCompleted` | End of each iteration | `AgentExecutor` |
| `AgentRunProviderCallCompleted` | After provider call returns | `AgentExecutor` |
| `AgentRunToolCallObserved` | Per tool call in the loop | `AgentExecutor` |
| `AgentRunTerminated` (normal) | Normal run completion | `AgentExecutor` |
| `AgentRunTerminated` (abnormal) | Supervisor kill / pre-executor cancel | `RunAgentHandler` |

Dispatch is best-effort: listener exceptions are logged via `LoggerInterface` and
do not abort the run. Exactly one `AgentRunTerminated` fires per run — either from
`AgentExecutor` (normal exit) or from `RunAgentHandler` (pre-executor abort path).

`AgentRunTelemetryListener` in `packages/ai-observability` subscribes to all five
events and captures token/cost/tool-count/latency per run.

## Broadcaster

`AgentRunBroadcaster` (in `packages/ai-agent`) is the sole implementation of
`AgentRunBroadcasterInterface`. `BroadcastStorageAdapter` was removed in mission
`agent-executor-v1-1-audit-followups` (PR #1511). The canonical service binding
is `AgentRunBroadcasterServiceProvider` (registered in `extra.waaseyaa.providers`
of `packages/ai-agent/composer.json`).

`AgentRunBroadcaster` writes directly to `BroadcastStorage` and is responsible
for all SSE event emission on channel `agent.run.<id>` (see SSE event vocabulary
section above). The `pending_approval` event shape is documented in
`packages/api/openapi.yaml`.

## CLI --watch

`bin/waaseyaa ai:run "<prompt>" --watch` attaches an SSE consumer to
`/broadcast?channels=agent.run.<id>` via `StreamHttpClient`. Events are printed
to stdout as they arrive. The command exits cleanly on `terminated` event.
SIGINT (Ctrl-C) closes the stream; the server-side run continues.

`--watch` is incompatible with `--inline`. When `--inline` is used, output is
written directly to stdout as the run proceeds and no SSE consumer is spawned.

## OpenAPI document

`packages/api/openapi.yaml` is a hand-maintained OpenAPI 3.1.0 **sub-spec** scoped to
the agent-run SSE surface: the `/api/agent-run/{id}/{events,approve,deny}` endpoints and
the `pending_approval` payload shape. It was bootstrapped in mission
`agent-executor-v1-1-audit-followups` (WP02/T018). It is **not** a full description of the
Waaseyaa Framework API — the framework's broader JSON:API entity surface is modelled at
runtime by `Waaseyaa\Api\OpenApi\OpenApiGenerator` (see `docs/specs/api-layer.md`
§"OpenAPI Generation"), which is a separate artifact not covered by this file.

`bin/check-openapi` runs Spectral lint against this one document and is included in
`composer verify`. The gate validates YAML/OpenAPI structural validity (syntax + shape)
of the hand-maintained sub-spec only; it does **not** introspect the route table, diff
against live routes, or enforce route/schema parity. Treat the agent-run entries here as
documentation that must be updated by hand when the agent-run SSE contract changes — the
gate will not catch drift between this file and the actual routes.

## Provider error handling

- **429 / `RateLimitException`**: honour `retryAfterSeconds`, retry up to 3
  with exponential backoff (capped at 30 s). Exhausted → `provider_rate_limited`.
- **5xx / transport failures**: retry up to 3 with backoff. Exhausted →
  `provider_unavailable`.
- **Malformed tool-use JSON**: write a `provider_call` audit row with
  `success=false`, feed the parse error back to the LLM as a tool result
  with `isError=true`. After two consecutive parse errors →
  `provider_invalid_response`.
- **Provider timeout**: per-provider `timeout_ms` from `config.ai.providers`.
  Treated as transient → retry.

## Worker retry semantics

`RunAgentHandler` is **not** auto-retryable by Messenger. Once a run flips
to `running`, side effects (entity writes, remote MCP calls) may have
occurred — re-execution would duplicate them.

- Pre-pickup runs (`status='queued'`) follow Messenger's normal retry
  semantics for transport failures — safe because no side effects yet.
- Worker crashes mid-run leave the row at `status='running'`. The reaper
  command (`ai:reap-stalled-runs`, every 5 minutes via scheduler) flips
  any run with `status='running' AND NOW() - started_at > max_runtime_seconds`
  to `status='failed'` with `error_code='worker_crashed'`.
- Interactive approval persists its own deadline when the worker enters
  `awaiting_approval`; time spent running before that transition does not
  consume the approval window.
- Operators re-issue manually; the framework will not re-run automatically.

## Cancellation

`DELETE /api/ai/agent/run/{id}` writes `status='cancelling'` on the
`AgentRun` row (no Messenger interaction). The worker polls
`AgentRun.status` between every iteration and between every tool call;
when it sees `cancelling`, it breaks the loop, sets `status='cancelled'`,
emits SSE `run_cancelled`, and returns.

Cancellation latency upper bound: one iteration boundary plus one tool-call
boundary (so at most the duration of one in-flight tool call).

## Retention

`config.ai.run_retention_days` (default 30) controls how long `AgentRun`
and `AgentAuditLog` rows live. The `ai:purge-runs` command (cron entry:
daily at 03:00) deletes rows where
`finished_at IS NOT NULL AND finished_at < NOW() - retention_days`.

Apps responsible for prompt-layer redaction (PII, secrets). Encrypted-
at-rest transcript storage is **out of scope** for v1.

## Observability

`packages/ai-observability` subscribes to `AgentRun` lifecycle domain
events and captures:

- Input / output tokens per provider call.
- Cost in cents, computed from a static per-model price table.
- Per-tool invocation count.
- Wall-clock and per-iteration latency.

Persisted via `packages/telescope`. Dashboards and Prometheus export are
deferred to a follow-up mission.

Drift detection, prompt-replay logging, and continuous-eval harnesses are
**out of scope** for v1.

## Routes

| Method | Path | Capability | Body | Returns |
|---|---|---|---|---|
| POST | `/api/ai/agent/run` | `agent.run` | `{ agent_id?, bundle?, params?, destructive_approval?, dry_run? }` | 202 `{ run_id, stream_url, status_url, approve_url }` |
| GET | `/api/ai/agent/run/{id}` | `agent.run` (same account) | — | `{ status, transcript, token_usage, cost_cents, error_code? }` |
| DELETE | `/api/ai/agent/run/{id}` | `agent.run` (same account) | — | 204 |
| POST | `/api/ai/agent/run/{id}/approve` | `agent.run.approve` (default = `agent.run`) | `{ call_id, decision: 'approve' \| 'deny' }` | 204 |

`agent.run` check additionally requires that the requesting account own the
run (initiator match) unless the account holds the bypass capability
(`agent.run.bypass_ownership`, admin-only).

## CLI commands

| Command | Flags | Behaviour |
|---|---|---|
| `ai:run "<prompt>"` | `--inline`, `--agent=<id>`, `--dry-run`, `--watch`, `--destructive-approval=<mode>` | Enqueue (default) or run inline. `--watch` tails the SSE channel. |
| `ai:purge-runs` | `--dry-run`, `--retention-days=<int>` (override config) | Delete `AgentRun` + `AgentAuditLog` rows past TTL. |
| `ai:reap-stalled-runs` | `--max-runtime-seconds=<int>` (override config) | Terminalize abandoned `queued`, `running`, `awaiting_approval`, and `cancelling` rows. Queued age uses `queued_at`, running/cancelling age uses `started_at`, and approval expiry uses its persisted HITL deadline. |

## Scheduler entries

- `ai:purge-runs` — daily at 03:00 UTC.
- `ai:reap-stalled-runs` — every 5 minutes.

Retention never deletes audit rows belonging to a non-terminal run. The reaper
must first classify an abandoned run as terminal; a later retention pass may
then remove the run and its audit trail together.

## Capabilities (seed)

| Name | Holder default | Notes |
|---|---|---|
| `agent.run` | authenticated users (configurable) | Required to use any `/api/ai/agent/run*` endpoint. |
| `agent.run.approve` | == `agent.run` | Required to POST `/approve`. |
| `agent.run.bypass_ownership` | admins | Required to view / cancel another user's run. |
| `tool.entity.read` | per-app | Per-tool fine grain. |
| `tool.entity.list` | per-app | |
| `tool.entity.create` | restricted | Destructive tool. |
| `tool.entity.update` | restricted | Destructive tool. |
| `tool.entity.delete` | restricted | Destructive tool. |
| `tool.entity.search` | per-app | |
| `tool.relationship.traverse` | per-app | |
| `tool.vector.search` | per-app | |
| `tool.mcp.<server>.<name>` | per-app | Per-remote-tool capability. |

## Breaking changes (internal, no external consumers exist)

| Change | Reason safe |
|---|---|
| `packages/mcp/src/Tools/{Entity,Discovery,Traversal,Editorial}Tools.php` deleted | Replaced by `packages/ai-tools/src/*Tool.php`; `McpController` rewired in the same PR. |
| `Waaseyaa\AI\Schema\Mcp\McpToolDefinition` deleted | Superseded by `Waaseyaa\AI\Tools\AgentTool` VO with metadata. `ai-schema/Mcp/*ToolGenerator` updated in lockstep. |
| `Waaseyaa\AI\Agent\ToolRegistry::register()` signature changes from `(McpToolDefinition, callable)` to `(AgentTool)` | Zero consumers outside `packages/ai-agent/tests/`. |
| `McpToolExecutor::accessCheck(false)` removed | The bypass was for the deleted orphan `McpServer`; tools now enforce entity-level access. |
| `AgentAuditLog` becomes a persisted entity (not in-memory VO) | The in-memory list inside `AgentExecutor` is removed; replaced by `AgentAuditLogRepository::record()`. |
| `Waaseyaa\AI\Agent\AgentInterface` deleted | Superseded by `AgentDefinition` + `AgentExecutor`. The escape hatch for procedural composition is `AgentRunService::runInline()` from a wrapping service. Zero external consumers. |

All five changes ship in a single coordinated mission. The `@api` surface
gets a documented break in the release notes for the alpha bump.

## Out of scope for v1

- Separate Planner abstraction (the LLM is the planner).
- Semantic prompt cache.
- Continuous-evaluation harness; drift detection.
- Prompt-injection guards.
- Encrypted-at-rest transcripts.
- stdio MCP transport.
- Pause/resume of failed runs (no auto-retry of partially-executed runs).
- Multi-agent orchestration (one agent per run in v1).
- Per-tenant cost caps (token totals are observed, not enforced).
- Drift-detector integration for prompt templates.

## Acceptance criteria

1. `bin/waaseyaa ai:run "list my nodes" --inline` returns a structured
   `AgentResult` within 10 s using `NullLlmProvider`.
2. `POST /api/ai/agent/run` with an inline bundle, then tailing
   `/broadcast?channels=agent.run.<id>`, yields a complete SSE stream
   ending in `run_completed`.
3. `DELETE /api/ai/agent/run/{id}` cancels within 3 iteration boundaries;
   the run terminates at `status='cancelled'`.
4. A destructive tool under `destructive_approval='interactive'` pauses,
   emits `approval_required`, resumes on `POST /approve`, and fails with
   `approval_timeout` after `hitl_timeout_seconds`.
5. A tool surfaced via `McpClientToolSource` from a configured remote
   MCP server is invoked end-to-end and returns its result through the
   agent loop.
6. `AgentRun` + `AgentAuditLog` rows persist; `ai:purge-runs --dry-run`
   reports the correct candidates and a real run removes them.
7. `ai-observability` captures token / cost / tool-count / latency per
   run with one row per metric per `AgentRun`.
8. `bin/check-package-layers` passes (no upward `waaseyaa/*` edges).
9. `bin/check-dead-code` passes (no new findings).
10. `composer phpstan` + `composer cs-check` + `composer test` all pass.

## WP outline (for `spec-kitty.tasks-outline`)

| WP | Title | Dependencies |
|---|---|---|
| WP-01 | `packages/ai-tools` package + tool migration | — |
| WP-02 | `AgentRun` + `AgentAuditLog` entities | WP-01 |
| WP-03 | `AgentDefinition` registry + `AgentExecutor` rewire | WP-02 |
| WP-04 | `RunAgent` message + worker + `AgentRunService` | WP-03 |
| WP-05 | HTTP endpoints + SSE wiring | WP-04 |
| WP-06 | CLI commands + scheduler | WP-04 |
| WP-07 | `McpClientToolSource` (remote MCP consumption) | WP-03 |
| WP-08 | `ai-observability` listeners | WP-04 |
| WP-09 | Spec, docs, security review | all |

WP-01 → 05 are the critical path. WP-06 / 07 / 08 are parallelizable
after WP-04. WP-09 is the wrap-up.

## File reference (target shape)

| File | Class | Role |
|---|---|---|
| `packages/ai-tools/src/AgentTool.php` | `AgentTool` | Runtime tool VO |
| `packages/ai-tools/src/AgentToolInterface.php` | `AgentToolInterface` | Tool contract |
| `packages/ai-tools/src/AgentToolResult.php` | `AgentToolResult` | Tool execution result VO |
| `packages/ai-tools/src/Attribute/AsAgentTool.php` | `AsAgentTool` | Discovery attribute |
| `packages/ai-tools/src/ToolRegistryInterface.php` | `ToolRegistryInterface` | Catalogue contract |
| `packages/ai-tools/src/Catalogue/AttributeToolRegistry.php` | `AttributeToolRegistry` | Manifest-compiler-discovered registry |
| `packages/ai-tools/src/Entity/EntityReadTool.php` | `EntityReadTool` | `entity.read` |
| `packages/ai-tools/src/Entity/EntityListTool.php` | `EntityListTool` | `entity.list` |
| `packages/ai-tools/src/Entity/EntityCreateTool.php` | `EntityCreateTool` | `entity.create` (destructive) |
| `packages/ai-tools/src/Entity/EntityUpdateTool.php` | `EntityUpdateTool` | `entity.update` (destructive) |
| `packages/ai-tools/src/Entity/EntityDeleteTool.php` | `EntityDeleteTool` | `entity.delete` (destructive) |
| `packages/ai-tools/src/Entity/EntitySearchTool.php` | `EntitySearchTool` | full-text |
| `packages/ai-tools/src/Relationship/RelationshipTraverseTool.php` | `RelationshipTraverseTool` | graph traversal |
| `packages/ai-tools/src/Vector/VectorSearchTool.php` | `VectorSearchTool` | semantic search |
| `packages/ai-agent/src/AgentDefinition.php` | `AgentDefinition` | Bundle VO |
| `packages/ai-agent/src/AgentDefinitionRegistry.php` | `AgentDefinitionRegistry` | Named agent registry |
| `packages/ai-agent/src/Attribute/AsAgentDefinition.php` | `AsAgentDefinition` | Definition discovery attribute |
| `packages/ai-agent/src/AgentRunService.php` | `AgentRunService` | `enqueue()` + `runInline()` |
| `packages/ai-agent/src/Message/RunAgent.php` | `RunAgent` | Messenger message |
| `packages/ai-agent/src/Message/RunAgentHandler.php` | `RunAgentHandler` | Worker handler |
| `packages/ai-agent/src/Entity/AgentRun.php` | `AgentRun` | Persisted run entity |
| `packages/ai-agent/src/Entity/AgentAuditLog.php` | `AgentAuditLog` | Persisted audit log entity |
| `packages/ai-agent/src/Repository/AgentRunRepository.php` | `AgentRunRepository` | Run persistence |
| `packages/ai-agent/src/Repository/AgentAuditLogRepository.php` | `AgentAuditLogRepository` | Audit persistence |
| `packages/ai-agent/src/Mcp/McpClientToolSource.php` | `McpClientToolSource` | Remote MCP client |
| `packages/ai-agent/src/Reaper/StalledRunReaper.php` | `StalledRunReaper` | Reaper logic |
| `packages/api/src/Controller/AgentRunController.php` | `AgentRunController` | HTTP surface |
| `packages/routing/src/AgentRouteServiceProvider.php` | `AgentRouteServiceProvider` | Route registration (Layer 4) |
| `packages/cli/src/Command/Ai/AiRunCommand.php` | `AiRunCommand` | `ai:run` |
| `packages/cli/src/Command/Ai/AiPurgeRunsCommand.php` | `AiPurgeRunsCommand` | `ai:purge-runs` |
| `packages/cli/src/Command/Ai/AiReapStalledRunsCommand.php` | `AiReapStalledRunsCommand` | `ai:reap-stalled-runs` |

## References

- `docs/specs/ai-integration.md` — broader AI subsystem (vector, pipeline,
  schema, embeddings).
- `docs/specs/mcp-endpoint.md` — the MCP server Waaseyaa hosts.
- `docs/specs/broadcasting.md` — SSE delivery via `BroadcastStorage`.
- `docs/specs/authoring-assist-contract.md` — a downstream consumer that
  this runtime will eventually back.
- `docs/specs/infrastructure.md` — `LoggerInterface`, error policy.
- Issue #1496 — agent consumer decision (this spec is the answer).
- PR #1508 — companion orphan deletion (`McpServer` removed).
- `kitty-specs/archive/ai-agent-end-to-end-01KRW91P/` — the archived
  predecessor mission whose split produced this design pass.
