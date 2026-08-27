# Bundle-scoped unique keys

Stable change-record ID: `bundle-scoped-unique-keys-20260826`

## Outcome

Provide one Framework-owned declaration and enforcement path for named,
race-safe unique keys on bundle-scoped fields. This unblocks Sheguiandah media
bundle uniqueness without application SQL or a check-then-write validator.

## Design

- Add a small bundle-unique-key registry contract alongside the existing field
  registry. The concrete `FieldDefinitionRegistry` implements both so bundle
  fields and their storage keys share one lifecycle and identity.
- Extend `ContentTypeModel` with optional named unique-key declarations and
  have `ContentModelRegistrar` register them after the referenced fields.
- Promote Data-backed key definitions to column storage in the registry before
  schema sync. Backfill reads the old base `_data` copy; subsequent query and
  write routing has one canonical subtable source while the index closes races.
- Verify the promoted columns and named unique indexes during read-only runtime
  schema assertion.
- Map an observed bundle-key collision to a stable repository-domain exception
  carrying entity type, bundle, key name, fields, and submitted values. Preserve
  unrelated driver uniqueness exceptions unchanged.

## Portable value semantics

- A key participates only when every component is non-null.
- Empty strings are values and therefore conflict with another identical empty
  string.
- Multiple rows with a null component are allowed, matching the common
  SQLite/MySQL/PostgreSQL unique-index contract.

## Work packages

1. Registry/value contract and validation.
2. Schema promotion, backfill, duplicate preflight, index creation, and runtime
   readiness.
3. Bundle write synchronization and stable conflict mapping.
4. Content-model declaration surface, specs, changelog, and acceptance tests.

## Explicit exclusions

- No Sheguiandah-specific names or values in Framework.
- No UI-only or event-listener uniqueness workaround.
- No release, package split, or deployment.
