# Claudriel GraphQL Adoption — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate Claudriel's frontend data-loading from JSON:API to GraphQL for Commitments + People, eliminating in-memory filtering and multi-roundtrip patterns.

**Architecture:** Schema-first migration — validate Waaseyaa's auto-generated GraphQL schema supports Claudriel's query needs, fix gaps in the framework, then incrementally migrate frontend composables and retire custom controllers.

**Tech Stack:** PHP 8.3+ (Waaseyaa GraphQL package, webonyx/graphql-php), TypeScript (Nuxt 3 composables), PHPUnit 10.5

**Design spec:** `docs/history/superpowers/specs/2026-03-16-claudriel-graphql-adoption-design.md`

---

## Chunk 1: Waaseyaa Schema Validation (framework repo)

All tasks in this chunk are in the `waaseyaa/framework` repo, milestone v1.3 (#24).

### Task 1: Schema validation test harness ([#437](https://github.com/waaseyaa/framework/issues/437))

**Files:**
- Create: `packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`

- [ ] **Step 1: Write the schema introspection test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SchemaFactory::class)]
final class SchemaValidationTest extends TestCase
{
    private function buildSchema(array $entityTypes): \GraphQL\Type\Schema
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        foreach ($entityTypes as $et) {
            $manager->addDefinition($et);
        }

        $resolver = $this->createMock(EntityResolver::class);
        $referenceLoader = $this->createMock(ReferenceLoader::class);

