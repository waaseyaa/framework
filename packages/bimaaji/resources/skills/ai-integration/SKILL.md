---
name: waaseyaa:ai-integration
description: Use when working with AI schema generation, agent execution, pipeline orchestration, vector storage, agent tools, or files in packages/ai-schema/, packages/ai-agent/, packages/ai-pipeline/, packages/ai-vector/, packages/ai-tools/, packages/ai-observability/
---

# AI Integration Specialist

## Scope

This skill covers the AI packages in layer 5–6 of the Waaseyaa architecture:

- `packages/ai-schema/` -- JSON Schema generation from entity types
- `packages/ai-agent/` -- Agent runtime: executor, run service, Messenger handler, HTTP controller, persisted `AgentRun` + `AgentAuditLog` entities, HITL state machine, stalled-run reaper
- `packages/ai-tools/` -- Shared tool catalogue (8 stock tools + `#[AsAgentTool]` attribute discovery; remote MCP via `McpClientToolSource`)
- `packages/ai-pipeline/` -- Config-entity-based processing pipelines with sync and async execution
- `packages/ai-vector/` -- Vector embedding storage, similarity search, distance metrics
- `packages/ai-observability/` -- AgentRun lifecycle listeners, token/cost metrics, `ModelPriceTable`

Use this skill when:
- Modifying or extending any file in `packages/ai-schema/src/`, `packages/ai-agent/src/`, `packages/ai-tools/src/`, `packages/ai-pipeline/src/`, `packages/ai-vector/src/`, or `packages/ai-observability/src/`
- Writing tests in any of those packages' `tests/` directories
- Adding new agent tools, agent definitions, pipeline steps, or embedding providers
- Debugging tool execution, agent runs, schema generation, pipeline flow, or vector search

## Running an agent

### CLI

```bash
bin/waaseyaa ai:run "<prompt>" --inline
# Inline (sync) mode runs the agent in the current process. Useful for dev/CI.

bin/waaseyaa ai:run "<prompt>" --agent=<bundle>
# Async mode: enqueues a RunAgent message; a worker consumes it.

bin/waaseyaa ai:purge-runs --older-than=30d
bin/waaseyaa ai:reap-stalled-runs
```

### HTTP

```http
POST /api/ai/agent/run        # 202 Accepted + { run_id, ... }
GET  /api/ai/agent/run/{id}   # current AgentRun state
DELETE /api/ai/agent/run/{id} # cancel
POST /api/ai/agent/run/{id}/approve  # HITL: approve a pending tool call
```

Stream progress via the durable broadcast channel:

```http
GET /broadcast?channels=agent.run.<id>     # SSE; events: run_started, iteration, tool_call, tool_result, approval_required, run_completed, run_failed, run_cancelled
```

### Extension

Register an agent bundle:

```php
use Waaseyaa\AI\Agent\Attribute\AsAgentDefinition;

#[AsAgentDefinition(id: 'my_agent', model: 'gpt-4o-mini')]
final class MyAgent
{
    public function __construct(
        public readonly string $prompt = '...',
        public readonly array $tools = ['entity.read', 'entity.search'],
    ) {}
}
```

Register a tool:

```php
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\AI\Tools\AbstractAgentTool;

#[AsAgentTool(name: 'my_tool', capability: 'my.feature', destructive: false)]
final class MyTool extends AbstractAgentTool { /* execute() */ }
```

Both classes are auto-discovered by the package-manifest compiler. Run `bin/waaseyaa optimize:manifest` after adding them.

## Where the code lives

