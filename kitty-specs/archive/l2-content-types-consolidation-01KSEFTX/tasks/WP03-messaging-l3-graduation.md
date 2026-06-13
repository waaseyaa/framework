---
work_package_id: "WP03"
title: "Messaging → L3 graduation (layer move + spec + CHANGELOG)"
dependencies: ["WP01"]
requirement_refs:
  - "FR-004"
  - "FR-005"
  - "FR-006"
  - "FR-007"
  - "FR-008"
  - "FR-009"
  - "NFR-001"
  - "NFR-002"
  - "C-001"
  - "C-004"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. WP03 depends on WP01 (the audit captures messaging's pre-graduation state for the historical record). May run in parallel with WP02 by source coupling, but the conservative ordering is post-WP01."
subtasks:
  - "T007"
  - "T008"
  - "T009"
  - "T010"
phase: "Phase 3 - Architectural change"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "packages/messaging"
execution_mode: "code_change"
owned_files:
  - "CLAUDE.md"
  - "bin/check-package-layers"
  - "packages/messaging/README.md"
  - "packages/messaging/composer.json"
  - "docs/specs/messaging.md"
  - "CHANGELOG.md"
history: []
---

# WP03 — Messaging → L3 graduation

**Mission:** `l2-content-types-consolidation-01KSEFTX`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd <path printed by `spec-kitty agent action implement WP03`>
```

This is the only code-bearing WP in this mission. The change is small in physical diff (5 files) but architecturally weighty (a layer move).

## Read first

- `CLAUDE.md` — the layer-architecture table + the orchestration table.
- `bin/check-package-layers` — the in-script layer map (PHP/shell associative array).
- `packages/messaging/README.md` — the current layer attribution.
- `packages/routing/src/AuthOidcRouteServiceProvider.php` — the canonical pattern for an L1→L4 route lift, in case messaging needs a similar `MessagingRouteServiceProvider`.
- `rg -l 'waaseyaa/messaging' packages/*/composer.json` — who currently requires messaging? Each result tells you the existing layer of the consumer; L4+ consumers stay clean post-graduation, L0-L2 consumers become violations.

## Subtasks

**T007 — CLAUDE.md layer table + orchestration table (FR-004, FR-008)**

Update the layer-architecture table per `../plan.md` §3.1:
- L2 row: remove `messaging`.
- L3 row: add `messaging` (after `listing`, per insertion-order convention).

Update the orchestration table: change (or add) the row for `packages/messaging/*` to point at `docs/specs/messaging.md` (created in T010).

**T008 — bin/check-package-layers (FR-005, NFR-002)**

Read `bin/check-package-layers`. Find the layer map (likely a PHP associative array `$layer_map = ['messaging' => 2, ...]`). Change `messaging` from `2` to `3`.

Run the script:

```
bin/check-package-layers
```

If it errors with a layer violation, the error message names the violating package. Resolve per the decision tree in `../plan.md` §3.2:
- L4+ consumer → no action; the violation is a false positive (verify by reading the script output).
- L0-L2 consumer → the dependency is a smell. Document in the WP03 report; either remove the consumer's require (if it was an unused require) or file a follow-up mission. Do not paper over the violation by reverting the layer change.

The acceptance condition is that the gate runs green against the new layer map.

**T009 — packages/messaging README + composer.json (FR-006, NFR-001, C-001)**

Read `packages/messaging/README.md`. Change `**Layer 2 — Content Types**` to `**Layer 3 — Services**`. After the existing class summary paragraph, append the verbatim "Why L3" paragraph from `../plan.md` §3.3.

`packages/messaging/composer.json` — read first. If the description field already mentions chat-substrate or L3, leave alone. Otherwise update to a description that reflects the L3 framing (e.g., `"description": "Direct-messaging service substrate for Waaseyaa (L3) — threads, messages, participants, per-thread access policies. Foundation for the framework's chat surface."`). **Do not touch `require`, `require-dev`, `autoload`, or `extra`.** C-001 + NFR-001 forbid source changes.

**Verify** `git diff packages/messaging/src` and `git diff packages/messaging/tests` are both empty.

**T010 — docs/specs/messaging.md + CHANGELOG (FR-007, FR-009)**

Create `docs/specs/messaging.md` with the verbatim content from `../plan.md` §3.4. Substitute `YYYY-MM-DD` in the stamp line with `date -u +"%Y-%m-%d"`.

Append two entries to `CHANGELOG.md` `[Unreleased]`:
- Under `### Changed`: the messaging L3 graduation entry from `../plan.md` §3.5.
- Under `### Added`: the audit document entry from `../plan.md` §3.5.

## Verification gate (in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/messaging/tests/` — should still pass (no code changes).
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers` — green against the new layer map. **Paste the output in the WP report.**
5. `bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
6. `git diff packages/messaging/src packages/messaging/tests` — empty (C-001 + NFR-001).
7. `git diff CLAUDE.md` — L2 row no longer contains `messaging`; L3 row contains `messaging`; orchestration table updated.
8. `docs/specs/messaging.md` exists + stamped.

## Commit + handoff

- Commits (footer `Mission: l2-content-types-consolidation-01KSEFTX`):
  - `chore(layers): graduate waaseyaa/messaging from L2 to L3`
  - `docs(messaging): L3 graduation spec + README update`
  - `docs(changelog): record messaging L3 graduation + L2 audit`
- Then:
  ```
  spec-kitty agent tasks mark-status T007 T008 T009 T010 --status done --mission l2-content-types-consolidation-01KSEFTX
  spec-kitty agent tasks move-task WP03 --to for_review --mission l2-content-types-consolidation-01KSEFTX --note "messaging graduated to L3; layer gate green; new spec stamped; CHANGELOG updated."
  ```

## Report back with
1. Commit SHA(s).
2. Output of `bin/check-package-layers` (must be green).
3. Output of `rg -l 'waaseyaa/messaging' packages/*/composer.json` (the post-graduation consumer list).
4. Paste the post-edit L2 and L3 rows of CLAUDE.md's layer table.
5. Paste the post-edit `**Layer 3 — Services**` line + the Why-L3 paragraph from `packages/messaging/README.md`.
6. Paste the new `docs/specs/messaging.md` (or at least its first heading + stamp line + outline).
7. Paste the two new CHANGELOG entries.

## Activity Log
- 2026-05-25T06:19:55Z – unknown – approved