        $factory = new SchemaFactory($manager, $resolver, $referenceLoader);
        return $factory->build();
    }

    #[Test]
    public function queryFieldsAreGeneratedForEntityType(): void
    {
        $schema = $this->buildSchema([
            new EntityType(
                id: 'article',
                label: 'Article',
                entityKeys: ['id' => 'aid', 'uuid' => 'uuid'],
                entityClass: \stdClass::class,
                fieldDefinitions: [
                    'aid' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string'],
                    'title' => ['type' => 'string', 'required' => true],
                    'score' => ['type' => 'float'],
                ],
            ),
        ]);

        $queryType = $schema->getQueryType();
        $this->assertNotNull($queryType);
        $this->assertTrue($queryType->hasField('article'), 'Missing single query field');
        $this->assertTrue($queryType->hasField('articleList'), 'Missing list query field');

        // Single query accepts id: ID!
        $articleField = $queryType->getField('article');
        $idArg = $articleField->getArg('id');
        $this->assertInstanceOf(NonNull::class, $idArg->getType());

        // List query accepts filter, sort, offset, limit
        $listField = $queryType->getField('articleList');
        $this->assertNotNull($listField->getArg('filter'));
        $this->assertNotNull($listField->getArg('sort'));
        $this->assertNotNull($listField->getArg('offset'));
        $this->assertNotNull($listField->getArg('limit'));
    }

    #[Test]
    public function listResultTypeHasItemsAndTotal(): void
    {
        $schema = $this->buildSchema([
            new EntityType(
                id: 'article',
                label: 'Article',
                entityKeys: ['id' => 'aid', 'uuid' => 'uuid'],
                entityClass: \stdClass::class,
                fieldDefinitions: [
                    'aid' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                ],
            ),
        ]);

        $listResultType = $schema->getType('ArticleListResult');
        $this->assertInstanceOf(ObjectType::class, $listResultType);
        $this->assertTrue($listResultType->hasField('items'));
        $this->assertTrue($listResultType->hasField('total'));
    }

    #[Test]
    public function mutationFieldsAreGenerated(): void
    {
        $schema = $this->buildSchema([
            new EntityType(
                id: 'article',
                label: 'Article',
                entityKeys: ['id' => 'aid', 'uuid' => 'uuid'],
                entityClass: \stdClass::class,
                fieldDefinitions: [
                    'aid' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                ],
            ),
        ]);

        $mutationType = $schema->getMutationType();
        $this->assertNotNull($mutationType);
        $this->assertTrue($mutationType->hasField('createArticle'));
        $this->assertTrue($mutationType->hasField('updateArticle'));
        $this->assertTrue($mutationType->hasField('deleteArticle'));
    }

    #[Test]
    public function fieldTypesMapCorrectly(): void
    {
        $schema = $this->buildSchema([
            new EntityType(
                id: 'article',
                label: 'Article',
                entityKeys: ['id' => 'aid', 'uuid' => 'uuid'],
                entityClass: \stdClass::class,
                fieldDefinitions: [
                    'aid' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'score' => ['type' => 'float'],
                    'active' => ['type' => 'boolean'],
                ],
            ),
        ]);

        $articleType = $schema->getType('Article');
        $this->assertInstanceOf(ObjectType::class, $articleType);

        // score should map to Float
        $scoreField = $articleType->getField('score');
        $this->assertSame('Float', $scoreField->getType()->name ?? $scoreField->getType()->toString());

        // active should map to Boolean
        $activeField = $articleType->getField('active');
        $this->assertSame('Boolean', $activeField->getType()->name ?? $activeField->getType()->toString());
    }

    #[Test]
    public function filterInputTypeHasCorrectStructure(): void
    {
        $schema = $this->buildSchema([
            new EntityType(
                id: 'article',
                label: 'Article',
                entityKeys: ['id' => 'aid', 'uuid' => 'uuid'],
                entityClass: \stdClass::class,
                fieldDefinitions: [
                    'aid' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                ],
            ),
        ]);

        $filterType = $schema->getType('FilterInput');
        $this->assertInstanceOf(InputObjectType::class, $filterType);
        $this->assertTrue($filterType->hasField('field'));
        $this->assertTrue($filterType->hasField('value'));
        $this->assertTrue($filterType->hasField('operator'));
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`
Expected: All tests PASS (these test the existing working implementation)

- [ ] **Step 3: Commit**

```bash
git add packages/graphql/tests/Unit/Schema/SchemaValidationTest.php
git commit -m "feat(#437): add GraphQL schema validation test harness"
```

---

### Task 2: Validate filter/sort on _data blob fields ([#438](https://github.com/waaseyaa/framework/issues/438))

**Files:**
- Modify: `packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`
- Possibly modify: `packages/graphql/src/Resolver/EntityResolver.php` or `packages/api/src/Query/QueryApplier.php`

- [ ] **Step 1: Write test for filtering on a schema column**

Add a test method to `SchemaValidationTest` that creates an entity with a schema column, executes a GraphQL query with a filter, and confirms results are filtered correctly. This requires an integration test with real storage.

- [ ] **Step 2: Write test for filtering on a _data blob field**

Add a test that filters on a field stored in `_data` and documents the behavior — does it silently return no results, throw, or work?

- [ ] **Step 3: Run tests, document findings**

Run: `./vendor/bin/phpunit packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`

If `_data` filtering doesn't work (expected), add inline documentation to `EntityResolver` or the GraphQL package README explaining the limitation and migration path.

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(#438): validate and document _data blob filter/sort limitations"
```

---

### Task 3: Separate create/update input types ([#439](https://github.com/waaseyaa/framework/issues/439))

**Files:**
- Modify: `packages/graphql/src/Schema/EntityTypeBuilder.php`
- Modify: `packages/graphql/src/Schema/SchemaFactory.php`
- Modify: `packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`

- [ ] **Step 1: Write failing test for separate input types**

```php
#[Test]
public function createAndUpdateInputTypesAreSeparate(): void
{
    $schema = $this->buildSchema([
        new EntityType(
            id: 'article',
            label: 'Article',
            entityKeys: ['id' => 'aid', 'uuid' => 'uuid'],
            entityClass: \stdClass::class,
            fieldDefinitions: [
                'aid' => ['type' => 'integer'],
                'uuid' => ['type' => 'string'],
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'string'],
            ],
        ),
    ]);

    $createInput = $schema->getType('ArticleCreateInput');
    $updateInput = $schema->getType('ArticleUpdateInput');

    $this->assertInstanceOf(InputObjectType::class, $createInput);
    $this->assertInstanceOf(InputObjectType::class, $updateInput);

    // Create input: required fields are NonNull
    $createTitle = $createInput->getField('title');
    $this->assertInstanceOf(NonNull::class, $createTitle->getType());

    // Update input: all fields nullable
    $updateTitle = $updateInput->getField('title');
    $this->assertNotInstanceOf(NonNull::class, $updateTitle->getType());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter createAndUpdateInputTypesAreSeparate`
Expected: FAIL (currently generates single `ArticleInput`)

- [ ] **Step 3: Implement separate input types**

In `EntityTypeBuilder`, add `buildCreateInputType()` and `buildUpdateInputType()` methods. The create version wraps required fields with `NonNull`, the update version makes all fields nullable. Update `SchemaFactory` to use `buildCreateInputType()` for `create{Type}` mutations and `buildUpdateInputType()` for `update{Type}` mutations.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(#439): separate create/update GraphQL input types for PATCH semantics"
```

---

### Task 4: Validate pagination arguments ([#440](https://github.com/waaseyaa/framework/issues/440))

**Files:**
- Modify: `packages/graphql/tests/Unit/Schema/SchemaValidationTest.php`
- Possibly modify: `packages/graphql/src/Resolver/EntityResolver.php`

- [ ] **Step 1: Write test for pagination argument types**

```php
#[Test]
public function listQueryHasPaginationArguments(): void
{
    $schema = $this->buildSchema([/* ... */]);

    $listField = $schema->getQueryType()->getField('articleList');

    $limitArg = $listField->getArg('limit');
    $this->assertSame('Int', $limitArg->getType()->name ?? $limitArg->getType()->toString());

    $offsetArg = $listField->getArg('offset');
    $this->assertSame('Int', $offsetArg->getType()->name ?? $offsetArg->getType()->toString());
}
```

- [ ] **Step 2: Write test for default behavior when omitted**

Test that resolveList with no limit/offset returns results (and ideally has a sensible max).

- [ ] **Step 3: Add default max limit if missing**

If `EntityResolver::resolveList()` has no max limit, add a default (e.g., 100) to prevent unbounded queries.

- [ ] **Step 4: Run tests, commit**

```bash
git commit -m "feat(#440): validate pagination arguments and add default max limit"
```

---

### Task 5: Tag v0.1.0-alpha.10 ([#441](https://github.com/waaseyaa/framework/issues/441))

- [ ] **Step 1: Verify all validation issues are resolved**

Run: `./vendor/bin/phpunit packages/graphql/tests/`
Expected: All PASS

- [ ] **Step 2: Tag and push**

```bash
git tag v0.1.0-alpha.10
git push origin v0.1.0-alpha.10
```

- [ ] **Step 3: Verify Packagist picks up the release**

Check that all `waaseyaa/*` packages show alpha.10 on Packagist.

---

## Chunk 2: Claudriel Schema Validation (claudriel repo)

All tasks in this chunk are in the `jonesrussell/claudriel` repo, milestone v1.5 (#16).

### Task 6: Bump Waaseyaa to alpha.10 ([#170](https://github.com/jonesrussell/claudriel/issues/170))

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update composer.json**

Bump all `waaseyaa/*` version constraints to `^0.1.0-alpha.10`. Add `waaseyaa/graphql` as a new dependency.

- [ ] **Step 2: Install and verify**

```bash
composer update waaseyaa/*
```

- [ ] **Step 3: Boot dev server and test /graphql endpoint**

```bash
php -S localhost:8080 public/index.php &
curl -s http://localhost:8080/graphql -H 'Content-Type: application/json' -d '{"query":"{ __schema { queryType { name } } }"}'
```

Expected: Returns schema introspection data with Claudriel entity types.

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(#170): bump Waaseyaa to alpha.10, add waaseyaa/graphql"
```

---

### Task 7: Schema contract test ([#171](https://github.com/jonesrussell/claudriel/issues/171))

**Files:**
- Create: `tests/Integration/GraphQL/SchemaContractTest.php`

- [ ] **Step 1: Write contract test**

Boot Claudriel's kernel, generate the GraphQL schema, assert the Commitment and Person types exist with expected fields, queries, mutations, and argument structures. Use the pattern from Waaseyaa's `SchemaValidationTest` but with Claudriel's actual entity types.

- [ ] **Step 2: Run test**

```bash
./vendor/bin/phpunit tests/Integration/GraphQL/SchemaContractTest.php
```

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(#171): add GraphQL schema contract test for Commitment + Person"
```

---

### Task 8: Validate field definitions ([#172](https://github.com/jonesrussell/claudriel/issues/172))

**Files:**
- Possibly modify: `src/Entity/Commitment.php` or entity type registration
- Possibly modify: `src/Schema/` (if schema handlers exist)

- [ ] **Step 1: Check if tenant_id, last_interaction_at, confidence are schema columns**

Inspect Claudriel's entity type definitions and schema handlers. If any of these fields are stored in `_data` blob, promote them to real schema columns.

- [ ] **Step 2: Run schema contract test to verify**

```bash
./vendor/bin/phpunit tests/Integration/GraphQL/SchemaContractTest.php
```

- [ ] **Step 3: Test filtering and sorting end-to-end**

Write a quick integration test that inserts test data, then queries via GraphQL with `tenant_id` filter and `last_interaction_at` sort to confirm SQL-level operations work.

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(#172): validate and fix field definitions for GraphQL compatibility"
```

---

## Chunk 3: Claudriel Frontend Migration (claudriel repo)

### Task 9: graphqlFetch() helper + gql tag ([#173](https://github.com/jonesrussell/claudriel/issues/173))

**Files:**
- Create: `frontend/admin/app/utils/gql.ts`
- Create: `frontend/admin/app/utils/graphqlFetch.ts`
- Create: `frontend/admin/app/utils/__tests__/graphqlFetch.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
import { describe, it, expect, vi } from 'vitest';
import { graphqlFetch, GraphQlError } from '../graphqlFetch';

describe('graphqlFetch', () => {
  it('returns typed data on success', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      json: () => Promise.resolve({ data: { article: { id: '1', title: 'Test' } } }),
    });

    const result = await graphqlFetch<{ article: { id: string; title: string } }>(
      '{ article(id: "1") { id title } }'
    );

    expect(result.article.title).toBe('Test');
  });

  it('throws GraphQlError on errors', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      json: () => Promise.resolve({ errors: [{ message: 'Not found' }] }),
    });

    await expect(graphqlFetch('{ bad }')).rejects.toThrow(GraphQlError);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd frontend/admin && npm test -- --run utils/__tests__/graphqlFetch.test.ts
```

- [ ] **Step 3: Implement**

Create `gql.ts` and `graphqlFetch.ts` as specified in the design spec (Section 4).

- [ ] **Step 4: Run test to verify it passes**

```bash
cd frontend/admin && npm test -- --run utils/__tests__/graphqlFetch.test.ts
```

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(#173): add graphqlFetch helper and gql template tag"
```

---

### Task 10: useCommitmentsQuery() composable ([#174](https://github.com/jonesrussell/claudriel/issues/174))

**Files:**
- Create: `frontend/admin/app/composables/useCommitmentsQuery.ts`
- Create: `frontend/admin/app/composables/__tests__/useCommitmentsQuery.test.ts`
- Create: `frontend/admin/app/types/commitment.ts` (TypeScript interface)

- [ ] **Step 1: Define Commitment TypeScript interface**

```typescript
export interface Commitment {
  uuid: string;
  title: string;
  status: string;
  confidence: number;
  due_date: string | null;
  person_uuid: string | null;
  source: string;
  created_at: string;
  updated_at: string;
}
```

- [ ] **Step 2: Write the failing test**

Test that the composable calls `graphqlFetch` with the correct query string and filter variables.

- [ ] **Step 3: Implement the composable**

```typescript
import { gql } from '~/utils/gql';
import { graphqlFetch } from '~/utils/graphqlFetch';
import type { Commitment } from '~/types/commitment';

interface ListResult<T> { items: T[]; total: number; }

const COMMITMENTS_LIST_QUERY = gql`
  query CommitmentsList($status: String, $tenantId: String) {
    commitmentList(
      filter: [
        { field: "status", value: $status }
        { field: "tenant_id", value: $tenantId }
      ]
      sort: "-updated_at"
      limit: 50
    ) {
      items {
        uuid title status confidence due_date
        person_uuid source created_at updated_at
      }
      total
    }
  }
`;

export function useCommitmentsQuery(filter: { status?: string; tenantId?: string }) {
  return useAsyncData('commitments', () =>
    graphqlFetch<{ commitmentList: ListResult<Commitment> }>(COMMITMENTS_LIST_QUERY, filter)
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd frontend/admin && npm test -- --run composables/__tests__/useCommitmentsQuery.test.ts
```

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(#174): add useCommitmentsQuery GraphQL composable"
```

---

### Task 11: usePeopleQuery() composable ([#175](https://github.com/jonesrussell/claudriel/issues/175))

**Files:**
- Create: `frontend/admin/app/composables/usePeopleQuery.ts`
- Create: `frontend/admin/app/composables/__tests__/usePeopleQuery.test.ts`
- Create: `frontend/admin/app/types/person.ts`

- [ ] **Step 1: Define Person TypeScript interface**

```typescript
export interface Person {
  uuid: string;
  name: string;
  email: string;
  tier: string;
  source: string;
  latest_summary: string | null;
  last_interaction_at: string | null;
  last_inbox_category: string | null;
}
```

- [ ] **Step 2: Write the failing test**

Same pattern as Task 10 but for `usePeopleQuery`.

- [ ] **Step 3: Implement the composable**

Same pattern as Task 10 with the People query from the design spec.

- [ ] **Step 4: Run test, commit**

```bash
git commit -m "feat(#175): add usePeopleQuery GraphQL composable"
```

---

## Chunk 4: Component Migration & Controller Cleanup (claudriel repo)

### Task 12: Migrate Commitment components ([#176](https://github.com/jonesrussell/claudriel/issues/176))

**Files:**
- Modify: All components that call `useEntity('commitment')`

- [ ] **Step 1: Find all commitment adapter calls**

```bash
grep -r "useEntity.*commitment" frontend/admin/app/
```

- [ ] **Step 2: Replace each with useCommitmentsQuery()**

For list calls: replace with `useCommitmentsQuery()`.
For single-entity, create, update, delete: use `graphqlFetch()` directly with the appropriate query/mutation.

- [ ] **Step 3: Smoke test**

Boot dev server, verify commitment list, detail view, create, edit, delete all work correctly.

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(#176): migrate commitment components to GraphQL"
```

---

### Task 13: Migrate People components ([#177](https://github.com/jonesrussell/claudriel/issues/177))

Same pattern as Task 12 but for `useEntity('person')` calls.

- [ ] **Step 1: Find all people adapter calls**
- [ ] **Step 2: Replace with usePeopleQuery()**
- [ ] **Step 3: Smoke test**
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(#177): migrate people components to GraphQL"
```

---

### Task 14: Deprecate controllers ([#178](https://github.com/jonesrussell/claudriel/issues/178), [#179](https://github.com/jonesrussell/claudriel/issues/179))

**Files:**
- Modify: `src/Controller/CommitmentApiController.php`
- Modify: `src/Controller/PeopleApiController.php`

- [ ] **Step 1: Verify no frontend code calls the old endpoints**

```bash
grep -r "/api/commitments\|/api/people" frontend/admin/app/
```

Expected: No matches (all migrated to GraphQL).

- [ ] **Step 2: Add deprecation logging to both controllers**

Add `error_log('Deprecated: /api/commitments — use /graphql endpoint instead');` at the top of each controller method.

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(#178,#179): deprecate Commitment and People API controllers"
```

---

### Task 15: Remove deprecated controllers ([#180](https://github.com/jonesrussell/claudriel/issues/180))

**Files:**
- Delete: `src/Controller/CommitmentApiController.php`
- Delete: `src/Controller/PeopleApiController.php`
- Modify: Route registration (remove `/api/commitments` and `/api/people` routes)

- [ ] **Step 1: Confirm zero deprecation log entries**

Check logs to verify no consumers remain.

- [ ] **Step 2: Delete controllers and route registrations**
- [ ] **Step 3: Run all tests**

```bash
./vendor/bin/phpunit
cd frontend/admin && npm test
```

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(#180): remove deprecated Commitment and People controllers"
```
