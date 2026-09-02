# ADR-025 — one typed artifact-plan authority for scaffolds, presets, and blueprints

- **Status:** Accepted on merge of the pull request that introduces it.
- **Date:** 2026-09-02
- **Anchor issue:** #2845
- **Related:** #2846 (transactional apply engine — blocked until this ADR is
  accepted), #2787 (blueprint materialization — blocked until this ADR is
  accepted), #2664 (`project:init`/upgrade/AI-verify orchestration), #2442
  (`site:init` `minimal`/`editorial` presets), #2438/ADR-024 (minimal bootable
  skeleton), ADR-023 (governed application blueprints extend `waaseyaa.site`
  v1), `docs/specs/site-golden-path.md`, `docs/specs/cli-kernel.md`
- **Command inventory:** `docs/adr/data/025-generation-command-inventory.json`
  (see "Why the inventory lives here" below)
- **Decision scope:** this is a decision record only. It authorizes no
  implementation, no command deletion, no release, and no generator rewrite
  (#2845 non-goals, restated verbatim in Non-goals below). Nothing in this
  document changes any handler, command, or generator's current behavior.

## Context

The CLI exposes more than twenty generation entrypoints — `make:*`,
`scaffold:*`, `fixture:*`, `site:init`, `site:doctor`, `install:init` — built
at different times by different authors with incompatible output shapes:
some print PHP to stdout, some print JSON, some write files, and one
(`make:content-type`) reads, mutates, and rewrites the application's
`composer.json` directly, in place, with no lock, no collision detection
against a concurrent editor, and no rollback if a later step in the same
command invocation fails.

`waaseyaa/site-contract` and `waaseyaa/cli` already contain a working,
tested, transactional authority for exactly this problem —
`SiteArtifactRenderer`/`GeneratedSite`/`GeneratedArtifact`
(`packages/site-contract/src/Generation/`) and
`SiteInitializationService` (`packages/cli/src/Site/SiteInitializationService.php`)
— built for `site:init` and already governing atomicity, durability,
recovery, collision detection, path containment, symlink policy, and
generated-artifact ownership (`docs/specs/site-golden-path.md`
"Initialization"; ratified as the sole directory-creation/ownership
mechanism by ADR-024 D-2). Three issues are queued to extend or consume that
authority: #2846 wants to add dry-run/apply/ownership/recovery semantics,
#2787 wants to compile approved blueprints through it, and #2664 wants to
orchestrate `project:init`/upgrades/AI-verification on top of it. Without a
prior decision naming which one thing they are all extending, the risk named
by #2845 is concrete: three separate efforts each build their own
transaction, their own generated-state manifest, and the framework ends up
with competing authorities that silently diverge on collision, rollback, and
ownership semantics — exactly the failure mode `SiteInitializationService`
already exists to prevent for `site:init` alone.

This ADR is that prior decision. It inventories every generation entrypoint,
names the single execution authority and the single generated-state
authority, defines the typed contract all three queued issues must compile
into, and proves — against the layer table in `CLAUDE.md` and the two
enforcement surfaces `bin/check-package-layers` reads — that naming that
owner introduces no dependency cycle.

### Why the inventory lives here, not in `docs/audits/`

`docs/audits/` holds independently-triggered audits (skeleton census,
dead-code baselines, security sweeps) whose freshness is pinned to the
audit's own SHA/lock and is never re-derived from an ADR's acceptance
(ADR-024 "Consequences": the skeleton audit's `data/*.json` "is fixed to a
cited SHA and lock digest by design, not a live inventory
`bin/refresh-governance-artifacts` regenerates"). This inventory is not
independent evidence gathered before a decision — it *is* the decision's
required deliverable (#2845 Acceptance: "A reviewed ADR and command
inventory names the canonical owner and every legacy disposition"), it is
versioned and re-derivable from the same source tree an implementer
re-verifies against, and every future issue in the D-11 migration sequence
updates its own row in place as it lands. It belongs beside the ADR that
requires it, under a `docs/adr/data/` convention this ADR introduces for
exactly that kind of artifact: `docs/adr/data/<NNN>-<slug>.json`, referenced
by filename from the owning ADR, never by a separate index.

## Decision

### D-1. Single execution authority, single generated-state authority

**`Waaseyaa\CLI\Site\SiteInitializationService`
(`packages/cli/src/Site/SiteInitializationService.php`) is the only
multi-file, transactional, publish-time execution authority for generated
application artifacts, for every generator: human-authored scaffolds,
`site:init` presets, and approved blueprints alike.** It already provides
atomicity (write-to-temp-then-rename plus a journaled item state machine),
durability (`fsync()` where `SiteHostPlatform` reports the capability),
crash recovery (the next run replays the journal to the exact prior
generation), collision refusal (an unrecognized existing file is never
overwritten), path containment (`SitePathContainment`, no traversal, no
absolute path, no embedded null), and symlink rejection. No new
implementation of any of those guarantees is authorized by this ADR or any
issue it governs. #2846 *extends* this service (richer typed result,
registration effects, optimistic stale-plan detection) — it does not fork
it, and does not build a second one.

**`.waaseyaa/generated.json`, produced by
`Waaseyaa\SiteContract\Generation\GeneratedSite`
(`packages/site-contract/src/Generation/GeneratedSite.php`), is the only
generated-state authority.** It already binds generator version, manifest
digest, and per-artifact mode/digest/extension-region for every artifact
`SiteInitializationService` has published. No second ownership manifest,
approval ledger, or hash engine may be introduced by #2846, #2787, or #2664.
Where #2664 needs an AI-verification hash/version engine, it reads this
file; where #2787 needs blueprint-applied evidence, ADR-023 already
specifies it as an *extension* of this same file's schema ("Its optional
`application_blueprint` evidence member is emitted only for an applied
blueprint... The blueprint-free metadata bytes remain unchanged" — ADR-023 /
`docs/specs/site-golden-path.md` "Governed application blueprints").

### D-2. Owning package and layer, with no dependency cycle

| Concern | Owner | Layer | Existing type/class |
|---|---|---|---|
| Typed artifact/plan model, artifact ownership metadata, blueprint/manifest validation | `waaseyaa/site-contract` | **0 (Foundation)** | `GeneratedArtifact`, `GeneratedSite`, `SiteRecipeRendererInterface` (existing); `ArtifactPlan`, `ComposerProviderRegistration` (new — D-5) |
| Transaction execution: journal, lock, durability, recovery, collision/containment/symlink refusal, publish | `waaseyaa/cli` | **6 (Interfaces)** | `SiteInitializationService`, `SitePathContainment`, `SiteHostPlatform` (existing) |

This is not a new split invented by this ADR — it is the split `site:init`
already uses today, ratified unchanged. The proof it introduces no cycle:

- **`waaseyaa/site-contract`'s `composer.json` `require` block is
  `{php, symfony/yaml}` — zero `waaseyaa/*` entries.** Verified directly
  against the checked-out file. It cannot depend on anything this ADR
  concerns, by construction; it is the framework's structural floor for this
  entire decision, matching its position in the `CLAUDE.md` Layer
  Architecture table (`Layer 0 | Foundation | ... site-contract ...`).
- **`waaseyaa/cli`'s `composer.json` already requires
  `waaseyaa/site-contract`** (`"waaseyaa/site-contract":
  "^0.1.0-alpha.300"`), matching its position in the table
  (`Layer 6 | Interfaces | cli ...`). A Layer 6 package requiring a Layer 0
  package is an ordinary downward edge under the stated rule ("Packages can
  only import from their own layer or lower"); `bin/check-package-layers`
  already passes on this exact edge today because `SiteInitHandler`
  (`packages/cli/src/Handler/SiteInitHandler.php`) already imports
  `Waaseyaa\SiteContract\SiteManifestParser` and
  `Waaseyaa\SiteContract\Exception\SiteManifestValidationException` under
  `<pkg>/src/` (rule PL005). No new manifest edge and no new file-level
  upward-import surface is created: D-5's new `site-contract` types
  (`ArtifactPlan`, `ComposerProviderRegistration`) are consumed by `cli`
  exactly the way `GeneratedSite`/`GeneratedArtifact` already are — a
  downward `use Waaseyaa\SiteContract\Generation\...` from
  `packages/cli/src/**`, not the reverse.
- **No edge runs the other way.** `site-contract` has, and this ADR grants
  it, no reason to import `Waaseyaa\CLI\*` — the transaction/journal/lock
  machinery is execution-time concern that stays entirely inside `cli`. If a
  future patch ever adds a `use Waaseyaa\CLI\...` inside
  `packages/site-contract/src/`, that is by itself a violation of this
  decision and of PL005, independent of whether CI happens to catch it that
  day.
- **No same-layer cycle is opened either.** D-5's `ComposerProviderRegistration`
  effect and `ArtifactPlan` wrapper live in `site-contract` beside the types
  they extend; they introduce no new Layer-0 package and no new edge among
  Layer-0 packages. `tools/package-layers-cycle-baseline.txt`'s five accepted
  same-layer 2-cycles are unaffected — none of them involve `site-contract`
  or `cli`.

`bin/check-package-layers` therefore continues to pass unchanged after any
issue implementing this ADR lands the D-5 types, because the edge it would
gate (`cli` → `site-contract`) already exists, is already exercised at the
file level, and this ADR adds no new direction of dependency — only new
symbols inside the existing direction.

### D-3. Command inventory and disposition

Every entrypoint that could generate application state was inventoried
against its actual source (not against an unverified prior summary) and
classified `keep` / `merge` / `out_of_scope` / `planned`. The full,
per-command evidence — exact file, exact writing call sites, exact
composer.json touch or its absence — is
`docs/adr/data/025-generation-command-inventory.json`. Summary:

| Disposition | Count | Commands |
|---|---|---|
| **keep** | 15 | `make:entity`, `make:job`, `make:listener`, `make:policy`, `make:provider`, `make:test`, `make:entity-type`, `make:plugin` (all stdout-only, zero side effects — verified: the only filesystem-adjacent call in each is `$io->write()`/`$io->writeln()`); `scaffold:bundle`, `scaffold:relationship`, `scaffold:workflow`, `scaffold:extension`, `fixture:scaffold`, `fixture:generate`, `fixture:pack:refresh` (stdout-JSON, optional caller-chosen `-o` path, never a project-owned generated artifact); `site:init` (the reference implementation of this authority — unchanged); `site:doctor` (read-only diagnostic, unchanged) |
| **merge** | 6 | `make:content-type` (**critical** — the only entrypoint that mutates `composer.json` directly, no transaction), `make:public`, `make:migration`, `make:storage-migration`, `scaffold:auth` — each writes application files today with its own ad hoc `is_dir()||mkdir()`/`file_put_contents()` guard and no journal, lock, or rollback |
| **out_of_scope** | 1 | `install:init` — database/config materialization, not a filesystem artifact generator; already composed by #2664 per `docs/specs/site-golden-path.md`'s five-phase lifecycle; this ADR does not reopen it |
| **planned** | 3 | `project:init`, `project:init --upgrade`, `ai:update`/`ai:verify` (#2664) — do not exist yet; bound to this authority in advance by D-12 so their implementation cannot invent a competing one |

`keep` commands need no follow-on issue. `merge` commands are the entire
scope of the D-11 migration sequence; no `merge` command's behavior changes
by force of this ADR alone.

### D-4. Interactive/non-interactive input, versioned output, error codes, exit statuses, dry-run/apply

Every migrated (`merge`) command adopts the shape `site:init` already
proves out:

- **Interactive input** stays exactly what each command already accepts
  (positional name, `--fields`, etc. for `make:content-type`; equivalent
  named options elsewhere) — this ADR does not touch argument parsing for
  any command.
- **Non-interactive input** for a scriptable "assemble many artifacts in one
  call" flow reuses `site:init`'s existing precedent: a complete
  `--answers=PATH` document is a red line already drawn (`SiteInitHandler`:
  "Non-interactive site:init requires a complete --answers document" on a
  missing one). No `merge` command gets a *second*, differently-shaped
  non-interactive contract.
- **Versioned JSON output.** A `--json`/`--dry-run` structured payload uses
  the same `{schema, version, ...}` envelope convention `waaseyaa.site`,
  `waaseyaa.generated`, and `waaseyaa.application_blueprint` already use
  (`docs/specs/site-golden-path.md`). The plan envelope is
  `{"schema": "waaseyaa.artifact_plan", "version": 1, ...}` — see D-9 for a
  concrete worked instance. Version 1 is closed exactly as the other
  `waaseyaa.*` schemas are; a future incompatible shape is version 2, never
  a silent field addition to version 1's required set.
- **Stable error codes.** `waaseyaa/site-contract` already has one coded
  family for manifest/blueprint *content* errors — `SITE0xx` (`SITE001`,
  `SITE010`–`SITE012`, `SITE040`–`SITE050`, all JSON-Pointer-addressed).
  Today's execution-time failures (`GeneratedArtifact`/`GeneratedSite`
  constructor `\InvalidArgumentException`s, and
  `SiteInitializationCollisionException`/`SiteInitializationLockedException`)
  are uncoded. This ADR reserves a **separate** `GEN0xx` family, in
  `site-contract` beside `SITE0xx`, for the *execution/plan* boundary —
  separate because these are refusals about where and how bytes may land,
  not about manifest shape, and conflating the two families would make a
  `SITE0xx` reader responsible for a boundary it doesn't own:

  | Code | Threat/condition | Today's uncoded equivalent |
  |---|---|---|
  | `GEN001_UNSAFE_PATH` | traversal, absolute path, embedded null, backslash | `GeneratedArtifact` constructor `\InvalidArgumentException` |
  | `GEN002_SYMLINK_REJECTED` | a path component or target resolves through a symlink | `SiteInitializationService` internal check (currently unexposed as a distinct exception) |
  | `GEN003_COLLISION_REFUSED` | an existing unrecognized file/directory blocks the target | `SiteInitializationCollisionException` |
  | `GEN004_AMBIGUOUS_EXTENSION_REGION` | managed-region digest drifted from what the generator expects, so a regeneration can't tell edit from substitution | `GeneratedArtifact::regionBounds()` `\InvalidArgumentException` |
  | `GEN005_STALE_PLAN` | the plan's captured state no longer matches the project (optimistic-concurrency refusal — #2846 net-new) | none today (no concept of a plan to go stale) |
  | `GEN006_MALICIOUS_IDENTIFIER` | a user-supplied name fails the existing `AbstractMakeHandler::IDENTIFIER_PATTERN`/`MACHINE_NAME_PATTERN`/`FQCN_PATTERN` grammar | `AbstractMakeHandler::validateIdentifier()` `\RuntimeException` |
  | `GEN007_UNSUPPORTED_DECLARATION` | an unsupported field type or generator-feature token, mirroring `SITE042`/the blueprint generator-feature-token refusal for the plan-compilation boundary | `ApplicationBlueprintValidator` `SITE042`, generalized |
  | `GEN008_LOCKED` | a concurrent initialization holds the project lock | `SiteInitializationLockedException` |

  Assigning these codes now, in this ADR, is a decision (the family exists,
  is `site-contract`-owned, and these eight ids are reserved for the D-10
  threats) — *coding the exceptions to carry them* is #2846 implementation,
  not authorized here.
- **Exit statuses.** The general CLI convention already stated in
  `docs/specs/cli-kernel.md` — `0` success, `1` command/domain failure, `2`
  usage/input error — is the target for every `merge` command's *new*
  output modes. `make:storage-migration`'s existing five-value contract
  (`0`/`1`/`2`/`3`/`4`, documented in its own class docblock) is **preserved
  unchanged** through the D-6 compatibility window: it is real,
  already-documented API surface a script may already switch on, and this
  ADR does not silently renumber it. D-6 spells out its convergence path.
- **Dry-run/apply.** Every `merge` command's dry-run and apply both compile
  to the same `ArtifactPlan` (D-5) and both pass through
  `SiteInitializationService::initialize($site, $dryRun)` — the existing
  boolean the method already accepts. Dry-run performs every check
  (collision, containment, symlink, identifier grammar) and returns the
  full plan without writing; apply performs the identical checks and then
  publishes. There is no dry-run-only code path that skips a check apply
  would perform, and no apply-only check dry-run would miss — that
  asymmetry is exactly the class of bug #2846's "Plan and dry-run are
  deterministic, side-effect-free ... digest-addressable" acceptance
  criterion exists to prevent.

### D-5. Generated-file, extension-region, companion-test, registration, and migration declarations

The typed **`ArtifactPlan`** (`Waaseyaa\SiteContract\Generation\ArtifactPlan`,
new, `site-contract`, Layer 0) is the output every compiler in D-7 produces
and the only input `SiteInitializationService` accepts to publish. It
*wraps and extends* `GeneratedSite`/`GeneratedArtifact` — it does not
replace or fork them, and it does not widen `GeneratedArtifact`'s own
constructor (that type is `@api`, already digest-checked, and used
unconditionally by every existing `GeneratedSite`; changing its shape is a
breaking change to a stable Layer-0 type this ADR has no reason to force).
Its shape, matching #2846's own acceptance-criteria vocabulary verbatim:

- **`generator`**: `{fqcn: string, version: int}` — which compiler (D-7)
  produced this plan, so `.waaseyaa/generated.json`'s existing
  `generator_version` field and any future per-artifact provenance can
  agree with the plan that produced them.
- **`artifacts`**: the existing `GeneratedSite` (unchanged type) — the
  full, final byte content of every artifact, keyed by path, already
  carrying `mode` and optional `extensionRegion` per artifact
  (`GeneratedArtifact` — unchanged, existing type).
- **`status`**: `array<string, 'created'|'changed'|'unchanged'|'refused'>`,
  keyed by the same paths as `artifacts` — computed by diffing against the
  project's current `.waaseyaa/generated.json` and filesystem state. This
  computation already exists, partially, inside
  `SiteInitializationService::initialize()` (today only surfaced as
  `SiteInitializationResult::$changedPaths`, a flat list with no per-path
  status or refusal detail); D-5 asks #2846 to widen that existing
  computation's *output shape*, not to add a second engine that
  independently re-derives it.
- **`registrations`**: `list<ComposerProviderRegistration>`
  (`Waaseyaa\SiteContract\Generation\ComposerProviderRegistration`, new,
  `site-contract`, Layer 0) — `{fqcn: string, group?: string}`. This is the
  typed replacement for `MakeContentTypeHandler::registerProvider()`'s
  direct `json_decode`/mutate/`json_encode` of `composer.json`
  (`packages/cli/src/Handler/MakeContentTypeHandler.php` lines 278–309).
  `composer.json` cannot become a wholly generator-owned `GeneratedArtifact`
  the way `public/index.php` can — a real application's `composer.json` has
  hundreds of unrelated, user-owned keys — so it is not modeled as file
  content at all. It is modeled as a **merge instruction**:
  `SiteInitializationService` decodes the project's current
  `composer.json`, applies every pending registration idempotently (already
  present ⇒ no-op, matching `registerProvider()`'s existing `return false`
  behavior), re-encodes with the project's existing formatting
  conventions, and writes it back **inside the same transaction and
  journal as every other artifact in the plan** — atomic with the file
  writes it accompanies, collision-checked (refuses, coded `GEN003`, if the
  file changed unexpectedly since the plan was computed — the
  read-modify-write race `registerProvider()` has no defense against
  today), and rolled back with everything else on failure. This is new
  Layer-0 type surface and new Layer-6 execution surface; it introduces no
  new package and, per D-2, no new dependency edge.
- **`companion_tests`**: `list<string>` — paths that MUST already appear as
  keys in `artifacts`. This is bookkeeping cross-reference, not a new
  artifact kind: a companion test is an ordinary `GeneratedArtifact` under
  a `tests/` path, and `companion_tests` exists only so tooling (a future
  "every scaffolded content type ships a test" CI gate, or `site:doctor`)
  can enumerate them without parsing paths by convention.
- **`schema_effects`** / **`config_effects`**: `list<string>`, reserved and
  empty for every compiler this ADR inventories today. No `merge` command
  touches entity-storage schema or the configuration generation directly;
  this reservation exists so #2664's `install:init` composition (already
  `out_of_scope` per D-3) and any future schema-touching recipe have a typed
  place to declare intent without a second plan shape.

**Migration declarations.** `make:migration` and `make:storage-migration`'s
target path already embeds a timestamp chosen with `date('Ymd_His')` at
call time. Compiled into an `ArtifactPlan`, that timestamp is captured
**once per plan compilation**, not once per filesystem check — dry-run and
the apply that follows it must name the identical file, or a dry-run
preview would lie about what apply actually publishes. This is the same
determinism `GeneratedSite`'s digest-addressability already requires of
every other artifact; migrations are not a special case, they are simply
the one existing generator whose content happens to be time-dependent at
render time rather than input-dependent.

### D-6. Compatibility and deprecation windows; script migration detection

No `merge`-disposition command's **externally observable behavior** —
argument names, default output shape, existing exit codes, existing file
paths and content — changes by force of this ADR. Migration happens per
command, one PR at a time, per the D-11 sequence, and each such PR:

1. **Preserves the command's existing default invocation exactly.** A
   script calling `make:content-type story --fields=...` today gets
   identical stdout, identical files, identical exit code after migration —
   only the *mechanism* underneath (a direct write vs. a compiled
   `ArtifactPlan` published by `SiteInitializationService`) changes.
2. **Adds new opt-in surface** (`--json`/`--dry-run` structured output
   where the command doesn't already have one) without touching the default
   path — exactly `site:init`'s own `--dry-run`/`--answers`/`--yes` are
   additive to what a bare invocation already did.
3. **Preserves existing bespoke exit codes unless and until a named,
   dated deprecation window closes them.** `make:storage-migration`'s
   `0`–`4` contract is the concrete case: it continues to return exactly
   those five values through migration; convergence onto the general `0`/
   `1`/`2` convention (if ever pursued) is its own future issue with its
   own deprecation notice, never bundled silently into the artifact-plan
   migration.
4. **Announces removal, not silent behavior change, for anything that does
   change** — e.g. `make:content-type`'s current unconditional
   `composer.json` write becomes, after migration, subject to the same
   collision refusal every other generated artifact already gets (a
   concurrently-edited `composer.json` today silently loses the concurrent
   edit; after migration it is a `GEN003` refusal). That is a *safety*
   change (fewer silent clobbers), documented in the migrating PR's
   changelog fragment, not treated as compatible-by-default.
5. **A script wanting to detect a migrated command in advance** checks
   `waaseyaa <command> --help`/its declared options for the new
   `--json`/`--dry-run` flag, exactly the detection path already available
   for `site:init` today (`SiteInitHandler` registers `--dry-run` as an
   ordinary Symfony Console option, discoverable without parsing prose).
   No separate machine-readable "is this command migrated yet" registry is
   introduced — the command's own declared option surface is the answer,
   consistent with how Symfony Console commands are already introspectable.

### D-7. How #2438 presets and #2787 blueprints compile to the same plan without sharing product-specific DTOs

`site-contract` already proves this exact fan-in pattern for `site:init`:
`SiteRecipeRendererInterface::render(SiteManifest): GeneratedSite` is
implemented by several distinct recipe renderers, each of which knows its
own input shape intimately and each of which emits the same output type.
This ADR generalizes that one interface's shape, unchanged in spirit, to
every input surface in the inventory:

| Input (product-specific, never shared) | Compiler (knows the input) | Output (shared, the only sharing point) |
|---|---|---|
| `SiteManifest` resolved from #2442 `minimal`/`editorial` preset answers — per ADR-024 D-3/D-4, **no preset DTO persists**; a preset resolves to the same ordinary manifest shape any other `site:init` answer set does | existing `SiteRecipeRendererInterface` implementations (unchanged) | `ArtifactPlan` (D-5), wrapping `GeneratedSite` |
| `ApplicationBlueprint` (ADR-023, existing typed value, `Waaseyaa\SiteContract\Blueprint\`) approved via a matching `BlueprintDecisionReceipt` | new `BlueprintArtifactCompiler` (#2787's own implementation work — not authorized here) | `ArtifactPlan` |
| Per-handler scaffold input (e.g. `make:content-type`'s `$name`/`--fields`, already validated by `AbstractMakeHandler`) | each migrating `make:*` handler itself, or a small shared `ScaffoldArtifactCompiler` if the follow-on issue finds enough common shape — that choice is implementation detail #2846/its follow-on decides, not this ADR | `ArtifactPlan` |

The DTOs in the left column are, and remain, deliberately incompatible with
one another — a `SiteManifest` is not an `ApplicationBlueprint` is not a
`make:content-type` argument bag, and no compiler in the middle column is
asked to understand another compiler's input. **The single shared surface
is the output type**, `ArtifactPlan`, defined exactly once, in `site-contract`
(D-2), consumed exactly once, by `SiteInitializationService::initialize()`
(D-1). This is the precise reading of "compile to the same plan without
sharing product-specific DTOs": the sharing is at the output boundary, by
construction, because nothing product-specific ever crosses into `cli` or
into the transaction — only fully-rendered artifact bytes, registration
effects, and status metadata do.

### D-8. After migration, no handler may bypass the execution authority

**After a `merge`-disposition command's migration PR lands, that handler
may not call `file_put_contents()`, `mkdir()` for an application-source
target, `json_decode`/`json_encode` on the project's `composer.json`, or any
other direct filesystem/`composer.json` mutation of its own. Every write of
every application file, and every `composer.json` registration, for every
generator inventoried by this ADR, happens exactly once, inside
`SiteInitializationService::initialize()`, through a compiled `ArtifactPlan`.**
This is the literal content of #2845's acceptance criterion "No handler may
write application files or mutate composer.json outside the selected
execution authority after migration," restated as a rule with no exception
carved for any command in the D-3 inventory. A future architecture test
enforcing this rule mechanically (the natural analogue of the existing
`bin/check-getquery-bindings` unbound-query gate, or `bin/check-package-layers`
itself) is an explicit candidate the D-11 sequence names for the follow-on
issue; writing that gate is implementation, not authorized by this ADR.

### D-9. Worked demonstration: a manual scaffold and an approved blueprint produce equivalent typed plans

This is a **target-shape demonstration**, not a report of existing output —
no code emits this JSON today. It shows what a `merge`-migrated
`make:content-type story --fields="title:string,body:text"` and a
`site:init`-materialized `application_blueprint` entity of the same shape
must each compile to, once #2846/#2787 exist, to satisfy D-7.

**Manual scaffold** — `waaseyaa make:content-type story
--fields="title:string,body:text" --json`:

```json
{
  "schema": "waaseyaa.artifact_plan",
  "version": 1,
  "generator": {
    "fqcn": "Waaseyaa\\CLI\\Handler\\MakeContentTypeHandler",
    "version": 1
  },
  "status": {
    "src/Entity/Story.php": "created",
    "src/Provider/StoryServiceProvider.php": "created"
  },
  "artifacts": [
    { "path": "src/Entity/Story.php", "mode": "0644", "managed_sha256": "<sha256 of rendered entity class>" },
    { "path": "src/Provider/StoryServiceProvider.php", "mode": "0644", "managed_sha256": "<sha256 of rendered provider class>" }
  ],
  "registrations": [
    { "fqcn": "App\\Provider\\StoryServiceProvider", "group": "content" }
  ],
  "companion_tests": [],
  "schema_effects": [],
  "config_effects": []
}
```

**Approved blueprint** — `waaseyaa site:init --dry-run` against a
`waaseyaa.site` manifest whose `application_blueprint.entities` contains one
entity `{id: "story", label: "Story", storage: "sql-blob", fields: [{id:
"title", type: "string", required: true}, {id: "body", type: "text"}]}`,
materialized through `SiteArtifactRenderer` + a `BlueprintArtifactCompiler`
(#2787):

```json
{
  "schema": "waaseyaa.artifact_plan",
  "version": 1,
  "generator": {
    "fqcn": "Waaseyaa\\SiteContract\\Blueprint\\BlueprintArtifactCompiler",
    "version": 1
  },
  "status": {
    "src/Entity/Story.php": "created",
    "src/Provider/StoryServiceProvider.php": "created"
  },
  "artifacts": [
    { "path": "src/Entity/Story.php", "mode": "0644", "managed_sha256": "<sha256 of rendered entity class>" },
    { "path": "src/Provider/StoryServiceProvider.php", "mode": "0644", "managed_sha256": "<sha256 of rendered provider class>" }
  ],
  "registrations": [
    { "fqcn": "App\\Provider\\StoryServiceProvider", "group": "content" }
  ],
  "companion_tests": [],
  "schema_effects": [],
  "config_effects": []
}
```

**The two plans are structurally identical** — same `status` keys, same
`artifacts` paths/modes, same `registrations` — down to the `managed_sha256`
values, which are equal whenever the two compilers render byte-identical
class bodies for the same field set (they are not required to render
byte-identical source for every possible field-type/option combination;
they are required to produce artifacts of the same *typed shape*, which is
what D-7's shared output boundary actually guarantees and what
`SiteInitializationService` actually checks — it diffs and journals by path
and digest, never by which compiler produced the bytes). They differ only
in `generator.fqcn`, which is exactly the provenance distinction D-5
requires `ArtifactPlan` to preserve: an operator or `site:doctor` can always
tell a hand-scaffolded content type from a blueprint-materialized one, while
`SiteInitializationService` treats both identically at publish time. This is
the concrete meaning of "equivalent typed artifact plans" in #2845's
acceptance criterion.

### D-10. Threat model

| Threat | Mitigation | Owning code (existing unless noted) |
|---|---|---|
| **Traversal** (`../../../etc/passwd`, embedded null, backslash) | Reject at `GeneratedArtifact` construction; `GEN001` (D-4) | `GeneratedArtifact::__construct()` guard clause |
| **Symlinks** (a path component or target resolves through one) | Reject at containment check; `GEN002` | `SitePathContainment`, `SiteInitializationService`'s symlink checks |
| **Races** (concurrent `site:init`/scaffold invocations) | Exclusive advisory lock around the transaction; `GEN008` on contention | `SiteInitializationService` lock + `SiteInitializationLockedException` |
| **Partial writes** (process or host death mid-publication) | Write-to-temp-then-rename plus a journaled per-item state machine; the next run recovers to the exact prior generation before starting new work | `SiteInitializationService` journal/recovery (existing, `docs/specs/site-golden-path.md` "Initialization") |
| **Ambiguous overwrite** (an extension region's managed-content digest drifted, so regeneration can't tell a user edit from a substitution) | Refuse regeneration; `GEN004`; the sanctioned unblock is the existing `framework.observed_lock_sha256` rebind, never a silent overwrite | `GeneratedArtifact::regionBounds()`/`withExtensionFrom()`, `docs/specs/site-golden-path.md` "Changed managed bytes" |
| **Stale approval** (a plan computed against project state that has since changed — e.g. `composer.json` edited by a human between dry-run and apply) | `GEN005` optimistic-concurrency refusal — **net-new for #2846**; today's `SiteInitializationService` re-validates at publish time but does not yet carry a distinct "the plan you approved is stale" code | #2846 (named here, not implemented here) |
| **Malicious identifiers** (a name crafted to break class-name/namespace/path assumptions) | Reject, never sanitize, at the top of every handler before the value reaches a class name, namespace, or path; `GEN006` | `AbstractMakeHandler::validateIdentifier()`/`validateMachineName()`, `IDENTIFIER_PATTERN`/`MACHINE_NAME_PATTERN`/`FQCN_PATTERN` |
| **Unsupported field/capability declarations** (an entity field type or generator-feature token the installed cohort doesn't advertise) | Fail closed at validation, before compilation; `GEN007`, generalizing the existing `SITE042`/generator-feature-token refusal | `ApplicationBlueprintValidator` (`SITE042`), `FieldTypeManager::blueprintFieldTypeIds()`, `docs/specs/site-golden-path.md` "Governed application blueprints" generator-feature-token paragraph |
| **`composer.json` read-modify-write race** (two generators, or a generator and a human editor, mutate it concurrently) | The D-5 `ComposerProviderRegistration` merge runs inside the same transaction/journal/lock as every other artifact, collision-checked like a file write; this closes the exact gap `MakeContentTypeHandler::registerProvider()` has today | D-5 (net-new type + execution path, named here, implemented by the D-11 sequence) |

### D-11. Ordered, compatibility-safe migration sequence for follow-on issues

1. **#2846 — transactional artifact-plan engine.** Adds `ArtifactPlan`,
   `ComposerProviderRegistration` (`site-contract`), and extends
   `SiteInitializationService`'s result/dry-run surface to the D-5 shape.
   Ships with unit, adversarial, failure-injection, and recovery tests per
   its own existing acceptance criteria. **No `make:*`/`scaffold:*` handler
   changes in this step** — the engine exists and is proven before anything
   is asked to compile into it.
2. **#2787 — blueprint materialization.** Adds `BlueprintArtifactCompiler`,
   consuming #2846's `ArtifactPlan` and the existing `ApplicationBlueprint`.
   Composes `SiteArtifactRenderer` + `SiteInitializationService` exactly as
   its own issue text already commits to ("Do not create a second
   transaction, ownership manifest, project initializer, or product-specific
   compiler").
3. **`make:content-type` migration (own PR).** The critical case — first
   because it is the only `composer.json`-mutating command and the
   `ComposerProviderRegistration` path needs at least one real caller before
   any other `merge` command adopts it. Default invocation unchanged (D-6);
   adds `--json`/`--dry-run`.
4. **`make:public`, `make:migration`, `make:storage-migration` migration**
   (own PR each, or combined if the diff stays reviewable) — same D-6
   contract; `make:storage-migration` keeps its existing five-value exit
   code contract through this step, unchanged.
5. **`scaffold:auth` migration (own PR).** Last among `merge` commands
   because its existing drift-tracking manifest (`AuthUiScaffoldManager`) is
   the most structurally different from a plain generated artifact and
   needs its own design pass for how `--check`'s read-only mode coexists
   with the plan/apply lifecycle (D-3's disposition note already flags
   this).
6. **#2664 — `project:init`, upgrades, AI-verification.** Composes
   `site:init` + `install:init` per its own acceptance criteria ("project:init
   composes site:init; it does not fork site-profile semantics"); by this
   point in the sequence every `merge` command it might orchestrate already
   speaks the same `ArtifactPlan` contract.
7. **#2442 — `site:init` `minimal`/`editorial` presets.** Can land any time
   after step 1 (it only needs `SiteManifest` → `GeneratedSite`, which
   already exists); ordered last here only because its own dependency
   (#2438) is already merged and it has no dependency on steps 2–6.
8. **Follow-on: mechanical enforcement of D-8.** A CI gate proving no
   `packages/cli/src/Handler/*.php` calls `file_put_contents()`/`mkdir()`
   for an application-source target or touches `composer.json` outside
   `SiteInitializationService`, analogous in shape to
   `bin/check-getquery-bindings`. Ordered last because it should have
   nothing left to baseline-exempt once steps 3–5 land.

Each numbered step is its own issue-traceable PR with a red boundary test
before implementation, per the workflow's design-first rule; no step
authorizes skipping ahead of a step it depends on.

### D-12. Composition statement for #2664, #2846, and #2787

All three compose the single authority named in D-1/D-2. None may
re-implement any part of it:

- **#2846 may not**: create a second transaction/journal/lock
  implementation; create a second generated-ownership manifest distinct
  from `.waaseyaa/generated.json`; create a second collision, containment,
  or symlink-safety check. It **must**: extend `SiteInitializationService`'s
  existing result and dry-run surface, and add the D-5 types to
  `site-contract` beside the types they extend.
- **#2787 may not**: create a second transaction, ownership manifest,
  project initializer, or product-specific compiler (its own issue text,
  restated here as binding on this ADR too). It **must**: consume
  `application_blueprint` from the existing `waaseyaa.site` v1 manifest
  (never a companion file or a Studio-private plan object — its own
  "Settled authority" section), and publish exclusively through
  `SiteArtifactRenderer` + `SiteInitializationService::initialize()`.
- **#2664 may not**: fork `site:init`'s profile semantics; create a second
  hash/version engine for `ai:update --check/--apply` and `ai:verify`
  distinct from `.waaseyaa/generated.json`'s existing digests; let Composer
  post-update touch anything outside generated/bounded regions (its own
  acceptance criteria, restated here as binding on this ADR too). It
  **must**: compose `site:init` and `install:init` as already-published
  commands, and read the one generated-state authority D-1 names for
  verification.

Any of the three found, on implementation, to require a genuinely new
capability `SiteInitializationService`/`GeneratedSite` cannot express
extends this ADR with a follow-on decision naming the extension — it does
not invent a parallel mechanism silently inside its own issue.

## Consequences

- Every future generator — human scaffold, preset, or AI-proposed blueprint
  — has exactly one place to learn atomicity, collision safety, and
  ownership from, and exactly one place to fix a bug in that safety once,
  for every generator at once.
- `make:content-type`'s `composer.json` race is closed for every future
  generator that registers a provider, not patched once for this one
  command and left as a pattern the next handler copies.
- The `docs/adr/data/` convention this ADR introduces gives future
  ADRs with a required machine-readable deliverable (an inventory, a
  roster, a threat matrix) a consistent, versioned home beside the
  decision that requires them, distinct from `docs/audits/`'s
  independently-triggered, SHA-pinned census artifacts.
- The `GEN0xx` error-code family gives #2846 a fixed namespace and eight
  pre-assigned ids to implement against, rather than inventing codes ad hoc
  per PR the way `SITE0xx` grew organically before ADR-023 closed its
  vocabulary.

## Non-goals

Restated verbatim from #2845, and binding on every issue this ADR
authorizes:

- No implementation, command deletion, release, or generator rewrite is
  authorized by this decision alone.
- This ADR does not modify any generator, handler, or command's current
  behavior. Every `merge` disposition in D-3 is a target for a **future**
  PR, sequenced by D-11; none of that work is done here.
- This ADR does not implement #2846's transactional engine, #2787's
  blueprint compiler, or #2664's orchestration. It names the authority each
  must compose and forbids each from re-implementing it (D-12); the
  implementation itself remains each issue's own, separately reviewed work.
- This ADR does not define an approval/decision-receipt mechanism beyond
  what ADR-023 already specifies for blueprints — that remains owned by the
  higher layer (AI system, human review, forge adapter) ADR-023 already
  names.
- This ADR does not retire, rename, or set a removal date for any `keep` or
  `merge` command. Deprecation windows and removal dates, where they prove
  necessary, are each `merge` command's own D-11 migration PR's decision to
  propose, not a blanket schedule fixed here.
