# Work Packages: charter-amendment-anokii-track-01KSEFE0

**Mission:** Charter amendment ratifying the Waaseyaa/Anokii track constitutional commitments. See `spec.md` and `plan.md`.

One WP. No parallelism. This is documentation housekeeping that unblocks all Wave 1+ missions.

## Work Package WP01: Apply charter amendment

**Owns:** `.kittify/charter/charter.md` (the only file modified).
**Depends on:** none.
**Blocks:** all Wave 1+ missions whose plans reference DIR-004..DIR-008 (`per-record-ai-access-flagship-*`, `anokii-distribution-scaffold-*`, all framework substrate missions).
**Authoritative surface:** `.kittify/charter/charter.md`.
**Execution mode:** `documentation`.
**Requirement refs:** FR-001, FR-002, FR-003, FR-004, FR-005, FR-006, NFR-001, NFR-002, NFR-003, C-001, C-002, C-003.

### Subtasks

- [x] T001 — Read the existing `.kittify/charter/charter.md` end-to-end (~400 lines). Identify the exact insertion anchors named in `plan.md` (end of Branch Strategy; end of Project Directives directive 3; end of Amendment Process; the `Generated:` line). Confirm line numbers in current working copy.
- [x] T002 — Insert the `## Framework vs Distribution Architecture` block at the anchor identified in T001 (between Branch Strategy and Governance Activation). Use `plan.md` §1 text verbatim.
- [x] T003 — Append directives 4 through 8 to `## Project Directives` after the existing directive 3. Use `plan.md` §2 text verbatim. Each directive's numbered list-item formatting MUST match the existing 1/2/3 style.
- [x] T004 — Insert the `## Amendment History` block after the `## Amendment Process` section. Use `plan.md` §3 text verbatim, substituting the actual amendment-time UTC ISO timestamp.
- [x] T005 — Edit the `Generated:` line per `plan.md` §4, substituting the actual amendment-time UTC ISO timestamp.
- [x] T006 — Run the verification grep checks listed in `plan.md` §Verification gate. All five MUST pass. If any fails, fix and re-verify before moving the WP to for_review.
- [x] T007 — Commit via `git add .kittify/charter/charter.md && git commit -m "docs(charter): ratify Waaseyaa/Anokii constitutional commitments (charter-amendment-anokii-track-01KSEFE0)"`. Single atomic commit. NO other files staged.

### Acceptance

- All seven grep verifications pass.
- `git diff HEAD~1 .kittify/charter/charter.md` shows the diff is purely additive except the one-line `Generated:` replacement.
- Reviewer (Opus) confirms tone consistency, cross-reference accuracy, and byte-for-byte match with `plan.md` blocks.

### Commit + handoff

After T007, move the WP to for_review:

```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T001 T002 T003 T004 T005 T006 T007 --status done --mission charter-amendment-anokii-track-01KSEFE0
spec-kitty agent tasks move-task WP01 --to for_review --mission charter-amendment-anokii-track-01KSEFE0 --note "Charter amendment applied; all grep verifications pass."
```

After approval and merge, Wave 1 missions (`per-record-ai-access-flagship-*`, `anokii-distribution-scaffold-*`) are unblocked.
