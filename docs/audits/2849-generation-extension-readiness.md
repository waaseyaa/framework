# #2849 — generation extension readiness: search, ingestion, seed

Recorded while implementing #2849's search slice. Scope: which of the three
optional application areas can be generated today against *stable, declared*
Framework extension contracts, and what precisely blocks the other two.

Evidence was re-derived from the tree at `5c16aa2f7`; every claim below cites
the file it came from. This is a findings record, not a decision: it
authorizes no implementation and changes no behaviour.

## Summary

| Area | Extension contract | Verdict |
|---|---|---|
| Search | `EntitySearchProjectorInterface`, `ProvidesEntitySearchProjectorsInterface` — both `@api` **and** declared `public` | **Shipped** — `make:search-projection` |
| Ingestion | `EnvelopeValidator`, `PayloadValidatorInterface` — declared **`internal`** | **Blocked** — needs a supported seam (below) |
| Seed | No generator, registration or determinism contract | **Blocked** — needs a decision (below) |

## A. Scaffolded entities index empty — reproduction for #2847

A `make:content-type` scaffolded entity, indexed by *any* search projector,
produces an empty document. Nothing errors; the row is simply blank.

**Cause.** `ContentTypeScaffoldCompiler` emits no `read:` argument on the
`#[Field]` attributes it generates (`grep -c FieldReadLevel
packages/cli/src/Site/Scaffold/ContentTypeScaffoldCompiler.php` → `0`). For a
**registered** entity type, a field whose read level is undeclared resolves to
`FieldReadLevel::Internal`
(`packages/entity/src/EntityReadRuntime.php:186`, and the undeclared default at
`:218`). Index-time projection runs with no account scope, so only
`FieldReadLevel::Public` fields release a value; the projector's guarded
accessor catches the denial and omits the field, which is correct behaviour
given the classification it was handed.

This is invisible to the ordinary unit-test idiom: an anonymous, *unregistered*
`ContentEntityBase` fixture takes the other branch of the same line and
defaults every undeclared field to `Public`, so a projector test passes while
the real application indexes nothing.

### Exact reproduction

Generated entity — byte-for-byte what `make:content-type story
--fields="title:string,body:text"` emits today:

```php
#[ContentEntityType(id: 'story', label: 'Story')]
#[ContentEntityKeys(label: 'title')]
final class Story extends ContentEntityBase
{
    #[Field(type: 'boolean', label: 'Published', default: true)]
    public bool $status = true;

    #[Field(type: 'string', label: 'Title')]
    public string $title = '';

    #[Field(type: 'text', label: 'Body')]
    public string $body = '';
}
```

Registration setup: none beyond the attributes — carrying
`#[ContentEntityType]` is what puts the class on the registered branch
(`EntityReadRuntime::classDefinitions()`, consumed at
`packages/entity/src/EntityReadRuntime.php:88-89`).

Projection invocation:

```php
$story = new Story(['id' => 7, 'title' => 'Fall harvest', 'body' => 'Wild rice camp opens Monday.']);
$document = new NodeSearchProjector(entityTypeId: 'story', bodyFields: ['body'])->project($story);
```

Observed today:

```
read levels:   title => internal   body => internal   status => internal
document:      ["title"]=> ""      ["body"]=> ""
```

Expected after the producer-side fix — identical invocation, with the emitter
declaring the classification it intends:

```
read levels:   title => public     body => public
document:      ["title"]=> "Fall harvest"   ["body"]=> "Wild rice camp opens Monday."
```

Declaring `read: FieldReadLevel::Public` on those two `#[Field]` attributes
produces that output and changes nothing else.

**This is not a recommendation to widen fields.** The restricted default is
correct and must be preserved: it is what keeps unclassified data out of a
public index. The defect is that the classification is never *decided* — the
emitter leaves it implicit, so the developer neither chose Public nor chose to
keep the field out of the index. Whatever #2847 does, a field should become
Public because someone decided its content is publishable, never to make an
empty index look populated. Fields not meant to be searchable should stay
restricted and simply not be indexed.

### Ownership

