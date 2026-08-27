# FW-STRUCTURED-XLSX-01 - bounded spreadsheet inspection and dry-run mapping

- Status: review
- Parent: `ee08759fa1016d25485fd51c3ee228bd2d16cf4d`
- Contract: `docs/specs/structured-import.md`
- Forge mirror: Framework #2593
- Upstream consumer: Anokii #25
- Downstream integrations: Sheg #200 and #204

## Intent

Extend `waaseyaa/structured-import` with a safe XLSX boundary and a generic,
deterministic mapping planner. The framework exposes workbook structure and an
explicit protected value channel, then classifies caller-supplied records as
create, update, unchanged, or conflict without persisting anything.

## Decisions

1. XLSX is treated as an untrusted ZIP/XML package. The inspector verifies an
   expected SHA-256 before opening and after closing the archive, validates the
   package content type and relationship graph, and enforces explicit archive,
   XML, sheet, row, column, cell, merge, and text ceilings.
2. DOCTYPE/entity declarations, encrypted entries, unsafe entry paths, macros,
   embedded objects, external links, external relationships, malformed XML,
   formulas, and source mutation are fail-closed typed refusals. Formula text,
   source paths, cell values, and package bytes never enter diagnostics.
   Aggregate XML object ceilings are enforced with a streaming preflight before
   DOM materialization; empty XML and invalid source ranges remain typed.
3. `inspect()` returns only structural metadata: sheet keys/names, declared and
   observed dimensions, merged ranges, connected populated regions, and cell
   coordinates/types/date flags. Its JSON and debug forms contain no values.
4. Values require a second explicit `readProtectedSelection()` call bound to
   the inspection checksum. Rectangular ranges and sparse coordinates can be
   selected; ignored areas are declared alongside them. Protected cells redact
   JSON, debug, and serialization surfaces and expose the typed value only via
   an explicit accessor.
   Styled blank cells are excluded, and out-of-range integers are refused rather
   than silently clamped.
5. Dates remain inert source values. The inspector reports the workbook date
   system and flags date-formatted cells; it does not apply locale or timezone
   policy and does not guess a destination representation.
6. Mapping records require a non-empty stable identity supplied by the caller.
   The planner never derives identity from headers, coordinates, names, or
   field values. Explicit mappings and ignored source keys are disjoint.
7. Plans expose only identity/record hashes, decision, changed field names, and
   stable conflict codes. The digest is a domain-separated SHA-256 over the
   source checksum, normalized mapping definition, protected source/target
   snapshots, and decisions. Input ordering cannot change plan bytes.
8. Duplicate source identities, duplicate target identities, unmapped source
   fields, and caller-declared unique-field collisions are represented as
   conflicts. Planning performs zero writes and invokes no application storage.
9. Existing `StructuredImporterInterface` and GFM behavior are unchanged.

## Work packages

1. Contract/spec, typed value objects, limits, and redacted failures.
2. Safe OOXML package inspection with synthetic fixture coverage.
3. Protected rectangular/sparse selection with checksum revalidation.
4. Deterministic dry-run mapping plan and conflict coverage.
5. Provider wiring, compatibility proof, package and repository gates.

## Verification target

- Synthetic fixtures cover shared and inline strings, numeric/date styles,
  sparse and merged cells, multiple populated regions, formulas, external
  relationships, malformed XML, excessive compression, and source mutation.
- Exception messages, logger records, JSON, debug, and serialization tests prove
  fixture values and paths are absent.
- Reordered equivalent mapping inputs produce the same checksum-bound digest.
- Duplicate identity and unique-field conflicts produce no persistence calls.
- Existing GFM package tests and all split repository suites remain green.

Verified on candidate `de6a8cb9540023a20a21301e0be93f4f2874e62a` before
the final documentation-only seal: Unit 12,455 / 234,530; Integration 2,195 /
10,657; Architecture 396 / 25,871 with one governed skip; structured-import
65 / 210; and full preflight 37 / 37.

## Boundaries

No upload handling, temporary-file lifecycle, Drive orchestration, spreadsheet
editing UI, classification policy, application entity mapping, persistence,
release, split publication, or deployment is authorized by this change record.
