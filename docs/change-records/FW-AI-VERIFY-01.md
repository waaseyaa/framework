# FW-AI-VERIFY-01

Status: bounded implementation candidate for Framework #2664 `ai:verify` read surface.

Anchor mirror: [waaseyaa/framework#2664](https://github.com/waaseyaa/framework/issues/2664)

Parent residuals: #2660 whole-client generated-state lifecycle, `ai:update`, `project:init --upgrade`, Composer post-update reconciliation, and the unified hash/version engine across `.waaseyaa/generated.json` remain open.

## Scope

This slice lands a **read-only** CLI command:

- `bin/waaseyaa ai:verify`
- Registered only through `Waaseyaa\Bimaaji\BimaajiServiceProvider`
- Reuses the seven canonical client transformers, `SkillSetParser`, `SkillInventory`, `ManagedRegion`, and schema 1 `InstalledManifest` wholefile sha1 records
- Does **not** install, update, migrate, repair, or invent a competing manifest/hash engine

## Contract

1. **Strict manifest read** — `InstalledManifest::readStrict()` reports `missing`, `unreadable`, `malformed`, and `unsupported_schema` distinctly. `InstalledManifest::load()` remains fail-soft for `bimaaji:install`.
2. **Row validation** — duplicate ownership paths, unsafe paths, invalid digests, and escaped targets fail before target reads.
3. **Bounded reads** — every target path passes the install-equivalent containment boundary; reads are capped at 1 MiB per file. No source contents or private file bytes appear in output.
4. **Dual evidence** — schema 1 wholefile sha1 provenance is assessed separately from managed-region freshness against the current transformer render. Valid bytes outside `ManagedRegion` markers do not fail verification and are not treated as permission to overwrite.
5. **Honest legacy** — targets present in the current render set but absent from the manifest are reported as `target_unrecorded`. Retired manifest paths still present on disk are reported as `target_retired_present`. Marker-less files report `target_managed_region_unprovable` rather than guessing historical renderer state.
6. **Exit codes** — `0` only when every selected check passes; `1` on any finding or project-root resolution failure.
7. **Machine output** — `--json` emits a deterministic bounded report; human output uses stable `ai:verify:` lines.

## Out of scope

- `ai:update --check/apply`
- `project:init` / `project:init --upgrade`
- Composer post-update hooks
- `.waaseyaa/generated.json` reconciliation
- Generated-output removal/uninstall
- Claiming complete future AI lifecycle or upgrade authority

## Evidence (this candidate)

Implementation files:

- `packages/cli/src/Command/AiVerifyCommand.php`
- `packages/bimaaji/src/Install/GeneratedStateVerifier.php`
- `packages/bimaaji/src/Install/*Verify*` + `InstallPathSandbox` + strict manifest read types
- `packages/bimaaji/tests/Unit/Command/AiVerifyCommandTest.php`
- `docs/specs/bimaaji-install.md` (`ai:verify` section)

### Qualification (coordinator locked install)

Coordinator private locked `vendor/` install and source binding completed in 4.07s.

Focused proof (exact command):

```bash
./vendor/bin/phpunit packages/bimaaji/tests/Unit/Command/AiVerifyCommandTest.php \
  packages/bimaaji/tests/Unit/Install/InstalledManifestTest.php \
  packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php --no-coverage
```

| Pass | Tests | Assertions | Note |
|---|---|---|---|
| Initial candidate | 34 | 112 | One legacy regression: `malformedRowsAreDroppedWithoutDiscardingTheGoodOnes` after `load()` delegation |
| Repair pass | 42 | 134 | Strict read, containment, bounded reads, selection semantics |
| Final pass | 45 | 145 | `client_not_installed` / `no_recorded_installation` selection proof |

## Residual acceptance for #2664 / #2660

- Shared update/check/apply engine with `.waaseyaa/generated.json`
- Whole generated-state removal and supported upgrade migrations
- OS-matrix first-commit CI proof that composes `ai:verify` with the full lifecycle
- Packaged-form lifecycle may continue using its inline verifier until this command is wired into that proof deliberately

### Independent review repairs

Root review identified a one-shot transformer iterable consumed twice, a retired symlink target incorrectly accepted, and an unknown direct-service client filter verifying nothing. Three discriminating regressions reproduced all three (48 tests: one error, two failures); scoped repairs materialize the transformer collection once and refuse both invalid evidence paths. The same three-file focused command now passes **48 tests, 155 assertions** (PHP 8.5.9, 0.072s, 24MB). No full suite was repeated.

The new adapter follows the existing inline CLI type-reference convention; scanner behavior is not an authorization or package-layer exemption.

### Package-boundary qualification

The layer gate rejected the new lower-layer command dependency. The CLI adapter now accepts only a documented typed callback; the existing Bimaaji provider supplies its canonical verification report. No runtime dependency, import-hiding exemption or baseline was added. The real registered command integration remains tested in Bimaaji, with a separate CLI transport test for option normalization and both output paths. Four focused files pass **49 tests, 163 assertions** (PHP 8.5.8, 0.072s, 22MB). Normal preflight: **41/41 passed in 23.2s**, including package layers, public-surface parity and style. Full suites were not repeated locally.
