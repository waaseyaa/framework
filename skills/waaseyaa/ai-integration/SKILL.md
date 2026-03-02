---
name: waaseyaa:ai-integration
description: Use when working with AI schema generation, agent execution, pipeline orchestration, vector storage, or files in packages/ai-schema/, packages/ai-agent/, packages/ai-pipeline/, packages/ai-vector/
---

# AI Integration Specialist

## Scope

This skill covers the four AI packages in layer 6 of the Waaseyaa architecture:

- `packages/ai-schema/` -- JSON Schema generation from entity types, MCP tool definitions and execution
- `packages/ai-agent/` -- Agent executor with audit logging, MCP server adapter
- `packages/ai-pipeline/` -- Config-entity-based processing pipelines with sync and async execution
- `packages/ai-vector/` -- Vector embedding storage, similarity search, distance metrics

Use this skill when:
- Modifying or extending any file in `packages/ai-schema/src/`, `packages/ai-agent/src/`, `packages/ai-pipeline/src/`, or `packages/ai-vector/src/`
- Writing tests in `packages/ai-schema/tests/`, `packages/ai-agent/tests/`, `packages/ai-pipeline/tests/`, or `packages/ai-vector/tests/`
- Adding new MCP tools, agent implementations, pipeline steps, or embedding providers
- Debugging schema generation, tool execution, pipeline flow, or vector search

## Key Interfaces

### AgentInterface (`packages/ai-agent/src/AgentInterface.php`)

```php
namespace Waaseyaa\AI\Agent;

interface AgentInterface
{
    public function execute(AgentContext $context): AgentResult;
    public function dryRun(AgentContext $context): AgentResult;
    public function describe(): string;
}
```

Every agent must implement both `execute()` and `dryRun()`. The `AgentContext` carries an `AccountInterface $account`, `array $parameters`, and `bool $dryRun`.

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
ai-schema   depends on: entity
ai-agent    depends on: ai-schema, access
ai-pipeline depends on: entity, queue
ai-vector   depends on: entity
```

Layer discipline: all four packages are in layer 6 (AI). They depend downward on layer 2 (entity) and layer 3 (access, queue). They must never import from layer 7 (interfaces) or from each other except `ai-agent -> ai-schema`.

### Namespace Conventions

- `Waaseyaa\AI\Schema\` -- ai-schema package
- `Waaseyaa\AI\Schema\Mcp\` -- MCP-specific classes within ai-schema
- `Waaseyaa\AI\Agent\` -- ai-agent package
- `Waaseyaa\AI\Pipeline\` -- ai-pipeline package
- `Waaseyaa\AI\Vector\` -- ai-vector package
- `Waaseyaa\AI\Vector\Testing\` -- test fixtures within ai-vector

### Schema Generation Flow

1. `EntityJsonSchemaGenerator` reads entity type definitions from `EntityTypeManagerInterface`
2. Maps entity keys (id, uuid, label, bundle, langcode, revision) to JSON Schema properties
3. Produces JSON Schema draft 2020-12 with `additionalProperties: true`
4. `SchemaRegistry` caches tool definitions in memory via `$this->toolCache ??= $this->toolGenerator->generateAll()`

### MCP Tool Execution Flow

1. `McpServer::callTool()` looks up the tool in `SchemaRegistry::getTool()`
2. Delegates to `McpToolExecutor::execute()`
3. `McpToolExecutor` parses tool name: iterates `['create', 'read', 'update', 'delete', 'query']`, matches prefix, extracts entity type ID
4. Dispatches to `executeCreate/Read/Update/Delete/Query` methods
5. Returns MCP-compliant array: `{content: [{type: 'text', text: JSON}]}`, with `isError: true` on failure

### Agent Execution Flow

1. Caller creates `AgentContext` with `AccountInterface`, parameters, and dryRun flag
2. `AgentExecutor::execute()` or `dryRun()` wraps the agent call in try/catch
3. Result (success or exception-wrapped failure) is logged to `AgentAuditLog`
4. Tool calls via `AgentExecutor::executeTool()` delegate to `McpToolExecutor` with audit logging

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
4. Search: `EntityEmbedder::searchSimilar()` embeds the query string, then calls `VectorStoreInterface::search()`
5. `InMemoryVectorStore::search()` computes cosine similarity, supports langcode filtering with fallbacks

## Common Mistakes

### Dual-state bug in Pipeline

The Pipeline class stores steps in both `$this->steps` (typed array) and `$this->values['steps']` (entity values array). Every mutation must call `syncStepsToValues()`. If you add a method that modifies `$this->steps`, call `$this->syncStepsToValues()` at the end. Never read from `$this->values['steps']` directly; use `$this->getSteps()`.

### JSON symmetry

`McpToolExecutor` and `EntityEmbedder` both use `json_encode(..., JSON_THROW_ON_ERROR)`. Always pair with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent null on corrupt data.

### Final classes cannot be mocked

All concrete classes in the AI packages are `final class`. PHPUnit's `createMock()` will fail on them. In tests:
- Mock interfaces (`AgentInterface`, `PipelineStepInterface`, `EmbeddingInterface`, `VectorStoreInterface`, `EntityTypeManagerInterface`, `AccountInterface`)
- Use real instances for value objects (`AgentResult`, `StepResult`, `EntityEmbedding`, `McpToolDefinition`)
- Use `FakeEmbeddingProvider` for deterministic test embeddings
- Use `InMemoryVectorStore` for vector storage in tests
- For `AgentExecutor` tests, create a concrete `TestAgent` class (see `packages/ai-agent/tests/Unit/TestAgent.php`)

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

### MCP tool name parsing is prefix-based

`McpToolExecutor::parseToolName()` iterates known operations and checks `str_starts_with()`. A tool named `create_` (empty entity type) throws `InvalidArgumentException`. A tool named `totally_invalid` (no matching prefix) also throws. The executor catches these and returns MCP error results.

### Query tool disables access checking

`McpToolExecutor::executeQuery()` calls `$query->accessCheck(false)`. Access control for MCP operations is enforced at the agent/endpoint level. Do not add access checks inside individual tool execution methods.

### AccountInterface::id() returns int|string

`AgentExecutor` casts `$context->account->id()` to `(int)` for the audit log's `accountId` field. If account IDs are strings (e.g., UUIDs), this cast will produce 0. Be aware of this when reviewing audit logs.

### EntityEmbedder text building

`EntityEmbedder::buildEntityText()` concatenates `label() . ' ' . json_encode(toArray())`. If you need to customize what text gets embedded, you must modify `buildEntityText()` or create a new embedder. There is no text extraction hook.

### InMemoryVectorStore key format

Keys are `"{entityTypeId}:{entityId}:{langcode}"`. The `delete()` method removes all langcode variants by matching the prefix `"{entityTypeId}:{entityId}:"`. The `get()` method returns the first match for any langcode.

## Testing Patterns

### Unit test locations

- `packages/ai-schema/tests/Unit/` -- EntityJsonSchemaGenerator, SchemaRegistry
- `packages/ai-schema/tests/Unit/Mcp/` -- McpToolGenerator, McpToolDefinition, McpToolExecutor, TranslationToolGenerator
- `packages/ai-agent/tests/Unit/` -- AgentExecutor, AgentResult, AgentAction, AgentContext, AgentAuditLog, McpServer
- `packages/ai-pipeline/tests/Unit/` -- Pipeline, PipelineExecutor, PipelineContext, PipelineStepConfig, PipelineDispatcher, StepResult, PipelineResult, PipelineQueueMessage
- `packages/ai-vector/tests/Unit/` -- InMemoryVectorStore, EntityEmbedder, EntityEmbedding, SimilarityResult, FakeEmbeddingProvider, DistanceMetric, LanguageAwareVectorTest

### Running tests

```bash
# All AI package tests
./vendor/bin/phpunit --filter 'Waaseyaa\\AI'

