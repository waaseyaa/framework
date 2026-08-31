# Schema evolution v2.0 — SchemaDiff engine (design)

**Status:** Design draft (Phase 1 — WP01 / [#522](https://github.com/waaseyaa/framework/issues/522))  
**Epic:** [#529](https://github.com/waaseyaa/framework/issues/529) Schema Evolution v2.0  
**Mission (authoritative checkpoint):** `kitty-specs/529-schema-evolution-v2/spec.md`  
**Architecture rule:** Waaseyaa owns diffing rules, migration generation, and safety gates; **applications** own timing and operational rollout of generated schema changes.

This document is the **canonical design contract** for the SchemaDiff pivot. It does not prescribe implementation filenames or package layout; those follow in [#521](https://github.com/waaseyaa/framework/issues/521). Until implementation lands, **no behavior change** is implied for production installs.

---

## 1. Goals

1. Represent structural schema change as **immutable, typed, serializable data** (SchemaDiff), not as ad-hoc imperative SQL in application code.
2. Compile diffs to **executable plans** (SQLite first), with a **deterministic** ordering story and **explicit** safety gates for destructive operations.
3. Extend the **migration ledger** so applied work is **auditable** (checksums, optional diff hashes) and **verifiable** against live databases.
4. Support **execution modes**: apply, dry-run, verify, and bounded replay — without “run once and hope” semantics.
5. Evolve **Composer manifest** discovery toward **declarative, ordered** migration registration while **coexisting** with the [#1286](https://github.com/waaseyaa/framework/issues/1286) string-path convention until a deprecation window closes.
6. **Coexist** with the existing `Migration` / `Migrator` / `MigrationLoader` stack until a deliberate deprecation phase; no big-bang removal.

---

## 2. Related specifications

| Document | Relevance |
|----------|-----------|
| [field/column-derivation.md](./field/column-derivation.md) | Column specs for field types → storage columns; compiler and diff producers **must** stay aligned with this contract. |
| [infrastructure.md](./infrastructure.md) | Current `Migrator`, `MigrationRepository`, `MigrationLoader`, package-declared migrations (`extra.waaseyaa.migrations`). |
| [bundle-scoped-storage.md](./bundle-scoped-storage.md) | `{base}__{bundle}` subtables; entity-level vs bundle-level diff scope. |
| [workflow.md](./workflow.md) | Semantic milestone v2.0; PR traceability to #521 / #522 / #518 / #529. |

---

## 3. SchemaDiff data model

### 3.1 Design principles

- **Pure data at the core boundary:** SchemaDiff and atomic operations contain **no SQL strings**. SQL appears only at the **compiler output** seam (see §6).
- **PHP 8.4-native:** Prefer **`readonly` classes**, **constructor-promoted properties**, **`enum`-backed** discriminated operation kinds where it improves exhaustiveness and serialization contracts.
- **Immutability:** Every operation type is immutable after construction; “chaining” produces new instances or new `CompositeDiff` containers.
- **Serialization:** Types SHOULD be JSON-serializable (for fixtures, CI, and checksum canonicalization) using a **stable** field order and normalized encoding (ADR to fix exact JSON rules).
- **Equality / hashing:** Two diffs that represent the same intent MUST compare equal under a defined canonicalization and produce the same **checksum** input (§8).

### 3.2 Column and index specs (shared vocabulary)

Column specifications at the diff boundary SHOULD reuse the same **semantic** shape as storage today: `type`, optional `length`, `not null`, `default`, etc., aligned with [`column-derivation.md`](./field/column-derivation.md) and `SqlSchemaHandler::deriveColumnSpec()` outcomes so there is a single conceptual mapping from **field definition** → **column spec** → **compiler**.

**Open (human):** Whether the diff layer references `FieldDefinitionInterface` shapes, a dedicated `ColumnSpec` DTO, or a normalized array — pick one and document in ADR before Phase 2.

### 3.3 Atomic operations (minimum set)

Each operation is a **named, readonly** type (exact class names TBD in implementation). The list below is the **minimum** supported set for v2; extensions require ADR.

| Operation | Fields (conceptual) | Notes |
|-----------|---------------------|--------|
| **AddColumn** | `table`, `column`, `spec` | Additive; default gate: allowed. |
| **AlterColumn** | `table`, `column`, `new_spec` | May be restricted on SQLite (rewrite-table semantics); policy may require explicit migration split. |
| **DropColumn** | `table`, `column` | **Destructive**; blocked by default or requires explicit danger flag at plan level. |
| **AddIndex** | `table`, `columns[]`, optional `name`, `unique` | Composite columns allowed. |
| **DropIndex** | `table`, `name` or `columns[]` identity | Resolver defines how anonymous indexes are addressed on SQLite. |
| **AddForeignKey** | `table`, constraint definition (referencing table/columns, ON DELETE/UPDATE) | SQLite vs MySQL/Postgres capability matrix in ADR; unsupported combos must fail at compile time with a clear code. |
| **DropForeignKey** | `table`, constraint `name` | Same as above. |
| **RenameColumn** | `table`, `from`, `to` | Never infer rename from “drop + add” without explicit op. |
| **RenameTable** | `from`, `to` | Rare for entity types; supported for completeness and tooling. |

### 3.4 Composite and scoped diffs

| Type | Purpose |
|------|---------|
| **CompositeDiff** | Ordered, immutable list of atomic operations. **Transaction boundary:** one `CompositeDiff` applied in a **single** DB transaction unless an ADR splits unsafe bundles (e.g. SQLite table rebuild). |
| **EntityLevelDiff** | Binds to one `entity_type_id` (logical): targets **base table** `entity_type_id` and any **known** `{base}__{bundle}` subtables per [bundle-scoped-storage.md](./bundle-scoped-storage.md). Producers MAY emit only `CompositeDiff` internally; the entity wrapper carries metadata for verify and UI. |
| **BundleLevelDiff** | Subset of entity scope: exactly one bundle’s subtable (and optional base-table touches if required by FK rules — document if forbidden). |

**Ordering:** Atomic ops inside `CompositeDiff` MUST be in **apply order** (compiler may not reorder without explicit pass). Topological dependencies between ops (e.g. add column before index on that column) are the **producer’s** responsibility; the compiler validates and fails fast on illegal sequences.

---

## 4. MigrationInterface v2 (authoring contract)

### 4.1 Interface shape (design-level)

- **`MigrationInterfaceV2`** (name illustrative): implemented by **`readonly` concrete classes** (final where possible).
- **No `up()` / `down()`** on this interface. Legacy imperative migrations keep `Migration::up` / `down` until deprecated.
- **Payload:** Each v2 migration holds **either** a single `SchemaDiff` root **or** an immutable `list` of atomic ops / `CompositeDiff` — **no** SQL, **no** `SchemaBuilder` callbacks inside the type.
- **Optional `MigrationPlan`:** Separates **intent** (immutable diff + metadata) from **materialization** (compiled steps for apply vs dry-run). Dry-run consumes the same plan without mutating ledger.

### 4.2 Metadata (required for ledger and ordering)

Each v2 migration value MUST expose (names illustrative):

| Metadata | Purpose |
|----------|---------|
| `migration_id` | Stable string ID (not only Composer display name); used in ledger and verify. |
| `package` | Composer package name (`waaseyaa/…`). |
| `dependencies` | Ordered list of **package names** or **migration_id** strings this entry must run after (extends today’s `$after` semantics conceptually). |

### 4.3 Interop with compilation

- v2 types **do not** execute DB I/O.
- A **compiler service** (Phase 3) accepts `SchemaDiff` + **platform** + optional **connection capabilities** and returns a **`CompiledMigrationPlan`**: ordered steps (e.g. SQL DTOs or DBAL operations) — the only layer where SQL is produced.

---

## 5. Diff → SQL compiler contract (SQLite-first)

### 5.1 Inputs

- **Root:** `SchemaDiff` (or `CompositeDiff`).
- **Platform:** `sqlite` in v1 of compiler; `mysql` / `postgres` later behind explicit platform enum and capability tables.
- **Options:** Strict vs permissive mode; whether to allow destructive ops; max operation count (DoS guard).

### 5.2 Outputs

- **`CompiledMigrationPlan`** (immutable): ordered list of **atomic executable steps**.
- Each step is a **narrow DTO** (e.g. `ExecuteStatement`, `CreateTable`, `AlterTableAddColumn`) — **not** arbitrary strings from callers. Only the compiler constructs these.
- **Determinism:** Same `SchemaDiff` + same platform + same compiler version ⇒ **byte-identical** ordered steps (required for golden tests and `diff_hash`).

### 5.3 Validation rules (compiler gates)

- Reject unknown operation kinds.
- Reject destructive ops when policy forbids (`DropColumn`, `DropForeignKey`, etc.).
- Reject FK definitions SQLite cannot enforce (document matrix).
- Detect illegal ordering (index before column) and fail with structured errors (codes suitable for operator diagnostics).

### 5.4 Alignment with existing storage

- Column type semantics MUST remain consistent with **`deriveColumnSpec()`** / [`column-derivation.md`](./field/column-derivation.md).
- Entity / bundle table naming MUST match [`bundle-scoped-storage.md`](./bundle-scoped-storage.md) and `SqlSchemaHandler` naming rules (`{base}__{bundle}`, separator invariants).

---

## 6. Ledger contract (extensions)

Today (`waaseyaa_migrations`): `migration`, `package`, `batch`, `ran_at` (see [infrastructure.md](./infrastructure.md) § Migrator / MigrationRepository).

### 6.1 New or renamed columns (design intent)

| Field | Type (illustrative) | Purpose |
|-------|---------------------|---------|
| `migration_id` | `VARCHAR(255)` | Stable logical id for v2 (and legacy rows may mirror current `migration` string). **Human decision:** one column vs overload `migration`; ADR must pick to avoid duplicate truth. |
| `checksum` | `VARCHAR(64)` or `BINARY` | Hash of **canonical serialized** diff (or v1 migration source file hash for legacy) at apply time. |
| `diff_hash` | `VARCHAR(64)` nullable | Hash of **compiled plan** (normalized SQL DTO sequence) for verify mode. |
| `applied_at` | timestamp | Same semantics as current `ran_at` OR rename in ADR with migration path. |

### 6.2 Rules

- On **apply**, persist `checksum` (and `diff_hash` if computed) **after** successful transaction commit.
- **Verify** recomputes expected hashes from **registered** definitions + ledger and compares to live introspection snapshot.
- **Replay:** **Production** MUST NOT silently re-apply the same `migration_id` with a **different** `checksum`. **Development** MAY allow `replay` on scratch DB only (explicit CLI flag, documented risk).

### 6.3 Backfill

Existing rows lack `checksum` / `diff_hash`. **ADR:** sentinel value, nullable columns, or one-time backfill script; verify mode defines behavior for “legacy unknown.”

---

## 7. Execution model

### 7.1 Apply

- Extends current **`Migrator::run`** semantics: a **merged ordered graph** of **legacy** `Migration` instances and **v2** compiled plans (exact merge algorithm ADR — e.g. all legacy for a batch, then v2, or interleaved by migration_id sort + dependency edges only if safe).
- One **batch number** per successful apply batch (unchanged conceptually).

### 7.2 Dry-run

- Compiles `SchemaDiff` → `CompiledMigrationPlan` **without** committing and **without** ledger writes.
- Emits **human-readable** summary and **machine-readable** JSON (schema TBD) for CI and operators.

### 7.3 Verify

- Inputs: live connection + **expected** schema fingerprint (from entity types + field registry + ledger history, per ADR).
- Output: pass/fail + **diagnostic codes** (align with operator diagnostics conventions where applicable).
- **Production output** MUST NOT leak raw filesystem paths in error strings (see mission Phase 5).

### 7.4 Replay

- **Dev:** optional command to drop/recreate scratch DB and re-run full chain (explicit).
- **Prod:** no implicit replay; any repair path is operator-driven and documented.

### 7.5 CLI surface

- Prefer **flags on existing** `migrate` / `migrate:status` (`--dry-run`, `--verify`) unless an ADR introduces new command names — avoid duplicate surfaces.

---

## 8. Composer manifest evolution

### 8.1 Today (#1286)

```json
"extra": {
  "waaseyaa": {
    "migrations": "migrations"
  }
}
```

`PackageManifestCompiler` records `packageName => string path`. `MigrationLoader` resolves the directory and loads `*.php` files in **lexicographic** order.

### 8.2 Target (Phase 6)

Ordered **array** of entries (namespace and/or path strings):

```json
"extra": {
  "waaseyaa": {
    "migrations": [
      "Waaseyaa\\Groups\\Migrations\\v1",
      "../patches/schema-v2"
    ]
  }
}
```

**Semantics:**

- **Array order** is the **authoritative** apply order within that package (before cross-package `$after` / dependency merge).
- **Entries:** FQCN namespace roots for class-discovered v2 migrations **and/or** filesystem path strings during transition — exact discovery rules in ADR (no class-string **registries** stored as opaque JSON blobs without validation).

### 8.3 Coexistence rule

- **String** value remains valid indefinitely until deprecation is announced; **array** form is additive.
- `PackageManifest` typing must represent `string|list<string>` without losing order (PHP `array` list preservation).

### 8.4 Root applications

The Composer root package participates in the same manifest contract as an
installed package. Its `name` is the default migration package identity; declaring
`extra.waaseyaa.migrations` without a non-empty root package name is invalid and
fails closed. Relative path entries resolve from the project root. The legacy
automatic `<projectRoot>/migrations` scan remains available only when that same
directory was not already declared. A root declaration resolving to that canonical
directory (including path aliases) retains the historical `app:*` ledger IDs;
other root paths retain Composer ownership. Upgrading discovery never makes an
already-applied `app:*` migration pending under a new identity.

Every stock migration lifecycle surface consumes the resulting legacy and V2
catalogues: dry-run, apply, status, verify, `db:init`, and `install:init`.
Stock apply compositions pass canonical `RuntimePolicy` and a logger to
`Migrator`: checksum drift warns and skips in development-like environments,
and throws in production-like environments without changing the applied row.

---

## 9. Coexistence with the current migration engine

| Layer | Rule |
|-------|------|
| **Legacy `Migration`** | Continues to run unchanged; `up`/`down` + `SchemaBuilder` remain supported. |
| **v2 migrations** | Run through the same **ledger** and **batch** concepts; different code path in `Migrator` (or adapter) once implemented. |
| **Ordering** | Legacy package topological sort **extends** to include v2 nodes; **edges** defined by `dependencies` / `$after` must not introduce cycles (fail boot or fail migrate with clear error). |
| **`ensureTable()`** | Remains the kernel path for **base** entity tables; v2 does **not** replace it in Phase 1–3. v2 handles **additive and evolved** schema beyond that contract per epic acceptance. |
| **Producers** | Field-definition diff engine (future) may emit `SchemaDiff` that compile to SQL **or** to calls that align with `SqlSchemaHandler` where appropriate — convergence is incremental. |

---

### 9.1 Fresh-install semantics for entity evolution (#2701)

The canonical entity-schema materializer remains authoritative for entity
base-table existence. A v2 migration neither creates that table itself nor
performs database I/O; the runtime reconciles lifecycle state on its behalf,
inside the node's transaction.

For each pending v2 node, in order:

1. The authored plan is compiled **in full** first, so `PlanPolicy` refusals and
   SQLite capability refusals fire before anything touches the database. The
   resulting compiled plan is what supplies `diff_hash`, whatever executes below.
2. Any *registered entity base table* the plan targets that is absent is
   materialized through the canonical materializer, injected by the composition
   site as an `EntityTableMaterializerInterface`. Only targeted tables, never a
   full synchronization, and never an existing table. Tables the materializer does
   not own stay absent so the migration fails closed on real SQL.
3. Each authored operation is classified against live state. An operation the
   schema still needs is outstanding; one the schema **exactly** satisfies is
   dropped from the executed set; one that exists in a different shape raises
   `IncompatibleSchemaStateException`. Only `AddColumn` and `AddIndex` can be
   exactly satisfied — renames and drops never qualify, because an absent source
   is ambiguous and nothing may be skipped on the strength of a name.
4. Outstanding operations execute. A node whose operations were all already
   satisfied issues no SQL.

Unrestricted schema synchronization is **not** run ahead of migrations: doing so
would let it act before a migration's intended order and validation.

**What exactness compares.** The authored vocabulary for a column is
`ColumnSpec` — type, nullability, default, length — and the compiler renders
exactly `<type-sql> [NOT NULL] [DEFAULT <literal>]`. A live column carrying
anything else is not the column the operation declares, and SQLite's
`column-constraint` and `table-constraint` productions are finite, so "anything
else" is a closed list rather than an open-ended one. The runtime decides all of
it: type affinity, nullability and default; **primary-key membership** at any
position in a composite key; **generated or hidden** columns; **`UNIQUE`
constraint** membership; foreign-key **source** membership; the effective
**`COLLATE`**; the applied **`NOT NULL` conflict policy**; and whether any
**`CHECK`** on the table can read the column, including through generated-column
indirection. Everything with a catalogue is read from it — `PRAGMA table_xinfo`,
`index_list` + `index_info`, `foreign_key_list` — and only the last three, which
have no pragma, are read from the stored definition. Anything the reader cannot
interpret is a refusal, never an assumption. `already_satisfied` is therefore a
positive statement about a hand-written table, not merely the absence of a
detected mismatch.

Divergences fail closed under `[S1-DB110]`, naming what was found. Primary-key
membership is checked before nullability because SQLite reports an
`INTEGER PRIMARY KEY` rowid alias as `notnull = 0` while it cannot hold NULL,
which makes the nullability reading untrustworthy for exactly that column.

Three shapes are deliberately **accepted**, because each is a genuine equivalent:
an independently created index — `origin = c` in `PRAGMA index_list`, including a
`UNIQUE` one, since an index is a separate schema object authored by `AddIndex`;
being the *target* of another table's foreign key; and explicit
`ON CONFLICT ABORT` or `COLLATE BINARY`, which restate the compiler's own
defaults. See `docs/specs/infrastructure.md` for the decision table and
`docs/change-records/FW-2701.md` for the supported grammar and its limitations.

**Backend dependence is the reason this contract exists.** On the framework
default `sql-blob`, synchronization emits entity-key columns plus `_data` and
never a per-field column, in either lifecycle — so a v2 `AddColumn` is genuinely
outstanding on a fresh install and on an upgrade alike. On `sql-column` it emits
the field column at creation and adds it to an existing table, so the same node is
already satisfied once the table is materialized.

**Ledger.** `checksum` and `diff_hash` are functions of the authored plan alone
and are therefore identical across lifecycles; replay guarding and verification
are unchanged. The nullable `apply_mode` column records `applied` or
`already_satisfied` as audit evidence only, and is never consulted by either.


## 10. Non-goals (explicit)

The following are **out of scope** for initial delivery unless the epic is explicitly expanded:

1. **Timestamp-prefixed** migration filenames as the **source of truth** for ordering (lexicographic sort is not a product feature; ordered manifest + deps are).
2. **Folder scanning** as the **only** discovery mechanism long-term — Phase 6 replaces with declarative manifest; until then, #1286 directory loading remains for string paths.
3. **Imperative SQL** or string-concatenated DDL **inside** SchemaDiff or `MigrationInterfaceV2` implementations from integrators — only the **compiler** emits SQL DTOs.
4. **Global mutable state** during diff or compile (no static caches of connection or config).
5. **Class-string registries** without validation (no unchecked `::class` strings from untrusted JSON).
6. **Admin UI** for migrations.
7. **Automatic apply** on HTTP kernel boot.
8. **Replacing `ensureTable()`** in one shot for all entity types.

---

## 11. Safety gates (policy layer)

Design-level requirements (implementation in [#521](https://github.com/waaseyaa/framework/issues/521)):

- **Classify** operations: additive / ambiguous / destructive.
- **Default:** additive allowed; destructive **blocked** unless an explicit operator flag or signed “danger accepted” channel (ADR).
- **Rename-like** patterns (drop + add with compatible types) MUST either be rejected or normalized to **RenameColumn** — no silent coalescing without human-visible warning.

---

## 12. Test strategy ([#518](https://github.com/waaseyaa/framework/issues/518))

### 12.1 Unit

- **Diff algebra:** equality, hashing, composite ordering, immutability.
- **Compiler golden files:** `SchemaDiff` → SQLite `CompiledMigrationPlan` snapshots (stable SQL or stable DTO JSON); version golden files when compiler output intentionally changes.
- **Policy:** destructive ops blocked unless flag set.

### 12.2 Idempotency

- Applying the **same** compiled plan twice: second run is **no-op** at ledger + DB level (or explicit “already applied” without error — pick one in ADR).

### 12.3 Integration

- SQLite file or `:memory:`: register entity type change → produce diff → compile → apply → introspect schema.
- **Bundle subtable** scenarios per [bundle-scoped-storage.md](./bundle-scoped-storage.md).

### 12.4 Cross-database

- **CI default:** SQLite only.
- **MySQL / Postgres:** optional matrix job when compiler gains platforms; same `SchemaDiff` may produce different compiled plans — separate golden files per platform if needed.

### 12.5 Round-trip (limited)

- Where feasible: introspected schema → diff against expected model. **Non-goal:** perfect round-trip for all SQLite DDL edge cases in v1.

---

## 13. Acceptance mapping (#529 epic)

From GitHub epic: child issues **#522** (this document + refinements), **#521** (implementation), **#518** (tests) complete; supported changes produce **deterministic** output; **unsafe** changes blocked or surfaced with operator-facing detail.

---

## 14. Open questions and ambiguities (human decisions before Phase 2)

1. **`migration_id` vs current `migration` column:** Rename vs new column vs overload string format — affects migrations table migration and verify queries.
2. **Checksum algorithm:** SHA-256 vs BLAKE3 vs truncated hash; canonical JSON rules for diff serialization.
3. **`MigrationPlan` vs single `CompositeDiff`:** Whether the plan type is always a composite root or a tagged union including “empty plan.”
4. **Merge algorithm** for legacy + v2 in one batch: strict “legacy first” vs interleaved by explicit `(package, migration_id)` total order — affects reproducibility.
5. **SQLite `AlterColumn`:** Support level (full rewrite vs subset); which alter shapes v1 refuses and surfaces as “manual intervention required.”
6. **FK on SQLite:** Which referential actions are supported in v1 compiler vs explicitly unsupported.
7. **EntityLevelDiff producer:** Lives in `entity-storage` vs `foundation` vs new package — layer graph must be respected ([CLAUDE.md](../CLAUDE.md) layer table).
8. **CLI UX:** Single `migrate --dry-run` for both legacy and v2 or separate subcommand — product preference.
9. **Deprecation calendar** for directory-only `migrations` string paths — communicate in CHANGELOG and [workflow.md](./workflow.md).

**Resolutions for each item are ratified in §15 (locked 2026-05-02).** §14 remains the question list for traceability; binding decisions are §15.

---

## 15. Ratified Resolutions (Q1–Q9)

**Status: RATIFIED — 2026-05-02.** This section locks the architectural decisions for Q1–Q9. Each entry below is a **finalized resolution** binding on Phase 2+ implementation. Changes to any ratified resolution require a new ADR and an explicit overturn note in this document; they may not be relitigated implicitly during WP execution.

### Q1 — `migration_id` vs current `migration` column

| Option | Description |
|--------|-------------|
| **A** | Add a parallel `migration_id` column; keep `migration` as display-only. |
| **B** | Retain **`migration` as the sole canonical ledger key** (same uniqueness semantics as today); v2 entries use the same stable string pattern (`{package}:{logical_slug}`). |

**Ratified resolution (2026-05-02):** **B.** Avoid a second identifier column and a wide data migration on every install. v2 registrations MUST supply a **stable, unique** `migration` string (document format in ADR — e.g. `{package}:v2:{kebab_or_uuid}`). New columns **`checksum`** and **`diff_hash`** (nullable) extend the row; **`ran_at`** semantics unchanged (§6 may still call it `applied_at` in prose only).

### Q2 — Checksum algorithm and canonical serialization

| Option | Description |
|--------|-------------|
| **A** | SHA-256 over UTF-8 bytes of canonical JSON. |
| **B** | BLAKE3 or truncated hash for shorter rows. |

**Ratified resolution (2026-05-02):** **A for both `checksum` and `diff_hash`.** SHA-256 is ubiquitous in PHP (`hash('sha256', …)`), tooling, and CI. **Canonical JSON rules** (ADR): UTF-8; object keys **sorted lexicographically**; arrays preserve order; integers vs floats disallowed in ambiguous forms; `null` only where schema allows. `checksum` hashes the **canonical SchemaDiff / plan intent**; `diff_hash` hashes the **canonical serialized compiled plan** (DTO list) once Phase 3 exists.

### Q3 — `MigrationPlan` vs `CompositeDiff` (empty plan)

| Option | Description |
|--------|-------------|
| **A** | Tagged union: `Plan \| Empty`. |
| **B** | **Single composite root:** `CompositeDiff` with **zero operations** is the canonical empty plan. |

**Ratified resolution (2026-05-02):** **B.** Avoid parallel “empty” types. **`MigrationPlan`** (readonly DTO) wraps **metadata** (`migration` ledger key, `package`, `dependencies`) plus a **root `CompositeDiff`** (possibly empty). No `up`/`down`; “no work” is `CompositeDiff([])` with deterministic JSON `{"ops":[]}`.

### Q4 — Merge algorithm (legacy `Migration` + v2 in one graph)

| Option | Description |
|--------|-------------|
| **A** | Always run all legacy migrations in a batch before any v2 plan. |
| **B** | **Single directed acyclic graph:** nodes = all pending units (legacy + v2); edges = `$after` / `dependencies`; topological sort; **tie-break** `(package ASC, migration ASC)`. |

**Ratified resolution (2026-05-02):** **B.** Deterministic, declarative, and avoids hidden “legacy first” coupling. If a product needs ordering across kinds, authors declare **`$after` / `dependencies`** explicitly. Document that **cross-kind edges are allowed** when safe (e.g. v2 after a legacy table-creating migration).

### Q5 — SQLite `AlterColumn` support (v1 compiler)

| Option | Description |
|--------|-------------|
| **A** | Attempt full SQLite table rebuild for arbitrary alters. |
| **B** | **v1: reject `AlterColumn` at compile time** for SQLite with a stable diagnostic code; additive-only path until rebuild strategy exists. |

**Ratified resolution (2026-05-02):** **B.** Keeps the compiler honest and predictable; aligns with “unsafe changes blocked or surfaced explicitly.” Broader `AlterColumn` support becomes a **later ADR** with table-rebuild semantics and tests.

### Q6 — Foreign keys on SQLite (v1 compiler)

| Option | Description |
|--------|-------------|
| **A** | Emit `REFERENCES` clauses where SQLite accepts them; document limitations. |
| **B** | **v1: reject `AddForeignKey` and `DropForeignKey`** at compile time for SQLite with `FOREIGN_KEY_UNSUPPORTED_SQLITE_V1`. |

**Ratified resolution (2026-05-02):** **B.** SQLite FK behavior and `ALTER TABLE` interactions are easy to get wrong; v1 focuses on **structural columns and indexes** Waaseyaa already models well. MySQL/PostgreSQL compilers (future) implement FK ops with a separate capability matrix ADR.

### Q7 — Where `EntityLevelDiff` is produced (package / layer)

| Option | Description |
|--------|-------------|
| **A** | New small package for diff types only. |
| **B** | **Atomic `SchemaDiff` / `CompositeDiff` value types in `waaseyaa/foundation`** (no imports from entity layers); **factory/builder that constructs entity-scoped composites in `waaseyaa/entity-storage`** using `EntityTypeInterface` + `FieldDefinitionRegistryInterface`. |

**Ratified resolution (2026-05-02):** **B.** Respects the layer graph: foundation stays free of L1+ domain imports; entity-storage already owns `SqlSchemaHandler` / table naming and can emit **pure DTO graphs** upward. **`EntityLevelDiff`** in the spec remains a **semantic wrapper** (metadata + child `CompositeDiff`), implemented as a readonly type **living next to factories** in entity-storage or as a named pattern in docs without forcing a cycle.

### Q8 — CLI surface (`--dry-run` / `--verify`)

| Option | Description |
|--------|-------------|
| **A** | New top-level commands only (`migrate:plan`, `schema:verify`). |
| **B** | **Extend existing `migrate`** with `--dry-run`; add **`--verify`** on `migrate` (or document pairing with `schema:check` if verify belongs there — pick one surface). |

**Ratified resolution (2026-05-02):** **B with a single verify entry point:** **`bin/waaseyaa migrate --dry-run`** compiles and prints pending legacy + v2 work without writes; **`bin/waaseyaa migrate --verify`** runs the verify pass described in §7.3 (no apply). If `schema:check` already covers overlap, **implementation** must consolidate so operators see one story (ADR); design intent is **no new command family** without ADR.

### Q9 — Deprecation calendar for string `migrations` paths

| Option | Description |
|--------|-------------|
| **A** | Set a hard removal release for directory-based discovery. |
| **B** | **Keep string path indefinitely** as an escape hatch; **prefer ordered array** for new packages; deprecate only **“ordering by filename alone”** as undocumented behavior. |

**Ratified resolution (2026-05-02):** **Hybrid (B + soft Phase 6 signal):** **No hard removal date** in this spec — [#1286](https://github.com/waaseyaa/framework/issues/1286) installs depend on string paths. Phase 6 adds **array** form as the **preferred** declarative manifest. **CHANGELOG + [workflow.md](./workflow.md)** announce: new work SHOULD use arrays when order matters; string path remains **supported**. A **hard removal** (if ever) requires a **future major** + ADR and is **out of scope** for Phase 1–2.

---

## Document history

| Date | Change |
|------|--------|
| 2026-04-26 | Initial design draft (Spec Kitty mission 529-schema-evolution-v2, Phase 1 / WP01). |
| 2026-04-26 | §15: proposed resolutions for §14 open questions (review checkpoint). |
| 2026-05-02 | §15: resolutions ratified (Q1–Q9 all accepted as proposed). Binding for Phase 2+ implementation; further changes require ADR. |
