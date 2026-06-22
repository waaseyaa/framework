# ai-observability Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `waaseyaa/ai-observability` package (MVP + Cost scope) implementing trace recording, cost tracking, outcome logging, and anomaly detection per the approved spec.

**Architecture:** Single package at layer 5 (AI), zero dependencies on other AI packages. `Trace` is an entity (full entity system). `trace_span` is a supporting table (DBAL direct writes). `TraceRecorder` is the explicit API; event listeners capture ai-agent LLM/tool calls. Disabled mode returns no-op handles.

**Tech Stack:** PHP 8.4, Symfony EventDispatcher, Doctrine DBAL, PHPUnit 10.5, Waaseyaa Foundation/Entity/EntityStorage.

**Spec:** `docs/history/superpowers/specs/2026-04-14-ai-observability-design.md`
**Issue:** waaseyaa/framework#622

---

## File Structure

```
packages/ai-observability/
├── composer.json
├── README.md
├── migrations/
│   └── 2026_04_14_000001_create_observability_tables.php
├── src/
│   ├── ObservabilityServiceProvider.php
│   ├── Trace.php                              # entity
│   ├── TraceContext.php                       # per-request handle registry
│   ├── Recorder/
│   │   ├── TraceRecorderInterface.php
│   │   ├── TraceRecorder.php
│   │   └── NullTraceRecorder.php
│   ├── Handle/
│   │   ├── TraceHandle.php
│   │   └── SpanHandle.php
│   ├── Value/
│   │   ├── DecisionTrace.php
│   │   ├── Outcome.php
│   │   ├── CostRecord.php
│   │   ├── BudgetDecision.php                 # enum
│   │   └── Anomaly.php
│   ├── Cost/
│   │   ├── ModelPricing.php
│   │   ├── TokenAccountant.php
│   │   ├── CostTracker.php
│   │   └── BudgetManager.php
│   ├── Outcome/
│   │   └── OutcomeTracker.php
│   ├── Analysis/
│   │   └── AnomalyDetector.php
│   └── Listener/
│       ├── LlmCallListener.php
│       └── ToolCallListener.php
└── tests/
    ├── Unit/
    │   ├── Handle/TraceHandleTest.php
    │   ├── Value/DecisionTraceTest.php
    │   ├── Value/OutcomeTest.php
    │   ├── Cost/ModelPricingTest.php
    │   ├── Cost/TokenAccountantTest.php
    │   ├── Cost/BudgetManagerTest.php
    │   ├── Analysis/AnomalyDetectorTest.php
    │   └── Recorder/NullTraceRecorderTest.php
    ├── Contract/
    │   └── TraceRecorderContractTest.php
    └── Integration/
        ├── TraceRecorderSqliteTest.php
        ├── DisabledModeTest.php
        └── EventWiringTest.php
```

---

### Task 1: Package Scaffold

**Files:**
- Create: `packages/ai-observability/composer.json`
- Create: `packages/ai-observability/README.md`
- Create: `packages/ai-observability/src/.gitkeep`
- Create: `packages/ai-observability/tests/.gitkeep`
- Modify: `composer.json` (root) — add path repo + `@dev` requirement

- [ ] **Step 1: Create `packages/ai-observability/composer.json`**

```json
{
    "name": "waaseyaa/ai-observability",
    "description": "Trace recording, cost tracking, and anomaly detection for Waaseyaa agentic framework",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "repositories": [
        {"type": "path", "url": "../foundation"},
        {"type": "path", "url": "../entity"},
        {"type": "path", "url": "../entity-storage"},
        {"type": "path", "url": "../database-legacy"}
    ],
    "require": {
        "php": ">=8.4",
        "waaseyaa/entity": "^0.1",
        "waaseyaa/entity-storage": "^0.1",
        "waaseyaa/database-legacy": "^0.1",
        "waaseyaa/foundation": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {"Waaseyaa\\AI\\Observability\\": "src/"}
    },
    "autoload-dev": {
        "psr-4": {"Waaseyaa\\AI\\Observability\\Tests\\": "tests/"}
    },
    "extra": {
        "waaseyaa": {
            "providers": ["Waaseyaa\\AI\\Observability\\ObservabilityServiceProvider"]
        },
        "branch-alias": {
            "dev-main": "0.1.x-dev"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {"sort-packages": true}
}
```

- [ ] **Step 2: Create `packages/ai-observability/README.md`**

```markdown
# waaseyaa/ai-observability

Trace recording, cost tracking, budget enforcement, outcome logging, and anomaly detection for the Waaseyaa agentic framework.

Layer 5 (AI). Zero dependencies on other AI packages.

See `docs/specs/ai-observability.md` (after this package lands) or the design at `docs/history/superpowers/specs/2026-04-14-ai-observability-design.md`.
```

- [ ] **Step 3: Create placeholder dirs**

```bash
mkdir -p packages/ai-observability/src packages/ai-observability/tests packages/ai-observability/migrations
touch packages/ai-observability/src/.gitkeep packages/ai-observability/tests/.gitkeep
```

- [ ] **Step 4: Register in root `composer.json`**

Add to `repositories` array:
```json
{"type": "path", "url": "packages/ai-observability"}
```

Add to `require`:
```json
"waaseyaa/ai-observability": "@dev"
```

Keep the array sorted alphabetically to satisfy `composer check-composer-policy`.

- [ ] **Step 5: Run composer update and policy check**

```bash
composer update waaseyaa/ai-observability --no-scripts
composer check-composer-policy
```

Expected: update succeeds, policy check reports OK.

- [ ] **Step 6: Commit**

```bash
git add packages/ai-observability composer.json composer.lock
git commit -m "feat(#622): scaffold ai-observability package"
```

---

### Task 2: Value Objects — Handles

**Files:**
- Create: `packages/ai-observability/src/Handle/TraceHandle.php`
- Create: `packages/ai-observability/src/Handle/SpanHandle.php`
- Create: `packages/ai-observability/tests/Unit/Handle/TraceHandleTest.php`

- [ ] **Step 1: Write failing test**

`packages/ai-observability/tests/Unit/Handle/TraceHandleTest.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Handle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Handle\TraceHandle;

#[CoversClass(TraceHandle::class)]
final class TraceHandleTest extends TestCase
{
    #[Test]
    public function it_holds_uuid_and_started_at(): void
    {
        $startedAt = new \DateTimeImmutable('2026-04-14T12:00:00Z');
        $handle = new TraceHandle('abc-123', $startedAt);

        self::assertSame('abc-123', $handle->uuid);
        self::assertSame($startedAt, $handle->startedAt);
    }
}
```

- [ ] **Step 2: Verify it fails**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Handle/TraceHandleTest.php
```
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement `TraceHandle`**

`packages/ai-observability/src/Handle/TraceHandle.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Handle;

/**
 * Opaque handle returned by TraceRecorder::startTrace().
 * Passed back to complete the trace or open child spans.
 */
