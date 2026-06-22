# CLI Project-Root Resolution + `$_ENV`/`$_SERVER` Population — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Execute [ADR-005](../adr/005-cli-bootstrap-and-env-in-kernel.md) — rewrite `packages/cli/bin/waaseyaa` to use `getcwd()`, extend `EnvLoader` to populate `$_ENV`/`$_SERVER`, and delete every consumer-side CLI-bootstrap workaround as the downstream outcome.

**Design doc:** `docs/adr/005-cli-bootstrap-and-env-in-kernel.md`

**Sequences with:** [ADR-004 implementation plan](./2026-04-16-framework-package-collapse-implementation.md). Both ADRs ship in `v0.2.0-alpha.1`. This plan runs first (smaller surface); ADR-004 stacks; giiken cleanup runs after release.

**Phase 1 finding (already recorded in ADR-005 §4):** `AbstractKernel::boot()` already invokes `EnvLoader::load($this->projectRoot . '/.env')` at line 88. Kernel ownership of `.env` is in place. The original Phase 2 ("add `.env` loading to kernel") is dropped from this plan — it's already done. `symfony/dotenv` is NOT needed in `packages/foundation`.

---

## Phase Overview

| Phase | Focus | Tasks | Outcome |
|-------|-------|-------|---------|
| 1 | Investigate (complete) | — | Kernel already owns `.env`; only bin + EnvLoader superglobal work remains |
| 2 | `EnvLoader` enhancement | 1-3 | `$_ENV` and `$_SERVER` populated; unit tests green |
| 3 | CLI bin rewrite | 4-7 | `bin/waaseyaa` uses `getcwd()`; symlinked-vendor integration test green |
| 4 | Consumer template update | 8-9 | Skeleton `public/index.php` shrunk |
| 5 | Release coordination | 10-11 | Merged to monorepo main; stacks with ADR-004 for release |
| 6 | Giiken cleanup (post-release) | 12-16 | All workarounds deleted in giiken |

---

## Phase 2: `EnvLoader` enhancement

### Task 1: Extend `EnvLoader::load()` to populate `$_ENV` and `$_SERVER`

**File modified:** `packages/foundation/src/Kernel/EnvLoader.php`

**Steps:** Inside the foreach loop, after the existing `putenv` call, add two more guarded writes:

```php
if (!array_key_exists($key, $_ENV)) {
    $_ENV[$key] = $value;
}
if (!array_key_exists($key, $_SERVER)) {
    $_SERVER[$key] = $value;
}
```

Preserve the `getenv($key) === false` guard on the `putenv()` call. Each superglobal is guarded independently so a partial pre-population is respected.

**Verify:** re-read the file. Confirm the three writes are ordered `putenv` → `$_ENV` → `$_SERVER`, each with its own presence guard.

### Task 2: Extend `EnvLoaderTest`

**File modified:** `packages/foundation/tests/Unit/Kernel/EnvLoaderTest.php`

**Coverage added:**

- `populates_env_superglobal` — after `EnvLoader::load()` runs on a fixture with `FOO=bar`, assert `$_ENV['FOO'] === 'bar'`.
- `populates_server_superglobal` — same fixture, assert `$_SERVER['FOO'] === 'bar'`.
- `does_not_overwrite_preset_env` — set `$_ENV['FOO'] = 'preset'` before load; fixture sets `FOO=from_file`; after load, `$_ENV['FOO'] === 'preset'`.
- `does_not_overwrite_preset_server` — same pattern for `$_SERVER`.

Each test must restore `$_ENV` / `$_SERVER` / `putenv` state in `tearDown()` to avoid cross-test pollution.

**Verify:** `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/EnvLoaderTest.php` → green, including the four new cases.

### Task 3: Regression check for existing tests

**Steps:**

```bash
cd /home/jones/dev/waaseyaa
./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/ 2>&1 | tail -10
```

**Verify:** all green. If any test was implicitly relying on `$_ENV` NOT being populated, update its fixture or pre-set value.

---

## Phase 3: CLI bin rewrite

### Task 4: Rewrite `packages/cli/bin/waaseyaa`

**File modified:** `packages/cli/bin/waaseyaa`

**Target content:** per ADR-005 §5.1. Reproduce exactly:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = getcwd();
if ($projectRoot === false || !file_exists($projectRoot . '/composer.json')) {
    fwrite(STDERR, "waaseyaa: must be run from a project root (directory containing composer.json).\n");
    exit(1);
}

