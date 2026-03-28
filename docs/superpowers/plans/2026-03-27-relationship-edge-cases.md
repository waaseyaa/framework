# Relationship Edge Case Test Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 10 new test methods and improve 4 existing tests to cover edge cases in the Relationship package's validator, pre-save listener, and delete guard.

**Architecture:** Add tests directly to the 3 existing test files using the shared fixtures from #677. One fixture enhancement (`getCallCount()` on `FixedResultEntityQuery`). All tests exercise code paths identified during #578 code review.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Waaseyaa entity interfaces, shared fixtures from `packages/relationship/tests/Fixtures/`

---

### Task 1: Add `getCallCount()` to FixedResultEntityQuery

**Files:**
- Modify: `packages/relationship/tests/Fixtures/FixedResultEntityQuery.php`

- [ ] **Step 1: Add the getter method**

Add after the `execute()` method:

```php
public function getCallCount(): int
{
    return $this->callCount;
}
```

- [ ] **Step 2: Verify static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Commit**

```bash
git add packages/relationship/tests/Fixtures/FixedResultEntityQuery.php
git commit -m "test(#678): add getCallCount() to FixedResultEntityQuery"
```

---

### Task 2: Add entity-not-found and UUID fallback tests to RelationshipValidatorTest

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipValidatorTest.php`

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipValidatorTest.php`
Expected: 24 tests, OK

- [ ] **Step 2: Add imports for new fixtures**

Add to the existing `use` block:

```php
use Waaseyaa\Relationship\Tests\Fixtures\FixedResultEntityQuery;
use Waaseyaa\Relationship\Tests\Fixtures\StubEntityStorage;
```

- [ ] **Step 3: Add entity-not-found test**

Add after the `validate_rejects_unknown_entity_type` test method (the last validate test before assertValid):

```php
#[Test]
public function validate_rejects_entity_not_found(): void
{
    $storage = new StubEntityStorage(
        loadHandler: static fn () => null,
    );
    $manager = new StubEntityTypeManager(['node'], $storage);
    $validator = new RelationshipValidator($manager);

    $errors = $validator->validate([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '999',
        'to_entity_type' => 'node',
        'to_entity_id' => '888',
        'directionality' => 'directed',
        'status' => 1,
    ]);

    $found = false;
    foreach ($errors as $error) {
        if (str_contains($error, 'missing entity')) {
            $found = true;
            break;
        }
    }
    $this->assertTrue($found, 'Expected missing entity error');
}
```

- [ ] **Step 4: Add UUID fallback success test**

Add after the entity-not-found test:

```php
#[Test]
public function validate_accepts_entity_found_by_uuid(): void
{
    $storage = new StubEntityStorage(
        loadHandler: static fn () => null,
        query: new FixedResultEntityQuery([[1], [1]]),
    );
    $manager = new StubEntityTypeManager(['node'], $storage);
    $validator = new RelationshipValidator($manager);

    $errors = $validator->validate([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
        'to_entity_type' => 'node',
        'to_entity_id' => 'f9e8d7c6-b5a4-3210-fedc-ba9876543210',
        'directionality' => 'directed',
        'status' => 1,
    ]);

    foreach ($errors as $error) {
        $this->assertStringNotContainsString('missing entity', $error);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipValidatorTest.php`
Expected: 26 tests, OK

- [ ] **Step 6: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipValidatorTest.php
git commit -m "test(#678): add entity-not-found and UUID fallback validator tests"
```

---

### Task 3: Add whitespace, status passthrough, and boundary confidence tests to RelationshipValidatorTest

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipValidatorTest.php`

- [ ] **Step 1: Add whitespace-only required fields test**

Add after the UUID fallback test:

```php
#[Test]
public function validate_rejects_whitespace_only_required_fields(): void
{
    $validator = $this->makeValidator();
    $errors = $validator->validate([
        'relationship_type' => '   ',
        'from_entity_type' => '   ',
        'from_entity_id' => '   ',
        'to_entity_type' => '   ',
        'to_entity_id' => '   ',
        'directionality' => '   ',
        'status' => 1,
    ]);

    $requiredFields = ['relationship_type', 'from_entity_type', 'from_entity_id', 'to_entity_type', 'to_entity_id', 'directionality'];
    foreach ($requiredFields as $field) {
        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, sprintf('"%s"', $field)) && str_contains($error, 'required')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Expected required field error for whitespace-only '$field'");
    }
}
```

- [ ] **Step 2: Add status passthrough test**

```php
#[Test]
public function normalize_passes_through_unsupported_status_types(): void
{
    $validator = $this->makeValidator();

    $arrayStatus = [1, 2, 3];
    $result = $validator->normalize(['status' => $arrayStatus]);
    $this->assertSame($arrayStatus, $result['status']);

    $objectStatus = new \stdClass();
    $result = $validator->normalize(['status' => $objectStatus]);
    $this->assertSame($objectStatus, $result['status']);
}
```

- [ ] **Step 3: Add boundary confidence tests**

```php
#[Test]
public function validate_accepts_boundary_confidence_zero(): void
{
    $manager = new StubEntityTypeManager(['node']);
    $validator = new RelationshipValidator($manager);
    $errors = $validator->validate([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '1',
        'to_entity_type' => 'node',
        'to_entity_id' => '2',
        'directionality' => 'directed',
        'status' => 1,
        'confidence' => 0.0,
    ]);
    $this->assertSame([], $errors);
}

#[Test]
public function validate_accepts_boundary_confidence_one(): void
{
    $manager = new StubEntityTypeManager(['node']);
    $validator = new RelationshipValidator($manager);
    $errors = $validator->validate([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '1',
        'to_entity_type' => 'node',
        'to_entity_id' => '2',
        'directionality' => 'directed',
        'status' => 1,
        'confidence' => 1.0,
    ]);
    $this->assertSame([], $errors);
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipValidatorTest.php`
Expected: 30 tests, OK

- [ ] **Step 5: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipValidatorTest.php
git commit -m "test(#678): add whitespace, status passthrough, and boundary confidence tests"
```

---

### Task 4: Add edge case tests to RelationshipPreSaveListenerTest

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`
Expected: 3 tests, OK

- [ ] **Step 2: Add null optional fields test**

Add after the `throws_on_invalid_relationship_data` test:

```php
#[Test]
public function normalizes_all_null_optional_fields(): void
{
    $entity = new Relationship([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '1',
        'to_entity_type' => 'node',
        'to_entity_id' => '2',
        'directionality' => 'directed',
        'status' => 1,
        'weight' => null,
        'confidence' => null,
        'start_date' => null,
        'end_date' => null,
    ]);

    $manager = new StubEntityTypeManager(['node']);
    $validator = new RelationshipValidator($manager);
    $listener = new RelationshipPreSaveListener($validator);

    $listener(new EntityEvent($entity));

    $this->assertNull($entity->get('weight'));
    $this->assertNull($entity->get('confidence'));
    $this->assertNull($entity->get('start_date'));
    $this->assertNull($entity->get('end_date'));
}
```

- [ ] **Step 3: Add boundary confidence through listener test**

```php
#[Test]
public function normalizes_boundary_confidence_values(): void
{
    $entity = new Relationship([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '1',
        'to_entity_type' => 'node',
        'to_entity_id' => '2',
        'directionality' => 'directed',
        'status' => 1,
        'confidence' => '0.0',
    ]);

    $manager = new StubEntityTypeManager(['node']);
    $validator = new RelationshipValidator($manager);
    $listener = new RelationshipPreSaveListener($validator);

    $listener(new EntityEvent($entity));

    $this->assertSame(0.0, $entity->get('confidence'));
}
```

- [ ] **Step 4: Add date normalization through listener test**

