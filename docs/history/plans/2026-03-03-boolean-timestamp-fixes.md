# Boolean + Timestamp Fixes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix boolean fields rendering as 1/0 (#18) and timestamps showing as 0 (#19) across API and admin UI.

**Architecture:** Type casting in ResourceSerializer at the API boundary. Timestamp auto-population in SqlEntityStorage::save(). Frontend list formatting in SchemaList.vue.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, Vue 3, Nuxt 3

---

### Task 1: ResourceSerializer — boolean and timestamp casting

**Files:**
- Modify: `packages/api/src/ResourceSerializer.php`
- Test: `packages/api/tests/Unit/ResourceSerializerTest.php`

**Step 1: Write the failing tests**

Add these tests to `ResourceSerializerTest.php`. First update `setUp()` to register an entity type with field definitions, then add the test methods.

In `setUp()`, change the entity type registration to include field definitions:

```php
$this->entityTypeManager->registerEntityType(new EntityType(
    id: 'article',
    label: 'Article',
    class: TestEntity::class,
    keys: [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'title',
        'bundle' => 'type',
    ],
    fieldDefinitions: [
        'status' => ['type' => 'boolean'],
        'promote' => ['type' => 'boolean'],
        'created' => ['type' => 'timestamp'],
        'changed' => ['type' => 'timestamp'],
    ],
));
```

Then add tests:

```php
#[Test]
public function serializeCastsBooleanFieldsToNativeBooleans(): void
{
    $entity = new TestEntity([
        'id' => 1,
        'uuid' => 'uuid-bool',
        'title' => 'Test',
        'status' => 1,
        'promote' => 0,
    ]);

    $resource = $this->serializer->serialize($entity);

    $this->assertTrue($resource->attributes['status']);
    $this->assertFalse($resource->attributes['promote']);
    // Verify they are actual booleans, not integers.
    $this->assertIsBool($resource->attributes['status']);
    $this->assertIsBool($resource->attributes['promote']);
}

#[Test]
public function serializeCastsTimestampFieldsToIso8601(): void
{
    $timestamp = 1709510400; // 2024-03-04T00:00:00+00:00
    $entity = new TestEntity([
        'id' => 1,
        'uuid' => 'uuid-ts',
        'title' => 'Test',
        'created' => $timestamp,
        'changed' => $timestamp,
    ]);

    $resource = $this->serializer->serialize($entity);

    $this->assertIsString($resource->attributes['created']);
    $this->assertStringContainsString('2024-03-04', $resource->attributes['created']);
}

#[Test]
public function serializeCastsZeroTimestampToNull(): void
{
    $entity = new TestEntity([
        'id' => 1,
        'uuid' => 'uuid-ts0',
        'title' => 'Test',
        'created' => 0,
    ]);

    $resource = $this->serializer->serialize($entity);

    $this->assertNull($resource->attributes['created']);
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter 'ResourceSerializerTest::serializeCastsBoolean|ResourceSerializerTest::serializeCastsTimestamp|ResourceSerializerTest::serializeCastsZero'`

Expected: 3 FAIL (attributes contain integers, not booleans/strings/null)

**Step 3: Implement castAttributes in ResourceSerializer**

Add a private method `castAttributes()` and call it from `serialize()`:

```php
public function serialize(
    EntityInterface $entity,
    ?EntityAccessHandler $accessHandler = null,
    ?AccountInterface $account = null,
): JsonApiResource {
    $entityTypeId = $entity->getEntityTypeId();
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    $keys = $entityType->getKeys();

    $resourceId = $entity->uuid() !== '' ? $entity->uuid() : (string) $entity->id();

    $allValues = $entity->toArray();
    $excludedFields = $this->getExcludedFields($keys);
    $attributes = array_diff_key($allValues, array_flip($excludedFields));

    if ($accessHandler !== null && $account !== null) {
        $allowedFields = $accessHandler->filterFields($entity, array_keys($attributes), 'view', $account);
        $attributes = array_intersect_key($attributes, array_flip($allowedFields));
    }

    // Cast attributes based on field definitions.
    $attributes = $this->castAttributes($attributes, $entityType->getFieldDefinitions());

    $selfLink = $this->basePath . '/' . $entityTypeId . '/' . $resourceId;

    return new JsonApiResource(
        type: $entityTypeId,
        id: $resourceId,
        attributes: $attributes,
        links: ['self' => $selfLink],
    );
}

/**
 * Cast attribute values based on field type definitions.
 *
 * @param array<string, mixed> $attributes
 * @param array<string, array<string, mixed>> $fieldDefinitions
 * @return array<string, mixed>
 */
private function castAttributes(array $attributes, array $fieldDefinitions): array
{
    foreach ($attributes as $name => &$value) {
        $type = $fieldDefinitions[$name]['type'] ?? null;

        $value = match ($type) {
            'boolean' => (bool) $value,
            'timestamp', 'datetime' => $this->formatTimestamp($value),
            default => $value,
        };
    }

    return $attributes;
}

/**
 * Convert a Unix timestamp to ISO 8601 string, or null if zero/empty.
 */
private function formatTimestamp(mixed $value): ?string
{
    $ts = (int) $value;
    if ($ts === 0) {
        return null;
    }

    return (new \DateTimeImmutable('@' . $ts))->format(\DateTimeInterface::ATOM);
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter ResourceSerializerTest`

