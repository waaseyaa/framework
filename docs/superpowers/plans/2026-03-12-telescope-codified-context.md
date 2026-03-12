# Telescope: Codified Context Observability — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend Waaseyaa Telescope with codified context observability — recorders, store adapters, validator agent, admin UI, and SSR views.

**Architecture:** New recorders follow existing `record()` → `TelescopeStoreInterface::store()` pattern. A `CodifiedContextStoreInterface` extends the base with richer query methods (by session, drift severity, time range). Three store adapters (JSONL, SQLite, Prometheus) implement this interface. A Validator Agent CLI computes deterministic drift scores. Admin SPA gets new Telescope panel pages. SSR gets a Twig-rendered session detail view.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Nuxt 3/Vue 3/TypeScript (admin), Twig (SSR), Playwright (E2E), Symfony Console (CLI)

---

## File Structure

### New PHP Files (packages/telescope/)

```
src/
  CodifiedContext/
    CodifiedContextEntry.php              # Value object extending TelescopeEntry pattern
    CodifiedContextObserver.php           # Facade service with helper methods
    Event/
      SessionStarted.php                  # Event value object
      SessionEnded.php
      ContextLoaded.php
      ContextHashComputed.php
      ContextLoadFailed.php
      ModelOutputRecorded.php
      DriftDetected.php
      DriftCorrected.php
      ValidationCompleted.php
    Recorder/
      CodifiedContextSessionRecorder.php  # TYPE = 'cc_session'
      CodifiedContextEventRecorder.php    # TYPE = 'cc_event'
      CodifiedContextValidationRecorder.php # TYPE = 'cc_validation'
    Storage/
      CodifiedContextStoreInterface.php   # Extends TelescopeStoreInterface
      JsonlCodifiedContextStore.php       # JSONL file-per-repo adapter
      SqliteCodifiedContextStore.php      # SQLite adapter (reuses PdoDatabase)
      PrometheusCodifiedContextStore.php  # Prometheus metrics adapter
    Validator/
      DriftScorer.php                     # Deterministic drift score algorithm
      ValidationReport.php               # Report value object
      StructuralChecker.php               # File/API surface checks
      ContradictionChecker.php            # Contradiction detection
      EmbeddingProviderInterface.php      # Pluggable embeddings
      MockEmbeddingProvider.php           # Test provider
      OpenAIEmbeddingProvider.php         # Real provider (interface only)
    Schema/
      SessionEventSchema.php             # PHP schema validator
      ValidationReportSchema.php         # PHP schema validator
schemas/
  codified_context_session.json           # JSON Schema
  codified_context_event.json
  codified_context_validation.json
tests/
  Unit/
    CodifiedContext/
      Recorder/
        CodifiedContextSessionRecorderTest.php
        CodifiedContextEventRecorderTest.php
        CodifiedContextValidationRecorderTest.php
      Storage/
        JsonlCodifiedContextStoreTest.php
        SqliteCodifiedContextStoreTest.php
        PrometheusCodifiedContextStoreTest.php
      Validator/
        DriftScorerTest.php
        StructuralCheckerTest.php
        ContradictionCheckerTest.php
        ValidationReportTest.php
      Event/
        EventValueObjectsTest.php
      CodifiedContextObserverTest.php
      CodifiedContextEntryTest.php
```

### New Admin SPA Files (packages/admin/)

```
app/
  pages/
    telescope/
      codified-context/
        index.vue                         # Session list view
        [sessionId].vue                   # Session detail + drift timeline
  composables/
    useCodifiedContext.ts                 # API composable
  components/
    telescope/
      DriftScoreChart.vue                 # Drift score timeline chart
      EventStreamViewer.vue               # Event stream table
      ValidationReportCard.vue            # Validation report display
      ContextHeatmap.vue                  # Context usage heatmap
e2e/
  telescope-codified-context.spec.ts      # Playwright tests
```

### New SSR Files (packages/ssr/)

```
templates/
  telescope/
    codified-context-session.html.twig    # Session detail template
```

### New API Files (packages/api/)

```
src/
  Controller/
    CodifiedContextController.php         # API endpoints for admin SPA
```

### New CLI Files

```
packages/cli/src/Command/Telescope/
  TelescopeValidateCommand.php            # bin/waaseyaa telescope:validate
```

### Other Files

```
docs/telescope/codified-context.md        # Developer documentation
telescope/codified-context/summary.md     # Design summary artifact
```

---

## Chunk 1: Core Infrastructure (Agent A)

### Task 1: Event Value Objects

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Event/SessionStarted.php`
- Create: `packages/telescope/src/CodifiedContext/Event/SessionEnded.php`
- Create: `packages/telescope/src/CodifiedContext/Event/ContextLoaded.php`
- Create: `packages/telescope/src/CodifiedContext/Event/ContextHashComputed.php`
- Create: `packages/telescope/src/CodifiedContext/Event/ContextLoadFailed.php`
- Create: `packages/telescope/src/CodifiedContext/Event/ModelOutputRecorded.php`
- Create: `packages/telescope/src/CodifiedContext/Event/DriftDetected.php`
- Create: `packages/telescope/src/CodifiedContext/Event/DriftCorrected.php`
- Create: `packages/telescope/src/CodifiedContext/Event/ValidationCompleted.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Event/EventValueObjectsTest.php`

- [ ] **Step 1: Write failing test for SessionStarted value object**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Event\SessionStarted;

#[CoversClass(SessionStarted::class)]
final class EventValueObjectsTest extends TestCase
{
    #[Test]
    public function session_started_stores_metadata(): void
    {
        $event = new SessionStarted(
            sessionId: 'sess-001',
            repoHash: 'abc123',
            metadata: ['branch' => 'main', 'tool' => 'claude-code'],
        );

        self::assertSame('sess-001', $event->sessionId);
        self::assertSame('abc123', $event->repoHash);
        self::assertSame(['branch' => 'main', 'tool' => 'claude-code'], $event->metadata);
        self::assertInstanceOf(\DateTimeImmutable::class, $event->occurredAt);
    }

    #[Test]
    public function session_started_serializes_to_array(): void
    {
        $event = new SessionStarted(
            sessionId: 'sess-001',
            repoHash: 'abc123',
            metadata: ['branch' => 'main'],
        );

        $array = $event->toArray();

        self::assertSame('sess-001', $array['session_id']);
        self::assertSame('abc123', $array['repo_hash']);
        self::assertSame(['branch' => 'main'], $array['metadata']);
        self::assertArrayHasKey('occurred_at', $array);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Event/EventValueObjectsTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement SessionStarted**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Event;

final class SessionStarted
{
    public readonly \DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $repoHash,
        public readonly array $metadata = [],
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'repo_hash' => $this->repoHash,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Event/EventValueObjectsTest.php`
Expected: PASS

- [ ] **Step 5: Add remaining event value objects and tests**

Create all remaining event classes following the same pattern:

- `SessionEnded` — fields: `sessionId`, `durationMs` (float), `eventCount` (int)
- `ContextLoaded` — fields: `sessionId`, `contextHash`, `filePaths` (array), `totalBytes` (int)
- `ContextHashComputed` — fields: `sessionId`, `contextHash`, `algorithm` (default 'sha256')
- `ContextLoadFailed` — fields: `sessionId`, `errorMessage`, `filePath` (nullable)
- `ModelOutputRecorded` — fields: `sessionId`, `outputHash`, `references` (array), `tokenCount` (int)
- `DriftDetected` — fields: `sessionId`, `driftScore` (int 0-100), `severity` (string), `issues` (array)
- `DriftCorrected` — fields: `sessionId`, `originalScore` (int), `correctedScore` (int), `corrections` (array)
- `ValidationCompleted` — fields: `sessionId`, `driftScore` (int), `issues` (array), `recommendation` (string)

Each class: readonly properties, constructor with `occurredAt` default, `toArray()` method.

Add tests for each: construction test + serialization test.

- [ ] **Step 6: Run all event tests**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Event/`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Event/ packages/telescope/tests/Unit/CodifiedContext/Event/
git commit -m "feat(telescope): add codified context event value objects"
```

### Task 2: CodifiedContextEntry Value Object

**Files:**
- Create: `packages/telescope/src/CodifiedContext/CodifiedContextEntry.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/CodifiedContextEntryTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;

#[CoversClass(CodifiedContextEntry::class)]
final class CodifiedContextEntryTest extends TestCase
{
    #[Test]
    public function creates_entry_with_session_id(): void
    {
        $entry = new CodifiedContextEntry(
            type: 'cc_session',
            data: ['session_id' => 'sess-001', 'action' => 'start'],
            sessionId: 'sess-001',
        );

        self::assertSame('cc_session', $entry->type);
        self::assertSame('sess-001', $entry->sessionId);
        self::assertSame('start', $entry->data['action']);
        self::assertNotEmpty($entry->id);
        self::assertInstanceOf(\DateTimeImmutable::class, $entry->createdAt);
    }

    #[Test]
    public function to_array_includes_session_id(): void
    {
        $entry = new CodifiedContextEntry(
            type: 'cc_event',
            data: ['event' => 'context.load'],
            sessionId: 'sess-002',
        );

        $array = $entry->toArray();

        self::assertSame('sess-002', $array['session_id']);
        self::assertSame('cc_event', $array['type']);
    }

    #[Test]
    public function from_array_reconstructs_entry(): void
    {
        $original = new CodifiedContextEntry(
            type: 'cc_validation',
            data: ['drift_score' => 85],
            sessionId: 'sess-003',
        );

        $restored = CodifiedContextEntry::fromArray($original->toArray());

        self::assertSame($original->id, $restored->id);
        self::assertSame($original->sessionId, $restored->sessionId);
        self::assertSame(85, $restored->data['drift_score']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/CodifiedContextEntryTest.php`
Expected: FAIL

- [ ] **Step 3: Implement CodifiedContextEntry**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext;

final class CodifiedContextEntry
{
    public readonly string $id;
    public readonly \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly string $sessionId,
        ?string $id = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'session_id' => $this->sessionId,
            'data' => $this->data,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            type: $row['type'],
            data: is_string($row['data']) ? json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR) : $row['data'],
            sessionId: $row['session_id'],
            id: $row['id'],
            createdAt: new \DateTimeImmutable($row['created_at']),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/CodifiedContextEntryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/src/CodifiedContext/CodifiedContextEntry.php packages/telescope/tests/Unit/CodifiedContext/CodifiedContextEntryTest.php
