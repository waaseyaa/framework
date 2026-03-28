# Relationship Package Test Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the Relationship package from 14 test methods to comprehensive unit test coverage across all 10 source files.

**Architecture:** Each untested class gets its own test file following existing patterns (anonymous class test doubles, `DBALDatabase::createSqlite()` for SQL-dependent tests, PHPUnit 10.5 attributes). Existing test files are not modified — they already have good coverage for their classes.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, DBALDatabase (SQLite in-memory), anonymous class test doubles

**Spec:** `docs/superpowers/specs/2026-03-27-relationship-package-tests-design.md`

---

### File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `packages/relationship/tests/Unit/RelationshipTest.php` | Entity construction, defaults, keys |
| Create | `packages/relationship/tests/Unit/RelationshipValidatorTest.php` | Normalize, validate, assertValid |
| Create | `packages/relationship/tests/Unit/RelationshipSchemaManagerTest.php` | Table column/index ensuring |
| Create | `packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php` | Event-driven normalization + validation |
| Create | `packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php` | Cascade delete prevention |

---

### Task 1: RelationshipTest — Entity Construction

**Files:**
- Create: `packages/relationship/tests/Unit/RelationshipTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Relationship\Relationship;

#[CoversClass(Relationship::class)]
final class RelationshipTest extends TestCase
{
    #[Test]
    public function entity_type_id_is_relationship(): void
    {
        $entity = new Relationship();
        $this->assertSame('relationship', $entity->getEntityTypeId());
    }

    #[Test]
    public function default_values_are_applied(): void
    {
        $entity = new Relationship();
        $this->assertSame('directed', $entity->get('directionality'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function constructor_values_override_defaults(): void
    {
        $entity = new Relationship([
            'directionality' => 'bidirectional',
            'status' => 0,
        ]);
        $this->assertSame('bidirectional', $entity->get('directionality'));
        $this->assertSame(0, $entity->get('status'));
    }

    #[Test]
    public function entity_keys_map_correctly(): void
    {
        $entity = new Relationship([
            'rid' => 42,
            'uuid' => 'abc-123',
            'relationship_type' => 'references',
        ]);
        $this->assertSame(42, $entity->id());
        $this->assertSame('abc-123', $entity->uuid());
        $this->assertSame('references', $entity->label());
        $this->assertSame('references', $entity->bundle());
    }

    #[Test]
    public function implements_content_entity_and_fieldable_interfaces(): void
    {
        $entity = new Relationship();
        $this->assertInstanceOf(ContentEntityInterface::class, $entity);
        $this->assertInstanceOf(FieldableInterface::class, $entity);
    }

    #[Test]
    public function custom_fields_are_accessible(): void
    {
        $entity = new Relationship([
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'weight' => 5.0,
            'confidence' => 0.9,
            'notes' => 'Test note',
        ]);
        $this->assertSame('node', $entity->get('from_entity_type'));
        $this->assertSame('1', $entity->get('from_entity_id'));
        $this->assertSame('node', $entity->get('to_entity_type'));
        $this->assertSame('2', $entity->get('to_entity_id'));
        $this->assertSame(5.0, $entity->get('weight'));
        $this->assertSame(0.9, $entity->get('confidence'));
        $this->assertSame('Test note', $entity->get('notes'));
    }

    #[Test]
    public function set_mutates_field_values(): void
    {
        $entity = new Relationship(['weight' => 1.0]);
        $entity->set('weight', 5.0);
        $this->assertSame(5.0, $entity->get('weight'));
    }

    #[Test]
    public function to_array_returns_all_values(): void
    {
        $entity = new Relationship([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
        ]);
        $array = $entity->toArray();
        $this->assertSame('references', $array['relationship_type']);
        $this->assertSame('node', $array['from_entity_type']);
        $this->assertSame('directed', $array['directionality']);
        $this->assertSame(1, $array['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipTest.php`
