# Codified Context — Telescope Integration

## Architecture Overview

The codified context subsystem instruments AI-assisted coding sessions to detect
context drift: the divergence between the project's codified knowledge (CLAUDE.md,
specs, skills) and what the agent actually used during a session.

```
CodifiedContextObserver          (entry point — attach to your session loop)
        │
        ▼
CodifiedContextSessionRecorder   (opens/closes sessions)
CodifiedContextEventRecorder     (records individual events)
CodifiedContextValidationRecorder (stores validation results)
        │
        ▼
CodifiedContextStoreInterface    (storage abstraction)
        │
        ├── JsonlCodifiedContextAdapter   (append-only JSONL log)
        ├── SqliteCodifiedContextAdapter  (queryable SQLite store)
        └── PrometheusCodifiedContextAdapter (metrics push-gateway)
```

Validation runs asynchronously after a session ends:

```
DriftScorer
  ├── EmbeddingValidator      (semantic alignment, 0-60 pts)
  ├── StructuralValidator     (CLAUDE.md rules adherence, 0-20 pts)
  └── ContradictionDetector   (conflicting instructions, 0-20 pts)
```

The final `driftScore` (0–100) is stored alongside the session and rendered
by the SSR template at `packages/ssr/templates/telescope/codified-context-session.html.twig`.

---

## Instrumenting Sessions

### 1. Wire the observer via `TelescopeServiceProvider`

```php
$provider = new TelescopeServiceProvider(config: [
    'enabled' => true,
    'record'  => ['codified_context' => true],
]);

$observer = $provider->getCodifiedContextObserver();
```

`getCodifiedContextObserver()` returns `null` when telescope is disabled or
`record.codified_context` is `false` — callers should always null-check.

### 2. Wrap a session

```php
$observer?->sessionStarted(
    sessionId: $sessionId,
    repoHash:  $repoHash,
);

// … session work happens here …

$observer?->specLoaded(specName: 'entity-system', tier: 3);
$observer?->skillActivated(skillName: 'waaseyaa:access-control');
$observer?->codeGenerated(fileCount: 3, linesAdded: 120);

$observer?->sessionEnded(sessionId: $sessionId);
```

### 3. Trigger validation

Validation is CPU-intensive and should run out-of-band (queue job or CLI):

```bash
bin/waaseyaa telescope:codified-context:validate <session-id>
```

Or via the API:

```
POST /api/telescope/codified-context/sessions/{id}/validate
```

---

## Interpreting Drift Scores

| Score | Band | Colour | Meaning |
|-------|------|--------|---------|
| 75–100 | Green | `#22c55e` | Well-aligned — context served the session accurately |
| 50–74 | Yellow | `#eab308` | Moderate drift — some specs may be stale |
| 25–49 | Orange | `#f97316` | Significant drift — update affected specs |
| 0–24 | Red | `#ef4444` | Severe drift — context is actively misleading |

### Score components

**Semantic Alignment (0–60 pts)** — embedding cosine similarity between the
codified context loaded and the code actually generated. Low scores indicate
the agent drew heavily on out-of-context knowledge.

**Structural Checks (0–20 pts)** — verifies CLAUDE.md rules were followed:
layer discipline, naming conventions, testing patterns, architecture gotchas.

**Contradiction Checks (0–20 pts)** — detects contradictory instructions
across tiers (e.g., spec says one thing, CLAUDE.md says another). Uses
heuristic keyword matching; not exhaustive.

### Issue severities

| Severity | Action |
|----------|--------|
| `critical` | Update the spec immediately before next session |
| `warning` | Schedule spec refresh within the milestone |
| `info` | Low-priority note; no immediate action required |

---

## Adapter Configuration

All adapters are configured via the telescope config array passed to
`TelescopeServiceProvider`:

### JSONL adapter

```php
'codified_context' => [
    'adapter' => 'jsonl',
    'path'    => storage_path('telescope/codified-context.jsonl'),
],
```

Append-only, line-delimited JSON. Simple, no dependencies. Ideal for
log-shipping pipelines (Loki, Datadog, CloudWatch). Not suitable for
range queries without a pre-processing step.

### SQLite adapter

```php
'codified_context' => [
    'adapter'  => 'sqlite',
    'database' => storage_path('telescope/codified-context.sqlite'),
],
```

Queryable via standard SQL. Supports per-session lookups, drift score
histograms, and event timeline queries. Single-writer only — safe for
single-server deployments.

### Prometheus adapter

```php
'codified_context' => [
    'adapter'  => 'prometheus',
    'endpoint' => 'http://pushgateway:9091/metrics/job/waaseyaa',
    'job'      => 'codified_context',
],
```

Pushes gauge metrics to a Prometheus push-gateway after each session:
- `waaseyaa_cc_drift_score` — per-session drift score
- `waaseyaa_cc_semantic_alignment` — semantic alignment component
- `waaseyaa_cc_structural_checks` — structural check component
- `waaseyaa_cc_contradiction_checks` — contradiction check component

Metrics are labelled with `repo_hash` and `session_id`.

---

## Extending with Custom Embedding Providers

`EmbeddingValidator` delegates embedding generation to an `EmbeddingProviderInterface`:

```php
interface EmbeddingProviderInterface
{
    /** @return float[] */
    public function embed(string $text): array;
}
```

Register a custom provider by passing it to `EmbeddingValidator`:

```php
use Waaseyaa\Telescope\CodifiedContext\Validator\EmbeddingValidator;

$validator = new EmbeddingValidator(
    provider: new MyOpenAiEmbeddingProvider(model: 'text-embedding-3-small'),
);
```

The default provider uses a local TF-IDF bag-of-words approximation — zero
external dependencies but lower accuracy. For production use, wire a real
embedding model.