git commit -m "feat(telescope): add CodifiedContextEntry value object"
```

### Task 3: JSON Schemas

**Files:**
- Create: `packages/telescope/schemas/codified_context_session.json`
- Create: `packages/telescope/schemas/codified_context_event.json`
- Create: `packages/telescope/schemas/codified_context_validation.json`
- Create: `packages/telescope/src/CodifiedContext/Schema/SessionEventSchema.php`
- Create: `packages/telescope/src/CodifiedContext/Schema/ValidationReportSchema.php`
- Test: (tested inline with recorder tests in Task 4)

- [ ] **Step 1: Create session event JSON schema**

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "Codified Context Session Event",
  "type": "object",
  "required": ["session_id", "repo_hash", "action", "occurred_at"],
  "properties": {
    "session_id": { "type": "string" },
    "repo_hash": { "type": "string" },
    "action": { "type": "string", "enum": ["start", "end"] },
    "metadata": { "type": "object" },
    "duration_ms": { "type": "number", "minimum": 0 },
    "event_count": { "type": "integer", "minimum": 0 },
    "occurred_at": { "type": "string", "format": "date-time" }
  },
  "additionalProperties": false
}
```

- [ ] **Step 2: Create context event JSON schema**

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "Codified Context Event",
  "type": "object",
  "required": ["session_id", "event_type", "occurred_at"],
  "properties": {
    "session_id": { "type": "string" },
    "event_type": {
      "type": "string",
      "enum": ["context.load", "context.hash", "context.fail", "model.output", "drift.detected", "drift.corrected"]
    },
    "context_hash": { "type": "string" },
    "file_paths": { "type": "array", "items": { "type": "string" } },
    "total_bytes": { "type": "integer", "minimum": 0 },
    "error_message": { "type": "string" },
    "output_hash": { "type": "string" },
    "references": { "type": "array", "items": { "type": "string" } },
    "token_count": { "type": "integer", "minimum": 0 },
    "drift_score": { "type": "integer", "minimum": 0, "maximum": 100 },
    "severity": { "type": "string", "enum": ["critical", "high", "medium", "low"] },
    "issues": { "type": "array", "items": { "type": "object" } },
    "corrections": { "type": "array", "items": { "type": "object" } },
    "occurred_at": { "type": "string", "format": "date-time" }
  },
  "additionalProperties": false
}
```

- [ ] **Step 3: Create validation report JSON schema**

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "Codified Context Validation Report",
  "type": "object",
  "required": ["session_id", "drift_score", "components", "issues", "recommendation", "validated_at"],
  "properties": {
    "session_id": { "type": "string" },
    "drift_score": { "type": "integer", "minimum": 0, "maximum": 100 },
    "components": {
      "type": "object",
      "properties": {
        "semantic_alignment": { "type": "number", "minimum": 0, "maximum": 60 },
        "structural_checks": { "type": "number", "minimum": 0, "maximum": 20 },
        "contradiction_checks": { "type": "number", "minimum": 0, "maximum": 20 }
      },
      "required": ["semantic_alignment", "structural_checks", "contradiction_checks"]
    },
    "issues": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["type", "message", "severity"],
        "properties": {
          "type": { "type": "string" },
          "message": { "type": "string" },
          "severity": { "type": "string", "enum": ["critical", "high", "medium", "low"] },
          "file_path": { "type": "string" },
          "context": { "type": "object" }
        }
      }
    },
    "recommendation": { "type": "string", "enum": ["critical", "high", "medium", "low"] },
    "validated_at": { "type": "string", "format": "date-time" }
  },
  "additionalProperties": false
}
```

- [ ] **Step 4: Implement PHP schema validators**

`SessionEventSchema.php` — static `validate(array $data): bool` that checks required keys and types.
`ValidationReportSchema.php` — same pattern for validation reports.
These are lightweight PHP validators (no JSON Schema library dependency — just key/type checks).

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/schemas/ packages/telescope/src/CodifiedContext/Schema/
git commit -m "feat(telescope): add codified context JSON schemas and PHP validators"
```

### Task 4: Recorders

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Recorder/CodifiedContextSessionRecorder.php`
- Create: `packages/telescope/src/CodifiedContext/Recorder/CodifiedContextEventRecorder.php`
- Create: `packages/telescope/src/CodifiedContext/Recorder/CodifiedContextValidationRecorder.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Recorder/CodifiedContextSessionRecorderTest.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Recorder/CodifiedContextEventRecorderTest.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Recorder/CodifiedContextValidationRecorderTest.php`

- [ ] **Step 1: Write failing test for CodifiedContextSessionRecorder**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Recorder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Recorder\CodifiedContextSessionRecorder;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;

#[CoversClass(CodifiedContextSessionRecorder::class)]
final class CodifiedContextSessionRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;
    private CodifiedContextSessionRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
        $this->recorder = new CodifiedContextSessionRecorder($this->store);
    }

    #[Test]
    public function records_session_start(): void
    {
        $this->recorder->recordStart(
            sessionId: 'sess-001',
            repoHash: 'abc123',
            metadata: ['branch' => 'main'],
        );

        $entries = $this->store->query('cc_session');
        self::assertCount(1, $entries);
        self::assertSame('start', $entries[0]->data['action']);
        self::assertSame('sess-001', $entries[0]->data['session_id']);
        self::assertSame('abc123', $entries[0]->data['repo_hash']);
    }

    #[Test]
    public function records_session_end(): void
    {
        $this->recorder->recordEnd(
            sessionId: 'sess-001',
            durationMs: 5432.1,
            eventCount: 12,
        );

        $entries = $this->store->query('cc_session');
        self::assertCount(1, $entries);
        self::assertSame('end', $entries[0]->data['action']);
        self::assertSame(5432.1, $entries[0]->data['duration_ms']);
        self::assertSame(12, $entries[0]->data['event_count']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Recorder/CodifiedContextSessionRecorderTest.php`
Expected: FAIL

- [ ] **Step 3: Implement CodifiedContextSessionRecorder**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Recorder;

use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;

final class CodifiedContextSessionRecorder
{
    public const string TYPE = 'cc_session';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function recordStart(string $sessionId, string $repoHash, array $metadata = []): void
    {
        $this->store->store(self::TYPE, [
            'session_id' => $sessionId,
            'repo_hash' => $repoHash,
            'action' => 'start',
            'metadata' => $metadata,
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function recordEnd(string $sessionId, float $durationMs, int $eventCount): void
    {
        $this->store->store(self::TYPE, [
            'session_id' => $sessionId,
            'action' => 'end',
            'duration_ms' => $durationMs,
            'event_count' => $eventCount,
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Recorder/CodifiedContextSessionRecorderTest.php`
Expected: PASS

- [ ] **Step 5: Write failing test for CodifiedContextEventRecorder**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Recorder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Recorder\CodifiedContextEventRecorder;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;

#[CoversClass(CodifiedContextEventRecorder::class)]
final class CodifiedContextEventRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;
    private CodifiedContextEventRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
        $this->recorder = new CodifiedContextEventRecorder($this->store);
    }

    #[Test]
    public function records_context_load(): void
    {
        $this->recorder->recordContextLoad(
            sessionId: 'sess-001',
            contextHash: 'hash123',
            filePaths: ['CLAUDE.md', 'docs/specs/entity-system.md'],
            totalBytes: 4096,
        );

        $entries = $this->store->query('cc_event');
        self::assertCount(1, $entries);
        self::assertSame('context.load', $entries[0]->data['event_type']);
        self::assertSame('hash123', $entries[0]->data['context_hash']);
        self::assertCount(2, $entries[0]->data['file_paths']);
    }

    #[Test]
    public function records_context_hash(): void
    {
        $this->recorder->recordContextHash('sess-001', 'sha256hash');

        $entries = $this->store->query('cc_event');
        self::assertSame('context.hash', $entries[0]->data['event_type']);
    }

    #[Test]
    public function records_context_failure(): void
    {
        $this->recorder->recordContextFail('sess-001', 'File not found', 'missing.md');

        $entries = $this->store->query('cc_event');
        self::assertSame('context.fail', $entries[0]->data['event_type']);
        self::assertSame('File not found', $entries[0]->data['error_message']);
    }

    #[Test]
    public function records_model_output(): void
    {
        $this->recorder->recordModelOutput(
            sessionId: 'sess-001',
            outputHash: 'outhash',
            references: ['entity-system.md:45', 'CLAUDE.md:12'],
            tokenCount: 1500,
        );

        $entries = $this->store->query('cc_event');
        self::assertSame('model.output', $entries[0]->data['event_type']);
        self::assertSame(1500, $entries[0]->data['token_count']);
    }

    #[Test]
    public function records_drift_detected(): void
    {
        $this->recorder->recordDriftDetected(
            sessionId: 'sess-001',
            driftScore: 35,
            severity: 'high',
            issues: [['type' => 'stale_reference', 'message' => 'File removed']],
        );

        $entries = $this->store->query('cc_event');
        self::assertSame('drift.detected', $entries[0]->data['event_type']);
        self::assertSame(35, $entries[0]->data['drift_score']);
        self::assertSame('high', $entries[0]->data['severity']);
    }

    #[Test]
    public function records_drift_corrected(): void
    {
        $this->recorder->recordDriftCorrected(
            sessionId: 'sess-001',
            originalScore: 35,
            correctedScore: 85,
            corrections: [['action' => 'updated_reference']],
        );

        $entries = $this->store->query('cc_event');
        self::assertSame('drift.corrected', $entries[0]->data['event_type']);
        self::assertSame(35, $entries[0]->data['original_score']);
        self::assertSame(85, $entries[0]->data['corrected_score']);
    }
}
```

- [ ] **Step 6: Implement CodifiedContextEventRecorder**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Recorder;

use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;

final class CodifiedContextEventRecorder
{
    public const string TYPE = 'cc_event';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
    ) {}

    /** @param string[] $filePaths */
    public function recordContextLoad(string $sessionId, string $contextHash, array $filePaths, int $totalBytes): void
    {
        $this->record($sessionId, 'context.load', [
            'context_hash' => $contextHash,
            'file_paths' => $filePaths,
            'total_bytes' => $totalBytes,
        ]);
    }

    public function recordContextHash(string $sessionId, string $contextHash, string $algorithm = 'sha256'): void
    {
        $this->record($sessionId, 'context.hash', [
            'context_hash' => $contextHash,
            'algorithm' => $algorithm,
        ]);
    }

    public function recordContextFail(string $sessionId, string $errorMessage, ?string $filePath = null): void
    {
        $data = ['error_message' => $errorMessage];
        if ($filePath !== null) {
            $data['file_path'] = $filePath;
        }
        $this->record($sessionId, 'context.fail', $data);
    }

    /** @param string[] $references */
    public function recordModelOutput(string $sessionId, string $outputHash, array $references, int $tokenCount): void
    {
        $this->record($sessionId, 'model.output', [
            'output_hash' => $outputHash,
            'references' => $references,
            'token_count' => $tokenCount,
        ]);
    }

    /** @param array<int, array<string, mixed>> $issues */
    public function recordDriftDetected(string $sessionId, int $driftScore, string $severity, array $issues): void
    {
        $this->record($sessionId, 'drift.detected', [
            'drift_score' => $driftScore,
            'severity' => $severity,
            'issues' => $issues,
        ]);
    }

    /** @param array<int, array<string, mixed>> $corrections */
    public function recordDriftCorrected(string $sessionId, int $originalScore, int $correctedScore, array $corrections): void
    {
        $this->record($sessionId, 'drift.corrected', [
            'original_score' => $originalScore,
            'corrected_score' => $correctedScore,
            'corrections' => $corrections,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function record(string $sessionId, string $eventType, array $extra = []): void
    {
        $this->store->store(self::TYPE, array_merge([
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ], $extra));
    }
}
```

