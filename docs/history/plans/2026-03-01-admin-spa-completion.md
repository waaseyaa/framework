# Admin SPA Completion Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete the remaining admin SPA features: entity autocomplete search, SSE real-time broadcasting, list auto-refresh, and accessibility/design improvements.

**Architecture:** The admin SPA is a Nuxt 3 + Vue 3 app that talks to a PHP JSON:API backend via `/api` proxy. Entity schemas drive all UI rendering. The autocomplete widget needs backend search support (translating `CONTAINS`/`STARTS_WITH` operators to SQL `LIKE`), and the SSE broadcast endpoint needs wiring in `public/index.php` with entity event listeners to push changes. The frontend already has a working `useRealtime` composable — it just needs a backend to connect to and components that react to incoming messages.

**Tech Stack:** Nuxt 3.15, Vue 3.5, TypeScript 5.6, PHP 8.3, SQLite (PDO), Symfony EventDispatcher, PHPUnit 10.5

---

## Task 1: Translate CONTAINS/STARTS_WITH to SQL LIKE in SqlEntityQuery

The `QueryFilter` allows `CONTAINS` and `STARTS_WITH` operators, and `InMemoryEntityQuery` handles them correctly in tests, but `SqlEntityQuery` passes them through to PdoSelect verbatim — generating invalid SQL like `field CONTAINS ?`. This must be fixed before autocomplete can use the existing JSON:API filter endpoint.

**Files:**
- Modify: `packages/entity-storage/src/SqlEntityQuery.php:122-132`
- Test: `packages/entity-storage/tests/Unit/SqlEntityQueryLikeTest.php` (create)

**Step 1: Write the failing test**

Create `packages/entity-storage/tests/Unit/SqlEntityQueryLikeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryLikeTest extends TestCase
{
    private PdoDatabase $database;
    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Entity\ContentEntityBase::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($entityType, $this->database, $dispatcher);
    }

    #[Test]
    public function containsOperatorMatchesSubstring(): void
    {
        $this->storage->save($this->storage->create(['title' => 'Hello World']));
        $this->storage->save($this->storage->create(['title' => 'Goodbye Moon']));
        $this->storage->save($this->storage->create(['title' => 'World Peace']));

        $ids = $this->storage->getQuery()
            ->condition('title', 'World', 'CONTAINS')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function startsWithOperatorMatchesPrefix(): void
    {
        $this->storage->save($this->storage->create(['title' => 'Hello World']));
        $this->storage->save($this->storage->create(['title' => 'Goodbye Moon']));
        $this->storage->save($this->storage->create(['title' => 'Hello Again']));

        $ids = $this->storage->getQuery()
            ->condition('title', 'Hello', 'STARTS_WITH')
            ->execute();

        $this->assertCount(2, $ids);
    }

    #[Test]
    public function containsOperatorIsCaseInsensitive(): void
    {
        $this->storage->save($this->storage->create(['title' => 'Hello World']));
        $this->storage->save($this->storage->create(['title' => 'hello world']));

        $ids = $this->storage->getQuery()
            ->condition('title', 'hello', 'CONTAINS')
            ->execute();

        // SQLite LIKE is case-insensitive for ASCII by default.
        $this->assertCount(2, $ids);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlEntityQueryLikeTest.php`
Expected: FAIL — SQL error because `CONTAINS` is not valid SQL syntax.

**Step 3: Implement CONTAINS/STARTS_WITH translation**

In `packages/entity-storage/src/SqlEntityQuery.php`, replace the condition application block (lines 122–133) with:

```php
        // Apply conditions.
        foreach ($this->conditions as $condition) {
            $operator = strtoupper($condition['operator']);

            if ($operator === 'IS NULL') {
                $select->isNull($condition['field']);
            } elseif ($operator === 'IS NOT NULL') {
                $select->isNotNull($condition['field']);
            } elseif ($operator === 'CONTAINS') {
                $select->condition($condition['field'], '%' . $condition['value'] . '%', 'LIKE');
            } elseif ($operator === 'STARTS_WITH') {
                $select->condition($condition['field'], $condition['value'] . '%', 'LIKE');
            } else {
                $select->condition($condition['field'], $condition['value'], $condition['operator']);
            }
        }
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlEntityQueryLikeTest.php`
Expected: PASS (3 tests, 3 assertions)

**Step 5: Run full test suite to verify no regressions**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All existing tests still pass.

**Step 6: Commit**