The consumer side is closed: generated projectors read through the guarded
accessor, and
`tests/Integration/Generation/SearchProjectionScaffoldRuntimeTest.php` proves
against a registered entity type that a Public field reaches the index while an
undeclared (`Internal`) field and a `Protected` field with no granting policy
do not — at index time and through `EntitySearchCandidateResolver`'s real
`EntityAccessHandler` + `AccountFieldReadScope`.

The producer side — what classification `make:content-type` should emit, and
whether that is an authored input or a default — belongs to #2847. It is a
deliberate omission here: changing default visibility from a search lane would
be a security-relevant change made from the wrong side of the boundary.

## B. Ingestion — the public-contract contradiction

`Waaseyaa\Ingestion\EnvelopeValidator`
(`packages/ingestion/src/EnvelopeValidator.php:15`) is an abstract class whose
own docblock reads: *"Applications extend this to provide their supported
versions, entity types, and entity-specific validation rules via
`validateEntityData()`."* The same docblock carries `@internal` (line 13), and
`packages/ingestion/public-surface.php:11` declares it
`'disposition' => 'internal'`. `PayloadValidatorInterface` is the same on both
counts (`public-surface.php:12`).

Per `docs/specs/public-surface-declarations.md` §1-2 the package-local
declaration is the single editable authority and the disposition column is what
distinguishes a commitment from a non-commitment. So the class is documented as
an application extension point and simultaneously declared not to be one.

ADR-012 (`docs/adr/012-migration-platform-out-of-scope.md:13`) is consistent
with the declaration, not the docblock: consumer applications "may keep an
`Ingestion` namespace ... That remains app-shaped, not framework-shaped."

Two further gaps are independent of that contradiction:

- **No registration or discovery.** There is no adapter registry, attribute or
  provider convention (`rg -n "IngestionSource|IngestionAdapter|IngestionMapper"`
  → no matches). `ingest:run`
  (`packages/cli/src/Handler/IngestRunHandler.php`) is a fixed pipeline over
  CLI-package-private collaborators in `packages/cli/src/Ingestion/`, not a
  plugin host.
- **No consumer example** exists in the repo, `skeleton/`, or
  `tests/PackagedForm/`.

**Why nothing was scaffolded.** Generating consumer code that extends an
`internal`-declared class would manufacture a support obligation the framework
has explicitly declined, and would be unfixable from the consumer side when the
class changes. #2849's own acceptance requires "stable Framework extension
contracts"; there is not one here yet.

### Proposed supported seam (for review, not adopted here)

1. Resolve the contradiction in one direction deliberately. Either promote a
   *successor* contract to `public` — not `EnvelopeValidator` itself, whose
   shape is entangled with the CLI pipeline — or affirm ADR-012 and state that
   ingestion adapters stay app-shaped, in which case #2849's ingestion clause
   should be narrowed rather than implemented.
2. If promoting: introduce a minimal `IngestionAdapterInterface` in
   `waaseyaa/ingestion` carrying only the entity-mapping boundary
   (`supports(Envelope): bool`, `map(Envelope): iterable`), declared `public`,
   and let it compose the already-usable Foundation validation surface
   (`MessageEnvelopeValidator`, `PayloadValidator`, `IngestionError`,
   `IngestionErrorCode`, `InvalidEnvelopeException`) rather than re-declaring
   envelope shape.
3. Add discovery in the idiom the framework already uses: a
   `ProvidesIngestionAdaptersInterface` bound in a `ServiceProvider::register()`,
   mirroring `ProvidesEntitySearchProjectorsInterface`, so `ingest:run` resolves
   application adapters instead of hard-wiring its pipeline.
4. Only then scaffold, through the same seeded-unit path
   `make:search-projection` uses.

Step 1 is a decision, and it is the blocker.

## B.1 Retained fixture commands and their narrower purposes

#2849 requires that existing fixture commands be "composed, migrated, or
explicitly retained with a documented narrower purpose." ADR-025 records all
three as `keep`
(`docs/adr/data/025-generation-command-inventory.json:193,203,213`). Their
narrower purposes, documented here rather than left implied:

| Command | Narrower purpose | Why it is not a generator |
|---|---|---|
| `fixture:scaffold` | Emits one deterministic ingestion *scenario document* as JSON, for authoring and reviewing ingestion fixtures | Writes only to a path the operator names via `-o`, or stdout. Produces no application source, mutates no `composer.json`, and records no ownership in `.waaseyaa/generated.json` |
| `fixture:generate` | Same shape for generated scenario payloads | Same rationale; the ADR records it verbatim as "same shape and rationale as `fixture:scaffold`" |
| `fixture:pack:refresh` | Re-aggregates an existing directory of scenario `.json` files into a refreshed pack | Reads and re-emits fixture documents only; never touches application source or `composer.json` |

All three are deterministic by construction — timestamps are CLI options with
fixed defaults and orderings are forced with `ksort`/`usort` before encoding —
so none is a source of the nondeterminism a seed generator would have to
solve. None of them seeds a database, and none is a candidate for migration
into the plan/apply engine: an operator-named output path is not project-owned
generated state, which is exactly the distinction ADR-025 D-2 draws.

They are therefore **retained, not superseded**, and a future seed generator
does not replace them.

## C. Seed — the declarations exist; the emitter is a deferred, named slice

An earlier pass concluded that no machine-readable canonical declarations
existed for seeding. **That was wrong**, and it was wrong because it looked
only at `FieldTypeManager`, `packages/relationship/` and `DefaultWorkflows`
and never opened `packages/site-contract/src/Blueprint/`. The corrected
finding is narrower and more actionable.

### The declarations are present, governed, and required

`fixtures` is not an optional afterthought in the governed blueprint — it is a
**required** root key alongside `entities`, `relationships` and `workflows`
(`packages/site-contract/src/Blueprint/ApplicationBlueprintSchema.php:38`).
`blueprintFixture` is a closed object of `{id, entity, values, workflow_state}`
(`:215-225`), cross-validated against entity and workflow declarations by
`ApplicationBlueprintValidator` (`packages/site-contract/src/Blueprint/ApplicationBlueprintValidator.php:355-366`
for fixture/workflow-state binding). The document is parsed without a kernel,
straight from `.waaseyaa/site.yaml`
(`packages/site-contract/src/SiteManifestParser.php:80-81`).

Per-declaration verdicts for what a seed generator would need:

| Declaration | Verdict | Evidence |
|---|---|---|
| Entity/field | metadata present | `BlueprintFieldType` is a closed 13-case enum, and `GovernanceCheckEmitter::sampleLiteral()` already synthesises a representative value per type (`packages/cli/src/Site/Blueprint/Emitter/GovernanceCheckEmitter.php:1157-1170`) |
| Relationship | metadata present; ordering gap | `from/to/cardinality/on_delete` declared (`ApplicationBlueprintSchema.php:98-120`), but `renderJsonApiCreatePayload()` hardcodes required targets to id `1` (`GovernanceCheckEmitter.php:1136-1139`) — nothing derives a dependency-ordered creation graph |
| Workflow | metadata present | `initial_state`, `states[].published`, `bindings[].entity` (`ApplicationBlueprintSchema.php:168-204`), already consumed for `workflow_state` |

So the acceptance clause "consumes canonical entity/relationship/workflow
declarations" is *satisfiable today*. What is missing is not metadata.

### What is actually missing

- **The emitter, deferred by name.** `ApplicationBlueprintCompilerFactory`
  wires eight emitters and its docblock states "01D-3 appends fixtures the same
  way" (`packages/cli/src/Site/Blueprint/ApplicationBlueprintCompilerFactory.php:23`).
  `GovernanceCheckEmitter` is blunter: "fixture materialization is 01D-3, a
  later slice — nothing seeds a blueprint fixture as a real persisted row yet"
  (`Emitter/GovernanceCheckEmitter.php:32`).
- **A registration/discovery contract.** None exists
  (`rg -n "SeedProvider|FixtureProvider|ProvidesSeed|ProvidesFixture"` returns
  only unrelated test doubles).
