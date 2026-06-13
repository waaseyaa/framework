# Implementation Plan: Genealogy Package Extraction (Distribution-Layer Reclassification)

**Mission:** `genealogy-package-extraction-01KSEFTZ`
**Spec:** [./spec.md](./spec.md)

This mission is a **metadata-only classification flip**. No genealogy source files are touched. The package keeps its name, namespace, autoload, dependencies, and split-mirror configuration. What changes is its *recorded position* in the framework architecture: from "Layer 6 — Interfaces" framework package to "Distribution Extensions" appendix. Three work packages execute the change in dependency order.

## WP01 — composer description flip + spec banner (foundation of the reclassification)

**Owns:** `packages/genealogy/composer.json`, `docs/specs/genealogy.md`.

### `packages/genealogy/composer.json`

Edit the `description` field only. Existing value (verbatim from current file):

```
"description": "Genealogy domain entities, graph traversal, and public SSR for Waaseyaa",
```

Replace with:

```
"description": "Distribution-extension package — Indigenous genealogy entities, graph traversal, and public SSR for Waaseyaa-based distributions",
```

No other field touched. `sort-packages: true` constraint preserved (CP001). `^<current-tag>` internal constraints preserved (CP-NEW). PSR-4 `Waaseyaa\Genealogy\` preserved (NFR-001, C-002).

### `docs/specs/genealogy.md`

Insert a banner block at the very top of the file, **before** the existing `# Genealogy package (v0.1)` H1. Banner shape:

```markdown
> **Distribution-extension package** — `waaseyaa/genealogy` is a *distribution-extension*,
> not framework substrate. Per charter directive DIR-004 (Framework vs Distribution
> Architecture), domain content like Indigenous family lineage modelling is delivered
> as a separately-versioned package consumers opt into, and is **not** required by
> `waaseyaa/core`, `waaseyaa/cms`, or `waaseyaa/full`. See
> `docs/specs/extraction-log.md` for the reclassification record.
```

The banner is 5 lines including the leading marker — within the FR-002 envelope of 3–6 lines. Cites DIR-004 explicitly (satisfies FR-002 acceptance).

### Pre-flight verification (block-or-proceed gate inside WP01 — required by FR-007)

Run, and record the output verbatim in the WP activity log:

```bash
grep -n "waaseyaa/genealogy" packages/cms/composer.json packages/core/composer.json packages/full/composer.json
```

Expected: **no matches** (exit code 1 from grep). If any match returns, WP01 enters BLOCKED state, the metapackage edit is filed as a separate out-of-band note, and WP02 does not start. (This is the only check that can short-circuit the mission.)

## WP02 — CLAUDE.md surfacing + extraction-log record (depends on WP01)

**Owns:** `CLAUDE.md`, `docs/specs/extraction-log.md`.

### `CLAUDE.md` — Layer 6 table edit

Locate the `## Layer Architecture` table row:

```
| 6 | Interfaces | cli, admin-surface, graphql, mcp, ssr, genealogy, telescope, deployer, inertia, debug |
```

Remove the `genealogy, ` token (keep the comma cadence consistent). Result:

```
| 6 | Interfaces | cli, admin-surface, graphql, mcp, ssr, telescope, deployer, inertia, debug |
```

### `CLAUDE.md` — new Distribution Extensions section

Insert a new H2 section **immediately after** the `## Layer Architecture` block (before `## Operation Checklists`):

```markdown
## Distribution Extensions

Distribution-extension packages live in `packages/` and split-mirror to Packagist
on the same release cadence as the framework, but they are **not** part of the
framework substrate. Consumers (Nation distributions, civic-tech apps) opt into
them by name. They are not required by `core`, `cms`, or `full`. The
framework-vs-distribution boundary is codified in charter directive DIR-004.

| Package | Purpose | Distribution channel | Spec |
|---|---|---|---|
| `genealogy` | Indigenous family lineage modelling — `genealogy_person`, `genealogy_family`, `genealogy_event`, `genealogy_tree`, lineage / spouse / membership / identity relationship bundles, OCAP-aligned access policies, public SSR pedigree views | Packagist `waaseyaa/genealogy` (split-mirror) | [docs/specs/genealogy.md](docs/specs/genealogy.md) |
```

### `CLAUDE.md` — orchestration table row update

Locate the existing row:

