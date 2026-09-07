# FW-CONSUMER-TEST-SUPPORT-01 — packaged reachability for public conformance helpers

- Forge mirror: `waaseyaa/framework#2961`
- Backlog entry: `docs/audits/cleanup-backlog.md` → CL-19
- Status: **design for decision — no mapping changed, no package created**
- Authority: this record proposes; it does not authorize a mapping change,
  a new package, a publication change, or any scanner change.

## 1. Problem

Composer honours `autoload-dev` for the **root package only**. A package's own
`autoload-dev` never activates when that package is installed as a dependency,
`require-dev` included. Three classes declared `'disposition' => 'public'` are published behind
such mappings and are therefore unreachable in a clean dependency consumer
without a custom root-level mapping:

| FQCN | Package | Mapped by |
|---|---|---|
| `Waaseyaa\Migration\Testing\DestinationConformanceTestCase` | `waaseyaa/migration` | its own `autoload-dev` |
| `Waaseyaa\Migration\Testing\SourceConformanceTestCase` | `waaseyaa/migration` | its own `autoload-dev` |
| `Waaseyaa\Entity\Testing\Translation\TranslatableEntityContractTest` | `waaseyaa/entity` | its own `autoload-dev` |

Reproduced from a clean consumer (CL-19). The declared contracts are correct
and are preserved; the defect is that the packaged form does not deliver them.

## 2. Scope

