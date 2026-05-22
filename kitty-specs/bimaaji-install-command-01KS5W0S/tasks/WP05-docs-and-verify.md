---
work_package_id: WP05
title: Docs + verify
dependencies:
- WP04
requirement_refs:
- FR-010
- FR-013
- SC-006
- C-005
- C-006
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T016
- T017
history: []
authoritative_surface: kitty-specs/bimaaji-install-command-01KS5W0S/
execution_mode: planning_artifact
owned_files:
- docs/specs/bimaaji-install.md
- packages/bimaaji/README.md
- CHANGELOG.md
- kitty-specs/bimaaji-install-command-01KS5W0S/verification.md
tags: []
---

## Objective

Complete the doctrine spec, edit the bimaaji README, write CHANGELOG, and produce the verification log.

## Subtasks

### T016 — Spec + README + CHANGELOG

Fill in the remaining sections of `docs/specs/bimaaji-install.md` (WP01 scaffold):

- §"Flag semantics" — `--client`, `--features`, `--force`, `--dry-run` behaviors.
- §"Interactive UX" — prompt format, choices, non-TTY behavior, exit codes.
- §"Adding a new client" — step-by-step extension guide:
  1. Implement `ClientTransformerInterface` in `packages/bimaaji/src/Install/Client/<NewClient>ClientTransformer.php`.
  2. Add a unit test mirroring the existing per-client tests.
  3. Add a row to the §"Supported clients" table with the target path + convention citation.
  4. Add a row to the `InstallCommandTest` `#[DataProvider]`.
  5. Update CHANGELOG.

Update the `<!-- Spec reviewed ... -->` stamp.

`packages/bimaaji/README.md`: add an "Installing guidelines / skills" section. One paragraph: what the command does, link to spec.

`CHANGELOG.md` `[Unreleased]`: one bullet for the install command + the 7 launch clients.

### T017 — Verification log

`kitty-specs/bimaaji-install-command-01KS5W0S/verification.md`:

- Local gate sweep (cs-check, phpstan, layer, composer-policy, dead-code, getQuery, full `composer verify` exit code).
- Test surface (unit: 7 transformer tests; integration: 5 tests). Total assertion count.
- Per-client convention citations (the URLs each transformer's docblock cites + the date verified during WP02).
- Manual smoke (optional but recommended): on a fresh consumer project, run `bimaaji:install --client=claude --dry-run`, capture stdout. Then `--force`, verify files appear, open Claude Code, confirm guidelines load.
- Mission PR provenance.

## Definition of Done

- [ ] `docs/specs/bimaaji-install.md` covers all 8 sections from WP01 scaffold.
- [ ] `packages/bimaaji/README.md` has the install section.
- [ ] CHANGELOG `[Unreleased]` bullet exists.
- [ ] `verification.md` records all gate exit codes, test counts, convention citations.
- [ ] `composer verify` exits 0 on the mission branch tip.
- [ ] No `--no-verify` on any mission commit (C-006).

## Risks and notes

- **Spec stamp date:** Match the commit date, not the planning date. drift-detector cares.
- **README placement:** The new section goes near the top of `packages/bimaaji/README.md` (between any existing "Quick start" and feature sections) so it's visible to new users.
- **CHANGELOG dedupe:** If the release-cut sweeps `[Unreleased]` before this PR merges, the bullet lands under the new version heading — fine. Otherwise it stays under `[Unreleased]` until the next release.