$autoload = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "waaseyaa: vendor/autoload.php not found. Run 'composer install'.\n");
    exit(1);
}
require $autoload;

exit((new Waaseyaa\Foundation\Kernel\ConsoleKernel($projectRoot))->handle());
```

**Verify:**

```bash
cd /home/jones/dev/waaseyaa
./packages/cli/bin/waaseyaa --version          # boots, exit 0, shows version
cd /tmp && /home/jones/dev/waaseyaa/packages/cli/bin/waaseyaa --version
# expect exit 1 + stderr "must be run from a project root"
```

### Task 5: Write unit smoke tests for the bin entry

**File created:** `packages/cli/tests/Integration/BinScriptTest.php` (confirm test dir structure exists; adapt path if needed)

**Coverage:**

- `runs_from_project_root` — chdir to a fixture project with `composer.json` + `vendor/autoload.php`; shell-exec the bin; assert exit 0.
- `errors_when_no_composer_json` — chdir to a scratch dir without `composer.json`; shell-exec the bin; assert exit 1 and stderr contains `must be run from a project root`.
- `errors_when_no_vendor` — fixture with `composer.json` but no `vendor/`; assert exit 1 and stderr contains `Run 'composer install'`.

Fixture creation: use `sys_get_temp_dir()` scratch; tear down in `tearDown()`.

**Verify:** `./vendor/bin/phpunit packages/cli/tests/Integration/BinScriptTest.php` → all green.

### Task 6: Symlinked-vendor regression test

**File created:** `packages/cli/tests/Integration/SymlinkedVendorTest.php`

**Coverage:** create a scratch fixture layout mirroring giiken's symlink pattern:

```
<scratch>/
├── composer.json                  (declares the consumer project; contents minimal)
├── .env                           (contains TEST_PROJECT_ROOT_MARKER=consumer)
├── vendor/
│   ├── autoload.php               (minimal composer-shaped autoload)
│   └── waaseyaa/
│       └── cli -> /home/jones/dev/waaseyaa/packages/cli   (symlink)
```

Assertion: invoke `<scratch>/vendor/waaseyaa/cli/bin/waaseyaa --version` with `cwd = <scratch>`. Either:

- Add a probe command `waaseyaa:debug:project-root` that prints `getcwd()`-resolved root (and guard it behind a dev-only registration), or
- Assert side-effect visibility — e.g., after running, `TEST_PROJECT_ROOT_MARKER` is in `$_ENV` (if we can shell the bin and read its output through a debug command).

Simplest implementation: emit project root to stdout via a small `--show-project-root` flag added to the minimal-console path. If that feels invasive, use strace-like behavior: verify `$_ENV['TEST_PROJECT_ROOT_MARKER']` would be set when the bin boots, by having the test fixture's `.env` set a unique marker and assert its presence after shelling.

**Decision point during implementation:** choose probe mechanism (debug flag vs marker-env fixture). Document the choice in the task notes. Prefer the marker-env approach — zero framework surface change.

**Verify:** test passes. If the bin had resolved to the monorepo (old behavior), the test would fail because the monorepo has no `.env` with that marker.

### Task 7: Update CLI README

**Files modified:** `packages/cli/README.md` (if exists) and any doc that explains how to invoke the CLI.

**Content:** document that the CLI must be invoked from the project root. Remove mentions of wrapper scripts or `.env` prerequisites. Canonical command: `./vendor/bin/waaseyaa <command>`.

**Verify:** `grep -n 'wrapper\|bin/giiken\|\\.env' packages/cli/README.md` returns nothing (after edit).

---

## Phase 4: Consumer template update

### Task 8: Shrink skeleton `public/index.php`

**File modified:** whichever file is the canonical skeleton for `public/index.php` in waaseyaa.

**Discovery:**

```bash
cd /home/jones/dev/waaseyaa
grep -rln 'Dotenv\|loadEnv\|HttpKernel' packages/*/stubs/ packages/*/skel/ skeleton/ 2>/dev/null | grep -v tests
```

**Target content:** per ADR-005 §5.3.

**Verify:** after edit, the skeleton contains no `Dotenv` reference.

### Task 9: Check for other consumer-side Dotenv usages

**Steps:**

```bash
grep -rln 'Dotenv\|loadEnv' packages/*/stubs/ packages/*/skel/ skeleton/ 2>/dev/null
```

**Verify:** empty. If any stub/skel still calls Dotenv (queue worker? scheduled runner?), apply the same reduction — instantiate a kernel and let it load `.env` via `EnvLoader`.

---

## Phase 5: Release coordination

### Task 10: Full CI pass

**Steps:**

```bash
cd /home/jones/dev/waaseyaa
composer install --no-interaction
./vendor/bin/phpstan analyse --no-progress 2>&1 | tail -5
./vendor/bin/phpunit 2>&1 | tail -5
./packages/cli/bin/waaseyaa --version
```

**Verify:** all four exit 0.

### Task 11: Commit and merge to `main`

**Steps:** commit as `feat(cli,env): getcwd-based project root; populate $_ENV/$_SERVER in EnvLoader (ADR-005)`. Push to origin main (branch protection allows fast-forward non-force pushes).

**Stacking order:** this lands before ADR-004 execution. Once both are on main, tag `v0.2.0-alpha.1`.

---

## Phase 6: Giiken cleanup (post-release)

**Prerequisite:** waaseyaa `v0.2.0-alpha.1` on packagist. Giiken bumps its `waaseyaa/framework` constraint.

### Task 12: Bump giiken's waaseyaa constraint

**File modified:** `/home/jones/dev/giiken/composer.json`

**Steps:** update `waaseyaa/framework` (and any pinned `waaseyaa/*` entries) to `^0.2.0-alpha.1`. Run `composer update waaseyaa/*`.

**Verify:** `./vendor/bin/waaseyaa --version` boots.

### Task 13: Delete the workaround files

```bash
cd /home/jones/dev/giiken
git rm bin/giiken scripts/repoint-vendor-bin.php
```

### Task 14: Remove composer hooks

**File modified:** `/home/jones/dev/giiken/composer.json`

**Steps:** delete `post-install-cmd` and `post-update-cmd` entries that invoke `repoint-vendor-bin.php`. Remove empty `scripts` subsections if they become empty.

**Verify:** `jq '.scripts' composer.json` contains no reference to `repoint-vendor-bin`.

### Task 15: Shrink `public/index.php`

**File modified:** `/home/jones/dev/giiken/public/index.php`

**Target:** match ADR-005 §5.3 exactly.

**Verify:** `grep -c 'Dotenv\|loadEnv' public/index.php` → 0.

### Task 16: Update giiken's CLAUDE.md

**File modified:** `/home/jones/dev/giiken/CLAUDE.md`

**Steps:**

- Delete the `./bin/giiken is the canonical CLI entry point` section entirely.
- Update command examples from `./bin/giiken ...` to `./vendor/bin/waaseyaa ...`.
- Update the "Boot-to-browser status" smoke path to use `./vendor/bin/waaseyaa`.

**Verify:**

```bash
grep -n 'bin/giiken\|repoint-vendor-bin' /home/jones/dev/giiken/CLAUDE.md
# expect no output
```

### Task 17 (validation): Smoke test end-to-end

```bash
cd /home/jones/dev/giiken
rm -rf vendor composer.lock
composer install
./vendor/bin/waaseyaa migrate
./vendor/bin/waaseyaa giiken:seed:test-community
./vendor/bin/waaseyaa serve &
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/
# expect 200
```

**Verify:** smoke passes with zero workarounds present.

---

## Rollback

Phases 2-4 are pure code changes with unit/integration tests. Revert the merge commit if CI fails or regressions appear. Phase 6 (giiken) is downstream and independent — `git revert` on giiken without touching waaseyaa.

---

## Risk Register

| Risk | Severity | Mitigation |
|---|---|---|
| Pre-existing code reads `$_ENV`/`$_SERVER` and had subtly relied on them being un-populated | Low | The change respects pre-set values. If any test set a sentinel and expected `$_ENV` to lack it, Task 3 catches it. |
| Symlink-based integration test flakes on CI runners without symlink support | Low | PHP's `symlink()` is universally available; fixtures are local-only (no git-tracked symlinks). |
| Consumer `public/index.php` in the wild still calls `Symfony\Component\Dotenv` | Low (harmless) | Dotenv's `loadEnv` is idempotent; redundant call is a no-op. Skeleton update (Task 8) fixes it going forward. |
| Giiken cleanup removes `bin/giiken` before waaseyaa release is installed | High (breaks dev loop) | Phase 6 is explicitly gated on Task 12 (constraint bump + install). Do not start 13+ until 12 is green. |
| Bin fails with clear error when someone runs it from a subdirectory, where previously it silently resolved wrong | Low (improvement) | Documented in CLI README (Task 7). Message is actionable. |
