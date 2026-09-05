# FW-SITE-BLUEPRINT-01 — governed application blueprints through the canonical site contract

Status: design candidate

Anchor mirror: waaseyaa/framework#2783

Decision mirror: waaseyaa/framework#2784

Parent candidate: `52d26455e7ec69825123df7f9102076f5a7eb4b7`

## Intent

Allow a human, deterministic tool, or AI-assisted product to propose one
closed, reviewable application blueprint and have Framework validate, approve,
materialize, own, and verify it through the existing `waaseyaa.site`/`site:init`
authority. Reusable Framework gaps are fixed upstream; Waaseyaa Studio does not
own a parallel schema or compiler.

## Governing decision

ADR-023 expands `waaseyaa.site` v1 in place with an optional
`application_blueprint` section. Presence derives the generator feature token
`site-application-blueprint-v1`, in a runtime roster separate from authored
site capabilities. Authored YAML contains the proposal only; approval binds a
claimed actor and decision to the exact blueprint and full manifest digests,
with authenticity limited by the higher-layer decision mechanism. Applied
evidence extends `.waaseyaa/generated.json` inside the existing transaction and
makes the canonical approval receipt an explicit second input to rendering and
strict verification.

Separation requires a demonstrated consumer or architecture boundary. Product
or model-provider convenience is not sufficient.

## Work packages

