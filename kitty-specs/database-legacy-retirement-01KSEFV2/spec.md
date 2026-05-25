# Database-Legacy Retirement — Audit, Migrate, Eliminate-or-Rename

**Mission:** `database-legacy-retirement-01KSEFV2`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue (framework cleanup). Closes out the DBAL migration whose tail is held open by the `database-legacy` package's transitional name. Builds on prior-art branches `audit/dbal-migration` and `chore/remove-database-legacy`.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape. M-B.1 `getQuery()` baseline drive-to-zero for the audit-then-act pattern.

## Why this mission exists

`packages/database-legacy/` carries the word **"legacy" in its directory name** for a reason: it was explicitly stood up as a transitional bridge during the Drupal DBAL → Doctrine DBAL migration. The package's own composer description records this: *"Database adapter wrapping Drupal DBAL. Interim until Doctrine migration."* CLAUDE.md captures the residual confusion: despite the directory being `packages/database-legacy/`, the PHP namespace is `Waaseyaa\Database` (not `Waaseyaa\DatabaseLegacy`), with a dedicated ADR (`docs/adr/007-database-legacy-package-naming.md`) documenting the asymmetry.

The migration to Doctrine DBAL has, by every visible signal, completed: `composer.json` requires `doctrine/dbal: ^4.0`, the codebase exclusively uses `DBALDatabase` for new work, the `bin/check-getquery-bindings` gate landed on top of the DBAL surface (not the bridge), and CLAUDE.md's primary guidance is to "prefer the query builder over raw DBAL" — there is no surviving guidance to use the legacy bridge. Yet:

- 243 files across `packages/` reference the `Waaseyaa\Database\` namespace (per the pre-flight grep). Some are legitimate consumers of DBAL primitives that happen to live in the legacy-named package; some may be vestigial.
- The package is still in the Layer 0 splitsh-lite matrix (line 20 of `.github/workflows/split.yml`) and still published to Packagist as `waaseyaa/database-legacy`.
- Branches `audit/dbal-migration` and `chore/remove-database-legacy` indicate a prior reckoning that was paused, not finished.

Three forces converge:

1. **DIR-003 (Greenfield Removal Policy)** — during alpha, the framework removes legacy surfaces outright when they are no longer load-bearing. No deprecation window required. The package's continued existence is friction, not protection.
2. **OCAP audit story coherence** — the `analytics → audit` rename and the OCAP audit-log substrate (`ocap-audit-log-substrate-01KSEFTF`) need a clean database story. Inheriting a legacy-named DB package into the audit substrate is a smell.
3. **Charter clarity** — every package whose name advertises "legacy" or "interim" is a placeholder for a decision that has not been made. We make the decision here.

## Scope

### In scope

- **Audit (WP01)** — enumerate every consumer of `Waaseyaa\Database\` across `packages/` and `tests/`. Classify each callsite as: (a) consumer of stable DBAL primitives that should migrate to a renamed package, (b) intentional bridge to legacy Drupal DBAL behaviour that must stay (e.g. `migration` package for ETL of legacy schemas), (c) misuse that should migrate to a different abstraction entirely (raw PDO, `EntityRepository`, `entity-storage` driver). Output: `docs/audits/2026-05-database-legacy-usage.md`.
- **Migration (WP02)** — execute the moves the audit prescribes. For category (a): bulk update namespace references to the chosen target. For category (c): file follow-up missions (out-of-band) and migrate any that are trivial in-line. For category (b): retain bridge with `@api` marker and a governance note.
- **Disposition (WP03)** — based on WP01 audit outcomes, choose between:
  - **ELIMINATE** (preferred, DIR-003): remove `packages/database-legacy/` entirely, drop its entry from `.github/workflows/split.yml`, remove the L0 listing in `CLAUDE.md`, retire the ADR with a follow-on ADR explaining the elimination, archive the `waaseyaa/database-legacy` Packagist namespace.
  - **RENAME** (fallback, only if WP01 surfaces a real intentional-bridge requirement): rename `packages/database-legacy/` → `packages/database-bridge/`, namespace stays `Waaseyaa\Database\` (existing ADR-007 documents the asymmetry; renaming the directory eliminates the false promise of "legacy" being a temporary marker), composer name becomes `waaseyaa/database-bridge`, split.yml entry renamed, CLAUDE.md guidance updated.

The disposition decision is **deferred to WP03's reviewer-of-record** based on WP01's audit transcript — with ELIMINATE the preferred outcome per DIR-003 unless the audit surfaces a real bridge use-case.

### Out of scope

- Migrating consumers off Doctrine DBAL itself. The framework's DB abstraction (`DBALDatabase`, the query builder, `bin/check-getquery-bindings`) is settled and stays.
- Changing the PSR-4 namespace prefix `Waaseyaa\Database\` (renaming the namespace is a separate, much larger mission). The bridge-vs-elimination choice in WP03 does not alter the namespace.
- The `migration` package's own ETL handling of legacy Drupal schemas (which is *that* package's reason for existing) — this mission only audits how `migration` consumes `Waaseyaa\Database\` symbols.
- Touching the `bin/check-getquery-bindings` gate's baseline file (`tools/getquery-bindings-baseline.txt`). The drive-to-zero of that baseline is its own M-B.1 follow-up.

## Requirements

### Functional

- **FR-001** WP01 produces `docs/audits/2026-05-database-legacy-usage.md`. The audit document MUST classify every file matching `grep -rln 'Waaseyaa\\\\Database\\\\' packages/ tests/ --include='*.php'` as one of: (a) `migrate-to-target` (stable DBAL primitive consumer), (b) `intentional-bridge` (must stay if RENAME, must justify if ELIMINATE), (c) `misuse-migrate-elsewhere` (move to `EntityRepository`, raw DBAL, or other).
- **FR-002** The audit document MUST include a per-package summary table: package, file count touched, category breakdown, recommended disposition.
- **FR-003** The audit document MUST end with an **explicit disposition recommendation** — either ELIMINATE or RENAME — with the rationale grounded in DIR-003 and the audit data.
- **FR-004** WP02 MUST execute every (a) migration the audit prescribes. After WP02, `grep -rln 'Waaseyaa\\\\Database\\\\' packages/ tests/ --include='*.php' | grep -v 'packages/database-legacy/' | grep -v 'packages/migration/'` MUST return only files explicitly enumerated in the WP01 audit as "retained for bridge" or "out-of-band follow-up". No stragglers.
- **FR-005** WP02 MUST file out-of-band follow-up notes (one per package) for any (c) misuse that the WP02 implementer judged too large to migrate in-line. Each note MUST cite the audit file path and line range and recommend the target abstraction.
- **FR-006** WP03 MUST take exactly one of two paths and record the decision in `docs/audits/2026-05-database-legacy-usage.md` (appending a `## Disposition` section):
  - **ELIMINATE path** — delete `packages/database-legacy/`; remove its entry from `.github/workflows/split.yml`; remove the L0 row containing `database-legacy` from `CLAUDE.md`'s Layer Architecture table; remove the `database-legacy` orchestration-table row; add a new ADR `docs/adr/0NN-database-legacy-retirement.md` (number assigned at write time) referencing ADR-007 as the retirement target; update root `composer.json` if it carries a path repository to `packages/database-legacy/`.
  - **RENAME path** — `git mv packages/database-legacy/ packages/database-bridge/`; update the package's `composer.json` `name` from `waaseyaa/database-legacy` to `waaseyaa/database-bridge`; update every internal `waaseyaa/database-legacy` require to `waaseyaa/database-bridge`; update `.github/workflows/split.yml` entry; update CLAUDE.md L0 row and orchestration row; supersede ADR-007 with a new ADR explaining the rename.