| Concern | Location |
|---|---|
| Agent runtime / executor | `packages/ai-agent/src/` (`AgentExecutor`, `AgentDefinition`, `AgentDefinitionRegistry`, `AgentRunService`) |
| Run service + worker | `packages/ai-agent/src/Service/AgentRunService.php`, `packages/ai-agent/src/Message/{RunAgent,RunAgentHandler}.php` |
| HTTP controller + validator | `packages/ai-agent/src/Controller/{AgentRunController,AgentRunRequestValidator}.php` |
| Routes | `packages/ai-agent/src/Routing/AgentRouteServiceProvider.php` (post-review: routes ride with the package, not `packages/routing`) |
| Persisted entities | `packages/ai-agent/src/Entity/{AgentRun,AgentAuditLog}.php` + repositories in `packages/ai-agent/src/Repository/` |
| Access policies | `packages/ai-agent/src/AccessPolicy/AgentRunAccessPolicy.php` (initiator ownership + `agent.run.bypass_ownership` capability) |
| Tools catalogue | `packages/ai-tools/src/` (8 stock tools + `AttributeToolRegistry`) |
| Remote MCP source | `packages/ai-agent/src/Mcp/McpClientToolSource.php` + `StreamableHttpMcpClient` |
| Stalled-run reaper | `packages/ai-agent/src/Service/StalledRunReaper.php` |
| CLI commands | `packages/cli/src/Command/Ai/{AiRunCommand,AiPurgeRunsCommand,AiReapStalledRunsCommand}.php` |
| Schedule entries | `packages/scheduler/src/Schedule/Ai/AgentScheduleEntries.php` |
| Observability | `packages/ai-observability/src/Listener/AgentRunTelemetryListener.php`, `packages/ai-observability/src/Pricing/ModelPriceTable.php` |

Cross-reference: `packages/ai-tools/README.md` for the tool catalogue surface.

## Key Interfaces

### EmbeddingInterface (`packages/ai-vector/src/EmbeddingInterface.php`)

```php
namespace Waaseyaa\AI\Vector;

interface EmbeddingInterface
{
    public function embed(string $text): array;       // float[]
    public function embedBatch(array $texts): array;  // float[][]
    public function getDimensions(): int;
}
```

### EmbeddingStorageInterface (`packages/ai-vector/src/EmbeddingStorageInterface.php`)

```php
interface EmbeddingStorageInterface
{
    public function store(string $entityType, string $id, array $vector): void;
    public function findSimilar(array $queryVector, string $entityType, int $limit): array;
    public function delete(string $entityType, string $id): void;
}
```

One vector per exact type/string ID, no persisted metadata or language variants.
Search returns `{id: string, score: float}` arrays in descending cosine order,
ties by bytewise ID. Finite nonempty numeric lists are required. Missing schema
and corrupt stored data refuse explicitly. The duplicate DTO/store family was
removed in #3141 candidate 2; do not recreate it or an adapter that discards
language/metadata. See `docs/specs/semantic-search-contract.md` and UPGRADING.md.

## Architecture

### Package Dependency Chain

```
ai-schema        depends on: entity
ai-tools         depends on: access, entity, entity-storage, foundation, media, publishing
ai-agent         depends on: access, ai-observability, ai-tools, api, audit, bimaaji, config,
                             database-legacy, entity, entity-storage, foundation, http-client, routing
ai-pipeline      depends on: entity, foundation
ai-vector        depends on: access, api, database-legacy, entity, entity-storage, foundation, queue, workflows
ai-observability depends on: ai-agent, database-legacy, entity, entity-storage, foundation
```

Layer discipline: ai-tools / ai-agent / ai-pipeline / ai-vector / ai-observability are in layer 5 (AI). Their direct package edges must match runtime imports, remain at layer 5 or below, and never import from layer 6 (interfaces).

### Namespace Conventions

