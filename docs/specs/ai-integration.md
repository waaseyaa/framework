# AI Integration

Waaseyaa's AI layer (architecture layer 5) provides four packages that enable AI agents to introspect, mutate, and search CMS content. All four packages sit in the `packages/` directory and follow the standard `Waaseyaa\AI\*` namespace pattern.

## Packages

| Package | Namespace | Path | Purpose |
|---------|-----------|------|---------|
| ai-schema | `Waaseyaa\AI\Schema\` | `packages/ai-schema/src/` | JSON Schema generation, MCP tool definitions, tool execution |
| ai-agent | `Waaseyaa\AI\Agent\` | `packages/ai-agent/src/` | Agent executor, audit logging, MCP server adapter |
| ai-pipeline | `Waaseyaa\AI\Pipeline\` | `packages/ai-pipeline/src/` | Processing pipelines, step orchestration, async dispatch |
| ai-vector | `Waaseyaa\AI\Vector\` | `packages/ai-vector/src/` | Vector embeddings, similarity search, distance metrics |

### Package Dependencies

```
ai-schema   -> entity
ai-agent    -> ai-schema, access
ai-pipeline -> entity, queue
ai-vector   -> entity
```

`ai-agent` depends on `ai-schema` for `McpToolExecutor` and on `access` for `AccountInterface`. `ai-pipeline` depends on `queue` for async dispatch via `QueueInterface`. `ai-vector` and `ai-schema` depend only on `entity`.

## Schema Generation

**File:** `packages/ai-schema/src/EntityJsonSchemaGenerator.php`
**Class:** `Waaseyaa\AI\Schema\EntityJsonSchemaGenerator`

Generates JSON Schema (draft 2020-12) from registered entity types. Takes an `EntityTypeManagerInterface` and inspects entity type definitions to produce a schema array.

### Constructor

```php
public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
)
```

### Key Methods

- `generate(string $entityTypeId): array` -- Produces a single JSON Schema for one entity type.
- `generateAll(): array` -- Returns schemas for all registered entity types, keyed by entity type ID.

### Schema Shape

The generated schema maps entity keys to JSON Schema properties:

| Entity Key | JSON Schema Type | Required |
|------------|-----------------|----------|
| id | `['integer', 'string']` | Yes |
| uuid | `string` (format: uuid) | Yes |
| label | `string` | Yes |
| bundle | `string` | Yes |
| langcode | `string` | No |
| revision | `integer` (only if revisionable) | No |

The output always includes `'$schema' => 'https://json-schema.org/draft/2020-12/schema'` and sets `'additionalProperties' => true` to allow non-key fields.

## MCP Tool System

### McpToolDefinition

**File:** `packages/ai-schema/src/Mcp/McpToolDefinition.php`
**Class:** `Waaseyaa\AI\Schema\Mcp\McpToolDefinition`

Readonly value object matching the MCP tool registration format:

```php
final readonly class McpToolDefinition
{
    public function __construct(
        public string $name,        // snake_case, e.g. "create_node"
        public string $description, // human-readable
        public array $inputSchema,  // JSON Schema for input params
    ) {}

    public function toArray(): array; // MCP-compliant serialization
}
```

### McpToolGenerator

**File:** `packages/ai-schema/src/Mcp/McpToolGenerator.php`
**Class:** `Waaseyaa\AI\Schema\Mcp\McpToolGenerator`

For each registered entity type, generates five CRUD+query tools:

| Tool Pattern | Operation | Required Arguments |
|-------------|-----------|-------------------|
| `create_{type}` | Create entity | `attributes` |
| `read_{type}` | Read by ID | `id` |
| `update_{type}` | Update entity | `id`, `attributes` |
| `delete_{type}` | Delete by ID | `id` |
| `query_{type}` | Query with filters | (all optional) |

All tools accept optional `langcode` and `fallback` parameters for multilingual operations. The query tool supports `filters` (array of `{field, value, operator}`), `sort` (prefix `-` for descending), `limit` (default 50), and `offset` (default 0).

### TranslationToolGenerator

**File:** `packages/ai-schema/src/Mcp/TranslationToolGenerator.php`
**Class:** `Waaseyaa\AI\Schema\Mcp\TranslationToolGenerator`

Generates four translation-specific tools per entity type:

| Tool Pattern | Required Arguments |
|-------------|-------------------|
| `{type}_translations_list` | `id` |
| `{type}_translation_create` | `id`, `langcode`, `attributes` |
| `{type}_translation_update` | `id`, `langcode`, `attributes` |
| `{type}_translation_delete` | `id`, `langcode` |

### McpToolExecutor

**File:** `packages/ai-schema/src/Mcp/McpToolExecutor.php`
**Class:** `Waaseyaa\AI\Schema\Mcp\McpToolExecutor`

Executes MCP tool calls against the entity system. Parses tool names by iterating known operations (`create`, `read`, `update`, `delete`, `query`) and extracting the entity type ID from the suffix.

```php
public function execute(string $toolName, array $arguments): array
```

Returns MCP-compliant result arrays: `{content: [{type: 'text', text: JSON}]}` on success, with `isError: true` on failure. All JSON encoding uses `JSON_THROW_ON_ERROR`.

**Important:** The query operation calls `$query->accessCheck(false)` because MCP tool calls run in an AI agent context where access is managed at the agent level, not the query level.

**Important:** The update operation checks `$entity instanceof FieldableInterface` before calling `set()`. Non-fieldable entities return an error.

## SchemaRegistry

**File:** `packages/ai-schema/src/SchemaRegistry.php`
**Class:** `Waaseyaa\AI\Schema\SchemaRegistry`

Central facade combining JSON Schema and MCP tool outputs. Provides a unified API for AI agents to discover the full CMS surface area.

```php
final class SchemaRegistry
{
    public function __construct(
        private readonly EntityJsonSchemaGenerator $schemaGenerator,
        private readonly McpToolGenerator $toolGenerator,
    ) {}

