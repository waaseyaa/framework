---
work_package_id: WP04
title: README + spec + docs refresh
dependencies:
- WP01
requirement_refs:
- FR-007
- FR-008
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T016
- T017
- T018
- T019
history: []
authoritative_surface: packages/bimaaji/
execution_mode: planning_artifact
owned_files:
- packages/bimaaji/README.md
- docs/specs/bimaaji.md
- CLAUDE.md
- CHANGELOG.md
tags: []
---

## Objective

Refresh all documentation surfaces for the bimaaji package: rewrite `packages/bimaaji/README.md`
to remove the stale "scaffolding only" claim and describe the shipped package, update
`docs/specs/bimaaji.md` Implementation Status to reflect the wired state, add an "Adding
a Bimaaji graph section provider" checklist to `CLAUDE.md`, and add a `[Unreleased]`
CHANGELOG bullet.

This WP has no code changes. It is a documentation-only delivery (`execution_mode: planning_artifact`).

## Context

The investigation that motivated this mission found that `packages/bimaaji/README.md`
states "This repository currently contains scaffolding only; behavior will land in
follow-up issues." This is false — the package has 25 functional PHP classes. The README
misleads consumers and blocks third-party adoption (SC-003: third-party packages need to
know the extension point exists to use it).

`docs/specs/bimaaji.md` has a corresponding stale "Implementation Status" section with
deferred/scaffolding framing. After WP01–WP03 land, both documents must be updated to
reflect the shipped state. The CLAUDE.md checklist addition (plan §WP04 "CLAUDE.md
addition") makes the extension point discoverable to any developer working in this repo.

## Subtasks

### T016 — Rewrite `packages/bimaaji/README.md`

**Purpose:** Replace the stale "scaffolding only" README with accurate documentation
covering the shipped package (FR-007).

**Steps:**

1. Read the current README:
   ```
   cat packages/bimaaji/README.md
   ```
2. Identify all claims that reference "scaffolding", "placeholder", "will land", or "deferred".
3. Rewrite with the following structure (content to match the actual implementation):

   **Structure:**
   ```
   # waaseyaa/bimaaji

   AI-layer package for application graph introspection. Provides a composable
   `ApplicationGraphGenerator` that aggregates structured sections from registered
   `GraphSectionProviderInterface` implementations.

   ## Default providers

   | Key | Class | Description |
   |-----|-------|-------------|
   | `entities`       | `EntityIntrospectionProvider`     | Entity types, fields, and keys |
   | `routing`        | `RoutingIntrospectionProvider`    | Route names, paths, and methods |
   | `jsonapi`        | `JsonApiIntrospectionProvider`    | JSON:API resource types and endpoints |
   | `admin`          | `AdminIntrospectionProvider`      | Admin UI entity coverage |
   | `sovereignty`    | `SovereigntyIntrospectionProvider`| Sovereignty profile and guardrail state |
   | `public_surface` | `PublicSurfaceProvider`           | Public-facing route and permission surface |

   ## Usage

   ```bash
   bin/waaseyaa graph:dump               # Full graph as JSON
   bin/waaseyaa graph:dump --section=routing   # Routing section only
   bin/waaseyaa graph:dump --format=yaml       # YAML output
   bin/waaseyaa graph:dump --strict            # Fail fast on provider errors
   ```

   ## Extending the graph

   Third-party packages can contribute sections by implementing
   `GraphSectionProviderInterface` and registering via the tagged collection:

   ```php
   // In your ServiceProvider::register():
   $this->singleton(FooGraphSectionProvider::class, FooGraphSectionProvider::class);
   $this->tag(FooGraphSectionProvider::class, \Waaseyaa\Bimaaji\BimaajiServiceProvider::TAG);
   ```

   The tag constant `BimaajiServiceProvider::TAG` is `'bimaaji.graph_section_providers'`.

   ## Further reading

   See [docs/specs/bimaaji.md](../../docs/specs/bimaaji.md) for the full specification,
   contract details, and mutation pipeline documentation.
   ```

4. Verify all 6 provider class names match what exists in `packages/bimaaji/src/Provider/`:
   ```
   ls packages/bimaaji/src/Provider/
   ```
   Adjust the table if any class name differs from the plan.

5. Run the drift detector to ensure `docs/specs/bimaaji.md` is not flagged as stale
   after the README edit (the spec is updated in T017):
   ```
   tools/drift-detector.sh 2>/dev/null | grep bimaaji || echo "No drift found"
   ```

**Files touched:**
- `packages/bimaaji/README.md` — rewrite

**Validation:**
- `grep -i "scaffold\|placeholder\|will land\|deferred" packages/bimaaji/README.md`
  returns no results (SC-004).
- All 6 provider keys and class names in the table are accurate.
- The extension point section shows `BimaajiServiceProvider::TAG`.

---

### T017 — Update `docs/specs/bimaaji.md` Implementation Status

**Purpose:** Flip the Implementation Status section from deferred/scaffolding framing to
"shipped" (FR-008).

**Steps:**

1. Read the current Implementation Status section:
   ```
   grep -n -A 10 "Implementation Status\|## Status\|## Implementation" docs/specs/bimaaji.md
   ```
2. Locate the section heading and the stale content.
3. Replace with:

   ```markdown
   ## Implementation Status

   **Status (as of bimaaji-wakeup-01KS5VEY, alpha.188+):** Shipped.

   `BimaajiServiceProvider` wires `ApplicationGraphGenerator` with the 6 default
   `GraphSectionProviderInterface` implementations as a tagged service collection
   (tag: `bimaaji.graph_section_providers`). Discovery is automatic via
   `extra.waaseyaa.providers` in `packages/bimaaji/composer.json`.

   `bin/waaseyaa graph:dump` is the first-party CLI surface. Integration tests live in
   `tests/Integration/PhaseN/Bimaaji/`.

   **Provider inventory:**

   | Key | FQCN |
   |-----|------|
   | `entities`       | `Waaseyaa\Bimaaji\Provider\EntityIntrospectionProvider`      |
   | `routing`        | `Waaseyaa\Bimaaji\Provider\RoutingIntrospectionProvider`     |
   | `jsonapi`        | `Waaseyaa\Bimaaji\Provider\JsonApiIntrospectionProvider`     |
   | `admin`          | `Waaseyaa\Bimaaji\Provider\AdminIntrospectionProvider`       |
   | `sovereignty`    | `Waaseyaa\Bimaaji\Provider\SovereigntyIntrospectionProvider` |
   | `public_surface` | `Waaseyaa\Bimaaji\Provider\PublicSurfaceProvider`            |

   **Extension contract:** Register against `BimaajiServiceProvider::TAG` in your
   `ServiceProvider::register()`. See `packages/bimaaji/README.md` for the full pattern.
   ```

4. Add a drift-review stamp at the bottom of the file (per `feedback_drift_detector_review_stamp.md`
   memory entry) to prevent the drift detector from flagging the spec as stale after
   this mission:
   ```
   <!-- Spec reviewed 2026-05-21 - bimaaji-wakeup-01KS5VEY: implementation status updated to shipped -->
   ```

**Files touched:**
- `docs/specs/bimaaji.md` — edit Implementation Status section

**Validation:**
- `grep -i "scaffold\|deferred\|will land" docs/specs/bimaaji.md` returns no results.
- `grep "bimaaji-wakeup-01KS5VEY" docs/specs/bimaaji.md` returns a result (FR-008).
- `tools/drift-detector.sh 2>/dev/null | grep bimaaji` returns no findings (or only the
  review stamp line is shown, which is acceptable).

---

### T018 — Add "Adding a Bimaaji graph section provider" to CLAUDE.md

**Purpose:** Make the bimaaji extension point discoverable to any developer working in
this repository via the "Operation Checklists" section of `CLAUDE.md`.

**Steps:**

1. Open `CLAUDE.md` and locate the "Operation Checklists" section.
2. Find an appropriate insertion point — after the "Adding a schedule-entries class" entry
   (the last entry in the checklists section, per the current CLAUDE.md).
3. Insert the following checklist block:

   ```markdown
   **Adding a Bimaaji graph section provider:**
   1. Create a class implementing `GraphSectionProviderInterface` in your package's
      `src/Bimaaji/` directory (e.g. `packages/foo/src/Bimaaji/FooGraphSectionProvider.php`).
   2. Implement `getKey(): string` — return a unique snake_case key (e.g. `'foo'`).
   3. Implement `provide(): GraphSection` — return the section data for your domain.
   4. In your `ServiceProvider::register()`, add:
      ```php
      $this->singleton(FooGraphSectionProvider::class, FooGraphSectionProvider::class);
      $this->tag(FooGraphSectionProvider::class, \Waaseyaa\Bimaaji\BimaajiServiceProvider::TAG);
      ```
   5. Run `bin/waaseyaa optimize:manifest` (or restart dev server).
   6. Verify with `bin/waaseyaa graph:dump` — your section key should appear.
   ```

4. Confirm the checklist is immediately after the "Adding a schedule-entries class" block
   (or at the end of the Operation Checklists section) — do not insert it in the middle
   of another checklist.

**Files touched:**
- `CLAUDE.md` — edit, Operation Checklists section

**Validation:**
- `grep -n "Adding a Bimaaji" CLAUDE.md` returns a result.
- `grep -n "BimaajiServiceProvider::TAG" CLAUDE.md` returns a result.
- CLAUDE.md passes a basic lint (no broken markdown fences, no tab/space mix).

---

### T019 — Add CHANGELOG `[Unreleased]` entry

**Purpose:** Record the bimaaji wakeup changes in `CHANGELOG.md` under `[Unreleased]`
per the project's release workflow (only `[Unreleased]` is edited; `release-cut.yml`
promotes it to the version heading at tag time).

