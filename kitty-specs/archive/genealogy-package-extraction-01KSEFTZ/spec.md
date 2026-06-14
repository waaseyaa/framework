# Genealogy Package Extraction — Distribution-Layer Split-Mirror

**Mission:** `genealogy-package-extraction-01KSEFTZ`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue (framework housekeeping). First execution of the framework-vs-distribution boundary codified in `charter-amendment-anokii-track-01KSEFE0` — sets the precedent for every future distribution-extension extraction.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape. `groups` extraction (2026-04, recorded in `docs/specs/extraction-log.md`) for prior-art on split-mirror process.

## Why this mission exists

`packages/genealogy/` is the only entry in Layer 6 whose **subject-matter scope** (Indigenous family lineage modelling — `genealogy_person`, `genealogy_family`, `genealogy_event`, `genealogy_tree`, `genealogy_*_of` relationship bundles, `GenealogyContentAccessPolicy`, public SSR pedigree views) is domain-specific application content, not framework substrate. The framework supplies the entity/storage/relationship/access primitives; genealogy *uses* them to model a particular cultural-records problem. By the charter's Framework vs Distribution boundary (DIR-004 codified by `charter-amendment-anokii-track-01KSEFE0`), domain content belongs in a **distribution-extension** package distributed on its own cadence, not in the framework metapackages (`core`, `cms`, `full`).

Per the inventory (`packages/genealogy/` = 15 src files, 94 tests, "POC of custom-domain integration; not core to workspace"), the package is mature enough to stand on its own and small enough to extract cleanly. Three forces converge:

1. **Boundary clarity** — keeping genealogy inside the framework muddies the framework's positioning. Outside consumers (other Nations, civic-tech orgs) should not have to pull `genealogy_person` to use the entity system.
2. **Release decoupling** — genealogy needs editorial / cultural review cadences (living-person rules, B2 identity-mapping precedence, tombstones) that are slower and more deliberate than framework patch releases.
3. **Precedent for future extractions** — Bimaaji-app-specific components, Minoo-specific surfaces, and other distribution-extension packages will follow this same pattern. Doing genealogy first establishes the playbook.

The extraction keeps genealogy on the framework org as a **split-mirror** (consistent with `groups`, `mail-api`, `geo-distance` precedent) but flips its classification from *framework package* to *distribution-extension package* — preserving git history and Packagist identity (`waaseyaa/genealogy`) while removing it from the default framework install set and the layer table.

## Scope

### In scope

- **Split-mirror configuration:** retain `waaseyaa/genealogy` as a split-mirror in `.github/workflows/split.yml` (line 78 already present), promote its classification to *distribution-extension* in spec + charter terminology.
- **Framework decoupling:**
  - Remove `genealogy` from any framework metapackage that requires it (verified pre-flight: `core`, `cms`, `full` do **not** currently require genealogy — keeping them unchanged is the correct outcome, NOT an oversight; spec must record that verification in the activity log).
  - Remove the `packages/genealogy` row from the Layer 6 table in `CLAUDE.md` and reclassify the package under a new **Distribution Extensions** appendix section.
  - Verify `bin/check-package-layers` behaviour for `genealogy` (whether it skips or layer-checks today) and document the reasoning so a future re-introduction of a genealogy framework-layer dep would still be caught.
- **Spec/doc updates:**
  - Convert `docs/specs/genealogy.md` opening header to mark genealogy as a *distribution-extension package* (not framework substrate). Keep the contract content (entity definitions, relationship bundles, access policies, SSR routes) — it is still the authoritative reference for any consumer.
  - Append a `2026-05 — genealogy distribution-extension reclassification` entry to `docs/specs/extraction-log.md` mirroring the existing `groups` entry's shape (rationale, scope, follow-ups, links).
  - Update the `CLAUDE.md` orchestration-table row for `packages/genealogy/*` to flag it as a distribution-extension (cold-memory spec stays pointed at `docs/specs/genealogy.md`).
