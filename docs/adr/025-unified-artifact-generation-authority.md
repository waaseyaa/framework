# ADR-025 — one typed artifact-plan authority for scaffolds, presets, and blueprints

- **Status:** Accepted on merge of the pull request that introduces it.
- **Date:** 2026-09-02
- **Anchor issue:** #2845
- **Related:** #2846 (transactional apply engine — blocked until this ADR is
  accepted), #2787 (blueprint materialization — blocked until this ADR is
  accepted), #2664 (`project:init`/upgrade/AI-verify orchestration), #2442
  (`site:init` `minimal`/`editorial` presets — a parallel lane, see D-12),
  #2438/ADR-024 (minimal bootable skeleton), ADR-023 (governed application
  blueprints extend `waaseyaa.site` v1), #1625/#2730/#2731 (schema
  migration — a future, separately bound consumer of the D-14 protocol, not
  authorized here), `docs/specs/site-golden-path.md`,
  `docs/specs/cli-kernel.md`
- **Command inventory:** `docs/adr/data/025-generation-command-inventory.json`
  (see "Why the inventory lives here" below)
- **Decision scope:** this is a decision record only. It authorizes no
  implementation, no command deletion, no release, and no generator rewrite
  (#2845 non-goals, restated verbatim in Non-goals below). Nothing in this
  document changes any handler, command, or generator's current behavior.

**Revision note (review round 2).** This revision adds D-2 (the
generated-state partition), splits the plan contract in D-6 into immutable
compiler output and target evaluation, corrects the provenance and
post-publication-traceability claims in D-10, rewrites the migration
sequence in D-12 into two lanes, and corrects a mis-attributed guard in
D-11. D-2 is new, so every decision after D-1 shifts by one: the previous
D-2…D-12 are now D-3…D-13, with their topics unchanged.

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

That authority, as it stands today, cannot express what this ADR asks of
it, and the gap is the central thing this decision has to close rather than
delegate. `GeneratedSite` describes a *complete* artifact set, and
`SiteInitializationService::prepare()` compares the incoming set against the
recorded one unconditionally and refuses on any inequality
(`packages/cli/src/Site/SiteInitializationService.php` lines 139–143:
`$expectedOwnedPaths !== $recordedPaths` throws). So a migrated
`make:content-type` could not add its two files to a site already
initialized by `site:init`; and if those two files were recorded anyway, the
next ordinary `site:init` render would omit them and refuse the changed set
forever. Routing incremental scaffolds through a whole-set model without
first deciding how independent units of generated state are persisted and
reconciled would hand #2846 the decision this ADR exists to make. D-2 makes
it.

This ADR is that prior decision. It inventories every generation entrypoint,
names the single execution authority and the single generated-state
authority, decides how that generated state is partitioned so more than one
generator can own part of it, defines the typed contract all three queued
issues must compile into, and proves — against the layer table in
`CLAUDE.md` and the two enforcement surfaces `bin/check-package-layers`
reads — that naming that owner introduces no dependency cycle.

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
re-verifies against, and every future issue in the D-12 migration sequence
updates its own row in place as it lands. It belongs beside the ADR that
requires it, under a `docs/adr/data/` convention this ADR introduces for
exactly that kind of artifact: `docs/adr/data/<NNN>-<slug>.json`, referenced
by filename from the owning ADR, never by a separate index.

## Decision

D-1 through D-13 fix the generation authority: one execution authority, one
generated-state authority, one plan contract, one migration sequence. D-14
names the lifecycle semantics that authority is an instance of — the
governed-change protocol and its change-receipt envelope — so that schema
migration can later conform without inheriting generation's types, and so
that no second interpretation of plan, preview, apply, receipt, verify and
recovery can appear by default. D-14 authorizes exactly one binding: the
generation one.

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
issue it governs. #2846 *extends* this service (target evaluation split out
from compilation, richer typed result, registration effects, optimistic
stale-plan detection, the D-2 unit reconciliation) — it does not fork it,
and does not build a second one.

**`.waaseyaa/generated.json` is the only generated-state authority.** It
already binds generator version, manifest digest, and per-artifact
mode/digest/extension-region for every artifact `SiteInitializationService`
has published. No second ownership manifest, approval ledger, or hash engine
may be introduced by #2846, #2787, or #2664. Where #2664 needs an
AI-verification hash/version engine, it reads this file; where #2787 needs
blueprint-applied evidence, ADR-023 already specifies it as an *extension*
of this same file's schema ("Its optional `application_blueprint` evidence
member is emitted only for an applied blueprint... The blueprint-free
metadata bytes remain unchanged" — ADR-023 / `docs/specs/site-golden-path.md`
"Governed application blueprints").

What changes, and only in D-2, is *how that one file is structured
internally* so that more than one generator can own part of it. It stays one
file, written by one writer, inside one transaction. One consequence is
structural and is decided here rather than discovered later: the metadata
artifact is no longer composed by `GeneratedSite`'s constructor alone (D-2.6).

### D-2. The generated-state authority is partitioned into generation units

`.waaseyaa/generated.json` records **generation units**. A unit is one
independent, re-addressable claim of ownership over a set of generated
paths, produced by one compiler from one validated input. `site:init`
publishes one unit; a migrated `make:content-type story` publishes another;
neither can see, compare against, or silently drop the other's paths.

#### D-2.1 Persisted shape

The document stays `schema: "waaseyaa.generated"`, **version 1**, and gains
exactly two optional members, following verbatim the extension rule ADR-023
already established for `application_blueprint` ("The `waaseyaa.generated`
document remains version 1 with an optional `application_blueprint` evidence
member emitted only for an applied blueprint. Older readers fail closed on
that unknown member").

- A top-level **`units`** list, one record per **non-root** unit, sorted by
  `id` byte-wise ascending (mirroring the existing `artifacts` sort by
  `path`). Each record is the closed object
  `{"id", "disposition", "generator": {"fqcn", "version"}, "input_digest"}`.
- An optional per-row **`unit`** key on an `artifacts` row, naming the
  non-root unit that owns that path.

Both members are emitted only when at least one non-root unit owns state.

```json
{
  "schema": "waaseyaa.generated",
  "version": 1,
  "generator_version": 3,
  "manifest_digest": "<64 hex>",
  "units": [
    {
      "id": "scaffold:content-type:story",
      "disposition": "seeded",
      "generator": {
        "fqcn": "Waaseyaa\\CLI\\Handler\\MakeContentTypeHandler",
        "version": 1
      },
      "input_digest": "<64 hex>"
    }
  ],
  "artifacts": [
    { "path": ".waaseyaa/site.yaml", "mode": "0644", "managed_sha256": "<64 hex>" },
    { "path": "src/Entity/Story.php", "mode": "0644", "managed_sha256": "<64 hex>", "unit": "scaffold:content-type:story" }
  ]
}
```

**The root unit is never listed in `units`, and its rows never carry a
`unit` key.** The root unit *is* the existing top-level triple —
`generator_version`, `manifest_digest`, `artifacts` — under the reserved id
`site`; its disposition is `managed` by definition, its generator is the
installed `SiteArtifactRenderer` at `generator_version`, and its input
digest is `manifest_digest`. An absent `unit` key on a row means "owned by
the root unit". This asymmetry is deliberate, not an oversight for a later
tidy-up to correct: giving the root unit a `units` entry "for symmetry"
would duplicate `manifest_digest`/`generator_version` inside it, and two
copies that can disagree would make the existing binding refusal
(`SiteInitializationService.php` lines 123–125) ambiguous about which copy
governs.

Every existing invariant is carried over unchanged: rows sorted by `path`
and unique; `mode` is `0644` or `0755`; `managed_sha256` is 64 lowercase
hex; the whole document is byte-equal to `CanonicalJson::encode($document)
. "\n"`. `CanonicalJson::encode()` `ksort`s object keys
(`packages/site-contract/src/CanonicalJson.php` line 30), so `units` sorts
between `schema` and `version` and `unit` sorts last within a row, without
moving any existing member's bytes relative to its neighbours.

**Unit ids.** An id is one or more `:`-separated segments, each matching
`/^[a-z0-9]+(?:-[a-z0-9]+)*$/D`, at most 128 characters total. The
single-segment id `site` is reserved for the root unit and may not be
claimed by any other. An id is derived deterministically from the
compiler's own validated input (`make:content-type story` →
`scaffold:content-type:story`), so re-running the same command re-addresses
the same unit rather than accreting a second one. Because that derivation is
what makes the per-unit frozen-set rule bite at all, each migrating
handler's id grammar is published surface from the moment it ships, and
changing it later orphans the old unit instead of re-parenting it (see
Consequences).

**`input_digest`** is `sha256` over the canonical encoding of the unit's
validated compiler input. It plays, per unit, exactly the role
`manifest_digest` plays for the root unit: it is what lets regeneration
distinguish "the input changed" from "the bytes were substituted".

#### D-2.2 Two dispositions

- **`managed`** — today's semantics verbatim. The unit's artifacts are
  re-rendered on every publish that supplies the unit; its recorded path set
  is frozen (D-2.3); while its `input_digest` is unchanged, every row's
  `managed_sha256` must equal the re-rendered `managedDigest()` (the
  existing check at lines 146–169), with the existing
  `framework.observed_lock_sha256` rebind as the only sanctioned unblock.
  The root unit is always `managed`.
- **`seeded`** — published exactly once, then owned by the developer. Never
  re-rendered. Never byte-enforced after publication. Its recorded
  `managed_sha256` is retained as provenance of what was originally emitted
  — it is what lets `site:doctor` say "modified since generation" — and is
  never a refusal input.

The `seeded` disposition is not a convenience; it is the half of the model
without which the scaffold case still fails. A one-shot scaffold exists to
be edited. If `src/Entity/Story.php` were recorded as `managed`, the first
edit — the entire point of the command — would make the next publish refuse
with "Generated artifact was edited outside an extension region" (lines
193–195), and the project could never be regenerated again.

**Disposition is fixed by the generation-unit kind, determined by the
compiler, and is never a per-run caller flag.** The set of compiler FQCNs
permitted to emit a `seeded` unit is a closed, reviewed list; #2846 carries
an architecture test asserting it. Without that closure, disposition becomes
an escape hatch and the `managed` guarantee erodes by drift.

#### D-2.3 Reconciliation: per-unit, carry-forward, frozen per unit

A plan (D-6) declares the unit it supplies and, optionally, a unit it
retires. `SiteInitializationService` reconciles as follows, replacing the
single global comparison at lines 139–143:

1. **Read.** The document is read into a roster of units and a path→unit
   index built alongside the existing `priorRows`. A path recorded under two
   units, a row naming an unknown unit, or a duplicate unit id is
   `GEN010_UNIT_PATH_CONFLICT` — the same class of fail-closed refusal as
   today's "Generated ownership metadata repeats {$path}" (line 135),
   widened from within-list to across-units. A document with no `units`
   member is promoted, **in memory only**, to a single root unit owning
   every recorded row.
2. **Carry forward.** Recorded units the plan does not supply or retire are
   preserved verbatim: their rows are carried into the composed document
   unchanged, their bytes are not re-derived, and **no set check and no byte
   check runs against them** — neither can, because nothing re-rendered
   them. This is the step that makes an incremental scaffold expressible,
   and the step that stops the next ordinary `site:init` from omitting it.
3. **Frozen set, per unit.** For the supplied unit, the recorded path set
   and the supplied path set are compared unconditionally, outside the
   input-digest guard, and any inequality refuses, with no override and no
   migration path — character-for-character today's rule, evaluated over one
   unit's partition instead of the whole document. A plan addressing a unit
   id that is not recorded is a **new** unit: no set comparison runs,
   because there is nothing to compare.
4. **Partition.** The union of every unit's paths, root included, is a
   partition of the recorded path set. It is enforced at three points: at
   read (step 1); at compile, because an `ArtifactPlan` declares exactly one
   owning unit and the type cannot express a cross-unit claim; and at
   publish, where a supplied path resolving to a *different* recorded unit
   is `GEN003_COLLISION_REFUSED`.
5. **Collision polarity: the first recorded owner wins, permanently.** The
   second claimant is refused, identically in dry-run and apply. There is no
   merge, no last-writer-wins, and no re-parenting. Three reasons, each
   load-bearing: it matches the existing "Refusing to overwrite unowned
   artifact" polarity (line 182), where an unrecognized owner never wins;
   silent re-parenting would let a scaffold capture a root-owned path, after
   which a `site:init` re-render would see a changed root set and refuse
   forever with no override — an unrecoverable state; and refusal is the
   only outcome that is identical in dry-run and apply, which D-5 requires.
   The operator's move is to retire the owning unit and re-run — a reviewed
   two-step, exactly as the `framework.observed_lock_sha256` rebind is the
   reviewed move for changed managed bytes.
