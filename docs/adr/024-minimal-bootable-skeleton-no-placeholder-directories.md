# ADR-024 — the skeleton is minimal and bootable; optional areas are generator-created, never scaffolded empty

- **Status:** Accepted on merge of the pull request that introduces it.
- **Date:** 2026-09-02
- **Anchor issue:** #2438
- **Related:** #2442 (`site:init` profiles — not implemented by this ADR; the
  rules below constrain how that future work may persist a choice),
  ADR-023 (governed application blueprints extend `waaseyaa.site` v1 in place)

## Context

`skeleton/` — the tree `composer create-project waaseyaa/waaseyaa` copies into a
fresh application — shipped fourteen `.gitkeep` files in fourteen
directories that carried no code of their own: `src/{Access,Controller,Domain,Entity,
Ingestion,Search,Seed,Support}/`, `src/Provider/` (redundant — the directory
already holds `AppServiceProvider.php`), `storage/`, `files/`, and
`tests/{,Unit,Integration}/` (`tests/Unit/` redundant — it already holds
`Http/BootFailureResponderTest.php`).

Eleven of those directories existed only for the placeholder and disappear
entirely; three (`src/Provider/`, `tests/`, `tests/Unit/`) already held real
files and survive, losing only the redundant `.gitkeep`.

None of the fourteen placeholders were load-bearing:

- `storage/` is created by `db:init`'s own `mkdir($parent, 0o755, recursive:
  true)` the first time it writes the SQLite file
  (`packages/cli/src/Handler/DbInitHandler.php`), and `storage/files` — the
  configured `files_dir` — is created the same way by
  `Waaseyaa\Media\LocalFileRepository`. Neither reads or requires a
  pre-existing directory.
- `files/` (top-level, distinct from `storage/files`) was wired to nothing:
  no config key, no package, no generator ever resolved a path under it. It
  was pure residue.
- `src/Access/`, `src/Controller/`, `src/Domain/`, `src/Ingestion/`,
  `src/Search/`, `src/Seed/`, `src/Support/` had no generator target and no
  framework reference at all — a developer's own first file in that role was
  always what would populate them.
- `src/Entity/` is populated by `make:content-type`, which already creates it
  on demand (`MakeContentTypeHandler::writeFile()`: `is_dir($dir) ||
  mkdir($dir, 0o755, true)`, guarded by its own `file_exists()` collision
  check and `--force` override) — proven by
  `packages/cli/tests/Unit/Handler/MakeContentTypeHandlerTest.php`, whose
  fixture never creates `src/Entity/` ahead of the write.
  `docs/audits/2026-08-29-skeleton-audit.md` §S8 had already flagged the
  inconsistency of scaffolding eight directories this way while shipping none
  for `migrations/`, which the same audit confirmed needs no placeholder
  because `make:migration` creates it on first use.
  `docs/audits/2026-08-29-skeleton-audit.md` §S3/status also records that
  `src/Controller/` had already been reduced to a bare `.gitkeep` once the
  homepage-bypass `HomeController` it used to hold was deleted (#2651,
  PR #2672) — the placeholder outlived the only file that had ever justified
  the directory.
- `tests/Integration/` had no committed test and nothing generates one by
  default; the consumer-app `.ci/site-verify.php`-generated verification
  script (`packages/site-contract/src/Generation/SiteArtifactRenderer.php`)
  runs specific test file paths, never a bare `phpunit` invocation, so it
  never depended on the directory either.

`packages/testing/tests/Unit/SkeletonLayoutTest.php` reads
`skeleton/phpunit.xml.dist` and asserts every declared `<testsuite>`
`<directory>` exists — a load-bearing check, since PHPUnit 13 refuses to boot
at all (`Test directory "…/tests/Integration" not found`, exit 2) when a
declared testsuite directory is missing. Deleting `tests/Integration/.gitkeep`
without also removing its `<testsuite>` entry from `phpunit.xml.dist` would
have broken every fresh project's `vendor/bin/phpunit` on first run.

## Decision

**D-1. The skeleton ships minimal and bootable, with no placeholder
directories.** All fourteen `.gitkeep` files above are removed; the eleven
directories that held nothing else go with them, and the three that already
held real files remain. What remains is exactly what a fresh
application needs to boot: `public/index.php`, `src/Http/
BootFailureResponder.php`, `src/Provider/AppServiceProvider.php`,
`config/{waaseyaa,entity-types,services}.php`, `templates/*.twig`,
`.env.example`, `bin/post-create-setup.php`, `tests/Unit/Http/
BootFailureResponderTest.php`, and the composer/CI/docs/maintenance
scaffolding around them. `skeleton/phpunit.xml.dist` drops the `Integration`
`<testsuite>` entry that pointed at the now-absent directory; `tests/Unit/`
alone ships pre-declared, because it alone ships with a real test.
`skeleton/.gitignore` drops the now-unwired `/files/*` / `!/files/.gitkeep`
pair and widens `/storage/framework/` to `/storage/` — nothing under
`storage/` is tracked once its `.gitkeep` is gone, and everything under it is
created at runtime.

**D-2. Optional architectural areas are created only through deterministic
generators — an area appears when a generator writes a real file into it,
never as an empty scaffold — and inherits the existing determinism,
collision, path-containment, and generated-ownership guarantees.** This ADR
introduces no second directory-creation or ownership mechanism. Two existing,
independent mechanisms already satisfy it, and this decision is a ratification
of that shape rather than new code:

  - `site:init` recipe generation
    (`packages/site-contract/src/Generation/SiteArtifactRenderer.php`,
    `packages/cli/src/Site/SiteInitializationService.php`) creates every
    missing parent directory of a `GeneratedArtifact` target via
    `ensureTargetDirectory()`, inside the same transaction that checks
    collisions (`assertSafeTarget()`), enforces path containment
    (`SitePathContainment`), and journals created directories for rollback.
  - `make:*` handlers that write files at all (`MakeContentTypeHandler`,
    `MakePublicHandler`, `MakeMigrationHandler`, `MakeStorageMigrationHandler`)
    each already guard their own target directory with an `is_dir() ||
    mkdir(…, recursive: true)` check immediately before the write, and check
    for an existing file before overwriting it (`--force` to override). The
    other `make:*` handlers (`make:entity`, `make:policy`, `make:test`, …)
    render a stub to stdout for the developer to save by hand; they write no
    file and therefore own no directory-creation obligation.

  Removing the placeholder directories changes nothing about either
  mechanism's behavior — both already tolerate and are exercised against an
  absent target directory (see Verification).

**D-3. `minimal` and `editorial` are init-time presets, not a durable runtime
profile flag.** Wherever a future `site:init` flow (#2442) offers a named
shortcut between a plain, capability-declining site and a fuller one that
enables governed visual authoring, that choice is made once, at
initialization time, exactly the way `SiteManifestWizard` already makes
every other product decision (content types, governed authoring, personal
data collection) through one-time interactive or non-interactive answers.
No component reads "which preset was this site initialized with" at runtime.
Nothing branches on a preset name after `site:init` has run.

**D-4. What persists in the `waaseyaa.site` manifest is the resolved
decisions, not the preset name.** `SiteManifestSchema` has no `preset` or
`profile` field today, and this ADR forbids adding one. The manifest already
records only resolved, individually-inspectable decisions — active/
`not_needed`/`planned` capabilities, content types and their routes,
personal-data stores, and selected recipe digests
(`packages/cli/src/Site/SiteManifestWizard.php`,
`packages/site-contract/src/SiteManifestSchema.php`) — and any future preset
mechanism must resolve to the same shape before it is written, never persist
its own label alongside or instead of those decisions.

**D-5. Existing applications are not migrated.** No upgrade path — in
particular `project:init --upgrade` (#2664) — treats an application generated
under the previous, placeholder-bearing skeleton as out of date or drifted
merely because the current skeleton no longer ships those directories. An
application that already has `src/Entity/` (or any of the other twelve) keeps
it untouched; an application that never populated one of them is not made to
create it. The new skeleton shape governs only what `composer create-project`
copies into a project that does not exist yet.

## Consequences

- `composer create-project waaseyaa/waaseyaa` produces a smaller, cleaner tree
  — a first-time developer sees only directories that hold something, not a
  guess at future ones.
- `docs/application-anatomy.md`, `README.md`, and `CLAUDE.md` in the skeleton
  now say, for each conventional area, which generator (if any) creates it and
  on what trigger — the same pattern `migrations/` already used, generalized.
- A developer who wants `tests/Integration/` re-adds the directory and its
  `<testsuite>` entry in `phpunit.xml.dist` themselves; nothing in the
  framework does it for them, because nothing writes an integration test file
  on their behalf. This is a documented, deliberate seam, not an oversight —
  the alternative (declaring the testsuite against a directory that may not
  exist) fails PHPUnit's own boot, unconditionally, for every fresh project.
- `docs/audits/FW-ARCH-2026-08/data/file-roster.json` and `…/support-
  surfaces.json` list the removed paths. Neither is read by any test
  or CI gate (verified: no reference to either file outside the audit tree
  itself) — they are the audit's frozen A0 census
  (`docs/audits/FW-ARCH-2026-08/README.md`), fixed to a cited SHA and lock
  digest by design, not a live inventory `bin/refresh-governance-artifacts`
  regenerates. This ADR leaves them untouched, as every prior change to the
  audited tree has.

## Non-goals

- This ADR does not implement `site:init` presets (#2442). It only constrains
  the shape that work must respect (D-3, D-4) so it cannot later persist a
  preset name or a runtime profile flag.
- This ADR does not migrate, offer, or gate an upgrade command for existing
  applications (D-5). #2664's `project:init --upgrade` is out of scope here
  beyond the non-goal it must honor.
- This ADR does not change `storage/` or `files_dir` runtime resolution, the
  `db:init` / `LocalFileRepository` directory-creation code, or any
  `site:init` recipe. All of it already worked without the placeholders it
  removes.