- **FR-007** Either disposition MUST result in `composer check-composer-policy` and `bin/check-package-layers` exiting 0 after the change.
- **FR-008** The `Waaseyaa\Database\` PSR-4 prefix MUST NOT change under either disposition path. (Eliminate removes it entirely; rename preserves it.)
- **FR-009** A pull request lands on `main` containing the changes, the body cites DIR-003, links the audit document, links prior-art branches `audit/dbal-migration` and `chore/remove-database-legacy` if either survives, and explicitly states the disposition path taken.

### Non-functional

- **NFR-001** No PHPUnit test changes other than namespace updates required by FR-004 migrations. No new tests authored in this mission (other than out-of-band follow-up missions filed under FR-005).
- **NFR-002** `bin/check-getquery-bindings` exits 0 throughout (no new offenders). The bindings-baseline file is not edited by this mission.
- **NFR-003** No GitHub issue filed; Spec Kitty mission state is canonical.
- **NFR-004** The audit document MUST be checked into `docs/audits/` (mirrors `docs/audits/2026-05-17-dead-code-baseline-audit.md` precedent).
- **NFR-005** Bulk namespace edits in WP02 MUST be classified per `spec-kitty-bulk-edit-classification` (this mission is a bulk edit in the technical sense — same identifier touched in many files). The implementer MUST produce an `occurrence_map.yaml` covering the (a) category as part of WP02's deliverables.

### Constraints

- **C-001** DIR-003 (Greenfield Removal Policy) is the governing directive. The disposition default is ELIMINATE; RENAME is the fallback only if WP01 surfaces a real bridge use-case. The audit document MUST cite DIR-003 by name in its disposition recommendation.
- **C-002** No callsite migrated by WP02 may regress the `bin/check-getquery-bindings` gate (NFR-002). Each migration MUST preserve `setAccount()` / `accessCheck(false)` binding semantics.
- **C-003** `Waaseyaa\Database\` PSR-4 prefix preserved (FR-008). Directory and Composer name may change; the PHP namespace does not.
- **C-004** ADR-007 (`docs/adr/007-database-legacy-package-naming.md`) MUST be referenced in the new ADR (whether retirement or rename). The historical record is preserved; the current state supersedes it.

## Acceptance

- `docs/audits/2026-05-database-legacy-usage.md` exists, has per-package summary table, has explicit disposition recommendation citing DIR-003 (FR-001, FR-002, FR-003).
- After WP02: `grep -rln 'Waaseyaa\\\\Database\\\\' packages/ tests/ --include='*.php' | grep -v 'packages/database-legacy/'` returns only files explicitly marked in the audit as "retained" or "out-of-band" (FR-004).
- A `## Disposition` section appears in the audit document recording which path was taken (FR-006).
- For ELIMINATE: `ls packages/database-legacy/ 2>&1 | grep -c "No such file"` returns 1; `grep "database-legacy" .github/workflows/split.yml` returns no matches; `grep "database-legacy" CLAUDE.md` returns no matches.
- For RENAME: `ls packages/database-bridge/composer.json` exists; `grep "waaseyaa/database-bridge" .github/workflows/split.yml` returns exactly one match; `grep "waaseyaa/database-legacy" packages/ -r --include='composer.json'` returns no matches.
- `composer check-composer-policy && bin/check-package-layers && bin/check-getquery-bindings` exits 0.
- All PHPUnit suites pass.

