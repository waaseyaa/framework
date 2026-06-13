# Fix #534: GraphQL `total` Must Return Unfiltered Count

**Date:** 2026-03-21
**Milestone:** v1.6 — Search Provider
**Issue:** #534

## Problem

`EntityResolver::resolveList()` (lines 88-97) overrides the raw database count with an access-scaled estimate after post-fetch filtering. When 2 articles exist and 1 is access-denied, `total` returns 1 instead of 2.

This contradicts the documented architectural invariant (CLAUDE.md):

> `totalCount` in list queries reflects the full storage count, not the access-filtered subset. `items` contains only entities the caller can access. This matches Relay/Apollo/Hasura conventions, ensures stable pagination, and avoids leaking content (only existence).

The raw count query (lines 54-60) already uses `accessCheck(false)` and returns the correct unfiltered count. The override block actively fights the intended behavior.

## Root Cause

Lines 88-97 of `packages/graphql/src/Resolver/EntityResolver.php`:

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

This code was introduced to "improve" the count accuracy but violates the Relay/Apollo convention and breaks pagination stability.

## Fix

### 1. Delete the override block

Remove lines 88-97 from `EntityResolver::resolveList()`. The `$total` from the raw count query (line 60) is already correct.

### 2. Fix unit test assertion

In `packages/graphql/tests/Unit/Resolver/EntityResolverTest.php`, the test `resolveListFiltersOutDeniedEntities`:
- Delete the stale comment at line 152: `// total reflects accessible items when full result fits in one page` — it describes the deleted logic and would be actively misleading post-fix.
- Change the assertion at line 153 from `assertSame(1, ...)` to `assertSame(2, ...)` — total reflects full storage count, not the filtered subset.

### 3. Verify integration tests

The two failing integration tests should now pass:
- `GraphQlAccessTest::testAccessDeniedEntitiesExcludedFromList` — expects `total=2`
- `GraphQlCrudTest::testListReturnsPersistentEntities` — expects `total=2`

## Files Changed

| File | Change |
|------|--------|
| `packages/graphql/src/Resolver/EntityResolver.php` | Delete lines 88-97 (access-scaling override) |
| `packages/graphql/tests/Unit/Resolver/EntityResolverTest.php` | Delete stale comment (line 152), fix `total` assertion from 1 to 2 (line 153) |

## Risk

Low. The count query already uses `accessCheck(false)`. We are removing code that contradicts the documented invariant. No new interfaces, files, or tests needed.
