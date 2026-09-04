> Superseded implementation proposal: the 2026-09-04 review below rejects the live-path changes proposed here. See `docs/change-records/FW-GENERATION-UNITS-05.md` for the execution contract.

# #2846 slice 5 — the generation-unit model and the `site:doctor` split

- **Date:** 2026-09-03
- **Anchor:** #2846, ADR-025 D-12.1 slice 5 of 8, non-activating
- **Status:** design only. No code is authorized by this document; it exists so
  the slice is not designed while it is being written.
- **Predecessors:** slice 1 (`b00320257`, byte-identity fixture +
  `GenerationAuthorityConstraintsTest`), slice 2 (`f251f88fb`, the pure D-6
  value types), slice 3 (the `GEN0xx` coded family), slice 4 (evaluation half,
  apply half, change receipt).

## Why this slice is the one that needs a design

Slices 2 and 3 added files and modified none. Slice 5 cannot: D-2.9 instructs
"Replacing lines 139–145 with per-unit reconciliation and gating 146–169 on the
supplied unit", and both regions are on the live `site:init` path. It is also
the only slice the ADR refuses to let anyone split — D-12.2 states the
`site:doctor` work must land with the unit model and explains at length why.

That argument is correct, and it is worth seeing the line it rests on.
`SiteDoctorService::generatedArtifactFindings()` re-renders the **root** unit
from the manifest and byte-compares the result against the whole on-disk
ownership document:

```php
$expectedMetadata = SiteArtifactRendererFactory::create()->render($manifest)
    ->artifacts['.waaseyaa/generated.json']->content;
if (!hash_equals($expectedMetadata, $metadataBytes)) {
    return [$this->finding('SITE010_GENERATED_ARTIFACT_DRIFT', ...)];
}
```

The moment any non-root unit publishes, the on-disk document carries a `units`
member and per-row `unit` keys that a manifest-only re-render cannot produce.
Doctor then reports drift against the project's own correct output — and
because every generated `bin/maintenance/site-verify` runs
`site:doctor --strict --format=json` and exits on a non-zero status, the
project's own verification script fails. That is not merely unreachable-by-
users; it is internally inconsistent, which constraint 2 forbids.

## Two findings that change the plan

Both were verified against the code, not inferred from the ADR.

### F1 — slice 1's fixture does not watch the code D-2.6 moves the bytes into

D-12.1's substitute-constraints table says the byte-identity fixture is the
oracle for the D-2.6 relocation: "the fixture is merged and green four slices
before the relocation is authored". But the test that consumes that fixture is

```php
$site = new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($this->manifest()));
$expected = file_get_contents(__DIR__ . '/../Fixtures/Generation/blueprint-free-v1.generated.json');
```

It asserts on **`SiteArtifactRenderer`'s** output. D-2.6 relocates composition
into **`SiteInitializationService`**. After the relocation the fixture still
passes while saying nothing about the bytes a project actually receives, so the
ADR's named oracle does not observe the thing it was created to police.

**Consequence for the plan:** slice 5's first commit adds the missing oracle — a
service-level test asserting the *published* `.waaseyaa/generated.json` equals
the renderer's composition for a root-only publish — and lands it green, before
any relocation. Slice 1's fixture is not weakened or edited; it guards the
renderer, and the new test guards the service. Say both sentences in the PR
body, because a reviewer who assumes slice 1 already covered this will approve
the relocation on an oracle that cannot fail.

### F2 — there is no non-blocking finding channel to put D-2.7's new finding in

D-2.7 requires "a `seeded` row whose file is present but modified is reported as
a distinct, **non-blocking** finding". The report model has no such channel:

```php
$passed = $findings === [];   // SiteDoctorReport, severity never consulted
```

`FindingSeverity::Warning` exists and is unused in production, so it looks like
the intended channel. It is not: a Warning still lands in `$findings`, `passed`
goes false, `exitCode()` returns 1, and every generated `site-verify` fails —
verbatim the outcome D-12.2 says this slice exists to prevent. There is even a
test whose name is the current decision:
`test_strict_report_is_canonical_and_never_calls_warnings_ok`.

