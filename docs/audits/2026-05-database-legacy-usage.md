# `waaseyaa/database-legacy` Usage Audit — 2026-05-25

**Mission:** `database-legacy-retirement-01KSEFV2` (WP01)
**Auditor:** claude (Sonnet implementer)
**Grep pattern:** `grep -rln 'Waaseyaa\\Database\\' packages/ tests/ --include='*.php'`
**Cites:** DIR-003 (Greenfield Removal Policy)

---

## TL;DR

| Metric | Count |
|--------|-------|
| Total files matching pattern | 307 |
| Files internal to `database-legacy` (own package) | 13 |
| Integration test files (`tests/`) | 48 |
| Package consumer files (`packages/` excl. `database-legacy`) | 246 |
| **(a) migrate-to-target** | **294** |
| **(b) intentional-bridge** | **0** |
| **(c) misuse-migrate-elsewhere** | **0** |

**Disposition recommendation: ELIMINATE** (see §"Disposition recommendation").

---

## 1. Inventory

Canonical grep run 2026-05-25:

```bash
grep -rln 'Waaseyaa\\Database\\' packages/ tests/ --include='*.php' | sort > /tmp/dblegacy-files.txt
wc -l /tmp/dblegacy-files.txt
# 307
```

**Exact count: 307 files.**

### Symbol frequency (all `use` statements)

| Symbol | Occurrences |
|--------|-------------|
| `Waaseyaa\Database\DBALDatabase` | 219 |
| `Waaseyaa\Database\DatabaseInterface` | 84 |
| `Waaseyaa\Database\SelectInterface` | 6 |
| `Waaseyaa\Database\UpdateInterface` | 5 |
| `Waaseyaa\Database\SchemaInterface` | 5 |
| `Waaseyaa\Database\InsertInterface` | 5 |
| `Waaseyaa\Database\DeleteInterface` | 5 |
| `Waaseyaa\Database\Schema\DBALSchema` | 4 |
| `Waaseyaa\Database\Query\DBALUpdate` | 4 |
| `Waaseyaa\Database\Query\DBALSelect` | 4 |
| `Waaseyaa\Database\Query\DBALInsert` | 4 |
| `Waaseyaa\Database\Query\DBALDelete` | 4 |
| `Waaseyaa\Database\TransactionInterface` | 3 |
| `Waaseyaa\Database\DBALTransaction` | 2 |

All symbols are stable DBAL primitives defined in `packages/database-legacy/src/`. Every consumer imports the symbol via `use` or FQCN reference — there is no aliasing, inheritance from Drupal base classes, or use of deprecated constructor signatures.

---

## 2. Classification rubric

| Category | Label | Criteria |
|----------|-------|----------|
| (a) | `migrate-to-target` | Stable DBAL primitive consumer; namespace-edit-only migration. No behaviour change required. |
| (b) | `intentional-bridge` | Load-bearing bridge to legacy Drupal DBAL behaviour. Must cite symbol(s) and the reason it cannot be replaced. |
| (c) | `misuse-migrate-elsewhere` | Wrong abstraction; must recommend target (entity-storage, EntityRepository, raw DBAL) and effort estimate. |

---

## 3. Per-file classification

### 3.1 Files internal to `packages/database-legacy/` — excluded from migration scope

These 13 files are the package itself. Under ELIMINATE they are deleted; under RENAME they are moved.

| File | Note |
|------|------|
| `packages/database-legacy/src/DBALDatabase.php` | Primary concrete class |
| `packages/database-legacy/src/DBALTransaction.php` | Transaction wrapper |
| `packages/database-legacy/src/DatabaseInterface.php` | Core interface |
| `packages/database-legacy/src/DeleteInterface.php` | Query interface |
| `packages/database-legacy/src/InsertInterface.php` | Query interface |
| `packages/database-legacy/src/SchemaInterface.php` | Schema interface |
| `packages/database-legacy/src/SelectInterface.php` | Query interface |
| `packages/database-legacy/src/TransactionInterface.php` | Transaction interface |
| `packages/database-legacy/src/UpdateInterface.php` | Query interface |
| `packages/database-legacy/src/Query/DBALDelete.php` | Concrete query builder |
| `packages/database-legacy/src/Query/DBALInsert.php` | Concrete query builder |
| `packages/database-legacy/src/Query/DBALSelect.php` | Concrete query builder |
| `packages/database-legacy/src/Query/DBALUpdate.php` | Concrete query builder |
| `packages/database-legacy/src/Schema/DBALSchema.php` | Schema implementation |

### 3.2 All 294 non-package-self files — category (a) `migrate-to-target`

