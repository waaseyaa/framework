---
work_package_id: WP05
title: Release pipeline + extension docs
dependencies:
- WP04
requirement_refs:
- FR-015
- FR-016
- SC-004
- SC-005
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main.
subtasks:
- T019
- T020
- T021
history: []
authoritative_surface: .github/workflows/
execution_mode: code_change
owned_files:
- .github/workflows/split.yml
- CLAUDE.md
- CHANGELOG.md
- packages/agent-output/README.md
tags: []
---

## Objective

Wire the new package into the release pipeline (split.yml matrix → first split push → Packagist registration), update root docs, and verify the third-party formatter extension path documented in WP02 is real.

## Subtasks

### T019 — split.yml matrix entry (FR-015)

Edit `.github/workflows/split.yml`:

- Add `packages/agent-output` to the matrix.
- Confirm `bin/check-release-tag-parity` would not flag the new package as missing (it runs post-tag).
- Smoke-test by running the existing matrix's first job against a local clone (`act -W .github/workflows/split.yml -j split` or equivalent).

### T020 — Packagist registration handoff (FR-016)

Add to `packages/agent-output/README.md`:

- A "First release checklist" section documenting the three-step pattern from memory `feedback_new_package_release_checklist`:
  1. split.yml matrix entry (T019)
  2. GitHub repo provisioning: `gh repo create waaseyaa/agent-output --public`
  3. Packagist registration: submit at https://packagist.org/packages/submit using the GitHub repo URL **after** the first split push lands a tag on the new repo.

This is a manual handoff, not automated. Document the order explicitly — Packagist must be done after the first split push so it can resolve a real ref.

### T021 — Root-level docs

`CLAUDE.md` (root):
- Add a "Commands" entry for `--output=json` and `WAASEYAA_OUTPUT=json`.
- Mention the env-detection list (link to `docs/specs/agent-output.md`).

`CHANGELOG.md` `[Unreleased]`:
- One bullet describing the new package, the env-detection list, the 8 first-party formatters, and the token-reduction claim (forward reference to WP06 verification).

Smoke-test SC-005 by writing a one-off third-party formatter (live, not committed — a scratch file) following `docs/specs/agent-output.md`. Verify it registers and emits envelopes. Document the test in WP06's verification.md.

## Definition of Done

- [ ] `split.yml` matrix includes `packages/agent-output`.
- [ ] README's first-release checklist documents the three-step pattern.
- [ ] CLAUDE.md mentions the flag and env var.
- [ ] CHANGELOG `[Unreleased]` has one bullet.
- [ ] SC-005 third-party-formatter smoke test recorded in WP06 verification.md.

## Risks and notes

- **Per memory `feedback_release_split_pre_flight_gap`:** `bin/check-release-tag-parity` runs after the release tag is pushed, so a missing split.yml entry half-ships the release silently. WP05 lands the entry before any release tag.
- **Per memory `feedback_release_cut_sync_commit_bug`:** Pre-alpha.179 release-cut.yml discarded sync output. Confirm the current release-cut.yml stages CHANGELOG + sync output together (it does as of 4c27e4670). No action needed beyond awareness.
- **GitHub repo provisioning:** Cannot be automated within a PR; this is the handoff item. Document the exact `gh repo create` command in the README so the human reviewer can run it during merge.