- [ ] **Step 7: Run event recorder test**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Recorder/CodifiedContextEventRecorderTest.php`
Expected: PASS

- [ ] **Step 8: Write failing test for CodifiedContextValidationRecorder**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Recorder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Recorder\CodifiedContextValidationRecorder;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;

#[CoversClass(CodifiedContextValidationRecorder::class)]
final class CodifiedContextValidationRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;
    private CodifiedContextValidationRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
        $this->recorder = new CodifiedContextValidationRecorder($this->store);
    }

    #[Test]
    public function records_validation_result(): void
    {
        $this->recorder->record(
            sessionId: 'sess-001',
            driftScore: 72,
            components: [
                'semantic_alignment' => 45.0,
                'structural_checks' => 17.0,
                'contradiction_checks' => 10.0,
            ],
            issues: [
                ['type' => 'stale_reference', 'message' => 'File removed', 'severity' => 'medium'],
            ],
            recommendation: 'medium',
        );

        $entries = $this->store->query('cc_validation');
        self::assertCount(1, $entries);
        self::assertSame(72, $entries[0]->data['drift_score']);
        self::assertSame('medium', $entries[0]->data['recommendation']);
        self::assertSame(45.0, $entries[0]->data['components']['semantic_alignment']);
        self::assertCount(1, $entries[0]->data['issues']);
    }
}
```

- [ ] **Step 9: Implement CodifiedContextValidationRecorder**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Recorder;

use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;

