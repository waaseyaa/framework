# FW-FIELD-PROJECTION-01 — canonical field metadata convergence

Date: 2026-09-06. Forge mirror: #2847. Parent:
`21f63d0b9adbc33100fd48b2f8b0f2461fb55672`.

## Intent

Remove `make:content-type`'s private field-type map and make its field
admission and generated PHP property shape a projection of the registered
field authority. Preserve the already-published seeded-unit custody and the
blueprint compiler's golden bytes.

## Decisions

1. `packages/field` owns one internal scaffold projection composed over a real
   `FieldTypeManagerInterface&FieldValueKindResolverInterface`.
2. Existing plugin metadata remains sufficient. This slice does not add a
   code-generation method to `FieldTypeInterface` or declare a new public API.
3. Admissible scalar ids come from `blueprintFieldTypeIds()` after excluding
   types whose declared default settings are incomplete. Registered
   entity-reference value kinds are admitted separately for the manual
   authored-reference syntax.
4. PHP scalar candidates derive from `FieldValueKind`; the existing
   `FieldTypeInferrer::isCompatible()` contract decides whether the candidate
   can safely accompany the explicit field id. Cardinality overrides the
   scalar shape with `array = []`.
5. `text` and `datetime` are compared directly with blueprint emitter output.
   The expected pairs are `string = ''` and `mixed = null`.
6. Manual `entity_reference:<target>` retains target settings. Blueprint
   relationships remain richer declarations and continue to own synthesized
   reference fields plus relationship registry metadata.

## Owned files

- `packages/field/src/FieldScaffoldProjection.php`
- `packages/field/tests/Unit/FieldScaffoldProjectionTest.php`
- `packages/cli/src/Site/Scaffold/ContentTypeScaffoldCompiler.php`
- `packages/cli/src/Handler/MakeContentTypeHandler.php`
- `packages/cli/src/Provider/MakeServiceProviderB.php`
- `packages/cli/tests/Unit/Handler/MakeContentTypeCustodyTest.php`
- focused tests for those CLI classes
- this record, `docs/specs/field-scaffold-projection.md`, the implementation
  plan, and the #2847 change fragment

## Exclusions and residual acceptance

No edit is authorized to `EntityClassEmitter`,
`ApplicationBlueprintCompiler`, `SiteInitializationService`,
`EntityClassEmitter` golden fixtures, complete-blueprint fixtures,
CI/PackagedForm, public-surface aggregates, or `StdinSource`.

Remaining #2847 integration acceptance after this slice:

- complete command taxonomy/deprecation decisions from the generator ADR;
- carry cardinality, enum metadata, authored defaults, revisionability,
  translation, and key prerequisites through the command input contract;
- emit the remaining schema/configuration/test intent through the shared
  artifact plan;
- prove the packaged fresh-application generate/boot/sync/create/read/update
  journey on supported hosts;
- decide whether legacy unowned registrations gain a separate governed
  adoption operation or require deliberate removal followed by regeneration,
  and publish that operator policy in the #2847 migration changelog.

The H08 seeded-registration proof now crosses the real migration boundary.
`MakeContentTypeCustodyTest::aSeededPublicationNeverRestoresADeliberatelyRemovedProviderRegistration()`
creates a seeded unit through `make:content-type`, proves that its provider
registration is applied, deliberately removes that registration, and invokes
the same seeded unit again with `--force`. The later publication reports the
unit unchanged, preserves both generated artifacts and the ownership document
byte-for-byte, and leaves the registration absent. The engine's pre-existing
fixture-level non-restoration coverage remains supporting evidence rather than
a substitute for this creating-publication regression.

This proof does not decide the separate legacy-registration policy. Implicit
adoption continues to refuse with `GEN012`, and this slice adds no adoption
operation. The policy and its operator-facing changelog remain residual #2847
acceptance.

## Evidence

Historical discriminating RED/GREEN tests cover registered extension admission,
unknown/unsupported refusal, scalar/cardinality projection, manual versus
blueprint `text`/`datetime` consistency, and authored-reference versus
relationship semantics. The H08 evidence follow-up adds a regression over the
existing engine behavior for the actual seeded create/remove/republish
non-restoration path; no RED result is claimed for that added test.

## CI-driven integration correction

Hosted Architecture evidence at 5383f0954b0c4b6851ce0ea628dea6e96db0f437
exposed two integration defects; both are repaired in this candidate.
MakeServiceProviderB is the production construction owner and lazily resolves
a boot-scoped object implementing both `FieldTypeManagerInterface` and
`FieldValueKindResolverInterface`. Missing or incompatible services refuse;
production no longer falls back to a built-ins-only manager. The real
ProviderRegistry/manifest regression first failed because its manifest field
type was unknown, then succeeded after injection and proved that the plugin id
reached generated output. MakeServiceProviderA remains outside this slice.

The two transient FieldDefinition constructions carry explicit
`FieldReadLevel::Internal` classification under the existing field-read
contract because they are compiler metadata, not persisted entities or
generated public access policy. No architecture exemption or static-default
roster expansion was added.

## Explicit construction compatibility decision

MakeContentTypeHandler and ContentTypeScaffoldCompiler now require a non-null
FieldScaffoldProjection constructor dependency. This intentionally changes
their public standalone construction contract; existing direct callers must
supply a projection. The real command provider lazily injects the boot-scoped
registry only when make:content-type executes. Isolated tests may explicitly
construct their own built-ins registry; no production default is inferred.
No static-default roster exemption or fallback relocation is introduced.

Transient projection definitions carry FieldReadLevel::Internal because they
are compiler metadata, not a persisted entity or an emitted access policy.
This does not classify generated attributes or change their existing bytes.

Historical exact checkpoint
`ef0fb3ae5d5a95c22913e439224487bb555a304f` had accepted independent review
and 44 successful hosted checks, including changed-line coverage. The H08
evidence follow-up adds only this evidence correction, the matching CLI spec
correction, and the custody regression; that focused method passes as 1 test
with 8 assertions on candidate-bound source. The earlier hosted checks do not
qualify the follow-up. Exact-head hosted checks, current-base integration, and
governed full qualification remain required.
