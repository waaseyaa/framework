---
work_package_id: WP02
title: .kittify init + Anokii distribution charter
dependencies:
- WP01
requirement_refs:
- FR-004
- FR-005
- C-001
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T007
- T008
- T009
agent: ''
history: []
authoritative_surface: .kittify/charter/charter.md
execution_mode: planning_artifact
mission_id: 01KSEFT768GN09JZXHWMAMJNFR
owned_files:
- .kittify/charter/charter.md
- .kittify/SPEC_KITTY_VERSION
tags: []
wp_code: WP02
---

# WP02 — `.kittify` init + Anokii distribution charter

**Mission:** `anokii-distribution-scaffold-01KSEFT7`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the Anokii repo

You are working in the Anokii repo clone (created by WP01), not in the Waaseyaa repo. `cd` to your local Anokii clone, branch from `main`, and commit there.

## THE pattern to mirror (read before writing anything)

- **Framework `.kittify/charter/charter.md`** — read it end-to-end. Your Anokii charter mirrors the structural shape (Preamble / Branch Strategy or Distribution Posture / Governance Activation / Project Directives / Reference Index / Amendment Process / Amendment History / Exception Policy) but its content is Anokii-specific.
- **`charter-amendment-anokii-track-01KSEFE0`** — its `plan.md` §1, §2, §3 contain the framework-side text codifying DIR-004..DIR-008. Anokii's DIR-A001..DIR-A005 mirror this register and reference DIR-004..DIR-008 by ID.

## Subtasks

**T007 — `spec-kitty init --here`.**
- From Anokii repo root: `spec-kitty init --here`.
- Verify created: `.kittify/charter/`, `.kittify/dashboard/` (or equivalent per current spec-kitty version), `.kittify/SPEC_KITTY_VERSION`, base mission lane directories. The skills symlink at `.kittify/skills/` is machine-specific — append it to `.gitignore` if `spec-kitty init` doesn't already.
- Commit: `chore: spec-kitty init --here (anokii-distribution-scaffold-01KSEFT7)`.

**T008 — Author `.kittify/charter/charter.md`.**
- Hand-author the entire file. Do NOT run `spec-kitty charter interview` or `spec-kitty charter generate --from-interview` — Anokii has no interview transcript and the charter content is pre-resolved.
- Sections (in order):
  1. **Top matter** — `Generated: <UTC ISO timestamp>`. Title line: `# Anokii Distribution Charter`.
  2. **Preamble** — paragraph defining Anokii (Anishinaabemowin verb stem "she/he works"; working name pending language-keeper verification before public use). Declares dependency on Waaseyaa via Packagist. Declares GPL-2.0-or-later. Declares the framework charter is upstream.
  3. **Distribution Posture** — Anokii consumes Waaseyaa; never modifies Waaseyaa from inside the Anokii repo; upstreams generally-useful work via separate framework-targeted missions filed against the Waaseyaa repo. Cross-references framework DIR-004 (Framework vs Distribution Architecture section).
  4. **Governance Activation** — Anokii operates under the framework charter (substrate concerns) AND this Anokii charter (product-surface concerns). Amendments to either are governed by their own amendment processes; some amendments may require coordination across both charters (e.g., a license change per DIR-008 / DIR-A004).
  5. **Anokii Project Directives** — numbered DIR-A001 through DIR-A005:
     - **DIR-A001 — AODA Level AA is a design constraint, not an optional feature.** Every Anokii v0.1 surface MUST meet WCAG 2.1 AA + AODA-specific procurement-legibility requirements. axe-core CI gate enforces baseline. Per-component a11y test in vitest + Playwright. Bypassing the baseline requires a `charter-exception` with a removal date.
     - **DIR-A002 — Offline-first is a design constraint, not an optional feature.** Every Anokii v0.1 surface MUST function in offline-degraded mode per the offline-first design (Dexie + Workbox + FSM sync engine composing on framework two-axis revisions per DIR-005). A surface that requires connectivity for read-after-write within the user's own classification scope is a charter violation.
     - **DIR-A003 — Indigenous-language translation pipeline is a product layer, not a configuration toggle.** Extraction tooling → `translation_string` entity (mirrors framework two-axis storage shape per DIR-005) → contributor dashboard → `translation_review` workflow → glossary entity → per-Nation override layer. Pilot: English ↔ Anishinaabemowin (southern + northern Ojibwe), 20–30 glossary terms co-authored with a language keeper. Pilot Nations: Pilot Nation A then Pilot Nation B; final selection deferred to language-keeper engagement.
     - **DIR-A004 — GPL-2.0-or-later license trajectory aligned with framework DIR-008.** Anokii is GPL-2.0-or-later because Waaseyaa is. Relicensing requires both a framework-charter amendment (per DIR-008) AND an Anokii-charter amendment.
     - **DIR-A005 — Product-surface OCAP-by-architecture commitments inherit framework DIR-004.** Anokii productivity surfaces MUST consume framework AccessChecker / FieldAccessPolicyInterface wiring; surface code never bypasses or weakens these. Per-record AI access (gap-matrix A5 flagship in framework — mission slug `per-record-ai-access-flagship-*`) extends through Anokii Co-Intelligence Workspaces verbatim. The unified OCAP audit log spans every Anokii surface.
  6. **Reference Index** — cross-references to framework charter sections + DIR-IDs + relevant Waaseyaa specs (`docs/specs/entity-storage-two-axis.md`, `docs/specs/access-control.md`, `docs/specs/admin-spa.md`).
  7. **Amendment Process** — mirrors framework amendment process structure: proposal → reviewer alignment → AskUserQuestion or equivalent author confirmation → atomic single-commit charter edit → Amendment History row.
  8. **Exception Policy** — `charter-exception` mechanism with mandatory removal date and rationale, matching framework pattern.
  9. **Amendment History** — empty table at scaffold time with the header row `| Date | Amendment | Authorization |`.
- Cross-reference the an existing tenant stewards channel as the Nation-level governance interface (referenced by DIR-A004 license-change amendment process, mirroring framework DIR-008's an existing tenant reference).
- Word budget: ~1500–2200 words total.

**T009 — Verify spec-kitty state.**
- `spec-kitty status` — green.
- `cat .kittify/charter/charter.md | grep -c "^## Anokii Project Directives"` → `1`.
- `grep -cE "^### DIR-A00[0-9]+ —|^DIR-A00[0-9]+ —" .kittify/charter/charter.md` → `≥ 5`.
- `grep -c "GPL-2.0-or-later" .kittify/charter/charter.md` → `≥ 2`.
- `grep -c "AODA Level AA" .kittify/charter/charter.md` → `≥ 2`.

## Commits

- `chore: spec-kitty init --here (anokii-distribution-scaffold-01KSEFT7)`
- `docs(charter): hand-author Anokii distribution charter — DIR-A001..DIR-A005 (anokii-distribution-scaffold-01KSEFT7)`

## Report back with

1. Commit SHAs in the Anokii repo.
2. `spec-kitty status` output.
3. Output of the four grep verification commands.
4. The final list of DIR-A IDs (in case any were renumbered or added during authoring) — WP04 references these.

## Activity Log

- 2026-05-25T05:09:42Z – unknown – Opus review: new repo waaseyaa/anokii live with composer + LICENSE + README + charter (DIR-A001..DIR-A005) + deploy + branded tokens + Pilot Nation A tenant stub. Repo currently public (consider toggling to private). 10 v0.1 surface seeds left in Waaseyaa artifacts/ for future Anokii-repo re-filing.
