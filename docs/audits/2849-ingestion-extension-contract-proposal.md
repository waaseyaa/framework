# #2849 — proposed minimal public ingestion extension contract

A proposal for review. It authorizes no implementation, changes no behaviour,
and commits no surface. Evidence re-derived at `8747683ea`.

Consolidation of the existing ingestion implementations, the stale
specifications and the unresolved surface declarations is tracked in
**#2984**. This document is that issue's evidence and reasoning; #2849's
ingestion clause remains open and is claimed by neither.

## The finding that shapes everything

**Do not design a new plugin system. One already ships, stable and public.**

`waaseyaa/migration` is `Status: Stable (M-002 landed, 2026-05-13)`
(`docs/specs/migration-platform.md:11`) and declares 31 `public` entries in
`packages/migration/public-surface.php`, including every contract an ingestion
adapter needs: `SourcePluginInterface`
(`packages/migration/src/Plugin/SourcePluginInterface.php:22`),
`ProcessPluginInterface`, `DestinationPluginInterface`, `MigrationDefinition`,
`EntityDestination`, `SourceId`, `SourceRecord`, `DestinationRecord`,
`WriteResult`, plus the discovery capability `HasMigrationsInterface`
(`packages/migration/src/Discovery/HasMigrationsInterface.php`) and the public
conformance bases `SourceConformanceTestCase` /
`DestinationConformanceTestCase` (`packages/migration/testing/`).

### The ADR grounding was stale, and this corrects it

ADR-012 is **superseded**: `docs/adr/012-migration-platform-out-of-scope.md:3`
reads "Superseded (2026-05-11) by ADR 012a", and its own supersession note says
"This ADR's verdict (migration out of scope) was **reversed**". ADR-012a
(`docs/adr/012a-migration-substrate-in-core.md:34-36`) decides the opposite:
"The migration platform is **in scope** … The framework ships the
**substrate**; source readers ship as **packages**."

Any argument that ingestion is settled as "app-shaped, not framework-shaped"
rests on a superseded document. ADR-012a does **not** revisit that sentence,
so it is not formally overturned for the *envelope* pipeline — but it can no
longer be cited as the framework's position on foreign-data-to-entity work in
general. Earlier notes in this program that leaned on ADR-012 should be read
with that correction.

## What is actually broken today

Four disconnected implementations, verified by search:

| Implementation | State |
|---|---|
| `ingest:run` CLI (`packages/cli/src/Ingestion/*`) | Live, but never persists an entity — it emits JSON (`docs/specs/ingestion-defaults.md:65`). Mapping is a private method, `IngestRunHandler::mapRecords()`, not an extension point. No `@api`/`@internal` markers anywhere in the package. |
| `packages/foundation/src/Ingestion/*` (`Envelope`, `MessageEnvelopeValidator`, `PayloadValidator`) | **Production-orphaned.** `rg` over `packages/ public/ config/ bin/` excluding tests and its own directory returns **zero** references. `docs/specs/ingestion-defaults.md:19-32` documents this pipeline as though it were live. |
| `packages/ingestion/` (`EnvelopeValidator`, `PayloadValidatorInterface`) | Declared `internal`; **zero production callers**, and nothing extends it. Its docblock nevertheless says "Applications extend this". |
| `packages/note/src/Ingestion/NoteIngester` | The only working envelope-to-entity path, content-type-local, with its own private envelope DTO. |

`docs/specs/app-level-ingestion.md` — the documentation deliverable ADR-012's
own acceptance required — **does not exist**.

## Proposed contract

### Nothing new is invented. One reader package is added.

**`waaseyaa-migrate-source-envelope`** — a first-party source-reader package
that reads the framework's own ingestion envelope format, implementing the
already-public `SourcePluginInterface`. This is precisely the shape ADR-012a
prescribes (`012a:101-108`, the `waaseyaa-migrate-source-<system>` convention).

```php
namespace Waaseyaa\MigrateSource\Envelope;

use Waaseyaa\Migration\Plugin\SourcePluginInterface;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\SourceId;

/**
 * Reads validated Waaseyaa ingestion envelopes as migration source records.
 *
 * @api
 */
final class EnvelopeSource implements SourcePluginInterface
{
    /** @param non-empty-string $path file or directory of envelope documents */
    public function __construct(string $path, string $expectedType);

    public function id(): string;            // 'waaseyaa_envelope'
    public function stability(): string;     // 'stable'

    /** @return iterable<SourceRecord> one record per validated envelope */
    public function records(): iterable;

    /** Derived from the envelope's own identity — never from file order. */
    public function sourceIdFor(SourceRecord $record): SourceId;

    public function count(): ?int;
}
```