# Single package
./vendor/bin/phpunit packages/ai-schema/tests/
./vendor/bin/phpunit packages/ai-agent/tests/
./vendor/bin/phpunit packages/ai-pipeline/tests/
./vendor/bin/phpunit packages/ai-vector/tests/
```

Do NOT use `-v` flag -- PHPUnit 10.5 rejects it.

### Test fixtures

- `FakeEmbeddingProvider` (`packages/ai-vector/src/Testing/FakeEmbeddingProvider.php`) -- Deterministic, hash-based vectors. Default 128 dimensions. Use for all tests needing embeddings.
- `InMemoryVectorStore` (`packages/ai-vector/src/InMemoryVectorStore.php`) -- Cosine similarity, no external dependencies. Use for all vector storage tests.
- `TestAgent` (`packages/ai-agent/tests/Unit/TestAgent.php`) -- Configurable test agent with settable results and exceptions.

### Pattern: Testing AgentExecutor

```php
$entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
$toolExecutor = new McpToolExecutor($entityTypeManager);
$executor = new AgentExecutor($toolExecutor);

$account = $this->createMock(AccountInterface::class);
$account->method('id')->willReturn(1);
$context = new AgentContext(account: $account, parameters: ['key' => 'value']);

$result = $executor->execute($agent, $context);
$log = $executor->getAuditLog();
```

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
$embedder = new EntityEmbedder($provider, $store);

$embedding = $embedder->embedEntity($entity);
$results = $embedder->searchSimilar('search query', limit: 5, entityTypeId: 'node');
```

## Related Specs

- `docs/specs/ai-integration.md` -- Full specification with interface signatures and architecture details
- `docs/plans/2026-02-28-aurora-architecture-v2-design.md` -- Architecture v2 design (context for AI layer positioning)
- `CLAUDE.md` -- Project-wide gotchas including dual-state bug pattern, JSON symmetry, final class mocking
