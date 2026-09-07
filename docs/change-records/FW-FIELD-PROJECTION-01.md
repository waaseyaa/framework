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
- focused tests for those CLI classes
- this record, `docs/specs/field-scaffold-projection.md`, the implementation
  plan, and the #2847 change fragment

## Exclusions and residual acceptance

No edit is authorized to `EntityClassEmitter`,
`ApplicationBlueprintCompiler`, `SiteInitializationService`,
`EntityClassEmitter` golden fixtures, complete-blueprint fixtures,
CI/PackagedForm, public-surface aggregates, or `StdinSource`.

Remaining #2847 integration acceptance after this slice:

- inject the kernel boot-scoped field manager from the command provider so
  downstream registered field plugins are available in the real command;
- complete command taxonomy/deprecation decisions from the generator ADR;
- carry cardinality, enum metadata, authored defaults, revisionability,
  translation, and key prerequisites through the command input contract;
- emit the remaining schema/configuration/test intent through the shared
  artifact plan; and
- prove the packaged fresh-application generate/boot/sync/create/read/update
  journey on supported hosts.

The seeded creating-publication and implicit-adoption refusal proofs already
owned by the generation engine are consumed unchanged and are not recreated.

## Evidence plan

Use discriminating RED/GREEN tests for registered extension admission,
unknown/unsupported refusal, scalar/cardinality projection, manual versus
blueprint `text`/`datetime` consistency, and authored-reference versus
relationship semantics. Run only the focused field and CLI tests, the
unchanged blueprint-emitter test, and `bin/git diff --check`.
