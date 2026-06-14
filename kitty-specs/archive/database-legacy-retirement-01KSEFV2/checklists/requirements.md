# Specification Quality Checklist: Database-Legacy Retirement

## Content Quality

- [x] Mission scope is bounded: audit (WP01), migrate (WP02), dispose (WP03). No drift into Doctrine DBAL major bumps or `bin/check-getquery-bindings` baseline drive-to-zero.
- [x] Decision authority is explicit: DIR-003 governs; ELIMINATE is the default; RENAME is the codified fallback with explicit trigger conditions.
- [x] All file allowlists per WP are specified up front (or in the case of WP02 derived from the WP01 audit's (a) classification — that derivation is itself codified).
- [x] No timeline language; mission progresses via Spec Kitty state, not calendar.
- [x] No hedging — every FR is MUST or MUST NOT.

## Requirement Completeness

- [x] FRs 001–009 each verifiable by a grep, diff, file-existence check, or exit-code listed in Acceptance.
- [x] NFR-001 (no test changes beyond namespace edits), NFR-002 (`bin/check-getquery-bindings` exit 0 throughout), NFR-003 (no GH issue), NFR-004 (audit under `docs/audits/`), NFR-005 (bulk-edit guardrail mandatory) all observable.
- [x] C-001 (DIR-003 governance), C-002 (binding preservation), C-003 (PSR-4 namespace preserved), C-004 (ADR-007 citation) specify hard constraints.
- [x] Disposition decision criteria for ELIMINATE vs RENAME are codified — implementer cannot wander.
- [x] Bulk-edit blast radius (243 files) is recognised and gated by `spec-kitty-bulk-edit-classification` invocation.
- [x] External-consumer scan is mandatory (FR-001 + T004) — no silent breakage of Minoo / Claudriel.
- [x] Reviewer focus enumerates audit completeness / (b) scrutiny / binding preservation / DIR-003 citation / ADR coherence / breaking-change discipline.

## Filing Readiness

- [x] Mission scaffold materialised by `spec-kitty specify`.
- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01-*.md`, `tasks/WP02-*.md`, `tasks/WP03-*.md` all populated.
- [x] WPs match wps.yaml shape: id, title, deps, owned_files, authoritative_surface, execution_mode, requirement_refs, subtasks, prompt_file.
- [x] Plan blocks for the audit document shape, the (a)/(b)/(c) rubric, and both disposition paths (A and B) are byte-for-byte ready.
- [x] No [TBD] / placeholder content anywhere in spec/plan/tasks.
- [x] Implementer can act on the spec without coming back for questions: the disposition decision tree is fully specified.
- [x] Coordinates-with relationships explicit: `ocap-audit-log-substrate-01KSEFTF` (consumer of the post-retirement DB abstraction); M-B.1 baseline drive-to-zero (out-of-band sibling).
