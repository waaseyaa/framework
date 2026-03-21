# Fix #534: GraphQL Total Count Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix GraphQL `total` in list queries to return the unfiltered storage count, matching the Relay/Apollo/Hasura convention documented in CLAUDE.md.

**Architecture:** Delete the access-scaling override block in `EntityResolver::resolveList()` so the raw `accessCheck(false)` count query result is returned as-is. Fix the unit test that asserts the wrong (filtered) behavior.

**Tech Stack:** PHP 8.3, PHPUnit 10.5

---

### Task 1: Fix the unit test expectation first (TDD — make the test assert correct behavior)

**Files:**
- Modify: `packages/graphql/tests/Unit/Resolver/EntityResolverTest.php:152-153`

- [ ] **Step 1: Update the test to assert unfiltered total**

Change `resolveListFiltersOutDeniedEntities` to expect `total=2` (both entities counted) instead of `total=1` (only accessible entities counted). Delete the stale comment that describes the removed behavior.

```php
// Before (lines 152-153):
// total reflects accessible items when full result fits in one page
self::assertSame(1, $result['total']);

// After (delete line 152 comment, change assertion):
self::assertSame(2, $result['total']);
```

- [ ] **Step 2: Run the unit test to verify it fails**

Run: `./vendor/bin/phpunit --filter resolveListFiltersOutDeniedEntities`
Expected: FAIL — `assertSame(2, 1)` because the override block still sets total=1.

### Task 2: Delete the access-scaling override

**Files:**
- Modify: `packages/graphql/src/Resolver/EntityResolver.php:88-97`

- [ ] **Step 3: Remove the override block**

Delete these lines from `resolveList()`:

```php
// When the full result set fits in one page, use the exact accessible count
// instead of the raw DB count which ignores access filtering.
$fetched = count($entities);
$accessible = count($items);
if ($fetched > 0 && $fetched < $limit && $offset === 0) {
    $total = $accessible;
} elseif ($fetched > 0 && $accessible < $fetched) {
    // Paginated: scale total proportionally based on this page's access ratio
    $total = (int) round($total * ($accessible / $fetched));
}
```

The `$total` from the count query at line 60 (which uses `accessCheck(false)`) is already the correct unfiltered value.

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `./vendor/bin/phpunit --filter resolveListFiltersOutDeniedEntities`
Expected: PASS — total now returns 2 (unfiltered count).

- [ ] **Step 5: Run all GraphQL unit tests**

Run: `./vendor/bin/phpunit --filter EntityResolverTest`
Expected: All tests PASS.

### Task 3: Verify integration tests pass

**Files:**
- Verify: `tests/Integration/GraphQL/GraphQlAccessTest.php`
- Verify: `tests/Integration/GraphQL/GraphQlCrudTest.php`

- [ ] **Step 6: Run the two previously-failing integration tests**

Run: `./vendor/bin/phpunit --filter "GraphQlAccessTest|GraphQlCrudTest"`
Expected: All tests PASS. `testAccessDeniedEntitiesExcludedFromList` gets `total=2`, `testListReturnsPersistentEntities` gets `total=2`.

- [ ] **Step 7: Run the full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests PASS. No regressions.

### Task 4: Commit and close

- [ ] **Step 8: Commit the fix**

```bash
git add packages/graphql/src/Resolver/EntityResolver.php packages/graphql/tests/Unit/Resolver/EntityResolverTest.php
git commit -m "fix(#534): return unfiltered total count in GraphQL list queries

Delete the access-scaling override in EntityResolver::resolveList() that
contradicted the Relay/Apollo convention. The count query already uses
accessCheck(false) for the correct unfiltered count.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```