## Risks

- **Audit miscategorisation.** WP01 may classify a real bridge consumer as (a) `migrate-to-target`. If WP02 then bulk-edits it, runtime behaviour shifts. **Mitigation:** WP01 audit document MUST be reviewer-approved before WP02 begins. Each (a) entry MUST cite the symbol(s) consumed and confirm they exist in the target abstraction.
- **Bulk-edit blast radius.** 243 files referencing `Waaseyaa\Database\` is a textbook bulk-edit. **Mitigation:** NFR-005 mandates `occurrence_map.yaml` per `spec-kitty-bulk-edit-classification`. WP02 cannot be reviewed without that map.
- **Hidden production dependency.** A downstream consumer (Minoo, Claudriel) may require `waaseyaa/database-legacy` by name. **Mitigation:** WP01 audit MUST grep `Minoo` and `Claudriel` repos (if accessible) and document any external consumer; if the disposition is ELIMINATE, the PR description must announce the breaking change explicitly.
- **`bin/check-getquery-bindings` regression.** A migration that drops a `setAccount()` binding by accident would re-introduce the very class of bug the gate was built to catch. **Mitigation:** C-002 codifies the binding preservation; the WP02 reviewer must spot-check each migration's binding chain.
- **ADR confusion.** ADR-007 currently documents the directory/namespace asymmetry as intentional. The new ADR must supersede, not contradict. **Mitigation:** C-004 mandates the citation; the new ADR's status field reads "Supersedes ADR-007".

## Decisions pre-resolved

- **DIR-003 is the governing directive** — ELIMINATE is the default disposition. RENAME is the fallback only if WP01 surfaces a real bridge use-case.
- **PSR-4 namespace `Waaseyaa\Database\` is preserved** in both dispositions. The bridge name vs the framework's general DB symbols is a directory / Composer concern, not a code concern.
- **Audit document lives in `docs/audits/`** following the `2026-05-17-dead-code-baseline-audit.md` precedent.
- **Bulk-edit guardrail is mandatory** — WP02 produces an `occurrence_map.yaml`.
- **No deprecation window** during alpha (DIR-003). The package's continued presence is friction.

## Decisions deferred to implementer

- **ELIMINATE vs RENAME** — chosen by WP03's reviewer-of-record after WP01's audit lands. The spec sets ELIMINATE as the default and codifies the exit criteria for both paths so either path is implementable without further specification.
- The exact target abstraction for category (a) consumers (DBAL direct, query builder, `EntityRepository`) — chosen per callsite during the audit.
- Whether to file follow-up missions for (c) misuse or migrate in-line — judged per case during WP02.
- New ADR number (next free under `docs/adr/`).

## Out-of-band

- M-B.1 `bin/check-getquery-bindings` baseline drive-to-zero (separate mission).
- Doctrine DBAL major-version bumps.
- The OCAP audit log substrate's choice of DB target — `ocap-audit-log-substrate-01KSEFTF` consumes whatever abstraction this mission lands on, so its target-abstraction language must read "the post-retirement framework DB abstraction".
