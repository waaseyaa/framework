# Config Entity Machine Name Fix — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix config entity creation so string machine name IDs are properly generated, stored, and used as JSON:API resource IDs.

**Architecture:** Six interconnected fixes across the entity lifecycle: EntityBase stops generating spurious UUIDs for config entities, SqlSchemaHandler creates varchar ID columns for config entities, SqlEntityStorage handles string IDs on save, SchemaPresenter exposes the ID as an editable machine_name widget, and the admin SPA auto-generates machine names from labels. The heuristic "has UUID key → content entity, no UUID key → config entity" threads through all layers.

**Tech Stack:** PHP 8.3 (EntityBase, SqlSchemaHandler, SqlEntityStorage, SchemaPresenter), Nuxt 3 + Vue 3 + TypeScript (MachineNameInput widget, SchemaField, SchemaForm)

---

### Task 1: EntityBase — Conditional UUID generation

**Files:**
- Modify: `packages/entity/src/EntityBase.php:58-64`
- Test: `packages/entity/tests/Unit/EntityBaseTest.php`

**Context:** EntityBase currently auto-generates UUID for ALL entities, even config entities that don't define a UUID key. This creates a spurious UUID that pollutes the values array and confuses ResourceSerializer.

**Step 1: Write the failing test**

Add this test to `EntityBaseTest.php`:

```php
public function testNoUuidGeneratedWhenNoUuidKeyDefined(): void
{
    // Config entities define explicit keys WITHOUT 'uuid'.
    $entity = new TestEntity(
        values: ['type' => 'article', 'name' => 'Article'],
        entityKeys: ['id' => 'type', 'label' => 'name'],
    );

    // Should NOT auto-generate a UUID.
    $this->assertSame('', $entity->uuid());
    // The 'uuid' key should NOT exist in the values array.
    $this->assertArrayNotHasKey('uuid', $entity->toArray());
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testNoUuidGeneratedWhenNoUuidKeyDefined packages/entity/tests/Unit/EntityBaseTest.php`
Expected: FAIL — UUID is auto-generated because EntityBase always generates one.

**Step 3: Write minimal implementation**

In `packages/entity/src/EntityBase.php`, replace the UUID auto-generation block (lines 60–64):

```php
// Auto-generate UUID if not provided.
$uuidKey = $this->entityKeys['uuid'] ?? 'uuid';
if (!isset($this->values[$uuidKey]) || $this->values[$uuidKey] === '') {
    $this->values[$uuidKey] = Uuid::v4()->toRfc4122();
}
```

With:

```php
// Auto-generate UUID only when entity type defines a UUID key
// (or has no explicit keys — backward compat for test fixtures).
if ($this->entityKeys === [] || isset($this->entityKeys['uuid'])) {
    $uuidKey = $this->entityKeys['uuid'] ?? 'uuid';
    if (!isset($this->values[$uuidKey]) || $this->values[$uuidKey] === '') {
        $this->values[$uuidKey] = Uuid::v4()->toRfc4122();
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity/tests/Unit/EntityBaseTest.php`
Expected: ALL tests pass — existing tests use `TestEntity` with empty entityKeys (backward compat path), new test verifies config entity behavior.

**Step 5: Commit**

```bash
git add packages/entity/src/EntityBase.php packages/entity/tests/Unit/EntityBaseTest.php
git commit -m "fix: skip UUID auto-generation for entities without UUID key (#27)"
```

---

### Task 2: SqlSchemaHandler — Config entity table schema

**Files:**
- Modify: `packages/entity-storage/src/SqlSchemaHandler.php:121-186`
- Test: `packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php` (may need to create)

**Context:** `SqlSchemaHandler::buildTableSpec()` always creates the ID column as `serial` (auto-increment integer) and always creates a UUID column with a unique key. For config entities (string machine name IDs, no UUID), the ID column should be `varchar` and the UUID column/unique key should be omitted.

**Step 1: Write the failing test**

