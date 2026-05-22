---
work_package_id: WP01
title: Source content audit + spec scaffold
dependencies: []
requirement_refs:
- FR-004
- FR-010
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T001
- T002
history: []
authoritative_surface: skills/waaseyaa/
execution_mode: code_change
owned_files:
- skills/waaseyaa/
- docs/specs/bimaaji-install.md
tags: []
---

## Objective

Audit `skills/waaseyaa/*/SKILL.md` for frontmatter consistency. Normalize any drift so WP02's transformer logic can rely on a stable schema. Scaffold `docs/specs/bimaaji-install.md` with the spec skeleton (transformer contract, client-convention table, audit-trail steps for adding a new client).

## Subtasks

### T001 — Skill frontmatter audit

For each `skills/waaseyaa/*/SKILL.md`:

- Confirm YAML frontmatter has required keys: `name` (string), `description` (string), `triggers` (list of strings or absent — both acceptable).
- Confirm body is markdown with at least one `## ` heading.
- Confirm file is ≤ 8KB (rough upper bound — clients like Cursor have token-budget concerns).

If any file is missing required fields, edit it. Document the canonical schema in `docs/specs/bimaaji-install.md` once verified.

If files are over 8KB, note in the spec — single-file clients (Cursor, Copilot) may need a truncation or summary strategy in WP02.

### T002 — `docs/specs/bimaaji-install.md` skeleton

Create the doctrine spec with these sections (content filled in across WP02-WP05):

1. **Overview** — what the install command does and why
2. **Supported clients** — table of the 7 launch clients with target paths (filled in WP02)
3. **Transformer contract** — `ClientTransformerInterface` definition + integration (filled in WP02)
4. **Source schema** — SKILL.md frontmatter + body convention (filled in T001 above)
5. **Flag semantics** — `--client`, `--features`, `--force`, `--dry-run` (filled in WP03)
6. **Interactive UX** — prompt format, choices, non-TTY behavior (filled in WP03)
7. **Adding a new client** — step-by-step extension guide (filled in WP05)
8. **Trust contract** — no overwrites without consent, no network, sandbox discipline (per C-002, C-004, NFR-002)

End the file with the `<!-- Spec reviewed YYYY-MM-DD — bimaaji-install-command-01KS5W0S -->` stamp.

## Definition of Done

- [ ] All `skills/waaseyaa/*/SKILL.md` files have consistent frontmatter (audit pass).
- [ ] `docs/specs/bimaaji-install.md` exists with the 8 sections scaffolded.
- [ ] `tools/drift-detector.sh` doesn't complain about the new spec (the stamp resolves it).
- [ ] All local gates clean.

## Risks and notes

- **Skill content stability:** Per C-003, the install command does not paraphrase skill content. WP01's audit can normalize *frontmatter* (structural metadata) but must not rewrite skill *body* content.
- **The 8KB upper bound** is a soft heuristic — single-file clients may have a stricter budget. Re-verify in WP02 against each client's documented token limits.