**In:** the three helpers above.
**Out, tracked separately:** `Waaseyaa\CLI\Io\StdinSource` (repaired under
#2961 lane 1); the package-internal helpers `CLI\Testing\CliTester` and
`EntityStorage\Testing\Contract\*` (in no `public-surface.php`); the
independent review of `PackageManifestCompiler`'s discovery skips; the
CLAUDE.md orchestration-table drift.

## 3. Constraints, with evidence

1. **Layers.** `testing` L1, `entity` L1, `migration` L3.
   `Migration\Testing\*` imports `Waaseyaa\Migration\{Exception,Plugin,SourceId}`;
   `Entity\Testing\Translation\*` imports `Waaseyaa\Entity\{ContentEntityInterface,
   Exception,Storage,TranslatableInterface}`.
2. **Rule scope.** PL005 (upward file imports) scopes to `<pkg>/src/`, so a
   `testing/` directory is outside it. PL010 governs `tests/**` and `testing/**`
   and explicitly permits upward edges when declared in `require` **or**
   `require-dev`.
3. **Precedent.** `waaseyaa/testing` maps `Waaseyaa\Testing\ => src/` under
   **production `autoload`** and ships `WaaseyaaTestCase extends TestCase`. Its
   dev-scoping comes from the **consumer's** `require-dev`, not from
   package-side `autoload-dev`. This is the mechanism that actually works.
4. **Discovery.** `PackageManifestCompiler` walks production PSR-4 roots, and
   since `181a699ae` skips `Tests\` namespaces and directories containing
   `/testing/`. This record treats that as **observed current behaviour to be
   verified by proof**, never as a licence to rely on or extend it.

## 4. Options

### Option E — flip each package's own mapping from `autoload-dev` to `autoload`

`packages/entity/composer.json` and `packages/migration/composer.json` move
their `Waaseyaa\<Pkg>\Testing\ => testing/` entries into `autoload`. Files do
not move.

- **FQCNs:** unchanged. **Namespace ownership:** unchanged — each package keeps
  its own `Waaseyaa\<Pkg>\Testing\`.
- **Consumer install:** the consumer already requires the package in
  production; the helper arrives with it. The consumer supplies
  `phpunit/phpunit ^13` in its **own** `require-dev`.
- **Dependency requirements:** none added. PHPUnit stays in each package's
  `require-dev` (root-only, therefore consumer-supplied in practice).
- **Packaging shape:** no new package, `split.yml` target, metapackage
  decision, or `bin/sync-internal-versions` sweep. The existing packages'
  production PSR-4 autoload roots do expand.
- **Cost:** one line per package.
- **Residual risk:** a `TestCase` subclass becomes PSR-4-reachable inside a
  `--no-dev` install of a **production** dependency. The §6 proof must establish
  that supported kernel discovery does not reflect or load it.

### Option A — dedicated test-support packages

`waaseyaa/migration-test-support` and `waaseyaa/entity-test-support`, each
mapping the **existing** namespace (`Waaseyaa\Migration\Testing\`, …) under its
own production `autoload`; consumers install them under `require-dev`.

- **FQCNs:** unchanged — a PSR-4 prefix is independent of the package that maps it.
- **Namespace ownership:** moves to the support package; must be recorded in
  `docs/specs/public-surface-declarations.md`.
- **Dependency requirements:** each requires its subject package (same layer or
  downward — no violation) and declares `phpunit/phpunit` in `require`, which is
  honest because the package is only ever installed under a consumer's `require-dev`.
- **Publication changes:** two new `split.yml` targets, Packagist registration,
  CP007 path-repo correspondence, CP-NEW internal-constraint equality, layer-table
  entries, and explicit exclusion from `core`/`cms`/`full`.
- **Benefit:** the package is **absent entirely** from a `--no-dev` tree, so
  reachability is safe independent of constraint 3.4.

### Option B — entity-only placement in `waaseyaa/testing` (the simpler comparison)

Feasible **only** for the entity helper: `waaseyaa/testing` is L1 and already
requires `waaseyaa/entity`, so no layer or cycle problem arises. It is
infeasible for `Migration\Testing\*` (L1→L3) and for anything in `cli` (L1→L6).

Assessed and **not recommended as the uniform answer.** Unlike Option E,
this placement keeps the entity helper absent from a `--no-dev` consumer when
`waaseyaa/testing` is required only under that consumer's `require-dev`; that is
a real isolation benefit. It also: (a) requires explicit ownership for a
`Waaseyaa\Entity\` prefix mapped by another package; (b) separates the contract
test from the package defining the contract; and (c) applies to only one of the
three helpers, leaving two packaging mechanisms. The recommendation below
rejects that mixed strategy for ownership and maintenance cost, not for lack of
technical benefit.

## 5. Recommendation

**Adopt Option E for both packages only if the acceptance evidence in §6
passes.** It is the smallest uniform mechanism. Option B offers stronger
`--no-dev` isolation for the entity helper, but introduces a mixed ownership and
packaging model; prefer it only if that isolation is chosen as a product
requirement. Do **not** create dedicated test-support packages unless the
escalation conditions below apply.

**Escalate to Option A** if either holds: the `--no-dev` boot proof fails; or
policy decides that a PHPUnit-extending class must never be PSR-4-reachable in a
production dependency regardless of whether anything loads it. That is a
governance call, not a technical one, and it belongs with the maintainer.

## 6. Acceptance evidence — required before any mapping changes

From a consumer built from the exact candidate with path repositories at
`symlink=false` and **no root-level namespace mapping**:

1. **A downstream conformance case that really passes** — a conforming
   implementation of `SourcePluginInterface` / `DestinationPluginInterface` /
   `TranslatableInterface` drives the shipped conformance case green.
2. **A downstream conformance case that deliberately fails** — a knowingly
   non-conforming implementation drives the *same* case red with the expected
   assertion message. Without this the harness is only proven to load, not to
   have teeth.
3. **`--no-dev` evidence** — `composer install --no-dev`, then a real kernel
   boot, plus an assertion that no PHPUnit-extending class is reflected during
   discovery.

`tests/PackagedForm/check-cli-io-consumer-contract` (#2961 lane 1) is the
working shape to extend; extend by adding a sibling script, not by editing
existing packaged-harness files, until file ownership is reconciled.

## 7. Migration guidance

The intended FQCNs and helper APIs remain unchanged. Compatibility is
established only for the supported clean-consumer installation exercised by §6;
custom root mappings, authoritative classmaps, preload lists, or other autoload
precedence choices may observe duplicate-definition or resolution changes and
are not covered by that proof. Consumers wanting the conformance harnesses add
`phpunit/phpunit ^13` to their own `require-dev` — the framework packages cannot
supply it for them, for the same root-only reason that caused this defect. Under
Option A they additionally add the support package to `require-dev`.

## 8. Scanner — deliberately separate, no change proposed

An earlier draft called the compiler's lowercase-`testing` path matching a
defect. **Withdrawn.** No supported configuration currently exhibits an
observable failure from it: nothing maps a `tests/Testing/`-shaped path under
production `autoload` today, so the asymmetry is unreachable. It is recorded as
an **untested asymmetry**, conditional on a future design mapping such a path —
and the cheap answer there is to keep helper files under a lowercase `testing/`
directory, which both Option E and Option A already do. **No scanner change is
proposed by this record**, and the independent review of `181a699ae` remains a
separate piece of work.

## 9. Residual work

- `docs/specs/public-surface-declarations.md` §"root that a consumer is expected
  to honour" cites `Waaseyaa\CLI\Io\StdinSource` at `packages/cli/tests/Io/…` as
  its worked example; lane 1 moved that file. The edit is **deferred** —
  `codex/2901-integration` carries 9 unmerged commits adding 219 lines to that
  file and ownership is unreconciled.
- `CLI\Testing\CliTester`, `EntityStorage\Testing\Contract\*` — internal, unfixed.
- CLAUDE.md orchestration table lists `packages/cli/src/CommandDefinition.php`
  and `src/CliKernel.php` (removed by the Symfony Console migration).