final readonly class TraceHandle
{
    public function __construct(
        public string $uuid,
        public \DateTimeImmutable $startedAt,
    ) {}
}
```

- [ ] **Step 4: Implement `SpanHandle` (no separate test — covered by contract test)**

`packages/ai-observability/src/Handle/SpanHandle.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Handle;

final readonly class SpanHandle
{
    public function __construct(
        public string $uuid,
        public string $traceUuid,
        public string $kind,
        public \DateTimeImmutable $startedAt,
        public ?string $parentSpanUuid = null,
    ) {}
}
```

- [ ] **Step 5: Run test — should pass**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Handle/TraceHandleTest.php
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add packages/ai-observability/src/Handle packages/ai-observability/tests/Unit/Handle
git commit -m "feat(#622): add TraceHandle and SpanHandle value objects"
```

---

### Task 3: Value Objects — DecisionTrace, Outcome, CostRecord, BudgetDecision, Anomaly

**Files:**
- Create: `packages/ai-observability/src/Value/DecisionTrace.php`
- Create: `packages/ai-observability/src/Value/Outcome.php`
- Create: `packages/ai-observability/src/Value/CostRecord.php`
- Create: `packages/ai-observability/src/Value/BudgetDecision.php`
- Create: `packages/ai-observability/src/Value/Anomaly.php`
- Create: `packages/ai-observability/tests/Unit/Value/DecisionTraceTest.php`
- Create: `packages/ai-observability/tests/Unit/Value/OutcomeTest.php`

- [ ] **Step 1: Write `DecisionTraceTest`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Value\DecisionTrace;

#[CoversClass(DecisionTrace::class)]
final class DecisionTraceTest extends TestCase
{
    #[Test]
    public function it_holds_decision_fields(): void
    {
        $d = new DecisionTrace(
            question: 'which model?',
            chosen: 'claude-opus-4-6',
            alternatives: ['gpt-4o', 'claude-sonnet-4-6'],
            reasoning: 'needs deep reasoning',
            confidence: 0.9,
        );

        self::assertSame('which model?', $d->question);
        self::assertSame('claude-opus-4-6', $d->chosen);
        self::assertSame(['gpt-4o', 'claude-sonnet-4-6'], $d->alternatives);
        self::assertSame(0.9, $d->confidence);
    }

    #[Test]
    public function confidence_must_be_between_zero_and_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DecisionTrace('q', 'a', [], 'r', 1.5);
    }
}
```

- [ ] **Step 2: Write `OutcomeTest`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Value\Outcome;

#[CoversClass(Outcome::class)]
final class OutcomeTest extends TestCase
{
    #[Test]
    public function it_holds_status_and_feedback(): void
    {
        $o = new Outcome('accepted', 'LGTM', ['reviewer' => 'russ']);
        self::assertSame('accepted', $o->status);
        self::assertSame('LGTM', $o->feedback);
        self::assertSame(['reviewer' => 'russ'], $o->metadata);
    }

    #[Test]
    public function status_must_be_known_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Outcome('bogus');
    }
}
```

- [ ] **Step 3: Run tests — expect FAIL**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Value/
```
Expected: class not found errors.

- [ ] **Step 4: Implement `DecisionTrace`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Value;

final readonly class DecisionTrace
{
    /**
     * @param string[] $alternatives
     */
    public function __construct(
        public string $question,
        public string $chosen,
        public array $alternatives,
        public string $reasoning,
        public float $confidence,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException(
                'DecisionTrace confidence must be in [0.0, 1.0], got '.$confidence
            );
        }
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'question' => $this->question,
            'chosen' => $this->chosen,
            'alternatives' => $this->alternatives,
            'reasoning' => $this->reasoning,
            'confidence' => $this->confidence,
        ];
    }
}
```

- [ ] **Step 5: Implement `Outcome`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Value;

final readonly class Outcome
{
    public const STATUSES = ['accepted', 'rejected', 'modified'];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $status,
        public ?string $feedback = null,
        public array $metadata = [],
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(
                'Outcome status must be one of '.implode(', ', self::STATUSES).', got '.$status
            );
        }
    }
}
```

- [ ] **Step 6: Implement `CostRecord`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Value;

final readonly class CostRecord
{
    public function __construct(
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedTokens,
        public float $costUsd,
    ) {}
}
```

- [ ] **Step 7: Implement `BudgetDecision` enum**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Value;

enum BudgetDecision: string
{
    case ALLOW = 'allow';
    case WARN = 'warn';
    case DENY = 'deny';
}
```

- [ ] **Step 8: Implement `Anomaly`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Value;

final readonly class Anomaly
{
    public const KIND_SPAN_COUNT = 'span_count_outlier';
    public const KIND_COST = 'cost_outlier';
    public const KIND_TOOL_LOOP = 'tool_loop';
    public const KIND_ERROR_RATIO = 'high_error_ratio';

    /**
     * @param array<string, mixed> $evidence
     */
    public function __construct(
        public string $kind,
        public string $description,
        public array $evidence = [],
    ) {}
}
```

- [ ] **Step 9: Run tests — expect PASS**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Value/
```
Expected: all pass.

- [ ] **Step 10: Commit**

```bash
git add packages/ai-observability/src/Value packages/ai-observability/tests/Unit/Value
git commit -m "feat(#622): add observability value objects (DecisionTrace, Outcome, CostRecord, BudgetDecision, Anomaly)"
```

---

### Task 4: Trace Entity

**Files:**
- Create: `packages/ai-observability/src/Trace.php`

The `Trace` entity extends `EntityBase` following the pattern in `packages/entity/src/EntityBase.php`. Constructor signature is `(array $values)`, entity type id hardcoded to `'trace'`, keys hardcoded. Non-schema values go into `_data` blob automatically.

- [ ] **Step 1: Implement `Trace`**

`packages/ai-observability/src/Trace.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability;

use Waaseyaa\Entity\EntityBase;

/**
 * Trace entity: one full agent execution.
 *
 * Spans (tool calls, LLM calls, decisions) live in the `trace_span`
 * supporting table, keyed by trace_uuid. See ObservabilityServiceProvider
 * and migrations.
 */