Expected: All tests PASS (existing + new)

**Step 5: Commit**

```bash
git add packages/api/src/ResourceSerializer.php packages/api/tests/Unit/ResourceSerializerTest.php
git commit -m "fix: cast boolean and timestamp fields in API serialization (#18, #19)"
```

---

### Task 2: SqlEntityStorage — timestamp auto-population

**Files:**
- Modify: `packages/entity-storage/src/SqlEntityStorage.php`
- Test: `packages/entity-storage/tests/Unit/SqlEntityStorageTest.php`

**Step 1: Write the failing test**

Add to `SqlEntityStorageTest.php`. First update `setUp()` to include timestamp field definitions:

```php
$this->entityType = new EntityType(
    id: 'test_entity',
    label: 'Test Entity',
    class: TestStorageEntity::class,
    keys: [
        'id' => 'id',
        'uuid' => 'uuid',
        'bundle' => 'bundle',
        'label' => 'label',
        'langcode' => 'langcode',
    ],
    fieldDefinitions: [
        'created' => ['type' => 'timestamp'],
        'changed' => ['type' => 'timestamp'],
    ],
);
```

Then add tests:

```php
public function testSaveNewEntitySetsCreatedTimestamp(): void
{
    $before = time();

    $entity = $this->storage->create([
        'label' => 'Timestamp Test',
        'bundle' => 'page',
        'created' => 0,
        'changed' => 0,
    ]);
    $entity->enforceIsNew();
    $this->storage->save($entity);

    $loaded = $this->storage->load($entity->id());
    $created = (int) $loaded->get('created');
    $changed = (int) $loaded->get('changed');

    $this->assertGreaterThanOrEqual($before, $created);
    $this->assertLessThanOrEqual(time(), $created);
    $this->assertGreaterThanOrEqual($before, $changed);
}

public function testSaveExistingEntityUpdatesChangedTimestamp(): void
{
    $entity = $this->storage->create([
        'label' => 'Update Test',
        'bundle' => 'page',
        'created' => 1000,
        'changed' => 1000,
    ]);
    $entity->enforceIsNew();
    $this->storage->save($entity);

    // Reload and save again.
    $loaded = $this->storage->load($entity->id());
    $loaded->set('label', 'Updated');
    $before = time();
    $this->storage->save($loaded);

    $reloaded = $this->storage->load($entity->id());

    // Created should NOT change on update.
    $this->assertSame(1000, (int) $reloaded->get('created'));
    // Changed should be updated.
    $this->assertGreaterThanOrEqual($before, (int) $reloaded->get('changed'));
}

public function testSaveNewEntityPreservesExplicitCreatedTimestamp(): void
{
    $entity = $this->storage->create([
        'label' => 'Explicit Created',
        'bundle' => 'page',
        'created' => 1700000000,
        'changed' => 0,
    ]);
    $entity->enforceIsNew();
    $this->storage->save($entity);

    $loaded = $this->storage->load($entity->id());

    // Explicit non-zero created should be preserved.
    $this->assertSame(1700000000, (int) $loaded->get('created'));
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter 'SqlEntityStorageTest::testSaveNewEntitySetsCreated|SqlEntityStorageTest::testSaveExistingEntityUpdatesChanged|SqlEntityStorageTest::testSaveNewEntityPreservesExplicit'`

Expected: First two FAIL (timestamps stay as 0 or 1000)

**Step 3: Implement timestamp auto-population in save()**

