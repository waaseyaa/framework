# Upgrade Guides

This directory contains per-release upgrade guides for Waaseyaa framework consumers.

## Naming convention

Files are named `waaseyaa-alpha-X-to-Y.md` where `X` is the previous alpha tag and
`Y` is the new alpha tag (e.g. `waaseyaa-alpha-0.1.0-alpha.165-to-0.1.0-alpha.170.md`).
During development the literal `X` and `Y` placeholders are used; the governed
`release-cut.yml` workflow substitutes the actual tag values at tag time. There
is no local release script.

## What each guide covers

Each upgrade guide documents:

- **Stable-surface deltas** — new `@api`-annotated symbols, changed constructor
  signatures, removed symbols.
- **Migration recipes** — step-by-step instructions for common upgrade paths such
  as sql-blob → sql-column backend migration.
- **Opt-in feature steps** — how to adopt new opt-in capabilities (e.g. revisions,
  new backend types).
- **Backwards-compatibility notes** — what doesn't change and what you can safely
  ignore.
- **Rollback plan** — how to revert if the upgrade causes problems.

## Guides

| Guide | Mission | Coverage |
|---|---|---|
| [waaseyaa-alpha-X-to-Y.md](waaseyaa-alpha-X-to-Y.md) | entity-storage-v2-01KRCDDC (M-001) | sql-blob → sql-column migration, revision opt-in, `view_revision` policy, partial-save recovery |

## Applying a migration

```bash
# Generate the migration file for an entity type
bin/waaseyaa make:storage-migration <entity_type_id>

# Inspect without writing
bin/waaseyaa make:storage-migration <entity_type_id> --dry-run

# Apply
bin/waaseyaa migrate

# Roll back the most recent migration
bin/waaseyaa migrate:rollback
```

See the relevant upgrade guide for the full recipe including verification steps.
