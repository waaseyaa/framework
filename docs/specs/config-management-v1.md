<!-- Spec reviewed 2026-06-15 - convention-spec-gate D-10: this v1 mission spec shipped as M-003 and was superseded by the canonical doctrine spec config-management.md. Added a SUPERSEDED banner and corrected the stale Draft status so it no longer reads as a live spec. -->
# Configuration Management v1 — Active/Sync Store Split

> **⚠️ SUPERSEDED.** This is the original M-003 mission spec, retained for
> historical/audit context only. The mission **shipped 2026-05-16**; the live,
> canonical doctrine spec is **[`config-management.md`](config-management.md)** —
> read that for the current Configuration Management (CMI) surface. Do not build
> against the draft requirements below.

**Status:** Superseded by [`config-management.md`](config-management.md) — shipped as M-003 (mission `config-management-v1-01KRCDEC`, 2026-05-16); retained for historical/audit context.
**Audience:** framework maintainers; input for Spec Kitty `specify` → `plan` → `tasks` flow
**Mission ID:** TBD (to be assigned by `@jonesrussell` on mission creation)
**Origin:** [ADR 018](../adr/018-configuration-management-sync.md) (Accepted 2026-05-11).

**Governing ADR:** [ADR 018](../adr/018-configuration-management-sync.md) — Drupal-shape CMI with active/sync store split.

**Charter linkage:**
- [`stability-charter.md`](stability-charter.md) §5.5 (Config/env) governs the existing config surface; this mission proposes an extension covering the sync layer and `config:*` CLI namespace.
- Beta-gate criterion 9 (charter §3.2) is unblocked by ADR 018's acceptance; this mission ships the implementation.

**Sibling missions:**
- Independent. Does not depend on `entity-storage-v2.md` for shipping, because config entities can stay on `sql-blob` in v0.x. May coordinate with entity-storage-v2 if a config entity is opted into `sql-column` during that mission.

---

## 0. Origin

`ConfigEntityBase` exists. Config entities work today. What does not exist is **multi-environment promotion machinery** — no active/sync store split, no `config:export` / `config:import`, no diff, no dependency graph for deterministic ordering.

ADR 018 commits to the Drupal-shape pattern: DB remains the runtime active store, filesystem becomes the sync store, six CLI commands handle export/import/diff/status/validate/reset. The mechanism layers cleanly on top of existing config entities — no schema migration of existing data, no breaking change to consumers.

This mission ships the layer.

---

## 1. Goals / non-goals

### 1.1 Goals

1. Define `ConfigDependencyInterface` and the dependency-graph computation, including cycle detection.
2. Ship the **sync-store file format** — `<entity_type>.<entity_id>.yml` with a `_meta` block carrying entity-type, uuid, dependencies, langcode; remaining keys are entity field values.
3. Ship the **six CLI commands** on the stable `config:*` namespace: `export`, `import`, `diff`, `status`, `validate`, `reset`.
4. Implement **dependency-ordered import** that respects the DAG; orphan-handling defaults to warn (not delete).
5. Implement **validation** via the existing `FieldDefinition::validators()` pipeline (from [ADR 013](../adr/013-form-abstraction-apps-own.md)); validation runs as a CI gate before import.
6. Enforce the **backend restriction** — config entities forbidden from `vector` and `remote` backends; allowed only on `sql-blob` / `sql-column`.
7. Reserve the **`config:*` CLI verb namespace** framework-side; app commands using these verbs fail at boot.
8. Document the **per-environment override pattern** — env vars in `config/waaseyaa.php`, NOT sync-store overrides.
9. Validate the mission with a **Minoo config-sync round-trip** — export, modify in sync store, import, diff = 0.

### 1.2 Non-goals