6. **Retirement is explicit.** A recorded row disappearing from a supplied
   unit's output without a declared retirement is
   `GEN009_UNDECLARED_UNIT_RETIREMENT`. A declared retirement applies to
   each recorded path the same proofs a publish applies before overwriting —
   private regular file, managed digest equal to the recorded
   `managed_sha256` — then removes the files, removes directories the
   retirement empties, withdraws exactly that unit's registrations from
   `composer.json`, and publishes a composed document with the unit absent.
   Retiring an unrecorded id is an idempotent success. **The root unit is
   not retirable**: retiring it would leave `.waaseyaa/site.yaml` without
   ownership metadata, precisely the state lines 114–117 already refuse to
   read.
7. **No *undeclared* set delta.** A supplied unit whose rendered path set
   differs from its recorded set, without declaring that difference, is
   refused: an undeclared drop is `GEN009`, an undeclared addition is
   `GEN011`. What a unit may not do is change its set *silently*. Declared
   additive evolution is authorized and digest-bound under D-2.3a, and only
   for the compilers that section's closed eligibility list names;
   shrinking a unit remains retirement-only (step 6). There is no flag, no
   `--force`, and no override on any of these paths.

#### D-2.3a Authorized successor-plan evolution (additive, digest-bound)

An earlier draft froze every unit's path set outright and pointed the
operator at retire-then-recreate. That is wrong for the case the framework
most needs to support: #2787's acceptance requires a changed blueprint to
produce an exact reviewable diff, and a blueprint that gains one entity
gains generated artifacts. Under a frozen set, ordinary evolution of an
authored declaration is refused forever, and the only sanctioned answer —
retire the root unit and recreate it — deletes and rewrites every file the
application owns in order to add one. Fail-closed *ownership* is right;
fail-closed *evolution* is not, and the two are separable.

The authorization is split across the two halves D-6 already separates,
because only one of them may observe the project.

**The compiler declares capability, purely.** `ArtifactPlan` gains one
member, `set_evolution`, with values `frozen` or `additive`. `frozen` is
the default; `additive` is valid only for the closed eligibility list
below. It is a
property of the compiler and its validated input — not of any project — so
the plan stays a pure function of its input and two runs still produce
byte-identical bytes. A compiler that cannot reason about growing its own
output keeps `frozen` and behaves exactly as before.

**Evaluation computes the delta and binds it.** `ProjectStateIdentity`
already observes "the union of the plan's artifact paths and every path
recorded to a unit the plan supplies or retires" (D-6.2), so the recorded
set is *already* inside the captured precondition identity and already bound
by `project_state_digest`. `EvaluatedArtifactPlan` surfaces the comparison
as `setDelta: {adds, drops}`, both sorted. No new digest is introduced: a
successor plan compiled against a generation that has since moved is refused
by the `GEN005_STALE_PLAN` recomputation D-6.5 already performs under the
exclusive lock.

**Eligibility is a closed list, not a field a compiler may set.** Exactly
one binding may emit `set_evolution: additive` in v1:

- the **root `site` compiler** — the `SiteArtifactRenderer`-composed render
  of a `waaseyaa.site` manifest, which is where manifest-authored cardinality
  already lives (`PublishedContentRecipe::render()` emits one bundle class
  per `contentTypes` entry, D-2.8), and, once #2787 lands, the
  approved-blueprint root compiler that produces the same root unit from an
  `application_blueprint` plus its matching `BlueprintDecisionReceipt`. That
  second entry is a **forward reference, not a standing grant**: the
  compiler does not exist, #2846's architecture test can only assert the
  manifest-render half, and adding the blueprint compiler to this list is an
  authority expansion that #2787 must earn against the review gate in D-13.

Every other compiler in the D-4 inventory — every `make:*`, every
`scaffold:*`, every non-root unit — is `frozen`, and a plan from one of them
carrying `additive` is `GEN011` regardless of what its paths look like. This
mirrors the closed `seeded` allowlist in D-2.2: the value is not a
self-service capability, it is a reviewed property of a named compiler.
#2846 carries the architecture test that asserts this list, alongside the
one that asserts the `seeded` allowlist, so a future compiler cannot promote
itself merely by setting the field.

Four further rules make this an authorization rather than an override:

1. **Additive only, in v1.** `drops` must be empty. A supplied unit whose
   render no longer contains a recorded path is `GEN009` (undeclared
   retirement) or, where the unit declared evolution, `GEN011`. Shrinking a
   unit is still retirement (D-2.3 step 6): explicit, journaled, and
   rollback-covered.
2. **Growth requires a declared capability.** `adds` non-empty against a
   `frozen` plan is `GEN011`, identically in dry-run and apply. The
   compiler must have said it evolves its set; the engine will not infer
   permission from the fact that the paths happen to be new.
3. **Every added path faces the full admission checks.** `assertSafeTarget()`
   (`GEN001`/`GEN002`), the cross-unit ownership check (`GEN003`, `GEN010`),
   and the existing-unmanaged-file collision refusal (`GEN003`) run on each
   added path exactly as on a first publish. An added path colliding with a
   file this authority does not own is refused with no override — the
   property the frozen set was really protecting, retained in full.
4. **Carried-forward paths are unaffected.** Paths in both sets keep the
   managed-digest and extension-region checks they have today (`GEN004`).
   Successor evolution widens *which paths a unit may own*; it changes
   nothing about how an already-owned path may be rewritten.

There is no flag and no `--force` on any of these paths. A plan may add
paths because its compiler declared `additive` and evaluation verified the
delta against state the digest already binds — not because an operator
asserted an override.

This closes the additive half of the live defect in D-2.8 — adding a content
type to an already-initialized `.waaseyaa/site.yaml` — and gives #2787 an
iterative blueprint path with no second mechanism. The subtractive half
remains refused in v1: a correct removal must decide the fate of the removed
file's contents, and that decision belongs to its own issue rather than to
this ADR's margins.

#### D-2.4 The no-ambiguous-set-change guarantee is retained

The guarantee's scope changes, and D-2.3a adds one named exception to its
letter; its nature does not change. The sentence in
`docs/specs/site-golden-path.md` — "A changed artifact set — one generated
file added or removed — is compared unconditionally, outside the
manifest-digest guard, and refuses regeneration on every already-initialized
project with no override and no migration path. Treat the set as frozen" —
remains true of the root unit for **every undeclared change and every
removal**, which is the behaviour it was written to protect. What it no
longer describes exhaustively is growth: a *declared* additive successor
from an eligible compiler (D-2.3a) is authorized, digest-bound, and refused
the moment it is undeclared, non-additive, or emitted by a compiler outside
that section's eligibility list.

Concretely, for the root unit: the path set is still compared to the
recorded set outside the manifest-digest guard; a removal still refuses with
no override, no `--force`, and no migration engine; an undeclared addition
is `GEN011`; and a declared addition from the eligible root compiler applies
under the same lock, the same admission checks, and the same stale-state
refusal as a first publish. Every non-root unit keeps the unconditional
treatment inside its own boundary, because no non-root compiler is eligible
to declare `additive`.

`docs/specs/site-golden-path.md` is not edited by this ADR: it documents
shipped behaviour, and the behaviour ships with #2846. That update is named
in D-12 step 1 as part of the slice, not deferred to a later cleanup.

What is removed is only the conflation. Today the guard cannot tell "the
framework's own generated set drifted" from "another generator wrote a
file", and answers both with a permanent refusal — `src/Entity/Story.php`
has never been in `SiteArtifactRenderer`'s output, yet the current guard
demands that it be. Partitioning separates the two questions and answers
each with the treatment it deserves.

Three sub-guarantees, each preserved by a named mechanism rather than by
assertion:

- **No ambiguous set change** — per-unit unconditional comparison, outside
  the input-digest guard, no override (D-2.3 step 3, step 7). What the
  guarantee forbids is *ambiguity*, not evolution: a declared, digest-bound
  additive successor (D-2.3a) is unambiguous by construction, and an
  undeclared change of any shape is still refused.
- **No ambiguous overwrite** — the existing managed-digest and
  extension-region checks (lines 146–204) run per path exactly as today for
  `managed` rows, with per-unit `input_digest` supplying the "input
  unchanged, bytes changed" refusal the root unit gets from
  `manifest_digest`.
- **No ambiguous ownership** — exclusive partition, first-recorded-owner
  wins, refused identically in dry-run and apply (D-2.3 steps 4–5).

In two respects the guarantee is strictly *stronger* than today's.
Scaffold-written files are currently outside the ownership model entirely:
unowned files the next render either ignores or collides with, whose
deletion is invisible. Recorded as unit rows, their deletion, substitution,
and mode drift are all detected. And ambiguity between owners becomes an
explicit refusal where today the second writer simply wins by being second.

One widening must be stated rather than inherited silently: carried-forward
rows are trusted from the metadata document without re-derivation, so a
tampered document could assert `seeded` ownership of arbitrary paths. The
blast radius is bounded and asymmetric — a recorded row can only *block* a
write, as a collision, and can never cause one, and `assertSafeTarget()`
still runs against every path — but it is a real change in what the document
asserts, and D-11 records it as its own threat row.

#### D-2.5 What happens to an existing `.waaseyaa/generated.json`

**Nothing.** A document written by the current code has no `units` member
and no row `unit` keys, and such a document *is* a single root unit by
definition. It is read exactly as today, needs no rewrite, no version bump,
no migration command, no re-`site:init`, and no operator action. There is no
state in which a project is "half migrated". The optional members are
created by the first non-root publish, inside the same transaction that
creates the unit they describe.

**ADR-023's byte-identical blueprint-free v1 output is preserved by
construction**, for the same reason ADR-023's own optional member preserves
it. The document stays version 1, so there is no reader-version negotiation
and no second shape. The root unit is not represented in `units`, so its
five members keep their names, types, ordering, and row grammar exactly.
`units` is emitted only when non-empty, so for every project that has run
only `site:init` — which is every project until a migrated `make:*` runs —
the composition over the prior document is the identity function and the
published bytes are the bytes `SiteArtifactRenderer` produced. Root-owned
rows never gain a `unit` key even in a multi-unit document, so root row
bytes are stable in every case. Existing golden and digest fixtures do not
change, and ADR-023's commitment — "A blueprint-free render retains the old
exact metadata bytes" — continues to hold for exactly the reason it holds
today. The two optional members compose rather than compete:
`application_blueprint` is evidence about the root unit's materialization
and stays at top level where ADR-023 puts it; `units` describes non-root
ownership.

**Older readers fail closed, which is the correct polarity and comes free.**
`readMetadata()` pins the top-level key set to exactly
`['artifacts','generator_version','manifest_digest','schema','version']`
(line 429), so a CLI predating this model meets a units-bearing document and
refuses with `SiteInitializationCollisionException` "Generated ownership
metadata has an unsupported shape" rather than proceeding blind to
foreign-owned paths — precisely ADR-023's treatment of an older reader
meeting `application_blueprint`. #2846 improves the message to name the
cause; it must not relax the check. The consequence is a **per-project
one-way door**: once a project has published one non-root unit it cannot be
operated by an older framework release at all, `site:doctor` included. That
is the intended safety property, and the migrating command's changelog
fragment and the refusal message must both say so, so operators learn it
from release notes rather than from a red `site:doctor`.

There is no downgrade tool and none is needed: retiring every non-root unit
removes every `units` entry, conditional emission drops the member, and the
document returns to byte-identical v1.

#### D-2.6 Metadata composition moves to the transaction authority

A scaffold compiler cannot know the root unit's rows, so it cannot construct
a `GeneratedSite` whose metadata artifact self-certifies against a document
it cannot see (`GeneratedSite::__construct()` derives the metadata bytes
from its own artifact list and byte-compares them,
`packages/site-contract/src/Generation/GeneratedSite.php` lines 51–60).
Therefore **the published `.waaseyaa/generated.json` is composed by
`SiteInitializationService`**, from the supplied unit's rows plus the
carried roster, and installed last in the same journal — the metadata-last
publish ordering already forced at lines 227–231. `GeneratedSite`,
`GeneratedArtifact`, and `SiteArtifactRenderer` are untouched types;
`GeneratedSite` remains exactly what `site:init` renders for the root unit,
and its constructor still certifies that **root projection**.

The cost, stated plainly: `GeneratedSite`'s constructor stops being the only
check that the published document matches the published artifacts. The
compensating requirement is that the service re-run the same derivation over
the composed document before staging it, so the invariant is enforced once,
in one place, on both paths. That is an obligation enforced by a test rather
than by a type's constructor, and #2846 carries it as a red boundary test.

#### D-2.7 `site:doctor` becomes unit-aware in the same slice

