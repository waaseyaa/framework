# Claudriel GraphQL Adoption — Design Spec

**Date:** 2026-03-16
**Status:** Approved
**Scope:** Claudriel adopts client-side GraphQL for Commitments + People data paths
**Follow-up:** Minoo server-side GraphQL adoption (separate spec)

---

## 1. Context & Motivation

Waaseyaa v1.3 added a zero-config GraphQL layer that auto-generates full CRUD schema from entity types — queries, mutations, filtering, sorting, pagination, nested reference resolution with N+1 prevention, and field-level access control.

Claudriel has accumulated architectural pain in its data-loading path:

- Controllers load ALL entities into memory, filter with `array_filter()`, sort with `usort()`
- No server-side pagination
- Frontend makes separate roundtrips for commitments, people, schedule, workspaces
- N+1 patterns everywhere when loading related entities

GraphQL solves all of these in one stroke by pushing filtering/sorting/pagination to the database and enabling nested entity resolution in a single request.

## 2. Strategy

- **Claudriel:** Adopt GraphQL on the **client side** for performance + UX gains
- **Migration approach:** Incremental, one data path at a time
- **First target:** Commitments + People (highest complexity, most relationship joins, most painful today)
- **Waaseyaa dependency:** Tag v0.1.0-alpha.10 including GraphQL package before Claudriel adopts
- **GitHub-first:** All issues created with milestones before any code changes

## 3. Target GraphQL Queries

### Commitments list

```graphql
query CommitmentsList($status: String, $tenantId: String) {
  commitmentList(
    filter: { status: $status, tenant_id: $tenantId }
    sort: { field: "updated_at", direction: DESC }
    limit: 50
  ) {
    uuid, title, status, confidence, due_date
    person_uuid, source, created_at, updated_at
  }
}
```

### Single commitment

```graphql
query Commitment($id: ID!) {
  commitment(id: $id) {
    uuid, title, status, confidence, due_date
    source, tenant_id, created_at, updated_at
    person_uuid
  }
}
```

### People list

```graphql
query PeopleList($tenantId: String, $tier: String) {
  personList(
    filter: { tenant_id: $tenantId, tier: $tier }
    sort: { field: "last_interaction_at", direction: DESC }
  ) {
    uuid, name, email, tier, source
    latest_summary, last_interaction_at, last_inbox_category
  }
}
```

### Mutations

```graphql
mutation CreateCommitment($input: CommitmentInput!) {
  createCommitment(input: $input) {
    uuid, title, status
  }
}

mutation UpdateCommitment($id: ID!, $input: CommitmentInput!) {
  updateCommitment(id: $id, input: $input) {
    uuid, title, status, updated_at
  }
}

mutation DeleteCommitment($id: ID!) {
  deleteCommitment(id: $id)
}
```

**Note:** `person_uuid` stays as a plain string for v1. Nested resolution (`commitment { person { name } }`) requires an `entity_reference` field definition — scoped as a future enhancement.

## 4. Frontend Composable Architecture

### Transport layer

A thin `graphqlFetch()` helper alongside the existing JSON:API adapter:

```typescript
// app/utils/gql.ts
export const gql = String.raw;

// app/utils/graphqlFetch.ts
export class GraphQlError extends Error {
  constructor(public errors: Array<{ message: string; path?: string[] }>) {
    super(errors.map(e => e.message).join(', '));
  }
}

export async function graphqlFetch<T>(
  query: string,
  variables?: Record<string, unknown>,
): Promise<T> {
  const response = await fetch('/graphql', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query, variables }),
  });
  const json = await response.json();
  if (json.errors?.length) throw new GraphQlError(json.errors);
  return json.data;
}
```

### Composables per data path

```typescript
// app/composables/useCommitmentsQuery.ts
export function useCommitmentsQuery(filter: { status?: string; tenantId?: string }) {
  return useAsyncData('commitments', () =>
    graphqlFetch<{ commitmentList: Commitment[] }>(COMMITMENTS_LIST_QUERY, filter)
  );
}

// app/composables/usePeopleQuery.ts
export function usePeopleQuery(filter: { tenantId?: string; tier?: string }) {
  return useAsyncData('people', () =>
    graphqlFetch<{ personList: Person[] }>(PEOPLE_LIST_QUERY, filter)
  );
}
```

### What changes in components

Replace:
```typescript
useEntity('commitment').list({ status: 'active' })
```

With:
```typescript
useCommitmentsQuery({ status: 'active' })
```

One-line change per component. No template changes. No reactive state changes.

### What stays as-is

- `claudrielAdapter.ts` and `useEntity.ts` remain for non-migrated entity types (schedule, triage, chat, workspace)
- JSON:API endpoints stay live during migration
- No breaking changes

### TypeScript interfaces

Flat types mirroring GraphQL response fields — `Commitment`, `Person` defined once, shared across composables.

## 5. Controller Simplification

### Controllers fully replaceable by GraphQL

- **CommitmentApiController** — pure CRUD + in-memory filtering/sorting. All logic moves to GraphQL resolvers.
- **PeopleApiController** — pure CRUD + in-memory filtering/sorting. Same.