final class CodifiedContextValidationRecorder
{
    public const string TYPE = 'cc_validation';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
    ) {}

    /**
     * @param array{semantic_alignment: float, structural_checks: float, contradiction_checks: float} $components
     * @param array<int, array<string, mixed>> $issues
     */
    public function record(
        string $sessionId,
        int $driftScore,
        array $components,
        array $issues,
        string $recommendation,
    ): void {
        $this->store->store(self::TYPE, [
            'session_id' => $sessionId,
            'drift_score' => $driftScore,
            'components' => $components,
            'issues' => $issues,
            'recommendation' => $recommendation,
            'validated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }
}
```

- [ ] **Step 10: Run all recorder tests**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Recorder/`
Expected: ALL PASS

- [ ] **Step 11: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Recorder/ packages/telescope/tests/Unit/CodifiedContext/Recorder/
git commit -m "feat(telescope): add codified context recorders"
```

### Task 5: CodifiedContextObserver Facade

**Files:**
- Create: `packages/telescope/src/CodifiedContext/CodifiedContextObserver.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/CodifiedContextObserverTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\CodifiedContextObserver;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;

#[CoversClass(CodifiedContextObserver::class)]
final class CodifiedContextObserverTest extends TestCase
{
    private SqliteTelescopeStore $store;
    private CodifiedContextObserver $observer;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
        $this->observer = new CodifiedContextObserver($this->store);
    }

    #[Test]
    public function full_session_lifecycle(): void
    {
        $this->observer->recordSessionStart('sess-001', 'repo-hash', ['branch' => 'main']);
        $this->observer->recordContextLoad('sess-001', 'ctx-hash', ['CLAUDE.md'], 2048);
        $this->observer->recordModelOutput('sess-001', 'out-hash', ['CLAUDE.md:10'], 500);
        $this->observer->recordDrift('sess-001', 42, 'medium', [['type' => 'stale', 'message' => 'old ref', 'severity' => 'medium']]);
        $this->observer->recordValidation('sess-001', 72, ['semantic_alignment' => 45.0, 'structural_checks' => 17.0, 'contradiction_checks' => 10.0], [], 'low');
        $this->observer->recordSessionEnd('sess-001', 12345.6, 5);

        $sessions = $this->store->query('cc_session');
        $events = $this->store->query('cc_event');
        $validations = $this->store->query('cc_validation');

        self::assertCount(2, $sessions); // start + end
        self::assertCount(3, $events); // load + output + drift
        self::assertCount(1, $validations);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/CodifiedContextObserverTest.php`
Expected: FAIL

- [ ] **Step 3: Implement CodifiedContextObserver**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext;

use Waaseyaa\Telescope\CodifiedContext\Recorder\CodifiedContextEventRecorder;
use Waaseyaa\Telescope\CodifiedContext\Recorder\CodifiedContextSessionRecorder;
use Waaseyaa\Telescope\CodifiedContext\Recorder\CodifiedContextValidationRecorder;
use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;

final class CodifiedContextObserver
{
    private readonly CodifiedContextSessionRecorder $sessionRecorder;
    private readonly CodifiedContextEventRecorder $eventRecorder;
    private readonly CodifiedContextValidationRecorder $validationRecorder;

    public function __construct(TelescopeStoreInterface $store)
    {
        $this->sessionRecorder = new CodifiedContextSessionRecorder($store);
        $this->eventRecorder = new CodifiedContextEventRecorder($store);
        $this->validationRecorder = new CodifiedContextValidationRecorder($store);
    }

    /** @param array<string, mixed> $metadata */
    public function recordSessionStart(string $sessionId, string $repoHash, array $metadata = []): void
    {
        $this->sessionRecorder->recordStart($sessionId, $repoHash, $metadata);
    }

    /** @param string[] $filePaths */
    public function recordContextLoad(string $sessionId, string $contextHash, array $filePaths, int $totalBytes): void
    {
        $this->eventRecorder->recordContextLoad($sessionId, $contextHash, $filePaths, $totalBytes);
    }

    /** @param string[] $references */
    public function recordModelOutput(string $sessionId, string $outputHash, array $references, int $tokenCount): void
    {
        $this->eventRecorder->recordModelOutput($sessionId, $outputHash, $references, $tokenCount);
    }

    /** @param array<int, array<string, mixed>> $issues */
    public function recordDrift(string $sessionId, int $driftScore, string $severity, array $issues): void
    {
        $this->eventRecorder->recordDriftDetected($sessionId, $driftScore, $severity, $issues);
    }

    /**
     * @param array{semantic_alignment: float, structural_checks: float, contradiction_checks: float} $components
     * @param array<int, array<string, mixed>> $issues
     */
    public function recordValidation(string $sessionId, int $driftScore, array $components, array $issues, string $recommendation): void
    {
        $this->validationRecorder->record($sessionId, $driftScore, $components, $issues, $recommendation);
    }

    public function recordSessionEnd(string $sessionId, float $durationMs, int $eventCount): void
    {
        $this->sessionRecorder->recordEnd($sessionId, $durationMs, $eventCount);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/CodifiedContextObserverTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/src/CodifiedContext/CodifiedContextObserver.php packages/telescope/tests/Unit/CodifiedContext/CodifiedContextObserverTest.php
git commit -m "feat(telescope): add CodifiedContextObserver facade service"
```

---

## Chunk 2: Store Adapters (Agent B)

### Task 6: CodifiedContextStoreInterface

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Storage/CodifiedContextStoreInterface.php`

- [ ] **Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Storage;

use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;

interface CodifiedContextStoreInterface extends TelescopeStoreInterface
{
    /** @return CodifiedContextEntry[] */
    public function queryBySession(string $sessionId, int $limit = 100, int $offset = 0): array;

    /** @return CodifiedContextEntry[] */
    public function queryByEventType(string $eventType, int $limit = 50, int $offset = 0): array;

    /** @return CodifiedContextEntry[] */
    public function queryByTimeRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 100): array;

    /** @return CodifiedContextEntry[] */
    public function queryByDriftSeverity(string $severity, int $limit = 50, int $offset = 0): array;
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Storage/CodifiedContextStoreInterface.php
git commit -m "feat(telescope): add CodifiedContextStoreInterface"
```

### Task 7: JSONL Store Adapter

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Storage/JsonlCodifiedContextStore.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Storage/JsonlCodifiedContextStoreTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Storage\JsonlCodifiedContextStore;

#[CoversClass(JsonlCodifiedContextStore::class)]
final class JsonlCodifiedContextStoreTest extends TestCase
{
    private string $tempDir;
    private JsonlCodifiedContextStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_jsonl_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->store = new JsonlCodifiedContextStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*.jsonl');
        if ($files) {
            array_map('unlink', $files);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function stores_and_queries_entries(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'end']);
        $this->store->store('cc_event', ['session_id' => 'sess-001', 'event_type' => 'context.load']);

        $sessions = $this->store->query('cc_session');
        self::assertCount(2, $sessions);

        $events = $this->store->query('cc_event');
        self::assertCount(1, $events);
    }

    #[Test]
    public function queries_by_session(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $this->store->store('cc_event', ['session_id' => 'sess-001', 'event_type' => 'context.load']);
        $this->store->store('cc_event', ['session_id' => 'sess-002', 'event_type' => 'context.load']);

        $results = $this->store->queryBySession('sess-001');
        self::assertCount(2, $results);
    }

    #[Test]
    public function queries_by_drift_severity(): void
    {
        $this->store->store('cc_event', [
            'session_id' => 'sess-001',
            'event_type' => 'drift.detected',
            'severity' => 'high',
            'drift_score' => 30,
        ]);
        $this->store->store('cc_event', [
            'session_id' => 'sess-002',
            'event_type' => 'drift.detected',
            'severity' => 'low',
            'drift_score' => 80,
        ]);

        $highDrift = $this->store->queryByDriftSeverity('high');
        self::assertCount(1, $highDrift);
    }

    #[Test]
    public function prunes_old_entries(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $pruned = $this->store->prune(new \DateTimeImmutable('+1 hour'));
        self::assertSame(1, $pruned);

        $entries = $this->store->query('cc_session');
        self::assertCount(0, $entries);
    }

    #[Test]
    public function clear_removes_all(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $this->store->store('cc_event', ['session_id' => 'sess-001', 'event_type' => 'context.load']);
        $this->store->clear();

        self::assertCount(0, $this->store->query('cc_session'));
        self::assertCount(0, $this->store->query('cc_event'));
    }

    #[Test]
    public function creates_jsonl_file_on_disk(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);

        $files = glob($this->tempDir . '/*.jsonl');
        self::assertNotEmpty($files);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Storage/JsonlCodifiedContextStoreTest.php`
Expected: FAIL

- [ ] **Step 3: Implement JsonlCodifiedContextStore**

The store writes one JSONL file (`telescope_cc.jsonl`) in the configured directory. Each line is a JSON object with `id`, `type`, `session_id`, `data`, `created_at`. Queries read the file and filter in memory. For production, rotation by date could be added later.

Implementation: append-only writes with `FILE_APPEND | LOCK_EX`. Reads parse all lines and filter. `prune()` rewrites the file excluding old entries. `clear()` truncates the file.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Storage/JsonlCodifiedContextStoreTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Storage/JsonlCodifiedContextStore.php packages/telescope/tests/Unit/CodifiedContext/Storage/JsonlCodifiedContextStoreTest.php
git commit -m "feat(telescope): add JSONL codified context store adapter"
```

### Task 8: SQLite Store Adapter

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Storage/SqliteCodifiedContextStore.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Storage/SqliteCodifiedContextStoreTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Storage\SqliteCodifiedContextStore;

#[CoversClass(SqliteCodifiedContextStore::class)]
final class SqliteCodifiedContextStoreTest extends TestCase
{
    private SqliteCodifiedContextStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteCodifiedContextStore::createInMemory();
    }

    #[Test]
    public function stores_and_queries_by_type(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $this->store->store('cc_event', ['session_id' => 'sess-001', 'event_type' => 'context.load']);

        self::assertCount(1, $this->store->query('cc_session'));
        self::assertCount(1, $this->store->query('cc_event'));
    }

    #[Test]
    public function queries_by_session_across_types(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $this->store->store('cc_event', ['session_id' => 'sess-001', 'event_type' => 'context.load']);
        $this->store->store('cc_event', ['session_id' => 'sess-002', 'event_type' => 'context.load']);

        $results = $this->store->queryBySession('sess-001');
        self::assertCount(2, $results);
    }

    #[Test]
    public function queries_by_event_type(): void
    {
        $this->store->store('cc_event', ['session_id' => 's1', 'event_type' => 'context.load']);
        $this->store->store('cc_event', ['session_id' => 's1', 'event_type' => 'drift.detected', 'severity' => 'high']);

        $results = $this->store->queryByEventType('drift.detected');
        self::assertCount(1, $results);
    }

    #[Test]
    public function queries_by_time_range(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);

        $from = new \DateTimeImmutable('-1 hour');
        $to = new \DateTimeImmutable('+1 hour');
        $results = $this->store->queryByTimeRange($from, $to);
        self::assertCount(1, $results);

        $future = new \DateTimeImmutable('+2 hours');
        $farFuture = new \DateTimeImmutable('+3 hours');
        $empty = $this->store->queryByTimeRange($future, $farFuture);
        self::assertCount(0, $empty);
    }

    #[Test]
    public function queries_by_drift_severity(): void
    {
        $this->store->store('cc_event', ['session_id' => 's1', 'event_type' => 'drift.detected', 'severity' => 'critical', 'drift_score' => 15]);
        $this->store->store('cc_event', ['session_id' => 's2', 'event_type' => 'drift.detected', 'severity' => 'low', 'drift_score' => 90]);

        $critical = $this->store->queryByDriftSeverity('critical');
        self::assertCount(1, $critical);
        self::assertSame(15, $critical[0]->data['drift_score']);
    }

    #[Test]
    public function prunes_and_clears(): void
    {
        $this->store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $this->store->clear();
        self::assertCount(0, $this->store->query('cc_session'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Storage/SqliteCodifiedContextStoreTest.php`
Expected: FAIL

- [ ] **Step 3: Implement SqliteCodifiedContextStore**

Uses a dedicated `telescope_cc_entries` table with columns: `id TEXT PRIMARY KEY`, `type TEXT NOT NULL`, `session_id TEXT NOT NULL`, `data TEXT NOT NULL` (JSON), `created_at TEXT NOT NULL`. Indexes on `(type, created_at)`, `(session_id)`, and a JSON extract index for `event_type`. Factory methods: `createInMemory()` and `createFromPath(string)`. Reuses the same PDO lazy-table pattern as `SqliteTelescopeStore`.

The `queryByDriftSeverity()` method uses `json_extract(data, '$.severity') = ?` for filtering.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Storage/SqliteCodifiedContextStoreTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Storage/SqliteCodifiedContextStore.php packages/telescope/tests/Unit/CodifiedContext/Storage/SqliteCodifiedContextStoreTest.php
git commit -m "feat(telescope): add SQLite codified context store adapter"
```

### Task 9: Prometheus Store Adapter

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Storage/PrometheusCodifiedContextStore.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Storage/PrometheusCodifiedContextStoreTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Storage\PrometheusCodifiedContextStore;

#[CoversClass(PrometheusCodifiedContextStore::class)]
final class PrometheusCodifiedContextStoreTest extends TestCase
{
    private PrometheusCodifiedContextStore $store;

    protected function setUp(): void
    {
        $this->store = new PrometheusCodifiedContextStore();
    }

    #[Test]
    public function increments_session_counter_on_store(): void
    {
        $this->store->store('cc_session', ['session_id' => 's1', 'action' => 'start']);
        $this->store->store('cc_session', ['session_id' => 's2', 'action' => 'start']);

        $metrics = $this->store->getMetrics();
        self::assertSame(2, $metrics['waaseyaa_cc_sessions_total']);
    }

    #[Test]
    public function increments_drift_counter(): void
    {
        $this->store->store('cc_event', [
            'session_id' => 's1',
            'event_type' => 'drift.detected',
            'severity' => 'high',
            'drift_score' => 30,
        ]);

        $metrics = $this->store->getMetrics();
        self::assertSame(1, $metrics['waaseyaa_cc_drift_events_total']);
    }

    #[Test]
    public function tracks_average_drift_score(): void
    {
        $this->store->store('cc_validation', ['session_id' => 's1', 'drift_score' => 80]);
        $this->store->store('cc_validation', ['session_id' => 's2', 'drift_score' => 60]);

        $metrics = $this->store->getMetrics();
        self::assertSame(70.0, $metrics['waaseyaa_cc_drift_score_avg']);
    }

    #[Test]
    public function renders_prometheus_text_format(): void
    {
        $this->store->store('cc_session', ['session_id' => 's1', 'action' => 'start']);
        $this->store->store('cc_validation', ['session_id' => 's1', 'drift_score' => 75]);

        $output = $this->store->renderPrometheusOutput();

        self::assertStringContainsString('waaseyaa_cc_sessions_total 1', $output);
        self::assertStringContainsString('waaseyaa_cc_drift_score_avg 75', $output);
        self::assertStringContainsString('# HELP', $output);
        self::assertStringContainsString('# TYPE', $output);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Storage/PrometheusCodifiedContextStoreTest.php`
Expected: FAIL

- [ ] **Step 3: Implement PrometheusCodifiedContextStore**

This adapter is a metrics-only store — it tracks counters and gauges in-process (no persistent query support). It delegates actual storage to an inner `TelescopeStoreInterface` (optional, for dual-write). The `renderPrometheusOutput()` method returns Prometheus text exposition format.

Metrics tracked:
- `waaseyaa_cc_sessions_total` (counter) — incremented on cc_session start actions
- `waaseyaa_cc_events_total` (counter) — incremented on any cc_event
- `waaseyaa_cc_drift_events_total` (counter) — incremented on drift.detected events
- `waaseyaa_cc_validations_total` (counter) — incremented on cc_validation stores
- `waaseyaa_cc_drift_score_avg` (gauge) — running average of drift scores

The `query*` methods from `CodifiedContextStoreInterface` delegate to the inner store or return empty arrays if no inner store is configured.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Storage/PrometheusCodifiedContextStoreTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Storage/PrometheusCodifiedContextStore.php packages/telescope/tests/Unit/CodifiedContext/Storage/PrometheusCodifiedContextStoreTest.php
git commit -m "feat(telescope): add Prometheus codified context store adapter"
```

---

## Chunk 3: Validator Agent (Agent C)

### Task 10: EmbeddingProviderInterface + MockProvider

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Validator/EmbeddingProviderInterface.php`
- Create: `packages/telescope/src/CodifiedContext/Validator/MockEmbeddingProvider.php`
- Create: `packages/telescope/src/CodifiedContext/Validator/OpenAIEmbeddingProvider.php`

- [ ] **Step 1: Create interface and mock**

```php
<?php
// EmbeddingProviderInterface.php
declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

interface EmbeddingProviderInterface
{
    /** @return float[] Vector embedding */
    public function embed(string $text): array;

    /** @param string[] $texts @return float[][] */
    public function embedBatch(array $texts): array;

    public function cosineSimilarity(array $a, array $b): float;
}
```

```php
<?php
// MockEmbeddingProvider.php
declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class MockEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @param array<string, float[]> $fixedEmbeddings Map of text → embedding for deterministic tests */
    public function __construct(
        private readonly array $fixedEmbeddings = [],
        private readonly int $dimensions = 8,
    ) {}

    public function embed(string $text): array
    {
        if (isset($this->fixedEmbeddings[$text])) {
            return $this->fixedEmbeddings[$text];
        }
        // Deterministic hash-based embedding for reproducible tests
        $hash = md5($text);
        $vector = [];
        for ($i = 0; $i < $this->dimensions; $i++) {
            $vector[] = (float) (hexdec(substr($hash, $i * 2, 2)) - 128) / 128.0;
        }
        return $this->normalize($vector);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn(string $t) => $this->embed($t), $texts);
    }

    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }
        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /** @param float[] $v @return float[] */
    private function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn(float $x) => $x ** 2, $v)));
        return $norm > 0 ? array_map(fn(float $x) => $x / $norm, $v) : $v;
    }
}
```

```php
<?php
// OpenAIEmbeddingProvider.php — stub interface-only implementation
declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
    ) {}

    public function embed(string $text): array
    {
        throw new \RuntimeException('OpenAI embedding provider requires network access. Configure API key and use in production only.');
    }

    public function embedBatch(array $texts): array
    {
        throw new \RuntimeException('OpenAI embedding provider requires network access.');
    }

    public function cosineSimilarity(array $a, array $b): float
    {
        // Reuse the same math as MockEmbeddingProvider
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }
        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Validator/EmbeddingProviderInterface.php packages/telescope/src/CodifiedContext/Validator/MockEmbeddingProvider.php packages/telescope/src/CodifiedContext/Validator/OpenAIEmbeddingProvider.php
git commit -m "feat(telescope): add embedding provider interface with mock and OpenAI stubs"
```

### Task 11: StructuralChecker

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Validator/StructuralChecker.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Validator/StructuralCheckerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\StructuralChecker;

#[CoversClass(StructuralChecker::class)]
final class StructuralCheckerTest extends TestCase
{
    #[Test]
    public function scores_20_when_all_references_valid(): void
    {
        $checker = new StructuralChecker();
        $result = $checker->check(
            references: ['CLAUDE.md', 'composer.json'],
            existingFiles: ['CLAUDE.md', 'composer.json', 'phpunit.xml.dist'],
        );

        self::assertSame(20.0, $result['score']);
        self::assertEmpty($result['issues']);
    }

    #[Test]
    public function penalizes_missing_file_references(): void
    {
        $checker = new StructuralChecker();
        $result = $checker->check(
            references: ['CLAUDE.md', 'nonexistent.md', 'also-missing.php'],
            existingFiles: ['CLAUDE.md'],
        );

        self::assertLessThan(20.0, $result['score']);
        self::assertCount(2, $result['issues']);
        self::assertSame('missing_file', $result['issues'][0]['type']);
    }

    #[Test]
    public function scores_20_when_no_references(): void
    {
        $checker = new StructuralChecker();
        $result = $checker->check(references: [], existingFiles: []);

        self::assertSame(20.0, $result['score']);
    }

    #[Test]
    public function penalizes_layer_violations(): void
    {
        $checker = new StructuralChecker();
        $result = $checker->check(
            references: [],
            existingFiles: [],
            layerViolations: [['from' => 'foundation', 'to' => 'admin', 'file' => 'Foundation/Bad.php']],
        );

        self::assertLessThan(20.0, $result['score']);
        self::assertSame('layer_violation', $result['issues'][0]['type']);
    }
}
```

