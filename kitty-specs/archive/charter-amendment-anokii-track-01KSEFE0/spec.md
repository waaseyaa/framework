# Charter Amendment — Waaseyaa / Anokii Track Constitutional Commitments

**Mission:** `charter-amendment-anokii-track-01KSEFE0`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue (charter housekeeping). Records the constitutional commitments made during the 2026-05-24 spec-production session that produced the Wave 0 → Wave 3 mission cluster.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape. This mission has no code-change WPs — only documentation edits to `.kittify/charter/charter.md`.

## Why this mission exists

The 2026-05-24 spec-production run filed ~17 framework missions and an Anokii scaffolding mission, all of which assume five constitutional commitments that are not yet recorded in the project charter:

1. **Framework vs distribution distinction.** Waaseyaa is a framework (substrate anyone can build distributions on). Anokii is the first opinionated distribution (sovereign-workspace product for First Nations). The charter currently treats Waaseyaa as a singular product; downstream missions need the distinction codified so spec writers know what belongs in which repo.
2. **OCAP-by-architecture as constitutional non-negotiable.** Per-record AI access controls, AccessChecker injection into every `AgentToolInterface::execute()`, FieldAccessPolicyInterface in MCP serializers, and the unified OCAP audit log are the framework's defining product claim. The charter must forbid future "simplifications" that downgrade or remove this wiring without a charter amendment carrying explicit Nation-level governance justification.
3. **Two-axis entity-storage preservation.** The revisionable × translatable substrate is simultaneously the audit-trail substrate AND the Indigenous-language-data substrate. Refactors must preserve both axes; storage drivers that drop either are charter violations.
4. **Codified policy gates as trust substrate.** The `bin/check-*` gates are how Nations and downstream maintainers verify Waaseyaa is trustworthy without reading every line. Bypassing a gate requires a charter-exception with a removal date; removing a gate requires a charter amendment with explicit rationale citing what now provides the same guarantee.
5. **Standalone Nuxt SPA architectural bet + GPL-2.0-or-later license commitment.** Two architectural commitments settled during the session: the Nuxt 3 + Vue 3 + TypeScript admin SPA is the committed workspace UI (with `packages/inertia` demoted to optional/experimental), and GPL-2.0-or-later is codified as the framework's license — changes to either require the full amendment process.

These commitments are recorded in the answers to four `AskUserQuestion` calls during the 2026-05-24 session ("Anokii repo: scaffold first; SPA bet: Standalone Nuxt; License: stay GPL-2.0-or-later; Git: hard reset"). This mission ratifies them through the standard Amendment Process described in the existing charter, producing the canonical edited `.kittify/charter/charter.md`.

## Scope

### In scope

- Edit `.kittify/charter/charter.md` to add:
  - New section "## Framework vs Distribution Architecture" placed between "Branch Strategy" and "Governance Activation".
  - New directives DIR-004 through DIR-008 appended to "Project Directives", covering OCAP-by-architecture, two-axis storage preservation, codified policy gates, Nuxt SPA bet, and GPL-2.0-or-later license commitment.
  - New section "## Amendment History" placed immediately after "Amendment Process", with the 2026-05-24 row recording this amendment.
- Update the Generated timestamp at the top of the charter to reflect the amendment date (`Generated: 2026-04-27T04:26:37Z; Last amended: 2026-05-24T..`).
- Do NOT run `spec-kitty charter generate --from-interview --force` — the interview transcript at `.kittify/charter/interview/` predates these decisions. Hand-edit the file with the exact text in the plan.md and commit. (Future amendments may regenerate from interview if the interview is first updated.)

### Out of scope