**Consequence for the plan:** the non-blocking mechanism is a decision slice 5
must make and defend, not an implementation detail. See D2 below.

## Surgery map

| Where | Change |
|---|---|
| `SiteInitializationService::readMetadata()` | widen the known-optional top-level key set to include `units`, and per-row `unit`. `registrations` stays refused (slice 6). Canonical re-encode check and both message literals unchanged. |
| `SiteInitializationService::prepare()` 139–145 | replace the single global set comparison with per-unit reconciliation |
| `SiteInitializationService::prepare()` 146–169 | gate the managed-digest enforcement on the supplied unit |
| `SiteInitializationService` (new) | compose the ownership document (D-2.6), staged last through the existing ordering |
| `SiteInitializationService::validateJournal()` / `rollback()` | the retirement verb: delete-with-backup, reverse-order restore |
| `GeneratedSite` | composition moves out; the self-certification `hash_equals` coupling has to go somewhere |
| `SiteDoctorService::generatedArtifactFindings()` | whole-document compare becomes root-projection compare; row loop becomes disposition-aware |
| `SiteDoctorService::finding()` | hardcodes `FindingSeverity::Error`; needs a severity parameter |
| `SiteDoctorReport` | whichever non-blocking mechanism D2 selects |

## Reconciliation, as an algorithm

Condensed from D-2.3. The supplied unit in slice 5 is always the root; no plan
type reaches this method until slice 8.

```
1  READ
   1.1  no document          -> first publish; no set check, no byte check
   1.2  readMetadata()       -> widened for `units`/`unit` only
   1.3  root binding         -> unchanged, evaluated against the ROOT unit only
   1.4  no `units` member    -> PROMOTE IN MEMORY ONLY to one root unit owning every row
        `units` present      -> validate each record; `site` listed / duplicate id /
                                bad disposition / bad input_digest -> GEN010
                                id failing the D-2.1 grammar        -> GEN006
   1.5  rows                 -> duplicate path, or a row naming an unknown unit -> GEN010
   1.6  partition holds by construction: one owner per recorded path

2  CARRY FORWARD
   Units neither supplied nor retired are copied VERBATIM. No set check, no byte
   check -- "neither can, because nothing re-rendered them". This is what stops
   the next ordinary site:init from deleting a scaffold's rows.

3  COMPARE THE SUPPLIED UNIT ONLY
   recorded set vs supplied set; non-empty delta -> the existing frozen-set
   refusal, message unchanged. Slice 7 relaxes this for the eligible compiler.

4  FIRST-OWNER-WINS
   a supplied path recorded to a different unit -> GEN003

5  RETIREMENT
   a recorded row dropped with no declared retirement -> GEN009
   a declared retirement -> delete with backup, journalled, rollback-covered
   retiring `site` -> refused (see D1)

6  COMPOSE + PUBLISH
   metadata staged last, through the ordering already forced today
```

**The byte-identity rule that governs all of it:** a project owning no non-root
unit must produce a document byte-identical to one produced before these members
existed. That means conditional emission — a root-only publish emits **no**
`units` member and **no** row `unit` key, not an empty list. `CanonicalJson`
`ksort`s object keys, so `units` sorts between `schema` and `version` and `unit`
sorts last within a row without moving any existing member's bytes.

## Decisions the ADR leaves open

Eight silences. Each needs a stated reading in the PR body, or a reviewer will
read the choice as an accident.

**D1 — the `site` reservation has no assigned code.** `site` *passes* the D-2.1
grammar; it is the reservation it violates, and D-5 assigns no id. D-11 attributes
"a unit id crafted to shadow `site`" to `GEN006`. Inventing `GEN016` is not
available ("any additional id is an amendment to this ADR"). *Reading:* `GEN006`
for a non-root plan claiming `site`; `GEN010` for a `units` roster that records
`site` and for "retire `site`", since both are claims about the unit-and-path
model. Slice 2 explicitly deferred all three here.

