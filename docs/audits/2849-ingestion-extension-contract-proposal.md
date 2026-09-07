# #2849 — proposed minimal public ingestion extension contract

A proposal for review. It authorizes no implementation, changes no behaviour,
and commits no surface. Evidence re-derived at `8747683ea`.

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

Proposed resolution, in order of preference:

1. **Commit them `@api`.** The envelope format is *already* an external
   contract — `CLAUDE.md` states the framework "defines the ingestion envelope
   contract that external tools (Python harvesters) must follow". Each is a
   `final class` with a single public method, so the committed surface is tiny.
   `Envelope`, `InvalidEnvelopeException` and `IngestionLogger` already carry
   `@api`; `IngestionErrorCode` is already declared `public`. Committing three
   more closes a documented contract that is presently unowned.
2. If (1) is refused, mark them `@internal` and have `EnvelopeSource` own its
   own validation. That is worse — it forks envelope semantics — but it is at
   least a decision.

**`Waaseyaa\Ingestion\EnvelopeValidator` and `PayloadValidatorInterface` stay
`internal`.** They have zero production callers and nothing extends them, so
the cost of keeping them closed is zero. The one required change is deleting
the sentence "Applications extend this to provide their supported versions,
entity types, and entity-specific validation rules" from
`packages/ingestion/src/EnvelopeValidator.php`, which contradicts the package's
own declared disposition. The contradiction is resolved by **narrowing**, not
by widening.

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