Expected: 8 tests, 8 assertions, all pass

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipTest.php
git commit -m "test(#578): add unit tests for Relationship entity construction and defaults"
```

---

### Task 2: RelationshipValidatorTest — Normalization

**Files:**
- Create: `packages/relationship/tests/Unit/RelationshipValidatorTest.php`

The validator depends on `EntityTypeManagerInterface`. For normalize-only tests, the manager is not called. For validate tests that check endpoint existence, we need a test double.

- [ ] **Step 1: Write the test file with normalize tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Relationship\RelationshipValidator;

#[CoversClass(RelationshipValidator::class)]
final class RelationshipValidatorTest extends TestCase
{
    private function makeValidator(?EntityTypeManagerInterface $manager = null): RelationshipValidator
    {
        return new RelationshipValidator($manager ?? new ValidatorStubEntityTypeManager([]));
    }

    // -----------------------------------------------------------------------
    // normalize()
    // -----------------------------------------------------------------------

    #[Test]
    public function normalize_trims_string_fields(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize([
            'relationship_type' => '  references  ',
            'from_entity_type' => ' node ',
            'source_ref' => '  http://example.com  ',
        ]);
        $this->assertSame('references', $result['relationship_type']);
        $this->assertSame('node', $result['from_entity_type']);
        $this->assertSame('http://example.com', $result['source_ref']);
    }

    #[Test]
    public function normalize_coerces_status_boolean_to_int(): void
    {
        $validator = $this->makeValidator();
        $this->assertSame(1, $validator->normalize(['status' => true])['status']);
        $this->assertSame(0, $validator->normalize(['status' => false])['status']);
    }

    #[Test]
    public function normalize_coerces_status_string_to_int(): void
    {
        $validator = $this->makeValidator();
        $this->assertSame(1, $validator->normalize(['status' => '1'])['status']);
        $this->assertSame(0, $validator->normalize(['status' => '0'])['status']);
        $this->assertSame(1, $validator->normalize(['status' => 'true'])['status']);
        $this->assertSame(0, $validator->normalize(['status' => 'false'])['status']);
    }

    #[Test]
    public function normalize_coerces_weight_string_to_float(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['weight' => '3.5']);
        $this->assertSame(3.5, $result['weight']);
    }

    #[Test]
    public function normalize_coerces_confidence_string_to_float(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['confidence' => '0.85']);
        $this->assertSame(0.85, $result['confidence']);
    }

    #[Test]
    public function normalize_leaves_null_optional_fields_as_null(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['weight' => null, 'confidence' => null]);
        $this->assertNull($result['weight']);
        $this->assertNull($result['confidence']);
    }

    #[Test]
    public function normalize_leaves_empty_string_optional_fields_unchanged(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['weight' => '', 'confidence' => '']);
        $this->assertSame('', $result['weight']);
        $this->assertSame('', $result['confidence']);
    }

    #[Test]
    public function normalize_converts_date_string_to_timestamp(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => '2025-01-15']);
        $this->assertIsInt($result['start_date']);
        $this->assertGreaterThan(0, $result['start_date']);
    }

    #[Test]
    public function normalize_passes_through_date_int(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => 1700000000]);
        $this->assertSame(1700000000, $result['start_date']);
    }

    #[Test]
    public function normalize_converts_numeric_date_string_to_int(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['end_date' => '1700000000']);
        $this->assertSame(1700000000, $result['end_date']);
    }

    #[Test]
    public function normalize_converts_empty_date_string_to_null(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => '', 'end_date' => '  ']);
        $this->assertNull($result['start_date']);
        $this->assertNull($result['end_date']);
    }

    #[Test]
    public function normalize_converts_null_date_to_null(): void
    {
        $validator = $this->makeValidator();
        $result = $validator->normalize(['start_date' => null]);
        $this->assertNull($result['start_date']);
    }

    #[Test]
    public function normalize_is_idempotent(): void
    {
        $validator = $this->makeValidator();
        $input = [
            'relationship_type' => '  references  ',
            'status' => 'true',
            'weight' => '2.5',
            'start_date' => '2025-01-01',
        ];
        $first = $validator->normalize($input);
        $second = $validator->normalize($first);
        $this->assertSame($first, $second);
    }

    // -----------------------------------------------------------------------
    // validate()
    // -----------------------------------------------------------------------

    #[Test]
    public function validate_returns_errors_for_all_missing_required_fields(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([]);
        $this->assertNotEmpty($errors);
        $requiredFields = ['relationship_type', 'from_entity_type', 'from_entity_id', 'to_entity_type', 'to_entity_id', 'directionality', 'status'];
        foreach ($requiredFields as $field) {
            $found = false;
            foreach ($errors as $error) {
                if (str_contains($error, sprintf('"%s"', $field))) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected error for required field '$field'");
        }
    }

    #[Test]
    public function validate_accepts_valid_complete_entity(): void
    {
        $manager = new ValidatorStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_invalid_directionality(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'sideways',
            'status' => 1,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'directionality')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected directionality validation error');
    }

    #[Test]
    public function validate_rejects_invalid_relationship_type_format(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'Invalid-Type!',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'relationship_type') && str_contains($error, 'match')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected relationship_type format validation error');
    }

    #[Test]
    public function validate_rejects_confidence_out_of_range(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => 1.5,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'confidence')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected confidence out-of-range error');
    }

    #[Test]
    public function validate_accepts_confidence_in_range(): void
    {
        $manager = new ValidatorStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => 0.5,
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_non_numeric_confidence(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'confidence' => 'high',
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'confidence')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected confidence non-numeric error');
    }

    #[Test]
    public function validate_rejects_start_date_after_end_date(): void
    {
        $manager = new ValidatorStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'start_date' => 2000,
            'end_date' => 1000,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'start_date') && str_contains($error, 'end_date')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected start_date > end_date error');
    }

    #[Test]
    public function validate_rejects_unparseable_date_string(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
            'start_date' => 'not-a-date-$$',
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'start_date')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected unparseable start_date error');
    }

    #[Test]
    public function validate_rejects_unknown_entity_type(): void
    {
        $manager = new ValidatorStubEntityTypeManager([]);
        $validator = new RelationshipValidator($manager);
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'nonexistent',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'unknown entity type')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected unknown entity type error');
    }

    #[Test]
    public function validate_rejects_invalid_status_value(): void
    {
        $validator = $this->makeValidator();
        $errors = $validator->validate([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 99,
        ]);
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'status')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected invalid status error');
    }

    // -----------------------------------------------------------------------
    // assertValid()
    // -----------------------------------------------------------------------

    #[Test]
    public function assert_valid_does_not_throw_for_valid_entity(): void
    {
        $manager = new ValidatorStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $this->expectNotToPerformAssertions();
        $validator->assertValid([
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);
    }

    #[Test]
    public function assert_valid_throws_for_invalid_entity(): void
    {
        $validator = $this->makeValidator();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship validation failed');
        $validator->assertValid([]);
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/** @internal */
final class ValidatorStubEntityTypeManager implements EntityTypeManagerInterface
{
    /** @param list<string> $knownTypes Entity types that "exist" with a loadable entity at ID "1" and "2" */
    public function __construct(private readonly array $knownTypes) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return new class($entityTypeId) implements EntityTypeInterface {
            public function __construct(private readonly string $id) {}
            public function id(): string { return $this->id; }
            public function label(): string { return $this->id; }
            public function getClass(): string { return ''; }
            public function getKeys(): array { return ['id' => 'id', 'uuid' => 'uuid']; }
            public function getFieldDefinitions(): array { return []; }
            public function getConstraints(): array { return []; }
        };
    }

    public function getDefinitions(): array { return []; }

    public function hasDefinition(string $entityTypeId): bool
    {
        return in_array($entityTypeId, $this->knownTypes, true);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return new ValidatorStubEntityStorage();
    }

    public function registerEntityType(EntityTypeInterface $type): void {}
    public function registerCoreEntityType(EntityTypeInterface $type): void {}
}

/** @internal */
final class ValidatorStubEntityStorage implements EntityStorageInterface
{
    public function create(array $values = []): EntityInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        // All entities "exist" for validation tests
        return new class($id) implements EntityInterface {
            public function __construct(private readonly int|string $id) {}
            public function id(): int|string|null { return $this->id; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }
    public function loadMultiple(array $ids = []): array { return []; }
    public function save(EntityInterface $entity): int { throw new \RuntimeException('Not needed.'); }
    public function delete(array $entities): void {}

    public function getQuery(): EntityQueryInterface
    {
        return new ValidatorStubEntityQuery();
    }

    public function getEntityTypeId(): string { return 'node'; }
}

/** @internal */
final class ValidatorStubEntityQuery implements EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }
    public function execute(): array { return []; }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipValidatorTest.php`