- **Composer-policy preservation:**
  - `genealogy` package name stays `waaseyaa/genealogy` (CP006: avoid rename; no Packagist coordination needed). Description in `packages/genealogy/composer.json` is updated to begin "Distribution-extension package — Indigenous genealogy entities…" so the Packagist landing page reflects the boundary.
  - `packages/genealogy/composer.json` `require` block keeps `^<current-tag>` framework constraints (CP-NEW). The release-cut script (`bin/sync-internal-versions`) continues to manage these.

### Out of scope

- Moving genealogy off Packagist / the framework GitHub org. (Outright relocation to a separate org belongs to a later mission once the distribution-extension boundary has bedded in.)
- Deleting genealogy code or shrinking its API surface. This mission is a **classification change**, not a refactor.
- Building a `waaseyaa/genealogy-distribution-extension` umbrella metapackage that bundles genealogy with optional companions. Future mission once a second distribution-extension exists.
- Migrating any consumer (Minoo) — they already require `waaseyaa/genealogy` by name; nothing changes for them.
- Touching `genealogy_api` (does not exist) or the SSR theme assets.

## Requirements

### Functional

- **FR-001** `packages/genealogy/composer.json` `description` is updated to a string beginning with the literal token `Distribution-extension package` (so Packagist's metadata clearly signals the classification).
- **FR-002** `docs/specs/genealogy.md` has a new top-of-file banner block (≥ 3 lines, ≤ 6 lines) declaring the package as a **distribution-extension** (not framework substrate), explicitly citing DIR-004 (Framework vs Distribution) from the charter.
- **FR-003** `docs/specs/extraction-log.md` gains a new section titled exactly `## 2026-05 — genealogy distribution-extension reclassification` (mirrors the `groups` entry's H2 cadence) with subsections covering: rationale, scope, what changed in this repo, downstream consumer impact (none — name and namespace unchanged), follow-ups.
- **FR-004** `CLAUDE.md` Layer 6 table no longer lists `genealogy`. A new H2 section titled exactly `## Distribution Extensions` appears below the layer table and contains a single row for `genealogy` with the same columns (package, purpose, distribution channel, spec link).
- **FR-005** `CLAUDE.md` orchestration table row for `packages/genealogy/*` updates its "Specialist skill" column to add the suffix `(distribution-extension)` after any existing entry, and keeps `docs/specs/genealogy.md` in the cold-memory column.
- **FR-006** `.github/workflows/split.yml` keeps the existing genealogy line **unchanged** (line 78); WP02 must verify presence rather than re-add. (Removing the split entry would orphan the existing Packagist package.)
- **FR-007** `packages/cms/composer.json`, `packages/core/composer.json`, `packages/full/composer.json` are verified to NOT require `waaseyaa/genealogy`. WP01 must produce a grep transcript in its activity log proving absence; if any of them does require genealogy, the WP enters BLOCKED state and files an out-of-band note rather than silently removing the dep.
- **FR-008** `bin/check-package-layers` continues to function unchanged. The script's behaviour for `genealogy` (whether it skips or layer-checks) must be explicitly reasoned about in the activity log, and the reasoning recorded in the extraction-log entry so a future framework-layer regression is detectable.
- **FR-009** A new pull request lands on `main` containing all of the above changes as a single coherent commit-set, the PR body explicitly links to charter directive DIR-004 and to `docs/specs/extraction-log.md` precedent.

### Non-functional

- **NFR-001** No genealogy source files (`packages/genealogy/src/**`, `packages/genealogy/templates/**`, `packages/genealogy/tests/**`) are modified by this mission. The classification change is metadata-only.
- **NFR-002** Composer policy gates (`composer check-composer-policy`, `bin/check-package-layers`) remain green after the change.
- **NFR-003** No GitHub issue is filed; this is housekeeping covered by Spec Kitty mission state.
- **NFR-004** The `Generated:` header (if present) in `docs/specs/genealogy.md` is preserved or refreshed in-place — no new generation marker introduced.

### Constraints

- **C-001** Mission outputs touch at most five files: `packages/genealogy/composer.json`, `docs/specs/genealogy.md`, `docs/specs/extraction-log.md`, `CLAUDE.md`, and `.github/workflows/split.yml` (verification-only — no edit expected). No other path may be modified.
- **C-002** No code generation, no namespace renaming, no autoload changes. PSR-4 prefix `Waaseyaa\Genealogy\` is preserved verbatim.
- **C-003** Mission blocks every future *distribution-extension extraction* mission (Bimaaji-specific surfaces, Minoo-specific extractions). Their plans cite this mission's slug for precedent.

## Acceptance

- `git diff --stat origin/main..HEAD` after WP03 — only files enumerated under C-001 appear in the diff (and split.yml only if a verification-only no-op change was needed).
- `grep -n "Distribution Extensions" CLAUDE.md` returns at least one match in the H2 position.
- `grep -n "genealogy distribution-extension reclassification" docs/specs/extraction-log.md` returns exactly one match.
- `grep -c "DIR-004" docs/specs/genealogy.md` returns ≥ 1.
- `grep "waaseyaa/genealogy" packages/cms/composer.json packages/core/composer.json packages/full/composer.json` returns no matches.
- `composer check-composer-policy && bin/check-package-layers` exits 0.
- `grep -n "packages/genealogy" .github/workflows/split.yml` returns exactly the one pre-existing entry (line 78).

## Risks

- **Hidden framework-metapackage dependency.** If `core`, `cms`, or `full` actually require genealogy (contrary to current evidence), the extraction breaks downstream installs. **Mitigation:** FR-007 mandates a verified grep before any metapackage edit and a BLOCKED state if found.
- **Split.yml regression.** A future contributor removing the genealogy split entry would orphan the Packagist package. **Mitigation:** FR-006 codifies the entry as load-bearing; the spec record and extraction-log entry document why it must remain.
- **Layer-guard regression.** If genealogy starts requiring an L4+ framework package without anyone noticing, the framework-vs-distribution boundary leaks. **Mitigation:** FR-008 requires explicit reasoning about `bin/check-package-layers` behaviour and recording the reasoning in the extraction-log so a regression is detectable.
- **Precedent miscarriage.** A future Bimaaji or Minoo extraction copies this mission's pattern without verifying its own metapackage independence. **Mitigation:** the extraction-log entry explicitly enumerates the verification checklist a follow-on extraction mission must run.

## Decisions pre-resolved

- **Distribution shape: split-mirror retained on framework org**, not a separate-org relocation. Rationale: preserves history, Packagist URL, and external consumer constraints; the extraction is a *classification* not a *physical move*. A future mission can perform the physical org migration once a stable distribution-extension catalogue exists.
- **No metapackage edits.** Pre-flight verification confirmed `core`, `cms`, `full` do not currently require `waaseyaa/genealogy` — the right action is to record the verification and move on, not to delete anything.
- **No package rename.** CP006 (Composer policy) makes name changes expensive (Packagist coordination, downstream consumer breakage). The classification flip is achieved through description text, charter reference, and CLAUDE.md surfacing.
- **One coherent commit per WP.** Each WP lands a single self-contained change.

## Decisions deferred to implementer

- Whether to introduce a regression test under `tools/composer-policy/` for FR-008 (the spec sketches the need; the implementer may decide between a shell test, a PHP unit test, or a documentation note depending on what already exists — but if introducing a test the file must be enumerated in the WP's owned_files and accepted by the reviewer before merge).
- Exact wording of the banner block in `docs/specs/genealogy.md` (within the 3–6 line envelope of FR-002 and the DIR-004 citation requirement).

## Out-of-band

- Future `waaseyaa/genealogy-distribution-extension` umbrella metapackage (post-v0.1).
- Future physical-org relocation off the framework GitHub org (post-v1).
- The OCAP-aligned genealogy access-policy hardening (`GenealogyContentAccessPolicy`, living-person rules) — owned by genealogy's own roadmap, not this mission.