- [ ] **Step 2: Implement StructuralChecker**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class StructuralChecker
{
    private const float MAX_SCORE = 20.0;
    private const float PENALTY_PER_MISSING_FILE = 4.0;
    private const float PENALTY_PER_LAYER_VIOLATION = 5.0;

    /**
     * @param string[] $references File paths referenced in model output
     * @param string[] $existingFiles Files that actually exist
     * @param array<int, array<string, string>> $layerViolations
     * @return array{score: float, issues: array<int, array<string, mixed>>}
     */
    public function check(array $references, array $existingFiles, array $layerViolations = []): array
    {
        $issues = [];
        $penalty = 0.0;

        $existingSet = array_flip($existingFiles);
        foreach ($references as $ref) {
            if (!isset($existingSet[$ref])) {
                $issues[] = ['type' => 'missing_file', 'message' => "Referenced file does not exist: {$ref}", 'severity' => 'medium', 'file_path' => $ref];
                $penalty += self::PENALTY_PER_MISSING_FILE;
            }
        }

        foreach ($layerViolations as $violation) {
            $issues[] = ['type' => 'layer_violation', 'message' => "Layer violation: {$violation['from']} imports from {$violation['to']}", 'severity' => 'high', 'file_path' => $violation['file'] ?? ''];
            $penalty += self::PENALTY_PER_LAYER_VIOLATION;
        }

        $score = max(0.0, self::MAX_SCORE - $penalty);

        return ['score' => $score, 'issues' => $issues];
    }
}
```

- [ ] **Step 3: Run test**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Validator/StructuralCheckerTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Validator/StructuralChecker.php packages/telescope/tests/Unit/CodifiedContext/Validator/StructuralCheckerTest.php
git commit -m "feat(telescope): add structural checker for drift validation"
```

### Task 12: ContradictionChecker

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Validator/ContradictionChecker.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Validator/ContradictionCheckerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\ContradictionChecker;

#[CoversClass(ContradictionChecker::class)]
final class ContradictionCheckerTest extends TestCase
{
    #[Test]
    public function no_contradictions_scores_full(): void
    {
        $checker = new ContradictionChecker();
        $result = $checker->check(
            modelOutput: 'Use EntityBase to create entities. Call enforceIsNew() for pre-set IDs.',
            contextFacts: [
                'Entity subclass constructors only accept (array $values)',
                'Call enforceIsNew() before save() for entities with pre-set IDs',
            ],
        );

        self::assertSame(20.0, $result['score']);
        self::assertEmpty($result['issues']);
    }

    #[Test]
    public function detects_contradictions_with_context(): void
    {
        $checker = new ContradictionChecker();
        $result = $checker->check(
            modelOutput: 'Use $event->getEntity() to access the entity from events.',
            contextFacts: [
                'EntityEvent uses public properties: $event->entity — no getter methods',
            ],
        );

        self::assertLessThan(20.0, $result['score']);
        self::assertNotEmpty($result['issues']);
        self::assertSame('contradiction', $result['issues'][0]['type']);
    }

    #[Test]
    public function scores_zero_with_many_contradictions(): void
    {
        $checker = new ContradictionChecker();
        $result = $checker->check(
            modelOutput: 'Use interface{} in Go. Use gomock for testing. Use Makefiles.',
            contextFacts: [
                'Use any not interface{}',
                'Use testify not gomock',
                'Use Taskfile.yml not Makefiles',
                'Use psr/log for logging',
            ],
        );

        self::assertSame(0.0, $result['score']);
    }
}
```

- [ ] **Step 2: Implement ContradictionChecker**

Uses keyword-based heuristics to detect contradictions between model output and context facts. Looks for patterns where context says "use X not Y" or "no Z methods" and the output references Y or Z. Each contradiction applies a penalty of 5.0 (max 20.0 total penalty → score 0.0).

- [ ] **Step 3: Run test**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Validator/ContradictionCheckerTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Validator/ContradictionChecker.php packages/telescope/tests/Unit/CodifiedContext/Validator/ContradictionCheckerTest.php
git commit -m "feat(telescope): add contradiction checker for drift validation"
```

### Task 13: DriftScorer

**Files:**
- Create: `packages/telescope/src/CodifiedContext/Validator/DriftScorer.php`
- Create: `packages/telescope/src/CodifiedContext/Validator/ValidationReport.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Validator/DriftScorerTest.php`
- Test: `packages/telescope/tests/Unit/CodifiedContext/Validator/ValidationReportTest.php`

- [ ] **Step 1: Write failing test for DriftScorer**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\ContradictionChecker;
use Waaseyaa\Telescope\CodifiedContext\Validator\DriftScorer;
use Waaseyaa\Telescope\CodifiedContext\Validator\MockEmbeddingProvider;
use Waaseyaa\Telescope\CodifiedContext\Validator\StructuralChecker;

#[CoversClass(DriftScorer::class)]
final class DriftScorerTest extends TestCase
{
    private DriftScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new DriftScorer(
            embeddingProvider: new MockEmbeddingProvider(),
            structuralChecker: new StructuralChecker(),
            contradictionChecker: new ContradictionChecker(),
        );
    }

    #[Test]
    public function perfect_alignment_scores_high(): void
    {
        // Identical text = cosine similarity 1.0 = semantic score 60
        $report = $this->scorer->score(
            modelOutput: 'Use EntityBase to create entities',
            contextSections: ['Use EntityBase to create entities'],
            referencedFiles: ['CLAUDE.md'],
            existingFiles: ['CLAUDE.md'],
            contextFacts: [],
        );

        self::assertGreaterThanOrEqual(80, $report->driftScore);
        self::assertSame('low', $report->recommendation);
    }

    #[Test]
    public function missing_files_reduce_score(): void
    {
        $report = $this->scorer->score(
            modelOutput: 'Check nonexistent.md for details',
            contextSections: ['Check nonexistent.md for details'],
            referencedFiles: ['nonexistent.md', 'also-missing.md'],
            existingFiles: [],
            contextFacts: [],
        );

        self::assertLessThan(80, $report->driftScore);
        self::assertNotEmpty($report->issues);
    }

    #[Test]
    public function score_is_between_0_and_100(): void
    {
        $report = $this->scorer->score(
            modelOutput: 'completely unrelated output about cooking recipes',
            contextSections: ['PHP 8.3 strict types entity system'],
            referencedFiles: ['a.md', 'b.md', 'c.md', 'd.md', 'e.md'],
            existingFiles: [],
            contextFacts: ['Use any not interface{}'],
        );

        self::assertGreaterThanOrEqual(0, $report->driftScore);
        self::assertLessThanOrEqual(100, $report->driftScore);
    }

    #[Test]
    public function recommendation_maps_to_score_ranges(): void
    {
        // Test the severity mapping logic directly
        self::assertSame('low', DriftScorer::severityFromScore(85));
        self::assertSame('medium', DriftScorer::severityFromScore(60));
        self::assertSame('high', DriftScorer::severityFromScore(35));
        self::assertSame('critical', DriftScorer::severityFromScore(15));
    }

    #[Test]
    public function score_is_deterministic(): void
    {
        $args = [
            'modelOutput' => 'Use EntityBase',
            'contextSections' => ['Use EntityBase for entities'],
            'referencedFiles' => ['CLAUDE.md'],
            'existingFiles' => ['CLAUDE.md'],
            'contextFacts' => [],
        ];

        $score1 = $this->scorer->score(...$args)->driftScore;
        $score2 = $this->scorer->score(...$args)->driftScore;

        self::assertSame($score1, $score2);
    }
}
```

- [ ] **Step 2: Write failing test for ValidationReport**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\ValidationReport;

#[CoversClass(ValidationReport::class)]
final class ValidationReportTest extends TestCase
{
    #[Test]
    public function serializes_to_array(): void
    {
        $report = new ValidationReport(
            sessionId: 'sess-001',
            driftScore: 72,
            semanticAlignment: 45.0,
            structuralScore: 17.0,
            contradictionScore: 10.0,
            issues: [['type' => 'missing_file', 'message' => 'File gone', 'severity' => 'medium']],
            recommendation: 'medium',
        );

        $array = $report->toArray();

        self::assertSame('sess-001', $array['session_id']);
        self::assertSame(72, $array['drift_score']);
        self::assertSame(45.0, $array['components']['semantic_alignment']);
        self::assertSame(17.0, $array['components']['structural_checks']);
        self::assertSame(10.0, $array['components']['contradiction_checks']);
        self::assertCount(1, $array['issues']);
        self::assertSame('medium', $array['recommendation']);
        self::assertArrayHasKey('validated_at', $array);
    }
}
```

- [ ] **Step 3: Implement ValidationReport and DriftScorer**

`ValidationReport` — readonly value object with `toArray()` and `toJson()`.

`DriftScorer` — constructor takes `EmbeddingProviderInterface`, `StructuralChecker`, `ContradictionChecker`. The `score()` method:
1. Embeds model output and each context section
2. Computes average cosine similarity, scales to 0–60 range
3. Runs structural checks (0–20)
4. Runs contradiction checks (0–20)
5. Sums components, clamps to 0–100
6. Maps to severity via `severityFromScore()`: ≥75 low, ≥50 medium, ≥25 high, <25 critical

- [ ] **Step 4: Run all validator tests**