That is **the entire new public API**: one class, on an existing interface.

- **0** new interfaces — `SourcePluginInterface`, `ProcessPluginInterface`,
  `DestinationPluginInterface` already exist and are public.
- **0** new discovery mechanisms — `HasMigrationsInterface` is `@api`, and a
  filesystem manifest fallback already exists.
- **0** CLI registration changes — `import:run`, `import:status`,
  `import:rollback`, `import:resume`, `import:reset`, `import:run-all` already
  ship (`packages/cli/src/Provider/ImportServiceProvider.php:165-286`).
- **0** internal APIs made public.

### Layer constraint (why it cannot live in `packages/ingestion`)

`bin/check-package-layers` assigns `ingestion => 0` (line 85) and
`migration => 3` (line 119). A source plugin implements an L3 interface, so it
cannot live in an L0 package without an upward import that PL005 rejects. The
reader therefore ships as its own package, which is also what ADR-012a wants.

### The contract resolution this requires (and it is not "make internals public")

Three foundation types are **unmarked** — carrying neither `@api` nor
`@internal`, and absent from `packages/foundation/public-surface.php`:
`MessageEnvelopeValidator`, `PayloadValidator`, `IngestionError`. They are the
envelope validation entry points (`MessageEnvelopeValidator::validate(array):
Envelope`, `PayloadValidator::validate(Envelope): array`). Unmarked is the
worst state: not committed, not disclaimed.

**The envelope wire format being public does not make these classes public.**
An earlier draft of this proposal argued it did. That was a category error and
is withdrawn. `CLAUDE.md`'s statement that the framework "defines the ingestion
envelope contract that external tools (Python harvesters) must follow" commits
a **JSON document shape** consumed across a process boundary by non-PHP
programs. `MessageEnvelopeValidator::validate(array): Envelope` is a **PHP
class contract** — constructor shape, method signature, exception type, return
type. The two can vary independently: the wire format could stay frozen for
years while the validating class is replaced, split or re-namespaced, and a
consumer pinned to the class would break while every harvester kept working.
Committing the class because the format is public would take on a semver
obligation nobody asked for, on a class with zero first-party callers.

Proposed resolution, and the choice is genuinely open:

1. **Mark them `@internal` and declare them so.** They are the implementation
   of a wire contract, not the contract. This is the default answer unless
   someone identifies a consumer that needs to call them in PHP.
2. **Commit them `@api`** only if a named consumer requirement exists — an
   application that must validate an envelope in PHP *before* handing it to a
   source plugin, and cannot do so through a higher-level seam. That
   requirement has not been demonstrated; if it is, (2) becomes right.

Either way the outcome is a **decision recorded in
`packages/foundation/public-surface.php`**. The defect is that they are
currently neither, which leaves consumers to guess and leaves the framework
unable to change them safely.

**`Waaseyaa\Ingestion\EnvelopeValidator` and `PayloadValidatorInterface` stay
`internal`, and stay *present*.** An earlier draft reasoned that zero
production callers made them free to remove. That reasoning is withdrawn:
`packages/ingestion` is split-mirrored to its own repository
(`.github/workflows/split.yml:53`) and published as `waaseyaa/ingestion`, so
"no callers" is evidence about **this repository only**. Any Packagist
consumer may have taken the class up — and its docblock actively invited them
to ("Applications extend this…"), which makes an extending consumer the
*expected* case rather than a hypothetical one, notwithstanding the
`@internal` marker they may never have read.

The one change with no compatibility cost is **deleting the invitation**: the
sentence "Applications extend this to provide their supported versions, entity
types, and entity-specific validation rules" contradicts the package's own
declared disposition and should go, so the contradiction stops recruiting new
extenders. Removing the *classes* is a separate, later decision requiring a
deprecation window and release-note treatment (see Compatibility below), not a
cleanup.

## Application example

An application ingesting harvester envelopes writes no plugin at all for the
simple case — it declares a migration:

```php
namespace App\Provider;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\MigrateSource\Envelope\EnvelopeSource;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\Process\HtmlSanitizeProcessor;

final class TeachingIngestServiceProvider extends ServiceProvider implements HasMigrationsInterface
{
    public function register(): void {}

    public function migrations(): iterable
    {
        yield new MigrationDefinition(
            id: 'envelopes_to_teachings',
            source: new EnvelopeSource(
                path: 'storage/import/envelopes',
                expectedType: 'teaching.v1',
            ),
            process: [
                'title' => 'payload.title',
                'body'  => new HtmlSanitizeProcessor('payload.body'),
            ],
            destination: EntityDestination::forEntityType('teaching'),
        );
    }
}
```

Registered the ordinary way, in `composer.json` `extra.waaseyaa.providers`.
Then `waaseyaa import:run envelopes_to_teachings`. Re-running is idempotent
because `migration_id_map` keys on the deterministic `SourceId`; failures
resume with `import:resume`; mistakes undo with `import:rollback`.

An application needing custom mapping writes a `ProcessPluginInterface`
(public) or its own `SourcePluginInterface` (public). Neither requires
framework change.

## Discriminating acceptance tests

Each is written to fail for one specific defect, not to pass trivially.

1. **Conformance — `EnvelopeSourceConformanceTest extends SourceConformanceTestCase`.**
   Uses the framework's own public base (`packages/migration/testing/SourceConformanceTestCase.php:56`).
   *Discriminates:* a `sourceIdFor()` that folds in file order, mtime or array
   position fails the stable-ID case; a non-resumable reader fails resume.

2. **Idempotency under re-run.** Run `import:run` twice over an unchanged
   envelope directory. Assert entity count and `migration_id_map` row count are
   identical after both runs.
   *Discriminates:* an ID derived from anything but envelope identity
   double-imports and fails on the second count. A test asserting only "run
   twice without error" would not.

3. **Envelope refusal is coded, and imports nothing.** Feed an envelope missing
   `trace_id`. Assert the failure carries the specific `IngestionErrorCode`
   case and that zero entities and zero id-map rows were written.
   *Discriminates:* a reader that catches validation and yields a
   partially-populated record still imports something, and fails the zero-write
   half even if it reports an error.

4. **Authorization is not bypassed.** Run a migration whose destination entity
   type a policy forbids for the run's account. Assert the write is refused and
   no entity is persisted. `EntityDestination` takes a `GateInterface`
   (`packages/migration/src/Plugin/Destination/EntityDestination.php:164`), so
   this is a real path, and #2849's boundary forbids authorization bypass.
   *Discriminates:* a reader that persists through `EntityRepository` directly,
   bypassing the destination, passes every other test here and fails this one.

5. **The internal seam stays shut (architecture test).** Assert no file under
   `packages/*/src` outside `packages/ingestion/src/` references
   `EnvelopeValidator` or `PayloadValidatorInterface`, and that
   `EnvelopeValidator`'s docblock contains no "Applications extend" claim.
   *Discriminates:* fails the moment someone re-opens the internal seam or
   restores the contradiction.

6. **Layer boundary.** `bin/check-package-layers` must stay green with the new
   package declared at its own layer, and the reader package must not appear in
   the production require closure of `core`/`cms`/`full` unless deliberately
   added.
   *Discriminates:* fails if the reader is placed in `packages/ingestion` (L0)
   and reaches upward into `Waaseyaa\Migration\*` (L3).

7. **Packaged consumer.** A `tests/PackagedForm/` harness that installs the
   reader into a disposable consumer, declares the migration through a
   provider, runs `import:run`, and asserts the entity exists and the id-map
   row is present.
   *Discriminates:* fails if discovery works only in the monorepo — the class
   of defect #2857 already found once for recipe providers.

## ADR-012a verified against current code

Checked before relying on it, at `8747683ea`. The substrate is real, with one
documented drift.

