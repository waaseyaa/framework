# Specification Quality Checklist: Genealogy Package Extraction

## Content Quality

- [x] Mission scope is bounded to a metadata-only classification flip; no source-code touches.
- [x] Decision rationale explicitly grounded in charter directive DIR-004 (Framework vs Distribution).
- [x] All non-genealogy-spec edits are limited to files enumerated under C-001.
- [x] No "should" / "might" / "consider" hedging — every FR is MUST or MUST NOT.
- [x] No timeline language; mission progresses via Spec Kitty state, not calendar.

## Requirement Completeness

- [x] FRs 001–009 each verifiable by a grep, diff-stat, or composer/layer-guard exit code listed in Acceptance.
- [x] NFR-001 (no source-file touches), NFR-002 (composer + layer gates green), NFR-003 (no GH issue), NFR-004 (Generated header preserved) all observable.
- [x] C-001 (file allowlist), C-002 (no autoload/namespace changes), C-003 (mission blocks future distribution-extension extractions) specify hard constraints.
- [x] Pre-flight grep on `cms` / `core` / `full` (FR-007) explicitly gates WP01 — no silent metapackage edits possible.
- [x] Layer-guard reasoning (FR-008) recorded in the extraction-log entry, so a future regression is detectable.
- [x] Split.yml verification (FR-006) prevents accidental Packagist orphaning.
- [x] Reviewer focus enumerates tone / boundary fidelity / byte-precision / pre-flight grep presence / precedent capture.

## Filing Readiness

- [x] Mission scaffold materialised by `spec-kitty specify`.
- [x] `spec.md`, `plan.md`, `tasks.md`, `wps.yaml`, `tasks/WP01-*.md`, `tasks/WP02-*.md`, `tasks/WP03-*.md` all populated.
- [x] WPs match wps.yaml shape: id, title, deps, owned_files, authoritative_surface, execution_mode, requirement_refs, subtasks, prompt_file.
- [x] Plan blocks for composer description, spec banner, CLAUDE.md inserts, and extraction-log entry are byte-for-byte ready — implementer copies, does not improvise.
- [x] No [TBD] / placeholder content anywhere in spec/plan/tasks.
- [x] Implementer can act on the spec without coming back for questions.
- [x] Coordinates-with relationships explicit: `charter-amendment-anokii-track-01KSEFE0` (DIR-004 source); future Bimaaji / Minoo extraction missions (precedent recipient).
