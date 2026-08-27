# Structured import

## Scope

`waaseyaa/structured-import` owns format parsing, bounded spreadsheet
inspection, explicit protected value extraction, and deterministic dry-run
mapping plans. It does not own upload transport, temporary-file custody,
application entities, persistence, domain conflict policy, or user interfaces.

The original `StructuredImporterInterface` remains the GFM two-column-table
contract described by `docs/specs/work-surface.md` F5. XLSX is a separate
two-phase API because binary workbook inspection, protected values, and
multi-record planning do not fit the single-record GFM result shape.

## XLSX trust boundary

`XlsxWorkbookInspector::inspect()` requires a local source path and the exact
expected lowercase SHA-256. The path is a sensitive parameter and is never
included in an exception or log record. The inspector hashes the source before
opening it and after parsing/closing it. A mismatch at either boundary refuses
the operation.

An XLSX source is accepted only when all of these conditions hold:

- the file has ZIP/XLSX MIME and magic, and contains the required OOXML package
  parts with the ordinary non-macro workbook content type;
- archive names are unique and confined, entries are unencrypted, and configured
  entry-count, compressed-size, uncompressed-size, and compression-ratio limits
  hold both per entry and in aggregate;
- XML parts fit the configured byte ceiling, contain no DOCTYPE or entity
  declarations/references, parse without errors under `LIBXML_NONET`, and fit
  the configured depth ceiling;
- the relationship graph contains no `TargetMode="External"`, external-link,
  macro, embedded-object, network-path, or path-escaping target;
- workbook, sheet, row, column, populated-cell, merged-range, shared-string, and
  text-length limits hold;
- no worksheet cell contains a formula.

Cell, merged-range, and shared-string totals are counted with a streaming XML
preflight before any workbook part is materialized as a DOM tree. Default XML
and object-count ceilings are chosen to remain below the package memory budget;
raising them is an explicit host capacity decision.

Formula cached results are not trusted or surfaced. Formula presence is a typed
refusal and formula text never appears in diagnostics. External relationships
are also typed refusals, including hyperlinks or other OOXML relationships that
leave the package.

## Structural inspection

Inspection returns `XlsxWorkbookInspection`, a value-free, JSON-safe result:

- exact source SHA-256 and the 1900 or 1904 workbook date system;
- deterministic sheet keys, sheet names, declared/observed dimensions, and
  counts;
- merged ranges;
- connected populated regions with deterministic region ids and bounds;
- populated cell coordinate, scalar type, and date-format flag.

Sheet names are structural workbook metadata. Cell values, inline/shared text,
formula text, source paths, and source bytes are absent. Result ordering follows
workbook order and row-major cell order.

## Protected selection

`readProtectedSelection()` is the only value-bearing API. It requires the prior
inspection plus an `XlsxSelection` containing a stable selection id, selected
areas, and optional ignored areas. An area names a deterministic sheet key and
may combine rectangular A1 ranges with sparse A1 coordinates. Selected and
ignored cells may not overlap.

The source is reparsed under the same limits and exact checksum. Only selected,
populated cells are returned; styled cells with no scalar value are not
populated and cannot connect regions. A protected cell carries its sheet key,
coordinate, type, date flag, and typed scalar/null value. JSON/debug surfaces
replace the value with `[REDACTED]`; string casting and serialization are
refused. Callers must invoke `value()` deliberately inside their own protected
processing boundary. Every selected or ignored sheet key must exist in the
reparsed workbook; unknown keys are a typed refusal rather than an empty result.

Dates remain the inert OOXML scalar plus `isDate=true`; destination timezone,
locale, and type conversion belong to the caller.
Integer source values outside the PHP integer range are refused rather than
silently clamped or converted through a lossy float.

## Dry-run mapping plans

`MappingDryRunPlanner` accepts:

- the exact 64-character source checksum;
- a stable `MappingDefinition` containing source-to-target field mappings,
  explicitly ignored source keys, and caller-declared unique target fields;
- source records with caller-supplied stable identities and protected scalar
  field maps;
- current target records with caller-supplied identities, record ids, and
  protected scalar field maps.

The framework never guesses identity. A record without a supplied identity is
invalid input. Input maps may contain only scalar/null values; non-finite floats
and invalid UTF-8 are refused before hashing.

Each source record becomes exactly one plan entry:

- `create`: no target with the same identity and no conflict;
- `update`: one target with the identity and at least one mapped field differs;
- `unchanged`: one target with the identity and every present mapped field is
  strictly equal;
- `conflict`: duplicate source identity, duplicate target identity, unmapped
  source field, or a collision on a declared unique target field.

A duplicate target identity is represented when it collides with a source
record in the requested plan. Target-only rows are comparison context, not
actions, and do not create source-less plan entries.

Plan entries expose only SHA-256 identity/record references, changed field
names, and stable conflict codes. Values and raw identities are absent from
JSON, debug output, exception messages, and log records. Planning has no
persistence dependency and performs no writes.

The plan digest is SHA-256 over a versioned, domain-separated canonical form of
the source checksum, normalized definition, protected source/target snapshots,
and normalized decisions. Map keys are byte-sorted and semantic lists are
sorted, so equivalent inputs produce identical plans regardless of caller
iteration order. Any source checksum, mapping, identity, value, target snapshot,
or decision change changes the digest.

## Errors and diagnostics

XLSX refusals use `XlsxInspectionException` plus a stable
`XlsxInspectionError` code. Messages identify only the violated rule. Optional
logger records contain the code and bounded non-sensitive counts only. Raw
library/parser diagnostics are never forwarded because they may contain source
text or paths.

Mapping validation uses stable generic exceptions for malformed caller input;
data conflicts remain plan entries rather than exceptions.

## Compatibility

The GFM parser/importer, `StructuredImporterInterface`, `ImportResult`,
`UnmatchedRow`, and their service-provider binding remain source-compatible and
behavior-compatible. XLSX and mapping services are additive bindings.