Run: `./vendor/bin/phpunit packages/telescope/tests/Unit/CodifiedContext/Validator/`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add packages/telescope/src/CodifiedContext/Validator/DriftScorer.php packages/telescope/src/CodifiedContext/Validator/ValidationReport.php packages/telescope/tests/Unit/CodifiedContext/Validator/
git commit -m "feat(telescope): add drift scorer and validation report"
```

### Task 14: Telescope Validate CLI Command

**Files:**
- Create: `packages/cli/src/Command/Telescope/TelescopeValidateCommand.php`

- [ ] **Step 1: Implement the command**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Cli\Command\Telescope;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Telescope\CodifiedContext\Validator\ContradictionChecker;
use Waaseyaa\Telescope\CodifiedContext\Validator\DriftScorer;
use Waaseyaa\Telescope\CodifiedContext\Validator\EmbeddingProviderInterface;
use Waaseyaa\Telescope\CodifiedContext\Validator\StructuralChecker;

#[AsCommand(name: 'telescope:validate', description: 'Validate codified context for a session and compute drift score')]
final class TelescopeValidateCommand extends Command
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('session-id', InputArgument::REQUIRED, 'Session ID to validate');
        $this->addArgument('output-file', InputArgument::OPTIONAL, 'Path to write validation report JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = $input->getArgument('session-id');
        $output->writeln("Validating session: {$sessionId}");

        $scorer = new DriftScorer(
            embeddingProvider: $this->embeddingProvider,
            structuralChecker: new StructuralChecker(),
            contradictionChecker: new ContradictionChecker(),
        );

        // In a real integration, load session data from the store
        // For now, this provides the CLI skeleton
        $output->writeln('Validator Agent CLI ready. Provide session data via store integration.');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Write test for TelescopeValidateCommand**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Cli\Tests\Unit\Command\Telescope;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Cli\Command\Telescope\TelescopeValidateCommand;
use Waaseyaa\Telescope\CodifiedContext\Validator\MockEmbeddingProvider;

#[CoversClass(TelescopeValidateCommand::class)]
final class TelescopeValidateCommandTest extends TestCase
{
    #[Test]
    public function executes_successfully_with_session_id(): void
    {
        $command = new TelescopeValidateCommand(new MockEmbeddingProvider());
        $tester = new CommandTester($command);

        $tester->execute(['session-id' => 'sess-test-001']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('sess-test-001', $tester->getDisplay());
    }

    #[Test]
    public function has_required_session_id_argument(): void
    {
        $command = new TelescopeValidateCommand(new MockEmbeddingProvider());
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasArgument('session-id'));
        self::assertTrue($definition->getArgument('session-id')->isRequired());
    }
}
```

- [ ] **Step 3: Run test**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/Command/Telescope/TelescopeValidateCommandTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add packages/cli/src/Command/Telescope/TelescopeValidateCommand.php packages/cli/tests/Unit/Command/Telescope/TelescopeValidateCommandTest.php
git commit -m "feat(cli): add telescope:validate command with tests"
```

### Task 14b: API Controller for Codified Context

**Files:**
- Create: `packages/api/src/Controller/CodifiedContextController.php`

This task creates the backend API endpoints that the admin SPA consumes. The controller reads from `CodifiedContextStoreInterface` and returns JSON responses.

- [ ] **Step 1: Implement CodifiedContextController**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextStoreInterface;

final class CodifiedContextController
{
    public function __construct(
        private readonly ?CodifiedContextStoreInterface $store = null,
    ) {}

    /** GET /api/telescope/codified-context/sessions */
    public function listSessions(array $query = []): array
    {
        if ($this->store === null) {
            return ['data' => []];
        }

        $limit = (int) ($query['limit'] ?? 50);
        $entries = $this->store->query('cc_session', $limit);

        // Group by session_id, merge start/end data
        $sessions = [];
        foreach ($entries as $entry) {
            $sid = $entry->data['session_id'] ?? '';
            if (!isset($sessions[$sid])) {
                $sessions[$sid] = [
                    'id' => $entry->id,
                    'sessionId' => $sid,
                    'repoHash' => $entry->data['repo_hash'] ?? '',
                    'startedAt' => $entry->createdAt->format('Y-m-d H:i:s.u'),
                    'endedAt' => null,
                    'durationMs' => null,
                    'eventCount' => 0,
                    'latestDriftScore' => null,
                    'latestSeverity' => null,
                ];
            }
            if (($entry->data['action'] ?? '') === 'end') {
                $sessions[$sid]['endedAt'] = $entry->createdAt->format('Y-m-d H:i:s.u');
                $sessions[$sid]['durationMs'] = $entry->data['duration_ms'] ?? null;
                $sessions[$sid]['eventCount'] = $entry->data['event_count'] ?? 0;
            }
        }

        // Enrich with latest drift scores from validation entries
        $validations = $this->store->query('cc_validation', 200);
        foreach ($validations as $v) {
            $sid = $v->data['session_id'] ?? '';
            if (isset($sessions[$sid])) {
                $sessions[$sid]['latestDriftScore'] = $v->data['drift_score'] ?? null;
                $sessions[$sid]['latestSeverity'] = $v->data['recommendation'] ?? null;
            }
        }

        return ['data' => array_values($sessions)];
    }

    /** GET /api/telescope/codified-context/sessions/{sessionId} */
    public function getSession(string $sessionId): array
    {
        if ($this->store === null) {
            return ['data' => null];
        }

        $entries = $this->store->queryBySession($sessionId, 2);
        if (empty($entries)) {
            return ['data' => null];
        }

        $session = [
            'id' => $entries[0]->id,
            'sessionId' => $sessionId,
            'repoHash' => $entries[0]->data['repo_hash'] ?? '',
            'startedAt' => $entries[0]->createdAt->format('Y-m-d H:i:s.u'),
            'endedAt' => null,
            'durationMs' => null,
            'eventCount' => 0,
            'latestDriftScore' => null,
            'latestSeverity' => null,
        ];

        foreach ($entries as $entry) {
            if (($entry->data['action'] ?? '') === 'end') {
                $session['endedAt'] = $entry->createdAt->format('Y-m-d H:i:s.u');
                $session['durationMs'] = $entry->data['duration_ms'] ?? null;
                $session['eventCount'] = $entry->data['event_count'] ?? 0;
            }
        }

        return ['data' => $session];
    }

    /** GET /api/telescope/codified-context/sessions/{sessionId}/events */
    public function getSessionEvents(string $sessionId): array
    {
        if ($this->store === null) {
            return ['data' => []];
        }

        $entries = $this->store->queryBySession($sessionId, 200);
        $events = [];
        foreach ($entries as $entry) {
            if ($entry->type === 'cc_event') {
                $events[] = [
                    'id' => $entry->id,
                    'sessionId' => $entry->data['session_id'] ?? $sessionId,
                    'eventType' => $entry->data['event_type'] ?? 'unknown',
                    'data' => $entry->data,
                    'createdAt' => $entry->createdAt->format('Y-m-d H:i:s.u'),
                ];
            }
        }

        return ['data' => $events];
    }

    /** GET /api/telescope/codified-context/sessions/{sessionId}/validation */
    public function getSessionValidation(string $sessionId): array
    {
        if ($this->store === null) {
            return ['data' => null];
        }

        $entries = $this->store->queryBySession($sessionId, 200);
        foreach (array_reverse($entries) as $entry) {
            if ($entry->type === 'cc_validation') {
                return ['data' => [
                    'sessionId' => $sessionId,
                    'driftScore' => $entry->data['drift_score'] ?? 0,
                    'components' => $entry->data['components'] ?? [],
                    'issues' => $entry->data['issues'] ?? [],
                    'recommendation' => $entry->data['recommendation'] ?? 'low',
                    'validatedAt' => $entry->data['validated_at'] ?? $entry->createdAt->format('Y-m-d H:i:s.u'),
                ]];
            }
        }

        return ['data' => null];
    }
}
```

- [ ] **Step 2: Write test for CodifiedContextController**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\CodifiedContextController;
use Waaseyaa\Telescope\CodifiedContext\Storage\SqliteCodifiedContextStore;

#[CoversClass(CodifiedContextController::class)]
final class CodifiedContextControllerTest extends TestCase
{
    #[Test]
    public function list_sessions_returns_empty_when_no_store(): void
    {
        $controller = new CodifiedContextController(null);
        $result = $controller->listSessions();

        self::assertSame(['data' => []], $result);
    }

    #[Test]
    public function list_sessions_groups_by_session_id(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_session', ['session_id' => 'sess-001', 'repo_hash' => 'abc', 'action' => 'start']);
        $store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'end', 'duration_ms' => 5000.0, 'event_count' => 3]);

        $controller = new CodifiedContextController($store);
        $result = $controller->listSessions();

        self::assertCount(1, $result['data']);
        self::assertSame('sess-001', $result['data'][0]['sessionId']);
        self::assertSame(5000.0, $result['data'][0]['durationMs']);
    }

    #[Test]
    public function get_session_returns_null_for_unknown_session(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $controller = new CodifiedContextController($store);

        $result = $controller->getSession('nonexistent');
        self::assertNull($result['data']);
    }

    #[Test]
    public function get_session_events_filters_to_cc_event_type(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_session', ['session_id' => 'sess-001', 'action' => 'start']);
        $store->store('cc_event', ['session_id' => 'sess-001', 'event_type' => 'context.load']);

        $controller = new CodifiedContextController($store);
        $result = $controller->getSessionEvents('sess-001');

        self::assertCount(1, $result['data']);
        self::assertSame('context.load', $result['data'][0]['eventType']);
    }

    #[Test]
    public function get_session_validation_returns_latest_report(): void
    {
        $store = SqliteCodifiedContextStore::createInMemory();
        $store->store('cc_validation', [
            'session_id' => 'sess-001',
            'drift_score' => 72,
            'components' => ['semantic_alignment' => 45.0, 'structural_checks' => 17.0, 'contradiction_checks' => 10.0],
            'issues' => [],
            'recommendation' => 'medium',
            'validated_at' => '2026-03-12 10:00:00.000000',
        ]);

        $controller = new CodifiedContextController($store);
        $result = $controller->getSessionValidation('sess-001');

        self::assertSame(72, $result['data']['driftScore']);
        self::assertSame('medium', $result['data']['recommendation']);
    }
}
```

- [ ] **Step 3: Run test**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Controller/CodifiedContextControllerTest.php`
Expected: PASS

- [ ] **Step 4: Register API routes**

Add route registration. In the service provider (Task 21) or in the existing `JsonApiRouteProvider`, add these routes:

```php
// Add to the routes() method of the appropriate service provider:
$router->addRoute('telescope_cc_sessions', RouteBuilder::create('/api/telescope/codified-context/sessions')
    ->controller('Waaseyaa\\Api\\Controller\\CodifiedContextController::listSessions')
    ->methods('GET')
    ->requireAuthentication()
    ->build());

$router->addRoute('telescope_cc_session', RouteBuilder::create('/api/telescope/codified-context/sessions/{sessionId}')
    ->controller('Waaseyaa\\Api\\Controller\\CodifiedContextController::getSession')
    ->methods('GET')
    ->requireAuthentication()
    ->build());

$router->addRoute('telescope_cc_session_events', RouteBuilder::create('/api/telescope/codified-context/sessions/{sessionId}/events')
    ->controller('Waaseyaa\\Api\\Controller\\CodifiedContextController::getSessionEvents')
    ->methods('GET')
    ->requireAuthentication()
    ->build());

$router->addRoute('telescope_cc_session_validation', RouteBuilder::create('/api/telescope/codified-context/sessions/{sessionId}/validation')
    ->controller('Waaseyaa\\Api\\Controller\\CodifiedContextController::getSessionValidation')
    ->methods('GET')
    ->requireAuthentication()
    ->build());
```

- [ ] **Step 5: Commit**