**D2 — the non-blocking finding (F2).** Three options: make `passed`
severity-aware (changes observable behaviour for any Warning-bearing report and
requires rewriting a test whose name is the current decision); add a third
bucket to the report (a schema change to the closed `waaseyaa.site-doctor` v1
document in a non-activating slice); or defer the seeded finding to slice 8
(cheapest, but then slice 5 does not carry the split D-12.1 says it must).
*Reading:* severity-aware `passed`, with `SITE013_SEEDED_ARTIFACT_MODIFIED` as
the id, and the rewritten test presented as a correction with its reasoning.
Keeping the v1 document shape closed is worth more than the test's current name.

**D3 — the closed `seeded` allowlist test has no slice.** D-2.2 and the
Consequences both call it required; D-12.1 assigns only slice 7's
`set_evolution` twin. *Reading:* land it in slice 5 with an **empty** list —
under constraint 1 no compiler exists yet — phrased so adding the first entry is
a visible reviewed diff. An empty allowlist enforced from day one is stronger
than one introduced beside its first member.

**D4 — spec edits.** The drift detector maps `packages/cli/` →
`docs/specs/cli-kernel.md` and `packages/site-contract/` →
`docs/specs/site-golden-path.md`; slice 5 touches both. *Reading:* two
`spec-reviewed:` trailers, no spec edit, following slice 2's precedent — the
behaviour is unreachable until activation and a spec documents shipped
behaviour. Write the reason **without commas**; the detector splits the payload
on `,` and a prose comma produces a bogus token warning.

**D5 — `registrations` is slice 6.** Widen `readMetadata()` for `units`/`unit`
only. Between slices 5 and 6 a `registrations`-bearing document is refused with
the existing unsupported-shape message, which is correct fail-closed polarity.
Add a slice-5 test asserting that refusal, so slice 6 opens on a red-to-green
transition and a reviewer does not read the omission as an oversight.

**D6 — `setDelta` is computed here but coded in slice 7.** Slice 5 is the first
slice to compute a recorded-vs-supplied path-set comparison, which is exactly
what `setDelta` surfaces. *Reading:* populate it from slice 5 and refuse every
non-empty delta; slice 7 then only relaxes the refusal for the eligible compiler
and attaches `GEN011`. The alternative reproduces the framework's own recorded
dual-state bug pattern — two sources for one fact.

**D7 — what "unreachable" means here.** Constraint 1 was easy for slices 2–4,
which modified no existing file. It cannot hold literally for slice 5: every
user who runs `site:init` executes the new reconciliation. The only coherent
reading is that constraint 1 is about reachable *behaviour*, not reachable
*code* — for every document a user can possess (none has a `units` member,
because nothing writes one) the new path is the identity function over the old.
*State this explicitly with the qualifier.* A reviewer who checks will find
`prepare()` on the live path, and an unqualified "unreachable" claim will cost
the rest of the PR its credibility.

**D8 — the composed document's own artifact identity.** Today the metadata
document is a `GeneratedArtifact`, inheriting path safety, the mode vocabulary,
the non-empty rule, the publish-last ordering, and the collision branch that
refuses to overwrite a *directory* of that name. *Reading:* wrap the composed
bytes in a `GeneratedArtifact` and feed them through the existing
`prepare()`/`publish()` machinery at the same position, so every guard is
retained by construction rather than re-implemented. The relocation changes who
produces the bytes, not how they are admitted.

**Cross-cutting requirement:** every GEN exception must extend
`\RuntimeException`. `SiteInitHandler` catches
`SiteInitializationCollisionException|SiteInitializationLockedException|\InvalidArgumentException|\RuntimeException`
and returns 2; anything outside that hierarchy turns a coded refusal into an
uncaught fatal, with no visible symptom until someone corrupts a metadata file.
Verify against what slice 3 actually landed. Note also that
`SiteInitializationCollisionException` is `final` and lives in Layer 6, so the
Layer 0 GEN family cannot extend it — reachable-today branches should keep the
existing exception and message, and only branches unreachable before slice 8
should throw GEN types.

## Commit sequence

D-12.2 forbids separate PRs. This is a commit order inside the one branch, each
opened by a red test, with the tree green at every tip.

