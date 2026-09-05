# FW-2833 — package-owned auth UI scaffold sources

- Issue mirror: `waaseyaa/framework#2833`
- Consumer blocker: `jonesrussell/content-pipeline#36`
- Related pattern: #2832 (`sync-rules` owns Foundation resources)
- Contract: `docs/specs/auth-consumer-extensions.md`, `docs/specs/cli-kernel.md`

## Problem

`AuthUiScaffoldManager::sourceContext()` only looked at the application root
and an installed `waaseyaa/framework` metapackage. ADR-004 direct-package
consumers omit that aggregate, so `scaffold:auth --check` exited 1 with
"Framework auth UI sources were not found … waaseyaa/framework package" even
though the loaded `waaseyaa/cli` path-install still sits beside
`packages/admin/app` in the monorepo.

## Decision (superseded — see "Repair design" below)

Candidate `290d064` kept project-root and metapackage lookups and added
owning-package candidates that followed the loaded `AuthUiScaffoldManager` /
`waaseyaa/cli` install path to the sibling `packages/admin/app`, using
`realpath` so Composer path symlinks resolve. Independent review found this
sibling guess only ever holds when `vendor/waaseyaa/cli` is a path-repo
symlink back into this monorepo — no real consumer install produces that
layout, since `packages/admin` has no `composer.json` and is not
split-mirrored. It is replaced by the package-owned resource mirror in
"Repair design" below.

## Proof (superseded — see "Repair design" below)

The unit-only proof originally claimed here, and a claimed Content Pipeline
direct-profile requalification of `scaffold:auth --check`, were never
substantiated by a real packaged-consumer install and are withdrawn. The
actual proof is `tests/PackagedForm/check-cli-auth-scaffold`: an exact-HEAD
archive installed into direct (no `waaseyaa/framework`) and aggregate
consumers via Composer path repositories with `symlink: false`, running the
real `vendor/bin/waaseyaa scaffold:auth`, with negatives for a missing
resources directory, a missing resource file, and a corrupted resource file,
plus a hand-edit preservation check. See "Repair design" decision 4.

## Repair design (2026-09-05, review of candidate 290d064)

Independent review reproduced the #2833 failure against candidate `290d064` under a
real direct-package install (Composer path repositories with `symlink: false`,
`waaseyaa/core` + `waaseyaa/cli`, no `waaseyaa/framework`): the candidate resolved
sources as `dirname(<waaseyaa/cli install path>) . '/admin/app'`, a sibling-directory
guess that holds only when `vendor/waaseyaa/cli` is a path-repo symlink back into
this monorepo. `packages/admin` has no `composer.json` and is not split-mirrored,
so no consumer can install it. The following decisions replace that approach.

1. **Distribution home.** The five auth scaffold inputs named by
   `AuthUiScaffoldManager::FILE_MAP` ship as package-owned resources of the
   already-installable `waaseyaa/cli` package at
   `packages/cli/resources/auth-ui/<FILE_MAP source path>`. No new package,
   dependency, release, or admin-UI feature.
2. **One authored authority.** `packages/admin/app` remains the only authored
   source. `packages/cli/resources/auth-ui/` is a generated mirror:
   `bin/sync-cli-auth-ui-resources` regenerates it deterministically (exactly the
   `FILE_MAP` sources, byte-for-byte, nothing else), and
   `tests/Architecture/CliAuthUiResourceParityTest` fails on any byte or
   membership difference in either direction, naming the regeneration command.
   A mirror can therefore never become an independent source.
3. **Resolution order** in `AuthUiScaffoldManager::sourceContext()`:
   (a) project-owned `<project>/packages/admin/app` (monorepo development and
   project-owned overrides — unchanged); (b) aggregate consumer
   `<project>/vendor/waaseyaa/framework/packages/admin/app` (unchanged);
   (c) canonical package-owned: `Composer\InstalledVersions::getInstallPath('waaseyaa/cli')`
   + `/resources/auth-ui`; (d) loaded-package-local fallback
   `dirname(__DIR__, 2) . '/resources/auth-ui'` relative to the manager's own class
   file, the same convention `Waaseyaa\Bimaaji\Install\PackagedSkillResources`
   uses, for runtimes where `InstalledVersions` does not register the package.
   The sibling `admin/app` guesses are removed. Version resolution keeps its
   existing semantics (a `VERSION` file in the candidate's version roots, else
   `InstalledVersions` pretty version of the owning package).
4. **Packaged proof.** `tests/PackagedForm/check-cli-auth-scaffold` follows
   `check-cli-sync-rules` exactly: archive the exact candidate HEAD, install every
   package as a real copy (`symlink: false`) into a direct-profile consumer with no
   `waaseyaa/framework`, run the real `vendor/bin/waaseyaa scaffold:auth --check`
   and bind the oracle to the five expected files and exit 0; run the same oracle
   against an aggregate-consumer control; then negatives — resource directory
   missing, a resource file missing, a resource file corrupt — must be rejected by
   the complete oracle, not by exit status alone; and hand-edit preservation
   (scaffold, edit, re-check without `--force` keeps the edit; `--force` reports the
   overwrite). Runs as its own dedicated `ci/cli-auth-scaffold` job in
   `.github/workflows/ci.yml`, alongside the existing `packaged-form` checks and
   mirroring the `check-cli-sync-rules` job's wiring.
5. **Coverage.** Changed-line coverage is closed by unit tests that drive the
   separate consumer branches through an injectable install-path resolver plus the
   packaged proof; the 80% threshold and coverage configuration are untouched.
6. **Documentation correction.** The earlier "Proof" text claiming a Content
   Pipeline direct-profile requalification, and the spec wording that limited the
   guarantee to "when Composer path-installs monorepo packages", are replaced by
   the behaviour actually implemented and proven here.
