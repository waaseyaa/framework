# waaseyaa/graphql

> **Alternative protocol — not the primary API surface.**
>
> Per the framework's API-surface consolidation (mission `api-surface-consolidation-jsonapi-primary-01KSEFTV`),
> the framework's primary API surface is **JSON:API** in `packages/api/`. `waaseyaa/graphql`
> remains supported as an **optional / experimental** L6 protocol adapter for distributions
> whose consumers need GraphQL. It is not bundled by `waaseyaa/full`; install it explicitly
> when your distribution chooses GraphQL.

**Layer 6 — Interfaces**

GraphQL endpoint for Waaseyaa with auto-generated schema from registered entity types.

`GraphQlEndpoint` accepts queries at the configured route (registered via `GraphQlRouteProvider`) and resolves them against `EntityTypeManagerInterface`-derived schemas. Connection-style pagination follows the Relay spec: `totalCount` reflects the full unfiltered dataset (matching JSON:API semantics — see #436), while `items` returns only the access-filtered subset. Field resolvers honour `FieldAccessPolicyInterface` so attribute-level access control matches the JSON:API surface.

Key classes: `GraphQlEndpoint`, `GraphQlRouteProvider`, `GraphQlServiceProvider`.

## Status

- **Stability:** optional / experimental. The public API surface (`GraphQlServiceProvider`, the `/graphql` endpoint, the schema-loading mechanism, any documented resolvers / mutations) is frozen at its current shape. The framework cadence ships no new feature work for this package; community contributions are accepted under the same review bar.
- **Bundle membership:** suggested by `waaseyaa/full` (not required). To install: `composer require waaseyaa/graphql`.
- **Decision provenance:** API-surface consolidation by mission `api-surface-consolidation-jsonapi-primary-01KSEFTV`. JSON:API is declared the framework's primary API surface in `docs/specs/jsonapi.md`.

## Implementation gotchas

- **Reference fields keep storage field names**: A field defined as `author_id` with type `entity_reference` produces a GraphQL field named `author_id` (not `author`). It resolves to the nested entity object but the field name includes the `_id` suffix.
- **Mutations require an authenticated account (R11)**: `GraphQlEndpoint::handle()` rejects any mutation operation (`create{Type}`/`update{Type}`/`delete{Type}`, any alias or `operationName`-selected mutation) for an unauthenticated (`AccountInterface::isAuthenticated() === false`) caller, for every HTTP method, BEFORE building the schema or invoking a resolver: a uniform error message `"Authentication required for mutation operations."` (no entity id/type ever named) and the mutation never executes. Queries are unaffected. This closes an anonymous existence oracle: `update{Type}`/`delete{Type}` distinguished "entity absent" ("Entity not found: {type}/{id}") from "entity exists but access denied", an anonymous or otherwise-unauthorized caller could enumerate entity ids by diffing the two messages even though every per-entity `AccessPolicyInterface` was itself correct. As defense-in-depth for the authenticated-but-unauthorized case (not blocked by the gate above), `EntityResolver::resolveUpdate()`/`resolveDelete()` now collapse an access-denied outcome, at BOTH the entity level AND the per-field `edit` level (the `assertFieldEditAccess()` loop is inside the collapse), into the SAME "Entity not found" error the absent-entity branch throws, mirroring `resolveSingle()`, which has always returned `null` uniformly for both cases. (The endpoint sets `statusCode` 401 internally, but `GraphQlRouter::handle()` currently hardcodes an HTTP-200 envelope regardless, a separate pre-existing issue, out of R11 scope.) See `docs/specs/api-layer.md` (2026-07-05 entry) for the full writeup.