```
git add packages/entity-storage/src/SqlEntityQuery.php packages/entity-storage/tests/Unit/SqlEntityQueryLikeTest.php
git commit -m "feat(entity-storage): translate CONTAINS/STARTS_WITH to SQL LIKE"
```

---

## Task 2: Wire SSE broadcast endpoint in public/index.php

The `BroadcastController` and `SseBroadcaster` classes exist but are not wired in the front controller. The frontend `useRealtime` composable already connects to `GET /api/broadcast?channels=admin` — we need to register the route and dispatch to the controller.

**Files:**
- Modify: `public/index.php:40-57` (add use statements), `public/index.php:148-155` (add route), `public/index.php:192-246` (add match arm)

**Step 1: Add use statements to public/index.php**

After line 57 (`use Symfony\Component\Routing\RequestContext;`), add:

```php
use Waaseyaa\Api\Controller\BroadcastController;
use Waaseyaa\Foundation\Broadcasting\SseBroadcaster;
```

**Step 2: Register the broadcast route**

After the entity types route (line 155), add:

```php
// SSE broadcast: GET /api/broadcast
$router->addRoute(
    'api.broadcast',
    RouteBuilder::create('/api/broadcast')
        ->controller('broadcast')
        ->methods('GET')
        ->build(),
);
```

**Step 3: Add the dispatch match arm**

In the `match (true)` block, before the JSON:API controller arm (before the `str_contains($controller, 'JsonApiController')` arm), add:

```php
        // SSE broadcast stream.
        $controller === 'broadcast' => (function () use ($query): never {
            $broadcaster = new SseBroadcaster();
            $broadcastController = new BroadcastController($broadcaster);
            $channels = BroadcastController::parseChannels($query['channels'] ?? 'admin');
            if ($channels === []) {
                $channels = ['admin'];
            }
            foreach ($broadcastController->getHeaders() as $name => $value) {
                header("{$name}: {$value}");
            }
            $broadcastController->stream($channels);
            exit;
        })(),
```

**Step 4: Update the route listing comment at the top of the file**

In the docblock at the top (lines 11–16), add after the entity-types line:

```
 *   GET              /api/broadcast            — SSE real-time broadcast stream
```

**Step 5: Commit**

```
git add public/index.php
git commit -m "feat(api): wire SSE broadcast endpoint in front controller"
```

---

## Task 3: Broadcast entity changes via SQLite-backed polling

The SSE endpoint works but the in-memory `SseBroadcaster` only delivers messages within the same PHP process. For the single-process dev server (`php -S`), we need entity save/delete events to broadcast to any active SSE connections. Since PHP's built-in server is single-threaded, true real-time push isn't possible with in-memory broadcasting — but we can add polling support by writing events to a SQLite table that the SSE loop reads from.

**Files:**
- Create: `packages/api/src/Controller/BroadcastStorage.php`
- Test: `packages/api/tests/Unit/Controller/BroadcastStorageTest.php` (create)

**Step 1: Write the failing test for BroadcastStorage**

Create `packages/api/tests/Unit/Controller/BroadcastStorageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\PdoDatabase;

#[CoversClass(BroadcastStorage::class)]
final class BroadcastStorageTest extends TestCase
{
    private PdoDatabase $database;
    private BroadcastStorage $storage;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
        $this->storage = new BroadcastStorage($this->database);
    }

    #[Test]
    public function pushAndPollReturnsMessages(): void
    {
        $this->storage->push('admin', 'entity.saved', ['type' => 'node', 'id' => '1']);
        $this->storage->push('admin', 'entity.deleted', ['type' => 'node', 'id' => '2']);

        $messages = $this->storage->poll(0);

        $this->assertCount(2, $messages);
        $this->assertSame('entity.saved', $messages[0]['event']);
        $this->assertSame('entity.deleted', $messages[1]['event']);
    }

    #[Test]
    public function pollWithCursorSkipsOlderMessages(): void
    {
        $this->storage->push('admin', 'first', []);
        $messages = $this->storage->poll(0);
        $cursor = $messages[0]['id'];

        $this->storage->push('admin', 'second', []);
        $messages = $this->storage->poll($cursor);

        $this->assertCount(1, $messages);
        $this->assertSame('second', $messages[0]['event']);
    }

    #[Test]
    public function pruneRemovesOldMessages(): void
    {
        $this->storage->push('admin', 'old', []);
        $this->storage->prune(0); // prune everything older than 0 seconds

        $messages = $this->storage->poll(0);
        $this->assertCount(0, $messages);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Controller/BroadcastStorageTest.php`