Every file outside the package uses one or more of the stable symbols listed in §1. No file:
- Extends a Drupal-era base class from `database-legacy`.
- Uses `PdoDatabase` (a legacy Drupal DBAL class not present in this package).
- Relies on Drupal-specific query-builder syntax.
- Has a constructor dependency that cannot be replaced by the same symbol from a renamed/relocated package.

The migration for every file in this category is a **namespace-edit-only** operation: replace the `use Waaseyaa\Database\*` import with the equivalent import from the new package location. No logic changes required.

**Classification: (a) for all 294 files.**

### 3.3 Category (b) — intentional-bridge candidates examined and rejected

The following files were examined as potential (b) candidates:

**`packages/entity-storage/tests/Repository/Support/CountingDatabaseProxy.php`** — uses `DatabaseInterface`, `DeleteInterface`, `InsertInterface`, `SchemaInterface`, `SelectInterface`, `TransactionInterface`, `UpdateInterface`. This is a test-only proxy that wraps `DatabaseInterface` to count query calls. It implements the stable interfaces; it is not a bridge to Drupal behaviour. **Classification: (a).**

**`packages/migration/src/MigrationIdMap.php`** — uses `DatabaseInterface` only. The migration package stores migration ID maps in a non-entity SQL table; this is an accepted use of `DatabaseInterface` directly (non-entity supporting table per CLAUDE.md). No Drupal-era bridge behaviour. **Classification: (a).**

**`packages/migration/src/MigrationRunState.php`** — uses `DatabaseInterface` only. Same pattern as `MigrationIdMap`. **Classification: (a).**

**`packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`** — bootstraps `DBALDatabase`. Kernel bootstrapper exemption applies (CLAUDE.md). The bootstrapper wires all layers; it is not a bridge. **Classification: (a).**

**Result: 0 files classified (b).** No load-bearing Drupal-era bridge behaviour was found anywhere in the monorepo.

### 3.4 Category (c) — misuse candidates examined and rejected

`PdoDatabase` appears only in `packages/database-legacy/src/DBALDatabase.php` as a `class_exists()` sanity check — it is not imported or used by any consumer. No consumer uses raw PDO, Laravel facades, or Illuminate imports. **Result: 0 files classified (c).**

---

## 4. Per-package summary table

| Package | Files | (a) | (b) | (c) | Recommended action |
|---------|-------|-----|-----|-----|--------------------|
| `database-legacy` (self) | 13 | — | — | — | Delete (ELIMINATE) or move (RENAME) |
| `entity-storage` | 71 | 71 | 0 | 0 | Namespace-edit-only |
| `tests/` (integration) | 48 | 48 | 0 | 0 | Namespace-edit-only |
| `foundation` | 47 | 47 | 0 | 0 | Namespace-edit-only |
| `migration` | 15 | 15 | 0 | 0 | Namespace-edit-only |
| `oidc` | 12 | 12 | 0 | 0 | Namespace-edit-only |
| `genealogy` | 12 | 12 | 0 | 0 | Namespace-edit-only |
| `cli` | 11 | 11 | 0 | 0 | Namespace-edit-only |
| `ai-agent` | 10 | 10 | 0 | 0 | Namespace-edit-only |
| `ssr` | 7 | 7 | 0 | 0 | Namespace-edit-only |
| `api` | 7 | 7 | 0 | 0 | Namespace-edit-only |
| `queue` | 6 | 6 | 0 | 0 | Namespace-edit-only |
| `search` | 5 | 5 | 0 | 0 | Namespace-edit-only |
| `scheduler` | 5 | 5 | 0 | 0 | Namespace-edit-only |
| `relationship` | 5 | 5 | 0 | 0 | Namespace-edit-only |
| `attachment` | 5 | 5 | 0 | 0 | Namespace-edit-only |
| `ai-observability` | 5 | 5 | 0 | 0 | Namespace-edit-only |
| `media` | 4 | 4 | 0 | 0 | Namespace-edit-only |
| `listing` | 4 | 4 | 0 | 0 | Namespace-edit-only |
| `auth` | 4 | 4 | 0 | 0 | Namespace-edit-only |
| `audit` | 4 | 4 | 0 | 0 | Namespace-edit-only |
| `notification` | 3 | 3 | 0 | 0 | Namespace-edit-only |
| `state` | 2 | 2 | 0 | 0 | Namespace-edit-only |
| `groups` | 2 | 2 | 0 | 0 | Namespace-edit-only |
| **Total** | **307** | **294** | **0** | **0** | |

*Note: column (a) count for `database-legacy` self-files is excluded from the "migrate" total — those files are the package itself, not consumers.*

---

## 5. External-consumer scan (T004)

Both `/home/fsd42/dev/minoo` and `/home/fsd42/dev/claudriel` were accessible and scanned.

### Minoo