- `Waaseyaa\AI\Schema\` -- ai-schema package
- `Waaseyaa\AI\Agent\` -- ai-agent package
- `Waaseyaa\AI\Tools\` -- ai-tools package (`AgentTool` VO, `AgentToolInterface`, `AttributeToolRegistry`, stock tools)
- `Waaseyaa\AI\Pipeline\` -- ai-pipeline package
- `Waaseyaa\AI\Vector\` -- ai-vector package
- `Waaseyaa\AI\Vector\Testing\` -- test fixtures within ai-vector
- `Waaseyaa\AI\Observability\` -- ai-observability package

### Schema Generation Flow

1. `EntityJsonSchemaGenerator` resolves the effective field set from `EntityTypeManagerInterface`
2. Requires a matching entity subject, access handler, and immutable principal
3. Delegates structural field shapes to the Layer-1 `FieldSchemaAuthority`
4. Produces a closed JSON Schema draft 2020-12 with `additionalProperties: false`

There is no `SchemaRegistry` or unscoped `generateAll()` entity catalogue in
the shipped package. AI tool schemas remain owned by `waaseyaa/ai-tools`.

### MCP endpoint

The framework's MCP surface is `Waaseyaa\Mcp\McpServerCard` in `packages/mcp/`, wired by `McpRouteProvider` at `/.well-known/mcp.json`. The earlier `Waaseyaa\AI\Agent\McpServer` `tools/list` + `tools/call` adapter was deleted as orphan scaffolding (#1498) — it was never reached. See `docs/specs/mcp-endpoint.md` for the live contract.

### Agent Execution Flow

1. Caller submits `RunAgentRequest` (CLI inline, HTTP enqueue) — `AgentRunService::enqueue()` persists an `AgentRun` row in `queued` state and dispatches a `RunAgent` Messenger message.
2. `RunAgentHandler::__invoke()` performs a CAS guard (`started_at IS NULL → markRunning()`) so duplicate worker delivery cannot double-execute (NFR-015). It then calls `AgentExecutor::executeWithProvider()`.
3. Each iteration: poll for cancellation, call the provider, append `AgentAuditLog` rows (`provider_call`, `tool_call`, `tool_result`, `error`), broadcast SSE events on `agent.run.<id>`, check HITL state machine (`none` / `all` / `interactive`) for destructive tool gating.
4. Tool dispatch goes through `Waaseyaa\AI\Tools\ToolRegistryInterface::register(AgentTool)`. The legacy `(McpToolDefinition, callable)` signature is gone; tools carry their executor.
5. Terminal state (`completed`, `failed`, `cancelled`, `approval_timeout`) persists `transcript_json` (truncated at the configured cap, default 262144 bytes — overflow marked `[truncated]`), token / cost totals, and emits `run_completed` / `run_failed` / `run_cancelled` SSE.

### MCP endpoint integration

`McpController` (`packages/mcp/`) consumes the same `ToolRegistryInterface` from `packages/ai-tools` for `tools/list` and `tools/call`. Entity ACLs apply to every MCP tool call — the previous `McpToolExecutor::accessCheck(false)` bypass was removed in WP-03 (ADR-019).

### Vector Search Flow

1. Explicit `ai.vector_enabled` activation composes one storage/provider pair.
2. Shared default-deny `EmbeddingIndexPolicy` selects only configured fields and
   explicit label inclusion, and permits off-host providers only explicitly.
3. Lifecycle indexing and operator refresh use that policy and canonical
   `EmbeddingStorageInterface`; excluded/missing entities lose their vector.
4. `SearchController` and host-wired `VectorSearchTool` call `findSimilar`, load
   current entities, enforce access, and emit the documented wire envelopes.
5. Real migrated SQLite and hosted PostgreSQL pass the same storage conformance
   suite. Never qualify a fake legacy store as evidence for the runtime.

## Common Mistakes

### JSON symmetry

Embedding storage uses `json_encode(..., JSON_THROW_ON_ERROR)`. Always pair with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent null on corrupt data.

### Final classes cannot be mocked

All concrete classes in the AI packages are `final class`. PHPUnit's `createMock()` will fail on them. In tests:
- Mock interfaces (`AgentToolInterface`, `ToolRegistryInterface`, `EmbeddingInterface`, `EmbeddingStorageInterface`, `EntityTypeManagerInterface`, `AccountInterface`)
- Use real instances for value objects (`AgentResult`, `AgentTool`, `AgentToolResult`)
- Use `FakeEmbeddingProvider` for deterministic test embeddings
- Use real migrated `DatabaseEmbeddingStorage` on SQLite for storage conformance

### Tool access checks are enforced

Every tool in `packages/ai-tools/` enforces entity-level access against the initiator account. The previous `McpToolExecutor::accessCheck(false)` bypass was removed (ADR-019). External MCP clients now enforce entity-level access via their bearer-token account.

### AccountInterface::id() returns int|string

`AgentRun.initiator_id` stores the raw account id (string or int via the `_data` blob). Audit logs reference `account_id` similarly. Do not cast to `(int)` blindly — UUID-style ids will collapse to `0`.

### Embedding source projection

Configure `ai.vector_index` fields and explicit label inclusion. Do not embed
`toArray()` or all fields as a fallback. Save and refresh share the same
default-deny policy and explicit off-host permission.


## Testing Patterns

### Unit test locations

- `packages/ai-schema/tests/Unit/` -- EntityJsonSchemaGenerator, SchemaRegistry
- `packages/ai-tools/tests/Unit/` -- AgentTool, AgentToolResult, AttributeToolRegistry, stock tools
- `packages/ai-agent/tests/Unit/` -- AgentExecutor, AgentResult, AgentAction, AgentContext, AgentDefinition, RunAgentHandler, AgentRunService, StalledRunReaper, repositories
- `packages/ai-observability/tests/Unit/` -- AgentRunTelemetryListener, ModelPriceTable
- `packages/ai-vector/tests/Unit/` -- DatabaseEmbeddingStorage, lifecycle, policy, providers, SearchController; tests/Contract holds shared storage conformance
- `tests/Integration/PhaseN/AgentRuntime/` -- CliInlineRunTest, EnqueueAndConsumeTest, AsyncHttpRunTest, CancellationTest, InteractiveHitlTest, ReaperTest, PurgeJobTest, TelemetryTest, McpClientToolSourceTest, EntityPersistenceTest

### Running tests

```bash
# All AI package tests
./vendor/bin/phpunit --filter 'Waaseyaa\\AI'