Expected: FAIL — class not found.

**Step 3: Implement BroadcastStorage**

Create `packages/api/src/Controller/BroadcastStorage.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Database\DatabaseInterface;

/**
 * SQLite-backed message queue for SSE broadcasting.
 *
 * Provides a durable store that decouples the HTTP request that triggers an
 * entity event from the long-lived SSE connection that delivers it. The SSE
 * loop polls this table for new rows since its last cursor.
 */
final class BroadcastStorage
{
    public function __construct(private readonly DatabaseInterface $database)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->database->getPdo()->exec(
            'CREATE TABLE IF NOT EXISTS _broadcast_log ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'channel TEXT NOT NULL,'
            . 'event TEXT NOT NULL,'
            . 'data TEXT NOT NULL DEFAULT \'{}\','
            . 'created_at REAL NOT NULL'
            . ')'
        );
    }

    /**
     * Push a message into the broadcast log.
     */
    public function push(string $channel, string $event, array $data): void
    {
        $stmt = $this->database->getPdo()->prepare(
            'INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$channel, $event, json_encode($data, JSON_THROW_ON_ERROR), microtime(true)]);
    }

    /**
     * Poll for messages newer than the given cursor (last seen row ID).
     *
     * @param int $afterId Return messages with id > $afterId. Pass 0 for all.
     * @param list<string> $channels Filter to specific channels. Empty = all.
     * @return list<array{id: int, channel: string, event: string, data: array, created_at: float}>
     */
    public function poll(int $afterId, array $channels = []): array
    {
        $sql = 'SELECT id, channel, event, data, created_at FROM _broadcast_log WHERE id > ?';
        $params = [$afterId];

        if ($channels !== []) {
            $placeholders = implode(', ', array_fill(0, count($channels), '?'));
            $sql .= " AND channel IN ({$placeholders})";
            $params = array_merge($params, $channels);
        }

        $sql .= ' ORDER BY id ASC LIMIT 100';

        $stmt = $this->database->getPdo()->prepare($sql);
        $stmt->execute($params);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $messages[] = [
                'id' => (int) $row['id'],
                'channel' => $row['channel'],
                'event' => $row['event'],
                'data' => json_decode($row['data'], true),
                'created_at' => (float) $row['created_at'],
            ];
        }

        return $messages;
    }

    /**
     * Remove messages older than $maxAgeSeconds.
     */
    public function prune(int $maxAgeSeconds = 300): void
    {
        $cutoff = microtime(true) - $maxAgeSeconds;
        $stmt = $this->database->getPdo()->prepare(
            'DELETE FROM _broadcast_log WHERE created_at < ?'
        );
        $stmt->execute([$cutoff]);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Controller/BroadcastStorageTest.php`
Expected: PASS (3 tests, 5 assertions)

**Step 5: Commit**

```
git add packages/api/src/Controller/BroadcastStorage.php packages/api/tests/Unit/Controller/BroadcastStorageTest.php
git commit -m "feat(api): add SQLite-backed BroadcastStorage for SSE polling"
```

---

## Task 4: Integrate BroadcastStorage into front controller

Wire entity events to push into BroadcastStorage, and rewrite the broadcast endpoint to poll from it instead of using the in-memory SseBroadcaster.

**Files:**
- Modify: `public/index.php`

**Step 1: Add entity event listener after entity type registration**

In `public/index.php`, after the `foreach ($entityTypes ...)` block (after line 112), add:

```php
// --- Broadcast storage for SSE ------------------------------------------------

$broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($database);

// Push entity lifecycle events into the broadcast log.
$dispatcher->addListener('waaseyaa.entity.post_save', function (object $event) use ($broadcastStorage): void {
    $entity = $event->getEntity();
    $broadcastStorage->push(
        'admin',
        'entity.saved',
        ['entityType' => $entity->getEntityTypeId(), 'id' => (string) ($entity->uuid() ?: $entity->id())],
    );
});

$dispatcher->addListener('waaseyaa.entity.post_delete', function (object $event) use ($broadcastStorage): void {
    $entity = $event->getEntity();
    $broadcastStorage->push(
        'admin',
        'entity.deleted',
        ['entityType' => $entity->getEntityTypeId(), 'id' => (string) ($entity->uuid() ?: $entity->id())],
    );
});
```