final class Trace extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct(
            $values,
            'trace',
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/ai-observability/src/Trace.php
git commit -m "feat(#622): add Trace entity"
```

(Entity test comes in the integration test — entity semantics are already covered by entity-storage contract tests.)

---

### Task 5: Schema Migration

**Files:**
- Create: `packages/ai-observability/migrations/2026_04_14_000001_create_observability_tables.php`

The migration creates both the `trace` table (entity-owned schema) and the `trace_span` supporting table. Follow the pattern in other packages that ship migrations (`packages/node/migrations/` or `packages/entity-storage/migrations/` — whichever exists with the closest shape). The engineer should consult one before writing this task.

- [ ] **Step 1: Inspect an existing migration for pattern reference**

```bash
ls packages/*/migrations/ 2>/dev/null | head -20
```

Pick the newest and read it end-to-end to match the migration class shape. If no migrations exist in the repo yet, use `waaseyaa migrate:make` (if available) or consult `packages/foundation/src/Migration/` for the base class interface.

- [ ] **Step 2: Write the migration**

`packages/ai-observability/migrations/2026_04_14_000001_create_observability_tables.php`:
```php
<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Waaseyaa\Foundation\Migration\Migration;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $trace = $schema->createTable('trace');
        $trace->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
        $trace->addColumn('uuid', 'string', ['length' => 36]);
        $trace->addColumn('label', 'string', ['length' => 255]);
        $trace->addColumn('status', 'string', ['length' => 32, 'default' => 'running']);
        $trace->addColumn('started_at', 'datetime');
        $trace->addColumn('ended_at', 'datetime', ['notnull' => false]);
        $trace->addColumn('outcome_status', 'string', ['length' => 32, 'notnull' => false]);
        $trace->addColumn('outcome_feedback', 'text', ['notnull' => false]);
        $trace->addColumn('_data', 'text', ['notnull' => false]);
        $trace->setPrimaryKey(['id']);
        $trace->addUniqueIndex(['uuid']);
        $trace->addIndex(['label']);
        $trace->addIndex(['status']);
        $trace->addIndex(['started_at']);

        $span = $schema->createTable('trace_span');
        $span->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
        $span->addColumn('uuid', 'string', ['length' => 36]);
        $span->addColumn('trace_uuid', 'string', ['length' => 36]);
        $span->addColumn('parent_span_uuid', 'string', ['length' => 36, 'notnull' => false]);
        $span->addColumn('kind', 'string', ['length' => 32]);
        $span->addColumn('name', 'string', ['length' => 255]);
        $span->addColumn('started_at', 'datetime');
        $span->addColumn('ended_at', 'datetime', ['notnull' => false]);
        $span->addColumn('status', 'string', ['length' => 32, 'default' => 'ok']);
        $span->addColumn('attributes', 'text', ['notnull' => false]);
        $span->setPrimaryKey(['id']);
        $span->addUniqueIndex(['uuid']);
        $span->addIndex(['trace_uuid']);
        $span->addIndex(['trace_uuid', 'kind']);
        $span->addIndex(['kind']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('trace_span');
        $schema->dropTable('trace');
    }
};
```

If the migration base class signature differs from the above (e.g. raw SQL or query-builder), adjust to the repo convention. The column structure must match the spec table exactly.

- [ ] **Step 3: Run migrations on a scratch SQLite to verify**

```bash
WAASEYAA_DB=/tmp/waaseyaa_obs_scratch.sqlite bin/waaseyaa migrate
rm -f /tmp/waaseyaa_obs_scratch.sqlite
```

Expected: migration runs without error.

- [ ] **Step 4: Commit**

```bash
git add packages/ai-observability/migrations
git commit -m "feat(#622): add trace + trace_span schema migration"
```

---

### Task 6: TraceContext

**Files:**
- Create: `packages/ai-observability/src/TraceContext.php`
- Create: `packages/ai-observability/tests/Unit/TraceContextTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\TraceContext;

#[CoversClass(TraceContext::class)]
final class TraceContextTest extends TestCase
{
    #[Test]
    public function registers_and_retrieves_handle(): void
    {
        $ctx = new TraceContext();
        $handle = new TraceHandle('t-1', new \DateTimeImmutable());
        $ctx->register($handle);

        self::assertSame($handle, $ctx->get('t-1'));
    }

    #[Test]
    public function returns_null_for_unknown_uuid(): void
    {
        $ctx = new TraceContext();
        self::assertNull($ctx->get('nope'));
    }

    #[Test]
    public function clear_removes_handle(): void
    {
        $ctx = new TraceContext();
        $handle = new TraceHandle('t-1', new \DateTimeImmutable());
        $ctx->register($handle);
        $ctx->clear('t-1');
        self::assertNull($ctx->get('t-1'));
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability;

use Waaseyaa\AI\Observability\Handle\TraceHandle;

/**
 * Per-request registry of active traces, keyed by uuid.
 * Event listeners consult this to correlate an event with its trace.
 */
final class TraceContext
{
    /** @var array<string, TraceHandle> */
    private array $handles = [];

    public function register(TraceHandle $handle): void
    {
        $this->handles[$handle->uuid] = $handle;
    }

    public function get(string $uuid): ?TraceHandle
    {
        return $this->handles[$uuid] ?? null;
    }

    public function clear(string $uuid): void
    {
        unset($this->handles[$uuid]);
    }
}
```

- [ ] **Step 3: Run — expect PASS**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/TraceContextTest.php
```

- [ ] **Step 4: Commit**

```bash
git add packages/ai-observability/src/TraceContext.php packages/ai-observability/tests/Unit/TraceContextTest.php
git commit -m "feat(#622): add TraceContext active-handle registry"
```

---

### Task 7: TraceRecorderInterface + TraceRecorder

**Files:**
- Create: `packages/ai-observability/src/Recorder/TraceRecorderInterface.php`
- Create: `packages/ai-observability/src/Recorder/TraceRecorder.php`

No standalone unit test — `TraceRecorder` is covered by the contract test (Task 13) against real SQLite. The contract test is the right layer because recorder behavior is inseparable from its storage side-effects.

- [ ] **Step 1: Interface**

`packages/ai-observability/src/Recorder/TraceRecorderInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Recorder;

use Waaseyaa\AI\Observability\Handle\SpanHandle;
use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\Value\DecisionTrace;
use Waaseyaa\AI\Observability\Value\Outcome;

interface TraceRecorderInterface
{
    /** @param array<string, mixed> $attributes */
    public function startTrace(string $label, array $attributes = []): TraceHandle;

    public function completeTrace(TraceHandle $handle, string $status = 'ok'): void;

    public function span(TraceHandle $handle, string $kind, string $name, ?SpanHandle $parent = null): SpanHandle;

    /** @param array<string, mixed> $attributes */
    public function endSpan(SpanHandle $handle, array $attributes = [], string $status = 'ok'): void;

    public function recordDecision(TraceHandle $handle, DecisionTrace $decision): void;

    public function recordOutcome(TraceHandle $handle, Outcome $outcome): void;
}
```

- [ ] **Step 2: Implementation**

`packages/ai-observability/src/Recorder/TraceRecorder.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Recorder;

use Symfony\Component\Uid\Uuid;
use Waaseyaa\AI\Observability\Handle\SpanHandle;
use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\Trace;
use Waaseyaa\AI\Observability\TraceContext;
use Waaseyaa\AI\Observability\Value\DecisionTrace;
use Waaseyaa\AI\Observability\Value\Outcome;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityRepository;

final class TraceRecorder implements TraceRecorderInterface
{
    public function __construct(
        private readonly EntityRepository $traces,
        private readonly DatabaseInterface $database,
        private readonly TraceContext $context,
    ) {}

    public function startTrace(string $label, array $attributes = []): TraceHandle
    {
        $uuid = Uuid::v4()->toRfc4122();
        $startedAt = new \DateTimeImmutable();

        $trace = new Trace([
            'uuid' => $uuid,
            'label' => $label,
            'status' => 'running',
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'attributes' => $attributes,
        ]);
        $trace->enforceIsNew();
        $this->traces->save($trace);

        $handle = new TraceHandle($uuid, $startedAt);
        $this->context->register($handle);
        return $handle;
    }

    public function completeTrace(TraceHandle $handle, string $status = 'ok'): void
    {
        $trace = $this->findTrace($handle->uuid);
        if ($trace === null) {
            return;
        }
        $trace->set('status', $status);
        $trace->set('ended_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->traces->save($trace);
        $this->context->clear($handle->uuid);
    }

    public function span(TraceHandle $handle, string $kind, string $name, ?SpanHandle $parent = null): SpanHandle
    {
        $spanUuid = Uuid::v4()->toRfc4122();
        $startedAt = new \DateTimeImmutable();

        $this->database->insert('trace_span', [
            'uuid' => $spanUuid,
            'trace_uuid' => $handle->uuid,
            'parent_span_uuid' => $parent?->uuid,
            'kind' => $kind,
            'name' => $name,
            'started_at' => $startedAt->format('Y-m-d H:i:s.u'),
            'status' => 'ok',
            'attributes' => null,
        ]);

        return new SpanHandle($spanUuid, $handle->uuid, $kind, $startedAt, $parent?->uuid);
    }

    public function endSpan(SpanHandle $handle, array $attributes = [], string $status = 'ok'): void
    {
        $this->database->update(
            'trace_span',
            [
                'ended_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'status' => $status,
                'attributes' => json_encode($attributes, JSON_THROW_ON_ERROR),
            ],
            ['uuid' => $handle->uuid],
        );
    }

    public function recordDecision(TraceHandle $handle, DecisionTrace $decision): void
    {
        $span = $this->span($handle, 'decision', 'decision');
        $this->endSpan($span, $decision->toAttributes());
    }

    public function recordOutcome(TraceHandle $handle, Outcome $outcome): void
    {
        $trace = $this->findTrace($handle->uuid);
        if ($trace === null) {
            return;
        }
        $trace->set('outcome_status', $outcome->status);
        $trace->set('outcome_feedback', $outcome->feedback);
        $trace->set('outcome_metadata', $outcome->metadata);
        $this->traces->save($trace);
    }

    private function findTrace(string $uuid): ?Trace
    {
        $results = $this->traces->findBy(['uuid' => $uuid], limit: 1);
        return $results[0] ?? null;
    }
}
```

Note: if `EntityRepository::findBy` signature in this repo differs (named `limit` param vs. positional), adjust to match. The recorder is fully validated by the contract test.

- [ ] **Step 3: Commit**

```bash
git add packages/ai-observability/src/Recorder
git commit -m "feat(#622): add TraceRecorder interface and implementation"
```

---

### Task 8: NullTraceRecorder + Disabled Mode

**Files:**
- Create: `packages/ai-observability/src/Recorder/NullTraceRecorder.php`
- Create: `packages/ai-observability/tests/Unit/Recorder/NullTraceRecorderTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Recorder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Recorder\NullTraceRecorder;
use Waaseyaa\AI\Observability\Value\DecisionTrace;
use Waaseyaa\AI\Observability\Value\Outcome;

#[CoversClass(NullTraceRecorder::class)]
final class NullTraceRecorderTest extends TestCase
{
    #[Test]
    public function all_operations_are_noops(): void
    {
        $r = new NullTraceRecorder();
        $trace = $r->startTrace('test');
        $span = $r->span($trace, 'tool_call', 'foo');
        $r->endSpan($span, ['x' => 1]);
        $r->recordDecision($trace, new DecisionTrace('q', 'a', [], 'r', 0.5));
        $r->recordOutcome($trace, new Outcome('accepted'));
        $r->completeTrace($trace);

        self::assertSame('disabled', $trace->uuid);
        self::assertSame('disabled', $span->uuid);
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Recorder;

use Waaseyaa\AI\Observability\Handle\SpanHandle;
use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\Value\DecisionTrace;
use Waaseyaa\AI\Observability\Value\Outcome;

final class NullTraceRecorder implements TraceRecorderInterface
{
    public function startTrace(string $label, array $attributes = []): TraceHandle
    {
        return new TraceHandle('disabled', new \DateTimeImmutable());
    }

    public function completeTrace(TraceHandle $handle, string $status = 'ok'): void {}

    public function span(TraceHandle $handle, string $kind, string $name, ?SpanHandle $parent = null): SpanHandle
    {
        return new SpanHandle('disabled', $handle->uuid, $kind, new \DateTimeImmutable(), $parent?->uuid);
    }

    public function endSpan(SpanHandle $handle, array $attributes = [], string $status = 'ok'): void {}

    public function recordDecision(TraceHandle $handle, DecisionTrace $decision): void {}

    public function recordOutcome(TraceHandle $handle, Outcome $outcome): void {}
}
```

- [ ] **Step 3: Run — expect PASS**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Recorder/NullTraceRecorderTest.php
```

- [ ] **Step 4: Commit**

```bash
git add packages/ai-observability/src/Recorder/NullTraceRecorder.php packages/ai-observability/tests/Unit/Recorder
git commit -m "feat(#622): add NullTraceRecorder for disabled mode"
```

---

### Task 9: ModelPricing + TokenAccountant

**Files:**
- Create: `packages/ai-observability/src/Cost/ModelPricing.php`
- Create: `packages/ai-observability/src/Cost/TokenAccountant.php`
- Create: `packages/ai-observability/tests/Unit/Cost/ModelPricingTest.php`
- Create: `packages/ai-observability/tests/Unit/Cost/TokenAccountantTest.php`

- [ ] **Step 1: `ModelPricingTest`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Cost;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Cost\ModelPricing;

#[CoversClass(ModelPricing::class)]
final class ModelPricingTest extends TestCase
{
    #[Test]
    public function defaults_include_claude_opus_4_6(): void
    {
        $pricing = new ModelPricing();
        $rates = $pricing->forModel('claude-opus-4-6');

        self::assertGreaterThan(0.0, $rates['input']);
        self::assertGreaterThan(0.0, $rates['output']);
    }

    #[Test]
    public function unknown_model_returns_zero_rates(): void
    {
        $pricing = new ModelPricing();
        $rates = $pricing->forModel('nonexistent-xyz');

        self::assertSame(0.0, $rates['input']);
        self::assertSame(0.0, $rates['output']);
        self::assertSame(0.0, $rates['cached']);
    }

    #[Test]
    public function custom_pricing_overrides_defaults(): void
    {
        $pricing = new ModelPricing(['my-model' => ['input' => 2.0, 'output' => 8.0, 'cached' => 0.2]]);
        $rates = $pricing->forModel('my-model');

        self::assertSame(2.0, $rates['input']);
    }
}
```

- [ ] **Step 2: `TokenAccountantTest`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Cost;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Cost\ModelPricing;
use Waaseyaa\AI\Observability\Cost\TokenAccountant;
use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\Recorder\NullTraceRecorder;

#[CoversClass(TokenAccountant::class)]
final class TokenAccountantTest extends TestCase
{
    #[Test]
    public function computes_cost_from_pricing(): void
    {
        $pricing = new ModelPricing(['m' => ['input' => 3.0, 'output' => 15.0, 'cached' => 0.3]]);
        $accountant = new TokenAccountant(new NullTraceRecorder(), $pricing);
        $handle = new TraceHandle('t', new \DateTimeImmutable());

        $record = $accountant->record($handle, 'm', inputTokens: 1_000_000, outputTokens: 1_000_000, cachedTokens: 1_000_000);

        self::assertSame('m', $record->model);
        self::assertSame(18.3, $record->costUsd); // 3 + 15 + 0.3 per 1M
    }

    #[Test]
    public function unknown_model_yields_zero_cost(): void
    {
        $accountant = new TokenAccountant(new NullTraceRecorder(), new ModelPricing());
        $handle = new TraceHandle('t', new \DateTimeImmutable());

        $record = $accountant->record($handle, 'bogus', 100, 100);

        self::assertSame(0.0, $record->costUsd);
    }
}
```

- [ ] **Step 3: Run — expect FAIL**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Cost/
```

- [ ] **Step 4: Implement `ModelPricing`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Cost;

/**
 * Per-model USD rates per 1,000,000 tokens.
 * Callers can override defaults via constructor.
 */
final class ModelPricing
{
    /** @var array<string, array{input: float, output: float, cached: float}> */
    private array $rates;

    /** @param array<string, array{input: float, output: float, cached: float}> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->rates = array_replace(self::defaults(), $overrides);
    }

    /** @return array{input: float, output: float, cached: float} */
    public function forModel(string $model): array
    {
        return $this->rates[$model] ?? ['input' => 0.0, 'output' => 0.0, 'cached' => 0.0];
    }

    /** @return array<string, array{input: float, output: float, cached: float}> */
    private static function defaults(): array
    {
        return [
            'claude-opus-4-6'     => ['input' => 15.00, 'output' => 75.00, 'cached' => 1.50],
            'claude-sonnet-4-6'   => ['input' =>  3.00, 'output' => 15.00, 'cached' => 0.30],
            'claude-haiku-4-5'    => ['input' =>  1.00, 'output' =>  5.00, 'cached' => 0.10],
            'gpt-4o'              => ['input' =>  2.50, 'output' => 10.00, 'cached' => 1.25],
            'gpt-4o-mini'         => ['input' =>  0.15, 'output' =>  0.60, 'cached' => 0.075],
        ];
    }
}
```

- [ ] **Step 5: Implement `TokenAccountant`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Cost;

use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
use Waaseyaa\AI\Observability\Value\CostRecord;

final class TokenAccountant
{
    public function __construct(
        private readonly TraceRecorderInterface $recorder,
        private readonly ModelPricing $pricing,
    ) {}

    public function record(
        TraceHandle $handle,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cachedTokens = 0,
    ): CostRecord {
        $rates = $this->pricing->forModel($model);
        $cost = ($inputTokens * $rates['input']
            + $outputTokens * $rates['output']
            + $cachedTokens * $rates['cached']) / 1_000_000;

        $record = new CostRecord($model, $inputTokens, $outputTokens, $cachedTokens, $cost);

        $span = $this->recorder->span($handle, 'llm_call', $model);
        $this->recorder->endSpan($span, [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cached_tokens' => $cachedTokens,
            'cost_usd' => $cost,
        ]);

        return $record;
    }
}
```

- [ ] **Step 6: Run — expect PASS**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Cost/
```

- [ ] **Step 7: Commit**

```bash
git add packages/ai-observability/src/Cost/ModelPricing.php packages/ai-observability/src/Cost/TokenAccountant.php packages/ai-observability/tests/Unit/Cost
git commit -m "feat(#622): add ModelPricing and TokenAccountant"
```

---

### Task 10: CostTracker

**Files:**
- Create: `packages/ai-observability/src/Cost/CostTracker.php`

Unit-testing `CostTracker` requires a real DB — covered in the integration test (Task 14). No standalone unit test.

- [ ] **Step 1: Implement**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Cost;

use Waaseyaa\Database\DatabaseInterface;

final class CostTracker
{
    public function __construct(private readonly DatabaseInterface $database) {}

    public function totalForTrace(string $traceUuid): float
    {
        $rows = $this->database->select('trace_span')
            ->condition('trace_uuid', $traceUuid)
            ->condition('kind', 'llm_call')
            ->execute()
            ->fetchAllAssociative();

        return $this->sumCostFromRows($rows);
    }

    public function totalForPeriod(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $rows = $this->database->select('trace_span')
            ->condition('kind', 'llm_call')
            ->condition('started_at', $from->format('Y-m-d H:i:s'), '>=')
            ->condition('started_at', $to->format('Y-m-d H:i:s'), '<=')
            ->execute()
            ->fetchAllAssociative();

        return $this->sumCostFromRows($rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sumCostFromRows(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $attrs = isset($row['attributes']) && is_string($row['attributes'])
                ? json_decode($row['attributes'], true, 512, JSON_THROW_ON_ERROR)
                : [];
            $total += (float) ($attrs['cost_usd'] ?? 0.0);
        }
        return $total;
    }
}
```

If the DBAL select API in this repo differs (e.g. uses `->where()` vs `->condition()`), match the existing style from `packages/entity-storage/src/`.

- [ ] **Step 2: Commit**

```bash
git add packages/ai-observability/src/Cost/CostTracker.php
git commit -m "feat(#622): add CostTracker"
```

---

### Task 11: BudgetManager

**Files:**
- Create: `packages/ai-observability/src/Cost/BudgetManager.php`
- Create: `packages/ai-observability/tests/Unit/Cost/BudgetManagerTest.php`

The spec threshold logic:
- `DENY` if `projectedAdditionalUsd > per_request_limit_usd`, OR if `dailyTotal + projectedAdditionalUsd > daily_limit_usd`.
- `WARN` if `dailyTotal + projectedAdditionalUsd > 0.8 * daily_limit_usd` (and not DENY).
- `ALLOW` otherwise.

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Cost;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Cost\BudgetManager;
use Waaseyaa\AI\Observability\Cost\CostTracker;
use Waaseyaa\AI\Observability\Value\BudgetDecision;

#[CoversClass(BudgetManager::class)]
final class BudgetManagerTest extends TestCase
{
    #[Test]
    public function denies_when_per_request_limit_exceeded(): void
    {
        $tracker = $this->fakeTracker(dailyTotal: 0.0);
        $mgr = new BudgetManager($tracker, dailyLimitUsd: 100.0, perRequestLimitUsd: 1.0);

        self::assertSame(BudgetDecision::DENY, $mgr->check(2.0));
    }

    #[Test]
    public function denies_when_daily_limit_exceeded(): void
    {
        $tracker = $this->fakeTracker(dailyTotal: 95.0);
        $mgr = new BudgetManager($tracker, dailyLimitUsd: 100.0, perRequestLimitUsd: 10.0);

        self::assertSame(BudgetDecision::DENY, $mgr->check(8.0));
    }

    #[Test]
    public function warns_at_80_percent(): void
    {
        $tracker = $this->fakeTracker(dailyTotal: 75.0);
        $mgr = new BudgetManager($tracker, dailyLimitUsd: 100.0, perRequestLimitUsd: 10.0);

        self::assertSame(BudgetDecision::WARN, $mgr->check(6.0));
    }

    #[Test]
    public function allows_when_under_thresholds(): void
    {
        $tracker = $this->fakeTracker(dailyTotal: 10.0);
        $mgr = new BudgetManager($tracker, dailyLimitUsd: 100.0, perRequestLimitUsd: 10.0);

        self::assertSame(BudgetDecision::ALLOW, $mgr->check(1.0));
    }

    private function fakeTracker(float $dailyTotal): CostTracker
    {
        return new class($dailyTotal) extends CostTracker {
            public function __construct(private readonly float $total) {}
            public function totalForTrace(string $traceUuid): float { return 0.0; }
            public function totalForPeriod(\DateTimeInterface $from, \DateTimeInterface $to): float { return $this->total; }
        };
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Cost;

use Waaseyaa\AI\Observability\Value\BudgetDecision;

final class BudgetManager
{
    private const WARN_RATIO = 0.8;

    public function __construct(
        private readonly CostTracker $tracker,
        private readonly float $dailyLimitUsd,
        private readonly float $perRequestLimitUsd,
    ) {}

    public function check(float $projectedAdditionalUsd): BudgetDecision
    {
        if ($projectedAdditionalUsd > $this->perRequestLimitUsd) {
            return BudgetDecision::DENY;
        }

        $today = new \DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');
        $dailyTotal = $this->tracker->totalForPeriod($today, $tomorrow);

        $projectedTotal = $dailyTotal + $projectedAdditionalUsd;

        if ($projectedTotal > $this->dailyLimitUsd) {
            return BudgetDecision::DENY;
        }

        if ($projectedTotal > $this->dailyLimitUsd * self::WARN_RATIO) {
            return BudgetDecision::WARN;
        }

        return BudgetDecision::ALLOW;
    }
}
```

- [ ] **Step 3: Run — expect PASS**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Cost/BudgetManagerTest.php
```

- [ ] **Step 4: Commit**

```bash
git add packages/ai-observability/src/Cost/BudgetManager.php packages/ai-observability/tests/Unit/Cost/BudgetManagerTest.php
git commit -m "feat(#622): add BudgetManager with allow/warn/deny thresholds"
```

---

### Task 12: OutcomeTracker

**Files:**
- Create: `packages/ai-observability/src/Outcome/OutcomeTracker.php`

Thin wrapper around `TraceRecorder::recordOutcome()` for explicit, typed use from callers that already have both a handle and an outcome to record. Exists mainly so consumers can inject `OutcomeTracker` where they don't need the full recorder surface.

- [ ] **Step 1: Implement**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Outcome;

use Waaseyaa\AI\Observability\Handle\TraceHandle;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
use Waaseyaa\AI\Observability\Value\Outcome;

final class OutcomeTracker
{
    public function __construct(private readonly TraceRecorderInterface $recorder) {}

    public function record(TraceHandle $handle, Outcome $outcome): void
    {
        $this->recorder->recordOutcome($handle, $outcome);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/ai-observability/src/Outcome/OutcomeTracker.php
git commit -m "feat(#622): add OutcomeTracker"
```

---

### Task 13: AnomalyDetector

**Files:**
- Create: `packages/ai-observability/src/Analysis/AnomalyDetector.php`
- Create: `packages/ai-observability/tests/Unit/Analysis/AnomalyDetectorTest.php`

Heuristics per spec:
1. **Span-count outlier**: > mean + 3σ of same-label traces in last 7 days.
2. **Cost outlier**: > 2 × median cost of same-label traces in last 7 days.
3. **Tool loop**: same tool called > 10 times in one trace.
4. **High error ratio**: ≥ 50% of spans have status=`error`.

Each heuristic is a pure function on (the trace under test, recent-history rows). Unit tests inject synthetic history rather than hitting the DB.

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Analysis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Analysis\AnomalyDetector;
use Waaseyaa\AI\Observability\Value\Anomaly;

#[CoversClass(AnomalyDetector::class)]
final class AnomalyDetectorTest extends TestCase
{
    #[Test]
    public function flags_tool_loop_when_same_tool_called_eleven_times(): void
    {
        $detector = new AnomalyDetector();
        $spans = array_fill(0, 11, ['kind' => 'tool_call', 'name' => 'grep', 'status' => 'ok']);

        $anomalies = $detector->check(
            traceLabel: 'x',
            spans: $spans,
            history: [],
        );

        self::assertContains(Anomaly::KIND_TOOL_LOOP, array_map(fn (Anomaly $a) => $a->kind, $anomalies));
    }

    #[Test]
    public function flags_high_error_ratio(): void
    {
        $detector = new AnomalyDetector();
        $spans = [
            ['kind' => 'tool_call', 'name' => 'a', 'status' => 'error'],
            ['kind' => 'tool_call', 'name' => 'b', 'status' => 'error'],
            ['kind' => 'tool_call', 'name' => 'c', 'status' => 'ok'],
        ];

        $anomalies = $detector->check(traceLabel: 'x', spans: $spans, history: []);

        self::assertContains(Anomaly::KIND_ERROR_RATIO, array_map(fn (Anomaly $a) => $a->kind, $anomalies));
    }

    #[Test]
    public function flags_span_count_outlier(): void
    {
        $detector = new AnomalyDetector();
        // History: same-label traces all had ~5 spans with low variance.
        $history = array_map(fn ($n) => ['span_count' => $n, 'cost_usd' => 0.10], [5, 5, 6, 4, 5, 5, 6, 4, 5, 5]);
        $spans = array_fill(0, 30, ['kind' => 'tool_call', 'name' => 'x', 'status' => 'ok']);

        $anomalies = $detector->check(traceLabel: 'x', spans: $spans, history: $history);

        self::assertContains(Anomaly::KIND_SPAN_COUNT, array_map(fn (Anomaly $a) => $a->kind, $anomalies));
    }

    #[Test]
    public function flags_cost_outlier(): void
    {
        $detector = new AnomalyDetector();
        $history = array_map(fn ($c) => ['span_count' => 5, 'cost_usd' => $c], [0.10, 0.12, 0.09, 0.11, 0.10, 0.12, 0.08, 0.10]);
        $spans = [['kind' => 'llm_call', 'name' => 'x', 'status' => 'ok', 'cost_usd' => 1.00]];

        $anomalies = $detector->check(traceLabel: 'x', spans: $spans, history: $history);

        self::assertContains(Anomaly::KIND_COST, array_map(fn (Anomaly $a) => $a->kind, $anomalies));
    }

    #[Test]
    public function returns_empty_for_unremarkable_trace(): void
    {
        $detector = new AnomalyDetector();
        $history = [['span_count' => 5, 'cost_usd' => 0.10]];
        $spans = [['kind' => 'tool_call', 'name' => 'x', 'status' => 'ok']];

        self::assertSame([], $detector->check('x', $spans, $history));
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Analysis;

use Waaseyaa\AI\Observability\Value\Anomaly;

final class AnomalyDetector
{
    private const TOOL_LOOP_THRESHOLD = 10;
    private const ERROR_RATIO_THRESHOLD = 0.5;
    private const SPAN_COUNT_SIGMA = 3.0;
    private const COST_OUTLIER_MULTIPLIER = 2.0;

    /**
     * @param array<int, array{kind: string, name: string, status: string, cost_usd?: float}> $spans
     * @param array<int, array{span_count: int, cost_usd: float}> $history  rows from last 7 days, same label
     * @return Anomaly[]
     */
    public function check(string $traceLabel, array $spans, array $history): array
    {
        $anomalies = [];

        if (($loop = $this->detectToolLoop($spans)) !== null) {
            $anomalies[] = $loop;
        }
        if (($err = $this->detectErrorRatio($spans)) !== null) {
            $anomalies[] = $err;
        }
        if (($count = $this->detectSpanCountOutlier(count($spans), $history)) !== null) {
            $anomalies[] = $count;
        }
        if (($cost = $this->detectCostOutlier($this->sumCost($spans), $history)) !== null) {
            $anomalies[] = $cost;
        }

        return $anomalies;
    }

    /** @param array<int, array{kind: string, name: string}> $spans */
    private function detectToolLoop(array $spans): ?Anomaly
    {
        $counts = [];
        foreach ($spans as $s) {
            if ($s['kind'] === 'tool_call') {
                $counts[$s['name']] = ($counts[$s['name']] ?? 0) + 1;
            }
        }
        foreach ($counts as $name => $n) {
            if ($n > self::TOOL_LOOP_THRESHOLD) {
                return new Anomaly(
                    Anomaly::KIND_TOOL_LOOP,
                    sprintf('Tool "%s" called %d times in one trace', $name, $n),
                    ['tool' => $name, 'call_count' => $n],
                );
            }
        }
        return null;
    }

    /** @param array<int, array{status: string}> $spans */
    private function detectErrorRatio(array $spans): ?Anomaly
    {
        if ($spans === []) {
            return null;
        }
        $errors = 0;
        foreach ($spans as $s) {
            if ($s['status'] === 'error') {
                $errors++;
            }
        }
        $ratio = $errors / count($spans);
        if ($ratio >= self::ERROR_RATIO_THRESHOLD) {
            return new Anomaly(
                Anomaly::KIND_ERROR_RATIO,
                sprintf('%.0f%% of spans are errors (%d/%d)', $ratio * 100, $errors, count($spans)),
                ['error_ratio' => $ratio, 'error_count' => $errors, 'total_spans' => count($spans)],
            );
        }
        return null;
    }

    /** @param array<int, array{span_count: int}> $history */
    private function detectSpanCountOutlier(int $actual, array $history): ?Anomaly
    {
        if (count($history) < 5) {
            return null;  // need enough history to compute σ
        }
        $counts = array_column($history, 'span_count');
        $mean = array_sum($counts) / count($counts);
        $variance = array_sum(array_map(fn ($n) => ($n - $mean) ** 2, $counts)) / count($counts);
        $sigma = sqrt($variance);
        $threshold = $mean + self::SPAN_COUNT_SIGMA * $sigma;

        if ($sigma > 0.0 && $actual > $threshold) {
            return new Anomaly(
                Anomaly::KIND_SPAN_COUNT,
                sprintf('Span count %d exceeds mean+3σ (%.1f)', $actual, $threshold),
                ['actual' => $actual, 'mean' => $mean, 'sigma' => $sigma],
            );
        }
        return null;
    }

    /** @param array<int, array{cost_usd: float}> $history */
    private function detectCostOutlier(float $actual, array $history): ?Anomaly
    {
        if (count($history) < 3 || $actual <= 0.0) {
            return null;
        }
        $costs = array_column($history, 'cost_usd');
        sort($costs);
        $median = $costs[(int) floor(count($costs) / 2)];
        if ($median > 0.0 && $actual > $median * self::COST_OUTLIER_MULTIPLIER) {
            return new Anomaly(
                Anomaly::KIND_COST,
                sprintf('Cost $%.4f is %.1fx median $%.4f', $actual, $actual / $median, $median),
                ['actual' => $actual, 'median' => $median],
            );
        }
        return null;
    }

    /** @param array<int, array{cost_usd?: float}> $spans */
    private function sumCost(array $spans): float
    {
        $total = 0.0;
        foreach ($spans as $s) {
            $total += (float) ($s['cost_usd'] ?? 0.0);
        }
        return $total;
    }
}
```

- [ ] **Step 3: Run — expect PASS**

```bash
./vendor/bin/phpunit packages/ai-observability/tests/Unit/Analysis/AnomalyDetectorTest.php
```

- [ ] **Step 4: Commit**

```bash
git add packages/ai-observability/src/Analysis packages/ai-observability/tests/Unit/Analysis
git commit -m "feat(#622): add AnomalyDetector with four MVP heuristics"
```

---

### Task 14: Event Listeners

**Files:**
- Create: `packages/ai-observability/src/Listener/LlmCallListener.php`
- Create: `packages/ai-observability/src/Listener/ToolCallListener.php`

These listeners reference event classes expected to live in `waaseyaa/ai-agent` (`LlmCallCompleted`, `ToolCallStarted`, `ToolCallCompleted`). If they do not yet exist, this package still installs cleanly — listeners subscribe via string class names; if the classes are absent, the dispatcher never invokes them.

Use the Symfony EventSubscriber pattern. Event payloads are expected to carry `traceUuid` (string) plus event-specific data.

- [ ] **Step 1: Check for existing ai-agent events**

```bash
find packages/ai-agent/src -name "*Event*.php" | head
```

If event classes exist, confirm their property shape. If not, the listeners still compile (string event names) and become active once ai-agent ships matching events.

- [ ] **Step 2: Implement `LlmCallListener`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\AI\Observability\Cost\TokenAccountant;
use Waaseyaa\AI\Observability\TraceContext;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class LlmCallListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TraceContext $context,
        private readonly TokenAccountant $accountant,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // String event name — resilient if ai-agent isn't installed.
            'Waaseyaa\\AI\\Agent\\Event\\LlmCallCompleted' => 'onLlmCallCompleted',
        ];
    }

    public function onLlmCallCompleted(object $event): void
    {
        $traceUuid = $this->readProp($event, 'traceUuid');
        if ($traceUuid === null) {
            return;
        }
        $handle = $this->context->get($traceUuid);
        if ($handle === null) {
            $this->logger->debug('LlmCallListener: no active trace for uuid', ['uuid' => $traceUuid]);
            return;
        }
        $model = (string) ($this->readProp($event, 'model') ?? 'unknown');
        $inputTokens = (int) ($this->readProp($event, 'inputTokens') ?? 0);
        $outputTokens = (int) ($this->readProp($event, 'outputTokens') ?? 0);
        $cachedTokens = (int) ($this->readProp($event, 'cachedTokens') ?? 0);

        $this->accountant->record($handle, $model, $inputTokens, $outputTokens, $cachedTokens);
    }

    private function readProp(object $obj, string $name): mixed
    {
        return property_exists($obj, $name) ? $obj->{$name} : null;
    }
}
```

- [ ] **Step 3: Implement `ToolCallListener`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\AI\Observability\Handle\SpanHandle;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
use Waaseyaa\AI\Observability\TraceContext;

final class ToolCallListener implements EventSubscriberInterface
{
    /** @var array<string, SpanHandle> keyed by toolCallId */
    private array $openSpans = [];

    public function __construct(
        private readonly TraceContext $context,
        private readonly TraceRecorderInterface $recorder,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'Waaseyaa\\AI\\Agent\\Event\\ToolCallStarted' => 'onToolCallStarted',
            'Waaseyaa\\AI\\Agent\\Event\\ToolCallCompleted' => 'onToolCallCompleted',
        ];
    }

    public function onToolCallStarted(object $event): void
    {
        $traceUuid = $this->readProp($event, 'traceUuid');
        $callId = $this->readProp($event, 'callId');
        if ($traceUuid === null || $callId === null) {
            return;
        }
        $handle = $this->context->get($traceUuid);
        if ($handle === null) {
            return;
        }
        $this->openSpans[$callId] = $this->recorder->span(
            $handle,
            'tool_call',
            (string) ($this->readProp($event, 'toolName') ?? 'unknown'),
        );
    }

    public function onToolCallCompleted(object $event): void
    {
        $callId = $this->readProp($event, 'callId');
        if ($callId === null || !isset($this->openSpans[$callId])) {
            return;
        }
        $span = $this->openSpans[$callId];
        unset($this->openSpans[$callId]);
        $status = ($this->readProp($event, 'error') === null) ? 'ok' : 'error';
        $this->recorder->endSpan($span, ['tool' => $span->kind], $status);
    }

    private function readProp(object $obj, string $name): mixed
    {
        return property_exists($obj, $name) ? $obj->{$name} : null;
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add packages/ai-observability/src/Listener
git commit -m "feat(#622): add LlmCallListener and ToolCallListener"
```

---

### Task 15: ServiceProvider and closing verification

**Files:**
- Create: `packages/ai-observability/src/ObservabilityServiceProvider.php`
- Create: `packages/ai-observability/tests/Contract/TraceRecorderContractTest.php`
- Create: `packages/ai-observability/tests/Integration/DisabledModeTest.php`
- Create: `packages/ai-observability/tests/Integration/EventWiringTest.php`
- Modify: `packages/ai-observability/composer.json` (register provider under `extra.waaseyaa.providers`)

- [ ] **Step 1: ServiceProvider**

  Extend the framework `ServiceProvider` base (protected `singleton()`, `bind()`, `entityType()`; public `resolve()`). In `register()`:
  - Register the `trace` EntityType
  - Bind `TraceContext`, `ModelPricing`, `TokenAccountant`, `CostTracker`, `BudgetManager`, `AnomalyDetector` as singletons
  - Bind `TraceRecorderInterface` to `NullTraceRecorder` when `observability.enabled` config is false, otherwise `TraceRecorder`

  In `boot()`, resolve the event dispatcher and `addSubscriber($listener)` for `LlmCallListener` and `ToolCallListener`. Mirror the pattern in `packages/ai-pipeline/src/AIPipelineServiceProvider.php`.

  Register the class under `composer.json` `extra.waaseyaa.providers`.

- [ ] **Step 2: Contract test**

  `TraceRecorderContractTest` (abstract) defines the recorder-lifecycle expectations (start → span → endSpan → recordOutcome → completeTrace → assertions against DB). A concrete subclass `TraceRecorderSqliteTest` supplies a real `TraceRecorder` wired to `DBALDatabase::createSqlite()`. Mark the abstract class `#[CoversNothing]`.

- [ ] **Step 3: Disabled-mode test**

  Integration test: boot with `observability.enabled = false`. Assert:
  - `TraceRecorderInterface` resolves to `NullTraceRecorder`
  - Dispatching an `LlmCallCompleted` event writes no rows to `trace_span`

- [ ] **Step 4: Event-wiring test**

  Integration test with observability enabled. Start a trace via the recorder, dispatch a real `LlmCallCompleted` and a `ToolCallStarted`/`ToolCallCompleted` pair, then assert:
  - Spans appear in `trace_span` with correct kinds (`llm_call`, `tool_call`)
  - Cost is accounted via `CostTracker::totalForTrace()`

- [ ] **Step 5: Full-suite verification**

  Run in order, all must pass:

  ```
  ./vendor/bin/phpunit
  composer cs-check
  composer phpstan
  composer check-composer-policy
  bin/waaseyaa optimize:manifest
  ```

- [ ] **Step 6: Commit**

  ```
  git add packages/ai-observability
  git commit -m "feat(#622): ai-observability ServiceProvider and closing tests"
  ```

## Open items / deferrals (follow-up issues)

- `MetricsCollector`, `PerformanceDashboard` — separate issue.
- `ErrorTaxonomy` — separate issue.
- `ObservabilityExporter` (OpenTelemetry, Langfuse) — separate issue.
- `IntrospectionApi` — separate issue.
- Add `docs/specs/ai-observability.md` and wire into the CLAUDE.md orchestration table.
- After #620 and #621 land, add `ai-guardrails` integration (BudgetManager→guardrail gate) as a separate PR.