1. **FW-SITE-BLUEPRINT-01A — authority and lifecycle (#2784).** Land ADR-023,
   this portable record, and the enduring golden-path contract.
2. **FW-SITE-BLUEPRINT-01B — typed contract (#2785).** Add the optional closed
   schema, typed PHP values, canonical identity, semantic validation, stable
   findings, and positive/negative fixtures while preserving old-v1 bytes.
3. **FW-SITE-BLUEPRINT-01C — schema authority (#2786).** Converge entity field
   and blueprint introspection on one canonical field/type authority.
4. **FW-SITE-BLUEPRINT-01D — transactional compilation (#2787).** Extend
   `site:init` dry-run/apply, exact-digest decisions, generated ownership,
   recovery, collision refusal, and idempotent replay. Split on 2026-09-05
   into two review candidates (design below): **01D-1** — fail-closed
   generator-feature negotiation and the pure blueprint root compiler with
   its emitter seam, engine admission explicitly not widened; **01D-2** —
   engine eligibility under the ADR-025 D-13 gate, the decision-receipt
   input, receipt verification in evaluation, and applied evidence with a
   compatible reader and strict doctor.
5. **FW-SITE-BLUEPRINT-01E — governance (#2788).** Compile permissions, roles,
   policies, workflows, and transitions through existing default-deny runtime
   enforcement.
6. **FW-SITE-BLUEPRINT-01F — packaged proof (#2789).** Prove a governance-rich
   reference application from a clean packaged install without a model
   provider or hidden manual repair.

Each implementation work package is one review candidate with its own failing
boundary test, change fragment, exact verification evidence, and explicit spec
review. No package may claim a later work package's acceptance.

## Invariants

- `site-contract` and `site:init` remain the only schema and generation
  authorities.
- Existing v1 manifests without a blueprint remain valid and byte/digest
  stable.
- Unknown or unsupported input fails closed before writes with stable codes and
  JSON Pointer paths.
- Approval matches both the exact canonical blueprint digest and the complete
  proposed manifest digest.
- Authored state cannot impersonate approval or application evidence.
- Dry-run, collision refusal, atomic publication/recovery, generated ownership,
  and verification extend existing machinery rather than being wrapped.
- Validation and materialization require no model provider.
- Access defaults to deny and is proven through real Framework policy and
  workflow composition.

## Explicit exclusions

- arbitrary executable code, prompts, shell commands, Composer dependencies,
  secrets, deployment, DNS, and existing-data migration;
- a Studio-owned schema, validator, compiler, transaction log, or ownership
  manifest; and
- merge, release, deployment, production mutation, or a beta claim merely from
  completing an individual work package.

## Required program evidence

- a closed governance-rich reference blueprint and focused invalid fixtures;
- cross-platform canonicalization and stable finding codes;
- exact-digest approve/reject/supersede tests;
- transactional dry-run/apply, rollback, recovery, and idempotent replay;
- packaged entity, relationship, API, permission, policy, workflow, fixture,
  and generated-test acceptance; and
- provider-portability measurement only after the provider-independent path is
  green.

## Review-candidate identity and evidence

The parent is recorded above. The candidate is the Git commit containing this
record; a commit cannot embed its own SHA without changing that SHA. Git history
is the portable identity, and the current GitHub adapter mirrors it in PR #2798.

For work package 01A, run `git diff --check`,
`php bin/check-changelog-shape`, `bash tools/drift-detector.sh origin/main`, and
the governed Linux CI/preflight roster. Environment-limited local results do
not replace exact-head hosted evidence.

## Work package 01C design — schema authority (#2786)

The canonical authority lives in `packages/field` (Layer 1), where field-type
plugins are registered. `FieldSchemaAuthority` composes closed entity schemas
from the effective field set supplied by `EntityTypeManagerInterface`; it does
not rediscover fields. Plugins own two named representations because the
boundary is real, not provider convenience: field-item JSON Schema describes
multi-column item values, while entity-value JSON Schema describes authoring
and entity introspection values. Unknown types fail closed in both paths.

`SchemaPresenter` is retained as an API-layer decorator for widgets, bundle
hints, exposure policy, and explicit principal/subject field access. The
Layer-5 `EntityJsonSchemaGenerator` is retained only as a principal-bound thin
adapter; its open `generateAll()` catalogue is removed and no runtime consumer
is invented. GraphQL remains a distinct wire-type adapter and does not become
JSON Schema authority. `site-contract` stays at Layer 0: its enum remains
closed, while a root composition test proves exact equality with the live
plugin registry's blueprint-admission roster.

Implementation review demonstrated that schema generation alone was not a
complete authority boundary: live field declarations and SQL schema builders
could still enter through independent type vocabularies. Work package 01C now
also makes field registration the common admission gate and makes entity,
translation, revision, and SQL-column schema derivation consume plugin-owned
storage projections. Unknown and ambiguous types fail before DDL. API, AI,
GraphQL, and Admin remain decorators/adapters over those projections, including
the shared internal-field visibility floor and nested enum cardinality shape.

Candidate evidence must cover registered plugin shapes, field-item versus
entity-value projection, cardinality and revision/translation metadata,
effective-field enumeration through the real schema controller, exact
blueprint-roster equality, protected-field concealment, unknown-type refusal,
package layers, and the governed CI/preflight roster.

## Work package 01D design — transactional compilation (#2787)

Recorded 2026-09-05 against parent
`4121eb395ae2ef05c773da71121744041864cde4`. Authorities: ADR-023 D-2, D-4,
D-5; ADR-025 D-2.3a, D-5, D-6, D-8, D-10.1, D-12 step 2, D-13;
`docs/specs/site-golden-path.md` "Governed application blueprints";
FW-GENERATION-UNITS-07/08. This section is design evidence; each slice's
own review candidate earns its code.

### Observed defect at the parent

At the parent, `site:init --dry-run --json` and `site:init --json --yes`
against `packages/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml`
both exit 0. Apply publishes `.waaseyaa/site.yaml` carrying the
`application_blueprint` section, `.waaseyaa/generated.json` binding that
manifest digest, and no blueprint artifact. Nothing advertises or negotiates
`site-application-blueprint-v1`; the section is silently ignored, which is
the outcome ADR-023 D-2 forbids. Closing that path before any compiler is
reachable is why 01D is split.

### Split

- **01D-1 — negotiation and pure root compiler.** Fail-closed
  generator-feature negotiation at the `site:init` boundary; a pure
  approved-blueprint root compiler composing `SiteArtifactRenderer`; the
  emitter seam with the entity, relationship and provider-registration
  emitters. Engine admission is explicitly not widened:
  `SiteInitializationService::ADDITIVE_COMPILERS` stays
  `[SiteArtifactRenderer::class]`, the root-unit identity reservation stays
  `SiteArtifactRenderer::class`, and no handler, handler-reachable factory
  or doctor path constructs the compiler. The compiler is unreachable from
  the CLI until 01D-2; a blueprint-bearing manifest is refused at
  negotiation in both modes.
- **01D-2 — engine eligibility, receipt verification, applied evidence.**
  Adds the compiler to the closed eligibility list under the ADR-025 D-13
  six-item gate; adds the `--decision-receipt` input; verifies the receipt
  inside engine evaluation; composes the `application_blueprint` evidence
  member of `.waaseyaa/generated.json` with a compatible reader and a strict
  doctor; wires the compiler into `site:init` and `site:doctor`.

Each slice is one review candidate with its own red boundary tests, change
fragment, exact verification evidence and spec review. 01D-1 claims no
01D-2 acceptance line; neither claims #2788 or #2789.

### Decisions

**(a) Compiler identity.**
`Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler` is a distinct
final class whose plan carries its own FQCN as `generator.fqcn`. It
publishes the existing root `site` unit (`managed`, ADR-025 D-10.1) and
composes an injected `SiteArtifactRenderer` by calling `render()` on the
same manifest and taking every artifact except `.waaseyaa/generated.json`,
which a plan never carries (D-6.1). Blueprint-free bytes are unchanged by
construction: 01D-1 does not edit `SiteArtifactRenderer.php`, and
`packages/site-contract/tests/Fixtures/Generation/blueprint-free-v1.generated.json`
stays byte-identical. The compiler is not a `SiteRecipeRendererInterface`
and is never registered in `SiteArtifactRendererFactory`: a recipe
renderer's output is rendered under `SiteArtifactRenderer::class`, whose
plan `initialize()` already declares `additive` and whose FQCN is already on
`ADDITIVE_COMPILERS`, so a blueprint recipe would inherit that grant and
evolve the root set with no approval check, bypassing D-13 items 1, 3 and 5
and leaving the eligibility list unable to tell the two compilers apart
(items 2 and 6). Plan identity: `generator.version` is
`$manifest->generatorVersion`, the root unit's provenance pair (D-10.1);
`input_digest` is `$manifest->digest`, which already covers the blueprint
digest (ADR-023 D-3); `set_evolution` is `additive`, declared purely, with
eligibility left entirely to the engine (D-13 item 1).

**Deviation from D-10.1's literal wording (review round 1, F6).** D-10.1
states "the root unit's provenance is the existing top-level pair:
`generator_version` plus the installed `SiteArtifactRenderer`." 01D-1
publishes root `site` under `ApplicationBlueprintCompiler::class` instead
(not `SiteArtifactRenderer`), for the D-13 reason above: the engine's
eligibility gate needs to tell the two compilers apart by `generator.fqcn`,
and every 01D-1 plan is refused `GEN011` because `ADDITIVE_COMPILERS` does
not yet name it. This is a deliberate staging deviation, not a
contradiction of D-10.1's intent (the root unit itself is unchanged); the
ADR's wording is amended when 01D-2 adds the compiler to the closed
eligibility list and the deviation becomes the shipped shape.

**(b) Approval-free compilation, approval-required evaluation.** Parsing,
semantic validation, negotiation and `compile()` take no receipt: the golden
path states that a proposal needs no approval to validate or render, and
ADR-023 D-4 that a proposal may validate without a receipt. The receipt is
an input to the execution authority: an `ArtifactPlan` whose
`generator.fqcn` is the compiler entering `SiteInitializationService`
evaluation without a `BlueprintDecisionReceipt` that is `approved` and
`matches()` the manifest is `GEN011_UNAUTHORIZED_SET_DELTA`, identically in
dry-run and apply (D-13 items 3 and 5). ADR-023 D-5's "second renderer
input" lands where ADR-025 D-2.6 moved metadata composition: the transaction
authority composes the evidence member from the verified receipt, and
artifact bytes remain a pure function of the manifest. The engine matches
the receipt against the manifest re-parsed from the plan's own
`.waaseyaa/site.yaml` row, asserting that digest equals `input_digest`, so
the check binds the reviewed bytes rather than a separately supplied object
(the D-6.5 principle). The receipt travels as a separate CLI input on every
invocation and is not a member of the closed `ArtifactApplyRequest`.
Consequence for #2789's reference journey, whose steps 2–3 read as
review-then-approve: under D-13 the executable order is approve
(request-scoped, ADR-023 D-5) → dry-run → apply, and the approval-free
review surface is the compiled plan, which 01D-1 exposes through
`compile()`. Whether 01D-2 also exposes it at the process boundary without
evaluation is a 01D-2 design item that may neither evaluate an unapproved
plan nor add a second non-interactive contract (ADR-025 D-5).

**(c) Seeding is a follow-on decision.** Fixtures and checks are declared
and validated by 01B; materializing fixture rows is a runtime operation,
not generation, and the loader contract fixes the fixture artifact's shape.
01D-1's emitter roster therefore excludes fixtures and checks rather than
guessing. The decided direction is a development-gated seed command exposed
by the generated provider and refused outside development (the policy
#2789's harness asserts before use), recorded as candidate **01D-3** and not
hidden inside 01D-1 or 01D-2.

**(d) Studio's boundary is the process boundary.** Studio invokes
`bin/waaseyaa site:init --json --answers <manifest> --decision-receipt <path> [--dry-run] [--yes]`
and `site:doctor --strict --format=json`; it depends on `site-contract` for
parsing, validation and receipt shapes only and never links the compiler.
01D-1 commits the negotiation-refusal envelope as a fixture under
`packages/cli/tests/Fixtures/SiteInit/`; 01D-2 commits the `planned`,
`applied`, `no_changes`, `GEN011` (missing, rejected, mismatched receipt)
and `SITE050` (malformed receipt) envelopes there.

**(e) No `content` group.** `MakeContentTypeHandler` scaffolds
`EntityType::fromClass(..., group: 'content')`; the prototype's generated
entity types and provider registration carry no group (`EntityType` group
`null`, `ComposerProviderRegistration::$group` `null`). Group is roster and
presentation metadata with no runtime authority, and `content` would assert
editorial-content semantics the blueprint never declares. A
blueprint-declared grouping is a later contract change, not an emitter
default.

**(f) The emitter seam.**
`Waaseyaa\CLI\Site\Blueprint\Emitter\BlueprintArtifactEmitterInterface`
declares `id(): string` and
`emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission`,
where `BlueprintEmission` is a final readonly value of
`list<GeneratedArtifact> $artifacts`,
`list<ComposerProviderRegistration> $registrations` and
`list<string> $companionTests`. Emitters are pure functions of their input
(ADR-025 D-8). The compiler composes a fixed ordered list; emitter ids are
unique; emitted path sets are pairwise disjoint and disjoint from the base
set, and any overlap is a compile-time `\InvalidArgumentException` (a
compiler defect, never a project-state refusal). The compiler sorts the
union by path and builds the plan.
`Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory::create()`
is the single composition root: 01D-1 registers the three emitters below,
#2788 appends its permission, role, policy and workflow emitters there
without editing the compiler, and 01D-3 appends fixtures and checks.

- `EntityClassEmitter` — one `src/Entity/<PascalCase(entity.id)>.php` per
  entity: a final `ContentEntityBase` subclass with the entity type id and
  keys hardcoded, declared fields through the closed `BlueprintFieldType`
  roster that 01C proved equal to the plugin registry, storage,
  revisionable and translatable flags, and the relationship-created
  reference field for every relationship whose `from.entity` is this entity
  (the validator already reserves that field id). An `enum` field also
  emits a generated backed-enum class,
  `src/Entity/Enum/<PascalCase(entity.id)><PascalCase(field.id)>.php`, and
  the property's `#[Field]` attribute carries `settings.enum_class` naming
  it explicitly — `Waaseyaa\Field\Item\EnumItem` requires that setting
  (`EnumFieldTypeException::MISSING_ENUM_CLASS` otherwise); a `values`
  setting alone, the review-round-1 finding (F1), is not sufficient.
  `keys.owner` is intentionally not carried onto the generated class:
  `ContentEntityKeys` has no `owner` parameter, and the entity runtime has
  no "owner" key at all (review-round-1 finding F4) — a future
  ownership-policy emitter must re-derive the owner relationship field from
  the blueprint itself, not from generated entity metadata. Review-round-2
  (R2-5) found a second, framework-wide limitation this slice inherits
  rather than introduces: no emitted `#[Field(...)]` declares `read:`, so
  every field's read level defaults to `FieldReadLevel::Internal` and every
  `get()` on a generated entity throws `FieldReadDenied` with no policy
  registered to grant it. `make:content-type` output shares this default;
  the blueprint contract has no per-field read-level declaration to carry
  through. Left open for 01D-2's engine wiring, a governance/policy emitter,
  or a future blueprint-contract extension to close.
- `RelationshipEmitter` — one deterministic registry artifact
  `config/waaseyaa-blueprint/relationships.php` listing id, from entity and
  field, to entity, cardinality, required and `on_delete`
  (`restrict` | `nullify`). Review-round-2 (R2-6) found nothing in 01D-1
  loads this registry: it is emitted for a later consumer to wire up
  (candidate: 01D-2's engine work, or a governance/policy emitter), not
  read by the generated provider today.
- `ProviderRegistrationEmitter` —
  `src/Provider/ApplicationBlueprintServiceProvider.php` registering every
  generated entity type in `register()`, plus one
  `ComposerProviderRegistration` for that FQCN with no group. It does not
  read the relationship registry (R2-6, corrected from an earlier "wires
  the relationship registry" claim this section and the emitter's own
  docblock both carried).

**(g) Negotiation refusal code.** The `SITE0xx` family was checked: the next
free id in the negotiation family after
`SITE031_RECIPE_CAPABILITY_NOT_ACTIVE` would be `SITE032`. It is not minted,
because ADR-025 D-5 already reserves `GEN007_UNSUPPORTED_DECLARATION` for
"an unsupported field type or generator-feature token ... for the
plan-compilation boundary", and a second id for one refusal is the
dual-source pattern the constitution forbids. The refusal is
`GEN007_UNSUPPORTED_DECLARATION`, carried as
`GenerationRefusalException(source: 'site:init', [GenerationViolation(GEN007, <message naming the missing tokens and the advertised roster>, pointer: '/application_blueprint')])`.
It is thrown by
`Waaseyaa\SiteContract\Generation\GeneratorFeatureNegotiation::assert(SiteManifest $manifest, list<string> $advertised, string $source)`
when `array_diff($manifest->requiredGeneratorFeatures, $advertised)` is
non-empty (exact token equality). `SiteInitHandler` calls it immediately
after `SiteManifestParser::parse()` and before
`SiteArtifactRendererFactory::create()->render()`, so the refusal precedes
every render, lock, journal and write and is identical in dry-run and apply;
the compiler's `compile()` repeats it against its own roster as a
precondition. Like a `SITE0xx` content refusal it precedes the generation
authority, so no change receipt is minted. The installed roster is
`SiteArtifactRendererFactory::advertisedGeneratorFeatures()`, `[]` in 01D-1
(the manifest-only root compiler advertises nothing); 01D-2 unions
`ApplicationBlueprintCompiler::GENERATOR_FEATURES = ['site-application-blueprint-v1']`
when it wires the compiler.

**(h) Compiler home package.** The compiler and emitters live in
`packages/cli/src/Site/Blueprint/` (Layer 6) beside the recipe renderers,
not in `site-contract`. `bin/check-package-layers` PL008 scans string
scalars, heredoc and nowdoc bodies under `<pkg>/src/` for upward
`Waaseyaa\<Ns>` references, and emitters must write
`use Waaseyaa\Entity\...` and `Waaseyaa\Field\...` into generated source,
which Layer 0 cannot carry. ADR-025 D-3's split holds: types stay in
`site-contract` (`GeneratorFeatureNegotiation` is a pure type over
`SiteManifest` and `GenerationRefusalException`, so it belongs there),
execution and compilers that know runtime FQCNs stay in `cli`, and the
01D-2 `ADDITIVE_COMPILERS` entry is a same-package class-string. New `@api`
types are declared in the package-local `public-surface.php` (#2901).

**(i) Identifier-grammar refusal (review round 1, F3).** The SITE0xx grammar
(`^[a-z][a-z0-9_-]*$`, `ManifestShapeReader::id()`) admits a hyphen that PHP
cannot represent as a class, property, or entity-key name. Rather than
tightening that shared grammar (a 01B/01C authority change out of scope
here) or letting an emitter crash with a bare, uncoded
`\InvalidArgumentException` the CLI envelope cannot carry a code or pointer
for, `ApplicationBlueprintCompiler::compile()` asserts every blueprint
entity id, field id, entity-key value, and relationship `from` field
against the PHP identifier grammar itself, immediately after negotiation
and before invoking any emitter. A violation reuses
`GEN006_MALICIOUS_IDENTIFIER` — ADR-025 D-5 already reserves it for "a unit
id fails the D-2.1 grammar", the same shape of problem one layer up — with
one `GenerationViolation` per offending identifier, pointing at
`/application_blueprint/entities/<id>/...`. Each emitter's own identifier
check (e.g. `EntityClassEmitter::safeId()`) becomes a defensive invariant
after this fix: it should be unreachable in practice, and its bare
`\InvalidArgumentException` now signals a compiler defect (the pre-check
and the emitter's own check have drifted apart), never a project-state
refusal.

### CLI outcomes

| Invocation on a blueprint-bearing manifest | 01D-1 | 01D-2 |
|---|---|---|
| `site:init --dry-run` | handler returns 2, `GEN007_UNSUPPORTED_DECLARATION` at `/application_blueprint`; no render, no write | without a matching approved receipt: handler returns 2, `GEN011_UNAUTHORIZED_SET_DELTA`, no write; with one: handler returns 0, `outcome: planned`, the complete deterministic artifact set with per-path status and `setDelta` |
| `site:init --yes` (apply) | identical refusal, payload and handler return; no lock, journal or write | without: identical `GEN011` refusal, no write; with: `outcome: applied` and `.waaseyaa/generated.json` carrying `application_blueprint` evidence; unchanged replay: `no_changes`; changed blueprint with a fresh approval: additive successor path; blueprint removal: `GEN011` (drops) |
| `--json` envelope | `{"evaluation":null,"result":null,"receipts":[],"errors":[{"code":"GEN007_UNSUPPORTED_DECLARATION","pointer":"/application_blueprint","message":"..."}]}` | the same shape for refusals; `evaluation`, `result` and `receipts` populated on success |
| blueprint-free manifest | unchanged bytes, handler return and envelopes | unchanged |

**Handler return vs. process exit (review round 1, F5, corrected).** The
handler returns 2 for every input refusal, shared with `SITE0xx`; that is
what the `CliTester`/handler-level red tests in this record assert, and
renumbering is not part of 01D. But `Waaseyaa\CLI\WaaseyaaConsoleApplication
::run()` normalizes every non-zero handler return to a **process exit of
1** (`$exitCode === 0 ? 0 : 1`) — verified: `bin/waaseyaa site:init
--dry-run --json` on a blueprint-bearing manifest exits the OS process with
`1`, not `2`, in this slice and pre-existing today for every other
`SITE0xx` refusal. Studio's process-boundary contract (decision (d)) is
therefore the fixture **envelope** (`errors[0].code`), not the numeric
process exit code — a non-zero exit signals failure, but distinguishing
refusal families by exit code alone is not supported at any point on this
branch. The `errors` entry for a `GenerationRefusalException` carries
`code`, `pointer` and `message` from
`GenerationRefusalException::toArray()`; today's handler emits `message`
only, and 01D-1 widens that entry for coded refusals without changing the
envelope's members.

### 01D-1 target files

- `packages/site-contract/src/Generation/GeneratorFeatureNegotiation.php` (new, `@api`)
- `packages/cli/src/Site/SiteArtifactRendererFactory.php` — `advertisedGeneratorFeatures(): list<string>` returning `[]`
- `packages/cli/src/Handler/SiteInitHandler.php` — negotiation call after parse; coded `errors` for `GenerationRefusalException`
- `packages/cli/src/Site/Blueprint/ApplicationBlueprintCompiler.php` (new)
- `packages/cli/src/Site/Blueprint/ApplicationBlueprintCompilerFactory.php` (new; referenced by no handler or doctor path)
- `packages/cli/src/Site/Blueprint/Emitter/BlueprintArtifactEmitterInterface.php`, `BlueprintEmission.php`, `EntityClassEmitter.php`, `RelationshipEmitter.php`, `ProviderRegistrationEmitter.php` (new)
- `packages/cli/tests/Fixtures/SiteInit/blueprint-negotiation-refused.json` (envelope fixture)
- `packages/cli/tests/Fixtures/Blueprint/expected/` golden artifacts for `minimal.yaml` and `complete.yaml`
- `changes/unreleased/2787.blueprint-negotiation-compiler.added.md`; spec review of `docs/specs/site-golden-path.md`

Not touched in 01D-1: `SiteArtifactRenderer.php`,
`SiteInitializationService.php`, `SiteDoctorService.php`,
`tests/Architecture/GenerationUnitActivationBoundaryTest.php`.

### 01D-1 red boundary tests

1. `packages/cli/tests/Unit/Handler/SiteInitBlueprintNegotiationTest.php` —
   red at the parent because both modes return 0 today: `--dry-run` and
   `--yes` on `minimal.yaml` return 2 with `GEN007` and pointer
   `/application_blueprint` in text and JSON (process exit 1, per the
   handler-return-vs-process-exit note above); the two envelopes are
   identical; the project root is byte-identical afterwards with no
   `.waaseyaa/` directory or lock; blueprint-free answers keep their
   existing envelopes (`SiteInitJsonTest` fixtures unchanged).
2. `packages/site-contract/tests/Unit/Generation/GeneratorFeatureNegotiationTest.php` —
   a blueprint-free manifest passes with an empty roster; a
   blueprint-bearing manifest with an empty roster refuses `GEN007` with the
   pointer and a message naming `site-application-blueprint-v1`; a roster
   containing the token passes; extra advertised tokens are ignored; a
   near-miss token (`site-application-blueprint-v2`) does not satisfy v1.
3. `packages/cli/tests/Unit/Site/Blueprint/ApplicationBlueprintCompilerTest.php` —
   base rows are byte-identical to
   `SiteArtifactRendererFactory::create()->render()` minus
   `.waaseyaa/generated.json`; plan identity is the compiler FQCN, `site`,
   `managed`, `additive`, and the manifest's `generator_version` and
   `input_digest`; two compiles are byte-identical (`canonicalJson`,
   `digest`); a blueprint-free manifest is refused with
   `\InvalidArgumentException`; an emitter overlapping a base path or
   another emitter is refused at compile; a manifest requiring a token
   outside the compiler's roster is `GEN007`; a hyphenated blueprint id is
   refused `GEN006_MALICIOUS_IDENTIFIER` before any emitter runs
   (review round 1, F3).
4. `packages/cli/tests/Unit/Site/Blueprint/Emitter/EntityClassEmitterTest.php`,
   `RelationshipEmitterTest.php`, `ProviderRegistrationEmitterTest.php` —
   one artifact per entity and one registry row per relationship against
   the golden fixtures; the relationship-created reference field appears on
   the `from` entity exactly once; the registration carries no group;
   `companionTests` are a subset of the emission's own paths; an `enum`
   field's generated backed-enum class and `settings.enum_class` load
   through the real `EntityMetadataReader`/`EnumItem` runtime, not only a
   snapshot comparison (review round 1, F1); `keys.owner` is asserted
   absent from the generated class (F4); a label containing a single and a
   double quote round-trips exactly (F2).
5. `packages/cli/tests/Unit/Site/GenerationBlueprintAdmissionTest.php` —
   a compiler plan through `SiteInitializationService::evaluate()` and
   `initialize()` (dry-run and apply) is refused `GEN011` before any write,
   identically in both modes, on a fresh and on an initialized project.
   01D-2 flips this test under the D-13 gate; it is never deleted.
6. `tests/Architecture/BlueprintCompilerActivationBoundaryTest.php` —
   `ADDITIVE_COMPILERS` is still `[SiteArtifactRenderer::class]`;
   `SiteArtifactRendererFactory::advertisedGeneratorFeatures()` is `[]`;
   no file under `packages/cli/src/Handler/` and neither
   `SiteDoctorService.php` nor `SiteArtifactRendererFactory.php` references
   `ApplicationBlueprintCompiler` (token scan, as
   `GenerationUnitActivationBoundaryTest::testOnlySiteDoctorReachesUnitInspection`);
   `SiteInitHandler.php` references `GeneratorFeatureNegotiation`; the
   compiler and emitter sources contain no filesystem, clock or environment
   calls.

### 01D-2 owns

- The D-13 six-item gate with explicit review evidence for each item;
  `ADDITIVE_COMPILERS` gains exactly `ApplicationBlueprintCompiler::class`;
  `GenerationUnitActivationBoundaryTest` asserts the two-entry list and that
  every other inventoried compiler stays frozen.
- The root-unit identity reservation in `prepareUnitPlan()` (today `GEN006`
  for any root plan not under `SiteArtifactRenderer::class`) and the
  recorded-identity check (`GEN010` on a compiler change) widened to the
  closed root pair, with the transition rules: manifest renderer → compiler
  under a matching approval is additive growth; compiler → manifest renderer
  removes recorded paths and is `GEN011` (v1 refuses subtraction, D-2.3a
  rule 1).
- `--decision-receipt <path>` on `site:init`; parsing through
  `BlueprintDecisionReceipt::fromArray()` (`SITE050` on shape); receipt
  verification inside engine evaluation as decided in (b); `GEN011` for a
  missing, rejected or non-matching receipt, identically in dry-run and
  apply.
- The `application_blueprint` evidence member of `.waaseyaa/generated.json`,
  composed by the transaction authority from the verified receipt (the
  canonical receipt members, both digests and the generator feature token)
  and installed last; blueprint-free metadata bytes unchanged; the reader
  accepts the exact historical v1 shape and the extended shape and rejects
  any other; `BlueprintLifecycleResolver` extended to `applied` and
  `superseded` from that evidence.
- Strict doctor: negotiates features, reads the receipt from metadata,
  validates its digest bindings, re-renders expected metadata through the
  compiler, and fails closed on missing, mismatched or success-shaped
  evidence; the reviewed schema-upgrade distinction the spec assigns to
  #2787 (`SITE010` wording for the `site.schema.json` change).
- Wiring `ApplicationBlueprintCompilerFactory` into `SiteInitHandler` and
  `SiteDoctorService` by feature negotiation; idempotent replay
  (`no_changes`); the process-boundary envelope fixtures of (d); flipping
  tests 5 and 6 above.

## Work package 01E design — governance compilation (#2788)

Recorded 2026-09-05 against the accepted 01D-1 head
`722d4a42111ab1ec059fb95a537a40559b26a070` (PR #2936), read alongside the
01D-2 candidate's compiler and handler diff so that 01E adds emitters only
through `ApplicationBlueprintCompilerFactory::create()` and never edits
`ApplicationBlueprintCompiler` (01D-2 changes only that class's and the
factory's docblocks; the factory's `create()` body is untouched and remains
the single roster). Authorities: ADR-023 D-6 (the contract carries domain
intent only; every producer meets the same enforcement), #2788 acceptance,
`docs/specs/access-control.md`, `docs/specs/field-access.md`,
`docs/specs/content-workflow.md`, `docs/specs/config-management.md`. This
section is design evidence; the 01E review candidate earns its code.

### Mapping: blueprint governance element to canonical runtime target

Every API named below was verified in this tree at the cited line. Status
is one of **sufficient** (use as is), **extend** (a reusable framework
addition 01E makes upstream), or **gap** (no canonical target; recorded
here for #2788, never worked around in generated code).

| Element | Canonical runtime target | Status |
|---|---|---|
| `roles[]` | `ProvidesRolesInterface::roles(): iterable<Role>` on a service provider (`packages/foundation/src/ServiceProvider/Capability/ProvidesRolesInterface.php:22-33`), collected by `RoleRepository::fromProviders()` (`packages/user/src/RoleRepository.php:50-55`; duplicate ids fail closed, `:43-44, 70`), kernel-bound (`packages/foundation/src/Kernel/AbstractKernel.php:1269-1270`); `Role(id, label, permissions, weight)` (`packages/user/src/Role.php:23-27`); assignment stamps the union of registry-known role permissions onto the account (`packages/cli/src/Handler/UserAssignRoleHandler.php:82-85, 111-126`); `User::hasPermission()` reads that flat array (`packages/user/src/User.php:194-204`) and short-circuits on the `administrator` role (`:185, :197`). | sufficient — with one compile-time refusal: a blueprint role id equal to `administrator` is refused (decision (c)). |
| `permissions[]` | Enforcement authority is `AccountInterface::hasPermission(string)` over opaque strings (`packages/access/src/AccountInterface.php:20`). Catalogue authority: `PermissionHandlerInterface` (`packages/access/src/PermissionHandlerInterface.php:11-25`) is bound by nothing in production (`packages/access/src/Capability/McpApprovalCapabilities.php:18` records "framework binds no `PermissionHandlerInterface`"); the shipped catalogue shape is `AgentCapabilities::seed()` / `register(PermissionHandler)` (`packages/access/src/Capability/AgentCapabilities.php:83, 155-160`). Transition permissions are explicit strings resolved verbatim by `Workflow::permissionFor()` (`packages/workflows/src/Workflow.php:316-322`). | gap (recorded) + sufficient for enforcement — 01E emits the catalogue in the `seed()` shape and proves every referenced permission is catalogued at compile time and in a generated test; the framework cannot refuse boot on an unknown permission because no catalogue is bound (gap G1). |
| `policies[]` | One `#[PolicyAttribute(entityType: '<id>')]` class (`packages/access/src/Gate/PolicyAttribute.php:35-53`) implementing `AccessPolicyInterface` (`packages/access/src/AccessPolicyInterface.php:15-40`), discovered by source scan of the root application's PSR-4 prefixes (`packages/foundation/src/Discovery/PackageManifestCompiler.php:1207-1225`; the skeleton maps `App\` to `src/`, `skeleton/composer.json:14-16`), instantiated at boot by `Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry::discover()` (`packages/foundation/src/Kernel/Bootstrap/AccessPolicyRegistry.php:40-107`, called from `AbstractKernel.php:797`) which throws `PolicyInstantiationException` for any unresolvable constructor dependency (`:35-38, :122`), composed by `EntityAccessHandler::check()` (`packages/access/src/EntityAccessHandler.php:104-165`: Neutral start, `orIf`, Forbidden short-circuits) and `checkCreateAccess()` (`:438-460`). JSON:API applies `isAllowed()` on every path (`packages/api/src/JsonApiController.php:190, 353, 443-444, 463, 838-843, 1013-1014, 1445-1446`), so Neutral is deny. Decisions require an immutable `AuthorizationPrincipal` (`EntityAccessHandler.php:717-725`). The framework-default grants never reach generated types: `ContentAdminAccessPolicy` and `PublishedContentAccessPolicy` apply only to group `content` (`packages/access/src/Policy/ContentAdminAccessPolicy.php:45-57`, `PublishedContentAccessPolicy.php` `appliesTo`), and 01D-1 decision (e) registers generated types with no group. Note: the registry is not at `packages/access/src/Gate/AccessPolicyRegistry.php` (that path does not exist); `Gate/` holds `Gate`, `EntityAccessGate`, `PolicyAttribute`, `RevisionAccessRouter`. | sufficient for discovery, composition and default deny. |
| `condition.kind: permission` | `$account->hasPermission(<permission>)` inside the generated policy. | sufficient |
| `condition.kind: ownership` | Owner is the relationship field named by `keys.owner` (validated to be one, `packages/site-contract/src/Blueprint/ApplicationBlueprintValidator.php:129-131, 155-160`); owner equality follows `NodeAccessPolicy` exactly (`packages/node/src/NodeAccessPolicy.php:130-136`: authenticated AND owner not null AND `(string) id === (string) owner`, so anonymous is never an owner). Reading the owner value inside `access()`: a generated entity declares no `read:`, so the field is Internal and `get()` throws `FieldReadDenied` (`packages/entity/src/EntityValueContainer.php:68-71`; undeclared defaults to Internal, `packages/entity/src/EntityReadRuntime.php:176, 218`). The framework's own ownership readers escape scope with a bound closure into `EntityBase::$valueContainer->rawValues()` (`packages/node/src/NodeAuthorizationSnapshotReader.php:13-14`; the same pattern at fifteen production sites) — not an `@api` seam a consumer may copy. The V2 subject path (`ProtectedEntityReadPolicyInterface`, `PolicySubjectViewInterface`, both `@api`) receives declared inputs only through `@internal` interfaces (`packages/access/src/ClassifiedProtectedEntityReadPolicyInterface.php`, `ProjectedProtectedEntityReadPolicyInterface.php:10`) and is consulted for `view` only (`EntityAccessHandler.php:116-125`). | extend — 01E adds the L1 seam `Waaseyaa\Access\Read\AuthorizationInputReader` (decision (f)) and marks the owner field as an authorization input (decision (e)). |
| `condition.kind: workflow_state` | Requires the entity bound to exactly one workflow (validator `:295-305`). The bound entity must declare `status` and `workflow_state` as ordinary fields (`packages/entity/src/Write/EntityWritePayloadGuard.php:53`; the shipped shape is `packages/node/src/Node.php:63-64, 81-82`: `status` boolean Protected authorization input, `workflow_state` string `stored: FieldStorage::Data` Protected authorization input). State is read through the same `AuthorizationInputReader`. | extend — `EntityClassEmitter` declares the two engine-owned selectors on bound entities (decision (e)). |
| `workflows[]` (definition) | `Workflow` config entity, entity type `workflow` (`packages/workflows/src/Workflow.php:18-20`), constructed from the `DefaultWorkflows::EDITORIAL` array shape (`packages/workflows/src/DefaultWorkflows.php`: `id, label, initial_state, states{label, published, default_revision}, transitions{label, from, to, permission}`), validated by `WorkflowValidator` (`packages/workflows/src/Validation/WorkflowValidator.php:26-60`), seeded at provider boot through `getRepository('workflow')` with log-and-skip and additive top-up (`packages/workflows/src/WorkflowServiceProvider.php:290-362, 365-401`). | sufficient — mirrored by the generated governance provider (decision (g)); `default_revision` is derived (decision (g)). |
| `workflows[].bindings[]` | `workflows.assignments` config, key `<entity_type>.<bundle>` (`packages/workflows/src/Binding/WorkflowBindingResolver.php:30, 51-54`); a bundle-less entity reports its type id as bundle (`packages/entity/src/EntityBase.php:253-256`), so the exact key is `<id>.<id>`; CFG-03 schema `workflows.assignments@1` (`packages/workflows/src/Config/WorkflowAssignmentsConfig.php:14-17`); revisionable single-axis enforced at import and runtime (`WorkflowBindingResolver.php:62-90`). Activation is only a verified signed `config:import` (`docs/specs/config-management.md` CFG-02/CFG-03: production refuses unsigned activation until CFG-04; `docs/specs/content-workflow.md`: "Boot never copies legacy flat files or writes an assignment implicitly", bindings "are never copied into genesis or read directly from the sync directory at runtime"). Sync entry is `<sync>/workflows.assignments.yml` (`packages/config/src/Sync/ConfigSyncRepository.php:95-104`; read by `packages/cli/src/Handler/ConfigImportHandler.php:27`). | gap (recorded) — 01E emits the authored sync entry; activation is a runtime operation outside generation (gap G2). |
| `workflows[].transitions[]` | `TransitionService::transition()` (`packages/workflows/src/Transition/TransitionService.php:84-100`): binding, edge, permission (`:149-158`, `REASON_PERMISSION`), group, revision-id CAS, own tip revision (`:191-288`); `WorkflowStateGuard::onPreSave()` forces `initial_state` on create and permission-gates a non-initial create (`packages/workflows/src/Listener/WorkflowStateGuard.php:76-79, 139-175`); HTTP `POST /api/{type}/{id}/workflow/transition` maps `REASON_PERMISSION` to 403 (`packages/api/src/Controller/WorkflowTransitionController.php:185-224, 356-362`). Audit entries are written by the service itself (`content-workflow.md` "Integration"). | sufficient |
| `checks[]` | Generated companion tests. Precedent style is a snapshot `TestCase` under `tests/Acceptance/` (`packages/site-contract/src/Generation/SiteArtifactRenderer.php:148-176`; `packages/cli/src/Site/Recipe/GovernedAuthoringRecipe.php:319-351`). Runtime composition precedent: `tests/Integration/Phase7/JsonApiAccessIntegrationTest.php:96-104` (`new JsonApiController($entityTypeManager, $serializer, $accessHandler, $account)` asserting status codes) and `packages/workflows/tests/Integration/DepartmentRoutingFlowTest.php:335-352` (`MemoryStorage` binding, SQLite repositories). Consumer harness: `Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory::authenticated(id, roles, permissions)` / `anonymous()` (`packages/testing/src/Factory/AuthorizationPrincipalFactory.php:22-59`), `TemporarySqliteDatabase`, `EntityFactory`. `InteractsWithApi` builds request descriptors and dispatches nothing (`packages/testing/src/Traits/InteractsWithApi.php:35-37`). | sufficient for in-process enforcement tests; gap G3 for the verify roster; gap G4 for an HTTP-level consumer harness. |
| API exposure | Generic JSON:API routes exist only for types whose definition says `api: true` or that the operator allowlists in `api.entity_type_allowlist` (`packages/entity/src/EntityType.php:56, 81`; `packages/entity/src/Attribute/ContentEntityType.php:39` defaults `false`; `packages/api/src/EntityTypeApiExposure.php:12-22`; `packages/api/src/EntityTypeApiExposurePolicy.php:25-45`; `packages/api/src/JsonApiRouteProvider.php:95-102`). 01D-1 emits no `api:` argument, so generated types are not exposed. | sufficient — exposure stays a deployment decision, decision (j). |

### Decisions

**(a) Emitters only through the factory roster.** 01E appends five
emitters to `ApplicationBlueprintCompilerFactory::create()` after the
01D-1 three, in this order: `PermissionCatalogueEmitter`,
`AccessPolicyEmitter`, `WorkflowDefinitionEmitter`,
`GovernanceProviderEmitter`, `GovernanceCheckEmitter`. The compiler's
invariants already hold the rest: unique ids, pairwise-disjoint paths,
sorted union, registrations sorted by the compiler (`ApplicationBlueprintCompiler.php:103-134`).
Order is therefore a readability contract (catalogue, then what references
it), not an ownership mechanism; no emitter's output depends on another's.
`ApplicationBlueprintCompiler.php` is not edited. Each new emitter is a
pure function of `(ApplicationBlueprint, SiteManifest)` with no filesystem,
clock or environment access, declared `@api` in `packages/cli/public-surface.php`
beside the 01D-1 entries (`packages/cli/public-surface.php:36-42`).

**(b) A second provider, not a second registration row.** `ArtifactPlan`
refuses a plan that declares the same provider FQCN twice at construction
(`packages/site-contract/src/Generation/ArtifactPlan.php:120-122`), before
the engine's `GEN012_REGISTRATION_OWNERSHIP_CONFLICT` checks, which govern
cross-unit and application-owned providers
(`packages/cli/src/Site/SiteInitializationService.php:882-889`). So a
governance emitter cannot add a row for
`App\Provider\ApplicationBlueprintServiceProvider`. 01E emits a distinct
provider, `App\Provider\ApplicationBlueprintGovernanceServiceProvider`
(`src/Provider/ApplicationBlueprintGovernanceServiceProvider.php`), with its
own `ComposerProviderRegistration` and no group. Rejected alternatives:
extending `ProviderRegistrationEmitter` to also carry roles and the workflow
seed (couples entity registration to governance, rewrites 01D-1's golden
provider fixtures, and makes the entity provider implement capabilities it
does not own); and letting roster order decide which emitter "owns" the
provider file (path sets must be disjoint by the compiler's own invariant,
so ownership by order is exactly what the seam forbids). Policies need no
provider at all: `#[PolicyAttribute]` discovery is a source scan.

**(c) Default deny; explicit allow per rule; no implicit administrator.**
A generated policy starts every decision `Neutral` and returns `Allowed`
only when a declared rule for that exact operation matches: `permission`
requires `$account->hasPermission(p)`; `ownership` requires the permission
and the owner equality of `NodeAccessPolicy.php:130-136`; `workflow_state`
requires the permission and the entity's current `workflow_state` in the
rule's `states`. It never returns `Forbidden`, so a stricter framework or
application policy still wins, and it never inspects roles: role membership
reaches a decision only through the permissions `user:assign-role` stamps.
An entity that declares no policy gets no policy class, and every operation
on it is denied by `isAllowed()`. Three declarations the validator admits
cannot be represented safely and are refused by the emitter as
`GEN007_UNSUPPORTED_DECLARATION` with a pointer (the coded refusal ADR-025
D-5 reserves for an unsupported declaration at the plan-compilation
boundary; the 01D-2 handler already widens the coded envelope for every
blueprint invocation): a role id `administrator`
(`User::ADMINISTRATOR_ROLE`, `User.php:185`, grants every permission
implicitly); an `ownership` or `workflow_state` condition on operation
`create` (no entity instance exists at create; `checkCreateAccess()`
receives only type and bundle, `EntityAccessHandler.php:438`); and a
permission whose derived constant name collides with another's (decision
(d)). Tightening the validator itself (`SITE047` for those conditions) is a
01B-authority change recorded as follow-up F1, not made here.

**(d) Permission catalogue.** `PermissionCatalogueEmitter` emits
`src/Access/ApplicationBlueprintPermissions.php`: one `public const string`
per permission (name `PERMISSION_` + the id upper-snaked, spaces and
hyphens to `_`; a collision after conversion is `GEN006_MALICIOUS_IDENTIFIER`,
the compiler's class-name-collision precedent), `seed(): array<string, array{title: string, description: string}>`
in the `AgentCapabilities::seed()` shape (`description` is the title; the
contract has no description), and `register(PermissionHandler $handler): void`
mirroring `AgentCapabilities::register()` (`AgentCapabilities.php:155-160`)
for an application that binds a handler. Every permission a role,
transition, policy condition or check references is already validated to
exist (`SITE042`); the emitted catalogue is the single source the generated
provider, definition and tests read constants from, so a generated
artifact cannot name a permission the catalogue lacks. No `*` can reach
this emitter (`SITE045`).

**(e) `EntityClassEmitter` learns the governance read shape.** The
blueprint contract has no per-field read classification, and 01D-1 R2-5
left every generated field Internal, so no generated entity is readable by
anyone. 01E closes that for generated entities with three rules, each
mirroring a shipped declaration: every ordinary declared field and every
relationship field carries `read: \Waaseyaa\Entity\FieldReadLevel::Public`
(the entity-level policy is the gate; field access stays open-by-default
per `docs/specs/field-access.md`); the relationship field named by
`keys.owner` instead carries `read: Protected` and
`settings['authorizationInput' => true]` exactly as `Node::$uid`
(`Node.php:84-85`), so it is an authorization input and never part of the
ordinary projection (`packages/api/src/ResourceSerializer.php:118-134,
247-248` drops Protected fields and catches `FieldReadDenied`, so no
protected-read grant is required for serialization); and an entity bound to
a workflow additionally declares `status` and `workflow_state` byte-for-byte
in Node's shape (`Node.php:63-64, 81-82`, `use Waaseyaa\Field\FieldStorage;`,
`stored: FieldStorage::Data`), because they are engine-owned selectors the
contract never authors. Golden fixtures for `minimal.yaml` and
`complete.yaml` change accordingly and are re-recorded in 01E. A
contract-level `read_level` per field (follow-up F2) would override the
first rule when it exists; a blueprint-declared `default_revision` per
state (F3) would override decision (g).

**(f) One reusable authorization-input seam, added upstream.** Reading
`keys.owner` and `workflow_state` inside `access()` has no consumer-facing
API today (mapping row "ownership"). 01E adds
`Waaseyaa\Access\Read\AuthorizationInputReader` (`packages/access/src/Read/`,
final, `@api`, declared in `packages/access/public-surface.php`) with
`read(EntityBase $entity): array<string, mixed>` returning only the fields
the entity's read layout marks as authorization inputs
(`settings.authorizationInput`, derived at `EntityReadRuntime.php:178-192`,
exposed by `EntityReadLayout::authorizationInputsFor()`/`levels()`,
`packages/entity/src/EntityReadLayout.php:91, 119`), implemented with the
same bound-closure read the framework's own readers use
(`NodeAuthorizationSnapshotReader.php:13-14`,
`packages/workflows/src/Read/WorkflowEntitySnapshotReader.php:11-20`, the
latter already `@api`). It reads no ordinary field, needs no account read
context (so it cannot throw `MissingFieldReadContext`), and returns an
immutable array. It is the L1 generic analogue of the L2 node reader and is
the fix the change record's intent requires ("reusable Framework gaps are
fixed upstream"). Rejected: promoting the `@internal` input-declaring V2
interfaces to `@api` (covers `view` only) and emitting the bound closure
into consumer code (a private-scope escape is not a contract). Generated
policies take the reader as a nullable constructor parameter resolved in
the body (`?AuthorizationInputReader $inputs = null`), which
`AccessPolicyRegistry` instantiates without a container binding.

**(g) Workflow definition and seed.** `WorkflowDefinitionEmitter` emits
`src/Workflow/<PascalCase(workflow.id)>WorkflowDefinition.php`, a final
class with `public const array DEFINITION` in the exact
`DefaultWorkflows::EDITORIAL` shape: `states[<id>]` carries `label`,
`published`, and `default_revision` derived as `published` (a published
state promotes its revision to default, `content-workflow.md` "State lives
on revisions"; the contract cannot express an unpublished default-revision
state such as the shipped `archived`, follow-up F3); `transitions[<id>]`
carries `label`, sorted `from`, `to`, and the blueprint's explicit
`permission`, which `Workflow::permissionFor()` returns verbatim
(`Workflow.php:318-321`). The generated governance provider's `boot()`
seeds it exactly as `WorkflowServiceProvider::seedDefaultEditorialWorkflow()`
(`WorkflowServiceProvider.php:318-362`): resolve the entity type manager
optionally, `getRepository('workflow')`, return on the `[S1-DB106]`
uninstalled-schema message, `WorkflowValidator` log-and-skip, `enforceIsNew()`,
save inside try/catch, and additive top-up of missing states and transitions
by machine name when the id already exists (`:365-401`). Boot never
deletes or rewrites an existing state or transition. The same emitter emits
`config/sync/workflows.assignments.yml` with one entry
`<entity.id>.<entity.id>: <workflow.id>` per binding, in the CMI simple-config
sync form the importer reads (`ConfigImportHandler.php:27`,
`ConfigSyncRepository.php:95-104`).

**(h) Governance provider.** `GovernanceProviderEmitter` emits
`src/Provider/ApplicationBlueprintGovernanceServiceProvider.php`: a final
`ServiceProvider` implementing `ProvidesRolesInterface`, whose `roles()`
yields one `Role(id, label, permissions)` per blueprint role using the
catalogue constants (sorted permissions, `weight` default), `register()`
binds nothing, and `boot()` runs the seed of decision (g) for each declared
workflow. It emits one `ComposerProviderRegistration` for that FQCN with no
group (decision (b)). Roles reach accounts only through
`user:assign-role`; nothing in generated code assigns a role or a
permission to any account.

**(i) Checks become companion tests.** `GovernanceCheckEmitter` emits
`tests/Blueprint/RolePermissionChecksTest.php`,
`tests/Blueprint/WorkflowTransitionChecksTest.php` and
`tests/Blueprint/EntityAccessChecksTest.php` (each only when the blueprint
declares a check of that kind) plus the always-emitted
`tests/Blueprint/GovernanceDefaultDenyTest.php`, all listed as
`companionTests`. Namespace `App\Tests\Blueprint` (skeleton autoload-dev
`App\Tests\` to `tests/`, `skeleton/composer.json:19-21`), extending
`PHPUnit\Framework\TestCase` like every generated test today. Principals
come from `AuthorizationPrincipalFactory::authenticated(id, roles: [<role>], permissions: <exact role permissions from the catalogue>)`
and `anonymous()` — never a `User` entity (`EntityAccessHandler.php:717-725`).
`role_permission` asserts against `RoleRepository::fromProviders([new ApplicationBlueprintGovernanceServiceProvider(...)])`.
`entity_access` composes `new EntityAccessHandler([new <Entity>Policy()])`
and asserts `check()->isAllowed()` (or `checkCreateAccess()` for `create`)
equals the expectation, on a subject entity constructed in memory from the
referenced fixture's declared values (pure blueprint data; nothing is
persisted). `workflow_transition` composes the generated definition into a
`Workflow`, a `MemoryStorage`-backed `ConfigFactory` carrying the emitted
assignment, `WorkflowBindingResolver`, an `EntityTypeManager` holding the
generated entity types, SQLite repositories through
`TemporarySqliteDatabase`, and
`new TransitionService(bindings:, entityTypeManager:, workflowValues: new WorkflowEntitySnapshotReader())`
(the provider's own composition, `WorkflowServiceProvider.php:100-118`);
`denied` expects `TransitionDeniedException` with `REASON_PERMISSION`,
`allowed` expects a `TransitionResult`. `GovernanceDefaultDenyTest` asserts,
for every entity and every operation, that `anonymous()` and an
authenticated principal with no permissions are denied; that no emitted
role is `administrator`; that every permission a role, transition or policy
names is a catalogue constant; and that every entity with a policy has a
discovered `#[PolicyAttribute]` class. `fixture_present` and any check that
needs a persisted fixture row belong to 01D-3 and are not emitted.

**(j) Exposure is not emitted.** Generated types stay `api: false`
(mapping row "API exposure"). Turning generic JSON:API routes on is a
deployment decision through `api.entity_type_allowlist`, not blueprint
intent, and the in-process tests exercise `JsonApiController` and the
access handler directly, which do not depend on route exposure. An
unprotected exposed entity is therefore impossible from generation alone.

**(k) What 01E does not claim.** 01E proves governance composition
in-process through the same handler, service and predicate JSON:API and
the transition endpoint apply. It does not claim #2789's packaged
HTTP-level acceptance, does not activate configuration, and does not
materialize fixtures.

### Gaps recorded for #2788 (not worked around)

- **G1 — no bound permission-catalogue authority.** `PermissionHandlerInterface`
  has no production binding, so the framework cannot refuse boot on an
  unknown permission. 01E enforces the invariant at compile time and in
  `GovernanceDefaultDenyTest`. Candidate framework fix: a
  `ProvidesPermissionsInterface` provider capability collected into a
  kernel-bound `PermissionHandler`, the role registry's pattern
  (`AbstractKernel.php:1269-1270`).
- **G2 — no pure-generation path activates a workflow binding.**
  Activation requires a signed, verified `config:import`
  (`config-management.md` CFG-02/CFG-03) and production refuses unsigned
  activation until CFG-04 supplies key custody; boot may not write an
  assignment. 01E emits the authored sync entry; the generated transition
  tests bind through `MemoryStorage` as the framework's own tests do.
  Activation is a runtime operation for 01D-3 / #2789, sequenced behind
  CFG-04.
- **G3 — generated companion tests are not on the verify roster.**
  `bin/maintenance/site-verify` is rendered with a roster fixed at render
  time from recipe tests only (`SiteArtifactRenderer.php:38-46, 180-186`),
  and `ArtifactPlan::$companionTests` has no production consumer. The
  01D-1 compiler composes the renderer's output unchanged, so emitter tests
  need a compiler-side change (re-render or pass the union of
  `companionTests`), which 01E may not make. Recorded for the slice that
  next owns `ApplicationBlueprintCompiler` after 01D-2 lands; a second
  runner is not an acceptable substitute.
- **G4 — no consumer-facing in-process HTTP harness.** `InteractsWithApi`
  builds descriptors and `CreatesApplication` is a service bag
  (`packages/testing/src/Traits/InteractsWithApi.php:35-37`,
  `CreatesApplication.php:16`). Generated tests therefore compose the
  controller and services directly, the framework's own precedent; the
  HTTP journey is #2789's harness.
- **G5 — the field-read default.** A registered type's undeclared fields
  are Internal (`EntityReadRuntime.php:176, 218`); decision (e) declares
  levels explicitly for generated entities and F2 records the contract
  extension.

Follow-ups outside 01E: **F1** validator refusal (`SITE047`) for
`ownership`/`workflow_state` on `create` and for the `administrator` role
id (01B authority); **F2** per-field `read_level` in the contract (01B);
**F3** per-state `default_revision` in the contract (01B); **F4** #2848
converges `make:policy` and `scaffold:workflow` on these emitters'
renderers so manual scaffolds and compiled blueprints produce equivalent
registrations.

### 01E emitters

| Emitter (id) | Output | Registrations / companion tests |
|---|---|---|
| `PermissionCatalogueEmitter` (`permission-catalogue`) | `src/Access/ApplicationBlueprintPermissions.php` | — |
| `AccessPolicyEmitter` (`access-policy`) | `src/Access/<PascalCase(entity.id)>Policy.php` per entity with at least one policy | — |
| `WorkflowDefinitionEmitter` (`workflow-definition`) | `src/Workflow/<PascalCase(workflow.id)>WorkflowDefinition.php` per workflow; `config/sync/workflows.assignments.yml` when any binding exists | — |
| `GovernanceProviderEmitter` (`governance-provider`) | `src/Provider/ApplicationBlueprintGovernanceServiceProvider.php` | one registration, FQCN `App\Provider\ApplicationBlueprintGovernanceServiceProvider`, no group |
| `GovernanceCheckEmitter` (`governance-checks`) | `tests/Blueprint/GovernanceDefaultDenyTest.php` always; `RolePermissionChecksTest.php`, `WorkflowTransitionChecksTest.php`, `EntityAccessChecksTest.php` when declared | every emitted test path |

Roster after 01E: `entity-class`, `relationship-registry`,
`provider-registration`, `permission-catalogue`, `access-policy`,
`workflow-definition`, `governance-provider`, `governance-checks`.

Framework additions outside the emitters: `packages/access/src/Read/AuthorizationInputReader.php`
(decision (f)) and the three `EntityClassEmitter` rules of decision (e).

### 01E red tests

1. `packages/cli/tests/Unit/Site/Blueprint/Emitter/PermissionCatalogueEmitterTest.php` —
   golden artifact for `complete.yaml`; constants, `seed()` and `register()`
   round-trip through a real `PermissionHandler`; two ids that upper-snake
   to one constant are `GEN006`; the emitted source never contains `*`.
2. `AccessPolicyEmitterTest.php` — golden `src/Access/ArticlePolicy.php`;
   no class for `person`; the generated class is loaded and composed into a
   real `EntityAccessHandler` with `AuthorizationInputReader`: viewer
   principal `view` allowed, `update` neutral; editor owner `update`
   allowed, editor non-owner neutral, anonymous never owner; workflow-state
   rule allowed in `draft`, neutral in `published`; `create` with a
   permission rule allowed, without it neutral; `ownership`/`workflow_state`
   on `create` and a role id `administrator` refuse `GEN007` with pointers
   before any artifact is produced; the class never returns `Forbidden`.
3. `WorkflowDefinitionEmitterTest.php` — golden definition and assignments
   yml; `new Workflow(<DEFINITION>)` passes `WorkflowValidator`;
   `permissionFor()` equals the blueprint's transition permission;
   `default_revision` equals `published` per state; an entity id and
   workflow id round-trip as `article.article: editorial`.
4. `GovernanceProviderEmitterTest.php` — golden provider; registration FQCN
   differs from 01D-1's and carries no group; `RoleRepository::fromProviders()`
   yields exactly the blueprint roles and permissions; seeding against an
   in-memory `workflow` repository creates the definition once and tops up
   an existing one additively without deleting a state or transition.
5. `GovernanceCheckEmitterTest.php` — golden tests for `complete.yaml`'s
   three emitted checks and the default-deny test; `companionTests` equals
   the emitted paths; no `fixture_present` artifact; the emitted tests
   never reference `administer`, `administrator`, or a `*` permission.
6. `ApplicationBlueprintCompilerTest.php` (extended) — the factory roster
   ids and order are exactly the eight above; `complete.yaml` compiles
   deterministically with two registrations of distinct FQCNs; the union
   path set is disjoint; the compiler file is byte-identical to 01D-1's.
7. `EntityClassEmitterTest.php` (extended) — the owner field carries
   `read: Protected` and `authorizationInput`; a bound entity declares
   `status` and `workflow_state` in Node's shape and an unbound one does
   not; ordinary fields carry `read: Public`; a generated entity loaded
   through the real read runtime answers `get('title')` and refuses
   `get('author')` without context (R2-5 closed for ordinary fields, kept
   for authorization inputs).
8. `packages/access/tests/Unit/Read/AuthorizationInputReaderTest.php` —
   returns only authorization-input fields; returns `[]` for an entity
   without the marker; works with no guard installed; the returned array is
   a copy.
9. `tests/Architecture/BlueprintGovernanceEmitterBoundaryTest.php` — the
   five emitter sources contain no filesystem, clock or environment calls
   (the 01D-1 scan extended); `ApplicationBlueprintCompiler.php` is
   unchanged from 01D-1; every emitted golden references only `@api`
   framework types; `packages/cli/public-surface.php` and
   `packages/access/public-surface.php` declare the new types.
10. `packages/cli/tests/Integration/Blueprint/GeneratedGovernanceExecutionTest.php` —
    materializes `complete.yaml`'s compiled plan into a `TemporaryDirectory`,
    autoloads `App\` from it, and runs the emitted `tests/Blueprint` suite
    through `proc_open` (POSIX-only, as the release-tooling tests), asserting
    exit 0: packaged tests exercise runtime enforcement, not only snapshots.

### Out of scope for 01E

Fixture materialization, `fixture_present` checks and any persisted-fixture
dependency (01D-3); converging `make:policy`/`scaffold:workflow` on these
emitters (#2848, F4); the packaged HTTP-level proof and JSON:API exposure
(#2789, decision (j)); configuration signing and activation (CFG-04, G2);
wiring generated tests into `bin/maintenance/site-verify` (G3, compiler
owner); anything AI-related; wildcard or administrator grants; a policy
expression, script, callable or regex condition; per-field read
classification and per-state `default_revision` in the contract (F2, F3);
and any edit to `ApplicationBlueprintCompiler.php`, `SiteArtifactRenderer.php`
or `SiteInitializationService.php`.