```php
#[Test]
public function normalizes_date_string_through_listener(): void
{
    $entity = new Relationship([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '1',
        'to_entity_type' => 'node',
        'to_entity_id' => '2',
        'directionality' => 'directed',
        'status' => 1,
        'start_date' => '2025-01-15',
    ]);

    $manager = new StubEntityTypeManager(['node']);
    $validator = new RelationshipValidator($manager);
    $listener = new RelationshipPreSaveListener($validator);

    $listener(new EntityEvent($entity));

    $this->assertIsInt($entity->get('start_date'));
    $this->assertGreaterThan(0, $entity->get('start_date'));
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php`
Expected: 6 tests, OK

- [ ] **Step 6: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipPreSaveListenerTest.php
git commit -m "test(#678): add null fields, boundary confidence, and date listener tests"
```

---

### Task 5: Improve delete guard tests and add direction verification

**Files:**
- Modify: `packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`
Expected: 8 tests, OK

- [ ] **Step 2: Replace `expectNotToPerformAssertions()` in early-return tests**

Update `ignores_non_guarded_entity_types`:

```php
#[Test]
public function ignores_non_guarded_entity_types(): void
{
    $entity = $this->makeEntity('taxonomy_term', 1);
    $manager = $this->makeManager();
    $listener = new RelationshipDeleteGuardListener($manager, 'node');

    $listener(new EntityEvent($entity));

    $this->addToAssertionCount(1);
}
```

Update `ignores_entities_with_null_id`:

```php
#[Test]
public function ignores_entities_with_null_id(): void
{
    $entity = $this->makeEntity('node', null);
    $manager = $this->makeManager();
    $listener = new RelationshipDeleteGuardListener($manager, 'node');

    $listener(new EntityEvent($entity));

    $this->addToAssertionCount(1);
}
```

Update `skips_when_relationship_type_not_defined`:

```php
#[Test]
public function skips_when_relationship_type_not_defined(): void
{
    $entity = $this->makeEntity('node', 1);
    $manager = $this->makeManager(hasRelationshipType: false);
    $listener = new RelationshipDeleteGuardListener($manager, 'node');

    $listener(new EntityEvent($entity));

    $this->addToAssertionCount(1);
}
```

- [ ] **Step 3: Replace `expectNotToPerformAssertions()` in allows-deletion test with query verification**

Update `allows_deletion_when_no_linked_relationships` to verify queries actually ran:

```php
#[Test]
public function allows_deletion_when_no_linked_relationships(): void
{
    $entity = $this->makeEntity('node', 1);
    $query = new FixedResultEntityQuery([[], []]);
    $storage = new StubEntityStorage(
        loadHandler: static fn () => null,
        query: $query,
        entityTypeId: 'relationship',
    );
    $hasDefinitionOverride = static fn (string $typeId): bool => true;
    $manager = new StubEntityTypeManager(
        knownTypes: [],
        storage: $storage,
        hasDefinitionOverride: $hasDefinitionOverride,
    );
    $listener = new RelationshipDeleteGuardListener($manager, 'node');

    $listener(new EntityEvent($entity));

    $this->assertSame(2, $query->getCallCount(), 'Expected both outbound and inbound queries to execute');
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php`
Expected: 8 tests, OK

- [ ] **Step 6: Commit**

```bash
git add packages/relationship/tests/Unit/RelationshipDeleteGuardListenerTest.php
git commit -m "test(#678): replace expectNotToPerformAssertions with real assertions in delete guard tests"
```

---

### Task 6: Final verification

- [ ] **Step 1: Run full Relationship test suite**

Run: `./vendor/bin/phpunit packages/relationship/tests/`
Expected: All tests passing (previous 65 + 9 new = ~74)

- [ ] **Step 2: Run code style check**

Run: `composer cs-check`
Expected: No new violations

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 4: Verify new test count**

Run: `./vendor/bin/phpunit packages/relationship/tests/ --list-tests 2>&1 | grep -c 'Test '`
Expected: ~74 tests listed
