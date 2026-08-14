# 018 — Configuration management: active/sync store split with filesystem export

**Status:** Accepted (2026-05-11); authority model amended by
[`S1 configuration authority`](../specs/s1-configuration-authority.md)
**Mission:** Stability charter ratification; clears charter §3.2 beta criterion 9 (matrix §3.5)
**Spec context:** `docs/specs/drupal-comparison-matrix.md` §1.5, §3.5; intersects [ADR 010](010-multi-backend-field-storage.md) (config entities ride storage backends).

## Context

`ConfigEntityBase` exists. Config entities work today. What does not exist is **multi-environment promotion machinery**:

- No active-store / sync-store split.
- No `config:export` to write running config to filesystem.
- No `config:import` to apply filesystem config to a running site.
- No diff between environments.
- No config dependency graph for deterministic import ordering.

This is operationally painful. Dev-to-staging-to-prod promotion of admin-defined config (taxonomy terms, content type bundles, role permissions, theme settings) requires manual SQL dumps or hand-replicated changes. Every non-Minoo Waaseyaa consumer will hit this within their first deploy cycle.

Three reference patterns:

- **Drupal CMI** — active store in DB, sync store on filesystem (YAML), `drush config:export/import/status`. Established pattern; ~10 years of refinement.
- **Laravel** — config-as-code in `config/*.php`, env vars for env-specific values. No DB-resident editable config beyond what app code declares.
- **WordPress** — DB-resident, no sync mechanism; environments diverge or are kept in sync via plugin (WP-CFM, etc.).

Waaseyaa's existing shape (config entities, DB-resident) matches Drupal's grain. The pattern that fits is Drupal CMI.

## Options considered

### A. No CMI; document manual promotion

Apps export config by hand or via DB dumps. Rejected: every consumer hits this; framework's mission promise to obsolete Drupal weakens at the first deploy.

### B. Laravel-shape config-as-code only

Make all config code-only; deprecate `ConfigEntityBase`. Rejected: reverses an existing substantial design, forfeits the admin-editable config model that domain consumers (CMS sites, community platforms) need.

### C. Drupal-shape CMI: active/sync split with filesystem export (CHOSEN)

DB remains the runtime active store. Filesystem becomes the sync store at a configurable path (`storage/config-sync/` default). Six CLI commands handle export, import, diff, status. Config entities declare dependencies; imports respect the DAG.

## Decision

Drupal-shape config management with active/sync store split.

### Stable surface

**Active store:** one versioned SQLite generation selected by the typed
configuration authority. Named settings and config entities share that
generation identity; ordinary runtime consumers do not select storage.

**Sync store:** filesystem path holding YAML representations of every config
entity. Default path: `storage/config-sync/`. Configurable via
`config.sync_path` in `config/waaseyaa.php`. It is desired-state input/output,
never runtime authority. Teams normally preserve it in their chosen source or
artifact versioning system; no forge, hosting vendor, or VCS is required by the
framework.

**Sync file format**: one YAML file per config entity, named `<entity_type>.<entity_id>.yml`. Example: `storage/config-sync/taxonomy_vocabulary.community_categories.yml`. The format:

```yaml
_meta:
  entity_type: taxonomy_vocabulary
  uuid: 0193abc...
  dependencies:
    - config: taxonomy_vocabulary.parent_thing
  langcode: en
id: community_categories
label: Community Categories
description: ...
weight: 0
```

The `_meta` block carries entity-type, uuid, dependencies, and langcode. The remaining keys are the entity's field values.

**Stable surface elements:**

- The sync file format (`_meta` block shape and per-field serialization).
- The default sync path convention.
- The six CLI commands (below).
- The `ConfigDependencyInterface` declared on config entities.
- The dependency-graph computation rules.

### CLI surface

Six commands, all stable surface:

- `bin/waaseyaa config:export [--diff] [--dry-run]` — write active config to sync store. With `--diff`, only writes changed files.
- `bin/waaseyaa config:import [--dry-run] [--no-dependency-check]` — read sync store, apply to active config in dependency order.
- `bin/waaseyaa config:diff [<entity-type>.<id>]` — show differences between active and sync; optionally scope to one entity.
- `bin/waaseyaa config:status` — summary of sync state (in-sync / drift / sync-only / active-only counts).
- `bin/waaseyaa config:validate` — validate sync-store YAML against config entity field definitions; runs at deploy time as a guard.
- `bin/waaseyaa config:reset <entity-type>.<id>` — reset a single config entity to its sync-store value.

The verb namespace `config:*` is reserved by the framework. Apps must not register conflicting commands.

### Dependency graph