```bash
git add packages/api/src/Controller/CodifiedContextController.php packages/api/tests/Unit/Controller/CodifiedContextControllerTest.php
git commit -m "feat(api): add CodifiedContextController with tests and route registration"
```

---

## Chunk 4: Admin SPA Integration (Agent D)

### Task 15: useCodifiedContext Composable

**Files:**
- Create: `packages/admin/app/composables/useCodifiedContext.ts`

- [ ] **Step 1: Create the composable**

```typescript
interface CodifiedContextSession {
  id: string
  sessionId: string
  repoHash: string
  startedAt: string
  endedAt: string | null
  durationMs: number | null
  eventCount: number
  latestDriftScore: number | null
  latestSeverity: string | null
}

interface CodifiedContextEvent {
  id: string
  sessionId: string
  eventType: string
  data: Record<string, unknown>
  createdAt: string
}

interface ValidationReport {
  sessionId: string
  driftScore: number
  components: {
    semantic_alignment: number
    structural_checks: number
    contradiction_checks: number
  }
  issues: Array<{ type: string; message: string; severity: string }>
  recommendation: string
  validatedAt: string
}

export function useCodifiedContext() {
  const sessions = ref<CodifiedContextSession[]>([])
  const currentSession = ref<CodifiedContextSession | null>(null)
  const events = ref<CodifiedContextEvent[]>([])
  const validationReport = ref<ValidationReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchSessions(limit = 50) {
    loading.value = true
    error.value = null
    try {
      const response = await $fetch<{ data: CodifiedContextSession[] }>(
        '/api/telescope/codified-context/sessions',
        { params: { limit } },
      )
      sessions.value = response.data
    } catch (e: any) {
      error.value = e.data?.errors?.[0]?.detail ?? e.message
    } finally {
      loading.value = false
    }
  }

  async function fetchSession(sessionId: string) {
    loading.value = true
    error.value = null
    try {
      const response = await $fetch<{ data: CodifiedContextSession }>(
        `/api/telescope/codified-context/sessions/${sessionId}`,
      )
      currentSession.value = response.data
    } catch (e: any) {
      error.value = e.data?.errors?.[0]?.detail ?? e.message
    } finally {
      loading.value = false
    }
  }

  async function fetchEvents(sessionId: string) {
    loading.value = true
    try {
      const response = await $fetch<{ data: CodifiedContextEvent[] }>(
        `/api/telescope/codified-context/sessions/${sessionId}/events`,
      )
      events.value = response.data
    } catch (e: any) {
      error.value = e.data?.errors?.[0]?.detail ?? e.message
    } finally {
      loading.value = false
    }
  }

  async function fetchValidation(sessionId: string) {
    loading.value = true
    try {
      const response = await $fetch<{ data: ValidationReport }>(
        `/api/telescope/codified-context/sessions/${sessionId}/validation`,
      )
      validationReport.value = response.data
    } catch (e: any) {
      error.value = e.data?.errors?.[0]?.detail ?? e.message
    } finally {
      loading.value = false
    }
  }

  return {
    sessions,
    currentSession,
    events,
    validationReport,
    loading,
    error,
    fetchSessions,
    fetchSession,
    fetchEvents,
    fetchValidation,
  }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/composables/useCodifiedContext.ts
git commit -m "feat(admin): add useCodifiedContext composable"
```

### Task 16: Session List Page

**Files:**
- Create: `packages/admin/app/pages/telescope/codified-context/index.vue`

- [ ] **Step 1: Create session list page**

```vue
<script setup lang="ts">
import { useCodifiedContext } from '~/composables/useCodifiedContext'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const { sessions, loading, error, fetchSessions } = useCodifiedContext()

onMounted(() => fetchSessions())

function severityColor(severity: string | null): string {
  switch (severity) {
    case 'critical': return 'var(--color-error)'
    case 'high': return 'var(--color-warning)'
    case 'medium': return 'var(--color-info)'
    case 'low': return 'var(--color-success)'
    default: return 'var(--color-text-muted)'
  }
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleString()
}
</script>

<template>
  <div class="codified-context-sessions">
    <h1>{{ t('telescope_codified_context') }}</h1>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading">{{ t('loading') }}</p>

    <table v-if="!loading && sessions.length > 0" class="session-table">
      <thead>
        <tr>
          <th>Session</th>
          <th>Repo</th>
          <th>Started</th>
          <th>Duration</th>
          <th>Events</th>
          <th>Drift Score</th>
          <th>Severity</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="session in sessions" :key="session.id">
          <td>
            <NuxtLink :to="`/telescope/codified-context/${session.sessionId}`">
              {{ session.sessionId.substring(0, 12) }}...
            </NuxtLink>
          </td>
          <td>{{ session.repoHash.substring(0, 8) }}</td>
          <td>{{ formatDate(session.startedAt) }}</td>
          <td>{{ session.durationMs ? `${(session.durationMs / 1000).toFixed(1)}s` : '—' }}</td>
          <td>{{ session.eventCount }}</td>
          <td>{{ session.latestDriftScore ?? '—' }}</td>
          <td>
            <span
              v-if="session.latestSeverity"
              class="severity-badge"
              :style="{ backgroundColor: severityColor(session.latestSeverity) }"
            >
              {{ session.latestSeverity }}
            </span>
            <span v-else>—</span>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="!loading && sessions.length === 0">No codified context sessions recorded yet.</p>
  </div>
</template>

<style scoped>
.session-table {
  width: 100%;
  border-collapse: collapse;
}
.session-table th, .session-table td {
  padding: 8px 12px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}
.severity-badge {
  padding: 2px 8px;
  border-radius: 4px;
  color: white;
  font-size: 0.85em;
  text-transform: uppercase;
}
.error {
  color: var(--color-error);
}
</style>
```

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/pages/telescope/codified-context/index.vue
git commit -m "feat(admin): add codified context session list page"
```

### Task 17: Session Detail Page

**Files:**
- Create: `packages/admin/app/pages/telescope/codified-context/[sessionId].vue`
- Create: `packages/admin/app/components/telescope/DriftScoreChart.vue`
- Create: `packages/admin/app/components/telescope/EventStreamViewer.vue`
- Create: `packages/admin/app/components/telescope/ValidationReportCard.vue`
- Create: `packages/admin/app/components/telescope/ContextHeatmap.vue`

- [ ] **Step 1: Create session detail page**

The page uses `useCodifiedContext` to fetch session, events, and validation report. Displays:
- Session metadata header
- DriftScoreChart (simple bar/timeline showing drift score)
- EventStreamViewer (table of events with type, timestamp, details)
- ValidationReportCard (drift score breakdown with component scores)
- ContextHeatmap (grid showing which context files were referenced, colored by frequency)

- [ ] **Step 2: Create DriftScoreChart component**

Simple visual showing the drift score as a colored bar (green ≥75, yellow ≥50, orange ≥25, red <25) with the numeric score.

- [ ] **Step 3: Create EventStreamViewer component**

Table component accepting `events` prop, displaying event_type, timestamp, and expandable data details.

- [ ] **Step 4: Create ValidationReportCard component**

Card showing drift_score, three component scores as progress bars, issues list, and recommendation badge.

- [ ] **Step 5: Create ContextHeatmap component**

Grid of file paths with color intensity based on reference count. Each cell shows filename and count.

- [ ] **Step 6: Commit**

```bash
git add packages/admin/app/pages/telescope/codified-context/ packages/admin/app/components/telescope/
git commit -m "feat(admin): add codified context session detail page and components"
```

### Task 18: i18n Keys

**Files:**
- Modify: `packages/admin/app/i18n/en.json`

- [ ] **Step 1: Add translation keys**

Add these keys to the existing en.json:
```json
{
  "telescope_codified_context": "Codified Context",
  "telescope_cc_sessions": "Sessions",
  "telescope_cc_drift_score": "Drift Score",
  "telescope_cc_severity": "Severity",
  "telescope_cc_validation": "Validation Report",
  "telescope_cc_events": "Event Stream",
  "telescope_cc_heatmap": "Context Heatmap",
  "telescope_cc_no_sessions": "No codified context sessions recorded yet.",
  "telescope_cc_semantic": "Semantic Alignment",
  "telescope_cc_structural": "Structural Checks",
  "telescope_cc_contradictions": "Contradiction Checks"
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/i18n/en.json
git commit -m "feat(admin): add codified context i18n keys"
```

### Task 19: Playwright E2E Tests

**Files:**
- Create: `packages/admin/e2e/telescope-codified-context.spec.ts`

- [ ] **Step 1: Write Playwright tests**

```typescript
import { test, expect } from '@playwright/test'

test.describe('Telescope Codified Context', () => {
  test.beforeEach(async ({ page }) => {
    // Mock API responses
    await page.route('**/api/telescope/codified-context/sessions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: '1',
              sessionId: 'sess-test-001',
              repoHash: 'abc12345',
              startedAt: '2026-03-12T10:00:00.000000',
              endedAt: '2026-03-12T10:05:00.000000',
              durationMs: 300000,
              eventCount: 5,
              latestDriftScore: 72,
              latestSeverity: 'medium',
            },
          ],
        }),
      })
    })

    await page.route('**/api/telescope/codified-context/sessions/sess-test-001', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: '1',
            sessionId: 'sess-test-001',
            repoHash: 'abc12345',
            startedAt: '2026-03-12T10:00:00.000000',
            endedAt: '2026-03-12T10:05:00.000000',
            durationMs: 300000,
            eventCount: 5,
            latestDriftScore: 72,
            latestSeverity: 'medium',
          },
        }),
      })
    })

    await page.route('**/api/telescope/codified-context/sessions/sess-test-001/events', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            { id: 'e1', sessionId: 'sess-test-001', eventType: 'context.load', data: { context_hash: 'h1' }, createdAt: '2026-03-12T10:00:01' },
            { id: 'e2', sessionId: 'sess-test-001', eventType: 'drift.detected', data: { drift_score: 72, severity: 'medium' }, createdAt: '2026-03-12T10:02:00' },
          ],
        }),
      })
    })

    await page.route('**/api/telescope/codified-context/sessions/sess-test-001/validation', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            sessionId: 'sess-test-001',
            driftScore: 72,
            components: { semantic_alignment: 45, structural_checks: 17, contradiction_checks: 10 },
            issues: [{ type: 'missing_file', message: 'File removed', severity: 'medium' }],
            recommendation: 'medium',
            validatedAt: '2026-03-12T10:03:00',
          },
        }),
      })
    })
  })

  test('displays session list', async ({ page }) => {
    await page.goto('/telescope/codified-context')
    await expect(page.locator('h1')).toContainText('Codified Context')
    await expect(page.locator('.session-table tbody tr')).toHaveCount(1)
    await expect(page.locator('.severity-badge')).toContainText('medium')
  })

  test('navigates to session detail', async ({ page }) => {
    await page.goto('/telescope/codified-context')
    await page.click('a[href*="sess-test-001"]')
    await expect(page).toHaveURL(/sess-test-001/)
  })

  test('shows drift score on detail page', async ({ page }) => {
    await page.goto('/telescope/codified-context/sess-test-001')
    await expect(page.locator('text=72')).toBeVisible()
  })

  test('shows validation report', async ({ page }) => {
    await page.goto('/telescope/codified-context/sess-test-001')
    await expect(page.locator('text=Semantic Alignment')).toBeVisible()
    await expect(page.locator('text=medium')).toBeVisible()
  })
})
```

- [ ] **Step 2: Commit**

```bash
git add packages/admin/e2e/telescope-codified-context.spec.ts
git commit -m "test(admin): add Playwright E2E tests for codified context panel"
```

---

## Chunk 5: SSR + Service Provider + Docs (Agent E)

### Task 20: SSR Template + Route

**Files:**
- Create: `packages/ssr/templates/telescope/codified-context-session.html.twig`

- [ ] **Step 1: Create Twig template**

```twig
{% extends "layouts/base.html.twig" %}