Expected: 25 tests, all pass

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipValidatorTest.php
git commit -m "test(#578): add unit tests for RelationshipValidator normalize/validate/assertValid"
```

---

### Task 3: RelationshipSchemaManagerTest — Schema Ensuring

**Files:**
- Create: `packages/relationship/tests/Unit/RelationshipSchemaManagerTest.php`

The schema manager checks if the `relationship` table exists and adds missing columns/indexes. It uses `DatabaseInterface::schema()` and raw `query()` for index checking. We need a real SQLite database to test this.

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Relationship\RelationshipSchemaManager;

#[CoversClass(RelationshipSchemaManager::class)]
final class RelationshipSchemaManagerTest extends TestCase
{
    #[Test]
    public function ensure_does_nothing_when_table_does_not_exist(): void
    {
        $database = DBALDatabase::createSqlite();
        $manager = new RelationshipSchemaManager($database);

        // Should not throw — early return when table doesn't exist
        $this->expectNotToPerformAssertions();
        $manager->ensure();
    }

    #[Test]
    public function ensure_adds_missing_columns_to_existing_table(): void
    {
        $database = DBALDatabase::createSqlite();
        // Create a minimal relationship table missing most columns
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
CREATE TABLE relationship (
  rid INTEGER PRIMARY KEY,
  relationship_type TEXT NOT NULL
)
SQL);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();

        // Verify columns were added by checking pragma
        $columns = $this->getColumnNames($database);
        $expectedColumns = [
            'from_entity_type', 'from_entity_id',
            'to_entity_type', 'to_entity_id',
            'directionality', 'status', 'weight',
            'start_date', 'end_date', 'confidence',
            'source_ref', 'notes',
        ];
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columns, "Column '$col' should exist after ensure()");
        }
    }

    #[Test]
    public function ensure_creates_all_four_indexes(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
CREATE TABLE relationship (
  rid INTEGER PRIMARY KEY,
  relationship_type TEXT NOT NULL,
  from_entity_type TEXT NOT NULL DEFAULT '',
  from_entity_id TEXT NOT NULL DEFAULT '',
  to_entity_type TEXT NOT NULL DEFAULT '',
  to_entity_id TEXT NOT NULL DEFAULT '',
  directionality TEXT NOT NULL DEFAULT 'directed',
  status INTEGER NOT NULL DEFAULT 1,
  weight REAL,
  start_date INTEGER,
  end_date INTEGER,
  confidence REAL,
  source_ref TEXT,
  notes TEXT
)
SQL);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();

        $indexes = $this->getIndexNames($database);
        $this->assertContains('relationship_from_status_idx', $indexes);
        $this->assertContains('relationship_to_status_idx', $indexes);
        $this->assertContains('relationship_type_status_idx', $indexes);
        $this->assertContains('relationship_temporal_idx', $indexes);
    }

    #[Test]
    public function ensure_is_idempotent(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->getNativeConnection()->exec(<<<SQL
CREATE TABLE relationship (
  rid INTEGER PRIMARY KEY,
  relationship_type TEXT NOT NULL
)
SQL);

        $manager = new RelationshipSchemaManager($database);
        $manager->ensure();
        // Second call should not throw or duplicate indexes
        $manager->ensure();

        $indexes = $this->getIndexNames($database);
        $this->assertCount(1, array_keys(array_count_values($indexes), 'relationship_from_status_idx'));
    }

    /** @return list<string> */
    private function getColumnNames(DBALDatabase $database): array
    {
        $rows = $database->query("PRAGMA table_info('relationship')");
        $columns = [];
        foreach ($rows as $row) {
            $columns[] = $row['name'];
        }
        return $columns;
    }

    /** @return list<string> */
    private function getIndexNames(DBALDatabase $database): array
    {
        $rows = $database->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'relationship'");
        $names = [];
        foreach ($rows as $row) {
            $names[] = $row['name'];
        }
        return $names;
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipSchemaManagerTest.php`
Expected: 4 tests, all pass

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipSchemaManagerTest.php
git commit -m "test(#578): add unit tests for RelationshipSchemaManager column and index ensuring"
```

---

### Task 4: RelationshipPreSaveListenerTest — Event-Driven Normalization

**Files:**
- Create: `packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`

The listener takes an `EntityEvent`, checks entity type, normalizes via validator, then asserts validity. It updates entity fields if the entity implements `FieldableInterface`.

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipPreSaveListener;
use Waaseyaa\Relationship\RelationshipValidator;

#[CoversClass(RelationshipPreSaveListener::class)]
final class RelationshipPreSaveListenerTest extends TestCase
{
    #[Test]
    public function ignores_non_relationship_entities(): void
    {
        $entity = new class implements EntityInterface {
            public function id(): int|string|null { return 1; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };

        $manager = new PreSaveStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        // Should not throw — early return for non-relationship entity
        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function normalizes_and_updates_relationship_entity_fields(): void
    {
        $entity = new Relationship([
            'relationship_type' => '  references  ',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 'true',
            'weight' => '3.5',
        ]);

        $manager = new PreSaveStubEntityTypeManager(['node']);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $listener(new EntityEvent($entity));

        // Relationship extends ContentEntityBase (FieldableInterface) so fields should be updated
        $this->assertSame('references', $entity->get('relationship_type'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame(3.5, $entity->get('weight'));
    }

    #[Test]
    public function throws_on_invalid_relationship_data(): void
    {
        $entity = new Relationship([
            // Missing required fields
            'relationship_type' => '',
            'directionality' => 'invalid',
        ]);

        $manager = new PreSaveStubEntityTypeManager([]);
        $validator = new RelationshipValidator($manager);
        $listener = new RelationshipPreSaveListener($validator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship validation failed');
        $listener(new EntityEvent($entity));
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/** @internal */
final class PreSaveStubEntityTypeManager implements EntityTypeManagerInterface
{
    /** @param list<string> $knownTypes */
    public function __construct(private readonly array $knownTypes) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return new class($entityTypeId) implements EntityTypeInterface {
            public function __construct(private readonly string $id) {}
            public function id(): string { return $this->id; }
            public function label(): string { return $this->id; }
            public function getClass(): string { return ''; }
            public function getKeys(): array { return ['id' => 'id', 'uuid' => 'uuid']; }
            public function getFieldDefinitions(): array { return []; }
            public function getConstraints(): array { return []; }
        };
    }

    public function getDefinitions(): array { return []; }

    public function hasDefinition(string $entityTypeId): bool
    {
        return in_array($entityTypeId, $this->knownTypes, true);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return new PreSaveStubEntityStorage();
    }

    public function registerEntityType(EntityTypeInterface $type): void {}
    public function registerCoreEntityType(EntityTypeInterface $type): void {}
}

/** @internal */
final class PreSaveStubEntityStorage implements EntityStorageInterface
{
    public function create(array $values = []): EntityInterface { throw new \RuntimeException('Not needed.'); }

    public function load(int|string $id): ?EntityInterface
    {
        return new class($id) implements EntityInterface {
            public function __construct(private readonly int|string $id) {}
            public function id(): int|string|null { return $this->id; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return 'node'; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }
    public function loadMultiple(array $ids = []): array { return []; }
    public function save(EntityInterface $entity): int { throw new \RuntimeException('Not needed.'); }
    public function delete(array $entities): void {}

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        return new PreSaveStubEntityQuery();
    }

    public function getEntityTypeId(): string { return 'node'; }
}

/** @internal */
final class PreSaveStubEntityQuery implements \Waaseyaa\Entity\Storage\EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }
    public function execute(): array { return []; }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`