1. **The missing oracle (F1).** Service-level byte-identity test for a root-only
   publish. Lands green as the control everything else is measured against.
2. **Read-side widening only.** `units`/`unit` accepted and promoted in memory.
   Nothing writes them. Commit 1 must still pass — that is the proof.
3. **Unit roster and path→unit index.** The `GEN010`/`GEN006` read refusals.
   `prepare()` still uses the old comparison.
4. **Per-unit reconciliation** replacing 139–145. Carry-forward, per-unit frozen
   set, `GEN003` cross-unit claim. Commit 1 must still pass: for a root-only
   project reconciliation is the identity.
5. **The D-2.6 relocation.** The commit commit 1 exists for. Conditional
   emission of `units`/`unit`. Run commit 1's test explicitly; do not assume it.
6. **The retirement journal verb**, with the failure-injection matrix: interrupt
   before-remove, after-remove, and during the rollback re-create.
7. **Doctor's root-projection compare.** The commit that makes D-12.2 true.
8. **Doctor's disposition-aware row loop** and the D2 non-blocking channel.
9. **Governance:** changelog fragment, both `spec-reviewed:` trailers,
   `bin/check-pr-preflight --full`, three suites run separately.

Commit 1 before commit 5 is the ADR's own ordering constraint, reproduced at
commit granularity because slice 1's fixture does not reach the relocated code.

## Failure modes and what catches them

| # | Failure | Caught by |
|---|---|---|
| FM-1 | Relocating composition trusting slice 1's fixture as the oracle | Nothing today — commit 1 exists to fix this |
| FM-2 | Emitting the seeded finding as a `Warning`, turning `site:doctor --strict` red and failing every generated `site-verify` | Doctor handler tests; this is the D-12.2 failure itself |
| FM-3 | Taking D-2.5's instruction to improve a refusal message — constraint 2 forbids it in a non-activating slice | Message-freeze diff review |
| FM-4 | Over-widening `readMetadata()` to accept `registrations` while writing the same `if` | D5's explicit refusal test |
| FM-5 | Stripping the renderer's composition when D-2.6 leaves the renderer untouched | Slice 1's fixture — say so in the PR, it is the one FM already covered |
| FM-6 | A new exception escaping `SiteInitHandler`'s catch, changing exit 2 to a fatal | Nothing — assert the hierarchy explicitly |
| FM-7 | Building the retirement verb with no failure-injection axis | Only the tests commit 6 adds |
| FM-8 | Writing the unit-model proofs as Architecture tests, which record no coverage and fail the 80% changed-lines gate | `ci/coverage` — use `#[CoversClass]` companions |

The one byte-change vector no existing test catches is an **empty `units`
member** emitted on a root-only publish. Commit 1's test is what closes it.

## PR evidence checklist

A reviewer should not have to re-derive the ADR.

1. Commit 1's passing output, plus the sentence distinguishing what slice 1's
   fixture guards from what the new test guards, and confirmation that the
   fixture file is unmodified in the diff.
2. A named test proving a root-only publish emits no `units` member and no row
   `unit` key — not an empty list.
3. `git diff origin/main -- packages/cli/src/Site/ | grep '^[-+].*throw new'`
   showing zero net string changes, or every change enumerated. Note the D-2.5
   message improvement as deliberately deferred.
4. The exception-hierarchy statement for exit-status preservation.
5. The constraint-1 claim **with D7's qualifier**, and its test.
6. The commit-7 red-then-green proof that doctor stays quiet on a multi-unit
   document.
7. Which D2 option was taken, and why the rewritten report test is a correction.
8. The chosen `SITE0xx` number with evidence of no collision.
9. The retirement recovery matrix: each injected fault stage, and the project
   state after recovery.
10. That the composed document is now derived in two places, and the test that
    enforces their agreement — the ADR calls this "the one place the partition
    costs structural safety" and a reviewer must see it acknowledged.
11. `bin/check-pr-preflight --full` plus Unit, Integration and Architecture run
    separately, on the exact pushed head.