- Updating `.kittify/charter/interview/` content (deferred — interview is historical record; not a blocker for this amendment).
- Updating CLAUDE.md to mirror charter changes (CLAUDE.md is session-hot; orchestration table needs no change for these commitments).
- Creating the Anokii repo or its charter (handled by `anokii-distribution-scaffold-*` mission).
- Adopting an SPA-bet research mission to revisit the Nuxt commitment (charter amendment ratifies the choice; future revisitation would be a separate amendment).
- Any code changes. This mission edits a single Markdown file.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | A new top-level `## Framework vs Distribution Architecture` section MUST exist in `.kittify/charter/charter.md`, placed immediately after the `## Branch Strategy` section and before `## Governance Activation`. Section text matches exactly the block in plan.md §1. |
| FR-002 | functional | Five new directives MUST be appended to `## Project Directives` as numbered items 4, 5, 6, 7, 8 — matching exactly the directive text in plan.md §2. Existing directives 1, 2, 3 MUST NOT be modified. |
| FR-003 | functional | A new top-level `## Amendment History` section MUST exist immediately after `## Amendment Process`. It contains a Markdown table with header `\| Date \| Amendment \| Authorization \|` and one row for 2026-05-24 recording this amendment. |
| FR-004 | functional | The "Generated:" line at the top of the charter MUST gain a `; Last amended: 2026-05-24T<HH:MM:SS>Z` suffix, preserving the original generation timestamp. Format: `Generated: 2026-04-27T04:26:37Z; Last amended: 2026-05-24T<HH:MM:SS>Z`. |
| FR-005 | functional | The directive text MUST include cross-references: DIR-004 references DIR-006 (gates) and the M-A5 mission slug `per-record-ai-access-flagship-*`; DIR-005 references `entity-storage` and `entity-storage-two-axis.md`; DIR-006 enumerates the current gate scripts by path; DIR-007 references `packages/admin` and `packages/inertia`; DIR-008 references `LICENSE.txt` and `composer.json` license fields. |
| FR-006 | functional | After the edit, `wc -l .kittify/charter/charter.md` MUST report a line count strictly greater than the pre-amendment count by exactly the sum of inserted lines documented in plan.md §3 (verification target). |
| NFR-001 | non-functional | The amendment edit MUST be a single atomic commit on `main` with message `docs(charter): ratify Waaseyaa/Anokii constitutional commitments (charter-amendment-anokii-track-01KSEFE0)`. |
| NFR-002 | non-functional | No `\| <!-- TODO -->` / `_TBD_` / placeholder content in any inserted block. Every clause is final text. |
| NFR-003 | non-functional | Markdown linting MUST pass on the edited charter (canonical reference: project's existing markdown standards as enforced by `bin/check-*` if present, otherwise rendering check via `glow` or equivalent). |
| C-001 | constraint | The mission produces ONE file change: `.kittify/charter/charter.md`. No other files modified. |
| C-002 | constraint | Amendment is purely additive: no existing charter content is rewritten, reworded, removed, or reordered except the `Generated:` line suffix (FR-004). |
| C-003 | constraint | The amendment MUST land before any of the Wave 1+ missions (`per-record-ai-access-flagship-*`, `anokii-distribution-scaffold-*`, and downstream substrate missions) merge — those missions reference DIR-004 through DIR-008 in their plans. Mission dependency `merge_target_branch: main` with downstream missions blocked-by this one. |

## Acceptance

- `cat .kittify/charter/charter.md \| grep -c "^## Framework vs Distribution Architecture"` returns `1`.
- `cat .kittify/charter/charter.md \| grep -c "^## Amendment History"` returns `1`.
- `awk '/^## Project Directives/,/^## Reference Index/' .kittify/charter/charter.md \| grep -cE "^[0-9]+\\. "` returns `8` (1..3 existing + 4..8 new).
- The "Generated:" line on disk matches the format in FR-004.
- Diff is purely additive (lines removed = 1 — the original `Generated:` line, replaced).
- Reviewer (Opus) can read the spec, the plan, and the diff and confirm every clause in the inserted blocks is justifiable by the strategic context, the 2026-05-24 AskUserQuestion answers, and the existing constitutional logic of the charter.

## Risks

- **Charter loses internal coherence.** Adding directives 4–8 alongside 1–3 must preserve the existing voice and authority gradient. Mitigation: WP01 prompt requires reading the entire existing charter before editing; reviewer specifically checks tone consistency.
- **DIR-006 enumerates gates that change over time.** Naming specific scripts (`bin/check-composer-policy`, etc.) creates a maintenance touchpoint. Mitigation: DIR-006 text uses "the `bin/check-*` family" as the canonical reference and lists current scripts as "(as of 2026-05-24)" — future additions/removals re-amend the charter.
- **Downstream missions reference DIR-IDs before they exist.** If a Wave 1+ mission merges first, its plan.md references DIR-005 with no DIR-005 in the charter. Mitigation: C-003 makes this mission blocking; the wave-organization document in `kitty-specs/_wave-plan.md` enforces the order.

## Decisions pre-resolved

- **All five constitutional commitments codified verbatim from plan.md.** No interpretation latitude for the implementer.
- **No interview regeneration.** Hand-edit only. Interview-based regeneration is deferred and explicitly out of scope.
- **Mission slug naming convention.** This mission ratifies the slug-naming convention used by all Wave 1+ missions (`<purpose>-<short-ULID>`); subsequent missions inherit it.

## Decisions deferred to implementer

- The exact `<HH:MM:SS>` UTC timestamp substituted into FR-004 and the Amendment-History row — use `date -u +"%H:%M:%S"` at edit time.
- The exact line numbers chosen for each insertion anchor — confirm in T001 by reading the current charter; if line numbers have drifted since plan.md was written, locate the documented anchor text (e.g., "takes no opinion on production deployment topology.") and insert relative to it.

Decision preference order (per the constitutional-decisions framework that applies to every Wave 1+ mission): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- No follow-up issue. Charter housekeeping is not a release item.
- If Russell later commissions an interview-based regeneration of the charter, the implementer of that regeneration must preserve DIR-004 through DIR-008 verbatim or re-amend.