Create or add to `packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Node\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSchemaHandler::class)]
final class SqlSchemaHandlerTest extends TestCase
{
    #[Test]
    public function configEntityTableUsesVarcharIdAndNoUuidColumn(): void
    {
        $database = PdoDatabase::createSqlite();

        $entityType = new EntityType(
            id: 'node_type',
            label: 'Content Type',
            class: NodeType::class,
            keys: ['id' => 'type', 'label' => 'name'],
        );

        $handler = new SqlSchemaHandler($entityType, $database);
        $handler->ensureTable();

        $schema = $database->schema();

        // ID column should exist.
        $this->assertTrue($schema->fieldExists('node_type', 'type'));

        // UUID column should NOT exist (config entity has no UUID key).
        $this->assertFalse($schema->fieldExists('node_type', 'uuid'));

        // Should be able to insert a string ID (not auto-increment).
        $database->insert('node_type')
            ->fields(['type', 'name', '_data'])
            ->values(['type' => 'article', 'name' => 'Article', '_data' => '{}'])
            ->execute();

        $result = $database->select('node_type')
            ->fields('node_type')
            ->condition('type', 'article')
            ->execute();

        $row = null;
        foreach ($result as $r) {
            $row = (array) $r;
            break;
        }

        $this->assertNotNull($row);
        $this->assertSame('article', $row['type']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter configEntityTableUsesVarcharIdAndNoUuidColumn packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php`
Expected: FAIL — UUID column exists (always created) and/or ID insert fails because column is serial.

**Step 3: Write minimal implementation**

In `packages/entity-storage/src/SqlSchemaHandler.php`, replace the `buildTableSpec()` method body:

```php
private function buildTableSpec(): array
{
    $keys = $this->entityType->getKeys();
    $fields = [];

    $idKey = $keys['id'] ?? 'id';

    // Config entities (class extends ConfigEntityBase) use string IDs.
    // Content entities use auto-increment serial IDs.
    $isConfig = is_subclass_of(
        $this->entityType->getClass(),
        \Waaseyaa\Entity\ConfigEntityBase::class,
    );

    if ($isConfig) {
        $fields[$idKey] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
        ];
    } else {
        $fields[$idKey] = [
            'type' => 'serial',
            'not null' => true,
        ];
    }

    // UUID column — only for entity types that define a UUID key.
    if (isset($keys['uuid'])) {
        $fields[$keys['uuid']] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
            'default' => '',
        ];
    }

    // Bundle column.
    $bundleKey = $keys['bundle'] ?? 'bundle';
    $fields[$bundleKey] = [
        'type' => 'varchar',
        'length' => 128,
        'not null' => true,
        'default' => '',
    ];

    // Label column.
    $labelKey = $keys['label'] ?? 'label';
    $fields[$labelKey] = [
        'type' => 'varchar',
        'length' => 255,
        'not null' => true,
        'default' => '',
    ];

    // Langcode column.
    $langcodeKey = $keys['langcode'] ?? 'langcode';
    $fields[$langcodeKey] = [
        'type' => 'varchar',
        'length' => 12,
        'not null' => true,
        'default' => 'en',
    ];

    // Data blob for extra/dynamic fields (JSON-encoded).
    $fields['_data'] = [
        'type' => 'text',
        'not null' => true,
        'default' => '{}',
    ];

    $spec = [
        'fields' => $fields,
        'primary key' => [$idKey],
    ];

    // UUID unique key — only when UUID column exists.
    if (isset($keys['uuid'])) {
        $spec['unique keys'] = [
            $this->tableName . '_uuid' => [$keys['uuid']],
        ];
    }

    // Bundle index.
    $spec['indexes'] = [
        $this->tableName . '_bundle' => [$bundleKey],
    ];

    return $spec;
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/`
Expected: ALL tests pass — existing tests use content entities with UUID keys, new test verifies config entity behavior.

**Step 5: Commit**

```bash
git add packages/entity-storage/src/SqlSchemaHandler.php packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php
git commit -m "fix: use varchar ID and skip UUID column for config entity tables (#27)"
```

---

### Task 3: SqlEntityStorage — Config entity save support

