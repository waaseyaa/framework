# Work Package Prompt: WP01 — analytics → audit rename

**Mission:** `empty-package-decisions-analytics-billing-aischema-01KSEFV4`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

WP01 is a **bulk edit** by definition (`Waaseyaa\Analytics\` namespace touched in every consumer). The `spec-kitty-bulk-edit-classification` skill is MANDATORY before any code change.

## What you are doing

Rename the entire `analytics` package to `audit`. Choose a Umami carving strategy. Move the directory, edit composer.json, bulk-edit every consumer, update split.yml and CLAUDE.md, write the ADR. This unblocks `ocap-audit-log-substrate-01KSEFTF`.

## THE pattern to mirror (read first)

- `docs/extraction-log.md` — for the precedent rename / extraction shape.
- `docs/adr/007-database-legacy-package-naming.md` — the ADR-shape reference (status / context / decision / consequences / references sections).
- `spec-kitty-bulk-edit-classification` skill — for `occurrence_map.yaml` shape.

## Subtasks

### T001 — Bulk-edit gate: occurrence_map.yaml

Invoke `spec-kitty-bulk-edit-classification`. Produce `occurrence_map.yaml` at the mission root.

Pre-flight grep:
```bash
grep -rln 'Waaseyaa\\Analytics' packages/ tests/ --include='*.php' > /tmp/analytics-consumers.txt
grep -l '"waaseyaa/analytics"' packages/*/composer.json > /tmp/analytics-composer-consumers.txt
```

Both lists feed `occurrence_map.yaml`.

### T002 — Umami carving decision (FR-005, recorded BEFORE edits)

Choose one:
- **Strategy i — Embed:** `UmamiClient` becomes `Waaseyaa\Audit\Umami\UmamiClient` inside the audit package. Single-package result.
- **Strategy ii — Shim:** new `packages/analytics-umami/` (namespace `Waaseyaa\Analytics\Umami\`); audit package has zero Umami code.

Record the choice and the one-paragraph rationale at the top of the WP01 activity log. The same paragraph goes into the ADR's Decision section.

### T003 — git mv

```bash
git mv packages/analytics packages/audit
```

History preservation matters (FR-001). Do not use `cp -r` + `rm -rf`.

### T004 — composer.json edit

Open `packages/audit/composer.json`. Edits:
- `name`: `waaseyaa/analytics` → `waaseyaa/audit`.
- `description`: per FR-003. Strategy i full text: `"Audit substrate for OCAP-aligned governance — read/write/export/access-denied event recording, retention policy hooks, query API. Includes legacy Umami pageview proxy."`. Strategy ii drops the trailing sentence.
- `autoload.psr-4["Waaseyaa\\Analytics\\"]` → `["Waaseyaa\\Audit\\"]`.
- `autoload-dev.psr-4["Waaseyaa\\Analytics\\Tests\\"]` → `["Waaseyaa\\Audit\\Tests\\"]`.
- Preserve `sort-packages: true`, `branch-alias`, every other field.

### T005 — UmamiClient relocation

**Strategy i:**
```bash
mkdir -p packages/audit/src/Umami
git mv packages/audit/src/UmamiClient.php packages/audit/src/Umami/UmamiClient.php
```
Edit the file's `namespace Waaseyaa\Analytics;` → `namespace Waaseyaa\Audit\Umami;`. If a Twig partial path or JS path is hardcoded, leave it (the partials moved along with the package directory).

**Strategy ii:**
```bash
mkdir -p packages/analytics-umami/src
git mv packages/audit/src/UmamiClient.php packages/analytics-umami/src/UmamiClient.php
git mv packages/audit/templates packages/analytics-umami/templates
git mv packages/audit/assets packages/analytics-umami/assets
```
Then author `packages/analytics-umami/composer.json` mirroring the original analytics composer.json shape with name `waaseyaa/analytics-umami`, PSR-4 prefix `Waaseyaa\Analytics\Umami\`, and minimal require (just PHP version).

### T006 — Bulk-edit consumers

For every file in `/tmp/analytics-consumers.txt`:
- Strategy i: `Waaseyaa\Analytics\UmamiClient` → `Waaseyaa\Audit\Umami\UmamiClient` (or `Waaseyaa\Analytics\…` → `Waaseyaa\Audit\…` for any other reference).
- Strategy ii: `Waaseyaa\Analytics\UmamiClient` → `Waaseyaa\Analytics\Umami\UmamiClient`.

Apply per occurrence map. Run each consumer package's PHPUnit suite after editing.

### T007 — composer.json consumer updates

For every file in `/tmp/analytics-composer-consumers.txt`:
- Strategy i: `"waaseyaa/analytics"` → `"waaseyaa/audit"`.
- Strategy ii: `"waaseyaa/analytics"` → `"waaseyaa/analytics-umami"` (the consumer was a Umami consumer; the audit substrate is not yet relevant to it).

Preserve the `^<current-tag>` constraint shape (CP-NEW). Confirm the per-file diff is namespace-only.

### T008 — split.yml edit

Open `.github/workflows/split.yml`. Locate the `packages/analytics` entry (line ~85):

```
          - { local: 'packages/analytics', remote: 'analytics' }
```

- Strategy i: change to:
  ```
          - { local: 'packages/audit', remote: 'audit' }
  ```
- Strategy ii: change as above AND insert:
  ```
          - { local: 'packages/analytics-umami', remote: 'analytics-umami' }
  ```

### T009 — CLAUDE.md edits

L0 row in Layer Architecture table:
- Current cell contains `analytics`.
- Strategy i: replace `analytics` with `audit`.
- Strategy ii: replace `analytics` with `audit, analytics-umami`.

Orchestration table row pattern `packages/analytics/*` → `packages/audit/*`. Cold-memory cell updates to reference `packages/audit/README.md` if present.

### T010 — New ADR

Write `docs/adr/0NN-analytics-renamed-to-audit.md` (NN = highest existing + 1). Use the template in `plan.md` §"New ADR" verbatim. Fill the `<strategy …>` placeholder per T002 decision.

### T011 — Verification

```bash
test -d packages/audit && test ! -d packages/analytics
grep -r "Waaseyaa\\\\Analytics" packages/ --include='*.php' | grep -v 'packages/analytics-umami/'   # empty
grep -l '"waaseyaa/analytics"' packages/*/composer.json   # empty
composer check-composer-policy
bin/check-package-layers
bin/check-getquery-bindings
./vendor/bin/phpunit
```

All exit 0 / all green.

### T012 — Commit + PR

Commit per logical batch (composer + rename in one commit, bulk-edit consumers in one commit, split.yml + CLAUDE.md in one commit, ADR in one commit). PR title:

```
chore(analytics→audit): rename package to host OCAP audit substrate (DIR-003 no-shim rename)
```

PR body cites:
- ADR-0NN.
- `ocap-audit-log-substrate-01KSEFTF` (consumer mission).
- DIR-003 (no-shim policy).
- Umami carving strategy taken (i embedded vs ii shim).
- Downstream consumer migration note for Minoo / Claudriel.
- Sibling WPs (WP02 billing, WP03 ai-schema) and the parent mission slug.

## Verification gate (in lane worktree)

- All T011 commands exit 0 / green.
- `git log --oneline` shows the batched commits per T012.
- ADR exists and Decision section names the chosen strategy.

## Commit + handoff

Open PR; cross-reference WP02 and WP03 PR URLs once they exist.

## Report back with

- Carving strategy chosen.
- New ADR number + path.
- PR URL.
- Number of consumer files bulk-edited.

## Activity Log

_(populated during execution; record carving strategy decision FIRST, before T003)_
