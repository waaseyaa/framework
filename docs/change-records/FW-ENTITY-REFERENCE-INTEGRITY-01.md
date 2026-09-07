# FW-ENTITY-REFERENCE-INTEGRITY-01 — canonical `entity_reference` existence validation

Status: focused candidate proof complete; independent runtime review pending
Anchor mirror: waaseyaa/framework#2981
Parent candidate: `8747683eaa0c063995a7560e6ef4c480c2ec5b4c` (`origin/main` at worktree open)

## Intent

When save-time entity validation is **active** (`EntityValidator` configured and
`EntityRepository::save(..., validate: true)`), `entity_reference` field values
must be checked for target existence at the canonical repository pre-write
constraint path — not in HTTP controllers and not via database foreign keys.
The check uses the existing `Waaseyaa\Validation\Constraint\EntityExists`
callback constraint backed by `Waaseyaa\Entity\Repository\EntityIdentifierResolver`
(ID or UUID, access-neutral lookup).

## Decisions

1. **Active-path only.** Validation runs only when a repository carries an
   `EntityValidator` and the save call does not pass `validate: false`. The
   documented `WAASEYAA_ENTITY_VALIDATION` boot opt-out (null validator) and
   per-save `validate: false` bootstrap/import bypass are unchanged. Those paths
   do not enforce reference integrity and are not claimed to.
2. **Fail-closed configuration.** When validation is active and resolved field
   definitions include `entity_reference`, absence of the injected resolver throws
   a stable `LogicException` before any driver write. Kernel-built repositories
   always receive `EntityIdentifierResolver`. Repositories for types without
   `entity_reference` fields remain compatible without a resolver.
3. **Constraint derivation.** `EntityTypeValidationConstraints` composes
   `EntityExists` (wrapped in `All` when `cardinality !== 1`) **after** manual
   per-type precedence. Unknown or malformed target metadata
   (`target_entity_type_id` / `targetEntityTypeId` / legacy `target_type`)
   throws `LogicException` on the active validation path. Malformed reference
   value shapes fail as ordinary constraint violations without uncaught
   `TypeError`.
4. **Manual per-type precedence preserved for ordinary constraints.**
   `EntityType::getConstraints()` layer-3 manual entries still **replace**
   derived scalar/declared constraints for that field name. Existence integrity
   composes afterward and cannot be removed by manual constraints.
5. **Protected fields.** `ValidationFieldReader` admits `EntityExists` and `All`
   only when every `EntityExists` uses the canonical resolver-backed
   `EntityReferenceExistenceChecker`. The public callback-shaped `EntityExists`
   API remains available for Public fields, but an arbitrary caller callback
   cannot receive a non-Public raw value. Invalid values retain the existing
   redaction contract.
6. **#2756 boundary.** Polymorphic engagement `target_type` + `target_id` pairs
   (issue #2756) remain a separate design lane. This change covers declared
   `entity_reference` fields only.
7. **`on_delete` residual.** Referential delete lifecycle (`restrict` / `delete`
   cascade semantics) is not implemented here; existence validation is pre-write
   only.

## Owned surfaces

| Area | Files |
|------|-------|
| Constraint derivation | `packages/entity/src/Validation/EntityTypeValidationConstraints.php`, `EntityReferenceExistenceConstraintBuilder.php`, `EntityReferenceExistenceChecker.php`, `ValidationFieldReader.php` |
| Repository gate | `packages/entity-storage/src/EntityRepository.php` |
| Kernel wiring | `packages/foundation/src/Kernel/EntityTypeManagerFactory.php` |
| Test seam | `packages/entity-storage/src/Testing/V2EntityRepositoryFactory.php` |
| Evidence | focused unit tests under `packages/entity/tests/Unit/Validation`, `packages/entity-storage/tests/Unit`, `packages/foundation/tests/Unit/Kernel` |
| Contracts | `packages/entity/public-surface.php`, `docs/specs/entity-system.md`, `changes/unreleased/2981.entity-reference-existence-validation.added.md` |

## Verification

```bash
./vendor/bin/phpunit packages/entity/tests/Unit/Validation/EntityReferenceExistenceConstraintBuilderTest.php --no-coverage
./vendor/bin/phpunit packages/entity/tests/Unit/Validation/EntityTypeValidationConstraintsTest.php --no-coverage
./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryEntityReferenceValidationTest.php --no-coverage
./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/EntityTypeManagerFactoryTest.php --filter build_wires_entity_reference --no-coverage
```

Executed against the candidate-local locked dependency graph at base
`8747683eaa0c063995a7560e6ef4c480c2ec5b4c` after the settings-first target
resolution and array-alias corrections:

- Entity builder, constraint-map and repository suites: 42 tests, 91 assertions.
- Closed-reader suite: 4 tests, 15 assertions.
- Kernel production resolver wiring: 1 test, 13 assertions.
- Closed-reader trust-boundary regression: the public callback-shaped
  `EntityExists` is rejected before reservation or value read, while the
  resolver-derived checker continues to validate protected references.
- Targeted PHP CS Fixer and `git diff --check`: clean.

This is focused evidence. Full qualification and hosted exact-head checks have
not run on the unpublished checkpoint.

## Residuals

- Root integration proof (`tests/Integration/`) not authored in this lane.
- `on_delete` / referential delete lifecycle.
- #2756 polymorphic engagement target integrity.