**Files:**
- Modify: `packages/entity-storage/src/SqlEntityStorage.php:53-58,126-149`
- Test: `packages/entity-storage/tests/Unit/SqlEntityStorageTest.php`

**Context:** Two bugs in SqlEntityStorage affect config entities: (1) `create()` doesn't call `enforceIsNew()`, so entities with pre-set string IDs aren't treated as new; (2) `save()` always casts the returned insert ID to `(int)`, overwriting string IDs with 0.

**Step 1: Write the failing test**

Add to `packages/entity-storage/tests/Unit/SqlEntityStorageTest.php`. This test needs a config entity type, so add a helper and test. First, create a test fixture at `packages/entity-storage/tests/Fixtures/TestConfigEntity.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Test config entity class for storage tests.
 */
final class TestConfigEntity extends ConfigEntityBase
{
    protected string $entityTypeId = 'test_config';

    protected array $entityKeys = [
        'id' => 'type',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

Then add the test to `SqlEntityStorageTest.php`:

```php
public function testSaveAndLoadConfigEntityWithStringId(): void
{
    // Set up config entity type and storage.
    $configEntityType = new EntityType(
        id: 'test_config',
        label: 'Test Config',
        class: \Waaseyaa\EntityStorage\Tests\Fixtures\TestConfigEntity::class,
        keys: ['id' => 'type', 'label' => 'name'],
    );

    $configSchemaHandler = new SqlSchemaHandler($configEntityType, $this->database);
    $configSchemaHandler->ensureTable();

    $configStorage = new SqlEntityStorage(
        $configEntityType,
        $this->database,
        $this->eventDispatcher,
    );

    // Create entity with a string machine name ID.
    $entity = $configStorage->create([
        'type' => 'article',
        'name' => 'Article',
    ]);

    // Entity should be treated as new despite having a pre-set ID.
    $this->assertTrue($entity->isNew());

    // Save should INSERT (not UPDATE).
    $result = $configStorage->save($entity);
    $this->assertSame(EntityConstants::SAVED_NEW, $result);

    // String ID should be preserved (not cast to int).
    $this->assertSame('article', $entity->id());

    // Load by string ID should work.
    $loaded = $configStorage->load('article');
    $this->assertNotNull($loaded);
    $this->assertSame('article', $loaded->id());
    $this->assertSame('Article', $loaded->label());
    $this->assertFalse($loaded->isNew());
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testSaveAndLoadConfigEntityWithStringId packages/entity-storage/tests/Unit/SqlEntityStorageTest.php`
Expected: FAIL — `isNew()` returns false for pre-set ID, and/or ID gets cast to int after insert.

**Step 3: Write minimal implementation**

In `packages/entity-storage/src/SqlEntityStorage.php`, fix `create()` (line 53–58):

```php
public function create(array $values = []): EntityInterface
{
    $class = $this->entityType->getClass();
    $entity = $this->instantiateEntity($class, $values);

    // Newly created entities are always new, even with pre-set IDs.
    if (method_exists($entity, 'enforceIsNew')) {
        $entity->enforceIsNew();
    }

    return $entity;
}
```

In `save()`, fix the post-insert ID handling (lines 136–144):

```php
if ($isNew) {
    $originalId = $entity->id();

    // Remove id key if null (auto-increment will handle it).
    $insertValues = [];
    foreach ($dbValues as $key => $value) {
        if ($key === $this->idKey && $value === null) {
            continue;
        }
        $insertValues[$key] = $value;
    }

    $id = $this->database->insert($this->tableName)
        ->fields(array_keys($insertValues))
        ->values($insertValues)
        ->execute();

    // Set auto-generated ID only when it was null (auto-increment).
    // Config entities with pre-set string IDs keep their original value.
    if ($originalId === null && method_exists($entity, 'set')) {
        $entity->set($this->idKey, (int) $id);
    }

    // Mark entity as no longer new.
    if (method_exists($entity, 'enforceIsNew')) {
        $entity->enforceIsNew(false);
    }

    $result = EntityConstants::SAVED_NEW;
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlEntityStorageTest.php`
Expected: ALL tests pass — existing content entity tests unchanged, new config entity test passes.

**Step 5: Commit**

```bash
git add packages/entity-storage/src/SqlEntityStorage.php packages/entity-storage/tests/Unit/SqlEntityStorageTest.php packages/entity-storage/tests/Fixtures/TestConfigEntity.php
git commit -m "fix: support config entity string IDs in SqlEntityStorage (#27)"
```

---

### Task 4: SchemaPresenter — Machine name widget for config entity IDs

**Files:**
- Modify: `packages/api/src/Schema/SchemaPresenter.php:195-206`
- Test: `packages/api/tests/Unit/Schema/SchemaPresenterTest.php`

**Context:** `SchemaPresenter::buildSystemProperties()` always renders the ID field as `readOnly: true, x-widget: 'hidden'`. For config entities (no UUID key), the ID should be rendered as `x-widget: 'machine_name'`, type `string`, NOT readOnly. It should include `x-source-field` pointing to the label key name so the frontend knows which field to auto-generate from.

**Step 1: Write the failing test**

Add to `SchemaPresenterTest.php`:

```php
#[Test]
public function presentConfigEntityIdAsMachineNameWidget(): void
{
    // Config entity: has keys but NO uuid key.
    $entityType = $this->createEntityType(keys: [
        'id' => 'type',
        'label' => 'name',
    ]);

    $schema = $this->presenter->present($entityType);
    $properties = $schema['properties'];

    // ID field should be a machine_name widget (not hidden).
    $this->assertArrayHasKey('type', $properties);
    $this->assertSame('string', $properties['type']['type']);
    $this->assertSame('machine_name', $properties['type']['x-widget']);
    $this->assertSame('Machine name', $properties['type']['x-label']);
    $this->assertSame('name', $properties['type']['x-source-field']);
    // Should NOT be readOnly in schema (widget handles edit-mode disabling).
    $this->assertArrayNotHasKey('readOnly', $properties['type']);

    // UUID should NOT be present (no uuid key).
    $this->assertArrayNotHasKey('uuid', $properties);
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter presentConfigEntityIdAsMachineNameWidget packages/api/tests/Unit/Schema/SchemaPresenterTest.php`
Expected: FAIL — ID field is `hidden` + `readOnly` + `integer`.

**Step 3: Write minimal implementation**

In `packages/api/src/Schema/SchemaPresenter.php`, replace the `buildSystemProperties()` method:

```php
private function buildSystemProperties(array $keys, EntityTypeInterface $entityType): array
{
    $properties = [];
    $hasUuidKey = isset($keys['uuid']);

    if (isset($keys['id'])) {
        if ($hasUuidKey) {
            // Content entity: integer auto-increment, hidden.
            $properties[$keys['id']] = [
                'type' => 'integer',
                'description' => 'The primary identifier.',
                'readOnly' => true,
                'x-widget' => 'hidden',
            ];
        } else {
            // Config entity: string machine name, editable.
            $properties[$keys['id']] = [
                'type' => 'string',
                'description' => 'The machine name identifier.',
                'x-widget' => 'machine_name',
                'x-label' => 'Machine name',
                'x-weight' => -1,
            ];
            // Tell the widget which field to auto-generate from.
            if (isset($keys['label'])) {
                $properties[$keys['id']]['x-source-field'] = $keys['label'];
            }
        }
    }

    if ($hasUuidKey) {
        $properties[$keys['uuid']] = [
            'type' => 'string',
            'format' => 'uuid',
            'description' => 'The universally unique identifier.',
            'readOnly' => true,
            'x-widget' => 'hidden',
        ];
    }

    if (isset($keys['label'])) {
        $properties[$keys['label']] = [
            'type' => 'string',
            'description' => sprintf('The %s label.', $entityType->getLabel()),
            'x-widget' => 'text',
            'x-label' => 'Title',
        ];
    }

    if (isset($keys['bundle'])) {
        $properties[$keys['bundle']] = [
            'type' => 'string',
            'description' => 'The entity bundle.',
            'x-widget' => 'hidden',
        ];
    }

    if (isset($keys['langcode']) && $entityType->isTranslatable()) {
        $properties[$keys['langcode']] = [
            'type' => 'string',
            'description' => 'The language code.',
            'x-widget' => 'select',
            'x-label' => 'Language',
        ];
    }

    return $properties;
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Schema/SchemaPresenterTest.php`
Expected: ALL tests pass. Some existing tests use entity types WITH uuid keys (unchanged behavior). The new test verifies config entity behavior.

Note: The existing test `presentIncludesSystemProperties` uses keys with 'uuid', so the ID is still `integer`/`hidden`/`readOnly`. Verify this test still passes unchanged.

**Step 5: Commit**

```bash
git add packages/api/src/Schema/SchemaPresenter.php packages/api/tests/Unit/Schema/SchemaPresenterTest.php
git commit -m "fix: render config entity ID as machine_name widget in schema (#27)"
```

---

### Task 5: Frontend — MachineNameInput widget + integration

**Files:**
- Create: `packages/admin/app/components/widgets/MachineNameInput.vue`
- Modify: `packages/admin/app/components/schema/SchemaField.vue:18-33`
- Modify: `packages/admin/app/components/schema/SchemaForm.vue:20,25-26`
- Modify: `packages/admin/app/composables/useSchema.ts:3-21`
- Test: `packages/admin/tests/components/schema/SchemaField.test.ts`

**Context:** The admin SPA needs a `machine_name` widget that auto-generates a URL-safe slug from another field's value. SchemaForm needs to provide form data and edit mode context so the widget can read the source field and disable itself in edit mode.

**Step 1: Add `x-source-field` to SchemaProperty interface**

In `packages/admin/app/composables/useSchema.ts`, add the property to the `SchemaProperty` interface:

```typescript
export interface SchemaProperty {
  type: string
  description?: string
  format?: string
  readOnly?: boolean
  enum?: string[]
  minimum?: number
  maximum?: number
  maxLength?: number
  'x-widget'?: string
  'x-label'?: string
  'x-description'?: string
  'x-weight'?: number
  'x-required'?: boolean
  'x-enum-labels'?: Record<string, string>
  'x-target-type'?: string
  'x-access-restricted'?: boolean
  'x-source-field'?: string
  default?: string | number | boolean
}
```

**Step 2: Create MachineNameInput.vue widget**

Create `packages/admin/app/components/widgets/MachineNameInput.vue`:

```vue
<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  modelValue: string
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const formData = inject<Ref<Record<string, any>>>('formData', ref({}))
const isEditMode = inject<boolean>('isEditMode', false)

const sourceField = computed(() => props.schema?.['x-source-field'] ?? '')

// Track whether user has manually edited the machine name.
const userEdited = ref(false)

// Auto-generate from source field when not manually edited and in create mode.
watch(
  () => sourceField.value ? formData.value[sourceField.value] : '',
  (newLabel) => {
    if (!userEdited.value && !isEditMode && typeof newLabel === 'string') {
      const slug = newLabel
        .toLowerCase()
        .replace(/[\s-]+/g, '_')
        .replace(/[^a-z0-9_]/g, '')
      emit('update:modelValue', slug)
    }
  },
)

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  userEdited.value = true
  emit('update:modelValue', value)
}
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <input
      type="text"
      :value="modelValue"
      :required="required"
      :disabled="disabled || isEditMode"
      pattern="[a-z0-9_]+"
      class="field-input"
      @input="onInput"
    />
    <p v-if="description" class="field-description">{{ description }}</p>
  </div>
</template>
```

**Step 3: Register the widget in SchemaField.vue**

In `packages/admin/app/components/schema/SchemaField.vue`, add `machine_name` to the widget map (after the `hidden` entry):

```typescript
const widgetMap: Record<string, Component> = {
  text: resolveComponent('WidgetsTextInput') as Component,
  email: resolveComponent('WidgetsTextInput') as Component,
  url: resolveComponent('WidgetsTextInput') as Component,
  textarea: resolveComponent('WidgetsTextArea') as Component,
  richtext: resolveComponent('WidgetsRichText') as Component,
  number: resolveComponent('WidgetsNumberInput') as Component,
  boolean: resolveComponent('WidgetsToggle') as Component,
  select: resolveComponent('WidgetsSelect') as Component,
  datetime: resolveComponent('WidgetsDateTimeInput') as Component,
  entity_autocomplete: resolveComponent('WidgetsEntityAutocomplete') as Component,
  hidden: resolveComponent('WidgetsHiddenField') as Component,
  machine_name: resolveComponent('WidgetsMachineNameInput') as Component,
  password: resolveComponent('WidgetsTextInput') as Component,
  image: resolveComponent('WidgetsTextInput') as Component,
  file: resolveComponent('WidgetsTextInput') as Component,
}
```

**Step 4: Provide form context from SchemaForm.vue**

In `packages/admin/app/components/schema/SchemaForm.vue`, add `provide()` calls after `formData` is declared (around line 20-26):

```typescript
const formData = ref<Record<string, any>>({})
const saving = ref(false)
const loadError = ref<string | null>(null)

// Provide form context for widgets that need cross-field access (e.g., MachineNameInput).
provide('formData', formData)
provide('isEditMode', !!props.entityId)
```

Add the `provide` import to the script — Nuxt auto-imports it, so no explicit import needed.

**Step 5: Write the failing test**

Add to `packages/admin/tests/components/schema/SchemaField.test.ts`:

```typescript
it('renders a machine name input for x-widget: machine_name', async () => {
  const wrapper = await mountSuspended(SchemaField, {
    props: {
      name: 'type',
      modelValue: '',
      schema: makeSchema('machine_name', { 'x-source-field': 'name' }),
    },
  })
  expect(wrapper.find('input[type="text"]').exists()).toBe(true)
  expect(wrapper.find('input[type="text"]').attributes('pattern')).toBe('[a-z0-9_]+')
})
```

**Step 6: Run tests**

Run: `cd packages/admin && npm test`
Expected: ALL tests pass including the new machine_name widget test.

**Step 7: Commit**

```bash
git add packages/admin/app/components/widgets/MachineNameInput.vue packages/admin/app/components/schema/SchemaField.vue packages/admin/app/components/schema/SchemaForm.vue packages/admin/app/composables/useSchema.ts packages/admin/tests/components/schema/SchemaField.test.ts
git commit -m "feat: add machine_name widget for config entity IDs (#27)"
```

---

### Task 6: Smoke test

**Precondition:** Delete existing SQLite database to start fresh (table schemas have changed).

**Step 1: Run all backend tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: ALL tests pass.

**Step 2: Run all frontend tests**

Run: `cd packages/admin && npm test`
Expected: ALL tests pass.

**Step 3: Start the dev server**

Run: `rm -f waaseyaa.sqlite && php -S localhost:8000 -t public public/index.php` (in background)
Run: `cd packages/admin && npm run dev` (in background)

**Step 4: Browser smoke test — create a content type**

1. Navigate to `http://localhost:3000/node_type`
2. Verify heading shows "Content Type" (not "node_type")
3. Click "Create new"
4. Verify heading shows "Create Content Type"
5. Type "Blog Post" in the Title/Name field
6. Verify Machine name field auto-fills with `blog_post`
7. Click Create
8. Verify redirect to `/node_type/blog_post` (edit page)
9. Verify heading shows "Edit Content Type #blog_post"
10. Verify entity loads (no "Entity not found" error)
11. Navigate back to `/node_type` list
12. Verify "Blog Post" appears in the list

**Step 5: Browser smoke test — verify content entities still work**

1. Navigate to `/node`
2. Click "Create new"
3. Fill in title, click Create
4. Verify redirect to edit page, entity loads correctly

**Step 6: Close #27**

Close GitHub issue #27 with a comment summarizing the fix.

**Step 7: Update roadmap and commit**

Add entry to `docs/roadmap.md` under Admin SPA section and commit.