### Controllers that stay (for now)

- **ScheduleApiController** — has temporal query logic (`TemporalContextFactory`, `RelativeScheduleQueryService`) that requires custom GraphQL resolver work. Migrates in a future phase.

### Cleanup sequence per entity type

1. Frontend composable ships
2. Validate GraphQL path works end-to-end
3. Remove old adapter calls from components
4. Deprecate controller routes (keep responding, log warnings)
5. Remove controller + routes in a follow-up PR

Rollback-safe: if GraphQL has issues, flip back to the adapter path.

## 6. Schema Validation & Gap Analysis

Seven items to validate before any Claudriel code changes:

| # | Item | Risk | Notes |
|---|------|------|-------|
| 1 | `tenant_id` is filterable | HIGH | Must be a declared field, not a `_data` blob value |
| 2 | `last_interaction_at` is sortable | HIGH | Must be a schema column for SQL-level sorting |
| 3 | `confidence` maps to GraphQL Float | MEDIUM | Commitment must define it with a float field type |
| 4 | Update input types have all-optional fields | MEDIUM | PATCH semantics require nullable inputs |
| 5 | `person_uuid` as entity reference (future) | LOW | Not needed for v1, flag for later |
| 6 | Tenant context as implicit filter (future) | LOW | Future enhancement, not a blocker |
| 7 | Pagination arguments (`limit`/`offset`) exist as `Int` | LOW | Confirm on all `*List` queries with correct defaults |

**Validation method:** PHPUnit integration test that boots the Claudriel kernel, generates the GraphQL schema, and asserts expected query/mutation fields exist with correct types. Becomes a regression guard for the schema contract.

## 7. GitHub Issue Tree

### Waaseyaa repo — milestone: v1.3 (GraphQL & Cleanup)

| # | Issue | Depends on | Description |
|---|-------|------------|-------------|
| W1 | Schema validation test harness | — | Integration test that boots a test kernel, generates GraphQL schema, asserts field/type presence. Reusable for any app. |
| W2 | Validate filter/sort on `_data` blob fields | W1 | Confirm whether `QueryApplier` can filter/sort on fields stored in `_data` vs schema columns. Document the limitation if not. |
| W3 | Validate nullable input types for PATCH semantics | W1 | Confirm auto-generated mutation input types have all-optional fields for update operations. Fix if not. |
| W4 | Validate pagination arguments on list queries | W1 | Confirm `limit`/`offset` exist as `Int` on all `*List` queries, with correct defaults when omitted. |
| W5 | Tag v0.1.0-alpha.10 | W2, W3, W4 | Release including GraphQL package + any fixes from validation. |

### Claudriel repo — new milestone: GraphQL Adoption

| # | Issue | Depends on | Description |
|---|-------|------------|-------------|
| C1 | Bump Waaseyaa to alpha.10, add `waaseyaa/graphql` | W5 | Update composer.json, verify schema auto-generates for all Claudriel entity types. |
| C2 | Schema contract test for Commitment + Person | C1 | PHPUnit test asserting expected queries, mutations, field types, filter/sort args exist in generated schema. |
| C3 | Validate `tenant_id`, `last_interaction_at`, `confidence` field definitions | C2 | Confirm these are schema columns (not `_data` blob). Fix entity type definitions if needed. |
| C4 | `graphqlFetch()` helper + `gql` tag | C1 | Add `app/utils/graphqlFetch.ts` and `app/utils/gql.ts`. Unit test the helper. |
| C5 | `useCommitmentsQuery()` composable | C3, C4 | Replaces `useEntity('commitment').list()`. Typed response, filter params, `useAsyncData` integration. |
| C6 | `usePeopleQuery()` composable | C3, C4 | Replaces `useEntity('person').list()`. Same pattern as C5. |
| C7 | Migrate Commitment components to GraphQL | C5 | Replace adapter calls in commitment-related components with `useCommitmentsQuery()`. |
| C8 | Migrate People components to GraphQL | C6 | Replace adapter calls in people-related components with `usePeopleQuery()`. |
| C9 | Deprecate CommitmentApiController | C7 | Add deprecation logging, keep routes live. |
| C10 | Deprecate PeopleApiController | C8 | Add deprecation logging, keep routes live. |
| C11 | Remove deprecated controllers | C9, C10 | Delete controllers + routes after validation period. |

### Dependency chain

```
W1 → W2,W3,W4 → W5 → C1 → C2 → C3 → C5,C6 → C7,C8 → C9,C10 → C11
                  W5 → C1 → C4 ↗
```

## 8. Future Work (Out of Scope)

- **Schedule migration:** Requires custom GraphQL resolvers for temporal query logic. Separate design.
- **Nested entity resolution:** Commitment → Person requires `entity_reference` field definition in Waaseyaa.
- **Tenant-aware context:** Implicit tenant scoping at the resolver level, removing explicit `tenant_id` filter params.
- **Minoo adoption:** Server-side GraphQL for admin SPA and SSR controller simplification. Separate spec.