| ADR-012a claim | Code | Verdict |
|---|---|---|
| `SourcePluginInterface` stable | `packages/migration/src/Plugin/SourcePluginInterface.php:22`, `@api`, declared `public` | **holds** |
| `ProcessPluginInterface`, `DestinationPluginInterface` stable | `packages/migration/src/Plugin/`, both declared `public` | **holds** |
| `MigrationDefinition` manifest with source/process/destination/dependencies | `packages/migration/src/MigrationDefinition.php:68-81` — carries all four plus `description`, `memoryBudgetBytes`, `errorRateWarn/Halt`, `bundle`, `fieldReads`, advisory codes | **holds, and is wider than the ADR sketch** |
| `EntityDestination` respects access policies and lifecycle events | ctor takes `GateInterface` and `EventDispatcherInterface` (`Plugin/Destination/EntityDestination.php:164-165`) | **holds** |
| `migration_id_map` idempotency primitive | `MigrationIdMap`, `Schema/MigrationIdMapSchema`, both declared `public` | **holds** |
| Six `import:*` CLI commands | `packages/cli/src/Provider/ImportServiceProvider.php:165-286` | **holds** |
| Conformance bases as stable surface | `packages/migration/testing/SourceConformanceTestCase.php:56`, `DestinationConformanceTestCase.php:51`, both declared `public` | **holds** |
| "`SourceIdInterface` for source records" is stable surface | **No such interface exists.** `rg -n "SourceIdInterface" packages/ --type=php` returns nothing outside tests; the shipped type is a `final readonly class SourceId` (`packages/migration/src/SourceId.php`) | **DRIFT — ADR names an interface the code never shipped** |
| Migration platform status | `docs/specs/migration-platform.md:11` — "Status: Stable (M-002 landed, 2026-05-13)" | **holds** |

The `SourceIdInterface` drift matters for this proposal only in that a source
plugin returns a concrete `SourceId`, not an interface, so third-party
substitution of identity semantics is not available. That is a fine v1 answer;
it should be corrected in ADR-012a's text rather than implemented into code.

### Where the existing mechanism is preferred — and where it is not enough

Preferred, per the instruction to reuse it: everything above. A consumer
importing foreign records into entities should use `SourcePluginInterface` +
process plugins + `EntityDestination` + `import:*`, and this proposal adds
nothing to that path.

The honest limit: ADR-012a `012a:97` states "**No incremental / continuous
sync** in v0.x. Migrations are one-shot operations." A recurring harvester feed
— which is what the `ingest:run` envelope pipeline exists for — is therefore
**outside** what the migration substrate promises today. Two consequences:

- For **one-shot backfills** of envelope data, the migration substrate already
  meets the requirement and no new contract is needed at all.
- For **recurring ingestion**, either ADR-012a's continuous-sync door is opened
  by a further decision, or recurring ingestion is served by a different seam.
  This proposal does not decide that, and #2849's ingestion clause cannot be
  closed until someone does.

## Recommended disposition per implementation

Each disposition is a recommendation for review, not an action taken.

### 1. `ingest:run` pipeline (`packages/cli/src/Ingestion/*`) — **retain, narrow, document**

The only ingestion code with live behaviour and four governing specs. It emits
JSON and never persists (`docs/specs/ingestion-defaults.md:65`), which is a
legitimate narrower purpose: validation, diagnostics, editorial review and
refresh planning ahead of any write.

- Keep it. Document that narrower purpose explicitly where the specs now imply
  a persistence pipeline.
- Its collaborators carry no `@api`/`@internal` markers; they are CLI-package
  implementation and should be declared `internal`.
- **Compatibility:** none. `waaseyaa/cli` ships them; no signature changes.

### 2. `packages/foundation/src/Ingestion/*` — **decide the markers; do not delete**

`Envelope`, `InvalidEnvelopeException`, `IngestionLogger`, `IngestionLogEntry`
carry `@api`. `MessageEnvelopeValidator`, `PayloadValidator`, `IngestionError`,
`UuidV4TraceIdGenerator` carry nothing. `IngestionErrorCode` and
`TraceIdGeneratorInterface` are declared `public`.

Two corrections to an earlier draft, both from checking package policy rather
than assuming it:

**Their absence from `public-surface.php` is not a declaration gap.** Charter
§2 is explicit that the tracked surface is contract *shapes* — interfaces,
abstract classes, traits, enums — and that "Concrete `final`/plain classes are
implementations, not extension points, and are intentionally **not** tracked by
the parity gate (audit C-16)". Every one of these is a concrete final class, so
the parity gate is behaving correctly and there is nothing for it to enforce.
The ambiguity is in the **PHPDoc tier**, not the declaration plane.

**Marking them `@internal` is not free.** Charter §2.4: "A symbol whose tier is
unclear is treated as **provisional** until a maintainer files a
public-surface-map update. The default during ambiguity favors the consumer:
maintainers must either commit to stability or downgrade to internal
explicitly." So an unmarked shipped class is *already provisional*, not
unclassified — and §4 requires that "any deliberate reshape of a provisional
one" follow the full introduce/shim/emit/document/remove cycle. Downgrading to
`internal` is such a reshape.

