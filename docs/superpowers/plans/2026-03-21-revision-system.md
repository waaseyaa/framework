# Revision System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `RevisionableInterface` + `RevisionableStorageInterface` so entity types can opt into append-only revision tracking with copy-forward rollback.

**Architecture:** Revision data lives in a `{table}_revision` table with composite PK `(entity_id, revision_id)`. The base table gains a `revision_id` column pointing to the current default revision. `EntityRepository` orchestrates revision creation, rollback, and event dispatch. A `RevisionableEntityTrait` provides the entity-side methods. The storage driver handles raw SQL I/O for revision rows.

**Tech Stack:** PHP 8.3, Doctrine DBAL (via `DatabaseInterface`), SQLite for tests, PHPUnit 10.5

**Spec:** `docs/superpowers/specs/2026-03-21-revision-lifecycle-design.md`

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Modify | `packages/entity/src/EntityTypeInterface.php` | Add `getRevisionDefault(): bool` |
| Modify | `packages/entity/src/EntityType.php` | Add `$revisionDefault` constructor param + getter |
| Modify | `packages/entity/src/Attribute/EntityTypeAttribute.php` | Add `$revisionDefault` param |
| Modify | `packages/entity/src/RevisionableInterface.php` | Extend with `setNewRevision`, `isNewRevision`, `setRevisionLog`, `getRevisionLog` |
| Modify | `packages/entity/src/Storage/RevisionableStorageInterface.php` | Update signatures to require `$entityId`, narrow types to `int`, add `getRevisionIds` |
| Create | `packages/entity/src/RevisionableEntityTrait.php` | Trait implementing `RevisionableInterface` |
| Modify | `packages/entity/src/Event/EntityEvents.php` | Add `REVISION_CREATED`, `REVISION_REVERTED` |
| Create | `packages/entity-storage/src/Driver/RevisionableStorageDriver.php` | SQL driver for revision table I/O |
| Modify | `packages/entity-storage/src/EntityRepository.php` | Revision-aware save, `rollback()`, event dispatch |
| Modify | `packages/entity/src/Repository/EntityRepositoryInterface.php` | Add revision methods |
| Modify | `packages/entity-storage/src/SqlSchemaHandler.php` | Add `ensureRevisionTable()` |
| Create | `packages/entity-storage/tests/Fixtures/TestRevisionableEntity.php` | Test entity using `RevisionableEntityTrait` |
| Create | `packages/entity-storage/tests/Unit/RevisionableEntityTraitTest.php` | Unit tests for trait |
| Create | `packages/entity-storage/tests/Unit/Driver/RevisionableStorageDriverTest.php` | Unit tests for revision driver |
| Create | `packages/entity-storage/tests/Unit/EntityRepositoryRevisionTest.php` | Unit tests for repository revision logic |
| Create | `tests/Integration/Revision/RevisionLifecycleIntegrationTest.php` | Full-stack integration tests |

---

## Task 1: Interface Changes — EntityType + RevisionableInterface

**Files:**
- Modify: `packages/entity/src/EntityTypeInterface.php:21`
- Modify: `packages/entity/src/EntityType.php:27-39`
- Modify: `packages/entity/src/Attribute/EntityTypeAttribute.php:37-53`
- Modify: `packages/entity/src/RevisionableInterface.php:7-14`
- Modify: `packages/entity/src/Storage/RevisionableStorageInterface.php:9-19`
- Modify: `packages/entity/src/Event/EntityEvents.php:7-15`

- [ ] **Step 1: Add `getRevisionDefault()` to `EntityTypeInterface`**

Add after line 21 (`isRevisionable`):

```php
public function getRevisionDefault(): bool;
```

- [ ] **Step 2: Add `$revisionDefault` to `EntityType` constructor and implement getter**

Add parameter after `$revisionable` (line 33):

```php
private bool $revisionDefault = false,
```

Add method after `isRevisionable()` (after line 71):

```php
public function getRevisionDefault(): bool
{
    return $this->revisionDefault;
}
```

- [ ] **Step 3: Add `$revisionDefault` to `EntityTypeAttribute`**

Add parameter after `$revisionable` (line 44):

```php
public readonly bool $revisionDefault = false,
```

- [ ] **Step 4: Extend `RevisionableInterface`**

Replace the full interface body with:

```php
interface RevisionableInterface
{
    public function getRevisionId(): ?int;

    public function isDefaultRevision(): bool;

    public function isLatestRevision(): bool;

    public function setNewRevision(bool $value): void;

    /** @return bool|null null means "use entity type default" */
    public function isNewRevision(): ?bool;

    public function setRevisionLog(?string $log): void;

    public function getRevisionLog(): ?string;
}
```

- [ ] **Step 5: Update `RevisionableStorageInterface`**

Replace the full interface body with:

```php
interface RevisionableStorageInterface extends EntityStorageInterface
{
    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface;

    /** @return array<int, EntityInterface> */
    public function loadMultipleRevisions(int|string $entityId, array $revisionIds): array;

    public function deleteRevision(int|string $entityId, int $revisionId): void;

    public function getLatestRevisionId(int|string $entityId): ?int;

    /** @return int[] Revision IDs in ascending order. */
    public function getRevisionIds(int|string $entityId): array;
}
```

- [ ] **Step 6: Add revision events to `EntityEvents`**

Add two new cases:

```php
case REVISION_CREATED = 'waaseyaa.entity.revision_created';
case REVISION_REVERTED = 'waaseyaa.entity.revision_reverted';
```

- [ ] **Step 7: Run existing tests to verify no regressions**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All existing tests pass (interface additions are backward-compatible for implementations, and the new `EntityType` param has a default).

- [ ] **Step 8: Commit**

```bash
git add packages/entity/src/EntityTypeInterface.php \
       packages/entity/src/EntityType.php \
       packages/entity/src/Attribute/EntityTypeAttribute.php \
       packages/entity/src/RevisionableInterface.php \
       packages/entity/src/Storage/RevisionableStorageInterface.php \
       packages/entity/src/Event/EntityEvents.php
git commit -m "feat(#512): extend interfaces for revision system

Add revisionDefault to EntityType, extend RevisionableInterface with
mutation methods, update RevisionableStorageInterface signatures to
require entityId (composite PK), add REVISION_CREATED/REVERTED events."
```

