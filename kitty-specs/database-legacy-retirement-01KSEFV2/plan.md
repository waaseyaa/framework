# Implementation Plan: Database-Legacy Retirement

**Mission:** `database-legacy-retirement-01KSEFV2`
**Spec:** [./spec.md](./spec.md)

Three sequential WPs: audit → migrate → dispose. The disposition (ELIMINATE vs RENAME) is determined by WP01's audit output, with ELIMINATE the codified default under DIR-003 (Greenfield Removal Policy).

## WP01 — Usage audit (no code changes; produces the audit document)

**Owns:** `docs/audits/2026-05-database-legacy-usage.md`.

### Audit collection

Run the canonical inventory query:

```bash
grep -rln 'Waaseyaa\\Database\\' packages/ tests/ --include='*.php' > /tmp/dblegacy-files.txt
wc -l /tmp/dblegacy-files.txt   # expect ~243 (per pre-flight)
```

For each file, the auditor opens it, identifies which `Waaseyaa\Database\*` symbols are used (typically `DBALDatabase`, `DatabaseInterface`, `DBALSelect`, `PdoDatabase`, `PdoSelect`), and classifies the file into one of three categories.

### Classification rubric (codified — implementer applies, does not reinvent)

**Category (a) — `migrate-to-target`**
- The file uses only stable DBAL primitives (`DBALDatabase`, `DatabaseInterface`, query builder, `DBALSelect`).
- The symbol consumed exists 1:1 in the (post-retirement) framework's DB abstraction.
- Migration is a namespace edit only — no behaviour change.
- Example: `packages/oidc/src/Repository/DatabaseAuthorizationCodeRepository.php` (uses `DatabaseInterface` parameter) — pure consumer of the stable API.

**Category (b) — `intentional-bridge`**
- The file uses `Waaseyaa\Database\` symbols that are *only* available in the legacy bridge package (typically `PdoDatabase`, `PdoSelect`, Drupal-DBAL-specific shims).
- The bridge behaviour is intentional (e.g. ETL of legacy Drupal schemas in the `migration` package).
- Audit MUST document the specific symbol(s) and the reason the bridge is load-bearing.
- If the implementer cannot articulate a load-bearing reason, the file is (a) or (c), not (b).

**Category (c) — `misuse-migrate-elsewhere`**
- The file uses `Waaseyaa\Database\` symbols where the correct abstraction is different — e.g. raw SQL where `EntityRepository` is appropriate, or a `PdoDatabase` callsite that should be raw DBAL.
- Audit MUST recommend the target abstraction (entity-storage driver, `EntityRepository`, direct DBAL) and estimate effort (`trivial` / `out-of-band-followup`).

### Document shape (`docs/audits/2026-05-database-legacy-usage.md`)

```markdown
# Database-Legacy Usage Audit (2026-05)

**Mission:** `database-legacy-retirement-01KSEFV2`
**Author:** WP01 implementer
**Date:** 2026-05-<DD>
**Governing directive:** DIR-003 (Greenfield Removal Policy)

## Summary

| Package | Files touched | (a) migrate | (b) bridge | (c) misuse | Recommended action |
|---------|--------------:|------------:|-----------:|-----------:|--------------------|
| relationship | N | N | 0 | 0 | bulk-migrate |
| listing      | N | N | 0 | 0 | bulk-migrate |
| genealogy    | N | N | 0 | 0 | bulk-migrate |
| oidc         | N | N | 0 | 0 | bulk-migrate |
| migration    | N | … | … | … | retain-bridge OR migrate |
| …            | … | … | … | … | …                  |
| **TOTAL**    | ~243 | … | … | … | …                |

## Per-file classification

### packages/relationship/
- `src/RelationshipSchemaManager.php` — (a) — uses `DatabaseInterface`; migrate via namespace edit only.
- `src/RelationshipTraversalService.php` — (a) — uses `DBALDatabase` query builder; migrate.
- … (every file from the grep, one line each, with category + symbol cited)

### packages/migration/
- (full breakdown — this is the most likely (b) candidate)

…

## External-consumer scan

`Minoo` (`/home/fsd42/dev/minoo` or via API):
- grep result + classification per match.

`Claudriel`:
- grep result + classification per match.

## Disposition recommendation

Based on the per-package and external-consumer scan, the recommended
disposition is **<ELIMINATE | RENAME>** because <one paragraph grounding in
DIR-003 + the audit data>.

ELIMINATE prerequisites that are / are not met:
- [ ] All (a) callsites have a 1:1 target in the post-retirement DB abstraction.
- [ ] All (b) classifications are reclassified as (a) or (c) under scrutiny.
- [ ] No external consumer requires `waaseyaa/database-legacy` by name (or the
      consumer can be migrated in lockstep).

