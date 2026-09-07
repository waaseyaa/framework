# FW-CONTENT-TYPE-READ-VISIBILITY-01 — content-type scaffold field-read visibility

Date: 2026-09-07. Part of #2847. Parent anchor:
`8747683eaa0c063995a7560e6ef4c480c2ec5b4c`.

## Intent

Carry explicitly selected canonical field-read visibility through
`make:content-type` scaffold metadata, plan digest, and generated `#[Field]`
attributes without weakening registered-entity runtime defaults or introducing a
compiler-local authorization engine.

## API (handler-owned; shared CLI registration deferred to #2849)

Additive handler option:

```text
--field-read="title:public,summary:protected"
```

- Wire values are canonical `Waaseyaa\Entity\FieldReadLevel` cases:
  `public`, `protected`, `internal`.
- Parse once; validate before compile/apply.
- Reject duplicate selections, duplicate `--fields` declarations, unknown
  selected field names, `status` selection, malformed entries, unsupported
  wire values, and empty comma-separated `--field-read` selections (leading,
  trailing, or repeated commas).
- Reject ambiguous `--fields` target segments: scalar `name:type` entries must
  not carry a third segment; `entity_reference` entries must use exactly
  `name:entity_reference:<target>` with a non-empty target and no fourth
  segment.
- Omitted fields stay undeclared in generated attributes so the registered
  runtime default remains Internal.
- Explicit selections emit `read: FieldReadLevel::<case>` in generated PHP,
  including an explicitly selected label/title field.
- Changing a selection changes the plan digest / stale-approval identity.

Scalar and `entity_reference:<target>` `--fields` syntax is unchanged.

## Owned files

- `packages/cli/src/Site/Scaffold/ContentTypeScaffoldCompiler.php`
- `packages/cli/src/Handler/MakeContentTypeHandler.php`
- `packages/cli/tests/Unit/Handler/MakeContentTypeHandlerTest.php`
- `packages/cli/tests/Unit/Handler/MakeContentTypeCustodyTest.php`
- this record

## Exclusions

No edit to `MakeContentTypeServiceProvider`, `CliKernel`, shared command
registration, seeded activation/allowlists, search production code, CI, other
specs, or other packages. Shared `--field-read` option registration on the
published command surface belongs to the #2849 integration lane.

## Residual #2847 acceptance

- Register `--field-read` on the shared `make:content-type` command through
  #2849 without duplicating handler validation.
- Complete command taxonomy / deprecation decisions from the generator ADR.
- Carry cardinality, enum metadata, authored defaults, revisionability,
  translation, and key prerequisites through the command input contract.
- Emit remaining schema/configuration/test intent through the shared artifact
  plan.
- Prove packaged fresh-application generate/boot/sync/create/read/update on
  supported hosts.
- Broader generator acceptance and legacy-registration policy decisions remain
  open on #2847.

## Evidence

Focused handler/custody tests pin generated imports/attributes,
explicit-vs-omitted behavior, duplicate/unknown/malformed refusal, digest
change, registered-entity `fieldReadLevel()` semantics, and
`NodeSearchProjector` principal-safe projection for explicit Public fields
versus Internal/Protected/omitted non-leakage.


Integration on main e83ec8ba9: registered --field-read on make:content-type after the search lane landed. The real command definition accepts the value. Focused handler/custody qualification:47tests185assertions20.355s24MiB. No full suite repeated.
