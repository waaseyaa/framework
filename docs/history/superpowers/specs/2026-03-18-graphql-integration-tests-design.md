# GraphQL Integration Tests with SQLite Storage

**Issue:** waaseyaa#431
**Date:** 2026-03-18
**Status:** Design

## Problem

The `packages/graphql/` unit tests verify schema building and endpoint parsing but use stubbed entity types with no real storage. There are no tests exercising the full query/mutation round-trip through real SQLite storage.

## Goal

Add integration tests that exercise the complete GraphQL pipeline: HTTP request parsing, schema generation, query resolution, entity storage, reference loading, access control, and response formatting, all backed by real in-memory SQLite via `PdoDatabase::createSqlite()`.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Test location | `tests/Integration/GraphQL/` | Cross-package tests belong in the root integration directory |
| Test level | `GraphQlEndpoint::handle()` (black box) | Full-stack coverage; catches wiring, schema, parsing, resolving, storage, and access in one pass |
| Access policies | Simple deny-by-ID/field for most tests; one role-based test for realism | Isolates GraphQL behavior without coupling to the access-policy framework |
| Reference graph | Linear chain + circular self-reference | Minimal setup that exercises multi-hop resolution, batch loading, cycle detection, and depth limiting |

## Test Entity Types

### article

| Field | Type | Notes |
|-------|------|-------|
| id | integer | Primary key |
| title | string (required) | |
| body | text | |
| author_id | entity_reference -> author | Linear chain |
| related_article_id | entity_reference -> article | Circular self-reference |

### author

| Field | Type | Notes |
|-------|------|-------|
| id | integer | Primary key |
| name | string (required) | |
| bio | text | |
| secret | string | Field-level access test target |
| organization_id | entity_reference -> organization | Second hop in chain |

### organization

| Field | Type | Notes |
|-------|------|-------|
| id | integer | Primary key |
| name | string (required) | |
| location | string | |

## Base Test Class

`GraphQlIntegrationTestBase` in `tests/Integration/GraphQL/`:

### setUp() sequence

0. `SchemaFactory::resetCache()` — clear static schema cache to prevent cross-test contamination
1. `PdoDatabase::createSqlite()` — in-memory database
2. `EntityTypeManager` — register article, author, organization with `fieldDefinitions`
3. `SqlSchemaHandler::ensureTable()` x3 — create tables
4. `SqlEntityStorage` x3 — one per entity type
5. Seed test data (see below)
6. `DenyByIdPolicy(article, id=2)` — entity-level deny
7. `RestrictFieldPolicy(author, "secret")` — field-level deny
8. `EntityAccessHandler([policies])`
9. `GraphQlEndpoint` wired with all dependencies

### Seed data

| Entity | ID | Key fields | References |
|--------|----|------------|------------|
| organization | 1 | name="Acme" | — |
| author | 1 | name="Alice", secret="classified" | organization_id=1 |
| author | 2 | name="Bob", secret="redacted" | organization_id=1 |
| article | 1 | title="Hello" | author_id=1, related_article_id=2 |
| article | 2 | title="World" | author_id=2, related_article_id=1 |

Article 2 is denied by `DenyByIdPolicy`. Author `secret` field is denied by `RestrictFieldPolicy`.

### Helper methods

- `query(string $graphql, array $vars = []): array` — sends POST to `endpoint->handle()`, returns decoded response
- `assertNoErrors(array $response): void` — asserts no `errors` key in response
- `assertHasError(array $response, string $messageFragment): void` — asserts error message present

## Test Policies

Located in `tests/Integration/GraphQL/Policy/`:

### DenyByIdPolicy

Implements `EntityAccessPolicyInterface`. Only overrides the `view` operation check: returns `AccessResult::forbidden()` when entity type, ID, and operation (`view`) match the configured deny target. All other operations (create, update, delete) and non-matching entities return `AccessResult::neutral()`.

### RestrictFieldPolicy

Implements `FieldAccessPolicyInterface`. Returns `AccessResult::forbidden()` for the configured field name on the configured entity type. Returns `AccessResult::neutral()` otherwise.

## Test Matrix

### GraphQlCrudTest (tests 1-4)

| # | Test | Query | Asserts |
|---|------|-------|---------|
| 1 | List returns persisted entities | `{ articleList { items { title } total } }` | items contains article1 only (article2 denied), total=2 (count query runs without access filtering) |
| 2 | Create mutation persists and returns | `mutation { createArticle(input: {title:"New"}) { id title } }` | Returns id + title; subsequent query finds it |
| 3 | Update mutation modifies entity | `mutation { updateArticle(id:"1", input:{title:"Updated"}) { title } }` | Returns "Updated" |
| 4 | Delete mutation removes entity | Create article3, then `mutation { deleteArticle(id:"3") { deleted } }` | Returns true; subsequent query returns null |

### GraphQlAccessTest (tests 5-6)

| # | Test | Query | Asserts |
|---|------|-------|---------|
| 5 | Access-denied entities excluded from list | `{ articleList { items { id } total } }` | article2 absent from items, total=2 (unfiltered count), no errors (silent filter) |
| 6 | Field-level access filters restricted fields | `{ author(id:"1") { name secret } }` | name present, secret null or absent |

### GraphQlReferenceTest (tests 7-9)

| # | Test | Query | Asserts |
|---|------|-------|---------|
| 7 | Reference resolves nested entity | `{ article(id:"1") { title author { name } } }` | author.name = "Alice" |
| 8 | Multi-hop reference resolves | `{ article(id:"1") { author { organization { name } } } }` | organization.name = "Acme" |
| 9 | Circular reference respects depth limit | `{ article(id:"1") { relatedArticle { relatedArticle { relatedArticle { title } } } } }` | Returns null beyond maxDepth |

### GraphQlRoleBasedAccessTest (test 10)

| # | Test | Query | Asserts |
|---|------|-------|---------|
| 10 | Role-based access patterns | Same list/detail queries with admin, anonymous, and member accounts | Admin sees all entities + fields; anonymous sees nothing; member sees filtered results |

This test replaces `DenyByIdPolicy`/`RestrictFieldPolicy` with a role-aware policy that checks account roles. It constructs three separate `GraphQlEndpoint` instances, one per account (admin, anonymous, member), since the endpoint takes `AccountInterface` at construction time (not per-request).

## File Layout

```
tests/Integration/GraphQL/
├── GraphQlIntegrationTestBase.php
├── GraphQlCrudTest.php
├── GraphQlAccessTest.php
├── GraphQlReferenceTest.php
├── GraphQlRoleBasedAccessTest.php
└── Policy/
    ├── DenyByIdPolicy.php
    └── RestrictFieldPolicy.php
```

## Out of Scope

- Performance benchmarking or load testing
- Testing GraphQL subscriptions (not supported)
- Testing custom mutation overrides (covered by existing unit tests)
- Schema introspection tests (covered by existing unit tests)
- Filter/sort on `_data` blob fields (separate issue #438)
- Pagination argument validation (separate issue #440)
- Create/update input type separation (separate issue #439)