{% block title %}Codified Context Session — {{ session.sessionId[:12] }}{% endblock %}

{% block body %}
<div class="telescope-cc-session">
    <h1>Session: {{ session.sessionId }}</h1>

    <div class="session-meta">
        <dl>
            <dt>Repo Hash</dt><dd>{{ session.repoHash }}</dd>
            <dt>Started</dt><dd>{{ session.startedAt }}</dd>
            {% if session.endedAt %}
                <dt>Duration</dt><dd>{{ (session.durationMs / 1000)|number_format(1) }}s</dd>
            {% endif %}
            <dt>Events</dt><dd>{{ session.eventCount }}</dd>
        </dl>
    </div>

    {% if validation %}
    <div class="drift-score">
        <h2>Drift Score: {{ validation.driftScore }}/100</h2>
        <div class="score-bar" style="width: {{ validation.driftScore }}%; background-color: {{ validation.driftScore >= 75 ? '#22c55e' : (validation.driftScore >= 50 ? '#eab308' : (validation.driftScore >= 25 ? '#f97316' : '#ef4444')) }}"></div>

        <h3>Components</h3>
        <ul>
            <li>Semantic Alignment: {{ validation.components.semantic_alignment|number_format(1) }}/60</li>
            <li>Structural Checks: {{ validation.components.structural_checks|number_format(1) }}/20</li>
            <li>Contradiction Checks: {{ validation.components.contradiction_checks|number_format(1) }}/20</li>
        </ul>

        {% if validation.issues|length > 0 %}
        <h3>Issues</h3>
        <ul>
            {% for issue in validation.issues %}
                <li><strong>[{{ issue.severity }}]</strong> {{ issue.message }}</li>
            {% endfor %}
        </ul>
        {% endif %}

        <p>Recommendation: <strong>{{ validation.recommendation }}</strong></p>
    </div>
    {% endif %}

    {% if events|length > 0 %}
    <h2>Event Stream</h2>
    <table>
        <thead><tr><th>Time</th><th>Type</th><th>Details</th></tr></thead>
        <tbody>
        {% for event in events %}
            <tr>
                <td>{{ event.createdAt }}</td>
                <td>{{ event.eventType }}</td>
                <td><pre>{{ event.data|json_encode(constant('JSON_PRETTY_PRINT')) }}</pre></td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
    {% endif %}
</div>
{% endblock %}
```

- [ ] **Step 2: Write Twig render smoke test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Ssr\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversNothing]
final class CodifiedContextTemplateTest extends TestCase
{
    #[Test]
    public function renders_session_detail_with_validation(): void
    {
        $templateSource = file_get_contents(__DIR__ . '/../../templates/telescope/codified-context-session.html.twig');
        // Strip the extends tag for isolated testing
        $templateSource = preg_replace('/\{%\s*extends\s+.*?%\}/', '', $templateSource);

        $twig = new Environment(new ArrayLoader(['session.html.twig' => $templateSource]));
        $html = $twig->render('session.html.twig', [
            'session' => [
                'sessionId' => 'sess-test-001',
                'repoHash' => 'abc123',
                'startedAt' => '2026-03-12 10:00:00',
                'endedAt' => '2026-03-12 10:05:00',
                'durationMs' => 300000,
                'eventCount' => 5,
            ],
            'validation' => [
                'driftScore' => 72,
                'components' => [
                    'semantic_alignment' => 45.0,
                    'structural_checks' => 17.0,
                    'contradiction_checks' => 10.0,
                ],
                'issues' => [['severity' => 'medium', 'message' => 'File removed']],
                'recommendation' => 'medium',
            ],
            'events' => [
                ['createdAt' => '2026-03-12 10:00:01', 'eventType' => 'context.load', 'data' => ['hash' => 'h1']],
            ],
        ]);

        self::assertStringContainsString('sess-test-001', $html);
        self::assertStringContainsString('72', $html);
        self::assertStringContainsString('Semantic Alignment', $html);
        self::assertStringContainsString('context.load', $html);
    }
}
```

- [ ] **Step 3: Run test**

Run: `./vendor/bin/phpunit packages/ssr/tests/Unit/CodifiedContextTemplateTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add packages/ssr/templates/telescope/codified-context-session.html.twig packages/ssr/tests/Unit/CodifiedContextTemplateTest.php
git commit -m "feat(ssr): add codified context session Twig template with render test"
```

### Task 21: Update TelescopeServiceProvider

**Files:**
- Modify: `packages/telescope/src/TelescopeServiceProvider.php`

- [ ] **Step 1: Add codified context recorder getters to TelescopeServiceProvider**

Add to the existing class:
- `private ?CodifiedContextSessionRecorder $ccSessionRecorder = null;`
- `private ?CodifiedContextEventRecorder $ccEventRecorder = null;`
- `private ?CodifiedContextValidationRecorder $ccValidationRecorder = null;`
- `private ?CodifiedContextObserver $ccObserver = null;`
- Add `getCodifiedContextObserver(): ?CodifiedContextObserver` method (creates lazily if `codified_context` recording is enabled)
- Add `config['record']['codified_context']` check (default: true)

- [ ] **Step 2: Write test for new getCodifiedContextObserver getter**

Add to `packages/telescope/tests/Unit/TelescopeServiceProviderTest.php`:

```php
#[Test]
public function returns_codified_context_observer_when_enabled(): void
{
    $provider = new TelescopeServiceProvider([
        'enabled' => true,
        'record' => ['codified_context' => true],
    ]);

    $observer = $provider->getCodifiedContextObserver();

    self::assertInstanceOf(CodifiedContextObserver::class, $observer);
}

#[Test]
public function returns_null_codified_context_observer_when_disabled(): void
{
    $provider = new TelescopeServiceProvider([
        'enabled' => true,
        'record' => ['codified_context' => false],
    ]);

    self::assertNull($provider->getCodifiedContextObserver());
}

#[Test]
public function returns_null_codified_context_observer_when_telescope_disabled(): void
{
    $provider = new TelescopeServiceProvider(['enabled' => false]);

    self::assertNull($provider->getCodifiedContextObserver());
}
```

- [ ] **Step 3: Run all Telescope tests**

Run: `./vendor/bin/phpunit packages/telescope/tests/`
Expected: ALL PASS (existing + new tests)

- [ ] **Step 4: Commit**

```bash
git add packages/telescope/src/TelescopeServiceProvider.php packages/telescope/tests/Unit/TelescopeServiceProviderTest.php
git commit -m "feat(telescope): wire codified context recorders into TelescopeServiceProvider"
```

### Task 22: Documentation

**Files:**
- Create: `docs/telescope/codified-context.md`
- Create: `telescope/codified-context/summary.md`

- [ ] **Step 1: Write developer documentation**

Cover: architecture overview, how to instrument sessions, how to interpret drift scores, adapter configuration (JSONL path, SQLite path, Prometheus endpoint), extending with custom embedding providers.

- [ ] **Step 2: Write summary report**

Cover: design decisions (extending Telescope vs separate module), tradeoffs (in-process Prometheus vs external push), algorithm choices, next steps.

- [ ] **Step 3: Commit**

```bash
git add docs/telescope/codified-context.md telescope/codified-context/summary.md
git commit -m "docs: add codified context observability documentation and summary"
```

### Task 23: Run Full Test Suite

- [ ] **Step 1: Run all Telescope unit tests**

Run: `./vendor/bin/phpunit packages/telescope/tests/`
Expected: ALL PASS

- [ ] **Step 2: Run admin Playwright tests**

Run: `cd packages/admin && npm run test:e2e -- --grep "Codified Context"`
Expected: ALL PASS

- [ ] **Step 3: Run full PHPUnit suite**

Run: `./vendor/bin/phpunit`
Expected: ALL PASS (no regressions)

### Task 24: Branch, Commit, PR

- [ ] **Step 1: Create feature branch** (should already exist from subagent setup)

```bash
git checkout -b feature/telescope-codified-context
```

- [ ] **Step 2: Verify all changes are committed**

```bash
git status
git log --oneline feature/telescope-codified-context ^main
```

- [ ] **Step 3: Push and create PR**

```bash
git push -u origin feature/telescope-codified-context
gh pr create --title "Telescope: Codified Context Observability Integration" --body "$(cat <<'EOF'
## Summary
- Extends Telescope with codified context observability (recorders, store adapters, validator agent, admin UI, SSR)
- Adds 3 new recorders: session, event, validation
- Adds 3 store adapters: JSONL (default), SQLite, Prometheus
- Adds deterministic drift scoring algorithm with structural + contradiction + semantic checks
- Adds Admin SPA panel with session list, detail, drift timeline, and validation reports
- Adds SSR template for session detail rendering

## Test plan
- [ ] PHPUnit tests pass for all new recorders, store adapters, and validator components
- [ ] Playwright E2E tests pass for admin panel navigation
- [ ] No regressions in existing Telescope tests
- [ ] Drift scoring is deterministic (verified by unit test)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Parallelization Guide

| Agent | Tasks | Dependencies |
|-------|-------|-------------|
| A | 1–5 (events, entry, schemas, recorders, observer) | None |
| B | 6–9 (store interface + 3 adapters) | None |
| C | 10–14, 14b (embedding, structural, contradiction, drift scorer, CLI, API controller) | Depends on B for CodifiedContextStoreInterface |
| D | 15–19 (composable, pages, components, i18n, Playwright) | Depends on C for API endpoints (but E2E tests use mocked routes so can start in parallel) |
| E | 20–22 (SSR template, service provider update, docs) | Depends on A for recorder imports |

**Practical parallelism:** Agents A, B, and D can start immediately. D's Playwright tests mock all API routes, so it does not need the real API controller to run. Agent C depends on B's `CodifiedContextStoreInterface` for the API controller (Task 14b). Agent E depends on A for import paths in the service provider update.

**Note:** Recorders type-hint `TelescopeStoreInterface` (the base interface) for writes. The richer `CodifiedContextStoreInterface` (from Agent B) is used only by consumers that need advanced queries (API controller, CLI). This separation is intentional — recorders write, consumers query.