    public function getSchema(string $entityTypeId): array;
    public function getAllSchemas(): array;
    public function getTools(): array;          // cached via null coalescing
    public function getTool(string $name): ?McpToolDefinition;
}
```

Tool definitions are cached in-memory via `$this->toolCache ??= $this->toolGenerator->generateAll()`. The cache lives for the request lifetime only.

## Agent Execution

### AgentInterface

**File:** `packages/ai-agent/src/AgentInterface.php`
**Class:** `Waaseyaa\AI\Agent\AgentInterface`

```php
interface AgentInterface
{
    public function execute(AgentContext $context): AgentResult;
    public function dryRun(AgentContext $context): AgentResult;
    public function describe(): string;
}
```

All agents must support both `execute()` (real mutations) and `dryRun()` (preview without changes). The `describe()` method returns a human-readable summary of the agent's purpose.

### AgentContext

**File:** `packages/ai-agent/src/AgentContext.php`
**Class:** `Waaseyaa\AI\Agent\AgentContext`

```php
final readonly class AgentContext
{
    public function __construct(
        public AccountInterface $account,   // user the agent acts as
        public array $parameters = [],      // agent-specific params
        public bool $dryRun = false,
    ) {}
}
```

Agents always operate as a specific user via `AccountInterface`. The `$parameters` array carries agent-specific input data.

### AgentResult and AgentAction

**File:** `packages/ai-agent/src/AgentResult.php`

```php
final readonly class AgentResult
{
    public bool $success;
    public string $message;
    public array $data;
    public array $actions;   // AgentAction[]

    public static function success(string $message, array $data = [], array $actions = []): self;
    public static function failure(string $message, array $data = []): self;
}
```

**File:** `packages/ai-agent/src/AgentAction.php`

```php
final readonly class AgentAction
{
    public string $type;         // 'create', 'update', 'delete', 'tool_call'
    public string $description;  // human-readable
    public array $data;          // structured action data
}
```

### AgentExecutor

**File:** `packages/ai-agent/src/AgentExecutor.php`
**Class:** `Waaseyaa\AI\Agent\AgentExecutor`

Wraps agent execution with safety guarantees and audit logging. Three execution paths:

1. `execute(AgentInterface, AgentContext): AgentResult` -- Full execution with try/catch.
2. `dryRun(AgentInterface, AgentContext): AgentResult` -- Preview mode with try/catch.
3. `executeTool(string $toolName, array $arguments, AgentContext): array` -- MCP tool call on behalf of an agent.

All three paths log to an in-memory audit log. Exceptions are caught and converted to failure results, never propagated. The `executeTool()` method delegates to `McpToolExecutor::execute()`.

### Audit Logging

**File:** `packages/ai-agent/src/AgentAuditLog.php`

```php
final readonly class AgentAuditLog
{
    public string $agentId;    // agent FQCN or 'tool'
    public int $accountId;     // user ID the agent acted as
    public string $action;     // 'execute', 'dry_run', or 'tool_call'
    public bool $success;
    public string $message;
    public array $data;
    public int $timestamp;     // Unix timestamp
}
```

The audit log is in-memory (`AgentExecutor::$auditLog`), retrieved via `getAuditLog()`, and accumulates across multiple executions within the same executor instance.

### McpServer

**File:** `packages/ai-agent/src/McpServer.php`
**Class:** `Waaseyaa\AI\Agent\McpServer`

Lightweight adapter exposing `tools/list` and `tools/call` from the MCP protocol. Not a full protocol server (no transport layer).

```php
public function listTools(): array;                         // {tools: [...]}
public function callTool(string $name, array $arguments): array; // MCP result
```

## Hybrid Search Ranking Contract

The AI vector search surface is implemented by `Waaseyaa\AI\Vector\SearchController` (`packages/ai-vector/src/SearchController.php`).

When an embedding provider is available, search runs in semantic mode and may apply relationship-context reranking:

- Base semantic score from embedding similarity.
- Graph context boost from published relationship adjacency.
- Deterministic tie-break by original semantic order.

When graph reranking changes order, JSON:API response meta includes:

- `ranking: semantic+graph_context`
- `ranking_weights`:
  - `semantic` (default `1.0`)
  - `graph_context` (default `0.001`)
- `graph_context_counts`: per-result relationship degree used in rerank
- `score_breakdown`: per-result deterministic explainability payload:
  - `semantic`
  - `graph_context`
  - `combined`
  - `base_rank`

Workflow visibility remains enforced at search output: node entities are only returned when resolved workflow state is `published`.

## AI-Assisted Discovery Blend Contract (v0.9)

The MCP discovery surface now exposes a deterministic blend contract via the `ai_discover` tool (`Waaseyaa\Mcp\McpController`):

- Input:
  - `query` (required, non-empty)
  - `type` (optional, defaults to `node`)
  - `limit` (optional, clamped `1..100`)
  - optional anchor pair `anchor_type` + `anchor_id` (must be provided together)
- Search foundation:
  - delegates to `SearchController` to preserve semantic/keyword fallback behavior and workflow visibility (`published` only for node output).
- Explainability output:
  - recommendations include deterministic explanation fields:
    - `semantic_score`
    - `graph_context_score`
    - `combined_score`
    - `base_rank`
    - `visibility_contract` (`published_only`)
- Graph context output:
  - when anchor is present, response includes anchor graph summary (`outbound`, `inbound`, `total`, and relationship type counts) computed with published-only relationship traversal.
- Enforcement:
  - anchor entity must exist and pass `view` access checks,
  - node anchors must be public (`workflow_state=published` or equivalent status),
  - invalid params return JSON-RPC `-32602`; authorization/publicity failures return execution error `-32000`.

## Pipeline System

### Pipeline (Config Entity)

**File:** `packages/ai-pipeline/src/Pipeline.php`
**Class:** `Waaseyaa\AI\Pipeline\Pipeline`

Config entity (extends `ConfigEntityBase`) with entity type ID `'pipeline'` and keys `{id, label}`. Contains an ordered list of `PipelineStepConfig` objects.

Constructor accepts `array $values` with optional `description` (string) and `steps` (array of step data or `PipelineStepConfig` objects).

**Critical:** The Pipeline class uses `syncStepsToValues()` to prevent the dual-state bug pattern documented in CLAUDE.md. Step data is always kept in sync between the `$this->steps` array and `$this->values['steps']`. Any mutation (`addStep()`, `removeStep()`) calls `syncStepsToValues()` immediately.

### PipelineStepConfig

**File:** `packages/ai-pipeline/src/PipelineStepConfig.php`

```php
final readonly class PipelineStepConfig
{
    public string $id;            // step ID within the pipeline
    public string $pluginId;      // plugin ID of the step implementation
    public string $label;
    public int $weight;           // execution order (lower = first)
    public array $configuration;  // step-specific config
}
```

### PipelineStepInterface

**File:** `packages/ai-pipeline/src/PipelineStepInterface.php`

```php
interface PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult;
    public function describe(): string;
}
```

Steps receive input from the previous step (or the pipeline trigger) and return a `StepResult`. The `PipelineContext` carries shared state across all steps.

### StepResult

**File:** `packages/ai-pipeline/src/StepResult.php`

Three factory methods control pipeline flow:

- `StepResult::success(array $output, string $message)` -- Continue to next step.
- `StepResult::failure(string $message, array $output)` -- Stop pipeline, mark as failed.
- `StepResult::halt(string $message, array $output)` -- Stop pipeline, mark as succeeded.

The `$stopPipeline` flag (set by `halt()`) triggers early exit without failure.

### PipelineExecutor

**File:** `packages/ai-pipeline/src/PipelineExecutor.php`
**Class:** `Waaseyaa\AI\Pipeline\PipelineExecutor`

Synchronous executor. Takes a map of `PipelineStepInterface` implementations keyed by plugin ID.

```php
public function __construct(private readonly array $stepPlugins = [])
public function execute(Pipeline $pipeline, array $input = []): PipelineResult
```

Execution flow:
1. Gets steps from the pipeline (sorted by weight).
2. Creates a `PipelineContext` with the pipeline ID and start timestamp.
3. Iterates steps in weight order. Each step's `$output` becomes the next step's `$input`.
4. Before each step, sets `_step_configuration` in the context.
5. Stops on: step failure, step halt, or missing plugin ID.
6. Returns `PipelineResult` with timing info (`hrtime(true)` for nanosecond precision).

### PipelineDispatcher (Async)

**File:** `packages/ai-pipeline/src/PipelineDispatcher.php`

Fire-and-forget async dispatch via `QueueInterface`:

```php
public function dispatch(Pipeline $pipeline, array $input = []): PipelineQueueMessage
```

Creates a `PipelineQueueMessage` with the pipeline ID, input data, and creation timestamp, then pushes it to the queue.

### PipelineResult

**File:** `packages/ai-pipeline/src/PipelineResult.php`

```php
final readonly class PipelineResult
{
    public bool $success;
    public array $stepResults;    // StepResult[]
    public array $finalOutput;    // output from last successful step
    public string $message;
    public float $durationMs;     // total execution time
}
```

## Vector Storage and Embeddings

### EmbeddingInterface

**File:** `packages/ai-vector/src/EmbeddingInterface.php`

```php
interface EmbeddingInterface
{
    public function embed(string $text): array;          // float[]
    public function embedBatch(array $texts): array;     // float[][]
    public function getDimensions(): int;
}
```

Implementations connect to embedding providers (OpenAI, local models, etc.). The `getDimensions()` method returns the vector dimensionality.

### VectorStoreInterface

**File:** `packages/ai-vector/src/VectorStoreInterface.php`

```php
interface VectorStoreInterface
{
    public function store(EntityEmbedding $embedding): void;
    public function delete(string $entityTypeId, int|string $entityId): void;
    public function search(
        array $queryVector,
        int $limit = 10,
        ?string $entityTypeId = null,
        ?string $langcode = null,
        array $fallbackLangcodes = [],
    ): array;  // SimilarityResult[]
    public function get(string $entityTypeId, int|string $entityId): ?EntityEmbedding;
    public function has(string $entityTypeId, int|string $entityId): bool;
}
```

The `search()` method supports language-aware retrieval: filter by `$langcode`, and if no results are found, try each `$fallbackLangcodes` in order.

### EntityEmbedding

**File:** `packages/ai-vector/src/EntityEmbedding.php`

```php
final readonly class EntityEmbedding
{
    public string $entityTypeId;
    public int|string $entityId;
    public array $vector;           // float[]
    public string $langcode;        // '' means language-neutral
    public array $metadata;         // e.g. {label, bundle}
    public int $createdAt;
}
```

### EntityEmbedder

**File:** `packages/ai-vector/src/EntityEmbedder.php`

High-level service that composes `EmbeddingInterface` and `VectorStoreInterface`:

```php
public function embedEntity(EntityInterface $entity): EntityEmbedding;
public function searchSimilar(string $query, int $limit = 10, ?string $entityTypeId = null): array;
public function removeEntity(string $entityTypeId, int|string $entityId): void;
```

`embedEntity()` builds text from `$entity->label() . ' ' . json_encode($entity->toArray(), JSON_THROW_ON_ERROR)`, generates a vector, stores it with metadata `{label, bundle}`, and returns the embedding.

### InMemoryVectorStore

**File:** `packages/ai-vector/src/InMemoryVectorStore.php`

In-memory implementation for testing. Uses cosine similarity. Stores embeddings keyed by `"{entityTypeId}:{entityId}:{langcode}"`. The `delete()` method removes all langcode variants for an entity.

### DistanceMetric

**File:** `packages/ai-vector/src/DistanceMetric.php`

```php
enum DistanceMetric: string
{
    case COSINE = 'cosine';
    case EUCLIDEAN = 'euclidean';
    case DOT_PRODUCT = 'dot_product';
}
```

### FakeEmbeddingProvider (Testing)

**File:** `packages/ai-vector/src/Testing/FakeEmbeddingProvider.php`

Deterministic embedding provider for tests. Generates vectors by SHA-256 hashing the input text with HMAC iterations, then normalizing to unit magnitude. Same text always produces the same vector. Default dimensionality is 128.

## Tool Safety

MCP tool execution has the following safety properties:

1. **Exception isolation:** `AgentExecutor` wraps all execution paths in try/catch. Exceptions never propagate to the caller; they are converted to failure results and logged.
2. **Audit trail:** Every agent execution, dry-run, and tool call is recorded in `AgentAuditLog` with the agent ID, account ID, action type, success status, message, and timestamp.
3. **User context:** Agents always execute as a specific `AccountInterface`. The account ID is logged in every audit entry.
4. **Dry-run support:** All agents must implement `dryRun()` to preview changes without mutations.
5. **Query access bypass:** `McpToolExecutor` sets `accessCheck(false)` on entity queries. Access control for MCP tool calls is expected to be enforced at the agent/endpoint level, not the individual query level.
6. **FieldableInterface check:** Updates verify the entity implements `FieldableInterface` before calling `set()`.

## Dual-State Bug Pattern

The Pipeline class explicitly guards against the dual-state bug pattern documented in CLAUDE.md. When entity data can exist in two locations (typed properties and the `$values` array), mutations must update both or use one canonical source.

Pipeline uses `syncStepsToValues()` to maintain a single source of truth. Called after every mutation (`addStep()`, `removeStep()`, and in the constructor). The `toConfig()` method reads from the `$this->steps` array (the canonical source) and serializes step configs inline.

**Rule:** When working on Pipeline or similar config entities with typed properties, always call the sync method after mutations. Do not read from `$this->values['steps']` directly; use `$this->getSteps()`.

## File Reference

| File | Class | Role |
|------|-------|------|
| `packages/ai-schema/src/EntityJsonSchemaGenerator.php` | `EntityJsonSchemaGenerator` | JSON Schema draft 2020-12 from entity types |
| `packages/ai-schema/src/SchemaRegistry.php` | `SchemaRegistry` | Unified schema + tool facade |
| `packages/ai-schema/src/Mcp/McpToolDefinition.php` | `McpToolDefinition` | MCP tool value object |
| `packages/ai-schema/src/Mcp/McpToolGenerator.php` | `McpToolGenerator` | CRUD tool generation per entity type |
| `packages/ai-schema/src/Mcp/McpToolExecutor.php` | `McpToolExecutor` | Tool call execution against entity system |
| `packages/ai-schema/src/Mcp/TranslationToolGenerator.php` | `TranslationToolGenerator` | Translation-specific tool generation |
| `packages/ai-agent/src/AgentInterface.php` | `AgentInterface` | Agent contract: execute, dryRun, describe |
| `packages/ai-agent/src/AgentExecutor.php` | `AgentExecutor` | Safety wrapper + audit logging |
| `packages/ai-agent/src/AgentContext.php` | `AgentContext` | Execution context with account + params |
| `packages/ai-agent/src/AgentResult.php` | `AgentResult` | Execution result with actions |
| `packages/ai-agent/src/AgentAction.php` | `AgentAction` | Single action value object |
| `packages/ai-agent/src/AgentAuditLog.php` | `AgentAuditLog` | Audit log entry |
| `packages/ai-agent/src/McpServer.php` | `McpServer` | MCP protocol adapter (tools/list, tools/call) |
| `packages/ai-pipeline/src/Pipeline.php` | `Pipeline` | Config entity for processing pipelines |
| `packages/ai-pipeline/src/PipelineStepInterface.php` | `PipelineStepInterface` | Step plugin contract |
| `packages/ai-pipeline/src/PipelineStepConfig.php` | `PipelineStepConfig` | Step configuration value object |
| `packages/ai-pipeline/src/PipelineContext.php` | `PipelineContext` | Shared execution state |
| `packages/ai-pipeline/src/StepResult.php` | `StepResult` | Step result (success/failure/halt) |
| `packages/ai-pipeline/src/PipelineResult.php` | `PipelineResult` | Full pipeline execution result |
| `packages/ai-pipeline/src/PipelineExecutor.php` | `PipelineExecutor` | Synchronous pipeline runner |
| `packages/ai-pipeline/src/PipelineDispatcher.php` | `PipelineDispatcher` | Async queue dispatch |
| `packages/ai-pipeline/src/PipelineQueueMessage.php` | `PipelineQueueMessage` | Queue message value object |
| `packages/ai-vector/src/EmbeddingInterface.php` | `EmbeddingInterface` | Embedding provider contract |
| `packages/ai-vector/src/VectorStoreInterface.php` | `VectorStoreInterface` | Vector storage contract |
| `packages/ai-vector/src/EntityEmbedding.php` | `EntityEmbedding` | Embedding value object |
| `packages/ai-vector/src/SimilarityResult.php` | `SimilarityResult` | Search result with score |
| `packages/ai-vector/src/EntityEmbedder.php` | `EntityEmbedder` | High-level embed + search service |
| `packages/ai-vector/src/InMemoryVectorStore.php` | `InMemoryVectorStore` | In-memory store (cosine similarity) |
| `packages/ai-vector/src/DistanceMetric.php` | `DistanceMetric` | Distance metric enum |
| `packages/ai-vector/src/Testing/FakeEmbeddingProvider.php` | `FakeEmbeddingProvider` | Deterministic test embeddings |