**Steps:**

1. Open `CHANGELOG.md` and locate the `## [Unreleased]` section.
2. Under the appropriate sub-heading (`### Added` preferred for new features), add:

   ```markdown
   - **bimaaji:** `BimaajiServiceProvider` wires `ApplicationGraphGenerator` with the
     6 default `GraphSectionProviderInterface` implementations as a tagged service
     collection; `bin/waaseyaa graph:dump` CLI command with `--section`, `--format`,
     and `--strict` flags; integration tests in `tests/Integration/PhaseN/Bimaaji/`
     (mission `bimaaji-wakeup-01KS5VEY`).
   ```

3. If a `### Changed` section is more appropriate (the package existed but was unwired),
   use that instead. Either is acceptable — use the sub-heading that best describes the
   semantic change to a package consumer.

**Files touched:**
- `CHANGELOG.md` — edit `[Unreleased]` section

**Validation:**
- `grep -n "bimaaji-wakeup-01KS5VEY\|BimaajiServiceProvider\|graph:dump" CHANGELOG.md`
  returns results under `[Unreleased]`.
- The `[Unreleased]` heading is not changed to a version number (that is `release-cut.yml`'s job).

---

## Test strategy

This WP has no automated tests. Validation is:

- `grep` checks that stale content is removed from README and spec.
- Drift detector passes for `docs/specs/bimaaji.md`.
- PR reviewer confirms CLAUDE.md checklist is clear and accurate.
- `composer verify` still exits 0 (documentation edits should not affect code quality gates).

## Definition of Done

- [ ] `packages/bimaaji/README.md` contains no "scaffolding", "placeholder", "will land",
  or "deferred" language (SC-004, FR-007).
- [ ] README table lists all 6 default providers with correct keys and class names (FR-007).
- [ ] README documents `BimaajiServiceProvider::TAG` for third-party extension (FR-007, SC-003).
- [ ] `docs/specs/bimaaji.md` Implementation Status says "Shipped" and references
  mission `bimaaji-wakeup-01KS5VEY` (FR-008).
- [ ] `docs/specs/bimaaji.md` has a drift-review stamp.
- [ ] `CLAUDE.md` Operation Checklists includes "Adding a Bimaaji graph section provider"
  with 6-step checklist.
- [ ] `CHANGELOG.md` `[Unreleased]` section has a bimaaji entry with the mission ID.
- [ ] `composer verify` exits 0 after all doc edits.

## Risks and notes

- **Provider class names:** The README table is only useful if the class names exactly
  match what exists. T016 includes a `ls packages/bimaaji/src/Provider/` step to verify.
- **Drift detector stamp:** Without the stamp in `docs/specs/bimaaji.md`, CI may flag the
  spec as stale if the drift detector runs after this mission. T017 adds the stamp.
- **CLAUDE.md merge conflicts:** CLAUDE.md is edited by many missions. If WP04 runs on a
  stale branch, check `git diff main CLAUDE.md` before editing to avoid clobbering recent
  additions in the Operation Checklists section.

## Reviewer guidance

The opus reviewer should check:

1. **Accuracy of 6-provider table** — Every key/class pair in the README and spec must
   match the actual `getKey()` return values in the provider source files. One mismatch
   misleads all future consumers.
2. **TAG stability** — The README and CLAUDE.md checklist show `BimaajiServiceProvider::TAG`.
   Confirm it matches what WP01 defined (`'bimaaji.graph_section_providers'`).
3. **CLAUDE.md placement** — The new checklist must be in the "Operation Checklists"
   section, not in the "Architecture Gotchas" or layer architecture tables. Wrong placement
   makes it harder to find.
4. **CHANGELOG sub-heading** — Confirm `### Added` or `### Changed` is the correct
   sub-heading. Do not add a new `## [Unreleased]` heading — one already exists.
5. **Drift stamp format** — Must be `<!-- Spec reviewed YYYY-MM-DD - reason -->` to be
   parsed correctly by the drift detector. Missing or malformed stamp causes future false-positives.