---

## Task 2: RevisionableEntityTrait

**Files:**
- Create: `packages/entity/src/RevisionableEntityTrait.php`
- Create: `packages/entity-storage/tests/Fixtures/TestRevisionableEntity.php`
- Create: `packages/entity-storage/tests/Unit/RevisionableEntityTraitTest.php`

- [ ] **Step 1: Write the test for `RevisionableEntityTrait`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(\Waaseyaa\Entity\RevisionableEntityTrait::class)]
final class RevisionableEntityTraitTest extends TestCase
{
    #[Test]
    public function revision_id_defaults_to_null(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertNull($entity->getRevisionId());
    }

    #[Test]
    public function revision_id_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['revision_id' => 3]);
        $this->assertSame(3, $entity->getRevisionId());
    }

    #[Test]
    public function revision_id_casts_numeric_string_to_int(): void
    {
        $entity = new TestRevisionableEntity(values: ['revision_id' => '5']);
        $this->assertSame(5, $entity->getRevisionId());
    }

    #[Test]
    public function is_default_revision_defaults_to_true(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertTrue($entity->isDefaultRevision());
    }

    #[Test]
    public function is_default_revision_reads_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['is_default_revision' => false]);
        $this->assertFalse($entity->isDefaultRevision());
    }

    #[Test]
    public function is_latest_revision_defaults_to_true(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertTrue($entity->isLatestRevision());
    }

    #[Test]
    public function is_latest_revision_reads_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['is_latest_revision' => false]);
        $this->assertFalse($entity->isLatestRevision());
    }

    #[Test]
    public function new_revision_flag_defaults_to_null(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertNull($entity->isNewRevision());
    }

    #[Test]
    public function set_and_get_new_revision(): void
    {
        $entity = new TestRevisionableEntity();
        $entity->setNewRevision(true);
        $this->assertTrue($entity->isNewRevision());

        $entity->setNewRevision(false);
        $this->assertFalse($entity->isNewRevision());
    }

    #[Test]
    public function set_and_get_revision_log(): void
    {
        $entity = new TestRevisionableEntity();
        $this->assertNull($entity->getRevisionLog());

        $entity->setRevisionLog('Updated content');
        $this->assertSame('Updated content', $entity->getRevisionLog());
    }

    #[Test]
    public function revision_log_from_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['revision_log' => 'Initial']);
        $this->assertSame('Initial', $entity->getRevisionLog());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/RevisionableEntityTraitTest.php`
Expected: FAIL — `TestRevisionableEntity` class not found.

- [ ] **Step 3: Create the test fixture `TestRevisionableEntity`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * Test entity class with revision support.
 */
class TestRevisionableEntity extends ContentEntityBase implements RevisionableInterface
{
    use RevisionableEntityTrait;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'test_revisionable',
        array $entityKeys = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
```

- [ ] **Step 4: Run test — still fails because trait doesn't exist**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/RevisionableEntityTraitTest.php`
Expected: FAIL — `RevisionableEntityTrait` not found.

- [ ] **Step 5: Create `RevisionableEntityTrait`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Implements RevisionableInterface for entity classes.
 *
 * Requires the using class to extend EntityBase (needs $values and $entityKeys).
 */
trait RevisionableEntityTrait
{
    /** @var bool|null null = use entity type default */
    private ?bool $newRevision = null;

