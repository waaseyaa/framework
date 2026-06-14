# `waaseyaa/ai-observability` — Design

**Status:** Approved 2026-04-14
**Issue:** waaseyaa/framework#622 (parent epic: #619)
**Scope:** MVP + Cost (first PR). Metrics, exporters, dashboards, error taxonomy, introspection API deferred to follow-up issues.

## Purpose

The observability organ of the Waaseyaa agentic framework. Records what agents do, how well they do it, and how much they cost. Pure observation layer: no dependencies on `ai-agent`, `ai-memory`, or `ai-guardrails`. Any package can use it; if it's absent, consumers get no-op behavior.

## Package metadata

- **Package:** `waaseyaa/ai-observability`
- **Namespace:** `Waaseyaa\AI\Observability`
- **Layer:** 5 (AI)
- **Composer dependencies:** `waaseyaa/foundation`, `waaseyaa/entity`, `waaseyaa/entity-storage`, `waaseyaa/database-legacy`
- **Does NOT depend on:** `waaseyaa/ai-agent`, `waaseyaa/ai-schema`, `waaseyaa/ai-memory`, `waaseyaa/ai-guardrails`

## Architecture

```
┌─ Waaseyaa\AI\Observability\ ─────────────────────────────────┐
│  Recording:   TraceRecorder (primary API)                    │
│  Entities:    Trace (entity)                                 │
│  Storage:     trace_span supporting table (DBAL)             │
│  Cost:        CostTracker, TokenAccountant, BudgetManager    │
│  Outcomes:    OutcomeTracker                                 │
│  Analysis:    AnomalyDetector                                │
│  Events:      LlmCallListener, ToolCallListener              │
│  DI:          ObservabilityServiceProvider                   │
└──────────────────────────────────────────────────────────────┘
```

## Storage

### `Trace` — entity

Registered via `EntityType` in `ObservabilityServiceProvider::register()`. Schema provisioned through `SqlSchemaHandler`.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK autoincrement | |
| `uuid` | CHAR(36) unique | Public identifier; used in handles |
| `label` | VARCHAR(255) | Free-text name (e.g., `agent.plan`, `agent.tool_loop`) |
| `status` | VARCHAR(32) | `running` / `ok` / `error` / `aborted` |
| `started_at` | DATETIME | |
| `ended_at` | DATETIME NULL | |
| `outcome_status` | VARCHAR(32) NULL | `accepted` / `rejected` / `modified` |
| `outcome_feedback` | TEXT NULL | |
| `created_at` | DATETIME | Entity standard |

Non-schema values (arbitrary attributes, outcome metadata) go into the `_data` JSON blob per the existing SqlEntityStorage pattern.

### `trace_span` — supporting table (DBAL only)

Scoped to a parent `Trace`. Append-only event log; no lifecycle beyond insertion. Uses `DatabaseInterface::insert()` directly per the entity-storage-invariant exemption for supporting tables.

| Column | Type | Notes |
|---|---|---|
| `id` | INT PK autoincrement | |
| `uuid` | CHAR(36) unique | |
| `trace_uuid` | CHAR(36) | FK to `trace.uuid` (indexed) |
| `parent_span_uuid` | CHAR(36) NULL | For nested spans |
| `kind` | VARCHAR(32) | `llm_call` / `tool_call` / `decision` / `custom` |
| `name` | VARCHAR(255) | |
| `started_at` | DATETIME(6) | Microsecond precision for latency |
| `ended_at` | DATETIME(6) NULL | |
| `status` | VARCHAR(32) | `ok` / `error` |
| `attributes` | TEXT | JSON payload (cost_usd, tokens, reasoning, etc.) |

Schema provisioned by a migration shipped with the package.

## Configuration

In `config/waaseyaa.php`:

```php
'observability' => [
    'enabled' => true,
    'budget' => [
        'daily_limit_usd' => 50.00,
        'per_request_limit_usd' => 1.00,
    ],
],
```

When `enabled=false`, `TraceRecorder` returns no-op handles and performs zero DB writes.

## Recording API

### `TraceRecorderInterface`

```php
interface TraceRecorderInterface
{
    public function startTrace(string $label, array $attributes = []): TraceHandle;
    public function completeTrace(TraceHandle $handle, string $status = 'ok'): void;
    public function span(TraceHandle $handle, string $kind, string $name): SpanHandle;
    public function endSpan(SpanHandle $handle, array $attributes = []): void;
    public function recordDecision(TraceHandle $handle, DecisionTrace $decision): void;
    public function recordOutcome(TraceHandle $handle, Outcome $outcome): void;
}
```

### Value objects

- **`TraceHandle`** — readonly: `string $uuid`, `\DateTimeImmutable $startedAt`.
- **`SpanHandle`** — readonly: `string $uuid`, `string $traceUuid`, `string $kind`, `\DateTimeImmutable $startedAt`.
- **`DecisionTrace`** — readonly: `string $question`, `string $chosen`, `array $alternatives`, `string $reasoning`, `float $confidence`. Persisted as a span of `kind=decision` with fields serialized into `attributes`.
- **`Outcome`** — readonly: `string $status` (accepted/rejected/modified), `?string $feedback`, `array $metadata`. Persisted on the `Trace` entity (`outcome_status`, `outcome_feedback`, `_data`).

### Disabled mode

`NullTraceRecorder` implementation bound when `observability.enabled=false`. Returns sentinel handles (uuid=`"disabled"`), performs no writes, no-ops all operations.

## Cost model

### `TokenAccountant`