- **Per-environment config-store overrides** (Drupal `$config['x']['y']` runtime overrides). Deferred; charter §11 names this as a future-ADR door.
- **Content entity promotion via CMI.** Content (events, teachings, dictionary entries) is NOT covered. Promotion of content between environments is a fixture/seed concern, not a config concern. Matches Drupal.
- **Admin UI for config sync** (the "Synchronize" admin screen in Drupal). CLI-only in v0.x.
- **Config translation** (per-langcode config entities). Defers to a future ADR that bridges [ADR 017](../adr/017-per-field-translation.md) and ADR 018.
- **Schema validation** of sync-store YAML against an explicit config schema definition. v0.x uses field-definition validators (which is field-level validation, not full schema validation).
- **Migration of pre-existing config from non-Waaseyaa systems.** That's the migration platform's job ([`migration-platform-v1.md`](migration-platform-v1.md)).

---

## 2. Scope summary

### 2.1 In scope

- `ConfigDependencyInterface` and a default no-op implementation on `ConfigEntityBase`.
- Dependency graph computation; cycle detection; missing-dep detection.
- Sync-store YAML format with `_meta` block.
- File-system layout under `storage/config-sync/`; path configurable via `config.sync_path`.
- Six CLI commands: `config:export`, `config:import`, `config:diff`, `config:status`, `config:validate`, `config:reset`.
- `--dry-run` flag on `export`, `import`, `diff`.
- `--delete-orphans` flag on `import` (default off; defaults to warn).
- `--halt-on-error` flag on `import` (default off; per-entity errors logged but don't stop).
- `--no-dependency-check` flag on `import` (emergency bypass).
- `--yes` flag on `reset` (skip confirmation).
- Atomic per-entity import (each entity is one transaction); non-atomic across the run.
- Backend-restriction enforcement (boot-time check + typed exception).
- `config:*` CLI namespace reservation.
- Validation via existing `FieldDefinition::validators()` pipeline.
- Audit log channel `config.audit` for import/export/reset operations.
- Documentation: spec, cookbook, upgrade guide, charter §5.5 amendment.

### 2.2 Out of scope

(See §1.2 non-goals.)

---

## 3. Functional requirements

Normative requirements use **MUST / SHOULD / MAY** per RFC 2119. Numbered for Spec Kitty tokenization.

### 3.1 Dependency graph

- **FR-001** The framework MUST expose `Waaseyaa\Config\ConfigDependencyInterface` as stable surface.
- **FR-002** `ConfigDependencyInterface` MUST require `configDependencies(): array` returning a list of `<entity_type>.<entity_id>` strings.
- **FR-003** `ConfigEntityBase` MUST provide a default no-op implementation returning `[]`. Entity types declaring dependencies override.
- **FR-004** The framework MUST compute the dependency DAG at import time across all sync-store entries.
- **FR-005** Cycle detection MUST raise `ConfigDependencyCycleException` carrying the full cycle path (e.g. `taxonomy.foo → menu.bar → taxonomy.foo`).
- **FR-006** Missing-dependency detection (sync-store entry references nonexistent config) MUST raise `ConfigDependencyMissingException` carrying the missing id.
- **FR-007** `--no-dependency-check` flag MUST bypass cycle and missing-dep detection. Use is logged at `warning` level to `config.audit`.
- **FR-008** Dependencies MAY span entity types and packages. The graph is global within the app's config-entity registry.

### 3.2 Sync-store file format

- **FR-009** Files MUST be named `<entity_type>.<entity_id>.yml`. Examples: `taxonomy_vocabulary.community_categories.yml`, `role.coordinator.yml`.
- **FR-010** Each file MUST have a top-level `_meta` block with: `entity_type`, `uuid`, `dependencies` (array of strings), `langcode`.
- **FR-011** Remaining top-level keys MUST be entity field values. The serializer MUST map `FieldDefinition` types to YAML scalars/sequences/mappings per a documented type table (FR-011a deliverable in §5).
- **FR-012** Serialization MUST use the entity's declared field definitions; fields not declared on the EntityType MUST NOT appear in the YAML.
- **FR-013** Deserialization MUST validate that the file's `_meta.entity_type` matches the filename prefix. Mismatch raises `ConfigSerializationException`.
- **FR-014** Default sync path MUST be `storage/config-sync/` resolved relative to the project root. Configurable via `config.sync_path` in `config/waaseyaa.php`.
- **FR-015** The sync store path MUST be considered git-tracked by convention; consumer apps add `storage/config-sync/` to their committed paths.
- **FR-016** The YAML format (key names in `_meta`, field-value serialization rules, file-naming convention) MUST be on stable surface. Changes follow charter §4 deprecation cycle. Reason: consumer git history depends on stable diffs.

### 3.3 config:export

- **FR-017** `bin/waaseyaa config:export` MUST write all active config entities to the sync store. Each entity becomes one YAML file per FR-009.
- **FR-018** `--diff` flag MUST cause export to write only files whose content would differ from the existing sync-store file. Unchanged files are not touched (preserves git's mtime-aware diff semantics).
- **FR-019** `--dry-run` flag MUST cause export to compute the would-be writes without touching the filesystem. Output: list of files that would be created / updated / unchanged.
- **FR-020** Export output MUST include a summary line: "X created, Y updated, Z unchanged."
- **FR-021** Exit code: 0 on success, 1 on any serialization error.

### 3.4 config:import

- **FR-022** `bin/waaseyaa config:import` MUST read the sync store, validate every entry (FR-037), then apply each in dependency order (FR-004).
- **FR-023** Each entity import MUST happen in its own database transaction. Successes commit; failures roll back the individual entity's transaction.
- **FR-024** `--dry-run` flag MUST cause import to compute would-be changes without writing to the active store. Output: per-entity preview.
- **FR-025** Per-entity diffs MUST be displayed for changes (additions, updates, removals).
- **FR-026** Orphan handling: a config entity in the active store with no matching sync-store entry. Default behavior MUST be **warn** — log a line per orphan to `config.audit`, do not delete. `--delete-orphans` flag opts into deletion.
- **FR-027** Each entity write MUST run through normal config-entity validation. Validation failures raise `ConfigImportFailedException` for that entity.
- **FR-028** Per-entity errors MUST be logged and counted. The run continues unless `--halt-on-error`.
- **FR-029** Final exit code: 0 only if all entities imported successfully; 1 if any failed.

### 3.5 config:diff

- **FR-030** `bin/waaseyaa config:diff` MUST show differences between active and sync stores. Without arguments, shows all entities. With `<entity-type>.<id>`, scopes to one.
- **FR-031** Per-entity diff format MUST be a unified diff of the YAML representation. Both sides serialize identically before diffing to avoid spurious whitespace differences.
- **FR-032** Exit code: 0 if no differences; 1 if any.
- **FR-033** UUID-tracked rename detection: if an entity's `_meta.uuid` matches an active-store entity but the id differs, the diff MUST display this as a rename, not as delete+create.

### 3.6 config:status

- **FR-034** `bin/waaseyaa config:status` MUST output: in-sync / drift / sync-only / active-only counts, plus a per-entity table when counts are non-trivial (< 50 total entries).
- **FR-035** Output format MUST be machine-parseable (`--format=json` flag for CI consumption).
- **FR-036** Status output MUST be a read-only operation; no side effects on either store.

### 3.7 config:validate

- **FR-037** `bin/waaseyaa config:validate` MUST validate sync-store YAML against entity field definitions, using the existing `FieldDefinition::validators()` pipeline.
- **FR-038** Validation errors MUST block `config:import` unless `--no-dependency-check` is also used (emergency bypass).
- **FR-039** Output MUST be per-entity, with per-field error detail.
- **FR-040** Validation MUST be runnable independently — CI uses `config:validate` as a deploy-time gate before `config:import`.

### 3.8 config:reset

- **FR-041** `bin/waaseyaa config:reset <entity-type>.<id>` MUST reset a single config entity to its sync-store value, overwriting the active store.
- **FR-042** Reset MUST prompt for confirmation unless `--yes` is provided.
- **FR-043** Each reset MUST log to `config.audit` channel: entity-type, id, actor, before-after diff summary.

### 3.9 Backend enforcement

- **FR-044** Config entities MUST only use the `sql-blob` or `sql-column` storage backends (per ADR 010 + ADR 018).
- **FR-045** Boot-time validation MUST raise `InvalidConfigBackendException` for any config entity declaring an alternate backend (e.g. `vector`, `remote`).
- **FR-046** The exception MUST carry: the offending entity type id, the disallowed backend id, the FQCN of the declaring code.

### 3.10 CLI namespace reservation

- **FR-047** The `config:*` CLI verb namespace MUST be reserved framework-side. Reserved sub-verbs: `export`, `import`, `diff`, `status`, `validate`, `reset`.
- **FR-048** App or extension commands registering reserved verbs MUST fail at boot via `ConfigCommandCollisionException`. Boot fails loudly per charter §5.4.
- **FR-049** Apps MAY register `config:<custom>` verbs that are NOT in the reserved set (e.g. `config:audit-export`); they own those.

### 3.11 Error model

- **FR-050** The mission MUST ship these exception types on stable surface: `ConfigDependencyCycleException`, `ConfigDependencyMissingException`, `InvalidConfigBackendException`, `ConfigSerializationException`, `ConfigImportFailedException`, `ConfigCommandCollisionException`.
- **FR-051** Each exception type MUST carry a stable string `code` field per charter §4.4.
- **FR-052** Renames or removals of any of the exception types or their codes MUST follow the deprecation cycle (charter §4).
- **FR-053** The `config.audit` log channel MUST be on stable surface (charter §4.4 amendment).

### 3.12 Validation (mission-internal)

- **FR-054** WP10 MUST demonstrate a Minoo config-sync round-trip: export Minoo's config → modify a sync-store file → import → diff returns 0; or equivalently, active store now matches the modified sync-store file.
- **FR-055** Round-trip preservation: export → import without modification → no observable change in the active store. No spurious diffs, no unintended writes.
- **FR-056** Cycle detection: a fixture with a deliberate `A → B → A` cycle MUST raise `ConfigDependencyCycleException` with the full cycle path.

### 3.13 Documentation

- **FR-057** `docs/specs/config-management.md` MUST exist post-mission as the canonical spec for the shipped surface.
- **FR-058** `docs/cookbook/config-sync.md` MUST exist as the operator guide — how to set up sync in a new app, how to handle conflicts, how to roll back a bad import.
- **FR-059** An upgrade-guide entry MUST ship for the alpha train that introduces CMI (per charter §7).
- **FR-060** Charter §5.5 MUST be amended to reference this mission's stable surface (the `config:*` CLI, the sync-store format, `ConfigDependencyInterface`).
- **FR-061** Per-environment override pattern MUST be documented prominently in the cookbook: env vars in `config/waaseyaa.php`, NOT sync-store overrides. The intent is to prevent operators from rolling their own per-env-sync-stores when they hit this need.

---

## 4. Stable surface deliverables

Maps the mission's stable-surface output to charter §5.5 (amended).

| Symbol | Kind | Notes |
|---|---|---|
| `ConfigDependencyInterface` | Interface | Default no-op on `ConfigEntityBase`; override per entity type to declare deps |
| Sync-store YAML format | File format spec | `_meta` block shape + field-value mapping table |
| `config.sync_path` config key | Config key | Default `storage/config-sync/` |
| `config.audit` log channel | Channel constant | Charter §4.4 amendment |
| `bin/waaseyaa config:export/import/diff/status/validate/reset` | CLI commands | Six commands; reserved sub-verb namespace |
| `ConfigDependencyCycleException`, `ConfigDependencyMissingException`, `InvalidConfigBackendException`, `ConfigSerializationException`, `ConfigImportFailedException`, `ConfigCommandCollisionException` | Exception classes | Stable surface; codes follow charter §4 |

**Charter amendment required:** §5.5 extended to cover the sync layer and CLI namespace. Drafted as part of WP11.

---

## 5. Sync-store format spec (normative)

### 5.1 File naming

`<entity_type>.<entity_id>.yml`. Entity-type and entity-id MUST be lowercase ASCII with `_` separators. Files outside this naming convention MUST be ignored by `config:import` (warn-and-skip; not error).

### 5.2 `_meta` block

```yaml
_meta:
  entity_type: taxonomy_vocabulary
  uuid: 0193abc...
  dependencies:
    - taxonomy_vocabulary.parent_thing
    - role.admin
  langcode: en
```

- `entity_type` (string) — must match filename prefix. Mismatch is an error.
- `uuid` (string) — stable across renames. If a sync file is renamed (entity id changes) but uuid is preserved, the import treats it as a rename, not a create+delete.
- `dependencies` (string[]) — declared dependencies. Used by the DAG.
- `langcode` (string) — language code of the config entity. Default `en` for non-translatable config (most cases).

### 5.3 Field value mapping

Maps `FieldDefinition` types to YAML representations. Initial mapping table (FR-011a deliverable):

| FieldDefinition type | YAML representation |
|---|---|
| `string` | scalar string |
| `int` | scalar int |
| `bool` | scalar bool |
| `datetime` | ISO 8601 string |
| `json` | mapping or sequence (native YAML structure) |
| `text` | scalar string (multi-line block where appropriate) |
| `uuid` | scalar string |
| `entity_reference` | `<entity_type>.<entity_id>` string |
| `field_list` | sequence of scalars |

Future field types extend this table; extensions follow charter §4 deprecation cycle.

### 5.4 Serialization rules

- YAML keys MUST be sorted alphabetically within `_meta` and within each top-level field group. Reason: deterministic diffs.
- Multi-line string values use YAML block scalars (`|` or `>`) when the value contains newlines.
- Empty arrays and maps serialize as `[]` and `{}` (flow style for empty values reduces visual noise in diffs).
- The `_meta` block MUST appear first; field values follow alphabetically.

---

## 6. Dependency graph spec

### 6.1 Computation

At import time:

1. Scan the sync store; parse each file's `_meta.dependencies`.
2. Build a directed graph: each file is a node; each dependency declaration is an edge from the dependency to the dependent.
3. Compute a topological order; this is the import order.
4. If the graph has a cycle, raise `ConfigDependencyCycleException` with the cycle path. Detection uses standard DFS.
5. If a dependency references an entity not present in the sync store and not present in the active store, raise `ConfigDependencyMissingException`.

### 6.2 Cross-package dependencies

A config entity in package A may depend on a config entity in package B. The graph is computed across all packages' contributions; ordering respects all declared dependencies regardless of which package contributes them.

### 6.3 Cycles

Cycles in real-world config are usually a sign of bad modeling — e.g. two taxonomies that each declare the other as a dependency. The exception's cycle-path message helps operators identify the offending pair.

`--no-dependency-check` bypasses cycle detection for emergency recovery. Use is logged at `warning` level.

---

## 7. CLI command behaviors

### 7.1 config:export

```
bin/waaseyaa config:export [--diff] [--dry-run]
```

1. Iterate the config-entity registry.
2. For each entity, serialize to YAML per §5.
3. Write to sync store path (default `storage/config-sync/`).
4. `--diff`: write only when content differs from existing file.
5. `--dry-run`: compute writes without filesystem effects; print summary.
6. Output summary: "X created, Y updated, Z unchanged."

### 7.2 config:import

```
bin/waaseyaa config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]
```

1. Validate every sync-store file via `config:validate` (FR-037). Failures block import unless `--no-dependency-check`.
2. Build dependency graph (§6).
3. Apply entities in topological order. Each in its own transaction.
4. Per-entity diff displayed when running interactively (TTY); suppressed in CI.
5. Orphans: default = warn; `--delete-orphans` = delete.
6. Per-entity errors counted; continue unless `--halt-on-error`.
7. Final summary: "N created, M updated, K deleted, J failed, P unchanged."

### 7.3 config:diff

```
bin/waaseyaa config:diff [<entity-type>.<id>]
```

1. For each requested entity (or all), serialize the active-store entity to a temporary YAML.
2. Compare to the sync-store YAML (or absence).
3. Print unified diff with `---`/`+++` headers showing active vs sync.
4. Exit 0 if no differences; 1 if any.

### 7.4 config:status

```
bin/waaseyaa config:status [--format=plain|json]
```

1. Compute counts: in-sync / drift / sync-only / active-only.
2. Print summary and (if interactive and counts < 50) per-entity table.
3. Read-only.

### 7.5 config:validate

```
bin/waaseyaa config:validate
```

1. Parse every sync-store file.
2. For each, instantiate the would-be entity (without persisting).
3. Run `FieldDefinition::validators()` on each field.
4. Output per-entity errors; exit 0 if all valid, 1 if any error.

### 7.6 config:reset

```
bin/waaseyaa config:reset <entity-type>.<id> [--yes]
```

1. Confirm with operator (skip if `--yes`).
2. Load the sync-store entity.
3. Overwrite the active-store entity (transactional, lifecycle events fire).
4. Log to `config.audit` channel with actor, before-after, timestamp.

---

## 8. Work package decomposition

Eleven WPs. Smaller than entity-storage-v2 or migration-platform-v1 — CMI is conceptually simpler.

| WP | Title | Primary FRs | Depends on |
|---|---|---|---|
| **WP01** | `ConfigDependencyInterface` + DAG computation + cycle detection | FR-001..FR-008 | — |
| **WP02** | Sync-store YAML format + serialization/parsing layer | FR-009..FR-016 | WP01 (dependency declarations serialized in `_meta`) |
| **WP03** | `config:export` command | FR-017..FR-021 | WP02 |
| **WP04** | `config:import` command (dependency-ordered, atomic per entity) | FR-022..FR-029 | WP01, WP02 |
| **WP05** | `config:diff` + `config:status` (read-only inspection commands) | FR-030..FR-036 | WP02 |
| **WP06** | `config:validate` (uses existing FieldDefinition validators) | FR-037..FR-040 | WP02 |
| **WP07** | `config:reset` + `config.audit` log channel | FR-041..FR-043, FR-053 | WP04 |
| **WP08** | Backend restriction enforcement (boot-time check) | FR-044..FR-046 | — |
| **WP09** | `config:*` CLI namespace reservation + collision check | FR-047..FR-049 | — |
| **WP10** | Validation: Minoo config-sync round-trip + cycle-fixture test | FR-054..FR-056 | WP03, WP04, WP05, WP06 |
| **WP11** | Documentation: spec, cookbook, upgrade-guide entry, charter §5.5 amendment | FR-057..FR-061 + charter | WP04, WP05, WP09 |

### 8.1 Sequencing diagram

```
WP01 ──► WP02 ──┬──► WP03 ──┐
                │            │
                ├──► WP04 ──┤
                │           │
                ├──► WP05 ──┤
                │           │
                └──► WP06 ──┤
                            │
                  WP04 ──► WP07
                            │
WP08, WP09 (independent) ───┤
                            │
                            └──► WP10 (validation) ──► WP11 (docs)
```

### 8.2 Parallelizable WPs

After WP02: WP03, WP04, WP05, WP06 can run in parallel. WP07 needs WP04. WP08 and WP09 are independent and can land at any time (recommend early — they're small).

### 8.3 No cross-mission external dependencies

This mission does not depend on entity-storage-v2 for shipping. Config entities can stay on `sql-blob` indefinitely; CMI works regardless. If a consumer migrates a config entity to `sql-column` (via entity-storage-v2's migration generator), CMI continues working — serialization is field-definition-driven, not backend-driven.

---

## 9. Acceptance criteria

The mission is complete when:

1. All 11 WPs are merged.
2. All FRs in §3 are covered by tests.
3. WP10's Minoo round-trip test passes in CI: Minoo's config entities export, can be modified in sync store, import, diff = 0.
4. The cycle-detection fixture test (FR-056) passes — a deliberate cycle raises the exception with the cycle path.
5. Charter §5.5 amendment lands as part of WP11, referencing `ConfigDependencyInterface`, the sync-store format, and the six `config:*` commands.
6. `public-surface-map.md` / `public-surface-map.php` gain entries for the new surface with tier (`stable`) and mission-status (`present`) labels.
7. Cookbook prominently documents the per-environment override pattern (env vars in `config/waaseyaa.php`).

---

## 10. Open questions

Mission-specific, in addition to charter §11 operational items.

1. **Validation pipeline state.** ADR 013 commits the framework to ship field-level validation primitives (`FieldDefinition::validators()`). Is this fully shipped today, or does this mission depend on a prerequisite validation-primitives mission? If not shipped: WP06 cannot start; this mission gates on validation primitives landing first. Recommend: confirm before WP01 starts.
2. **Orphan handling default.** FR-026 defaults to warn-not-delete. Drupal defaults to delete. The warn default is safer (no silent data loss) but operators may expect Drupal semantics. Recommend: ship warn-default; document Drupal-equivalence requires `--delete-orphans`; revisit if operator feedback strongly prefers delete-default.
3. **UUID generation on export.** When an existing config entity has no UUID (legacy from pre-CMI), does `config:export` generate one? Recommend: yes; generated UUIDs are deterministic (sha-256 of `entity_type + entity_id`) so the same entity always gets the same UUID across environments.
4. **Multi-environment overrides — env-vars pattern sufficient?** ADR 018 defers runtime overrides. Operators with per-env config (e.g. "feature_x enabled in staging but not prod") will hit friction. Recommend: document the env-vars pattern aggressively in the cookbook; revisit if friction emerges. If a future ADR adds per-env overrides, this mission's surface is unaffected — overrides are a parallel mechanism, not a sync-store extension.
5. **Atomicity scope.** FR-023 specifies per-entity transactions; FR-028 specifies per-entity errors. The alternative — full-import atomicity — is appealing but hard to make robust across hundreds of config entities with dependencies. Recommend: stay per-entity; document the boundary clearly so operators understand "a failed import is partially applied."
6. **Reset confirmation pattern.** FR-042 prompts unless `--yes`. Matches existing `bin/waaseyaa migrate:rollback` ergonomics. Confirm.
7. **CLI dry-run output format.** Per-entity diffs in `--dry-run` and `config:diff` should match the same format for operator familiarity. Single shared diff-renderer. Confirm before WP03 starts.
8. **Cross-package config dependencies.** §6.2 supports them; an extension package may declare config that depends on another extension's config. Confirm the order of provider registration is deterministic enough that dependency resolution always succeeds when the producing package is installed. If not: explicit declaration of provider load order may be needed.

---

## 11. References

- [ADR 018](../adr/018-configuration-management-sync.md) — governing decision (Accepted 2026-05-11).
- [ADR 010](../adr/010-multi-backend-field-storage.md) — backend restriction (config forbidden on vector/remote).
- [ADR 013](../adr/013-form-abstraction-apps-own.md) — validation pipeline reused for `config:validate`.
- [`stability-charter.md`](stability-charter.md) §5.5 (config/env rules); this mission amends.
- [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md) §1.5, §3.5 — origin of the gap; resolved by ADR 018.
- [`entity-storage-v2.md`](entity-storage-v2.md), [`migration-platform-v1.md`](migration-platform-v1.md) — sibling mission specs; style template.
- 2026-05-11 framework/app audit (`waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`) — strategic context.
- Drupal Configuration Management — prior art.

---

## 12. Mission metadata for Spec Kitty

```yaml
mission:
  id: TBD
  title: Configuration Management v1 — Active/Sync Store Split
  status: draft-spec
  governing_adrs: [018]
  related_adrs: [010, 013]
  charter_dependencies:
    - section: §5.5
      relation: amends
  external_dependencies:
    - feature: FieldDefinition::validators() pipeline (per ADR 013)
      relation: required-for-wp06
      verification: confirm-before-wp01
  validation_consumer: minoo
  validation_scope: full-config-sync-round-trip
  work_packages: 11
  parallelizable_after_wp02: true
  estimated_breaking_change_count: 0  # additive surface; existing config entities continue working unchanged
  ships_followup_mission_unblocked: none
  agent_assignments:
    implementer: sonnet
    reviewer: opus
```
