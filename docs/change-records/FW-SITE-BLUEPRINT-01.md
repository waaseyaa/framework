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
