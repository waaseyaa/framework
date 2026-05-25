---
work_package_id: "WP02"
title: "File per-package follow-up missions (harden + remove scaffolds)"
dependencies: ["WP01"]
requirement_refs:
  - "FR-002"
  - "FR-003"
  - "C-005"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. WP02 depends on WP01 (the audit determines which packages need follow-up missions filed). May run in parallel with WP03 (messaging→L3 is an independent edit)."
subtasks:
  - "T004"
  - "T005"
  - "T006"
phase: "Phase 2 - Follow-ups"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "kitty-specs/"
execution_mode: "documentation"
owned_files:
  - "docs/audits/2026-05-l2-content-types-audit.md"
history: []
---

# WP02 — File per-package follow-up missions

**Mission:** `l2-content-types-consolidation-01KSEFTX`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Subtasks

**T004 — File harden scaffolds for alpha packages (FR-002, C-005)**

For each L2 package classified as **alpha — needs hardening** in the audit:

```
spec-kitty specify l2-harden-<package>-<short-ULID>
```

(Use the package's bare name — e.g. for `relationship` the slug is `l2-harden-relationship-<ULID>`.)

In the newly-created `kitty-specs/l2-harden-<package>-<ULID>/spec.md`, write a stub per the template in `../plan.md` §2 "Stub shape for `l2-harden-<package>-*`":

- Mission title + status (stub)
- Parent mission cross-reference (this mission slug + the audit path)
- Hardening scope (paste the alpha-classification rationale from the audit)
- Suggested WPs (one-line each, derived from the rationale's identified gaps)
- Pre-resolved decisions (package stays at L2; follow M5A pattern for new API surface)
- "To be specified in implement-review" note (the real WPs land in this new mission's own /spec-kitty.plan + /spec-kitty.tasks invocations).

Do NOT write full WP prompt files for the new mission. WP02 fills only the stub.

**T005 — File remove scaffolds for dead packages (FR-003, C-005)**

For each L2 package classified as **dead — propose removal** in the audit:

```
spec-kitty specify l2-remove-<package>-<short-ULID>
```

In the new mission's spec.md, write a stub per the template in `../plan.md` §2 "Stub shape for `l2-remove-<package>-*`":

- Removal rationale (paste from the audit)
- Charter-bearing context (decision-preference order; no consumer breakage; gates remain green)
- Suggested WPs (consumer audit → removal + manifest updates → acceptance gate + CHANGELOG)
- "To be specified in implement-review" note.

The dead-classified package's removal is a separate charter-bearing decision per the codified-policy-gate sensitivity. This mission only files the proposal.

**T006 — Update the audit + spec.md Out-of-band (FR-002, FR-003)**

In `docs/audits/2026-05-l2-content-types-audit.md`:
- Update the "Follow-up mission" column for every needs-hardening + dead-proposed row with the newly-filed slug.
- The Summary table at the bottom should reflect the same slugs in its "Follow-up mission" column.

In `../spec.md`:
- Replace the **Out-of-band** placeholder list with the real list. Format: `- <slug> — <one-sentence summary of the hardening or removal scope>`. If WP01 found zero needs-hardening and zero dead packages, write: `No follow-up missions filed; every L2 package is production-ready per the audit.`

## Verification gate

1. Every alpha-classified row in the audit has a populated "Follow-up mission" slug.
2. Every dead-classified row in the audit has a populated "Follow-up mission" slug.
3. `ls kitty-specs/l2-harden-*/spec.md` returns one path per alpha row.
4. `ls kitty-specs/l2-remove-*/spec.md` returns one path per dead row.
5. Each new mission's spec.md is a stub (one section + suggested WPs), not a fully-fleshed spec (C-005).
6. `../spec.md` Out-of-band is post-edited with the real list.

## Commit + handoff

- Commits (footer `Mission: l2-content-types-consolidation-01KSEFTX`):
  - `spec-kitty: file follow-up missions for L2 needs-hardening packages`
  - `spec-kitty: file follow-up missions for L2 dead-package removal proposals` (if any)
  - `docs(audits): populate L2 audit follow-up slugs`
- Then:
  ```
  spec-kitty agent tasks mark-status T004 T005 T006 --status done --mission l2-content-types-consolidation-01KSEFTX
  spec-kitty agent tasks move-task WP02 --to for_review --mission l2-content-types-consolidation-01KSEFTX --note "<N> harden + <M> remove missions filed; audit + spec.md Out-of-band updated."
  ```

## Report back with
1. Commit SHA(s).
2. The list of newly-filed mission slugs (harden + remove).
3. Confirmation that each new mission's spec.md is a stub (paste one as a sample).
4. The post-edited Out-of-band section.

## Activity Log
- 2026-05-25T06:19:52Z – unknown – approved