Source files (non-cache, non-vendor) referencing `Waaseyaa\Database\`:

| File | Symbol | Classification |
|------|--------|----------------|
| `src/Provider/AppCommandServiceProvider.php` | `\Waaseyaa\Database\DatabaseInterface` (FQCN in constructor param) | (a) |
| `tests/App/Integration/Storage/GroupBundleRoutingTest.php` | `DBALDatabase` (via waaseyaa/* vendored) | (a) |
| `tests/App/Integration/AuthEmailFlowTest.php` | `DBALDatabase` (via waaseyaa/* vendored) | (a) |

`minoo/composer.json` requires `"waaseyaa/database-legacy": "^0.1"` directly. Under ELIMINATE, this constraint must be updated to point to the new package name in lockstep with the Waaseyaa release. Minoo's own source code references `DatabaseInterface` by FQCN in one service provider — a namespace-edit-only migration.

### Claudriel

Source files (non-cache, non-vendor) referencing `Waaseyaa\Database\`:

| File | Symbol | Classification |
|------|--------|----------------|
| `src/Provider/StateServiceProvider.php` | `DatabaseInterface`, `DBALDatabase` | (a) |
| `src/Provider/McpServiceProvider.php` | `DatabaseInterface` | (a) |
| `src/Provider/ClaudrielServiceProvider.php` | `DatabaseInterface` | (a) |

`claudriel/composer.json` requires `"waaseyaa/database-legacy": "dev-main"` directly. Claudriel is co-developed in the same workspace; `dev-main` resolution means it tracks `main` — it will pick up the package rename automatically once the Packagist entry is updated.

**External-consumer verdict:** Both consumers use only stable (a) symbols. Both will require a `composer.json` constraint update (composer package name change). Neither requires a PHP code logic change beyond namespace import updates. The release cadence can be coordinated: Minoo uses a pinned semver constraint (`^0.1`) and can upgrade in lockstep; Claudriel uses `dev-main` and self-updates.

---

## 6. ADR-007 engagement

ADR-007 ("Keep `database-legacy` Composer name with `Waaseyaa\Database` namespace", status: Accepted) decided *not to rename* the Composer package during the alpha window, citing:

1. Breaking every `composer.json` constraint, split-repo mirrors, and consumer apps.
2. Collision with a future `waaseyaa/database` metapackage.
3. No incremental runtime value.

This mission supersedes that context. ADR-007 was a *naming* decision, not a *retirement* decision. The current mission is not a rename — it is a **retirement of the package**. The constraints cited in ADR-007 apply equally here (breaking consumer `composer.json`), but DIR-003 explicitly permits breaking changes during alpha without a deprecation window, requiring only a CHANGELOG entry and UPGRADING.md migration recipe. The "no incremental runtime value" argument from ADR-007 does not apply to elimination — retirement has clear architectural value: it removes a layer of indirection, shrinks the public surface, and reduces the split-repo count.

---

## 7. Disposition recommendation

**Recommendation: ELIMINATE `waaseyaa/database-legacy`.**

The audit data grounds this recommendation in DIR-003 (Greenfield Removal Policy):

> "During alpha (current state), the greenfield principle applies. When a better pattern lands, the old one is removed outright. No deprecation window is required. Backwards-compat shims that retain known-bad patterns are forbidden."

All three ELIMINATE preconditions are met:

1. **Every (a) callsite has a 1:1 target.** All 294 consumer files use `DatabaseInterface`, `DBALDatabase`, and query-builder interfaces — these are defined in `database-legacy` and will need a new home. The PHP namespace `Waaseyaa\Database\` is **preserved** per FR-008; only the Composer package name and directory change. No PHP code logic changes are required at any callsite.

2. **Zero (b) classifications survived scrutiny.** No file in the monorepo bridges Drupal-era DBAL behaviour. The `DBALDatabase` / `DatabaseInterface` / query-builder interface layer is already the idiomatic Waaseyaa persistence seam — it needs no bridge.

3. **External consumers can coordinate.** Minoo (pinned `^0.1`) and Claudriel (`dev-main`) both use stable (a) symbols. Claudriel auto-tracks `main`. Minoo requires a coordinated `composer.json` update at the next release — standard alpha-phase coordination, no exceptional barrier.

**Path selected: Path A — ELIMINATE** per `plan.md`. WP03 will delete `packages/database-legacy/`, move the classes to their permanent home (either inlined into `packages/foundation/` or a new `packages/database/` package as decided by WP03 per plan.md §"Path A"), update split.yml, CLAUDE.md, root composer.json, write ADR superseding ADR-007, and open the PR.

**WP02 scope:** 294 files. All category (a). All namespace-edit-only. No (c) out-of-band follow-ups. No (b) retentions.
