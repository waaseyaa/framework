# Custom-storage repository refusal

Stable change record: `custom-storage-repository-refusal-20260826`

Parent candidate: Framework `main` at
`f8c99921c383c59e22fa97a7c9075817e1bc8aaa`.
Forge mirror: `waaseyaa/framework#2496`.

## Decision

An entity type that declares a valid custom `EntityStorageInterface`
implementation may be resolved through `getStorage()`, but it cannot be
represented honestly by the Framework SQL `EntityRepositoryInterface`.
`getRepository()` therefore refuses before schema inspection or SQL repository
construction.

Adapting the eight-method storage contract into the repository's revision,
translation, publication, and working-copy contract would fabricate semantics
the custom backend has not declared. A typed refusal preserves the existing
bring-your-own-storage seam without weakening the production runtime-schema
assertion for Framework SQL entity types.

## Implementation plan

1. Add a failing factory-level regression proving that a valid custom-storage
   type reaches an explicit repository refusal rather than the SQL schema gate.
2. Reuse the factory's custom-storage classification before constructing the
   Framework SQL repository.
3. Keep malformed storage classes on their existing `must implement` refusal
   and prove ordinary SQL-backed repository resolution still succeeds.
4. Document the `getStorage()` / `getRepository()` boundary in the entity-system
   contract and add the governed changelog fragment.
5. Run focused tests, all three suites, full preflight, and exact-head hosted CI.

## Explicit exclusions

- No adapter from `EntityStorageInterface` to `EntityRepositoryInterface`.
- No weakening of the #2478/#2482 fail-closed runtime-schema checks.
- No new custom-backend lifecycle, revision, translation, or publication APIs.
- No release or deployment action.