In `SqlEntityStorage::save()`, add timestamp logic before `splitForStorage()`:

```php
public function save(EntityInterface $entity): int
{
    $isNew = $entity->isNew();

    // Auto-populate timestamp fields.
    $this->populateTimestamps($entity, $isNew);

    $values = $entity->toArray();

    // Split values into schema columns and extra data.
    $dbValues = $this->splitForStorage($values);
    // ... rest of method unchanged
}

/**
 * Auto-populate timestamp fields on save.
 *
 * Sets `created` to current time on new entities (if not already set).
 * Always updates `changed` to current time.
 */
private function populateTimestamps(EntityInterface $entity, bool $isNew): void
{
    $fieldDefs = $this->entityType->getFieldDefinitions();
    $now = time();

    foreach ($fieldDefs as $fieldName => $def) {
        if (($def['type'] ?? null) !== 'timestamp') {
            continue;
        }

        if ($fieldName === 'created' && $isNew && (int) ($entity->get('created') ?? 0) === 0) {
            $entity->set('created', $now);
        } elseif ($fieldName === 'changed') {
            $entity->set('changed', $now);
        }
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter SqlEntityStorageTest`

Expected: All tests PASS

**Step 5: Commit**

```bash
git add packages/entity-storage/src/SqlEntityStorage.php packages/entity-storage/tests/Unit/SqlEntityStorageTest.php
git commit -m "fix: auto-populate created/changed timestamps on entity save (#19)"
```

---

### Task 3: SchemaList.vue — formatted cell rendering

**Files:**
- Modify: `packages/admin/app/components/schema/SchemaList.vue`
- Test: `packages/admin/tests/components/SchemaList.test.ts` (if exists, otherwise manual verification)

**Step 1: Check existing frontend tests**

Run: `ls packages/admin/tests/components/ 2>/dev/null || echo "no component tests dir"`

If test infrastructure exists, write a test. Otherwise skip to implementation.

**Step 2: Add a formatCellValue helper in SchemaList.vue**

In the `<script setup>` section, add a formatting function:

```typescript
function formatCellValue(value: unknown, fieldSchema: Record<string, unknown>): string {
  if (value === null || value === undefined) return ''

  const type = fieldSchema.type as string
  const format = fieldSchema.format as string | undefined

  if (type === 'boolean') {
    return value ? '✓' : '—'
  }

  if (format === 'date-time' && typeof value === 'string') {
    try {
      return new Date(value).toLocaleString()
    } catch {
      return String(value)
    }
  }

  return String(value)
}
```

**Step 3: Update the template to use formatCellValue**

Change line 131-133 from:

```vue
<td v-for="[fieldName] in columns" :key="fieldName">
  {{ entity.attributes[fieldName] ?? '' }}
</td>
```

To:

```vue
<td v-for="[fieldName, fieldSchema] in columns" :key="fieldName">
  {{ formatCellValue(entity.attributes[fieldName], fieldSchema) }}
</td>
```

**Step 4: Verify in browser**

Run: Open `http://localhost:3001/node` and verify:
- "Published" column shows ✓ instead of 1
- "Promoted" / "Sticky" show — instead of 0
- "Authored on" shows a formatted date instead of 0

**Step 5: Commit**

```bash
git add packages/admin/app/components/schema/SchemaList.vue
git commit -m "fix: format boolean and datetime values in entity list (#18, #19)"
```

---

### Task 4: Smoke test and close issues

**Step 1: Run all backend tests**

Run: `./vendor/bin/phpunit`

Expected: All tests PASS

**Step 2: Run frontend tests**

Run: `cd packages/admin && npm test`

Expected: All tests PASS

**Step 3: Browser smoke test**

1. Navigate to `http://localhost:3001/node` — verify boolean columns show ✓/— and dates are formatted
2. Click Edit on "My First Article" — verify checkboxes work (no Vue warnings), datetime fields show dates
3. Create a new node — verify `created`/`changed` are auto-populated after save
4. Navigate to `http://localhost:3001/user` — verify no 500 error

**Step 4: Close issues**

```bash
gh issue close 18 --comment "Fixed: ResourceSerializer casts booleans to native bool, SchemaList formats as ✓/—"
gh issue close 19 --comment "Fixed: SqlEntityStorage auto-populates created/changed, ResourceSerializer formats as ISO 8601, SchemaList formats with toLocaleString()"
```
