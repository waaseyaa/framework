# Configuration Management (CMI) — Active/Sync Store Split

<!-- Spec reviewed 2026-05-16 - M-003 closure (config-management-v1-01KRCDEC) -->

**Status:** Shipped (M-003, mission `config-management-v1-01KRCDEC`, 2026-05-16)
**Audience:** framework maintainers + application authors operating multi-environment deployments.
**Governing ADR:** [ADR 018](../adr/018-configuration-management-sync.md) — Drupal-shape CMI with active/sync store split (Accepted 2026-05-11).
**Charter linkage:** [`stability-charter.md`](stability-charter.md) §5.5 enumerates the stable surface ratified by this mission; beta-gate criterion 9 (§3.2) is **SATISFIED** by this mission's landing.
**Mission archive:** [`kitty-specs/config-management-v1-01KRCDEC/`](../../kitty-specs/config-management-v1-01KRCDEC/) — original spec, plan, work packages, review history.

This is the canonical doctrine spec. The original mission spec
[`config-management-v1.md`](config-management-v1.md) is retained as a historical artifact;
this file is the single source of truth post-mission.

> **S1 authority amendment (2026-08-12).** The command and YAML surfaces below
> remain stable, but active production configuration is now one versioned
> SQLite generation composed through `configuration.authority.v1`. The sync
> directory is desired-state input/output and is never a runtime fallback.
> Production mutation remains refused until CFG-02 supplies atomic generation
> activation and CFG-03 supplies schema, manifest, compatibility, and drift
> gates. Environment overlays may select bootstrap authorities and opaque
> secret references only; they cannot override deployable values.

### S1 transactional activation amendment (CFG-02)

Production mutation compiles a complete immutable successor generation and
publishes it through one database transaction. The active token is the pair of
a deterministic content `generation_id` and a monotonically increasing
`activation_sequence`; both are compared at commit so rollback to familiar
content cannot create an ABA hole. A caller-supplied activation request ID is
bound to the canonical input and original expected token before staging, making
lost-response retries idempotent and mismatched reuse a typed refusal.

Ordinary input is additive: absence retains the active entry. Deletion requires
an explicit tombstone bound to the expected active entry hash, or a separately
authorized complete-replacement plan. The transaction's first operation claims
the configuration activation counter, then rechecks the expected head and
appends one immutable activation record. Any false result, exception,
contention, or stale token leaves the previous head serving. Events and external
evidence follow commit and never define the head.

### S1 schema, sync-format, and manifest amendment (CFG-03)

The normative closed schema dialect, strict versioned sync format, canonical
authored/effective identities, package compatibility rules, signed-envelope
boundary, and snapshot-consistent drift contract are defined in
[`s1-configuration-schema-manifest.md`](s1-configuration-schema-manifest.md).
Production continues to refuse unsigned activation until CFG-04 supplies
independent key custody and trust policy.

#### CFG-03 production composition (#2430)

The verification types existed from the start but were never composed, so
`config:import` always received `RefusingConfigImportPreflight` and always
refused. Three of `VerifiedConfigImportPreflight`'s five dependencies had no
production producer at all: `ConfigSyncBundleValidator` and
`ConfigPackageCompatibility` were bound nowhere, and nothing could produce or
load a `VerifiedConfigManifest` because no code signed a bundle manifest or read
an envelope back. Restoring the path is one unit of work, because a producer
without a verifier is unusable and a verifier without a producer is falsely
reassuring.

**The trust boundary is a split between two hosts.** Signing is an authoring
action performed where custody lives — a maintainer machine or a protected CI
environment. Importing is performed by the consumer, which holds public
`trust_keys` and never the signing key. The signing secret must never be exposed
to pull-request workflows or to ordinary production runtime.

| Side | Holds | Does |
|---|---|---|
| Authoring host | signing key (via the secret registry), authored `config/sync` | validates the bundle, builds the canonical manifest, signs the envelope, writes it |
| Importing host | public `trust_keys` only | recomputes the manifest, verifies byte identity, signature, compatibility, and replay, then imports |

