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

### PipelineStepInterface (`packages/ai-pipeline/src/PipelineStepInterface.php`)

```php
namespace Waaseyaa\AI\Pipeline;

interface PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult;
    public function describe(): string;
}
```

Steps receive input data and a shared context. Return `StepResult::success()`, `StepResult::failure()`, or `StepResult::halt()`.

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

### VectorStoreInterface (`packages/ai-vector/src/VectorStoreInterface.php`)

```php
namespace Waaseyaa\AI\Vector;

interface VectorStoreInterface
{
    public function store(EntityEmbedding $embedding): void;
    public function delete(string $entityTypeId, int|string $entityId): void;
    public function search(array $queryVector, int $limit = 10, ?string $entityTypeId = null, ?string $langcode = null, array $fallbackLangcodes = []): array;
    public function get(string $entityTypeId, int|string $entityId): ?EntityEmbedding;
    public function has(string $entityTypeId, int|string $entityId): bool;
}
```

## Architecture

### Package Dependency Chain

```
ai-schema        depends on: entity
ai-tools         depends on: entity, access, ai-schema, ai-vector
ai-agent         depends on: ai-schema, ai-tools, access, entity-storage, queue
ai-pipeline      depends on: entity, queue
ai-vector        depends on: entity
ai-observability depends on: ai-agent, telescope
```

Layer discipline: ai-tools / ai-agent / ai-pipeline / ai-vector / ai-observability are in layer 5 (AI). They depend downward on layer 1 (entity, entity-storage, access) and layer 0 (queue). They must never import from layer 6 (interfaces).

### Namespace Conventions

- `Waaseyaa\AI\Schema\` -- ai-schema package
- `Waaseyaa\AI\Agent\` -- ai-agent package
- `Waaseyaa\AI\Tools\` -- ai-tools package (`AgentTool` VO, `AgentToolInterface`, `AttributeToolRegistry`, stock tools)
- `Waaseyaa\AI\Pipeline\` -- ai-pipeline package
- `Waaseyaa\AI\Vector\` -- ai-vector package
- `Waaseyaa\AI\Vector\Testing\` -- test fixtures within ai-vector
- `Waaseyaa\AI\Observability\` -- ai-observability package

### Schema Generation Flow

1. `EntityJsonSchemaGenerator` reads entity type definitions from `EntityTypeManagerInterface`
2. Maps entity keys (id, uuid, label, bundle, langcode, revision) to JSON Schema properties
3. Produces JSON Schema draft 2020-12 with `additionalProperties: true`
4. `SchemaRegistry` caches tool definitions in memory via `$this->toolCache ??= $this->toolGenerator->generateAll()`

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

### Pipeline Execution Flow

1. `PipelineExecutor::execute()` gets steps from the `Pipeline` config entity, sorted by weight (lower first)
2. Creates a `PipelineContext` with pipeline ID and start timestamp
3. For each step: looks up plugin by `pluginId`, sets `_step_configuration` in context, calls `process()`
4. Output of each step becomes input for the next
5. Stops on: `StepResult::failure()`, `StepResult::halt()`, or missing plugin
6. Returns `PipelineResult` with nanosecond-precision timing via `hrtime(true)`

### Vector Search Flow

1. `EntityEmbedder::embedEntity()` builds text as `label + ' ' + json_encode(toArray())`
2. Passes text to `EmbeddingInterface::embed()` to get a float vector
3. Stores `EntityEmbedding` via `VectorStoreInterface::store()`
4. Search: `EntityEmbedder::searchSimilar($query, $account, ...)` embeds the query string, calls `VectorStoreInterface::search()`, then filters fail-closed: each hit is loaded through its repository and dropped unless `EntityAccessHandler::check($entity, 'view', $account)` is Allowed (an account is REQUIRED)
5. `InMemoryVectorStore::search()` computes cosine similarity, supports langcode filtering with fallbacks

## Common Mistakes

### Dual-state bug in Pipeline

The Pipeline class stores steps in both `$this->steps` (typed array) and `$this->values['steps']` (entity values array). Every mutation must call `syncStepsToValues()`. If you add a method that modifies `$this->steps`, call `$this->syncStepsToValues()` at the end. Never read from `$this->values['steps']` directly; use `$this->getSteps()`.

### JSON symmetry

`EntityEmbedder` uses `json_encode(..., JSON_THROW_ON_ERROR)`. Always pair with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent null on corrupt data.

### Final classes cannot be mocked

All concrete classes in the AI packages are `final class`. PHPUnit's `createMock()` will fail on them. In tests:
- Mock interfaces (`AgentToolInterface`, `ToolRegistryInterface`, `PipelineStepInterface`, `EmbeddingInterface`, `VectorStoreInterface`, `EntityTypeManagerInterface`, `AccountInterface`)
- Use real instances for value objects (`AgentResult`, `AgentTool`, `AgentToolResult`, `StepResult`, `EntityEmbedding`)
- Use `FakeEmbeddingProvider` for deterministic test embeddings
- Use `InMemoryVectorStore` for vector storage in tests

### Pipeline step plugins must be anonymous classes in tests

The `PipelineStepInterface` is not `final`, so it can be mocked. But for integration-style unit tests, use anonymous classes:

```php
$step = new class implements PipelineStepInterface {
    public function process(array $input, PipelineContext $context): StepResult
    {
        return StepResult::success(['text' => strtoupper($input['text'])]);
    }
    public function describe(): string { return 'Uppercase'; }
};
```

### Tool access checks are enforced

Every tool in `packages/ai-tools/` enforces entity-level access against the initiator account. The previous `McpToolExecutor::accessCheck(false)` bypass was removed (ADR-019). External MCP clients now enforce entity-level access via their bearer-token account.

### AccountInterface::id() returns int|string

`AgentRun.initiator_id` stores the raw account id (string or int via the `_data` blob). Audit logs reference `account_id` similarly. Do not cast to `(int)` blindly — UUID-style ids will collapse to `0`.

### EntityEmbedder text building

`EntityEmbedder::buildEntityText()` concatenates `label() . ' ' . json_encode(toArray())`. If you need to customize what text gets embedded, you must modify `buildEntityText()` or create a new embedder. There is no text extraction hook.

### InMemoryVectorStore key format

Keys are `"{entityTypeId}:{entityId}:{langcode}"`. The `delete()` method removes all langcode variants by matching the prefix `"{entityTypeId}:{entityId}:"`. The `get()` method returns the first match for any langcode.

## Testing Patterns

### Unit test locations

- `packages/ai-schema/tests/Unit/` -- EntityJsonSchemaGenerator, SchemaRegistry
- `packages/ai-tools/tests/Unit/` -- AgentTool, AgentToolResult, AttributeToolRegistry, stock tools
- `packages/ai-agent/tests/Unit/` -- AgentExecutor, AgentResult, AgentAction, AgentContext, AgentDefinition, RunAgentHandler, AgentRunService, StalledRunReaper, repositories
- `packages/ai-observability/tests/Unit/` -- AgentRunTelemetryListener, ModelPriceTable
- `packages/ai-pipeline/tests/Unit/` -- Pipeline, PipelineExecutor, PipelineContext, PipelineStepConfig, PipelineDispatcher, StepResult, PipelineResult, PipelineQueueMessage
- `packages/ai-vector/tests/Unit/` -- InMemoryVectorStore, EntityEmbedder, EntityEmbedding, SimilarityResult, FakeEmbeddingProvider, DistanceMetric, LanguageAwareVectorTest
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
./vendor/bin/phpunit packages/ai-pipeline/tests/
./vendor/bin/phpunit packages/ai-vector/tests/

# Agent-runtime integration suite
./vendor/bin/phpunit tests/Integration/PhaseN/AgentRuntime/
```

