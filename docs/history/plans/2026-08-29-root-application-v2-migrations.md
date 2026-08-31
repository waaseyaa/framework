# Root-application V2 migration discovery implementation plan

Stable change record: `FW-2695`

## Contract

Root applications participate in `extra.waaseyaa.migrations` exactly as
installed packages do, using their Composer package name as the stable package
identity. Existing undeclared `/migrations` applications remain compatible.

## Sequence

1. Add red compiler/cache tests for root declaration retention, validation,
   and fail-closed identity.
2. Add red loader tests for root-relative paths, fallback deduplication, and
   class-discovered V2 migrations.
3. Add red runtime tests proving dry-run, apply, status, and verify use the
   discovered root V2 catalogue.
4. Implement compiler and loader support through the existing manifest model.
5. Wire the existing V2 executor/catalogue through all stock migration
   compositions.
6. Run focused suites, architecture/static gates, the full preflight, and a
   packaged consumer acceptance before publishing the draft review candidate.

## Review risks

- A cached pre-fix manifest may have a matching input fingerprint yet omit root
  migrations.
- A root `migrations` path can collide with the legacy automatic fallback and
  acquire two ledger identities.
- Discovery can appear green while `Migrator` lacks `V2PlanExecutor`, causing
  the first apply to fail.
- `migrate:status` can silently omit V2 nodes even when dry-run/apply see them.
- V2 entity-evolution plans do not yet have a safe fresh-install semantic;
  that separate contract gap is tracked in `waaseyaa/framework#2701` rather
  than hidden inside discovery work.