```
| `packages/genealogy/*` | — | `docs/specs/genealogy.md`, `docs/specs/relationship-modeling.md` |
```

Replace with:

```
| `packages/genealogy/*` | — (distribution-extension) | `docs/specs/genealogy.md`, `docs/specs/relationship-modeling.md` |
```

(The `—` becomes `— (distribution-extension)` — preserves the no-specialist-skill status while flagging the boundary classification per FR-005.)

### `docs/specs/extraction-log.md` — new H2 entry

Insert **immediately after** the `# Extraction Log` H1 and any leading prose, **before** the first `##` heading currently in the file (so the newest entry appears first in chronological-descending order, matching the existing pattern). Block:

```markdown
## 2026-05 — genealogy distribution-extension reclassification

**Mission:** `genealogy-package-extraction-01KSEFTZ`
**Source:** `packages/genealogy/` (in-tree before and after — no physical move)
**Target:** Packagist `waaseyaa/genealogy` (already split-mirrored; classification flipped from framework-layer to distribution-extension)

### Rationale

`packages/genealogy/` is the first package to be reclassified under the
framework-vs-distribution boundary codified in charter directive DIR-004 (see
`charter-amendment-anokii-track-01KSEFE0`). Its subject-matter scope —
Indigenous family lineage modelling, living-person rules, B2 identity-mapping
precedence, tombstones — is domain content, not framework substrate. Framework
consumers (`core`, `cms`, `full`) must not be forced to pull genealogy entities
to use the entity / storage / relationship / access primitives.

### Scope

Metadata-only classification flip. Package name, namespace, autoload,
dependencies, split-mirror configuration, src tree, and tests are all
preserved verbatim. Five files touched: `packages/genealogy/composer.json`
(description), `docs/specs/genealogy.md` (banner block), `CLAUDE.md` (Layer 6
table row removed, new `## Distribution Extensions` section added,
orchestration-table row annotated), `docs/specs/extraction-log.md` (this
entry), and `.github/workflows/split.yml` (verified unchanged).

### What changed in this repo

- `packages/genealogy/composer.json` `description` now begins
  `Distribution-extension package —`.
- `docs/specs/genealogy.md` now opens with a DIR-004 banner block.
- `CLAUDE.md` Layer 6 table no longer lists `genealogy`; new
  `## Distribution Extensions` H2 lists it with package / purpose /
  distribution channel / spec link columns.
- `CLAUDE.md` orchestration row for `packages/genealogy/*` carries the
  `(distribution-extension)` annotation.

### Downstream consumer impact

**None.** `waaseyaa/genealogy` keeps its Packagist URL, its PSR-4 namespace
(`Waaseyaa\Genealogy\`), and its `^<current-tag>` framework constraints.
Consumers (notably Minoo) require it by name; nothing changes for them.

### Layer-guard reasoning

`bin/check-package-layers` enforces internal `waaseyaa/*` dependency layers
against the table in `CLAUDE.md`. Because the Layer 6 row no longer lists
`genealogy`, the script no longer layer-checks it as a framework package; the
verification command (`bin/check-package-layers`) continues to exit 0 because
metapackages and packages not in the layer table are skipped. A future
re-introduction of a genealogy framework-layer dep would surface as a
classification audit follow-up here (see Follow-ups, below) and via the
extraction-log entry being out of date relative to actual deps.

### Follow-ups

- Future `waaseyaa/genealogy-distribution-extension` umbrella metapackage
  (post-v0.1) once a second distribution-extension exists.
- Future physical-org relocation off the framework GitHub org (post-v1) once
  the distribution-extension catalogue has stabilised.
- Bimaaji-specific surfaces and Minoo-specific extractions follow this same
  classification-flip pattern; their missions must run the same metapackage
  verification grep (FR-007 equivalent) before reclassifying.
```

## WP03 — verification gates + PR (depends on WP02)

**Owns:** nothing edited. Owns the verification transcript and the PR.

### Acceptance commands (run in order, capture transcripts in activity log)

```bash
git diff --stat origin/main..HEAD                                            # only spec-enumerated files
grep -n "Distribution Extensions" CLAUDE.md                                  # >= 1 match in H2 position
grep -n "genealogy distribution-extension reclassification" docs/specs/extraction-log.md  # exactly 1
grep -c "DIR-004" docs/specs/genealogy.md                                    # >= 1
grep "waaseyaa/genealogy" packages/cms/composer.json packages/core/composer.json packages/full/composer.json  # no matches
composer check-composer-policy                                               # exit 0
bin/check-package-layers                                                     # exit 0
grep -n "packages/genealogy" .github/workflows/split.yml                     # exactly the pre-existing entry (line 78)
```

If any command fails, WP03 transitions to BLOCKED with the failing command and output recorded.

### PR

- Title: `chore(genealogy): reclassify as distribution-extension package (DIR-004 first extraction)`
- Body cites: charter directive DIR-004 (link to `charter-amendment-anokii-track-01KSEFE0`), `docs/specs/extraction-log.md` precedent entries (`groups`, `mail-api`, `geo-distance`), mission slug `genealogy-package-extraction-01KSEFTZ`.
- No GitHub issue link (NFR-003).

## Verification gate (each WP, in lane worktree)

- `composer check-composer-policy` exits 0.
- `bin/check-package-layers` exits 0.
- `git status` shows only spec-enumerated files modified.
- WP01 additionally: the pre-flight grep transcript is in the activity log.

## Reviewer focus

- **Tone:** no apologetic / hedging language in the spec banner or the extraction-log entry. The classification flip is a positive architectural decision, not a retreat.
- **Boundary fidelity:** every reference to DIR-004 lands on real anchor text — verify each citation has a target.
- **Byte-precision for table cells:** Layer 6 table cell must read exactly `cli, admin-surface, graphql, mcp, ssr, telescope, deployer, inertia, debug` (no double commas, no trailing whitespace).
- **No silent metapackage edits:** confirm WP01's pre-flight grep transcript is present and shows zero matches; reject if the grep is missing.
- **Precedent capture:** the extraction-log Follow-ups section explicitly names the verification checklist a Bimaaji or Minoo extraction must run. Reject if that paragraph is missing.