**Envelope location.** The envelope is a *sibling* of the sync directory, named
from the same governed selector: `config/sync` yields `config/sync.envelope.json`.
It cannot live inside the bundle — `ConfigSyncBundleValidator` is strict and
complete, so every file in the sync directory must be a valid versioned config
sync file and a JSON envelope there would fail the bundle. Deriving the path from
`ConfigurationAuthorityContext::$syncPath` also means the envelope inherits the
existing sync selector and its provenance rules rather than introducing a second,
separately governed selector.

`Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile` owns that path
(`pathFor()`, `read()`, `write()`) and moves bytes only — it never verifies a
signature, so reading an envelope grants nothing on its own. Writes are
temp-then-rename: a partially written sidecar would fail verification in a way
indistinguishable from tampering. An absent envelope reads as `null`, because a
site may legitimately hold none yet and the preflight turns that into an
actionable refusal; malformed, unreadable, or symlinked bytes throw, because
something present and untrustworthy must never be mistaken for nothing being
present.

**Nothing self-attests.** `VerifiedConfigBundle::bind()` recomputes the manifest
from the freshly validated sync directory and requires byte identity with the
manifest carried inside the signed envelope. A signature therefore covers exactly
the authored bytes on the importing host at import time. Package compatibility is
built only from `extra.waaseyaa.config-contract` declarations discovered at boot
(`PackageManifest::$configContracts`), never from the bundle under import — a
bundle that could name its own contract version would be authorizing itself.

`Waaseyaa\Config\Sync\SignedEnvelopeConfigImportPreflight` is the importing
host's gate and the binding published for `ConfigImportPreflightInterface`. It
reads the sidecar, verifies signature and replay sequence, then delegates to
`VerifiedConfigImportPreflight`. Verification happens at import time rather than
at container composition for two reasons: replay state needs the database, and a
verification failure must surface as a refusal from `config:import` rather than a
kernel that cannot boot. When replay state is unavailable the composition falls
back to `RefusingConfigImportPreflight` — a gate missing one of its checks is not
a weaker gate, it is a different one.

**Unsigned stays refused.** `UnsignedConfigPolicy` remains `refusing()` pending a
sealed CFG-01 bootstrap identity. The sealed-unsigned policy is not a shortcut
around this work.