```php
final class TokenAccountant
{
    public function record(
        TraceHandle $handle,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens = 0,
    ): CostRecord;
}
```

Resolves per-model pricing via `ModelPricing` registry and writes a span of `kind=llm_call` with attributes `{cost_usd, input_tokens, output_tokens, cached_tokens, model}`. Returns a `CostRecord` value object for immediate use.

### `ModelPricing`

Static registry seeded with current Anthropic + OpenAI rates (input/output/cached USD per 1M tokens). Consumers can extend via config:

```php
'observability' => [
    'model_pricing' => [
        'my-custom-model' => ['input' => 1.00, 'output' => 5.00, 'cached' => 0.10],
    ],
],
```

Unknown models produce a `CostRecord` with `cost_usd=0.0` and a warning log. Never throws.

### `CostTracker`

```php
final class CostTracker
{
    public function totalForTrace(string $traceUuid): float;
    public function totalForPeriod(\DateTimeInterface $from, \DateTimeInterface $to): float;
}
```

Queries `trace_span` for `kind=llm_call`, sums `attributes.cost_usd`. Spans are the ledger — no separate cost table.

### `BudgetManager`

```php
final class BudgetManager
{
    public function check(float $projectedAdditionalUsd): BudgetDecision;
}

enum BudgetDecision: string {
    case ALLOW = 'allow';
    case WARN = 'warn';
    case DENY = 'deny';
}
```

Threshold logic:
- `DENY` if `projectedAdditionalUsd > per_request_limit_usd`, OR if `CostTracker::totalForPeriod(today) + projectedAdditionalUsd > daily_limit_usd`.
- `WARN` if daily total + projected exceeds 80% of `daily_limit_usd` (not yet over).
- `ALLOW` otherwise.

Returns a decision; the caller decides how to act. `ai-guardrails` (follow-up) will wire this into tool-execution gates.

## Event integration

ai-agent already dispatches (or will dispatch) structured events. This package ships listeners:

- **`LlmCallListener`** — subscribes to `Waaseyaa\AI\Agent\Event\LlmCallCompleted`. Reads model + token counts + latency + `traceUuid` attribute → calls `TokenAccountant::record()` on the active handle.
- **`ToolCallListener`** — subscribes to `ToolCallStarted` / `ToolCallCompleted`. Opens a `tool_call` span on start, closes it on completion with the result status in attributes.

Listeners registered via `ObservabilityServiceProvider::boot()` through the EventDispatcher. If ai-agent isn't installed, the event classes don't exist and listeners never receive callbacks — no runtime coupling.

### Trace correlation

Active traces held in a `TraceContext` (per-request singleton keyed by uuid). `TraceRecorder::startTrace()` registers the handle; event listeners look it up via the `traceUuid` attribute on the event. When no active trace matches, listeners log once at debug and skip recording.

## Anomaly detection

```php
final class AnomalyDetector
{
    /** @return Anomaly[] */
    public function check(Trace $trace): array;
}
```

MVP heuristics — not an ML system:

1. **Span-count outlier**: >3σ from rolling mean of traces with same `label` over last 7 days.
2. **Cost outlier**: total cost >2× median for same-label traces over last 7 days.
3. **Tool loop**: same tool called >N times (default 10) in one trace.
4. **High error ratio**: `error`-status spans ≥ 50% of total spans.

Rolling statistics computed on demand via DBAL queries. No separate anomaly store; anomalies returned inline as value objects. Caller decides what to do (log, alert, abort).

## Service provider

`ObservabilityServiceProvider` in `Waaseyaa\AI\Observability\`:

**`register()`**:
- Bind `TraceRecorderInterface` to `TraceRecorder` (or `NullTraceRecorder` when disabled).
- Bind `TokenAccountant`, `CostTracker`, `BudgetManager`, `AnomalyDetector`, `OutcomeTracker` as singletons.
- Register `Trace` `EntityType` with `EntityTypeManager`.
- Bind `ModelPricing` registry (seeded from config).

**`boot()`**:
- Register event subscribers (`LlmCallListener`, `ToolCallListener`) via `EventDispatcherInterface`.

## Testing

**Unit tests** (`packages/ai-observability/tests/Unit/`):
- Value-object construction + invariants.
- `TokenAccountant`: pricing math for known/unknown models, cached-token discount.
- `BudgetManager`: allow/warn/deny thresholds at boundaries.
- `AnomalyDetector`: each heuristic with synthetic inputs.
- `NullTraceRecorder`: every method is a no-op.

**Contract test** (`packages/ai-observability/tests/Contract/`):
- `TraceRecorderContractTest` (abstract, `#[CoversNothing]`). Round-trips: start trace → spans → decision → outcome → complete. Query back asserts shape. Concrete subclass runs against real `TraceRecorder` with `DBALDatabase::createSqlite()`.

**Integration tests** (`tests/Integration/AIObservability/`):
- Full flow with real SQLite: start trace → record multi-span workload → cost totals → anomaly detection.
- Disabled mode: `observability.enabled=false` → no DB writes occur (inspect schema).
- Event wiring: dispatch `LlmCallCompleted` through a real EventDispatcher → assert span appears on active trace.

## Out of scope (follow-up issues)

Tracked as separate GitHub issues after this package lands:

- `MetricsCollector` — success rates, latency percentiles, quality scores.
- `PerformanceDashboard` — query API for dashboards.
- `ErrorTaxonomy` — classified error recording.
- `ObservabilityExporter` — OpenTelemetry + Langfuse exporters.
- `IntrospectionApi` — external agent-performance query API.

## Open questions

None outstanding. Design approved 2026-04-14.