**Step 2: Rewrite the broadcast match arm to use BroadcastStorage polling**

Replace the broadcast match arm from Task 2 with:

```php
        // SSE broadcast stream — polls BroadcastStorage for new messages.
        $controller === 'broadcast' => (function () use ($broadcastStorage, $query): never {
            $channels = BroadcastController::parseChannels($query['channels'] ?? 'admin');
            if ($channels === []) {
                $channels = ['admin'];
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            // Send initial connected event.
            echo "event: connected\ndata: " . json_encode(['channels' => $channels]) . "\n\n";
            if (ob_get_level() > 0) { ob_flush(); }
            flush();

            $cursor = 0;
            $lastKeepalive = time();

            while (connection_aborted() === 0) {
                $messages = $broadcastStorage->poll($cursor, $channels);
                foreach ($messages as $msg) {
                    $cursor = $msg['id'];
                    $frame = "event: {$msg['event']}\ndata: " . json_encode($msg) . "\n\n";
                    echo $frame;
                }

                if ($messages !== []) {
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();
                }

                $now = time();
                if (($now - $lastKeepalive) >= 30) {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) { ob_flush(); }
                    flush();
                    $lastKeepalive = $now;
                    // Prune old messages every keepalive cycle.
                    $broadcastStorage->prune(300);
                }

                usleep(500_000); // Poll every 500ms.
            }
            exit;
        })(),
```

**Step 3: Commit**

```
git add public/index.php
git commit -m "feat(api): integrate BroadcastStorage with entity events and SSE endpoint"
```

---

## Task 5: Implement EntityAutocomplete widget

Replace the stub `EntityAutocomplete.vue` with a working autocomplete that searches entities via the existing JSON:API filter endpoint using `STARTS_WITH` on the label field.

**Files:**
- Modify: `packages/admin/app/components/widgets/EntityAutocomplete.vue`
- Modify: `packages/admin/app/composables/useEntity.ts` (add `search` method)
- Modify: `packages/admin/app/composables/useSchema.ts` (add `x-target-type` to interface)
- Modify: `packages/admin/app/i18n/en.json` (add autocomplete keys)

**Step 1: Add search method to useEntity composable**

In `packages/admin/app/composables/useEntity.ts`, add a `search` function before the `return` statement (before line 81):

```typescript
  async function search(
    type: string,
    labelField: string,
    query: string,
    limit: number = 10,
  ): Promise<JsonApiResource[]> {
    if (query.length < 2) return []

    const params = new URLSearchParams()
    params.set(`filter[${labelField}][operator]`, 'STARTS_WITH')
    params.set(`filter[${labelField}][value]`, query)
    params.set('page[limit]', String(limit))
    params.set('sort', labelField)

    const url = `/api/${type}?${params.toString()}`
    const response = await $fetch<JsonApiDocument>(url)
    return (Array.isArray(response.data) ? response.data : []) as JsonApiResource[]
  }
```

Update the return to include `search`:

```typescript
  return { list, get, create, update, remove, search }
```

**Step 2: Add x-target-type to SchemaProperty interface**

In `packages/admin/app/composables/useSchema.ts`, add to the `SchemaProperty` interface (after the `'x-enum-labels'` line):

```typescript
  'x-target-type'?: string
```

**Step 3: Add i18n keys**

In `packages/admin/app/i18n/en.json`, add before the closing `}`:

```json
  "autocomplete_placeholder": "Start typing to search...",
  "autocomplete_no_results": "No results found.",
  "autocomplete_loading": "Searching..."
```

**Step 4: Rewrite EntityAutocomplete.vue**

Replace the full contents of `packages/admin/app/components/widgets/EntityAutocomplete.vue`:

```vue
<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import type { SchemaProperty } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  modelValue: string
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { search } = useEntity()
const { t } = useLanguage()

const inputValue = ref('')
const results = ref<JsonApiResource[]>([])
const showDropdown = ref(false)
const searching = ref(false)
const selectedLabel = ref('')

// Determine target entity type and label field from schema.
const targetType = computed(() => props.schema?.['x-target-type'] ?? 'node')
const labelField = computed(() => 'title') // Label field varies by entity type; default to title.

let debounceTimer: ReturnType<typeof setTimeout> | null = null

// When modelValue changes externally, update display.
watch(() => props.modelValue, (val) => {
  if (val && !selectedLabel.value) {
    selectedLabel.value = val
    inputValue.value = val
  }
}, { immediate: true })

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  inputValue.value = value
  selectedLabel.value = ''

  if (debounceTimer) clearTimeout(debounceTimer)

  if (value.length < 2) {
    results.value = []
    showDropdown.value = false
    return
  }

  debounceTimer = setTimeout(async () => {
    searching.value = true
    try {
      results.value = await search(targetType.value, labelField.value, value)
      showDropdown.value = results.value.length > 0 || value.length >= 2
    } catch {
      results.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

function selectResult(resource: JsonApiResource) {
  const label = resource.attributes[labelField.value] ?? resource.id
  selectedLabel.value = label
  inputValue.value = label
  emit('update:modelValue', resource.id)
  showDropdown.value = false
  results.value = []
}

function onBlur() {
  // Delay to allow click on dropdown item.
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

function onFocus() {
  if (results.value.length > 0) {
    showDropdown.value = true
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    showDropdown.value = false
  }
}

function clear() {
  inputValue.value = ''
  selectedLabel.value = ''
  emit('update:modelValue', '')
  results.value = []
  showDropdown.value = false
}
</script>

<template>
  <div class="field autocomplete-field">
    <label v-if="label" class="field-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <div class="autocomplete-wrapper">
      <input
        type="text"
        :value="inputValue"
        :required="required"
        :disabled="disabled"
        :placeholder="t('autocomplete_placeholder')"
        class="field-input"
        role="combobox"
        :aria-expanded="showDropdown"
        aria-autocomplete="list"
        aria-haspopup="listbox"
        @input="onInput"
        @blur="onBlur"
        @focus="onFocus"
        @keydown="onKeydown"
      />
      <button
        v-if="inputValue"
        type="button"
        class="autocomplete-clear"
        :aria-label="t('delete')"
        @click="clear"
      >&times;</button>
      <div v-if="showDropdown" class="autocomplete-dropdown" role="listbox">
        <div v-if="searching" class="autocomplete-item autocomplete-loading">
          {{ t('autocomplete_loading') }}
        </div>
        <div v-else-if="results.length === 0" class="autocomplete-item autocomplete-empty">
          {{ t('autocomplete_no_results') }}
        </div>
        <button
          v-for="resource in results"
          :key="resource.id"
          type="button"
          class="autocomplete-item"
          role="option"
          @mousedown.prevent="selectResult(resource)"
        >
          {{ resource.attributes[labelField] ?? resource.id }}
        </button>
      </div>
    </div>
    <p v-if="description" class="field-description">{{ description }}</p>
  </div>
</template>

<style scoped>
.autocomplete-wrapper {
  position: relative;
}
.autocomplete-clear {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  font-size: 18px;
  color: var(--color-muted);
  cursor: pointer;
  padding: 0 4px;
  line-height: 1;
}
.autocomplete-clear:hover {
  color: var(--color-text);
}
.autocomplete-dropdown {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-top: none;
  border-radius: 0 0 4px 4px;
  max-height: 200px;
  overflow-y: auto;
  z-index: 100;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.autocomplete-item {
  display: block;
  width: 100%;
  padding: 8px 12px;
  text-align: left;
  border: none;
  background: none;
  font-size: 14px;
  cursor: pointer;
  color: var(--color-text);
  font-family: inherit;
}
.autocomplete-item:hover {
  background: var(--color-bg);
}
.autocomplete-loading,
.autocomplete-empty {
  color: var(--color-muted);
  cursor: default;
  font-style: italic;
}
</style>
```

**Step 5: Commit**

```
git add packages/admin/app/components/widgets/EntityAutocomplete.vue \
       packages/admin/app/composables/useEntity.ts \
       packages/admin/app/composables/useSchema.ts \
       packages/admin/app/i18n/en.json
git commit -m "feat(admin): implement EntityAutocomplete widget with search"
```

---

## Task 6: Add x-target-type to SchemaPresenter for entity_reference fields

The autocomplete widget needs to know which entity type to search. The `SchemaPresenter` should add `x-target-type` to fields with `entity_reference` type.

**Files:**
- Modify: `packages/api/src/Schema/SchemaPresenter.php:258-279`
- Test: `packages/api/tests/Unit/Schema/SchemaPresenterTargetTypeTest.php` (create)

**Step 1: Write the failing test**

