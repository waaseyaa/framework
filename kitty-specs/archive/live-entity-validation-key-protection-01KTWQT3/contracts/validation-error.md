# Contract: Save-Time Validation Failure Surface

**Mission**: live-entity-validation-key-protection-01KTWQT3 | **Requirements**: FR-001..FR-004, NFR-003

## Producer

`EntityRepository::save()` / `saveMany()` with `validate: true` (default) on a kernel-built repository.

## Contract

1. **Pre-persistence**: when validation fails, `EntityValidationException` is thrown BEFORE any storage write — base table, bundle subtable, translation table, and revision table are all untouched. Existing rows are unchanged.
2. **Completeness**: the exception's violation list contains ALL violations across all fields (not first-failure).
3. **Addressability**: each violation exposes the field name via `propertyPath` (prefix = field name), a human-readable message, and the invalid value.
4. **Determinism (NFR-003)**: for a given entity state and definition set, the violation list content is stable across runs. Consumers may rely on field-name presence, not list ordering, at this layer; the tool layer adds sorted ordering.
5. **saveMany**: validation failure inside the transaction aborts the transaction; entities saved earlier in the same `saveMany()` batch are rolled back (UnitOfWork transaction semantics). The thrown exception identifies the failing entity's violations.
6. **Opt-outs**: `validate: false` per call, or `WAASEYAA_ENTITY_VALIDATION=0|false|off` globally → behavior identical to pre-mission (no validation, no exception).
7. **No-constraint types**: entity types whose resolved constraint map is empty save with zero validation overhead beyond the map resolution (guard already returns early on `[]`).

## Verification

- Integration: `tests/Integration/Validation/KernelValidationWiringTest.php` — booted kernel, SQLite; out-of-range int rejected, row absent; valid entity saves; `validate: false` passes invalid data (unchanged behavior); env opt-out disables; saveMany rollback case.
- Unit: builder cases in `packages/entity/tests/Unit/Validation/FieldDefinitionConstraintBuilderTest.php` (Range min/max/both/neither, declared-constraint merge, non-Constraint fail-loud).