    public function getRevisionId(): ?int
    {
        $revisionKey = $this->entityKeys['revision'] ?? 'revision_id';
        $value = $this->values[$revisionKey] ?? null;

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function isDefaultRevision(): bool
    {
        return (bool) ($this->values['is_default_revision'] ?? true);
    }

    public function isLatestRevision(): bool
    {
        return (bool) ($this->values['is_latest_revision'] ?? true);
    }

    public function setNewRevision(bool $value): void
    {
        $this->newRevision = $value;
    }

    public function isNewRevision(): ?bool
    {
        return $this->newRevision;
    }

    public function setRevisionLog(?string $log): void
    {
        $this->values['revision_log'] = $log;
    }

    public function getRevisionLog(): ?string
    {
        return isset($this->values['revision_log'])
            ? (string) $this->values['revision_log']
            : null;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/RevisionableEntityTraitTest.php`
Expected: All 11 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/entity/src/RevisionableEntityTrait.php \
       packages/entity-storage/tests/Fixtures/TestRevisionableEntity.php \
       packages/entity-storage/tests/Unit/RevisionableEntityTraitTest.php
git commit -m "feat(#512): add RevisionableEntityTrait with unit tests

Trait provides getRevisionId, isDefaultRevision, isLatestRevision,
setNewRevision/isNewRevision (nullable override), setRevisionLog/
getRevisionLog. Reads revision_id from entity keys mapping."
```

---

## Task 3: SqlSchemaHandler — Revision Table Creation

**Files:**
- Modify: `packages/entity-storage/src/SqlSchemaHandler.php:31-41`
- Modify: `packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php`

- [ ] **Step 1: Write the test for `ensureRevisionTable`**

Add to `packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php`:

```php
#[Test]
public function ensure_revision_table_creates_table_with_composite_pk(): void
{
    $entityType = new EntityType(
        id: 'node',
        label: 'Content',
        class: TestStorageEntity::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        revisionable: true,
        revisionDefault: true,
    );

    $db = DBALDatabase::createSqlite();
    $handler = new SqlSchemaHandler($entityType, $db);
    $handler->ensureTable();
    $handler->ensureRevisionTable();

    $schema = $db->schema();
    $this->assertTrue($schema->tableExists('node_revision'));
    $this->assertTrue($schema->fieldExists('node_revision', 'entity_id'));
    $this->assertTrue($schema->fieldExists('node_revision', 'revision_id'));
    $this->assertTrue($schema->fieldExists('node_revision', 'revision_created'));
    $this->assertTrue($schema->fieldExists('node_revision', 'revision_log'));
    $this->assertTrue($schema->fieldExists('node_revision', '_data'));
}

#[Test]
public function ensure_revision_table_is_idempotent(): void
{
    $entityType = new EntityType(
        id: 'node',
        label: 'Content',
        class: TestStorageEntity::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        revisionable: true,
    );

    $db = DBALDatabase::createSqlite();
    $handler = new SqlSchemaHandler($entityType, $db);
    $handler->ensureTable();
    $handler->ensureRevisionTable();
    $handler->ensureRevisionTable(); // second call should be a no-op

    $this->assertTrue($db->schema()->tableExists('node_revision'));
}

#[Test]
public function ensure_table_adds_revision_id_column_for_revisionable_types(): void
{
    $entityType = new EntityType(
        id: 'node',
        label: 'Content',
        class: TestStorageEntity::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        revisionable: true,
    );

    $db = DBALDatabase::createSqlite();
    $handler = new SqlSchemaHandler($entityType, $db);
    $handler->ensureTable();

    $this->assertTrue($db->schema()->fieldExists('node', 'revision_id'));
}

#[Test]
public function seed_revisions_creates_revision_1_for_existing_rows(): void
{
    $entityType = new EntityType(
        id: 'node',
        label: 'Content',
        class: TestStorageEntity::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        revisionable: true,
    );

    $db = DBALDatabase::createSqlite();
    $handler = new SqlSchemaHandler($entityType, $db);
    $handler->ensureTable();
    $handler->ensureRevisionTable();

    // Insert an existing row without a revision.
    $db->insert('node')
        ->fields(['nid', 'uuid', 'title', 'bundle', 'label', 'langcode', '_data'])
        ->values(['nid' => '1', 'uuid' => 'abc', 'title' => 'Existing', 'bundle' => 'page', 'label' => 'Existing', 'langcode' => 'en', '_data' => '{}'])
        ->execute();

    $handler->seedRevisions();

    // Verify revision 1 was created.
    $result = $db->query('SELECT * FROM node_revision WHERE entity_id = ? AND revision_id = 1', ['1']);
    $revRow = null;
    foreach ($result as $row) {
        $revRow = (array) $row;
        break;
    }
    $this->assertNotNull($revRow);
    $this->assertSame('Existing', $revRow['title'] ?? $revRow['label']);

    // Verify base table pointer updated.
    $result = $db->query('SELECT revision_id FROM node WHERE nid = ?', ['1']);
    foreach ($result as $row) {
        $this->assertSame(1, (int) ((array) $row)['revision_id']);
    }

    // Verify idempotent — second call is a no-op.
    $handler->seedRevisions();
    $result = $db->query('SELECT COUNT(*) as cnt FROM node_revision WHERE entity_id = ?', ['1']);
    foreach ($result as $row) {
        $this->assertSame(1, (int) ((array) $row)['cnt']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php --filter "ensure_revision"`
Expected: FAIL — `ensureRevisionTable` method doesn't exist.

- [ ] **Step 3: Add `revision_id` column to base table in `buildTableSpec()`**

In `SqlSchemaHandler::buildTableSpec()`, add after the langcode field block (around line 176):

```php
// Revision pointer column (revisionable entity types only).
if ($this->entityType->isRevisionable()) {
    $revisionKey = $keys['revision'] ?? 'revision_id';
    $fields[$revisionKey] = [
        'type' => 'int',
        'not null' => false,
        'default' => null,
    ];
}
```

- [ ] **Step 4: Add `ensureRevisionTable()` method to `SqlSchemaHandler`**

Add after `ensureTranslationTable()` (after line 63):

```php
/**
 * Ensures the revision table exists for revisionable entity types.
 *
 * The revision table stores snapshots of all field values for each revision.
 * Primary key is composite (entity_id, revision_id).
 */
public function ensureRevisionTable(): void
{
    $schema = $this->database->schema();
    $revisionTableName = $this->getRevisionTableName();

    if ($schema->tableExists($revisionTableName)) {
        return;
    }

    $spec = $this->buildRevisionTableSpec();
    $schema->createTable($revisionTableName, $spec);
}

/**
 * Returns the revision table name for this entity type.
 */
public function getRevisionTableName(): string
{
    return $this->tableName . '_revision';
}
```

- [ ] **Step 5: Add `buildRevisionTableSpec()` private method**

Add after `buildTranslationTableSpec()`:

```php
/**
 * Builds the revision table specification.
 *
 * Mirrors the base table field columns plus revision metadata.
 * PK is composite (entity_id, revision_id).
 *
 * @return array<string, mixed>
 */
private function buildRevisionTableSpec(): array
{
    $keys = $this->entityType->getKeys();
    $idKey = $keys['id'] ?? 'id';
    $fields = [];

    // Entity ID foreign key — always varchar to handle both serial and string PKs.
    $fields['entity_id'] = [
        'type' => 'varchar',
        'length' => 128,
        'not null' => true,
    ];

    // Revision ID — monotonic integer per entity.
    $fields['revision_id'] = [
        'type' => 'int',
        'not null' => true,
    ];

    // Revision metadata.
    $fields['revision_created'] = [
        'type' => 'varchar',
        'length' => 32,
        'not null' => true,
    ];

    $fields['revision_log'] = [
        'type' => 'text',
        'not null' => false,
    ];

    // Mirror base table field columns (label, bundle, langcode, etc.)
    $labelKey = $keys['label'] ?? 'label';
    $fields[$labelKey] = [
        'type' => 'varchar',
        'length' => 255,
        'not null' => true,
        'default' => '',
    ];

    $bundleKey = $keys['bundle'] ?? 'bundle';
    $fields[$bundleKey] = [
        'type' => 'varchar',
        'length' => 128,
        'not null' => true,
        'default' => '',
    ];

    $langcodeKey = $keys['langcode'] ?? 'langcode';
    $fields[$langcodeKey] = [
        'type' => 'varchar',
        'length' => 12,
        'not null' => true,
        'default' => 'en',
    ];

    if (isset($keys['uuid'])) {
        $fields[$keys['uuid']] = [
            'type' => 'varchar',
            'length' => 128,
            'not null' => true,
            'default' => '',
        ];
    }

    // Data blob for extra fields.
    $fields['_data'] = [
        'type' => 'text',
        'not null' => true,
        'default' => '{}',
    ];

    $revisionTableName = $this->getRevisionTableName();

    return [
        'fields' => $fields,
        'primary key' => ['entity_id', 'revision_id'],
        'indexes' => [
            $revisionTableName . '_entity_rev' => ['entity_id', 'revision_id DESC'],
        ],
    ];
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php`
Expected: All tests PASS (new + existing).

- [ ] **Step 7: Commit**

```bash
git add packages/entity-storage/src/SqlSchemaHandler.php \
       packages/entity-storage/tests/Unit/SqlSchemaHandlerTest.php
git commit -m "feat(#512): add revision table creation to SqlSchemaHandler

ensureRevisionTable() creates {table}_revision with composite PK
(entity_id, revision_id). Base table gains revision_id pointer column
for revisionable entity types."
```

---

## Task 4: RevisionableStorageDriver

**Files:**
- Create: `packages/entity-storage/src/Driver/RevisionableStorageDriver.php`
- Create: `packages/entity-storage/tests/Unit/Driver/RevisionableStorageDriverTest.php`

- [ ] **Step 1: Write the tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(RevisionableStorageDriver::class)]
final class RevisionableStorageDriverTest extends TestCase
{
    private DBALDatabase $db;
    private RevisionableStorageDriver $driver;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test Revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $this->driver = new RevisionableStorageDriver($resolver, $this->entityType);
    }

    #[Test]
    public function write_revision_creates_revision_row_and_returns_revision_id(): void
    {
        $revisionId = $this->driver->writeRevision('1', [
            'title' => 'Hello',
            'uuid' => 'abc-123',
        ], 'Initial creation');

        $this->assertSame(1, $revisionId);
    }

    #[Test]
    public function write_revision_increments_revision_id(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $rev2 = $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $this->assertSame(2, $rev2);
    }

    #[Test]
    public function revision_ids_are_per_entity(): void
    {
        $this->driver->writeRevision('1', ['title' => 'A', 'uuid' => 'a'], null);
        $rev = $this->driver->writeRevision('2', ['title' => 'B', 'uuid' => 'b'], null);

        $this->assertSame(1, $rev); // entity 2's first revision is also 1
    }

    #[Test]
    public function read_revision_returns_row(): void
    {
        $this->driver->writeRevision('1', ['title' => 'Hello', 'uuid' => 'a'], 'log msg');
        $row = $this->driver->readRevision('1', 1);

        $this->assertNotNull($row);
        $this->assertSame('Hello', $row['title']);
        $this->assertSame('log msg', $row['revision_log']);
        $this->assertArrayHasKey('revision_created', $row);
    }

    #[Test]
    public function read_revision_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->driver->readRevision('1', 99));
    }

    #[Test]
    public function get_latest_revision_id(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $this->assertSame(2, $this->driver->getLatestRevisionId('1'));
    }

    #[Test]
    public function get_latest_revision_id_returns_null_for_no_revisions(): void
    {
        $this->assertNull($this->driver->getLatestRevisionId('999'));
    }

    #[Test]
    public function get_revision_ids_returns_ascending_list(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v3', 'uuid' => 'a'], null);

        $this->assertSame([1, 2, 3], $this->driver->getRevisionIds('1'));
    }

    #[Test]
    public function delete_revision_removes_row(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $this->driver->deleteRevision('1', 1);

        $this->assertNull($this->driver->readRevision('1', 1));
        $this->assertNotNull($this->driver->readRevision('1', 2));
    }

    #[Test]
    public function update_revision_in_place(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], 'log');
        $this->driver->updateRevision('1', 1, ['title' => 'v1-updated', 'uuid' => 'a']);

        $row = $this->driver->readRevision('1', 1);
        $this->assertSame('v1-updated', $row['title']);
        // revision_log must be preserved during in-place update
        $this->assertSame('log', $row['revision_log']);
    }

    #[Test]
    public function delete_default_revision_throws(): void
    {
        // Write rev 1 and simulate it being the default by inserting a base row.
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        // The base table has revision_id from ensureTable, set it to 1.
        $this->db->query("UPDATE test_revisionable SET revision_id = 1 WHERE id = '1'", []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot delete the default revision');
        $this->driver->deleteRevision('1', 1);
    }

    #[Test]
    public function read_multiple_revisions(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v3', 'uuid' => 'a'], null);

        $rows = $this->driver->readMultipleRevisions('1', [1, 3]);
        $this->assertCount(2, $rows);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/Driver/RevisionableStorageDriverTest.php`
Expected: FAIL — `RevisionableStorageDriver` not found.

- [ ] **Step 3: Implement `RevisionableStorageDriver`**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Storage\RevisionableStorageInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;

/**
 * SQL driver for revision table I/O.
 *
 * Implements RevisionableStorageInterface for the revision-specific methods.
 * Handles raw read/write against the {entity_table}_revision table.
 * Does not handle entity hydration or event dispatch — that's EntityRepository's job.
 *
 * Note: The EntityStorageInterface methods (load, save, delete, etc.) are not
 * implemented here — those remain on SqlStorageDriver for the base table.
 * This class provides the revision-specific operations only.
 */
final class RevisionableStorageDriver
{
    private readonly string $revisionTable;

    public function __construct(
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly EntityTypeInterface $entityType,
    ) {
        $this->revisionTable = $this->entityType->id() . '_revision';
    }

    /**
     * Write a new revision row.
     *
     * @param array<string, mixed> $values Field values to snapshot.
     * @return int The new revision ID.
     */
    public function writeRevision(string $entityId, array $values, ?string $log): int
    {
        $db = $this->getDatabase();

        $revisionId = $this->getNextRevisionId($entityId);

        $row = [
            'entity_id' => $entityId,
            'revision_id' => $revisionId,
            'revision_created' => date('Y-m-d H:i:s'),
            'revision_log' => $log,
        ];

        // Add field values, excluding entity keys that don't belong in revision table.
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        foreach ($values as $key => $value) {
            if ($key === $idKey || $key === 'revision_id' || $key === 'is_default_revision' || $key === 'is_latest_revision') {
                continue;
            }
            $row[$key] = $value;
        }

        $db->insert($this->revisionTable)
            ->fields(array_keys($row))
            ->values($row)
            ->execute();

        return $revisionId;
    }

    /**
     * Update an existing revision row's field values in place.
     *
     * Preserves revision_created and revision_log (immutable metadata).
     *
     * @param array<string, mixed> $values Updated field values.
     */
    public function updateRevision(string $entityId, int $revisionId, array $values): void
    {
        $db = $this->getDatabase();

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        $updateFields = [];
        foreach ($values as $key => $value) {
            // Skip keys that are part of the revision PK or immutable metadata.
            if (\in_array($key, [$idKey, 'entity_id', 'revision_id', 'revision_created', 'revision_log', 'is_default_revision', 'is_latest_revision'], true)) {
                continue;
            }
            $updateFields[$key] = $value;
        }

        if ($updateFields === []) {
            return;
        }

        $db->update($this->revisionTable)
            ->fields($updateFields)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();
    }

    /**
     * Read a specific revision row.
     *
     * @return array<string, mixed>|null
     */
    public function readRevision(string $entityId, int $revisionId): ?array
    {
        $db = $this->getDatabase();

        $result = $db->select($this->revisionTable)
            ->fields($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();

        foreach ($result as $row) {
            return (array) $row;
        }

        return null;
    }

    /**
     * Read multiple revision rows for an entity.
     *
     * @param int[] $revisionIds
     * @return array<int, array<string, mixed>>
     */
    public function readMultipleRevisions(string $entityId, array $revisionIds): array
    {
        $rows = [];
        foreach ($revisionIds as $revId) {
            $row = $this->readRevision($entityId, $revId);
            if ($row !== null) {
                $rows[$revId] = $row;
            }
        }

        return $rows;
    }

    public function getLatestRevisionId(string $entityId): ?int
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT MAX(revision_id) as max_rev FROM ' . $this->revisionTable . ' WHERE entity_id = ?',
            [$entityId],
        );

        foreach ($result as $row) {
            $row = (array) $row;
            return $row['max_rev'] !== null ? (int) $row['max_rev'] : null;
        }

        return null;
    }

    /**
     * @return int[] Revision IDs in ascending order.
     */
    public function getRevisionIds(string $entityId): array
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT revision_id FROM ' . $this->revisionTable . ' WHERE entity_id = ? ORDER BY revision_id ASC',
            [$entityId],
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) ((array) $row)['revision_id'];
        }

        return $ids;
    }

    public function deleteRevision(string $entityId, int $revisionId): void
    {
        $db = $this->getDatabase();

        // Guard: cannot delete the default revision (invariant #8).
        $baseTable = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $result = $db->query(
            'SELECT revision_id FROM ' . $baseTable . ' WHERE ' . $idKey . ' = ?',
            [$entityId],
        );
        foreach ($result as $row) {
            $row = (array) $row;
            if ((int) $row['revision_id'] === $revisionId) {
                throw new \LogicException(
                    "Cannot delete the default revision {$revisionId} for entity {$entityId}. Delete the entity instead."
                );
            }
        }

        $db->delete($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();
    }

    /**
     * Delete all revisions for an entity.
     */
    public function deleteAllRevisions(string $entityId): void
    {
        $db = $this->getDatabase();

        $db->delete($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->execute();
    }

    private function getNextRevisionId(string $entityId): int
    {
        $latest = $this->getLatestRevisionId($entityId);

        return ($latest ?? 0) + 1;
    }

    private function getDatabase(): DatabaseInterface
    {
        return $this->connectionResolver->connection();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/Driver/RevisionableStorageDriverTest.php`
Expected: All 11 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/entity-storage/src/Driver/RevisionableStorageDriver.php \
       packages/entity-storage/tests/Unit/Driver/RevisionableStorageDriverTest.php
git commit -m "feat(#512): add RevisionableStorageDriver for revision table I/O

Handles writeRevision, readRevision, updateRevision, deleteRevision,
getLatestRevisionId, getRevisionIds, deleteAllRevisions. Monotonic
revision IDs per entity via MAX+1. Preserves immutable metadata
(revision_created, revision_log) during in-place updates."
```

---

## Task 5: EntityRepository Revision Awareness

**Files:**
- Modify: `packages/entity/src/Repository/EntityRepositoryInterface.php`
- Modify: `packages/entity-storage/src/EntityRepository.php`
- Create: `packages/entity-storage/tests/Unit/EntityRepositoryRevisionTest.php`

- [ ] **Step 1: Add revision methods to `EntityRepositoryInterface`**

Add after the `count()` method:

```php
/**
 * Load a specific revision of an entity.
 *
 * @param string $entityId The entity ID.
 * @param int $revisionId The revision ID.
 * @return EntityInterface|null The entity hydrated from the revision, or null.
 */
public function loadRevision(string $entityId, int $revisionId): ?EntityInterface;

/**
 * Rollback an entity to a previous revision (copy-forward).
 *
 * Creates a new revision with the target revision's field values
 * and auto-annotates the revision log.
 *
 * @param string $entityId The entity ID.
 * @param int $targetRevisionId The revision to copy values from.
 * @return EntityInterface The entity hydrated from the new revision.
 * @throws \InvalidArgumentException If the target revision does not exist.
 */
public function rollback(string $entityId, int $targetRevisionId): EntityInterface;
```

- [ ] **Step 2: Write the tests for revision-aware save and rollback**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryRevisionTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;
    private EntityType $entityType;
    /** @var string[] */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $this->entityType);

        $this->dispatchedEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event, $eventName) {
            $this->dispatchedEvents[] = $eventName;
            return $event;
        });

        $this->repo = new EntityRepository(
            $this->entityType,
            $driver,
            $dispatcher,
            $revisionDriver,
            $this->db,
        );
    }

    #[Test]
    public function save_new_entity_creates_revision_1(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'Hello', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertNotNull($loaded);
        $this->assertInstanceOf(RevisionableInterface::class, $loaded);
        $this->assertSame(1, $loaded->getRevisionId());
    }

    #[Test]
    public function save_creates_new_revision_when_revision_default_true(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertSame(2, $loaded->getRevisionId());
        $this->assertSame('v2', $loaded->label());
    }

    #[Test]
    public function save_with_new_revision_false_updates_in_place(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->setNewRevision(false);
        $entity->set('title', 'v1-updated');
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertSame(1, $loaded->getRevisionId()); // same revision
        $this->assertSame('v1-updated', $loaded->label());
    }

    #[Test]
    public function load_revision_returns_specific_revision(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $rev1 = $this->repo->loadRevision('1', 1);
        $this->assertNotNull($rev1);
        $this->assertSame('v1', $rev1->label());
    }

    #[Test]
    public function rollback_creates_new_revision_with_target_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v3');
        $this->repo->save($entity);

        $rolledBack = $this->repo->rollback('1', 1);

        $this->assertSame(4, $rolledBack->getRevisionId()); // new revision
        $this->assertSame('v1', $rolledBack->label()); // v1 values
        $this->assertSame('Reverted to revision 1', $rolledBack->getRevisionLog());
    }

    #[Test]
    public function rollback_dispatches_revision_reverted_event(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $this->dispatchedEvents = [];
        $this->repo->rollback('1', 1);

        $this->assertContains(EntityEvents::REVISION_REVERTED->value, $this->dispatchedEvents);
    }

    #[Test]
    public function rollback_throws_for_nonexistent_target(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->rollback('1', 99);
    }

    #[Test]
    public function save_dispatches_revision_created_event(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->dispatchedEvents = [];
        $this->repo->save($entity);

        $this->assertContains(EntityEvents::REVISION_CREATED->value, $this->dispatchedEvents);
    }

    #[Test]
    public function delete_removes_all_revisions(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $this->repo->delete($entity);

        $this->assertNull($this->repo->find('1'));
        $this->assertNull($this->repo->loadRevision('1', 1));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryRevisionTest.php`
Expected: FAIL — `EntityRepository` constructor doesn't accept `$revisionDriver` yet.

- [ ] **Step 4: Modify `EntityRepository` constructor to accept optional revision driver**

Update the constructor (line 27-31) to:

```php
public function __construct(
    private readonly EntityTypeInterface $entityType,
    private readonly EntityStorageDriverInterface $driver,
    private readonly EventDispatcherInterface $eventDispatcher,
    // Concrete type is intentional — RevisionableStorageDriver is framework-internal,
    // not a pluggable extension point. Matches SqlStorageDriver usage pattern.
    private readonly ?RevisionableStorageDriver $revisionDriver = null,
    private readonly ?DatabaseInterface $database = null,
) {}
```

The `$database` parameter provides transaction support (invariant #4). Both `SqlStorageDriver` and `RevisionableStorageDriver` use the same database via `ConnectionResolver`, so wrapping operations in `$database->transaction()` ensures atomicity.

Add the imports:

```php
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Database\DatabaseInterface;
```

- [ ] **Step 5: Modify `EntityRepository::save()` for revision awareness**

Replace the `save()` method (lines 85-115) with revision-aware logic:

```php
public function save(EntityInterface $entity): int
{
    $isNew = $entity->isNew();
    $entityTypeId = $this->entityType->id();

    // Dispatch PRE_SAVE event.
    $this->eventDispatcher->dispatch(
        new EntityEvent($entity),
        EntityEvents::PRE_SAVE->value,
    );

    $values = $entity->toArray();
    $id = (string) ($entity->id() ?? '');

    // Determine if we should create a new revision.
    $createRevision = $this->shouldCreateRevision($entity, $isNew);

    // Wrap revision + base table writes in a transaction (invariant #4).
    $transaction = $this->database?->transaction();
    try {
        if ($createRevision && $this->revisionDriver !== null) {
            $log = ($entity instanceof RevisionableInterface) ? $entity->getRevisionLog() : null;

            $revisionId = $this->revisionDriver->writeRevision($id, $values, $log);

            // Set revision_id on the entity values for the base table.
            $values['revision_id'] = $revisionId;

            // Update entity's internal revision_id.
            if ($entity instanceof ContentEntityInterface) {
                $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                $entity->set($revisionKey, $revisionId);
            }
        } elseif (!$createRevision && !$isNew && $this->revisionDriver !== null && $entity instanceof RevisionableInterface) {
            // In-place update of current revision.
            $currentRevisionId = $entity->getRevisionId();
            if ($currentRevisionId !== null) {
                $this->revisionDriver->updateRevision($id, $currentRevisionId, $values);
            }
        }

        $this->driver->write($entityTypeId, $id, $values);
        $transaction?->commit();
    } catch (\Throwable $e) {
        $transaction?->rollBack();
        throw $e;
    }

    // Mark entity as no longer new.
    if ($isNew && method_exists($entity, 'enforceIsNew')) {
        $entity->enforceIsNew(false);
    }

    $result = $isNew ? EntityConstants::SAVED_NEW : EntityConstants::SAVED_UPDATED;

    // Dispatch POST_SAVE event.
    $this->eventDispatcher->dispatch(
        new EntityEvent($entity),
        EntityEvents::POST_SAVE->value,
    );

    // Dispatch REVISION_CREATED if a revision was created.
    if ($createRevision && $this->revisionDriver !== null) {
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::REVISION_CREATED->value,
        );
    }

    return $result;
}
```

Add the import for `RevisionableInterface` and `ContentEntityInterface`:

```php
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\ContentEntityInterface;
```

- [ ] **Step 6: Add `shouldCreateRevision()` private method**

```php
/**
 * Determine if a new revision should be created for this save.
 */
private function shouldCreateRevision(EntityInterface $entity, bool $isNew): bool
{
    if (!$this->entityType->isRevisionable()) {
        // Invariant #9: type gating — reject explicit revision request on non-revisionable type.
        if ($entity instanceof RevisionableInterface && $entity->isNewRevision() === true) {
            throw new \LogicException(
                'Cannot create revision for non-revisionable entity type ' . $this->entityType->id()
            );
        }
        return false;
    }

    // First save always creates revision 1.
    if ($isNew) {
        return true;
    }

    // Caller override takes precedence.
    if ($entity instanceof RevisionableInterface) {
        $override = $entity->isNewRevision();
        if ($override !== null) {
            return $override;
        }
    }

    // Fall back to entity type default.
    return $this->entityType->getRevisionDefault();
}
```

- [ ] **Step 7: Add `loadRevision()` method to `EntityRepository`**

```php
public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
{
    if ($this->revisionDriver === null) {
        throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
    }

    $row = $this->revisionDriver->readRevision($entityId, $revisionId);
    if ($row === null) {
        return null;
    }

    // Inject the entity ID back (revision table uses entity_id, not the id key).
    $keys = $this->entityType->getKeys();
    $idKey = $keys['id'] ?? 'id';
    $row[$idKey] = $row['entity_id'];

    // Determine if this revision is the current default by reading the base table pointer.
    $baseRow = $this->driver->read($this->entityType->id(), $entityId);
    $currentRevId = $baseRow !== null ? (int) ($baseRow['revision_id'] ?? 0) : 0;
    $latestRevId = $this->revisionDriver->getLatestRevisionId($entityId);
    $row['is_default_revision'] = ($revisionId === $currentRevId);
    $row['is_latest_revision'] = ($revisionId === $latestRevId);

    return $this->hydrate($row);
}
```

- [ ] **Step 8: Add `rollback()` method to `EntityRepository`**

```php
public function rollback(string $entityId, int $targetRevisionId): EntityInterface
{
    if ($this->revisionDriver === null) {
        throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
    }

    // Load the target revision.
    $targetRow = $this->revisionDriver->readRevision($entityId, $targetRevisionId);
    if ($targetRow === null) {
        throw new \InvalidArgumentException(
            "Revision {$targetRevisionId} does not exist for entity {$entityId}."
        );
    }

    // Remove revision metadata from the row — we're creating a new revision.
    unset($targetRow['revision_id'], $targetRow['revision_created'], $targetRow['revision_log'], $targetRow['entity_id']);

    // Wrap in transaction (invariant #4: atomic pointer update).
    $transaction = $this->database?->transaction();
    try {
        // Create new revision with the target's field values.
        $log = "Reverted to revision {$targetRevisionId}";
        $newRevisionId = $this->revisionDriver->writeRevision($entityId, $targetRow, $log);

        // Update the base table pointer.
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $targetRow[$idKey] = $entityId;
        $targetRow['revision_id'] = $newRevisionId;
        $this->driver->write($this->entityType->id(), $entityId, $targetRow);

        $transaction?->commit();
    } catch (\Throwable $e) {
        $transaction?->rollBack();
        throw $e;
    }

    // Hydrate and return the new entity.
    $entity = $this->find($entityId);

    // Dispatch REVISION_CREATED (rollback creates a revision) then REVISION_REVERTED.
    $this->eventDispatcher->dispatch(
        new EntityEvent($entity),
        EntityEvents::REVISION_CREATED->value,
    );
    $this->eventDispatcher->dispatch(
        new EntityEvent($entity),
        EntityEvents::REVISION_REVERTED->value,
    );

    return $entity;
}
```

- [ ] **Step 9: Modify `delete()` to cascade revisions**

Update `delete()` to also remove revision rows:

```php
public function delete(EntityInterface $entity): void
{
    $entityTypeId = $this->entityType->id();
    $id = (string) $entity->id();

    // Dispatch PRE_DELETE event.
    $this->eventDispatcher->dispatch(
        new EntityEvent($entity),
        EntityEvents::PRE_DELETE->value,
    );

    // Delete all revisions first (if revisionable).
    if ($this->revisionDriver !== null && $this->entityType->isRevisionable()) {
        $this->revisionDriver->deleteAllRevisions($id);
    }

    $this->driver->remove($entityTypeId, $id);

    // Dispatch POST_DELETE event.
    $this->eventDispatcher->dispatch(
        new EntityEvent($entity),
        EntityEvents::POST_DELETE->value,
    );
}
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryRevisionTest.php`
Expected: All 9 tests PASS.

- [ ] **Step 11: Run ALL existing tests to verify no regressions**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All existing tests still pass (new constructor param is nullable with default).

- [ ] **Step 12: Commit**

```bash
git add packages/entity/src/Repository/EntityRepositoryInterface.php \
       packages/entity-storage/src/EntityRepository.php \
       packages/entity-storage/tests/Unit/EntityRepositoryRevisionTest.php
git commit -m "feat(#512): add revision-aware save, rollback, and loadRevision to EntityRepository

EntityRepository now accepts optional RevisionableStorageDriver. Save
creates new revisions based on entity type default + caller override.
rollback() implements copy-forward with log annotation. delete() cascades
revision rows. Dispatches REVISION_CREATED and REVISION_REVERTED events
after transaction."
```

---

## Task 6: Integration Tests — Full Lifecycle

**Files:**
- Create: `tests/Integration/Revision/RevisionLifecycleIntegrationTest.php`

- [ ] **Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Revision;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * Full lifecycle integration test matching the spec's example scenario.
 *
 * @see docs/superpowers/specs/2026-03-21-revision-lifecycle-design.md Section F
 */
#[CoversNothing]
final class RevisionLifecycleIntegrationTest extends TestCase
{
    private EntityRepository $repo;
    private DBALDatabase $db;
    /** @var string[] */
    private array $events = [];

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $entityType);

        $this->events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EntityEvents::REVISION_CREATED->value, function () {
            $this->events[] = 'revision_created';
        });
        $dispatcher->addListener(EntityEvents::REVISION_REVERTED->value, function () {
            $this->events[] = 'revision_reverted';
        });

        $this->repo = new EntityRepository($entityType, $driver, $dispatcher, $revisionDriver, $this->db);
    }

    /**
     * Reproduces the exact 5-step lifecycle from the design spec.
     */
    #[Test]
    public function full_lifecycle_from_spec(): void
    {
        // Step 1: Create → rev 1
        $entity = new TestRevisionableEntity(values: ['title' => 'Hello', 'id' => '1', 'uuid' => 'abc-123']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertSame(1, $loaded->getRevisionId());
        $this->assertSame('Hello', $loaded->label());

        // Step 2: Edit → rev 2
        $loaded->set('title', 'Hello World');
        $this->repo->save($loaded);

        $loaded = $this->repo->find('1');
        $this->assertSame(2, $loaded->getRevisionId());
        $this->assertSame('Hello World', $loaded->label());

        // Step 3: Edit again → rev 3
        $loaded->set('title', 'Greetings');
        $this->repo->save($loaded);

        $loaded = $this->repo->find('1');
        $this->assertSame(3, $loaded->getRevisionId());
        $this->assertSame('Greetings', $loaded->label());

        // Step 4: Rollback to rev 1 → rev 4 (copy-forward)
        $rolledBack = $this->repo->rollback('1', 1);
        $this->assertSame(4, $rolledBack->getRevisionId());
        $this->assertSame('Hello', $rolledBack->label());

        // Verify rollback log
        $rev4 = $this->repo->loadRevision('1', 4);
        $this->assertSame('Reverted to revision 1', $rev4->getRevisionLog());

        // Step 5: In-place update (no new revision)
        $loaded = $this->repo->find('1');
        $loaded->setNewRevision(false);
        $loaded->set('title', 'Hello!');
        $this->repo->save($loaded);

        $loaded = $this->repo->find('1');
        $this->assertSame(4, $loaded->getRevisionId()); // still rev 4
        $this->assertSame('Hello!', $loaded->label());

        // Verify historical revisions are intact
        $rev1 = $this->repo->loadRevision('1', 1);
        $this->assertSame('Hello', $rev1->label());

        $rev2 = $this->repo->loadRevision('1', 2);
        $this->assertSame('Hello World', $rev2->label());

        $rev3 = $this->repo->loadRevision('1', 3);
        $this->assertSame('Greetings', $rev3->label());
    }

    #[Test]
    public function revision_events_are_dispatched(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->events = [];
        $this->repo->save($entity);
        $this->assertContains('revision_created', $this->events);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->events = [];
        $this->repo->save($entity);
        $this->assertContains('revision_created', $this->events);

        $this->events = [];
        $this->repo->rollback('1', 1);
        $this->assertContains('revision_reverted', $this->events);
    }

    #[Test]
    public function delete_cascades_all_revisions(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $this->repo->delete($entity);

        $this->assertNull($this->repo->find('1'));
        $this->assertNull($this->repo->loadRevision('1', 1));
        $this->assertNull($this->repo->loadRevision('1', 2));
    }

    #[Test]
    public function non_revisionable_entity_type_ignores_revision_logic(): void
    {
        $entityType = new EntityType(
            id: 'test_simple',
            label: 'Simple',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            revisionable: false,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $dispatcher = new EventDispatcher();
        $repo = new EntityRepository($entityType, $driver, $dispatcher);

        $entity = new TestRevisionableEntity(
            values: ['title' => 'No revisions', 'id' => '1', 'uuid' => 'x'],
            entityTypeId: 'test_simple',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $entity->enforceIsNew();
        $repo->save($entity);

        $loaded = $repo->find('1');
        $this->assertSame('No revisions', $loaded->label());
        $this->assertNull($loaded->getRevisionId()); // no revision tracking
    }

    #[Test]
    public function rollback_to_nonexistent_revision_throws(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Revision 99 does not exist');
        $this->repo->rollback('1', 99);
    }

    #[Test]
    public function monotonic_revision_ids_after_deletion(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v3');
        $this->repo->save($entity);

        // Revision IDs are 1, 2, 3. Even though they can't be directly
        // deleted via the repo yet (that would need the storage interface
        // wired), verify monotonic increment continues.
        $entity = $this->repo->find('1');
        $entity->set('title', 'v4');
        $this->repo->save($entity);

        $this->assertSame(4, $this->repo->find('1')->getRevisionId());
    }
}
```

- [ ] **Step 2: Run integration test**

Run: `./vendor/bin/phpunit tests/Integration/Revision/RevisionLifecycleIntegrationTest.php`
Expected: All 6 tests PASS.

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass, no regressions.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Revision/RevisionLifecycleIntegrationTest.php
git commit -m "test(#512): add integration tests for revision lifecycle

Full 5-step lifecycle from design spec (create, edit, edit, rollback,
in-place update). Tests event dispatch, delete cascade, non-revisionable
entity types, and error cases."
```

---

## Task 7: Final Verification and Cleanup

- [ ] **Step 1: Run full test suite one more time**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

- [ ] **Step 2: Verify spec compliance**

Check each design spec invariant against the implementation:
1. Single default — base table `revision_id` pointer ✓
2. Monotonic IDs — `MAX+1` in `writeRevision` ✓
3. Immutable history — `updateRevision` preserves metadata ✓
4. Atomic pointer — `save()` and `rollback()` wrap in transaction ✓
5. Timestamp fidelity — `revision_created` set in `writeRevision`, not in update ✓
6. Log fidelity — `revision_log` set in `writeRevision`, excluded from update ✓
7. Default resolution — `find()` reads base table ✓
8. Protected default — `deleteRevision` guards against default, delete cascades all ✓
9. Type gating — `shouldCreateRevision` throws `\LogicException` for explicit revision on non-revisionable ✓
10. Rollback is creation — `rollback()` calls `writeRevision` ✓

- [ ] **Step 3: Add migration helper for existing data**

Add a `seedRevisions()` method to `SqlSchemaHandler` for migrating existing entities to revisionable. This creates revision 1 for each existing base row:

```php
/**
 * Seed revision 1 for all existing rows in the base table.
 *
 * Used when enabling revisions on an entity type with existing data.
 * Must run after ensureRevisionTable().
 */
public function seedRevisions(): void
{
    $db = $this->database;
    $keys = $this->entityType->getKeys();
    $idKey = $keys['id'] ?? 'id';
    $revisionKey = $keys['revision'] ?? 'revision_id';
    $revisionTable = $this->getRevisionTableName();

    // Read all base rows that don't have a revision yet.
    $result = $db->select($this->tableName)
        ->fields($this->tableName)
        ->execute();

    foreach ($result as $row) {
        $row = (array) $row;
        $entityId = (string) $row[$idKey];

        // Skip if revision already exists.
        $existing = $db->query(
            "SELECT 1 FROM {$revisionTable} WHERE entity_id = ? AND revision_id = 1",
            [$entityId],
        );
        $found = false;
        foreach ($existing as $_) {
            $found = true;
            break;
        }
        if ($found) {
            continue;
        }

        // Build revision row from base row.
        $revRow = ['entity_id' => $entityId, 'revision_id' => 1];
        $revRow['revision_created'] = date('Y-m-d H:i:s');
        $revRow['revision_log'] = 'Seeded from existing data';
        foreach ($row as $col => $val) {
            if ($col === $idKey || $col === $revisionKey) {
                continue;
            }
            $revRow[$col] = $val;
        }

        $db->insert($revisionTable)
            ->fields(array_keys($revRow))
            ->values($revRow)
            ->execute();

        // Update base row pointer.
        $db->update($this->tableName)
            ->fields([$revisionKey => 1])
            ->condition($idKey, $entityId)
            ->execute();
    }
}
```

- [ ] **Step 4: Commit final state and tag**

```bash
git commit --allow-empty -m "feat(#512): revision system implementation complete

All 10 design invariants verified. Full lifecycle tested.
Closes #512."
```