Create `packages/api/tests/Unit/Schema/SchemaPresenterTargetTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityType;

#[CoversClass(SchemaPresenter::class)]
final class SchemaPresenterTargetTypeTest extends TestCase
{
    #[Test]
    public function entityReferenceFieldIncludesTargetType(): void
    {
        $presenter = new SchemaPresenter();
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \Waaseyaa\Entity\ContentEntityBase::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schema = $presenter->present($entityType, [
            'author' => [
                'type' => 'entity_reference',
                'label' => 'Author',
                'settings' => ['target_type' => 'user'],
            ],
        ]);

        $this->assertSame('entity_autocomplete', $schema['properties']['author']['x-widget']);
        $this->assertSame('user', $schema['properties']['author']['x-target-type']);
    }

    #[Test]
    public function entityReferenceFieldDefaultsToNoTargetType(): void
    {
        $presenter = new SchemaPresenter();
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \Waaseyaa\Entity\ContentEntityBase::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $schema = $presenter->present($entityType, [
            'related' => [
                'type' => 'entity_reference',
                'label' => 'Related',
            ],
        ]);

        $this->assertSame('entity_autocomplete', $schema['properties']['related']['x-widget']);
        $this->assertArrayNotHasKey('x-target-type', $schema['properties']['related']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Schema/SchemaPresenterTargetTypeTest.php`
Expected: First test FAILS — `x-target-type` key doesn't exist.

**Step 3: Add x-target-type to SchemaPresenter**

In `packages/api/src/Schema/SchemaPresenter.php`, in the `buildFieldSchema` method, after the settings block that handles `max` (around line 278), add:

```php
            // Handle target_type for entity_reference fields.
            if (isset($settings['target_type'])) {
                $schema['x-target-type'] = $settings['target_type'];
            }
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Schema/SchemaPresenterTargetTypeTest.php`
Expected: PASS (2 tests, 4 assertions)

**Step 5: Commit**

```
git add packages/api/src/Schema/SchemaPresenter.php packages/api/tests/Unit/Schema/SchemaPresenterTargetTypeTest.php
git commit -m "feat(api): add x-target-type to entity_reference schema fields"
```

---

## Task 7: Auto-refresh entity lists via SSE

When the SSE stream delivers `entity.saved` or `entity.deleted` events, the entity list should auto-refresh. This wires `useRealtime` into the `SchemaList` component.

**Files:**
- Modify: `packages/admin/app/components/schema/SchemaList.vue`
- Modify: `packages/admin/app/components/layout/AdminShell.vue` (add indicator CSS)
- Modify: `packages/admin/app/i18n/en.json` (add i18n key)

**Step 1: Update SchemaList.vue to react to SSE events**

In `packages/admin/app/components/schema/SchemaList.vue`, add the `useRealtime` import after line 4:

```typescript
import { useRealtime } from '~/composables/useRealtime'
```

After line 21 (`const listError = ref<string | null>(null)`), add:

```typescript
const { messages, connected } = useRealtime(['admin'])
```

After the `onMounted` block (after line 89), add:

```typescript
// Auto-refresh when entity events arrive for this entity type.
watch(messages, (msgs) => {
  if (msgs.length === 0) return
  const latest = msgs[msgs.length - 1]
  if (
    (latest.event === 'entity.saved' || latest.event === 'entity.deleted') &&
    latest.data?.entityType === props.entityType
  ) {
    fetchEntities()
  }
})
```

**Step 2: Add a connection indicator to the template**

In the template, after the pagination `<div>` (after line 136), add:

```html
      <div v-if="connected" class="sse-status" :title="t('realtime_connected')">&#9679;</div>
```

**Step 3: Add i18n key**

In `packages/admin/app/i18n/en.json`, add:

```json
  "realtime_connected": "Real-time updates active"
```

**Step 4: Add CSS for the indicator**

In `packages/admin/app/components/layout/AdminShell.vue`, in the `<style>` block, add:

```css
.sse-status {
  display: inline-block;
  color: #16a34a;
  font-size: 10px;
  margin-left: 8px;
  vertical-align: middle;
  animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
```

**Step 5: Commit**

```
git add packages/admin/app/components/schema/SchemaList.vue \
       packages/admin/app/components/layout/AdminShell.vue \
       packages/admin/app/i18n/en.json
git commit -m "feat(admin): auto-refresh entity lists via SSE real-time events"
```

---

## Task 8: Add keyboard navigation to EntityAutocomplete dropdown

The autocomplete dropdown needs arrow key navigation and Enter to select for accessibility.

**Files:**
- Modify: `packages/admin/app/components/widgets/EntityAutocomplete.vue`

