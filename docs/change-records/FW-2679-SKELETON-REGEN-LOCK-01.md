# Portable skeleton `composer regen-lock`

Status: implementation candidate, fourth bounded slice of Framework #2679
(program #2676). Base: `9de68180297d20152d02ac5ad7521f1d90b2a5b3`.

## Problem

The skeleton (`waaseyaa/waaseyaa`) declares

    "regen-lock": "@composer update 'waaseyaa/*' --no-plugins --no-install --no-scripts"

`skeleton/docs/local-dev.md` tells every generated project to run it before
committing dependency changes. Composer runs a script line through the host
shell, and the single quotes were POSIX shell quoting.

The following was reproduced with Composer 2.9.5 in a disposable fixture. The
fixture locked `waaseyaa/alpha`, `waaseyaa/gamma` and `other/beta` at 1.0.0,
with 1.1.0 of each available from an inline repository and Packagist disabled.

| Shell | Pattern Composer received | Output | Exit | Lock afterwards |
|---|---|---|---|---|
| native PowerShell → Composer → `cmd.exe` | `'waaseyaa/*'`, quotes included | `Pattern "'waaseyaa/*'" listed for update does not match any locked packages.` / `Nothing to modify in lock file` | 0 | unchanged |
| native `cmd.exe` | same | same | 0 | unchanged |
| Linux `sh` (WSL2) | `waaseyaa/*` | `Upgrading waaseyaa/alpha (1.0.0 => 1.1.0)`, `Upgrading waaseyaa/gamma (1.0.0 => 1.1.0)` | 0 | only the two `waaseyaa/*` packages moved |

On native Windows the command therefore did nothing and still reported
success.

Candidate forms were run through the same fixture before one was chosen. The
fixture adds a `waaseyaa/decoy` file in the project root.

| Candidate | PowerShell | cmd | Linux `sh` |
|---|---|---|---|
| `"waaseyaa/*"` | correct | correct | correct |
| `waaseyaa/*` (unquoted) | correct | correct | **no-op, exit 0**: `sh` globbed the pattern into `waaseyaa/decoy` |

## Contract

- The skeleton's `regen-lock` is
  `@composer update "waaseyaa/*" --no-plugins --no-install --no-scripts`.
  Double quotes are quoting in both `cmd.exe` and POSIX `sh`, and they stop a
  POSIX shell from globbing, so Composer receives exactly `waaseyaa/*` on both
  hosts.
- `--no-plugins`, `--no-install` and `--no-scripts` are unchanged, and
  Composer's output and exit code pass through the script unchanged.
- A generated project inherits the command verbatim. `composer create-project`
  copies the manifest, `bin/post-create-setup.php` only reads it, and
  `tools/sync-skeleton-requirements.php` rewrites only `require` when it
  publishes the skeleton.
- `docs/specs/native-host-support.md` keeps the entry classified as
  unsupported on native Windows pending verification. No native Windows CI job
  runs it (#2678).

## Out of scope

Other Composer scripts, raw `bin/git` convergence, the native-host inventory,
the two native-Windows `ProjectHooksTest` failures, and #2678.

## Verification boundary

`tests/Architecture/SkeletonRegenLockTest.php` runs offline. Packagist is
disabled, `COMPOSER_DISABLE_NETWORK=1` is set, and `COMPOSER_HOME` and
`COMPOSER_CACHE_DIR` are isolated.

- A real `composer create-project` of the local skeleton through a path
  repository, and a run of `tools/sync-skeleton-requirements.php`, must both
  carry the skeleton's script verbatim.
- The generated project's `regen-lock`, run through `composer run-script` in
  the fixture above, must upgrade exactly the two `waaseyaa/*` packages and
  leave `other/beta`. It must create no `vendor/` (`--no-install`) and run no
  `post-update-cmd` (`--no-scripts`).
- With an unsatisfiable requirement, the script must return Composer's exit
  code 2.

Results:

- **Base, native Windows:** the update case fails with the no-op above; the
  other two pass.
- **Candidate, native Windows:** 3/3.
- **Linux (WSL2, Composer 2.7.1):** the base and the candidate both pass 3/3.
  The unquoted form fails the update case, because of the glob decoy.

`--no-plugins` is checked through the manifest, not by effect: the fixture
installs no plugin. WSL2 is local Linux evidence. Hosted Linux CI
qualification of the exact candidate is still required.
