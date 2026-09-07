# Canonical Field Scaffold Projection Implementation Plan

**Goal:** Replace `make:content-type`'s private field map with a registered,
field-owned projection while preserving blueprint bytes and reference
semantics.

**Architecture:** An internal field-package adapter validates definitions
through the existing schema and storage authorities, derives scalar candidates
from `FieldValueKind`, and checks compatibility through
`FieldTypeInferrer`. The handler and pure scaffold compiler share one adapter;
the blueprint emitter remains unchanged.

**Tech Stack:** PHP 8.5, PHPUnit 10.5 attributes, Waaseyaa field plugin
registry and generation artifact plans.

## Global constraints

- Do not change shared field interfaces or public-surface declarations.
- Do not edit blueprint emitters, compilers, golden fixtures, or the generation
  execution engine.
- Preserve seeded publication and implicit-adoption refusal behavior.
- Do not commit, push, merge, publish, release, or run broad suites.

### Task 1: Field-owned projection

**Files:**
- Create: `packages/field/src/FieldScaffoldProjection.php`
- Create: `packages/field/tests/Unit/FieldScaffoldProjectionTest.php`

- [ ] Add tests proving `text => string/''`, `datetime => mixed/null`,
  cardinality `-1 => array/[]`, registered extension admission, incomplete
  metadata exclusion, and unknown-type refusal.
- [ ] Run the focused test and confirm RED because the projection does not
  exist.
- [ ] Implement the smallest registry-derived projection with no type-id map.
- [ ] Run the focused test and confirm GREEN.

### Task 2: Manual scaffold convergence

**Files:**
- Modify: `packages/cli/src/Site/Scaffold/ContentTypeScaffoldCompiler.php`
- Modify: `packages/cli/src/Handler/MakeContentTypeHandler.php`
- Modify: `packages/cli/tests/Unit/Handler/MakeContentTypeHandlerTest.php`
- Modify: `packages/cli/tests/Unit/Handler/MakeContentTypeCustodyTest.php`

- [ ] Add tests proving a registered extension is accepted when injected,
  unknown/unsupported ids fail closed, manual `text`/`datetime` property lines
  equal blueprint emitter lines, and manual references retain target settings
  while blueprint relationships retain their registry artifact.
- [ ] Run the focused CLI tests and confirm RED against the private map.
- [ ] Inject one projection into handler/compiler, remove `TYPE_MAP`, derive
  the allowed list from the projection, and render projected property metadata.
- [ ] Run the focused CLI tests and confirm GREEN.

### Task 3: Documentation and verification

**Files:**
- Create: `docs/specs/field-scaffold-projection.md`
- Create: `docs/change-records/FW-FIELD-PROJECTION-01.md`
- Create: `changes/unreleased/2847.field-scaffold-projection.changed.md`

- [ ] Record architecture, intentional reference distinction, exclusions, and
  all remaining #2847 integration acceptance.
- [ ] Run the focused field/CLI tests and the unchanged
  `EntityClassEmitterTest`.
- [ ] Run `bin/git diff --check`.
- [ ] Inspect `bin/git status --short` and `bin/git diff --stat`; report exact
  evidence and residuals without making a commit.