`SiteDoctorService::generatedArtifactFindings()` byte-compares the *whole*
metadata document against a fresh
`SiteArtifactRendererFactory::create()->render($manifest)`
(`packages/cli/src/Site/SiteDoctorService.php` lines 79–82). Under the unit
model that comparison reports `SITE010_GENERATED_ARTIFACT_DRIFT` on every
project that has ever run a migrated `make:*`, turning `site:doctor
--strict` red and breaking the generated `bin/maintenance/site-verify`.
Doctor therefore changes in the same #2846 slice, not as a follow-up:

- It byte-compares the **root projection** — the recorded document with
  `units` removed and every `unit`-bearing row removed — against the fresh
  render. Today's substitution proof stays fully intact for everything the
  manifest is a pure function of.
- It runs its existing row loop over non-root rows, **disposition-aware**: a
  `managed` row keeps today's blocking `SITE010` treatment; a `seeded` row
  whose file is present but modified is reported as a distinct,
  **non-blocking** finding (its id assigned by #2846 within the existing
  `SITE0xx` family — it must not reuse `SITE010`, which is strict-blocking,
  because a modified seed is the expected state).
- A recorded row whose file is **missing** is drift regardless of
  disposition.
- Doctor's per-row output always surfaces the owning unit and its
  disposition, so a mis-set disposition — which otherwise fails silently, a
  `managed` artifact wrongly recorded as `seeded` simply stops being
  protected — is visible.

#### D-2.8 Related pre-existing defect, explicitly out of scope

`PublishedContentRecipe::render()` already emits one
`src/Content/Bundle/<Class>.php` per `$manifest->contentTypes` entry
(`packages/cli/src/Site/Recipe/PublishedContentRecipe.php` lines 66–68), so
the root unit's artifact set is *already* a function of a manifest-authored
list of variable cardinality. Combined with the unconditional set comparison
at lines 139–143, that means **adding a content type to an
already-initialized `.waaseyaa/site.yaml` is refused today** — the
manifest's own documented editing surface is blocked by the frozen-set rule.
D-2.3a fixes the additive half of this: the root `site` compiler is on its
closed eligibility list precisely because of this case, so adding a content
type renders a strict superset of the recorded root set, which its plan may
declare `additive` and apply, bound to the recorded state it evolved from. **Removing** a
content type remains refused — the rendered set would shrink, which v1
routes to retirement rather than to a silent delete — so this defect is
narrowed, not closed, and its subtractive half is legitimate future work
with its own decision to make.

#### D-2.9 Decided here versus implemented in #2846

| Decided by this ADR (binding) | Implemented by #2846 (not authorized here) |
|---|---|
| Generated state is partitioned into generation units; the unit is the ownership granule | Widening `readMetadata()`'s key-set assertions (lines 429, 444–449) from exact-list to required-plus-known-optional-allowlist |
| The persisted shape: optional `units` list + optional per-row `unit`, version 1, root unit implicit (D-2.1) | Read-time implicit promotion of a `units`-free document to one root unit |
| The unit-id grammar and its reserved `site` id | Each migrating handler's own id derivation (its own PR) |
| Two dispositions, fixed by compiler kind, with a closed `seeded` allowlist (D-2.2) | The architecture test asserting that allowlist |
| Per-unit frozen set, carry-forward, partition, first-owner-wins, explicit retirement (D-2.3) | Replacing lines 139–145 with per-unit reconciliation and gating 146–169 on the supplied unit |
| Declared additive successor evolution, digest-bound, with removal still routed to retirement, and a closed eligibility list for `additive` (D-2.3 step 7, D-2.3a) | The `set_evolution` plan member, `EvaluatedArtifactPlan::$setDelta`, `GEN011`, and the architecture test asserting the eligibility list |
| Metadata composition moves to the transaction authority (D-2.6) | The composition itself, the service-level re-derivation check, and the byte-identity fixture test that must land **before** the relocation |
| Retirement is a new journal verb with its own rollback and directory-cleanup semantics | The journal item kind, the rollback branch that restores a deleted file from backup, and its failure-injection coverage |
| `site:doctor` splits into root-projection compare plus disposition-aware row loop (D-2.7) | The split itself and its new non-blocking finding id |
| `GEN009`/`GEN010` are reserved (D-5) | Coding the exceptions to carry them |

### D-3. Owning package and layer, with no dependency cycle

| Concern | Owner | Layer | Existing type/class |
|---|---|---|---|
| Typed artifact/plan model, artifact ownership metadata, blueprint/manifest validation | `waaseyaa/site-contract` | **0 (Foundation)** | `GeneratedArtifact`, `GeneratedSite`, `SiteRecipeRendererInterface` (existing); `ArtifactPlan`, `EvaluatedArtifactPlan`, `ProjectStateIdentity`, `ArtifactApplyRequest`, `ArtifactApplyResult`, `ComposerProviderRegistration` (new — D-6) |
| Transaction execution: journal, lock, durability, recovery, collision/containment/symlink refusal, unit reconciliation, target evaluation, publish | `waaseyaa/cli` | **6 (Interfaces)** | `SiteInitializationService`, `SitePathContainment`, `SiteHostPlatform` (existing) |

The split is the one `site:init` already uses today, ratified unchanged:
the immutable types are Layer 0 values with no knowledge of any project;
everything that *observes* a project — the unit roster on disk, the
filesystem, `composer.json`, the lock — is evaluated inside `cli`. The proof
it introduces no cycle:

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
  upward-import surface is created: D-6's new `site-contract` types are
  consumed by `cli` exactly the way `GeneratedSite`/`GeneratedArtifact`
  already are — a downward `use Waaseyaa\SiteContract\Generation\...` from
  `packages/cli/src/**`, not the reverse.
- **No edge runs the other way.** `site-contract` has, and this ADR grants
  it, no reason to import `Waaseyaa\CLI\*` — the transaction, journal, lock,
  unit-roster read, and target evaluation are execution-time concerns that
  stay entirely inside `cli`. If a future patch ever adds a
  `use Waaseyaa\CLI\...` inside `packages/site-contract/src/`, that is by
  itself a violation of this decision and of PL005, independent of whether
  CI happens to catch it that day.
- **No same-layer cycle is opened either.** D-6's new types live in
  `site-contract` beside the types they extend; they introduce no new
  Layer-0 package and no new edge among Layer-0 packages.
  `tools/package-layers-cycle-baseline.txt`'s five accepted same-layer
  2-cycles are unaffected — none of them involve `site-contract` or `cli`.

`bin/check-package-layers` therefore continues to pass unchanged after any
issue implementing this ADR lands the D-6 types, because the edge it would
gate (`cli` → `site-contract`) already exists, is already exercised at the
file level, and this ADR adds no new direction of dependency — only new
symbols inside the existing direction.

### D-4. Command inventory and disposition

Every entrypoint that could generate application state was inventoried
against its actual source (not against an unverified prior summary) and
classified `keep` / `merge` / `out_of_scope` / `planned`. The full,
per-command evidence — exact file, exact writing call sites, exact
composer.json touch or its absence — is
`docs/adr/data/025-generation-command-inventory.json`. Summary:

| Disposition | Count | Commands |
|---|---|---|
| **keep** | 17 | `make:entity`, `make:job`, `make:listener`, `make:policy`, `make:provider`, `make:test`, `make:entity-type`, `make:plugin` (all stdout-only, zero side effects — verified: the only filesystem-adjacent call in each is `$io->write()`/`$io->writeln()`); `scaffold:bundle`, `scaffold:relationship`, `scaffold:workflow`, `scaffold:extension`, `fixture:scaffold`, `fixture:generate`, `fixture:pack:refresh` (stdout-JSON, optional caller-chosen `-o` path, never a project-owned generated artifact); `site:init` (the reference implementation of this authority — unchanged; it publishes the root `site` unit); `site:doctor` (read-only diagnostic; D-2.7 makes it unit-aware in #2846's slice, with no change to its interface) |
| **merge** | 5 | `make:content-type` (**critical** — the only entrypoint that mutates `composer.json` directly, no transaction), `make:public`, `make:migration`, `make:storage-migration`, `scaffold:auth` — each writes application files today with its own ad hoc `is_dir()||mkdir()`/`file_put_contents()` guard and no journal, lock, or rollback |
| **out_of_scope** | 1 | `install:init` — database/config materialization, not a filesystem artifact generator; already composed by #2664 per `docs/specs/site-golden-path.md`'s five-phase lifecycle; this ADR does not reopen it |
| **planned** | 3 | `project:init`, `project:init --upgrade`, `ai:update`/`ai:verify` (#2664) — do not exist yet; bound to this authority in advance by D-13 so their implementation cannot invent a competing one |

`keep` commands need no follow-on issue. `merge` commands are the entire
scope of the D-12 migration sequence; no `merge` command's behavior changes
by force of this ADR alone.

Each migrated `merge` command publishes exactly one non-root generation unit
(D-2), and, because a non-root unit may only be published into a project
that already has a root unit, requires an initialized site — see D-7 rule 6.

### D-5. Interactive/non-interactive input, versioned output, error codes, exit statuses, dry-run/apply

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
- **Versioned JSON output.** Structured payloads use the same
  `{schema, version, ...}` envelope convention `waaseyaa.site`,
  `waaseyaa.generated`, and `waaseyaa.application_blueprint` already use
  (`docs/specs/site-golden-path.md`). D-6 defines four such documents —
  `waaseyaa.artifact_plan`, `waaseyaa.project_state`,
  `waaseyaa.artifact_apply_request`, and `waaseyaa.artifact_result` — each
  version 1 and closed exactly as the other `waaseyaa.*` schemas are; a
  future incompatible shape is version 2, never a silent field addition to
  version 1's required set. D-10 shows concrete worked instances.
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
  | `GEN001_UNSAFE_PATH` | traversal, absolute path, backslash, embedded null | `GeneratedArtifact` constructor `\InvalidArgumentException` (first four) and `SiteInitializationService::assertSafeTarget()` (all five) |
  | `GEN002_SYMLINK_REJECTED` | a path component or target resolves through a symlink | `SiteInitializationService::assertSafeTarget()`/`assertRegularOwnedFile()` (currently unexposed as a distinct exception) |
  | `GEN003_COLLISION_REFUSED` | an existing unrecognized file/directory blocks the target, or the target is recorded to a different generation unit (D-2.3 step 4) | `SiteInitializationCollisionException` |
  | `GEN004_AMBIGUOUS_EXTENSION_REGION` | managed-region digest drifted from what the generator expects, so a regeneration can't tell edit from substitution | `GeneratedArtifact::regionBounds()` `\InvalidArgumentException` |
  | `GEN005_STALE_PLAN` | the plan digest or the captured project-state digest no longer matches what apply recomputes (D-6.5 optimistic-concurrency refusal — #2846 net-new) | none today (no concept of a plan to go stale) |
  | `GEN006_MALICIOUS_IDENTIFIER` | a user-supplied name fails the existing `AbstractMakeHandler::IDENTIFIER_PATTERN`/`MACHINE_NAME_PATTERN`/`FQCN_PATTERN` grammar, or a unit id fails the D-2.1 grammar | `AbstractMakeHandler::validateIdentifier()` `\RuntimeException` |
  | `GEN007_UNSUPPORTED_DECLARATION` | an unsupported field type or generator-feature token, mirroring `SITE042`/the blueprint generator-feature-token refusal for the plan-compilation boundary | `ApplicationBlueprintValidator` `SITE042`, generalized |
  | `GEN008_LOCKED` | a concurrent initialization holds the project lock | `SiteInitializationLockedException` |
  | `GEN009_UNDECLARED_UNIT_RETIREMENT` | a recorded row disappears from a supplied unit's output with no declared retirement (D-2.3 step 6) | none today (no concept of a unit) |
  | `GEN011_UNAUTHORIZED_SET_DELTA` | a supplied unit renders paths its recorded set does not contain while its plan declares `set_evolution: frozen`, or an evolving unit's render drops a recorded path (D-2.3a) | none today (no concept of a unit) |
  | `GEN010_UNIT_PATH_CONFLICT` | a duplicate unit id, a row naming an unknown unit, or one path claimed by two units (D-2.3 step 1) | none today (no concept of a unit) |

  Assigning these codes now, in this ADR, is a decision (the family exists,
  is `site-contract`-owned, and these ten ids are reserved for the D-11
  threats) — *coding the exceptions to carry them* is #2846 implementation,
  not authorized here. An eleventh id is an amendment to this ADR, not a
  silent addition.
- **Exit statuses.** The general CLI convention already stated in
  `docs/specs/cli-kernel.md` — `0` success, `1` command/domain failure, `2`
  usage/input error — is the target for every `merge` command's *new*
  output modes. `make:storage-migration`'s existing five-value contract
  (`0`/`1`/`2`/`3`/`4`, documented in its own class docblock) is **preserved
  unchanged** through the D-7 compatibility window: it is real,
  already-documented API surface a script may already switch on, and this
  ADR does not silently renumber it. D-7 spells out its convergence path.
- **Dry-run/apply.** Dry-run and apply consume the *same* immutable
  `ArtifactPlan` and run the *same* evaluation (D-6.2) against the project.
  Dry-run performs every check — unit reconciliation, collision,
  containment, symlink, identifier grammar — and returns the evaluated plan
  without writing; apply performs the identical checks, verifies the
  stale-plan binding (D-6.5), and then publishes. There is no dry-run-only
  code path that skips a check apply would perform, and no apply-only check
  dry-run would miss. That asymmetry is exactly the class of bug #2846's
  "Plan and dry-run are deterministic, side-effect-free ...
  digest-addressable" acceptance criterion exists to prevent, and D-6's type
  split is what makes it structurally impossible rather than merely
  intended.

### D-6. The plan contract: immutable compiler output, separate target evaluation, and the apply binding

The earlier draft of this ADR put `status` (`created`/`changed`/
`unchanged`/`refused`) inside the same object a compiler emits, while also
saying that status is computed by diffing the live project inside
`SiteInitializationService`. Both cannot be true of one immutable value: a
pure compiler cannot know whether a file it renders already exists. This
decision splits the two, names both types, and fixes the digests that bind
them.

#### D-6.1 `ArtifactPlan` — immutable compiler output, no observation of the project

`Waaseyaa\SiteContract\Generation\ArtifactPlan` (new, `site-contract`,
Layer 0) is what every compiler in D-8 emits and the only thing that can be
handed to `SiteInitializationService` for evaluation. It is a pure function
of the compiler's validated input plus the compiler's own version. **It
contains no status, no diff, no filesystem observation, and no reference to
any project.** Two runs of the same compiler on the same input, on different
machines and at different times, produce byte-identical plans.

Its canonical document, `{"schema": "waaseyaa.artifact_plan", "version": 1}`:

| Member | Type | Meaning |
|---|---|---|
| `generator` | `{fqcn: string, version: int}` | which compiler (D-8) produced this plan |
| `unit` | `{id: string, disposition: "managed"\|"seeded"}` | the generation unit this plan supplies (D-2.1/D-2.2) |
| `input_digest` | 64 hex | `sha256` over the canonical encoding of the compiler's validated input |
| `artifacts` | list of `{path, mode, content, extension_region?}` | the full, final bytes of every artifact this unit owns, sorted by `path` |
| `retires` | list of unit ids, sorted | units this plan retires (D-2.3 step 6); usually empty |
| `registrations` | list of `ComposerProviderRegistration` | `{fqcn, group?}`, sorted by `fqcn` then `group` |
| `companion_tests` | list of paths, sorted | must each also appear in `artifacts` |
| `set_evolution` | `"frozen"` \| `"additive"` | whether this compiler may render a strict superset of its unit's recorded path set (D-2.3a). `additive` only for the compilers on D-2.3a's closed eligibility list; `frozen` for every other compiler in the inventory, and the default |
| `schema_effects` / `config_effects` | lists of strings, sorted | reserved, empty for every compiler this ADR inventories |

`artifacts` carries the artifact **bytes**, not a digest of them, because
the plan is the thing an operator reviews and an apply later executes; a
digest-only plan cannot be applied in a second process. v1 is therefore a
**text-artifact contract**: content must be valid UTF-8, which every
generated artifact in the inventory already is, and `GeneratedArtifact`
already refuses empty content. A binary artifact would require version 2.

Each row's `path`, `mode`, and `extension_region` are exactly
`GeneratedArtifact`'s existing fields, so a plan row converts to a
`GeneratedArtifact` without widening that type's `@api` constructor. The
plan does **not** contain `.waaseyaa/generated.json`: that document is
composed by the transaction authority from the plan plus the carried roster
(D-2.6), and a plan that declared it would be claiming ownership of state it
cannot see.

#### D-6.2 `ProjectStateIdentity` and `EvaluatedArtifactPlan` — the evaluation half

`Waaseyaa\SiteContract\Generation\ProjectStateIdentity` (new,
`site-contract`, Layer 0) is the **captured precondition identity**: the
closed record of exactly what evaluation observed, and therefore exactly
what may not change under it. Its canonical document,
`{"schema": "waaseyaa.project_state", "version": 1}`:

| Member | Value |
|---|---|
| `generated_metadata_sha256` | `sha256` of `.waaseyaa/generated.json` bytes, or 64 zeros when absent |
| `manifest_sha256` | `sha256` of `.waaseyaa/site.yaml` bytes, or 64 zeros when absent |
| `composer_json_sha256` | `sha256` of `composer.json` bytes, or 64 zeros when absent |
| `targets` | list of `{path, state, sha256, mode}`, sorted by `path` |

`targets` is the union of the plan's artifact paths and every path recorded
to a unit the plan supplies or retires — precisely the set evaluation reads.
`state` is `absent`, `file`, or `other` (a directory, symlink, or special
file, which evaluation refuses but the identity must still record as
observed). `sha256` is the file's bytes or 64 zeros. `mode` is `0644`,
`0755`, `other`, or `unknown` on a host where
`SiteHostPlatform::enforcesPermissionBits()` is false.

`Waaseyaa\SiteContract\Generation\EvaluatedArtifactPlan` (new,
`site-contract`, Layer 0) is the result of evaluating one `ArtifactPlan`
against one project. It is constructed only by `SiteInitializationService`,
because only the execution authority observes a project (D-3), and it is
immutable once constructed:

- `plan` — the `ArtifactPlan`, verbatim and unmodified.
- `planDigest` — D-6.3.
- `projectState` / `projectStateDigest` — the `ProjectStateIdentity` and its
  digest.
- `status` — `array<string, 'created'|'changed'|'unchanged'|'refused'>`,
  keyed by the plan's artifact paths. This is the widened output shape of
  the computation `SiteInitializationService::prepare()` already performs
  and today surfaces only as `SiteInitializationResult::$changedPaths`, a
  flat list with no per-path status or refusal detail. #2846 widens that
  existing computation's output; it does not add a second engine that
  independently re-derives it.
- `setDelta` — `{adds: list<string>, drops: list<string>}`, both sorted:
  the comparison of the plan's path set against the recorded set for the
  unit it supplies (D-2.3a). Empty on a first publish, since nothing is
  recorded to compare.
- `refusals` — `list<{code: string, path?: string, message: string}>`, the
  coded detail behind every `refused` status.

`--dry-run` renders an `EvaluatedArtifactPlan`. So does the evaluation half
of an apply, which is why no check can differ between them.

#### D-6.3 The canonical plan digest

**`plan_digest = sha256(CanonicalJson::encode($planDocument) . "\n")`**,
where `$planDocument` is exactly the D-6.1 document — `schema`, `version`,
`generator`, `unit`, `input_digest`, `artifacts`, `retires`,
`registrations`, `companion_tests`, `set_evolution`, `schema_effects`,
`config_effects` — and nothing else.

`CanonicalJson::encode()` (`packages/site-contract/src/CanonicalJson.php`)
`ksort`s every object's keys with `SORT_STRING` and encodes with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, so object key order is
total without the compiler choosing it. It does **not** reorder lists, so
the plan contract fixes every list order explicitly, and a plan whose lists
are not in that order is invalid rather than silently re-sorted:

- `artifacts` — ascending byte-wise by `path`; paths unique.
- `retires`, `companion_tests`, `schema_effects`, `config_effects` —
  ascending byte-wise; entries unique.
- `registrations` — ascending byte-wise by `fqcn`, then by `group`, with an
  absent `group` sorting before any present one; `fqcn` unique.

The digest is a function of compiler output alone. `status`, the project
state, the evaluation's wall-clock time, and the operator's terminal are all
outside it — which is what makes two dry-runs of the same input at different
times digest-identical, and what makes the digest a stable review handle.

#### D-6.4 The result/error envelope

`Waaseyaa\SiteContract\Generation\ArtifactApplyResult` (new,
`site-contract`, Layer 0), canonical document
`{"schema": "waaseyaa.artifact_result", "version": 1}`:

| Member | Value |
|---|---|
| `outcome` | `planned` (dry-run), `applied`, `no_changes`, `cancelled`, or `refused` |
| `plan_digest` / `project_state_digest` | the two identities the outcome is bound to |
| `status` | the `EvaluatedArtifactPlan`'s per-path status map |
| `changed` | the paths actually published, sorted |
| `recovered_interrupted_transaction` / `cleanup_pending` | today's `SiteInitializationResult` booleans, unchanged in meaning |
| `errors` | `list<{code, path?, pointer?, message}>`, `code` from the `GEN0xx` family (D-5); empty unless `outcome` is `refused` |

`ArtifactApplyResult` is also the generation binding of the
change-receipt envelope; D-14.7 fixes how these members map onto it and
where the receipt is durably recorded.

This is a strict superset of today's `SiteInitializationResult`
(`changedPaths`, `dryRun`, `recoveredInterruptedTransaction`,
`cleanupPending`, `cancelled`), so no information available today is lost;
`dryRun` and `cancelled` are absorbed into `outcome`. `pointer` is a JSON
Pointer into the plan document, so a refusal about a specific artifact row
or registration is addressable the same way `SITE0xx` addresses a manifest.

#### D-6.5 The apply input, and what `GEN005_STALE_PLAN` binds

`Waaseyaa\SiteContract\Generation\ArtifactApplyRequest` (new,
`site-contract`, Layer 0), canonical document
`{"schema": "waaseyaa.artifact_apply_request", "version": 1}`, carries
exactly three members:

- `plan` — the `ArtifactPlan` document verbatim, the bytes included.
- `plan_digest` — the digest the operator reviewed.
- `project_state_digest` — the `ProjectStateIdentity` digest the reviewed
  evaluation was computed against.

The request carries the **plan itself**, not merely its digest, and the
compiler is not re-run at apply time in a two-process flow. That is not
redundancy: `make:migration` and `make:storage-migration` choose their target
filename from `date('Ymd_His')` at compile time, so recompiling at apply
would produce a different, equally valid plan and the operator's review
would not bind anything. Carrying the plan makes apply's input *provably*
the reviewed artifact for every generator, time-dependent or not, and
`plan_digest` is then a self-check against transport corruption.

**`GEN005_STALE_PLAN` is the failure of that binding.** Under the exclusive
lock, before any byte is staged, apply:

1. recomputes `plan_digest` over the plan document it was handed, and
   refuses `GEN005` if it differs from the request's `plan_digest`;
2. recomputes the `ProjectStateIdentity` over the same target set the
   evaluation used, and refuses `GEN005` if its digest differs from the
   request's `project_state_digest`, naming the first differing member or
   target path in the error's `pointer`/`path`.

Only then does it re-run evaluation and publish. So a `composer.json` edited
by a human between dry-run and apply, a target file created or modified in
that window, or a concurrent publish that changed the unit roster are all
one decidable, coded refusal instead of an incidental collision message.

In the default single-invocation flow (`make:content-type story` with no
`--dry-run`), compile, evaluate, and apply happen once in one process, and
the same two digests are computed and passed through the same check. There
is one code path, not a fast path and a careful path.

#### D-6.6 Registrations, companion tests, and reserved effects

`ComposerProviderRegistration` (`Waaseyaa\SiteContract\Generation\`, new,
Layer 0) is `{fqcn: string, group?: string}` — the typed replacement for
`MakeContentTypeHandler::registerProvider()`'s direct
`json_decode`/mutate/`json_encode` of `composer.json`
(`packages/cli/src/Handler/MakeContentTypeHandler.php` lines 280–313).
`composer.json` cannot become a wholly generator-owned `GeneratedArtifact`
the way `public/index.php` can — a real application's `composer.json` has
hundreds of unrelated, user-owned keys — so it is not modeled as file
content at all. It is modeled as a **merge instruction**:
`SiteInitializationService` decodes the project's current `composer.json`,
applies every pending registration idempotently (already present ⇒ no-op,
matching `registerProvider()`'s existing `return false` behavior),
re-encodes with the project's existing formatting conventions, and writes it
back **inside the same transaction and journal as every other artifact in
the plan** — atomic with the file writes it accompanies, bound by
`composer_json_sha256` in the captured project state so a concurrent edit is
a `GEN005` refusal rather than a silent clobber, and rolled back with
everything else on failure. Registrations are attributed to the unit that
declared them, so retiring a unit withdraws exactly its own and no others.

`companion_tests` is bookkeeping cross-reference, not a new artifact kind: a
companion test is an ordinary artifact row under a `tests/` path, and this
list exists only so tooling (a future "every scaffolded content type ships a
test" CI gate, or `site:doctor`) can enumerate them without parsing paths by
convention.

`schema_effects` and `config_effects` are reserved and empty for every
compiler this ADR inventories. No `merge` command touches entity-storage
schema or configuration generation directly; the reservation exists so
#2664's `install:init` composition (already `out_of_scope` per D-4) and any
future schema-touching recipe have a typed place to declare intent without a
second plan shape. When they become real they are unit-attributed like every
other effect (D-10.1).

**Migration declarations.** `make:migration` and `make:storage-migration`'s
target path embeds a timestamp chosen with `date('Ymd_His')` at call time.
Compiled into an `ArtifactPlan`, that timestamp is part of the compiler's
**validated input** and is therefore captured once, at compilation, and
covered by `input_digest` and `plan_digest`. Dry-run and the apply that
follows it name the identical file because apply executes the plan it was
handed rather than recompiling (D-6.5). Migrations are not a special case in
the model; they are the one existing generator whose input happens to
include a clock reading.

#### D-6.7 Decided here versus implemented in #2846

Decided: the type split (immutable `ArtifactPlan` versus
`EvaluatedArtifactPlan`), the four `waaseyaa.*` v1 documents and their
closed member sets, the exact canonical digest formula and list orders
(D-6.3), the captured project-state identity and its target set (D-6.2), the
result/error envelope (D-6.4), and that apply carries the plan and is bound
to both digests by `GEN005` (D-6.5). Implemented by #2846: the classes, the
extraction of the evaluation half out of `prepare()`, the widened result
surface, and the tests — including a red boundary test proving dry-run and
apply run the identical check set, and one proving a mutated plan or a
touched target refuses `GEN005` rather than publishing.

### D-7. Compatibility and deprecation windows; script migration detection

No `merge`-disposition command's **externally observable behavior** —
argument names, default output shape, existing exit codes, existing file
paths and content — changes by force of this ADR. Migration happens per
command, one PR at a time, per the D-12 sequence, and each such PR:

1. **Preserves the command's existing default invocation exactly**, subject
   to rules 4 and 6. A script calling `make:content-type story --fields=...`
   in an initialized project gets identical stdout, identical files, and an
   identical exit code after migration — only the *mechanism* underneath (a
   direct write vs. a compiled `ArtifactPlan` published by
   `SiteInitializationService`) changes.
2. **Adds new opt-in surface** (`--json`/`--dry-run` structured output where
   the command doesn't already have one) without touching the default path —
   exactly `site:init`'s own `--dry-run`/`--answers`/`--yes` are additive to
   what a bare invocation already did.
3. **Preserves existing bespoke exit codes unless and until a named, dated
   deprecation window closes them.** `make:storage-migration`'s `0`–`4`
   contract is the concrete case: it continues to return exactly those five
   values through migration; convergence onto the general `0`/`1`/`2`
   convention (if ever pursued) is its own future issue with its own
   deprecation notice, never bundled silently into the artifact-plan
   migration.
4. **Announces removal, not silent behavior change, for anything that does
   change.** Two named cases for `make:content-type`: its current
   unconditional `composer.json` write becomes subject to the same
   collision refusal every other generated artifact gets (a concurrently
   edited `composer.json` today silently loses the concurrent edit; after
   migration it is a `GEN005`/`GEN003` refusal), and `--force` narrows from
   "overwrite whatever is there" to "regenerate this unit's own recorded
   paths". After migration `--force` may **never** overwrite a path owned by
   another unit or an unowned pre-existing file; the mechanics by which it
   waives the retirement drift proof for the unit's *own* paths are the
   migrating PR's to design within that constraint. Both are *safety*
   changes (fewer silent clobbers), documented in the migrating PR's
   changelog fragment, not treated as compatible-by-default.
5. **A script wanting to detect a migrated command in advance** checks
   `waaseyaa <command> --help`/its declared options for the new
   `--json`/`--dry-run` flag, exactly the detection path already available
   for `site:init` today (`SiteInitHandler` registers `--dry-run` as an
   ordinary Symfony Console option, discoverable without parsing prose). No
   separate machine-readable "is this command migrated yet" registry is
   introduced — the command's own declared option surface is the answer,
   consistent with how Symfony Console commands are already introspectable.
6. **A migrated command requires an initialized site.** A non-root
   generation unit may only be published into a project that already has a
   root unit, because `.waaseyaa/generated.json`'s required v1 members
   `manifest_digest` and `generator_version` are meaningless without
   `.waaseyaa/site.yaml`, and lines 114–117 already refuse to read ownership
   metadata that exists without its manifest authority. `skeleton/` ships no
   `.waaseyaa/`, so `make:content-type` works on a bare `create-project`
   tree today and, after migration, refuses and names `site:init`. The
   considered alternative — letting a scaffold bootstrap ownership metadata
   with a sentinel `manifest_digest` — is rejected: it manufactures a second
   document shape with weaker binding, contradicting D-1's single-authority
   rule, to avoid an ordering that `docs/specs/site-golden-path.md`'s
   five-phase lifecycle already imposes. This is a rule-4 announced change,
   not a rule-1 preserved invocation, and the migrating PR says so.

### D-8. How #2438 presets and #2787 blueprints compile to the same plan without sharing product-specific DTOs

`site-contract` already proves this exact fan-in pattern for `site:init`:
`SiteRecipeRendererInterface::render(SiteManifest): list<GeneratedArtifact>`
(`packages/site-contract/src/Generation/SiteRecipeRendererInterface.php:14-15`)
is implemented by several distinct recipe renderers, each of which knows its
own input shape intimately and each of which emits the same output type.
`SiteArtifactRenderer::render()`
(`packages/site-contract/src/Generation/SiteArtifactRenderer.php:30`) is the
composer above them, and it — not the per-recipe interface — is what returns
`GeneratedSite`. An earlier draft of this section attributed the
`GeneratedSite` return to the interface itself; the fan-in argument is
unchanged, but the citation was wrong and this ADR becomes accepted evidence
for the issues that consume it.
This ADR generalizes that one interface's shape, unchanged in spirit, to
every input surface in the inventory:

| Input (product-specific, never shared) | Compiler (knows the input) | Unit supplied | Output (shared, the only sharing point) |
|---|---|---|---|
| `SiteManifest` resolved from #2442 `minimal`/`editorial` preset answers — per ADR-024 D-3/D-4, **no preset DTO persists**; a preset resolves to the same ordinary manifest shape any other `site:init` answer set does | existing `SiteRecipeRendererInterface` implementations composed by `SiteArtifactRenderer` (unchanged) | root `site` (`managed`) | `ArtifactPlan` (D-6.1) |
| `ApplicationBlueprint` (ADR-023, existing typed value, `Waaseyaa\SiteContract\Blueprint\`) approved via a matching `BlueprintDecisionReceipt` | new blueprint-aware renderer/compiler (#2787's own implementation work — not authorized here) | root `site` (`managed`) | `ArtifactPlan` |
| Per-handler scaffold input (e.g. `make:content-type`'s `$name`/`--fields`, already validated by `AbstractMakeHandler`) | each migrating `make:*` handler itself, or a small shared scaffold compiler if the follow-on issue finds enough common shape — that choice is implementation detail #2846/its follow-on decides, not this ADR | its own non-root unit (`seeded`) | `ArtifactPlan` |

The DTOs in the first column are, and remain, deliberately incompatible with
one another — a `SiteManifest` is not an `ApplicationBlueprint` is not a
`make:content-type` argument bag, and no compiler is asked to understand
another compiler's input. **The single shared surface is the output type**,
`ArtifactPlan`, defined exactly once, in `site-contract` (D-3), evaluated
and published exactly once, by `SiteInitializationService` (D-1). This is
the precise reading of "compile to the same plan without sharing
product-specific DTOs": the sharing is at the output boundary, by
construction, because nothing product-specific ever crosses into `cli` or
into the transaction — only artifact bytes, registration effects, and the
unit declaration do.

Note the third column. A preset and a blueprint both supply the *root* unit,
because both are ways of arriving at a `SiteManifest` that the manifest-
derived render is a pure function of; a scaffold supplies its own non-root
unit, because it is not derived from the manifest and must not be compared
against it. That is the whole reason D-2 exists, and it is what keeps the
root unit's frozen set intact while scaffolds remain expressible.

Renderer purity is an invariant of this arrangement, not an accident:
every recipe renderer and every root-unit compiler must be a pure function
of its declared input, with no read of the clock, the filesystem, or the
environment. Today's renderers satisfy it and nothing gates it; an
architecture test asserting it is named as follow-on work in D-12 step 8.
(`make:migration`'s clock reading is not a counter-example: D-6.6 makes the
timestamp part of the compiler's *input*, captured once, precisely so the
compiler itself stays pure.)

### D-9. After migration, no handler may bypass the execution authority

**After a `merge`-disposition command's migration PR lands, that handler
may not call `file_put_contents()`, `mkdir()` for an application-source
target, `json_decode`/`json_encode` on the project's `composer.json`, or any
other direct filesystem/`composer.json` mutation of its own. Every write of
every application file, and every `composer.json` registration, for every
generator inventoried by this ADR, happens exactly once, inside
`SiteInitializationService`, through a compiled `ArtifactPlan`.** This is
the literal content of #2845's acceptance criterion "No handler may write
application files or mutate composer.json outside the selected execution
authority after migration," restated as a rule with no exception carved for
any command in the D-4 inventory. A future architecture test enforcing this
rule mechanically (the natural analogue of the existing
`bin/check-getquery-bindings` unbound-query gate, or `bin/check-package-layers`
itself) is an explicit candidate the D-12 sequence names for the follow-on
issue; writing that gate is implementation, not authorized by this ADR.

### D-10. Provenance cardinality, worked plans, and what survives publication

#### D-10.1 Provenance is carried at generation-unit cardinality

**Provenance is recorded per generation unit — not per artifact, and not per
effect.** Each non-root unit record carries `{fqcn, version}` and
`input_digest` (D-2.1); each non-root artifact row names its owning unit;
each registration and each future schema/config effect is attributed to the
unit that declared it. Per-artifact provenance is therefore *derivable* by
lookup (row → unit → generator) and is deliberately not duplicated into the
row: two copies of the same fact can disagree, and a row-level FQCN that
disagreed with its unit's would make ownership ambiguous in exactly the way
D-2's partition exists to prevent.

The unit is the right granule because it is the granule at which generation
actually happens: one compiler, one validated input, one set of paths that
are created and retired together. An artifact has no independent
provenance — it was produced as part of a unit — and an effect has none
either.

The root unit's provenance is the existing top-level pair:
`generator_version` plus the installed `SiteArtifactRenderer`. Blueprint
materialization does not get its own unit; it is a `managed` publish of the
root unit whose render took an approved receipt as a second input, exactly
as ADR-023 specifies ("current generated metadata is a pure function of the
manifest, while blueprint application makes the canonical approval receipt a
second input"), and its evidence is the `application_blueprint` member
ADR-023 already places in this same document.

#### D-10.2 Worked demonstration

This is a **target-shape demonstration**, not a report of existing output —
no code emits these documents today. It shows what a `merge`-migrated
`make:content-type story --fields="title:string,body:text"` must compile to
and how the three D-6 documents relate. Artifact `content` is elided as
`"<rendered entity class>"` for readability; in a real plan it is the
verbatim file bytes.

**1. The immutable plan** (`--dry-run --json` prints this inside the
evaluated result; the same document is what apply is handed):

```json
{
  "schema": "waaseyaa.artifact_plan",
  "version": 1,
  "generator": {
    "fqcn": "Waaseyaa\\CLI\\Handler\\MakeContentTypeHandler",
    "version": 1
  },
  "unit": { "id": "scaffold:content-type:story", "disposition": "seeded" },
  "input_digest": "<sha256 of the canonical validated input>",
  "artifacts": [
    { "path": "src/Entity/Story.php", "mode": "0644", "content": "<rendered entity class>" },
    { "path": "src/Provider/StoryServiceProvider.php", "mode": "0644", "content": "<rendered provider class>" }
  ],
  "retires": [],
  "registrations": [
    { "fqcn": "App\\Provider\\StoryServiceProvider", "group": "content" }
  ],
  "companion_tests": [],
  "schema_effects": [],
  "config_effects": []
}
```

No `status`. Nothing about the project. `plan_digest` is
`sha256(CanonicalJson::encode(<that document>) . "\n")` (D-6.3).

**2. The evaluated plan** — what `SiteInitializationService` returns for
dry-run, and what the apply half recomputes:

```json
{
  "schema": "waaseyaa.artifact_result",
  "version": 1,
  "outcome": "planned",
  "plan_digest": "<64 hex>",
  "project_state_digest": "<64 hex>",
  "status": {
    "src/Entity/Story.php": "created",
    "src/Provider/StoryServiceProvider.php": "created"
  },
  "changed": [],
  "recovered_interrupted_transaction": false,
  "cleanup_pending": false,
  "errors": []
}
```

The `project_state_digest` covers `{generated_metadata_sha256,
manifest_sha256, composer_json_sha256, targets}` (D-6.2); `targets` here is
the two plan paths (both `absent`) because `scaffold:content-type:story` is
not yet recorded, so no recorded rows join the target set.

**3. The apply request** binds apply to exactly that reviewed plan:

```json
{
  "schema": "waaseyaa.artifact_apply_request",
  "version": 1,
  "plan": { "...": "the document from step 1, verbatim" },
  "plan_digest": "<the digest from step 2>",
  "project_state_digest": "<the digest from step 2>"
}
```

If a human edits `composer.json`, or `src/Entity/Story.php` appears, between
steps 2 and 3, the recomputed project-state digest differs and apply refuses
`GEN005_STALE_PLAN` naming the differing target — instead of publishing over
a project it never evaluated.

**The reconciliation this walkthrough turns on.** At evaluation,
`scaffold:content-type:story` is not in the recorded roster, so it is a new
unit and **no set comparison runs** — which is precisely where today's code
refuses: the incoming set would be the two Story paths, `$recordedPaths`
would be the root unit's seven-plus paths, and line 143's
`$expectedOwnedPaths !== $recordedPaths` would throw "Generated ownership
metadata does not match this generator version." Under D-2 that comparison
is never posed, because the two sets belong to different units. Every
existing per-path check still runs unchanged: `assertSafeTarget()`,
`assertRegularOwnedFile()`, mode and content comparison, and the
unowned-collision refusal.

And the reverse half: a later ordinary `site:init` with an unchanged
manifest supplies the root unit, compares the root set against the recorded
root set (equal), carries `scaffold:content-type:story` forward by record,
and touches neither Story file. The set change that today would refuse
forever never arises. The developer's subsequent edit to
`src/Entity/Story.php` — the point of the scaffold — refuses nothing, now or
ever, because the unit is `seeded`.

**A blueprint materializing the same entity** produces a plan that differs
in three visible ways and is otherwise the same shape: `generator.fqcn` is
the blueprint-aware root-unit renderer, `unit` is `{id: "site", disposition:
"managed"}`, and `artifacts` is the *whole* root set rather than two files,
because the root unit is re-rendered as a complete set. Its evaluated
result's `status` marks the untouched root artifacts `unchanged` and the two
new ones — which, being part of the root set, change the root unit's frozen
path set and are therefore refused unless the site is being initialized for
the first time. That is the pre-existing constraint recorded in D-2.8, not a
new one this ADR introduces, and it is #2787's to confront with the manifest
authority it already owns.

#### D-10.3 What provenance actually survives publication (correction)

An earlier draft of this ADR claimed that "an operator or `site:doctor` can
always tell a hand-scaffolded content type from a blueprint-materialized
one". **That claim was false and is withdrawn.** Plan-level provenance is
not persisted: `.waaseyaa/generated.json` persists exactly
`['artifacts','generator_version','manifest_digest','schema','version']`
(the key-set assertion at `SiteInitializationService.php` line 429, matching
`GeneratedSite`'s composition at `GeneratedSite.php` lines 51–60) — a single
manifest-level `generator_version`, no FQCN, and no per-artifact ownership.
A `generator` field that lives only in a plan object is gone the moment the
command exits.

What is true under D-2, stated exactly:

- **For non-root state recorded after this model ships**, provenance is
  recoverable at unit granularity: a path resolves to its unit, and the unit
  record carries `{fqcn, version}` and `input_digest`. `site:doctor` can say
  which compiler at which version produced a file, and whether the input
  that produced it still digests the same.
- **For root-owned paths**, provenance is manifest-derived. Whether a
  blueprint was applied is read from the `application_blueprint` evidence
  member ADR-023 already specifies — not from a compiler FQCN, because a
  plain render and a blueprint-aware render both publish the root unit. The
  receipt evidence is the distinction, and it is ADR-023's mechanism, not a
  second one.
- **For anything published before this model ships**, or by an unmigrated
  command, nothing is recovered. Pre-existing rows are root-unit rows;
  files written by today's `make:content-type` are unowned and unrecorded.
  This ADR does not adopt them retroactively, and an `--adopt` flow that
  records existing files as a unit is explicitly out of scope for both this
  decision and #2846.
- **Reproducibility, as distinct from provenance, is weaker for non-root
  units and must be said in those words.** For the root unit, `site:doctor`
  re-renders from the manifest and byte-compares, proving both "unmodified"
  and "reproducible". For a non-root unit it has no oracle — it cannot
  re-run `MakeContentTypeHandler`'s compiler from an `input_digest` — so it
  proves only that a `managed` row is unmodified, and for a `seeded` row not
  even that (by design). A future unit record may persist its canonical
  validated input and declare itself replayable, restoring the oracle; that
  constrains every migrating compiler and belongs in each migration's own
  PR, not in this decision.

### D-11. Threat model

| Threat | Mitigation | Owning code (existing unless noted) |
|---|---|---|
| **Traversal** (`../../../etc/passwd`, absolute path, backslash) | Reject at artifact construction and again at target evaluation; `GEN001` (D-5) | `GeneratedArtifact::__construct()` rejects empty path, leading `/`, backslash, and `..` segments (`packages/site-contract/src/Generation/GeneratedArtifact.php` line 16) |
| **Embedded NUL in a target path** | Reject at target evaluation; `GEN001` | `SiteInitializationService::assertSafeTarget()` (`packages/cli/src/Site/SiteInitializationService.php` line 589) — this check is *not* in `GeneratedArtifact`'s constructor; `assertSafeTarget()` re-runs the other four clauses and adds the NUL clause and the symlink-component walk |
| **Symlinks** (a path component or target resolves through one) | Reject at containment check; `GEN002` | `SiteInitializationService::assertSafeTarget()`'s component walk (lines 592–601) and `assertRegularOwnedFile()` (lines 604–614); `SitePathContainment` |
| **Races** (concurrent `site:init`/scaffold invocations) | Exclusive advisory lock around the transaction; `GEN008` on contention | `SiteInitializationService` lock + `SiteInitializationLockedException` |
| **Partial writes** (process or host death mid-publication) | Write-to-temp-then-rename plus a journaled per-item state machine; the next run recovers to the exact prior generation before starting new work | `SiteInitializationService` journal/recovery (existing, `docs/specs/site-golden-path.md` "Initialization") |
| **Ambiguous overwrite** (an extension region's managed-content digest drifted, so regeneration can't tell a user edit from a substitution) | Refuse regeneration; `GEN004`; the sanctioned unblock is the existing `framework.observed_lock_sha256` rebind, never a silent overwrite | `GeneratedArtifact::regionBounds()`/`withExtensionFrom()`, `docs/specs/site-golden-path.md` "Changed managed bytes" |
| **Stale approval** (the plan or the project changed between review and apply — a human edited `composer.json`, a target appeared, a concurrent publish changed the unit roster) | Apply recomputes the plan digest and the captured project-state identity under the lock and refuses `GEN005` on either mismatch, naming the differing member or target — **net-new for #2846** | D-6.5 (named here, not implemented here) |
| **Malicious identifiers** (a name crafted to break class-name/namespace/path assumptions, or a unit id crafted to shadow `site`) | Reject, never sanitize, before the value reaches a class name, namespace, path, or unit id; `GEN006` | `AbstractMakeHandler::validateIdentifier()`/`validateMachineName()`; the D-2.1 unit-id grammar (net-new) |
| **Unsupported field/capability declarations** (an entity field type or generator-feature token the installed cohort doesn't advertise) | Fail closed at validation, before compilation; `GEN007`, generalizing the existing `SITE042`/generator-feature-token refusal | `ApplicationBlueprintValidator` (`SITE042`), `FieldTypeManager::blueprintFieldTypeIds()` |
| **`composer.json` read-modify-write race** (two generators, or a generator and a human editor, mutate it concurrently) | The D-6.6 registration merge runs inside the same transaction/journal/lock as every artifact and is bound by `composer_json_sha256` in the captured project state; this closes the exact gap `MakeContentTypeHandler::registerProvider()` has today | D-6.6 (net-new type + execution path, named here, implemented by the D-12 sequence) |
| **Cross-unit path capture** (a scaffold claims a path the root unit owns, or two scaffolds claim one path) | The recorded owner wins permanently; the second claimant is `GEN003`, identically in dry-run and apply; no merge, no re-parenting | D-2.3 steps 4–5 (net-new) |
| **Silent ownership loss** (a supplied unit quietly stops emitting a recorded path, orphaning a governed file) | An undeclared drop is `GEN009`; retirement must be declared, applies the same drift proofs a publish applies, and is journaled and rolled back like any other item | D-2.3 step 6 (net-new) |
| **Tampered ownership metadata asserting foreign ownership** (a hand-edited or merge-conflicted `.waaseyaa/generated.json` claiming `seeded` ownership of arbitrary paths) | Bounded and asymmetric by construction: a recorded row can only *block* a write as a collision and can never cause one; `assertSafeTarget()` still runs against every recorded path; duplicate or unknown ownership is `GEN010` at read | D-2.3 step 1, D-2.4 (net-new; this is a genuine widening of what the document asserts, recorded rather than inherited) |

### D-12. Migration sequence for follow-on issues: one dependent lane, one parallel lane

#### Lane A — sequenced, each step depending on the one before

1. **#2846 — transactional artifact-plan engine.** Adds the D-6 types
   (`ArtifactPlan`, `EvaluatedArtifactPlan`, `ProjectStateIdentity`,
   `ArtifactApplyRequest`, `ArtifactApplyResult`,
   `ComposerProviderRegistration`) to `site-contract`; splits target
   evaluation out of `prepare()`; implements the D-2 unit model — read-time
   promotion, per-unit reconciliation, carry-forward, composed metadata,
   the retirement journal verb, and the D-2.7 `site:doctor` split — and
   codes the `GEN0xx` exceptions, `GEN011` and D-2.3a's additive successor
   evolution included — the `set_evolution` plan member, the
   `EvaluatedArtifactPlan::$setDelta` comparison, the refusals on a
   frozen-plan addition and an evolving-unit drop, the architecture test
   asserting D-2.3a's closed eligibility list, and the
   `docs/specs/site-golden-path.md` update that records the one named
   exception to the frozen-set sentence (D-2.4). Ships with unit, adversarial,
   failure-injection, and recovery tests per its own acceptance criteria,
   plus the two ordering constraints this ADR fixes: the byte-identity
   fixture test lands **before** the metadata-composition relocation
   (D-2.6), and the `site:doctor` split lands **in the same slice** as the
   unit model (D-2.7), because a first multi-unit publish otherwise turns
   `site:doctor --strict` red and breaks every generated
   `bin/maintenance/site-verify`. It also returns the D-14.7 change receipt —
   the closed envelope, the outcome mapping, and
   `SiteInitializationService::CONTRACT_VERSION` as its sole version
   declaration (D-14.9) — in the same slice that introduces the apply
   result, because a result type shipped without its receipt binding would
   have to be widened again immediately. It persists no receipt: the durable
   sink is deferred to its own decision (D-14.7). **No `make:*`/`scaffold:*` handler
   changes in this step** — the engine exists and is proven before anything
   is asked to compile into it.
2. **#2787 — blueprint materialization**, subject to D-13's
   authority-expansion review gate for its addition to D-2.3a's eligibility
   list. Adds the blueprint-aware root-unit compiler, consuming #2846's `ArtifactPlan` and the existing
   `ApplicationBlueprint`. Composes `SiteArtifactRenderer` +
   `SiteInitializationService` exactly as its own issue text already commits
   to ("Do not create a second transaction, ownership manifest, project
   initializer, or product-specific compiler").
3. **`make:content-type` migration (own PR).** The critical case — first
   among the scaffolds because it is the only `composer.json`-mutating
   command and the registration path needs at least one real caller before
   any other `merge` command adopts it. Publishes a `seeded` non-root unit;
   default invocation unchanged in an initialized project, with the two
   D-7 rule-4 announcements (`--force` narrowing, `composer.json` collision)
   and the D-7 rule-6 initialized-site requirement in its changelog
   fragment.
4. **`make:public`, `make:migration`, `make:storage-migration` migration**
   (own PR each, or combined if the diff stays reviewable) — same D-7
   contract; `make:storage-migration` keeps its existing five-value exit
   code contract through this step, unchanged.
5. **`scaffold:auth` migration (own PR).** Last among `merge` commands
   because its existing drift-tracking manifest (`AuthUiScaffoldManager`) is
   the most structurally different from a plain generated artifact and needs
   its own design pass for how `--check`'s read-only mode coexists with the
   plan/apply lifecycle. If that pass concludes it cannot be expressed as a
   generation unit, that conclusion is an amendment to this ADR, not a
   silently retained second mechanism.
6. **#2664 — `project:init`, upgrades, AI-verification.** Composes
   `site:init` + `install:init` per its own acceptance criteria
   ("project:init composes site:init; it does not fork site-profile
   semantics"); by this point every `merge` command it might orchestrate
   already speaks the same contract, and every unit it verifies is recorded
   in the one generated-state authority it reads.
7. **Follow-on: mechanical enforcement of D-9.** A CI gate proving no
   `packages/cli/src/Handler/*.php` calls `file_put_contents()`/`mkdir()`
   for an application-source target or touches `composer.json` outside
   `SiteInitializationService`, analogous in shape to
   `bin/check-getquery-bindings`. Ordered last because it should have
   nothing left to baseline-exempt once steps 3–5 land.
8. **Follow-on: renderer-purity architecture test.** Asserts that every
   `SiteRecipeRendererInterface` implementation and every root-unit compiler
   is a pure function of its declared input (D-8). Independent of steps 3–7.

#### Lane B — parallel, dependent only on this ADR's acceptance

**#2442 — `site:init` `minimal`/`editorial` presets.** This is **not** a
step in Lane A and has **no dependency on #2846**. A preset resolves to an
ordinary `SiteManifest` (ADR-024 D-3/D-4: no preset DTO persists) and
composes the unmodified `SiteManifestParser` →
`SiteArtifactRendererFactory` → `SiteInitializationService` chain that
exists and passes today. It supplies the root unit on a first
initialization, touches no generation-unit surface, no plan type, and no
`GEN0xx` code, and its own implementation on `fix/2442-init-presets` was
built under the explicit constraint not to invent the new plan contract. It
may land before, during, or after any Lane A step. Once #2846 lands,
`site:init` — presets included — publishes through the widened engine like
every other root-unit publish, with no change to #2442's own surface.

An earlier draft of this ADR both ordered #2442 "after step 1 (#2846)" and
said in the same breath that it "only needs the already-existing
`SiteManifest` → `GeneratedSite` path". The second half was correct; the
ordering claim was not, and it is withdrawn.

Each Lane A step is its own issue-traceable PR with a red boundary test
before implementation, per the workflow's design-first rule; no step
authorizes skipping ahead of a step it depends on.

### D-13. Composition statement for #2664, #2846, and #2787

All three compose the single authority named in D-1/D-2/D-3. None may
re-implement any part of it:

- **#2846 may not**: create a second transaction/journal/lock
  implementation; create a second generated-ownership manifest distinct from
  `.waaseyaa/generated.json`; create a second collision, containment, or
  symlink-safety check; introduce a per-run disposition flag or any way for
  a caller to choose `seeded` for a unit whose compiler is not on the closed
  allowlist; bump `waaseyaa.generated` past version 1 to carry the unit
  members; persist change receipts to any durable sink, or create any second
  durable record that competes with `.waaseyaa/generated.json` for authority
  over what is owned or with the transaction journal for authority over
  recovery (D-14.7 defers the sink to its own decision); restate
  `authority_version` anywhere but
  `SiteInitializationService::CONTRACT_VERSION` (D-14.9); or extract a
  shared protocol type, interface, or package on the strength of this one
  binding (D-14.8). It **must**: extend `SiteInitializationService`'s
  existing evaluation, result, and dry-run surface; implement D-2's unit
  model inside the one generated-state authority, D-2.3a's declared
  additive evolution and its closed eligibility list included; add the D-6
  types to
  `site-contract` beside the types they extend; and satisfy D-14.1's seven
  obligations, returning a D-14.3 change receipt for every terminated
  controlled-apply or recovery attempt, including the non-mutating terminal
  outcomes `refused` and `failed`.
- **#2787 may not**: create a second transaction, ownership manifest,
  project initializer, or product-specific compiler (its own issue text,
  restated here as binding on this ADR too); or give blueprint
  materialization its own generation unit — it is a root-unit publish
  (D-10.1), and its evidence is ADR-023's `application_blueprint` member.
  It **must**: consume `application_blueprint` from the existing
  `waaseyaa.site` v1 manifest (never a companion file or a Studio-private
  plan object — its own "Settled authority" section), and publish
  exclusively through the root-unit render plus
  `SiteInitializationService`.

  **#2787 carries an authority-expansion review gate.** Adding the
  approved-blueprint root compiler to D-2.3a's closed eligibility list is
  the only widening of a closed authority allowlist this ADR anticipates.
  The code change may be one line; changing a closed authority allowlist is
  never merely a test update, and #2787's acceptance is not satisfied
  without explicit review evidence for all six of these:

  1. **The engine controls eligibility, not the compiler.** The list is
     evaluated by the execution authority against the plan's declared
     `generator`; a compiler cannot assert its own eligibility by setting
     `set_evolution`.
  2. **Only the approved-blueprint root compiler is added.** No other
     compiler, and no generalization of the entry to a category.
  3. **A valid, digest-matched `BlueprintDecisionReceipt` is mandatory.**
     An unapproved or mismatched blueprint is not eligible to evolve a path
     set, whatever its plan declares.
  4. **It produces the existing root `site` unit** — not a new unit, not a
     parallel one (D-10.1, restated because eligibility makes the
     temptation concrete).
  5. **Missing or invalid approval, or an ineligible compiler carrying
     `additive`, is `GEN011`** — identically in dry-run and apply.
  6. **Architecture tests prove the boundary in both directions**: the
     existing manifest binding remains eligible, and every other compiler
     in the D-4 inventory remains frozen. A test asserting only the
     addition is insufficient.
- **#2664 may not**: fork `site:init`'s profile semantics; create a second
  hash/version engine for `ai:update --check/--apply` and `ai:verify`
  distinct from `.waaseyaa/generated.json`'s digests; or let Composer
  post-update touch anything outside generated/bounded regions (its own
  acceptance criteria, restated here as binding on this ADR too). It
  **must**: compose `site:init` and `install:init` as already-published
  commands, and read the one generated-state authority — units included —
  that D-1 and D-2 name.

Any of the three found, on implementation, to require a genuinely new
capability this decision cannot express extends this ADR with a follow-on
decision naming the extension — it does not invent a parallel mechanism
silently inside its own issue.

### D-14. The governed-change protocol and the change-receipt envelope

Doctrine holds that install, generate, upgrade, replay and rollback must
stop being separate interpretations of the same lifecycle. The earlier draft
of this ADR answered only half of that: it fixed a plan/preview/apply
contract that is correct for generation and said nothing about whether
schema migration — which owns its own planner, executor and ledger under
#1625/#2730/#2731 — is speaking the same language or a different one.
Leaving that open would settle it by default, and the default is the exact
authority discontinuity #2851 exists to prevent.

This section names the shared semantics now and binds only generation to
them now. It authorizes no schema work, no new package, and no shared
runtime code.

#### D-14.1 What the protocol is, and what it deliberately is not

The **governed-change protocol** is a set of obligations on any lifecycle
authority that mutates durable project state. It is a *contract*, not an
implementation: there is no protocol base class, no shared interface, and no
new package. Two authorities conform to it by satisfying its obligations in
their own types, not by importing each other's.

`protocol_version` is **1** for everything this ADR authorizes.

An authority conforming to the protocol must provide:

| # | Obligation | Why it is protocol-level and not domain-level |
|---|---|---|
| 1 | **Immutable, digest-bound plan.** A plan is a pure function of validated input and the planner's own version, carries no observation of the target, and is identified by a digest over its canonical document. | The review handle must be stable across processes and machines, or an operator's approval binds nothing. |
| 2 | **Side-effect-free preview.** Evaluating a plan against live state produces a decidable prediction and writes nothing. | Preview that can mutate is not preview, and a preview that differs from apply's own evaluation is a second interpretation. |
| 3 | **Stale-plan detection.** Apply recomputes both the plan digest and a captured precondition identity under the exclusive lock, and refuses with a typed code when either moved. | Otherwise the window between review and apply is an unbounded, silent race. |
| 4 | **Controlled apply.** State-changing work happens under an exclusive lock, is atomic with respect to interruption, and either reaches its declared end state or leaves the prior one. | Partial success reported as success is the failure mode the beta exit contract names by name. |
| 5 | **Typed change receipt.** Every terminated **controlled-apply or recovery attempt** emits one receipt conforming to D-14.3 — including attempts that terminate without changing anything, because `refused` and `failed` are outcomes a caller must be able to see. | What happened must be expressible in a shape a reader can interpret without knowing the domain. v1 requires the *typed outcome*, not its retention: see D-14.7. |
| 6 | **Verification.** The authority can re-derive, from durable state alone, whether its declared end state still holds. | Recovery and upgrade both require a truth test that does not depend on the run that produced the state. |
| 7 | **Recovery.** An interrupted apply resolves, on the next run, to a named prior state before new work begins, and says so. | A lifecycle whose interruption semantics are undefined cannot be operated. |

The protocol does **not** define: a shared plan type, a shared executor, a
shared lock, a universal ledger, a distributed transaction, or a
cross-domain rollback. Each authority owns its planner, its executor, its
durable record, and its recovery.

#### D-14.2 "One durable ownership record" means one per boundary

Exactly one authoritative record per lifecycle boundary — not one record for
the framework. `.waaseyaa/generated.json` is authoritative for generated
state; the migration ledger is authoritative for schema state; the audit
ledger is authoritative for authorization events; the validation read ledger
is authoritative for its own reads. These stay separate. What the protocol
requires is that each boundary's ownership is **explicit and
non-overlapping**, and that where cross-boundary work is reconstructed at
all it is reconstructed by *correlating* receipts rather than by one record
narrating another's business. No ledger may record, infer, or restate an
outcome that belongs to another authority.

Reconstruction is therefore **conditional on retention**, and v1 mandates no
retention (D-14.7). An authority that has not adopted a governed retention
sink can correlate receipts only within the process that emitted them. This
is a stated limit of v1, not a capability the protocol claims and fails to
deliver.

#### D-14.3 The change-receipt envelope

Every conforming authority emits receipts carrying at least these members.
The envelope is closed: an authority adds detail under `domain_payload`, not
as new top-level members.

| Member | Type | Meaning |
|---|---|---|
| `receipt_id` | string, unique, immutable | identifies this receipt for all time; never reused, never rewritten |
| `protocol_version` | int | governed-change protocol version (`1`) |
| `authority` | string, namespaced | the lifecycle owner, e.g. `waaseyaa.generation` |
| `authority_version` | int | the authority's own implementation/contract version |
| `operation` | string, domain-defined | stable operation name, e.g. `site.init` |
| `plan_digest` | 64 hex | the exact approved plan this outcome is bound to |
| `outcome` | enum | `applied` \| `no_op` \| `refused` \| `failed` \| `recovered` |
| `correlation_id` | string | shared identifier for one top-level operation |
| `causation_receipt_id` | string, optional | the direct predecessor in the causal chain, always another **change** receipt |
| `decision_receipt_id` | string, optional | the approval this outcome executed (D-14.6), never carried in `causation_receipt_id` |
| `issued_at` | RFC 3339 UTC | the time the authority issued this outcome |
| `domain_payload` | versioned object | authority-owned detail; carries its own `version` |

`issued_at` is the time the authority reached and issued this terminal
outcome, not the time the work began, and not a claim that anything was
stored — v1 emits receipts and retains none (D-14.7). It is wall-clock and
therefore never an input to any digest. A future retention sink that needs
to distinguish issuing from recording adds its own member; it does not
redefine this one.

#### D-14.4 The outcome vocabulary, and what does not earn a receipt

| `outcome` | Means |
|---|---|
| `applied` | the declared end state was reached and is durable |
| `no_op` | controlled apply began and terminated with no durable change — the declared end state already held |
| `refused` | the authority declined before changing anything, with a typed code |
| `failed` | the attempt neither reached its end state nor cleanly restored the prior one — the state requires operator attention |
| `recovered` | the durable effect of this run was resolving a prior interrupted attempt, and no new work was published |

**A receipt begins at controlled apply or recovery, not before.** Obligation
2 makes preview side-effect-free, so a dry-run yields its evaluation and
nothing more. The same boundary settles cancellation: an operator who
declines at confirmation does so **before** apply begins — in the generation
binding, `SiteInitializationService`'s authorize callback runs after
evaluation and before `publish()`
(`packages/cli/src/Site/SiteInitializationService.php:94-96`), so no byte is
staged, no journal item is opened, and nothing has been attempted to record.
**Pre-apply cancellation emits no receipt.** It is not a `no_op`: `no_op`
means apply ran and found the end state already satisfied, which is
operationally a different fact, and burying the difference in
`domain_payload` would hide a distinction the vocabulary exists to make. An
authority whose cancellation can occur *after* apply begins does not have
this option and must record the terminal outcome it actually reached.

This is also the one place the envelope deliberately does not mirror
`ArtifactApplyResult`, whose `planned` and `cancelled` values describe a
*return value* rather than a durable event.

**Recovery followed by new work is two receipts, not one.** When a run
resolves an interrupted transaction and then publishes, it emits a
`recovered` receipt and an `applied` receipt sharing a `correlation_id`,
with the second naming the first as its `causation_receipt_id`. An outcome
enum cannot express two durable effects, and collapsing them would make the
recovery invisible to anyone reading receipts rather than logs.

#### D-14.5 Correlation and causation

`correlation_id` groups every receipt belonging to one top-level operation.
An authority invoked without one mints one and is itself the top level; an
authority invoked by an orchestrator inherits the one it is given.

`causation_receipt_id` names the single receipt this one directly followed.
Together they describe a tree, and that tree is the *only* sanctioned way
to describe a multi-authority operation — reconstructible for as long as the
receipts are held, which in v1 is the emitting process unless a governed
retention sink says otherwise.

Correlation carries no transactional meaning. It does not imply a
distributed transaction, a shared lock, a two-phase commit, or a rollback
that crosses authorities. A correlated sibling failing does not oblige an
authority to undo an `applied` outcome it already emitted — and an
orchestrator that wants that behavior must implement compensation as
explicit new operations with their own receipts.

A composite orchestrator **may** emit a parent receipt whose
`domain_payload` references child receipt ids and summarizes their outcomes.
It **may not** overwrite a child receipt, restate a child's outcome as its
own, or emit a receipt on a child authority's behalf.

#### D-14.6 A change receipt is not a decision receipt

ADR-023 already owns the word *receipt* for approval:
`BlueprintDecisionReceipt` records that a proposed change **was authorized**,
before anything runs. This section's **change receipt** records what an
authority **did**, after it ran. They are different objects at different
ends of the lifecycle, and neither substitutes for the other: an approved
plan that was never applied has a decision receipt and no change receipt; a
refused apply has a change receipt and may have no decision receipt at all.
Where both exist for one operation, the change receipt sets the top-level
`decision_receipt_id` — a reference, never a copy. It is a protocol-level
relationship and therefore a closed-envelope member, not domain detail; and
it is never expressed by overloading `causation_receipt_id`, which chains
change receipts to change receipts only.

#### D-14.7 The generation binding

`ArtifactApplyResult` (D-6.4) is the **generation binding** of this
envelope, and the only binding this ADR authorizes. Its existing members are
retained verbatim and relocated under `domain_payload`; the envelope members
are added around them:

| Envelope member | Generation binding |
|---|---|
| `authority` | `waaseyaa.generation` |
| `authority_version` | `SiteInitializationService::CONTRACT_VERSION`, a new `public const int` that is the **sole** machine-readable declaration of this authority's contract version (D-14.9) |
| `operation` | the invoking entrypoint's stable name (`site.init`, `make.content_type`, `blueprint.materialize`, …) |
| `plan_digest` | D-6.3, unchanged |
| `outcome` | mapped from D-6.4: `applied`→`applied`, `no_changes`→`no_op`, `refused`→`refused`; a transaction that could neither complete nor roll back is `failed`; a recovery-only run is `recovered`. `planned` and `cancelled` emit **no receipt** — both terminate before controlled apply (D-14.4) |
| `domain_payload` | `{version: 1, project_state_digest, status, changed, errors, recovered_interrupted_transaction, cleanup_pending}` — generation detail only; `decision_receipt_id` is a top-level envelope member (D-14.3), not payload |

**v1 emits the receipt; it does not retain it.** The generation binding
constructs and returns a conformant change receipt, and stops there. This is
the protocol's v1 position, not a generation-specific shortcut: obligation 5
requires the typed outcome, and retention is delegated in full to a future
governed sink.
`.waaseyaa/generated.json` remains the sole authority for what is owned, and
the existing transaction journal remains the sole authority for recovery.
Neither is displaced, duplicated, or narrated by a second file.

An earlier draft of this section required an append-only
`.waaseyaa/receipts.jsonl`. That is withdrawn, because a durable receipt
sink is a custody problem this ADR has not solved and a half-solved one
would be the discontinuity D-14 exists to prevent:

- If a failed append leaves the apply successful, the file is best-effort
  telemetry and calling it durable evidence is false.
- If a failed append fails the apply, the engine reports failure *after* a
  committed mutation — the exact success-shaped-lie inversion, in the other
  direction.
- Reconstructing lost receipts from `.waaseyaa/generated.json` cannot
  recover `refused`, `failed`, or recovery detail: the ownership record only
  knows about outcomes that changed ownership.
- Concurrent append, truncation, `fsync`, permissions, retention, redaction
  of `domain_payload`, and Windows append semantics each become acceptance
  obligations nobody has specified.

Persistent receipt projection is therefore **a separately governed sink**,
designed as its own decision once its failure semantics are settled, and
sequenced after #2846. Until then a receipt reaches an operator through the
command's machine-readable output and reaches a caller as a return value —
which is enough for the CLI contract doctrine requires, and honest about
what is not yet durable.

#### D-14.8 No shared runtime code until a second binding exists

The envelope is specified here; it is **not** extracted into shared code.
Generation's types stay in `waaseyaa/site-contract`. A future schema binding
under #1625/#2730/#2731 conforms by satisfying D-14.1's obligations and
emitting D-14.3's envelope through **its own** planner, executor and ledger.
It may not import a generation type, and no schema operation may be routed
through `SiteInitializationService`.

Only when a second binding exists, and its shape is observed rather than
predicted, may a shared runtime home be proposed — as its own decision,
naming the package boundary the two real implementations demonstrate. One
implementation is not evidence of the right abstraction.

**Conformance checklist for a second binding**, to be satisfied in its own
issue and reviewed against this section: obligations 1–7 of D-14.1 named and
tested; a closed envelope per D-14.3 with a domain-versioned payload; the
outcome vocabulary of D-14.4 with preview emitting no receipt; correlation
and causation per D-14.5 with no cross-authority rollback implied; its own
durable record, explicitly non-overlapping with every record in D-14.2.

#### D-14.9 One declaration of `authority_version`

`authority_version` has exactly one machine-readable source per authority.
For generation that is a new `public const int
SiteInitializationService::CONTRACT_VERSION`, starting at `1`. Receipts,
tests, fixtures, and documentation **read** it; none of them restate its
value. A fixture carrying a literal version integer is a defect, not a
convenience.

Compatibility semantics: the constant is a monotonically increasing integer,
incremented when the authority's observable contract changes — its refusal
codes, its `domain_payload` shape, or its recovery semantics — and never for
an internal refactor. A reader may always parse the closed envelope of a
receipt from any `authority_version`; it may not assume `domain_payload`
compatibility across a bump without consulting that payload's own `version`.

This rule is written because the adjacent concept already violates it:
`generator_version` has no single declaration today. It is a bare literal
`1` in `SiteManifestWizard.php:114`, flows untyped through
`SiteManifest::$generatorVersion` into `GeneratedSite` and the ownership
metadata, and sits beside a per-recipe `SubscriptionRecipe::VERSION` and a
`SiteManifestSchema::CURRENT_VERSION` on a different axis entirely.
Consolidating `generator_version` is not authorized here; `authority_version`
simply does not repeat the mistake.

## Consequences

- Every future generator — human scaffold, preset, or AI-proposed blueprint
  — has exactly one place to learn atomicity, collision safety, and
  ownership from, and exactly one place to fix a bug in that safety once,
  for every generator at once.
- Incremental generation becomes expressible for the first time without
  weakening the frozen-set rule: the rule's *scope* narrows to the unit that
  was re-rendered, and its *nature* — unconditional, outside the input-digest
  guard, no override, no migration engine — is unchanged for every unit
  including the root.
- Scaffold-written files stop being invisible. Today they are unowned files
  whose deletion, substitution, and mode drift nothing detects; recorded as
  unit rows, all three are detected, and a second scaffold claiming the same
  path becomes a coded refusal instead of a silent overwrite.
- `make:content-type`'s `composer.json` race is closed for every future
  generator that registers a provider, not patched once for this one command
  and left as a pattern the next handler copies.
- Splitting the plan from its evaluation makes "dry-run and apply ran
  different checks" a shape error rather than a discipline problem, and
  gives review a stable handle — the plan digest — that does not change
  because the project underneath it did.

Costs and one-way doors, recorded here rather than discovered later:

- **Retirement is a new journal verb.** Every existing journal item is a
  rename-into-place with `existed`/`backup`/`installed_sha256` semantics;
  there is no remove operation, and `rollback()` restores or unlinks but
  never re-creates a deliberately deleted file. Unit retirement needs a new
  item kind, a new rollback branch, and a new interaction with the
  reverse-order created-directory cleanup. The existing failure-injection
  matrix covers none of it, so #2846's recovery suite grows by a whole axis.
- **`site:doctor` loses its reproducibility oracle for non-root units.** It
  proves a `managed` non-root row is unmodified; it cannot prove the row is
  what its recorded generator would produce again, and for a `seeded` row it
  proves nothing about content by design. Persisting a unit's canonical
  input would restore the oracle and constrains every future `make:*`
  migration; that is each migration's decision, not this one's.
- **Disposition is a permanent axis, and a mis-set one fails silently** — a
  `managed` artifact wrongly recorded as `seeded` simply stops being
  protected, with no error. The closed `seeded` allowlist and doctor's
  per-row disposition output are the two controls; both are required, not
  optional.
- **`set_evolution` is a reviewed property, not a capability flag.** A
  compiler that could set `additive` for itself would have re-invented
  `--force` with extra steps: the refusal that protects an application's
  files would become opt-out by the very code being protected against. The
  closed eligibility list and its architecture test are the control, and
  they are required, not optional — exactly as for the `seeded` allowlist.
- **Unit ids are published surface from their first release.** A key
  derivation changed later orphans the old unit while the new one collides
  with it, and the collision is permanent until one is retired. Each
  migrating PR treats its id grammar accordingly.
- **`units` growth is unbounded** where today's document is bounded by a
  fixed renderer output, and the whole canonically-encoded document is
  re-encoded and rewritten under the lock on every publish. Absolute size
  stays small, but publish cost becomes proportional to total units rather
  than to changed artifacts.
- **The older-reader refusal is a per-project one-way door** (D-2.5). It is
  the intended fail-closed polarity, but it must reach operators through
  release notes and the refusal message, not through an unexplained
  collision exception.
- **`GeneratedSite`'s constructor stops being the sole check** that the
  published document matches the published artifacts (D-2.6). The
  compensating re-derivation is enforced by a test rather than by a type,
  which is strictly weaker and is the one place the partition costs
  structural safety rather than merely adding surface.
- The `docs/adr/data/` convention this ADR introduces gives future ADRs with
  a required machine-readable deliverable a consistent, versioned home
  beside the decision that requires them, distinct from `docs/audits/`'s
  independently-triggered, SHA-pinned census artifacts.
- The `GEN0xx` family gives #2846 a fixed namespace and ten pre-assigned ids
  to implement against, rather than inventing codes ad hoc per PR the way
  `SITE0xx` grew organically before ADR-023 closed its vocabulary.

## Non-goals

Restated verbatim from #2845, and binding on every issue this ADR
authorizes:

- No implementation, command deletion, release, or generator rewrite is
  authorized by this decision alone.
- This ADR does not modify any generator, handler, or command's current
  behavior. Every `merge` disposition in D-4 is a target for a **future**
  PR, sequenced by D-12; none of that work is done here.
- This ADR does not implement #2846's transactional engine, #2787's
  blueprint compiler, or #2664's orchestration. It names the authority each
  must compose and forbids each from re-implementing it (D-13); the
  implementation itself remains each issue's own, separately reviewed work.
- This ADR does not define an approval/decision-receipt mechanism beyond
  what ADR-023 already specifies for blueprints — that remains owned by the
  higher layer (AI system, human review, forge adapter) ADR-023 already
  names. D-14's **change receipt** is a different object at the other end of
  the lifecycle (D-14.6) and does not extend, replace, or reinterpret
  ADR-023's decision receipt.
- This ADR does not authorize a schema-migration binding of the D-14
  protocol, a shared runtime implementation of it, or a new package to hold
  one. D-14.8 fixes both the conformance obligations a second binding must
  satisfy and the rule that no shared code is extracted until a second
  binding exists.
- This ADR does not fix the pre-existing refusal on manifest-driven artifact
  set *shrinkage* described in D-2.8 — additive growth is authorized by
  D-2.3a, removal is not — and does not adopt already-written, unowned
  scaffold output into the unit model retroactively (D-10.3).
- This ADR does not retire, rename, or set a removal date for any `keep` or
  `merge` command. Deprecation windows and removal dates, where they prove
  necessary, are each `merge` command's own D-12 migration PR's decision to
  propose, not a blanket schedule fixed here.