Config entities declare dependencies via `ConfigDependencyInterface`:

```php
interface ConfigDependencyInterface
{
    public function configDependencies(): array;
    // Returns ['<entity_type>.<id>', ...] config that must exist before this one
}
```

The framework computes the import-order DAG at import time. Cycles raise `ConfigDependencyCycleException` with the cycle path. Missing dependencies (config in sync store referencing nonexistent config) raise `ConfigDependencyMissingException`.

`config:import --no-dependency-check` bypasses the graph for emergency use; not recommended.

### Storage entities vs config entities

Config entities (`ConfigEntityBase`) are in scope for CMI.

**Content entities (`ContentEntityBase`) are NOT.** Content (events, teachings, dictionary entries) is not promoted between environments via CMI; it ships via fixtures, seeds, or actual content creation in each env. The CMI mechanism is config-only.

This distinction matches Drupal's. The framework MUST refuse to export content entities via `config:export`; the command operates on the config-entity registry only.

### Per-environment overrides

CMI is **environment-agnostic**. The same sync store applies to dev, staging,
and production. Bootstrap environment values may select environment identity,
database authority, sync path, and opaque secret references only. Raw secrets
and deployable values do not enter the sync store or active generation:

```php
    'secrets' => [
        'mail_provider' => [
            'api_key_env_var' => 'SENDGRID_API_KEY',
        ],
],
```

Per-environment **config-store overrides** (Drupal `$config['x']['y']`-style runtime overrides) are out of scope for v0.x. If demand emerges, a follow-up ADR adds them.

### Validation

`config:validate` runs sync-store YAML through the same `FieldDefinition::validators()` pipeline content entities use (ADR 013 §"Validation primitives"). Invalid sync-store config fails the validate command and blocks `config:import` unless `--no-dependency-check` is also used.

Validation runs at deploy time as a CI guard before `config:import`. A failing deploy that's caught at validate is much cheaper than a half-applied import.

### Interaction with storage backends (ADR 010)

Config entities ride the storage coordinator. With multi-backend storage, a config entity could theoretically have fields on a vector backend. **This is forbidden by this ADR**: config entities MUST use only `sql-blob` or `sql-column` backends. The CLI validates this at boot via a typed `InvalidConfigBackendException`. Vector/remote backends are content-entity territory.

### Migration of existing apps

Minoo and any future consumer with existing config entities:

1. Run `bin/waaseyaa config:export` once.
2. Preserve `storage/config-sync/` in the application's chosen versioned
   source or artifact system.
3. Add `storage/config-sync/` to deploy artifacts.
4. On each deploy, CI runs `config:validate` and then `config:import`.

No data migration. No schema change. The mechanism layers cleanly on top of existing config entities.

## Consequences

- **Multi-environment deployment becomes operationally tractable.** A reviewed,
  versioned sync bundle plus `config:import` promotes intent independently of
  the forge or delivery system.
- **Framework gains six CLI commands and a YAML format on stable surface.** Modest addition; bounded scope.
- **Beta gate criterion 9 is fully cleared.** Matrix §3.5 moves from `❌` to `📋` (planned). Combined with ADR 017, charter §11 Q7 is fully resolved.
- **Config entities are explicitly excluded from multi-backend storage** beyond `sql-blob` / `sql-column`. Bounds the implementation; config-entity-on-vector-backend is forbidden by typed exception.
- **Per-environment runtime overrides are a future-ADR door.** Not opened in v0.x; named so it's not forgotten.
- **Content entity promotion is explicitly NOT covered.** Apps that want "ship some content with the deploy" use fixtures or seeders, not CMI. This distinction matches Drupal and prevents content-via-config feature creep.
- **Minoo and future apps share an operational pattern.** Sister Waaseyaa apps know exactly how to manage multi-env config; no per-app divergence.

## References

- Matrix: `docs/specs/drupal-comparison-matrix.md` §1.5, §3.5.
- Charter: `docs/specs/stability-charter.md` §3.2 criterion 9, §5.5 (config / env rules).
- Related ADRs: [ADR 010](010-multi-backend-field-storage.md) (config rides storage; forbidden on vector/remote backends), [ADR 013](013-form-abstraction-apps-own.md) (validation pipeline reused for sync-store validation), [ADR 017](017-per-field-translation.md) (config translation is named as out-of-scope here; addressed in a future ADR).
- Audit reference: `waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md` (mission-completeness gap).
- Drupal prior art: CMI, Configuration Management module, `drush config:*` commands.
- Parity reference: Drupal CMI (matched), Laravel (asymmetric — Laravel doesn't have DB-resident editable config; we keep ours because the entity model demands it).