RENAME triggers (any one is sufficient):
- [ ] One or more (b) classifications survive scrutiny.
- [ ] An external consumer's release cadence cannot be coordinated.
- [ ] A symbol in the legacy package has no 1:1 target.
```

WP01 deliverable: the audit document above, with every per-file line filled in, plus the disposition recommendation. No code edits.

## WP02 — Migration (executes the (a) bulk edit + files (c) follow-ups)

**Owns:** every file the audit classifies as (a) under "migrate"; `occurrence_map.yaml` at mission root.

### Pre-flight (bulk-edit gate)

WP02 is a bulk edit by the technical definition (same identifier touched in many files). The `spec-kitty-bulk-edit-classification` skill is mandatory.

Produce `occurrence_map.yaml` at the mission root listing every (a) file from the audit, the symbol(s) edited, and the change mode (`namespace_rename_only` in nearly all cases).

### Migration execution

For each (a) file:
1. Open the file.
2. Apply the namespace edit (or other narrow change) the audit prescribed.
3. Verify by running the file's owning package's PHPUnit suite — no behaviour delta.
4. Verify `bin/check-getquery-bindings` exits 0 after each batch (NFR-002, C-002).

Batch commits per package (e.g. one commit for `packages/relationship/`, one for `packages/oidc/`, etc.) to keep diff review surface manageable.

### (c) misuse handling

For each (c) file the audit marked `trivial`: migrate in-line as part of WP02.
For each (c) file the audit marked `out-of-band-followup`: file a Spec Kitty follow-up note in the WP's activity log with the audit file/line range and the recommended target abstraction. Do NOT touch the file in this mission.

### Verification after WP02

```bash
grep -rln 'Waaseyaa\\Database\\' packages/ tests/ --include='*.php' \
  | grep -v 'packages/database-legacy/' \
  | grep -v 'packages/migration/' \
  > /tmp/dblegacy-stragglers.txt
diff /tmp/dblegacy-stragglers.txt <(yq '.retained_or_followup[]' docs/audits/2026-05-database-legacy-usage.md)
```

Diff must be empty (or only contain explicitly-marked entries).

```bash
composer check-composer-policy
bin/check-package-layers
bin/check-getquery-bindings
./vendor/bin/phpunit
```

All exit 0 / all green.

## WP03 — Disposition (ELIMINATE or RENAME)

**Owns:** depends on disposition path.

### Decision input

Reviewer-of-record reads WP01's audit recommendation and confirms one of two paths. The default is ELIMINATE; RENAME is taken only when the audit triggers one of the listed RENAME conditions.

### Path A — ELIMINATE (default per DIR-003)

**Owns:** `packages/database-legacy/` (deletion), `.github/workflows/split.yml`, `CLAUDE.md`, `composer.json` (if a path repository entry exists), `docs/adr/0NN-database-legacy-retirement.md` (new), `docs/audits/2026-05-database-legacy-usage.md` (appended `## Disposition` section).

Steps:
1. `git rm -r packages/database-legacy/`
2. Edit `.github/workflows/split.yml` — remove line 20 (the `packages/database-legacy` matrix entry).
3. Edit `CLAUDE.md` Layer 0 table — remove `database-legacy` from the package list cell.
4. Edit `CLAUDE.md` orchestration table — remove any row containing `database-legacy`.
5. Edit root `composer.json` — remove the `{"type": "path", "url": "./packages/database-legacy"}` repository entry if present.
6. Write new ADR `docs/adr/0NN-database-legacy-retirement.md` (number = highest existing + 1). Required content:
   - Status: `Accepted (supersedes ADR-007)`.
   - Context: the audit summary + the DIR-003 grounding.
   - Decision: ELIMINATE.
   - Consequences: the package's external consumers (per the audit) MUST migrate in lockstep with this release; CHANGELOG entry under `### Removed`.
   - References: ADR-007, this mission slug, the audit file path.
7. Append a `## Disposition` section to `docs/audits/2026-05-database-legacy-usage.md`:

```markdown
## Disposition

**Path taken:** ELIMINATE
**Decision date:** 2026-05-<DD>
**Reviewer-of-record:** <name>
**Rationale:** Per DIR-003 (Greenfield Removal Policy) and the audit summary
above, no (b) intentional-bridge callsites survived scrutiny and no external
consumer required the legacy package's name. The package is removed outright.

**Files removed:** `packages/database-legacy/` (entire directory).
**CHANGELOG entry:** `### Removed\n- waaseyaa/database-legacy (no replacement; DBAL is the canonical DB abstraction)`.
**ADR:** docs/adr/0NN-database-legacy-retirement.md (supersedes ADR-007).
```

### Path B — RENAME (fallback)

**Owns:** `packages/database-legacy/` → `packages/database-bridge/` (git mv), `.github/workflows/split.yml`, `CLAUDE.md`, every `composer.json` requiring `waaseyaa/database-legacy`, `docs/adr/0NN-database-legacy-rename.md` (new), `docs/audits/2026-05-database-legacy-usage.md` (appended).

Steps:
1. `git mv packages/database-legacy packages/database-bridge`.
2. Edit `packages/database-bridge/composer.json`:
   - `name`: `waaseyaa/database-legacy` → `waaseyaa/database-bridge`.
   - `description`: drop "Interim until Doctrine migration"; replace with the bridge's actual ongoing scope.
   - Preserve `Waaseyaa\Database\` autoload prefix (C-003, FR-008).
3. Run grep to find every package requiring `waaseyaa/database-legacy`:
   ```bash
   grep -l '"waaseyaa/database-legacy"' packages/*/composer.json
   ```
   Edit each to require `waaseyaa/database-bridge` at the same `^<current-tag>` constraint.
4. Edit `.github/workflows/split.yml` — change `packages/database-legacy` / `database-legacy` entry to `packages/database-bridge` / `database-bridge`.
5. Edit `CLAUDE.md` Layer 0 table — replace `database-legacy` with `database-bridge` in the L0 cell.
6. Edit `CLAUDE.md` orchestration table — same replacement.
7. Edit the database-legacy ADR-007 footer (or write new ADR `0NN-database-legacy-rename.md`) marking it Superseded by the new ADR. New ADR contents mirror Path A's ADR but for the rename outcome.
8. Update CHANGELOG `### Changed` entry: `- waaseyaa/database-legacy renamed to waaseyaa/database-bridge (namespace Waaseyaa\\Database\\ preserved)`.
9. Append `## Disposition` to the audit document recording Path B taken, the bridge-justification, and the ADR pointer.

### WP03 verification (either path)

```bash
composer check-composer-policy   # exit 0
bin/check-package-layers          # exit 0
bin/check-getquery-bindings       # exit 0
./vendor/bin/phpunit              # all green
grep -c "database-legacy" CLAUDE.md   # 0 for ELIMINATE; 0 for RENAME (since rename replaces the token)
```

For ELIMINATE additionally:
```bash
test ! -d packages/database-legacy   # directory gone
grep "database-legacy" .github/workflows/split.yml   # no matches
```

For RENAME additionally:
```bash
test -f packages/database-bridge/composer.json   # new path exists
grep "waaseyaa/database-bridge" .github/workflows/split.yml | wc -l   # 1
grep -l '"waaseyaa/database-legacy"' packages/*/composer.json   # no matches
```

### PR

- Title (ELIMINATE): `chore(database-legacy): eliminate package per DIR-003 (audit-driven retirement)`
- Title (RENAME): `chore(database-legacy): rename to waaseyaa/database-bridge (audit-driven; ADR-007 superseded)`
- Body cites: DIR-003, the audit document, ADR-007, the new ADR, prior-art branches `audit/dbal-migration` and `chore/remove-database-legacy`.
- For ELIMINATE: PR body MUST contain a `## Breaking Change` section announcing the removal and listing any external consumer that needs to migrate in lockstep.

## Verification gate (each WP, in lane worktree)

- WP01: `docs/audits/2026-05-database-legacy-usage.md` exists; every file from the pre-flight grep is enumerated; disposition recommendation is present and grounded in DIR-003.
- WP02: namespace migration complete for all (a) files; `occurrence_map.yaml` present; bulk-edit gate green; `bin/check-getquery-bindings` exit 0; PHPUnit green.
- WP03: chosen path's verification commands all green; `## Disposition` section appended to audit; new ADR present; CHANGELOG entry present.

## Reviewer focus

- **Audit completeness:** every file in the pre-flight grep appears in the audit's per-file classification. No silent omissions.
- **(b) scrutiny:** every (b) classification has a load-bearing reason recorded. Reviewer challenges any (b) that reads "kept for now" — that is (a) or (c) in disguise.
- **Binding preservation:** spot-check 5 random (a) migrations for `setAccount()` / `accessCheck(false)` chain preservation (C-002).
- **DIR-003 citation:** the audit's disposition recommendation MUST cite DIR-003 by name; reject if missing.
- **ADR coherence:** the new ADR's Status field reads "Supersedes ADR-007". Reject if it contradicts rather than supersedes.
- **Breaking-change discipline (ELIMINATE only):** PR body has a `## Breaking Change` section. CHANGELOG has the `### Removed` entry.