**Genesis is separate.** `install:init` activates only the canonical empty
generation (#2428). It can express no content, claims no CFG-03 verification, and
is unaffected by any of the above. A freshly installed site is bootable but
unconfigured until a verified import runs.

---

## 1. What ships

Eleven work packages composed an active/sync configuration substrate on top of
the pre-existing `ConfigEntityBase`. Existing config entities continue working
unchanged — CMI is purely additive.

**Subsystem surface (Layer 1, `packages/config/`):**

| Slice | Purpose | FQCNs |
|---|---|---|
| Dependency declarations | DAG ordering for import | `Waaseyaa\Config\Dependency\ConfigDependencyInterface` + `Dependency\Exception\ConfigDependencyCycleException`, `ConfigDependencyMissingException` |
| Sync-store format | Deterministic YAML serialization with `_meta` block | `Waaseyaa\Config\Sync\ConfigSyncFile`, `ConfigSyncSerializer`, `ConfigSyncDeserializer`, `ConfigSyncRepository`, `ConfigSyncFileSourceInterface`, `ConfigManifestEntry` |
| Orchestrators | One service per CLI command | `Waaseyaa\Config\Sync\ConfigExporter`, `ConfigImporter`, `ConfigDiffer`, `ConfigStatusReporter`, `ConfigSyncValidator`, `ConfigResetter`, `ConfigImportApplyHookInterface` |
| Audit channel | `config.audit` log channel | `Waaseyaa\Config\Audit\ConfigAuditChannel` (`CHANNEL` constant) + `ConfigAuditEvent` |
| Backend restriction | Boot-time guard: config entities limited to `sql-blob` / `sql-column` | `Waaseyaa\Config\Backend\BackendRestrictionEnforcer` + `Waaseyaa\Config\Exception\InvalidConfigBackendException` |
| CLI namespace reservation | Six reserved `config:*` sub-verbs | `Waaseyaa\CLI\Command\Config\ConfigCommand` (abstract base with `RESERVED_VERBS`, `RESERVED_FULL_VERBS`, `RESERVED_FQCNS` constants) + `Waaseyaa\Config\Exception\ConfigCommandCollisionException` |

**CLI surface (Layer 6, `packages/cli/`):** six commands under `bin/waaseyaa config:*`.

| Command | Class | Spec FRs |
|---|---|---|
| `config:export [--diff] [--dry-run]` | `Waaseyaa\CLI\Command\Config\ConfigExportCommand` | FR-017..FR-021 |
| `config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]` | `Waaseyaa\CLI\Command\Config\ConfigImportCommand` | FR-022..FR-029 |
| `config:diff [<entity-type>.<id>]` | `Waaseyaa\CLI\Command\Config\ConfigDiffCommand` | FR-030..FR-033 |
| `config:status [--format=plain|json]` | `Waaseyaa\CLI\Command\Config\ConfigStatusCommand` | FR-034..FR-036 |
| `config:validate` | `Waaseyaa\CLI\Command\Config\ConfigValidateCommand` | FR-037..FR-040 |
| `config:reset <entity-type>.<id> [--yes]` | `Waaseyaa\CLI\Command\Config\ConfigResetCommand` | FR-041..FR-043 |

---

## 2. Architecture (one diagram)

```
┌──────────────────────────┐                          ┌──────────────────────────┐
│      Active store        │                          │       Sync store         │
│  (SQL — runtime config)  │                          │  (filesystem — YAML)     │
│  ConfigEntityBase rows   │                          │  storage/config-sync/   │
└──────────┬───────────────┘                          └─────────┬────────────────┘
           │                                                    │
           │       config:export ────────────────────────────►  │
           │  ◄─── config:import (DAG-ordered, per-entity tx)   │
           │  ◄─── config:reset <id>                            │
           │                                                    │
           ├─────────►  ConfigDiffer / ConfigStatusReporter ◄───┤
           │           (read-only inspection)                   │
           │                                                    │
           └─────────►  ConfigSyncValidator  ◄──────────────────┘
                       (FieldDefinition::validators())
```

**Boot-time gates:**
- `BackendRestrictionEnforcer` scans every config-entity type at boot; refuses non-`sql-blob` / non-`sql-column` declarations with `InvalidConfigBackendException`.
- `ConfigCommand::assertNoCollision()` runs during CLI registration; an app command claiming any reserved sub-verb (`export`, `import`, `diff`, `status`, `validate`, `reset`) fails with `ConfigCommandCollisionException`.

---

## 3. Sync-store file format (canonical)

### 3.1 Filename

`<entity_type>.<entity_id>.yml` — lowercase ASCII with `_` separators. Files outside this convention are ignored by `config:import` (warn-and-skip; not error).

Examples: `taxonomy_vocabulary.community_categories.yml`, `role.coordinator.yml`.

### 3.2 `_meta` block (leading)

```yaml
_meta:
  dependencies:
    - role.admin
    - taxonomy_vocabulary.parent_thing
  entity_type: taxonomy_vocabulary
  langcode: en
  uuid: 0193abc...
```

- `dependencies` — array of `<entity_type>.<entity_id>` strings consumed by the DAG.
- `entity_type` — must match the filename prefix; mismatch raises `ConfigSerializationException`.
- `langcode` — language code; default `en` for non-translatable config.
- `uuid` — stable across renames. When a sync file is renamed (entity id changes) but the uuid is preserved, the importer treats it as a rename, not a create+delete.

Keys within `_meta` are sorted alphabetically. New optional `_meta` keys may be added without deprecation; renames or removals follow charter §4.

### 3.3 Field values

The remaining top-level keys are entity field values, sorted alphabetically. The serializer maps `FieldDefinition` types to YAML representations:

| `FieldDefinition` type | YAML representation |
|---|---|
| `string` | scalar string |
| `int` | scalar int |
| `bool` | scalar bool |
| `datetime` | ISO 8601 string |
| `json` | mapping or sequence (native YAML structure) |
| `text` | scalar string (block scalar where appropriate) |
| `uuid` | scalar string |
| `entity_reference` | `<entity_type>.<entity_id>` string |
| `field_list` | sequence of scalars |

The table itself is stable; new field types extend additively. Removals / renames follow the deprecation cycle.

### 3.4 Determinism rules

- Alphabetical key ordering within `_meta` and within the top-level field group.
- Multi-line strings use YAML block scalars (`|` or `>`) when they contain newlines.
- Empty arrays/maps serialize as `[]` / `{}` (flow style) to reduce visual noise.
- The `_meta` block always appears first.

These rules are load-bearing — operator and automation diffs depend on them,
independently of the selected VCS or artifact service. They follow charter §4.

---

## 4. Dependency graph

`ConfigDependencyInterface::configDependencies(): array` returns `<entity_type>.<entity_id>` strings. `ConfigEntityBase` ships a default no-op implementation that returns `[]`; entity types override.

At import time `DependencyResolver` (internal):

1. Parses every file's `_meta.dependencies`.
2. Builds a directed graph: each file is a node; each dependency declaration is an edge from dependency → dependent.
3. Computes topological order; that becomes the import order.
4. Cycles raise `ConfigDependencyCycleException` carrying the full cycle path (DFS-based detection, hop-limited error message via `MESSAGE_HOP_LIMIT`).
5. Missing dependencies (entry references nonexistent config in both stores) raise `ConfigDependencyMissingException` carrying the missing id.

`--no-dependency-check` bypasses cycle and missing-dep detection for emergency recovery. Bypass is logged at `warning` level to `config.audit`.

Cross-package dependencies are supported transparently — the graph is global within the app's config-entity registry.

---

## 5. CLI command behaviours

(Normative reference; see [`docs/cookbook/config-sync.md`](../cookbook/config-sync.md) for operator walkthroughs.)

### 5.1 `config:export [--diff] [--dry-run]`

Walks the config-entity registry. For each entity, serialises to YAML per §3 and writes under `config.sync_path` (default `storage/config-sync/`). Output ends with `X created, Y updated, Z unchanged.`

- `--diff` writes only files whose content differs.
- `--dry-run` computes writes without filesystem effects.
- Exit code 0 on success; 1 on any serialisation error.

### 5.2 `config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]`

1. Validates every sync file via `ConfigSyncValidator`; schema and manifest
   failures cannot be bypassed by `--no-dependency-check`.
2. Builds the DAG (§4).
3. Executes mandatory schema/manifest/compatibility/drift preflight before any
   apply or orphan deletion.
4. In production, CFG-02 stages the complete generation and activates it with
   compare-and-swap only after CFG-03 authorizes the manifest. Until those
   bindings exist, import refuses without mutation.
5. Per-entity diffs are displayed when interactive (TTY); suppressed in CI.
6. Orphans default to warn-only; `--delete-orphans` makes deletion part of the
   staged generation.
7. Explicit testing adapters may retain the original per-entity apply/error
   semantics to exercise command behavior, but they are not production
   authority or recovery evidence.

### 5.3 `config:diff [<entity-type>.<id>]`

Unified diff of active vs sync YAML (serialised identically on both sides to avoid whitespace noise). UUID-tracked rename detection: a `_meta.uuid` match with a different id is rendered as a rename. Exit 0 if no differences, 1 otherwise.

### 5.4 `config:status [--format=plain|json]`

Counts: in-sync / drift / sync-only / active-only. Per-entity table when interactive and total < 50. Read-only (no side effects on either store).

### 5.5 `config:validate`

Parses every sync file. Instantiates the would-be entity without persisting. Runs `FieldDefinition::validators()` over each field. Per-entity errors with per-field detail. Exit 0 if all valid, 1 otherwise. Designed to run as a CI gate before `config:import`.

### 5.6 `config:reset <entity-type>.<id> [--yes]`

Loads and validates the sync entity, then requests a guarded generation change.
Production refuses until CFG-02/03 provide activation and preflight. Confirmation
is required unless `--yes`; authorized outcomes log actor, before/after digest,
authority, and generation identity to `config.audit`.

---

## 6. Audit log channel

Channel constant: `Waaseyaa\Config\Audit\ConfigAuditChannel::CHANNEL` = `'config.audit'`. Event payload: `Waaseyaa\Config\Audit\ConfigAuditEvent`.

The channel receives:

- One event per authorized `config:import` outcome, generation-bound in production.
- One event per `config:export` write (per file created/updated).
- One event per authorized `config:reset` outcome.
- A `warning`-level event per `--no-dependency-check` bypass.
- A `warning`-level event per detected orphan when `config:import` runs without `--delete-orphans`.

The channel name is on stable surface (charter §4.4); operators wire `config.audit` into their log shipping with confidence.

---

## 7. Backend restriction

Config entities are restricted to `sql-blob` and `sql-column` backends (`Waaseyaa\Config\Backend\BackendRestrictionEnforcer::ALLOWED_BACKEND_IDS`). Attempts to declare `vector` or `remote` (or any future non-SQL backend) fail at boot with `InvalidConfigBackendException`, which carries:

- The offending entity-type id.
- The disallowed backend id.
- The FQCN of the declaring code.

Reason: config entities require deterministic, queryable serialization for CMI export/import to work. Vector or remote backends would either lose fidelity (vector quantisation) or fail to participate in transactional imports (remote).

---

## 8. CLI namespace reservation

`Waaseyaa\CLI\Command\Config\ConfigCommand` exposes three constants for boot-time collision detection:

- `RESERVED_VERBS` — the six short verbs (`export`, `import`, `diff`, `status`, `validate`, `reset`).
- `RESERVED_FULL_VERBS` — the qualified forms (`config:export`, etc.).
- `RESERVED_FQCNS` — the six concrete command FQCNs.

If an app or extension registers a command whose name matches any reserved sub-verb but whose class is NOT in `RESERVED_FQCNS`, registration fails with `ConfigCommandCollisionException`. The exception names the conflicting command class so operators can locate the offending package quickly.

Apps and extensions may freely register `config:<custom>` verbs that are NOT in the reserved set (e.g. `config:audit-export`, `config:snapshot`). They own those.

---

## 9. Environment boundary and secret-reference pattern (load-bearing)

CMI does **not** ship runtime deployable-value overrides (Drupal
`$config['x']['y']` style). Feature flags, endpoints, workflows, and similar
behavior belong to the active generation and its reviewed sync artifact.
Bootstrap environment inputs are limited to authority selection—environment
identity, database location, sync path—and opaque secret references.

Credential-bearing configuration uses closed `SecretReference` fields that
bind provider, identifier, expected secret class, and versioned purpose.
`DeployableConfigurationPolicy` rejects raw secret-shaped fields and
bootstrap-owned names in both sync files and database generations. CFG-04
resolves a reference through the frozen kernel registry into a guarded handle;
only an exact registered consumer may use the bytes, and only for one operation
boundary. Secret bytes never enter active configuration, YAML, manifests, or
evidence. Legacy environment-variable-name fields are migration input only and
become central-provider references without reading the environment.

See [`docs/cookbook/config-sync.md`](../cookbook/config-sync.md) §10 for the
operator mapping. If two environments intentionally differ in deployable
behavior, promote two explicit reviewed generations; do not hide the difference
in an environment overlay.

---

## 10. Stability tier map (matches charter §5.5)

| Symbol | Tier | Notes |
|---|---|---|
| `ConfigDependencyInterface` | stable | Charter §5.5; consumers safely implement. |
| `ConfigSyncFile`, `ConfigSyncSerializer`, `ConfigSyncDeserializer`, `ConfigSyncRepository` | stable | Format I/O. |
| `ConfigSyncFileSourceInterface`, `ConfigImportApplyHookInterface` | stable | Extension points. |
| `ConfigExporter`, `ConfigImporter`, `ConfigDiffer`, `ConfigStatusReporter`, `ConfigSyncValidator`, `ConfigResetter` | stable | Orchestrators. |
| `ConfigSyncManifestEntry` | stable | Manifest value object. |
| Sync-store YAML format (`_meta` shape, key sort order, filename convention) | stable | Load-bearing strings (charter §4 cycle for changes). |
| `config.sync_path` config key | stable | Default `storage/config-sync/`. |
| `ConfigAuditChannel`, `ConfigAuditEvent`, channel constant `config.audit` | stable | Charter §4.4 amendment. |
| `BackendRestrictionEnforcer`, `InvalidConfigBackendException` | stable | Boot-time gate. |
| `ConfigCommand` abstract base + 6 concrete `Config*Command` classes | stable | Six reserved sub-verbs. |
| `ConfigDependencyCycleException`, `ConfigDependencyMissingException`, `ConfigSerializationException`, `ConfigImportFailedException`, `ConfigCommandCollisionException` | stable | Error model. |
| `DependencyGraph`, `DependencyResolver` | internal | Topological-sort implementation; exceptions are the stable contract. |
| `FieldValueMapper` | internal | Per-field-type YAML emitter; the type→YAML table in §3.3 is the stable contract. |
| `DiffResult`, `StatusEntry`, `StatusReport`, `FieldViolation`, `ConfigExportFileResult`, `ConfigExportResult`, `ConfigImportEntryResult`, `ConfigImportResult`, `ConfigValidateEntry`, `ConfigValidateResult` | internal | Operator output is the contract; PHP shape is refactorable. |

---

## 11. Cross-references

- ADR 018 — governing decision (Accepted 2026-05-11).
- ADR 010 — multi-backend field storage (origin of the `sql-blob` / `sql-column` constraint).
- ADR 013 — form abstraction (origin of `FieldDefinition::validators()`).
- Charter [`stability-charter.md`](stability-charter.md) §5.5 (stable surface), §3.2 criterion 9 (CMI gap → SATISFIED), §4 (deprecation cycle), §4.4 (log channel registry), §11 (future-ADR doors including per-env overrides).
- Cookbook [`docs/cookbook/config-sync.md`](../cookbook/config-sync.md) — operator walkthrough.
- Conventions [`docs/conventions/cmi-sync-format.md`](../conventions/cmi-sync-format.md) — sync-store format invariants.
- Upgrade guide entry for the introducing alpha train — [`docs/upgrades/`](../upgrades/).
- Mission archive [`kitty-specs/config-management-v1-01KRCDEC/`](../../kitty-specs/config-management-v1-01KRCDEC/) — original spec, plan, work packages.
- Mission spec history [`config-management-v1.md`](config-management-v1.md) — pre-implementation working document (preserved for context).

---

## 12. Mission post-mortem

Mission `config-management-v1-01KRCDEC` (M-003, 2026-05-16) shipped FR-001..FR-061 across 11 work packages. Lane-a sequenced WP01 → WP02 → WP03/04/05/06 → WP07 → WP09 → WP10 → WP11; lane-b ran WP08 in parallel. Highlights:

- Zero breaking changes — existing `ConfigEntityBase` consumers untouched.
- Beta-gate criterion 9 (charter §3.2) — Drupal-comparison-matrix §3.5 (CMI) — flipped from `unshipped` to **SATISFIED**.
- Six reserved CLI sub-verbs landed with boot-fail collision detection (WP09), preventing future namespace squatting.
- Backend restriction landed independently on lane-b (WP08), unblocking the `sql-blob` / `sql-column` invariant claim that ADR 018 made on entity-storage-v2's behalf.
- Minoo round-trip (WP10) validates the substrate end-to-end: export → modify-in-sync → import → diff = 0.

Acceptance criteria §9 of [`config-management-v1.md`](config-management-v1.md) are satisfied; mission complete.