- **A determinism contract** beyond `ConfigSyncFile::deterministicUuid()`
  (`packages/config/src/Sync/ConfigSyncFile.php:325`), which is UUID-shaped
  only. No seeded PRNG or general stable-value derivation ships.
- **A runtime-mutation rollback authority.** `SiteInitializationService`'s
  journal covers file artifacts. Nothing equivalent exists for entity rows.

The production guard, by contrast, already exists:
`RuntimePolicy::isProductionLike()` (`packages/foundation/src/Kernel/RuntimePolicy.php:45-58`)
and the narrower exact-env `DevelopmentInterruptionSeam`
(`packages/cli/src/Site/DevelopmentInterruptionSeam.php:76-81`).

### Three unrelated things are called "seed" — do not conflate them

1. `waaseyaa.site-seed` v1 — init-time preset answers
   (`packages/site-contract/src/Seed/SiteSeedParser.php:32`). Not demo data.
2. `seedSubject()`/`seedWorkflow()` emitted into generated governance tests and
   providers — in-process test fixtures and boot-time catalogue bootstrap
   (`Emitter/GovernanceCheckEmitter.php:839`, `Emitter/GovernanceProviderEmitter.php:114,122`).
3. Blueprint `fixtures` — the demo/representative content #2849 means. Only
   this one is unimplemented.

### The precise design decision needed

The architecture question is already **decided and recorded**, which is the
most important correction here. `docs/change-records/FW-SITE-BLUEPRINT-01.md:249-257`
states: "materializing fixture rows is a runtime operation, not generation …
The decided direction is a development-gated seed command exposed by the
generated provider and refused outside development, recorded as candidate
**01D-3**." ADR-025's inventory is consistent, recording all three `fixture:*`
commands `keep` precisely because they are never project-owned generated
artifacts (`docs/adr/data/025-generation-command-inventory.json:193,203,213`).

What remains open, phrased so a reviewer can accept or reject each:

1. **Seeding is a runtime mutation, not a generation unit** — therefore
   `ArtifactPlan`/`SiteInitializationService` own none of it, and #2849's
   "each generator uses the shared plan/apply engine" clause does **not**
   extend to seeded rows. Accept, or overturn the recorded direction.
2. **If (1) holds, idempotency and rollback need a named owner** for entity
   rows, since the generation journal deliberately does not cover them. Either
   a documented "re-running replaces by fixture id" contract, or an explicit
   statement that seeding is non-transactional.
3. **The environment gate is exact-match `development`, not the broad
   `dev/development/local/testing` set** — or the reverse. This is what
   "distinguishes development/test fixtures from production mutation"
   operationally resolves to, and the two options differ materially.
4. **v1 accepts single-scalar sample synthesis and does not build a
   relationship-ordered seed graph**, matching what the shipped emitters
   already do — or it does, and cardinality-aware ordering is in scope.
5. **Blueprint-declared `fixtures` is the whole seed surface for v1**, with no
   application-authored extension point — or a registration contract is
   required, which is new design surface.
6. **#2849's seed clause is satisfied by 01D-3 landing**, or #2849's seed
   scope should be explicitly re-cut to reference 01D-3 rather than duplicate
   it. As written, two issues describe the same capability.

Decision 6 is the one that unblocks #2849's seed acceptance; the rest are
01D-3's to answer.

### Proposed issue relationship (a proposal, not a resolution)

#2849's seed clause and candidate 01D-3 describe one capability: materializing
blueprint-declared `fixtures` as real rows, development-gated. Two issues
owning it invites two implementations, which is the failure mode ADR-025
exists to prevent.

The proposal, for the #2844 program owner to accept or reject, is that 01D-3
remains the single implementer and #2849's seed clause is re-cut to *depend
on* it rather than restate it — leaving #2849 responsible only for the
generator-and-registration surface, if any, that sits above it.

**This is explicitly not a claim that #2849's seed acceptance is satisfied.**
Nothing in this branch implements seeding. The clause is unmet, and it stays
unmet until either 01D-3 lands or the clause is deliberately re-scoped. The
same applies to the ingestion clause in section B.
