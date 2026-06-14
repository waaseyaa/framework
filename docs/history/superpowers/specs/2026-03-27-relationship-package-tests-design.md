# Relationship Package Test Coverage — Design Spec

**Issue:** #578
**Date:** 2026-03-27
**Status:** Approved

## Problem

The Relationship package has 1,689 LOC across 10 source files but only 14 test methods in 3 test files. Four classes have zero test coverage, and two tested classes have minimal coverage (1-2 methods each).

## Scope

Unit tests only. All tests use in-memory storage (SQLite `:memory:` or mocks). No integration tests, performance tests, or cross-package graph traversal tests.

## Test Matrix

### New Test Files

#### 1. RelationshipTest.php
**Class:** `Relationship` (ContentEntityBase subclass)
**Tests:**
- Construction with default values (directionality='directed', status=1)
- Entity type ID is 'relationship'
- Entity keys mapping (rid, uuid, relationship_type)
- Field values passed through constructor
- Bundle key returns relationship_type value

#### 2. RelationshipValidatorTest.php
**Class:** `RelationshipValidator`
**Tests:**
- **normalize():**
  - Weight string→float coercion ("3.5" → 3.5)
  - Confidence string→float coercion
  - Status boolean→int coercion (true → 1, false → 0)
  - Status string→int coercion ("1" → 1)
  - Date string→timestamp coercion (ISO date string → Unix timestamp)
  - Date int passthrough (already timestamp)
  - Null optional fields left as null
  - Idempotent (normalize twice = same result)
- **validate():**
  - Empty array returns all required field errors
  - Missing relationship_type returns error
  - Missing from_entity_type/from_entity_id returns error
  - Missing to_entity_type/to_entity_id returns error
  - Invalid directionality value returns error
  - Valid complete entity returns empty errors array
  - Partial missing fields returns only relevant errors
- **assertValid():**
  - Valid entity does not throw
  - Invalid entity throws with error messages

#### 3. RelationshipSchemaManagerTest.php
**Class:** `RelationshipSchemaManager`
**Tests:**
- ensureSchema creates relationship table with all columns
- ensureSchema creates all 4 indexes (from_status, to_status, type_status, temporal)
- ensureSchema is idempotent (second call succeeds without error)
- Column types match expected definitions

#### 4. RelationshipPreSaveListenerTest.php
**Class:** `RelationshipPreSaveListener`
**Tests:**
- Normalizes relationship entity values on pre-save event
- Validates and throws on invalid relationship data
- Ignores non-relationship entity types (no-op)
- Updates entity fields with normalized values when FieldableInterface

#### 5. RelationshipDeleteGuardListenerTest.php
**Class:** `RelationshipDeleteGuardListener`
**Tests:**
- Blocks deletion when entity has linked relationships (throws RuntimeException)
- Exception message contains relationship IDs
- Allows deletion when no relationships exist
- Ignores entity types not in guard list
- Default guard applies to 'node' entity type

### Expanded Existing Tests

#### 6. RelationshipDiscoveryServiceTest.php (expand)
**Added tests:**
- edgeContext() returns from/to endpoint context for a relationship
- topicHub() with empty results returns zero counts
- topicHub() status filtering (published only, unpublished only, all)
- topicHub() temporal window filtering (only relationships active at given timestamp)

#### 7. RelationshipTraversalServiceTest.php (expand)
**Added tests:**
- loadRelationships() loads by entity endpoint
- browse() bidirectional relationships appear in both directions
- browse() type filtering restricts to specific relationship_type
- browse() deterministic sort: weight DESC → start_date ASC tie-breaking
- browse() with no matching relationships returns empty result

## Conventions

- PHPUnit 10.5 attributes: `#[Test]`, `#[CoversClass(...)]`
- Namespace: `Waaseyaa\Relationship\Tests\Unit\`
- In-memory SQLite via `DBALDatabase::createSqlite()` where SQL queries are needed
- Real instances preferred over mocks (final classes can't be mocked by PHPUnit)
- No `-v` flag on test runs
- `declare(strict_types=1)` in every file

## Out of Scope

- Integration tests (multi-package graph traversal, fixture corpus)
- Performance/stress tests (large-fanout graphs)
- Cycle detection tests (not implemented in source)
- Inverse/duplicate prevention tests (not fully implemented in source)