**Step 1: Add keyboard state and handlers**

In the `<script setup>` section of `EntityAutocomplete.vue`, after the `clear()` function, add:

```typescript
const activeIndex = ref(-1)

// Reset active index when results change.
watch(results, () => {
  activeIndex.value = -1
})
```

Replace the existing `onKeydown` function with:

```typescript
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    showDropdown.value = false
    return
  }

  if (!showDropdown.value || results.value.length === 0) return

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, 0)
  } else if (event.key === 'Enter' && activeIndex.value >= 0) {
    event.preventDefault()
    selectResult(results.value[activeIndex.value])
  }
}
```

**Step 2: Update dropdown items to show active state**

In the template, update the result button to include active class:

Replace:
```html
        <button
          v-for="resource in results"
          :key="resource.id"
          type="button"
          class="autocomplete-item"
          role="option"
          @mousedown.prevent="selectResult(resource)"
        >
```

With:
```html
        <button
          v-for="(resource, index) in results"
          :key="resource.id"
          type="button"
          class="autocomplete-item"
          :class="{ 'autocomplete-item--active': index === activeIndex }"
          role="option"
          :aria-selected="index === activeIndex"
          @mousedown.prevent="selectResult(resource)"
        >
```

**Step 3: Add active item CSS**

In the `<style scoped>` block, add:

```css
.autocomplete-item--active {
  background: var(--color-primary);
  color: #fff;
}
```

**Step 4: Commit**

```
git add packages/admin/app/components/widgets/EntityAutocomplete.vue
git commit -m "feat(admin): add keyboard navigation to autocomplete dropdown"
```

---

## Task 9: Improve accessibility across admin SPA

Add ARIA landmarks, skip navigation, focus management on route changes, and proper roles to the main layout and form components.

**Files:**
- Modify: `packages/admin/app/components/layout/AdminShell.vue`
- Modify: `packages/admin/app/components/schema/SchemaForm.vue`
- Modify: `packages/admin/app/components/schema/SchemaList.vue`
- Modify: `packages/admin/app/i18n/en.json`

**Step 1: Add skip-to-content link and ARIA landmarks to AdminShell.vue**

Replace the `<template>` section of `AdminShell.vue` with:

```html
<template>
  <div class="admin-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <header class="topbar" role="banner">
      <NuxtLink to="/" class="topbar-brand">{{ t('app_name') }}</NuxtLink>
    </header>

    <div class="admin-body">
      <aside class="sidebar" role="navigation" :aria-label="t('sidebar_nav')">
        <LayoutNavBuilder />
      </aside>
      <main id="main-content" class="content" role="main">
        <slot />
      </main>
    </div>
  </div>
</template>
```

Add to the `<style>` block:

```css
.skip-link {
  position: absolute;
  top: -100%;
  left: 16px;
  background: var(--color-primary);
  color: #fff;
  padding: 8px 16px;
  border-radius: 0 0 4px 4px;
  z-index: 1000;
  font-size: 14px;
  text-decoration: none;
}
.skip-link:focus {
  top: 0;
}
```

**Step 2: Add aria-live region to SchemaList for status updates**

In `SchemaList.vue`, after the pagination `<div>`, add:

```html
      <div class="sr-only" role="status" aria-live="polite">
        {{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}
      </div>
```

Add to `AdminShell.vue` `<style>`:

```css
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
```

**Step 3: Add aria-label to SchemaForm submit button**

In `SchemaForm.vue`, update the submit button to:

```html
        <button
          type="submit"
          :disabled="saving"
          class="btn btn-primary"
          :aria-label="saving ? t('saving') : (entityId ? t('save') : t('create'))"
        >
```

**Step 4: Add aria-label to delete buttons in SchemaList**

In `SchemaList.vue`, update the delete button to:

```html
              <button
                class="btn btn-sm btn-danger"
                :aria-label="t('delete') + ': ' + (entity.attributes[columns[0]?.[0]] ?? entity.id)"
                @click="deleteEntity(entity)"
              >
```

**Step 5: Add i18n key**

In `packages/admin/app/i18n/en.json`, add:

```json
  "sidebar_nav": "Sidebar navigation"
```

**Step 6: Commit**

```
git add packages/admin/app/components/layout/AdminShell.vue \
       packages/admin/app/components/schema/SchemaForm.vue \
       packages/admin/app/components/schema/SchemaList.vue \
       packages/admin/app/i18n/en.json
git commit -m "feat(admin): improve accessibility with ARIA landmarks and keyboard support"
```