Expected: 3 tests, all pass

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php
git commit -m "test(#578): add unit tests for RelationshipPreSaveListener normalization and validation"
```

---

### Task 5: RelationshipDeleteGuardListenerTest — Cascade Prevention

**Files:**
- Create: `packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`

The listener queries for linked relationships and throws `RuntimeException` if any exist. We need an `EntityTypeManagerInterface` that provides a storage with a working `getQuery()`.

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Relationship\RelationshipDeleteGuardListener;

#[CoversClass(RelationshipDeleteGuardListener::class)]
final class RelationshipDeleteGuardListenerTest extends TestCase
{
    #[Test]
    public function ignores_non_guarded_entity_types(): void
    {
        $entity = $this->makeEntity('taxonomy_term', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: []);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        // Should not throw — entity type doesn't match guarded type
        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function ignores_entities_with_null_id(): void
    {
        $entity = $this->makeEntity('node', null);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: []);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function allows_deletion_when_no_linked_relationships(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: []);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function blocks_deletion_when_relationships_exist(): void
    {
        $entity = $this->makeEntity('node', 42);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [10, 20, 30]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Safe-delete blocked for node 42');
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function exception_message_contains_sorted_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 5);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [30, 10, 20]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('10, 20, 30', $e->getMessage());
        }
    }

    #[Test]
    public function defaults_to_guarding_node_entity_type(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [99]);
        // No second arg — defaults to 'node'
        $listener = new RelationshipDeleteGuardListener($manager);

        $this->expectException(\RuntimeException::class);
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function skips_when_relationship_type_not_defined(): void
    {
        $entity = $this->makeEntity('node', 1);
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [], hasRelationshipType: false);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        $this->expectNotToPerformAssertions();
        $listener(new EntityEvent($entity));
    }

    #[Test]
    public function deduplicates_outbound_and_inbound_relationship_ids(): void
    {
        $entity = $this->makeEntity('node', 1);
        // Same ID appears in both outbound and inbound — should be deduped
        $manager = new DeleteGuardStubEntityTypeManager(linkedIds: [5], inboundIds: [5]);
        $listener = new RelationshipDeleteGuardListener($manager, 'node');

        try {
            $listener(new EntityEvent($entity));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            // Should only list "5" once, not "5, 5"
            $this->assertStringContainsString('[5]', $e->getMessage());
            $this->assertStringNotContainsString('5, 5', $e->getMessage());
        }
    }

    private function makeEntity(string $entityTypeId, int|string|null $id): EntityInterface
    {
        return new class($entityTypeId, $id) implements EntityInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly int|string|null $id,
            ) {}
            public function id(): int|string|null { return $this->id; }
            public function uuid(): string { return ''; }
            public function label(): string { return 'test'; }
            public function getEntityTypeId(): string { return $this->entityTypeId; }
            public function bundle(): string { return 'default'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
        };
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/** @internal */
final class DeleteGuardStubEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param list<int|string> $linkedIds IDs returned for outbound query
     * @param list<int|string> $inboundIds IDs returned for inbound query (defaults to empty)
     */
    public function __construct(
        private readonly array $linkedIds,
        private readonly array $inboundIds = [],
        private readonly bool $hasRelationshipType = true,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        throw new \RuntimeException('Not needed in test.');
    }

    public function getDefinitions(): array { return []; }

    public function hasDefinition(string $entityTypeId): bool
    {
        if ($entityTypeId === 'relationship') {
            return $this->hasRelationshipType;
        }
        return true;
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return new DeleteGuardStubStorage($this->linkedIds, $this->inboundIds);
    }

    public function registerEntityType(EntityTypeInterface $type): void {}
    public function registerCoreEntityType(EntityTypeInterface $type): void {}
}

/** @internal */
final class DeleteGuardStubStorage implements EntityStorageInterface
{
    private int $queryCallCount = 0;

    /**
     * @param list<int|string> $outboundIds
     * @param list<int|string> $inboundIds
     */
    public function __construct(
        private readonly array $outboundIds,
        private readonly array $inboundIds = [],
    ) {}

    public function create(array $values = []): EntityInterface { throw new \RuntimeException('Not needed.'); }
    public function load(int|string $id): ?EntityInterface { return null; }
    public function loadByKey(string $key, mixed $value): ?EntityInterface { return null; }
    public function loadMultiple(array $ids = []): array { return []; }
    public function save(EntityInterface $entity): int { throw new \RuntimeException('Not needed.'); }
    public function delete(array $entities): void {}
    public function getEntityTypeId(): string { return 'relationship'; }

    public function getQuery(): EntityQueryInterface
    {
        $this->queryCallCount++;
        // First call = outbound query, second call = inbound query
        $ids = $this->queryCallCount <= 1 ? $this->outboundIds : $this->inboundIds;
        return new DeleteGuardStubQuery($ids);
    }
}

/** @internal */
final class DeleteGuardStubQuery implements EntityQueryInterface
{
    /** @param list<int|string> $resultIds */
    public function __construct(private readonly array $resultIds) {}

    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }
    public function execute(): array { return $this->resultIds; }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`
Expected: 8 tests, all pass

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php
git commit -m "test(#578): add unit tests for RelationshipDeleteGuardListener cascade prevention"
```

---

### Task 6: Run Full Test Suite and Verify

- [ ] **Step 1: Run all relationship package tests**

Run: `./vendor/bin/phpunit packages/relationship/tests/`
Expected: All tests pass (existing + new)

- [ ] **Step 2: Run full project test suite to verify no regressions**

Run: `./vendor/bin/phpunit`
Expected: No new failures

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No new errors from test files

- [ ] **Step 4: Run code style check**

Run: `composer cs-check`
Expected: No violations in new test files

- [ ] **Step 5: Fix any issues found, commit fixes**

If any issues, fix and commit with: `git commit -m "fix(#578): address test suite issues"`

---

### Task 7: Final Commit and Summary

- [ ] **Step 1: Verify final test count**

Run: `./vendor/bin/phpunit packages/relationship/tests/ --list-tests 2>&1 | tail -1`
Expected: Shows total test count (should be ~50+ methods)

- [ ] **Step 2: Create summary commit if needed**

If any loose changes remain:
```bash
git add packages/relationship/tests/
git commit -m "test(#578): complete Relationship package test coverage"
```
