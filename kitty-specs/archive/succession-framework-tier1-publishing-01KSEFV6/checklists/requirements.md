# Specification Quality Checklist: Succession Framework — Tier 1 Publishing

Mission: `succession-framework-tier1-publishing-01KSEFV6`. Verifies `spec.md` + `plan.md` + `tasks.md` + `wps.yaml` + the four `tasks/WP*.md` files meet the quality bar before WP dispatch.

## Content Quality

- [x] Mission scope (Tier 1 floor — four artifacts: MAINTAINERS.md, SUCCESSION.md, Packagist trustee, Nation-hosted mirror) is crisply bounded against Tiers 2/3/4 (deferred to post-v1.0 / funded-engagement / long-horizon missions).
- [x] Strategic context (`assumptions.md` §4, `design-succession-framework.md`) is cited in spec.md §"Why this mission exists" and the WP prompts.
- [x] DIR-006 (codified gates as trust substrate) is explicitly referenced; the mission operationalises that directive rather than amending the charter.
- [x] Framework-vs-distribution distinction (DIR-004 / spec.md scope) is honoured — this mission is framework-only; no Anokii or other-distribution content.
- [x] The implementer preference order (preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates) is encoded as C-005 and re-stated in WP03 and WP04 prompts at every choice point.

## Requirement Completeness

- [x] FR/NFR/C separated, IDs unique. 11 FRs, 5 NFRs, 5 Cs.
- [x] Every FR is testable via a grep, file-existence, or external-system verification listed in Acceptance.
- [x] NFR-001 (atomic commits with named messages), NFR-002 (no placeholder tokens at WP close), NFR-003 (charter-matching tone), NFR-004 (trustee additive, not transfer), NFR-005 (mirror read-only in steady state) are all observable.
- [x] C-001..C-005 specify hard constraints: file-count per WP, additive-only edits, no code changes, deferred-value selection by Russell, preference order.
- [x] Edge cases covered: trustee account becomes unavailable (NFR-004 inheritance posture); GitHub becomes unavailable (FR-006 recovery procedure); future charter renumbering (Risks §); README rewrite drops the pointer (Risks §); substrate inventory drifts (FR-003 date stamp + Risks §).
- [x] No "should" / "might" / "consider" hedging — all requirements are MUST / MUST NOT.

## Plan & WP Decomposition

- [x] Four WPs declared with explicit dependency shape: WP01 || WP02, then WP03 → WP01, then WP04 → WP01,WP03.
- [x] `MAINTAINERS.md` and `SUCCESSION.md` full content drafted verbatim in `plan.md` §1 and §2 — implementer applies, does not author from scratch (matches `charter-amendment-anokii-track-01KSEFE0/plan.md` pattern).
- [x] WP03 and WP04 each have a clearly split "operational" portion (external configuration) and "documentation" portion (MAINTAINERS.md substitution); both are required for WP close.
- [x] Marker tokens (`<<TRUSTEE_PACKAGIST_ACCOUNT>>`, `<<NATION_HOSTED_MIRROR_URL>>`) are introduced in WP01 and substituted in WP03 / WP04. The grep-for-zero-occurrences check at WP04 close enforces full substitution before mission close.
- [x] Verification gate per WP is concrete: file-existence checks, grep counts with exact expected values, manual-check items for external state (Packagist owner list, mirror URL resolution).
- [x] Each WP prompt includes a "Decisions deferred to you (with Russell)" section calling out the exact selection that requires author input, with selection criteria but no candidate names.

## Filing Readiness

- [x] No `<TBD>` / `TODO` content in any spec/plan/task file (marker tokens in plan.md §1 are intentional and documented).
- [x] No timeline language anywhere — "post-v1.0", "as adoption grows", "deferred to a future mission" instead of dates.
- [x] No questions to Russell that are not explicit "implementer chooses at execution time" deferrals.
- [x] Mission references the design doc and assumptions §4 by path so a reviewer can audit fidelity.
- [x] WP prompts include `cd` to lane worktree, `spec-kitty agent tasks` move-task command for handoff, and "Report back with" section so the implementer's output is structured for review.
- [x] Mission produces zero code changes; the four WPs are documentation + external configuration only (per C-003).