---

## Task 10: Add responsive design and visual polish

Make the admin layout responsive (collapsible sidebar on small screens) and add subtle visual improvements.

**Files:**
- Modify: `packages/admin/app/components/layout/AdminShell.vue`
- Modify: `packages/admin/app/components/layout/NavBuilder.vue`

**Step 1: Add responsive sidebar toggle to AdminShell.vue**

Update the `<script setup>`:

```vue
<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const sidebarOpen = ref(false)

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

// Close sidebar on route change (mobile).
const route = useRoute()
watch(() => route.fullPath, () => {
  sidebarOpen.value = false
})
</script>
```

Update the template to include toggle button and overlay:

```html
<template>
  <div class="admin-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <header class="topbar" role="banner">
      <button class="topbar-toggle" aria-label="Toggle menu" @click="toggleSidebar">
        <span class="topbar-toggle-icon">&#9776;</span>
      </button>
      <NuxtLink to="/" class="topbar-brand">{{ t('app_name') }}</NuxtLink>
    </header>

    <div class="admin-body">
      <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false" />
      <aside
        class="sidebar"
        :class="{ 'sidebar--open': sidebarOpen }"
        role="navigation"
        :aria-label="t('sidebar_nav')"
      >
        <LayoutNavBuilder />
      </aside>
      <main id="main-content" class="content" role="main">
        <slot />
      </main>
    </div>
  </div>
</template>
```

Add responsive CSS to the `<style>` block:

```css
.topbar-toggle {
  display: none;
  background: none;
  border: none;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  padding: 0 8px;
  margin-right: 8px;
}

.sidebar-overlay {
  display: none;
}

@media (max-width: 768px) {
  .topbar-toggle {
    display: inline-flex;
    align-items: center;
  }

  .sidebar {
    position: fixed;
    top: var(--topbar-height);
    left: 0;
    bottom: 0;
    z-index: 50;
    transform: translateX(-100%);
    transition: transform 0.2s ease;
  }

  .sidebar--open {
    transform: translateX(0);
  }

  .sidebar-overlay {
    display: block;
    position: fixed;
    inset: 0;
    top: var(--topbar-height);
    background: rgba(0, 0, 0, 0.3);
    z-index: 40;
  }

  .content {
    padding: 16px;
  }

  .page-header h1 {
    font-size: 20px;
  }

  .entity-table {
    font-size: 13px;
  }
  .entity-table th, .entity-table td {
    padding: 8px;
  }
}
```

**Step 2: Add subtle transitions**

In `AdminShell.vue`, add transition properties to existing classes:

- Add to `.btn`: `transition: background 0.15s, border-color 0.15s;`
- Add to `.field-input`: `transition: border-color 0.15s, box-shadow 0.15s;`

In `NavBuilder.vue`, add to `.nav-item`:
```css
.nav-item { transition: background 0.15s; }
```

**Step 3: Commit**

```
git add packages/admin/app/components/layout/AdminShell.vue \
       packages/admin/app/components/layout/NavBuilder.vue
git commit -m "feat(admin): add responsive sidebar and visual polish"
```

---

## Summary

| Task | Area | What it does |
|------|------|-------------|
| 1 | Backend | CONTAINS/STARTS_WITH to LIKE in SqlEntityQuery |
| 2 | Backend | Wire SSE broadcast route in front controller |
| 3 | Backend | SQLite-backed BroadcastStorage for SSE polling |
| 4 | Backend | Entity event listeners + polling SSE endpoint |
| 5 | Frontend | Full EntityAutocomplete widget with search |
| 6 | Backend | x-target-type in schema for entity_reference fields |
| 7 | Frontend | Auto-refresh entity lists via SSE |
| 8 | Frontend | Keyboard navigation for autocomplete |
| 9 | Frontend | ARIA landmarks, skip nav, focus management |
| 10 | Frontend | Responsive sidebar and visual transitions |

**Dependencies:** Task 1 must be done before Task 5 (autocomplete needs STARTS_WITH). Task 2 must be done before Task 4. Task 3 must be done before Task 4. Task 4 must be done before Task 7. Tasks 5 and 8 are closely related (8 extends 5). Tasks 9 and 10 are independent of other tasks.

**Not in scope (deferred):** File/image upload widgets (needs server-side storage), i18n locales beyond English (mechanical work), frontend unit tests (needs Vitest setup), dark mode.