# Single package
./vendor/bin/phpunit packages/ai-schema/tests/
./vendor/bin/phpunit packages/ai-tools/tests/
./vendor/bin/phpunit packages/ai-agent/tests/
./vendor/bin/phpunit packages/ai-observability/tests/
./vendor/bin/phpunit packages/ai-vector/tests/

# Agent-runtime integration suite
./vendor/bin/phpunit tests/Integration/PhaseN/AgentRuntime/
```

Do NOT use `-v` flag -- PHPUnit 10.5 rejects it.

### Test fixtures

- `FakeEmbeddingProvider` (`packages/ai-vector/testing/FakeEmbeddingProvider.php`) -- Development-only, deterministic hash-based vectors. Default 128 dimensions. Consumers must map `Waaseyaa\\AI\\Vector\\Testing\\` to the package's `testing/` directory in their own `autoload-dev`; Composer does not load dependency `autoload-dev` rules.
- `DatabaseEmbeddingStorage` uses the migration-owned database table. Run shared storage conformance on real SQLite and hosted PostgreSQL.

## Related Specs

- `docs/specs/agent-executor.md` -- Canonical v1 agent runtime spec: SCs, NFRs, audit invariants, HITL state machine, SSE vocabulary, security posture, ADR-019 access-bypass removal
- `docs/specs/ai-integration.md` -- Layer 5 AI surface overview (schema generation, agent runtime, pipelines, vector store)
- `docs/specs/authoring-assist-contract.md` -- Downstream consumer contract for agents
- `docs/specs/semantic-refresh-trigger-contract.md` -- Pipeline trigger contract
- `packages/ai-tools/README.md` -- Tool catalogue surface (`#[AsAgentTool]`, stock tools, remote MCP via `McpClientToolSource`)
- `packages/ai-agent/README.md` -- Agent-runtime package: surfaces (CLI, HTTP, Messenger), extension points, quality gates
- `CLAUDE.md` -- Project-wide gotchas including dual-state bug pattern, JSON symmetry, final class mocking