Do NOT use `-v` flag -- PHPUnit 10.5 rejects it.

### Test fixtures

- `FakeEmbeddingProvider` (`packages/ai-vector/src/Testing/FakeEmbeddingProvider.php`) -- Deterministic, hash-based vectors. Default 128 dimensions. Use for all tests needing embeddings.
- `InMemoryVectorStore` (`packages/ai-vector/src/InMemoryVectorStore.php`) -- Cosine similarity, no external dependencies. Use for all vector storage tests.
- `TestAgent` (`packages/ai-agent/tests/Unit/TestAgent.php`) -- Configurable test agent with settable results and exceptions.

### Pattern: Testing PipelineExecutor

```php
$step = new class implements PipelineStepInterface {
    public function process(array $input, PipelineContext $context): StepResult {
        return StepResult::success(['result' => $input['text'] . '_processed']);
    }
    public function describe(): string { return 'Test step'; }
};

$executor = new PipelineExecutor(['my_step' => $step]);
$pipeline = new Pipeline([
    'id' => 'test_pipeline',
    'label' => 'Test',
    'steps' => [
        ['id' => 'step_1', 'plugin_id' => 'my_step', 'weight' => 0],
    ],
]);
$result = $executor->execute($pipeline, ['text' => 'hello']);
```

### Pattern: Testing vector search

```php
$provider = new FakeEmbeddingProvider(dimensions: 128);
$store = new InMemoryVectorStore();
$embedder = new EntityEmbedder($provider, $store, $accessHandler, $entityTypeManager);

$embedding = $embedder->embedEntity($entity);
// searchSimilar REQUIRES an account and returns only view-permitted results
// (fail-closed: each hit is loaded and gated through EntityAccessHandler).
$results = $embedder->searchSimilar('search query', $account, limit: 5, entityTypeId: 'node');
```

## Related Specs

- `docs/specs/agent-executor.md` -- Canonical v1 agent runtime spec: SCs, NFRs, audit invariants, HITL state machine, SSE vocabulary, security posture, ADR-019 access-bypass removal
- `docs/specs/ai-integration.md` -- Layer 5 AI surface overview (schema generation, agent runtime, pipelines, vector store)
- `docs/specs/authoring-assist-contract.md` -- Downstream consumer contract for agents
- `docs/specs/semantic-refresh-trigger-contract.md` -- Pipeline trigger contract
- `packages/ai-tools/README.md` -- Tool catalogue surface (`#[AsAgentTool]`, stock tools, remote MCP via `McpClientToolSource`)
- `packages/ai-agent/README.md` -- Agent-runtime package: surfaces (CLI, HTTP, Messenger), extension points, quality gates
- `CLAUDE.md` -- Project-wide gotchas including dual-state bug pattern, JSON symmetry, final class mocking