- Give the unmarked four a deliberate tier — recommended `@internal` unless a
  consumer requirement is named (see above) — and treat it as a **provisional
  downgrade under §4**, with a `deprecated` changelog fragment and an upgrade-
  guide entry, not as a silent annotation.
- **Do not remove anything.** `waaseyaa/foundation` is the most widely
  installed package in the graph; every metapackage requires it.
- **Compatibility:** real, and previously understated here. §2.4 makes these
  provisional by default, which is a consumer-facing position; withdrawing it
  is a downgrade that consumers are entitled to see announced. §2.4 also calls
  indefinite ambiguity "a charter violation", so leaving them unmarked is not a
  neutral option either.

### 3. `packages/ingestion` (`EnvelopeValidator`, `PayloadValidatorInterface`) — **keep, stop advertising, deprecate deliberately if at all**

- Delete the "Applications extend this…" sentence. Zero compatibility cost;
  stops the contradiction recruiting extenders.
- If removal is ever wanted, it needs the full path: a deprecation notice in
  the class, a release-note entry, a stated window, and a successor named. The
  package is published and split-mirrored; a Packagist consumer extending
  `EnvelopeValidator` is exactly what the docblock asked for.
- **Compatibility:** deletion today would be a silent break for any such
  consumer, undetectable from this repository.

### 4. `packages/note/src/Ingestion/*` — **leave alone; treat as precedent, not duplication**

`NoteIngester` is the one working envelope-to-entity path. It is content-type
local with its own DTO. It is not a competing framework contract and should not
be consolidated away as part of this work.

- **Compatibility:** none; untouched.

### 5. Specifications — **correct the drift**

`docs/specs/ingestion-defaults.md:19-32` documents the foundation pipeline as
the live one. The live one is `ingest:run`, documented by four other specs.
`docs/specs/app-level-ingestion.md`, required by ADR-012's own acceptance, was
never written; ADR-012 is superseded, so that deliverable should be formally
retired rather than left outstanding. ADR-012a's `SourceIdInterface` reference
should be corrected to `SourceId`.

## Focused acceptance criteria

For the consolidation work, not for a runtime implementation:

1. Every class under `packages/foundation/src/Ingestion/`,
   `packages/ingestion/src/` and `packages/cli/src/Ingestion/` carries exactly
   one PHPDoc tier — `@api` or `@internal` — so charter §2.4's "indefinite
   ambiguity is a charter violation" is discharged for these three trees.
   **Verified by extending the existing surface tooling, not by adding a
   scanner:** `tools/lib/SurfaceScanner.php` already walks `packages/*/src`,
   `tools/check-surface-parity.php` already gates it, and the
   `tests/Architecture/SurfaceDeclaration*` / `SurfaceParity*` suite already
   asserts over that walk. The tier check belongs there as an additional
   assertion over the existing traversal. Contract *shapes* among them must
   also appear in their package's `public-surface.php`, which the parity gate
   already enforces; concrete finals are deliberately untracked (§2, C-16) and
   must not be added to the declaration files to satisfy this criterion.
2. No first-party source or spec advertises extension of a type declared
   `internal`. Verified by an architecture test asserting
   `EnvelopeValidator`'s docblock carries no "Applications extend" claim.
3. `docs/specs/ingestion-defaults.md` describes the pipeline that actually
   runs, and names the foundation types' status. Verified by review, with the
   spec-drift detector acknowledging the change.
4. ADR-012a's `SourceIdInterface` reference is corrected, or the interface
   ships. Verified by a documentation test asserting every type ADR-012a names
   as stable surface resolves to a real declared-public symbol.
5. No class is removed from a published package in this work. Verified by
   `bin/check-surface-parity` and the release-notes discipline; any future
   removal carries a deprecation window.
6. #2849's ingestion clause remains open and is explicitly not claimed by this
   work.

## What this proposal does not do

- It does not implement anything.
- It does not decide the fate of the three disconnected pipelines. That is the
  larger question: the live `ingest:run` never persists, the foundation
  pipeline is orphaned, and `ingestion-defaults.md` documents the orphan. This
  proposal deliberately builds on the *stable, exercised* migration substrate
  instead of resurrecting an unused one — but somebody must still decide
  whether `ingest:run` converges on `import:*` or keeps a separate purpose.
- It does not satisfy #2849's ingestion clause. It proposes the contract that
  clause presupposes, which does not exist yet.
