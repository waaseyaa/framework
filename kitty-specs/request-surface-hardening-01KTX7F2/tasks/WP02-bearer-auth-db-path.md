---
work_package_id: WP02
title: Bearer Hardening & DB Path Resolution
dependencies: []
requirement_refs:
- FR-005
- FR-006
- FR-007
- FR-008
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
created_at: '2026-06-12T00:00:00+00:00'
subtasks:
- T007
- T008
- T009
- T010
- T011
history:
- date: '2026-06-12T00:00:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/mcp/src/Auth/
execution_mode: code_change
owned_files:
- packages/mcp/src/Auth/BearerTokenAuth.php
- packages/mcp/tests/Unit/Auth/BearerTokenAuthTest.php
- packages/mcp/tests/Unit/Auth/BearerTokenAuthHardeningTest.php
- packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php
- packages/foundation/src/Kernel/AbstractKernel.php
- packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php
- packages/cli/src/Handler/DbInitHandler.php
- packages/cli/src/Handler/HealthReportHandler.php
- packages/cli/src/Handler/AboutHandler.php
- packages/cli/tests/Unit/Handler/DbInitHandlerTest.php
- packages/cli/tests/Unit/Handler/HealthReportHandlerTest.php
- packages/cli/tests/Unit/Handler/AboutHandlerTest.php
- tests/Integration/DbPath/DbPathResolutionTest.php
tags: []
---

# WP02 — Bearer Hardening & DB Path Resolution

**Mission**: request-surface-hardening-01KTX7F2 | **Tracks**: #1652, #1650
**Requirements**: FR-005..FR-008, NFR-003 | **Dependencies**: none
**Command**: `spec-kitty agent action implement WP02 --agent <name>`

## Objective

Two independent hardenings sharing a lane. **Bearer (#1652)**: token comparison becomes constant-time over all candidates (`hash_equals` full scan, no early exit), and a token whose account is blocked stops authenticating at request time — fail closed, indistinguishable from an unknown token, zero added queries. **DB path (#1650)**: a relative `WAASEYAA_DB` (or `config['database']`) resolves against the kernel project root in every runtime — HTTP, dev server, CLI, queue — so the dev server can never again silently create a second database under the docroot; when a resolved path *does* land inside the docroot, boot logs a warning. Absolute paths and the unset default behave byte-identically (scenario 5).

## Context (read first)

- `research.md` D4 (constant-time + the duck-typed blocked seam, with the falsified `AccountInterface` assumption), D5 (why `DatabaseBootstrapper::resolvePath()` is the single canonical seam), D6 (the warning's predicate and placement).
- `contracts/bearer-and-dbpath.md` — authoritative; every numbered clause must hold.
- `data-model.md` "Bearer authentication decision table" and "Database path resolution matrix".
- **Bearer ground truth**: `BearerTokenAuth::authenticate()` does `return $this->tokens[$token] ?? null;` (`packages/mcp/src/Auth/BearerTokenAuth.php:26-28`). Map shape `array<string, AccountInterface>` (`:11`) — PHP coerces numeric-string keys to `int`, so the scan must `(string)`-cast before `hash_equals()`. `getTokens()` (`:41-44`) feeds `ServerConfigReadModel` fingerprinting — must keep returning the raw map. `AccountInterface` has NO status member; `User implements AccountInterface` exposes `isActive(): bool` (`packages/user/src/User.php:29,340-343`). `packages/mcp/composer.json` does NOT require `waaseyaa/user` — the blocked check is duck-typed (`method_exists`) precisely to keep it that way.
- **Freshness**: every runtime builds a fresh kernel per request (`public/index.php:36-41`, including FrankenPHP worker mode), so token-map account objects are loaded at this request's boot; an in-memory `isActive()` read IS a request-time check (NFR-003: zero added queries).
- **Path ground truth**: `DatabaseBootstrapper::resolvePath()` (`packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:25-43`) selects `config['database']` → `getenv('WAASEYAA_DB')` → `$projectRoot . '/storage/waaseyaa.sqlite'` and passes relative values verbatim to `@mkdir`/`createSqlite` — SQLite then joins them onto process CWD. Sole production caller: `AbstractKernel:197` (`new DatabaseBootstrapper()->boot($this->projectRoot, $this->config)`); `$this->logger` is initialized before that line (`:120-151`). All runtimes funnel through it: HTTP (`public/index.php` projectRoot = `dirname(__DIR__)`, `:23`), CLI/queue (`packages/cli/bin/waaseyaa` projectRoot = `getcwd()` gated on `composer.json`, `:14-20`, → `CliApplication:86` → `ConsoleKernel` → `AbstractKernel`).
- **CLI divergence to fix**: `DbInitHandler::resolveDatabasePath()` returns `config['database']` VERBATIM (`packages/cli/src/Handler/DbInitHandler.php:160-165`) but absolutizes env values via `absolutize()` (`:175-181`) which treats only `:memory:` and `/`-prefixed values as absolute (Windows drive-letter paths would be wrongly prefixed). Display-only raw-env surfaces: `HealthReportHandler:115`, `AboutHandler:44`.
- **Logger convention** (CLAUDE.md): `Waaseyaa\Foundation\Log\LoggerInterface`, accept `?LoggerInterface $logger = null`, default `NullLogger`. Never `psr/log`.
- **Scratch hygiene rule**: the integration test manipulates CWD and creates temp project dirs — temp dirs under `sys_get_temp_dir()`, restore CWD in `finally`.

## Requirement / contract map

| Deliverable | Requirement | Contract anchor |
|---|---|---|
| Full-scan `hash_equals` comparison | FR-005 | bearer-and-dbpath.md §1–4 |
| Duck-typed `isActive()` fail-closed rejection | FR-006, NFR-003 | §5–8 |
| Project-root absolutization in `resolvePath()` | FR-007 | §9–13 |
| `DbInitHandler` parity + display surfaces | FR-007 | §14–15 |
| Docroot boot warning | FR-008 | §17–19 |
| HTTP/CLI agreement integration test | SC-004 | §13 + Verification |

## Out of scope for this WP (do not touch)

- `packages/mcp/src/McpEndpoint.php`, `McpServiceProvider.php` — the endpoint/binding are correct; only the auth class changes. The #1635/#1636 transport bugs stay out.
- `packages/access/src/AccountInterface.php` — no interface widening (research D4 alternative b, rejected).
- `packages/mcp/composer.json` — zero manifest edges; an `instanceof User` import is an automatic reject.
- `DatabaseBootstrapper`'s production guard / `@mkdir` semantics — they now operate on the resolved path, otherwise unchanged.
- `packages/api/**`, `packages/entity/**` — WP01's surface. CHANGELOG/docs — WP03's.

## Subtasks

### T007 — Constant-time comparison

**Files**: `packages/mcp/src/Auth/BearerTokenAuth.php`

1. Replace the lookup (`:28`) with a full scan:
   ```php
   $matched = null;
   foreach ($this->tokens as $candidate => $account) {
       if (\hash_equals((string) $candidate, $token)) {
           $matched = $account;
       }
   }
   ```
   No `break` (constant-time over the candidate set, FR-005's wording); single return path after the loop (T008 adds the status gate there). Keep the existing null/empty/prefix handling above it byte-identical.
2. Document the two non-obvious choices inline: the `(string)` cast (numeric-key coercion) and the absent `break` (whole-call timing independent of match position).
3. `getTokens()` untouched.

**Validation**: existing `BearerTokenAuthTest` (7 tests) green UNCHANGED — they pin valid/invalid/missing/empty/malformed/case-insensitive/empty-map behavior that must survive.

### T008 — Blocked-account rejection + auth tests

**Files**: `packages/mcp/src/Auth/BearerTokenAuth.php`, `packages/mcp/tests/Unit/Auth/BearerTokenAuthHardeningTest.php` (NEW)

1. After the scan:
   ```php
   if ($matched !== null
       && \method_exists($matched, 'isActive')
       && !$matched->isActive()
   ) {
       // FR-006: blocked/inactive account fails closed — indistinguishable
       // from an unknown token. Duck-typed: AccountInterface has no status
       // member; User::isActive() is the canonical liveness accessor and
       // an mcp→user manifest edge for one method check is not warranted
       // (mission request-surface-hardening research D4).
       return null;
   }
   return $matched;
   ```
2. New `BearerTokenAuthHardeningTest` (separate file — the existing test's fixture style uses plain `AccountInterface` stubs; the new cases need richer anonymous classes):
   - blocked: account stub with `isActive(): false` → `authenticate()` null (SC-003);
   - active: `isActive(): true` → account returned;
   - no method: plain `AccountInterface` stub → returned (custom implementations own liveness, contract §6);
   - indistinguishability: blocked-token result `===` unknown-token result (both null; no exception, no distinct state);
   - numeric token: map `['12345' => $account]` (becomes int key) authenticates via `Bearer 12345` — pins the `(string)` cast;
   - match position: token matching the first and the last entry of a 3-entry map both authenticate (pins the no-early-exit refactor's correctness, not its timing);
   - NFR-003 note in the test docblock: the check is an in-memory read; no storage/DB fixture exists in this test by construction.
   Use anonymous classes implementing `AccountInterface` (+ an `isActive()` method where needed) — `createMock()` cannot add non-interface methods.

**Validation**: `./vendor/bin/phpunit packages/mcp/tests/Unit/Auth/ --no-progress`.

### T009 — DatabaseBootstrapper resolution + docroot warning

**Files**: `packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`, `packages/foundation/src/Kernel/AbstractKernel.php`

1. `boot()` gains a trailing `?LoggerInterface $logger = null` (Waaseyaa logger; resolve to `NullLogger` internally). `AbstractKernel:197` passes `$this->logger`.
2. In `resolvePath()`, after the existing precedence selection and BEFORE the production-guard / mkdir logic, absolutize:
   ```php
   $dbPath = $this->absolutize($dbPath, $projectRoot);
   ```
   with classification per data-model.md: pass through `:memory:`, leading `/`, drive-letter (`preg_match('/^[A-Za-z]:[\\\\\/]/', …)`), UNC (`str_starts_with($p, '\\\\')`); otherwise strip a leading `./` and prefix `rtrim($projectRoot, '/\\') . '/'`. Climbing `../` values are relatives — concatenate, do not collapse here.
3. Docroot warning (FR-008), after resolution: lexically normalize both the resolved path and `$projectRoot . '/public'` (unify separators, resolve `.`/`..` segments — a small private normalizer; NO `realpath()`, the file may not exist) and if the resolved path is contained in the docroot, `$logger->warning(...)` naming both paths and advising correcting `WAASEYAA_DB`/`config['database']`. `:memory:` never warns. Boot always proceeds.
4. The existing production missing-file guard and non-production `@mkdir` now act on the resolved path — no semantic change to them.

**Validation**: T011 unit matrix; full `packages/foundation/tests/` green (the existing `DatabaseBootstrapperTest` cases pin default/absolute behavior that must not move).

### T010 — CLI parity + display surfaces

**Files**: `packages/cli/src/Handler/DbInitHandler.php`, `HealthReportHandler.php`, `AboutHandler.php`, their three test files

1. `DbInitHandler::resolveDatabasePath()`: absolutize `config['database']` values too (verbatim today, `:162-165`) — route both config and env through `absolutize()`. Upgrade `absolutize()` (`:175-181`) to the same classification as T009 (drive-letter + UNC recognized; today only `/`). Keep the method local (cli L6 may import foundation L0, but a tiny private duplicate matching the contract matrix is acceptable if extracting a shared helper would touch more foundation surface — implementer's choice; if you extract, the helper lives in `DatabaseBootstrapper` as a public static and `DbInitHandler` calls it: same rules, one definition. Prefer the extraction).
2. `HealthReportHandler:115` and `AboutHandler:44`: display the resolved path (same resolution; these handlers have `$projectRoot` available — check their constructors; if one lacks it, compute via the existing handler context, do not add new wiring beyond a ctor param default-compatible change).
3. Extend the three handler tests minimally: relative-config case for db:init (`--dry-run` reports the project-root path), resolved display values for health/about.

**Validation**: `./vendor/bin/phpunit packages/cli/tests/Unit/Handler/ --no-progress`.

### T011 — Resolution/warning unit matrix + DbPath integration test

**Files**: `packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php`, `tests/Integration/DbPath/DbPathResolutionTest.php` (NEW)

1. Unit matrix (data-model.md table, env via `putenv` like the existing tests — restore in `finally`/tearDown): relative env, relative config, `./`-prefixed, climbing `../`, leading-`/` absolute untouched, drive-letter untouched, UNC untouched, `:memory:` untouched, unset default byte-identical. Warning cases with a spy logger: resolved-inside-`public/` warns once; outside doesn't; `:memory:` doesn't; no logger → no error.
2. Integration `DbPathResolutionTest` (`#[CoversNothing]`, namespace per `tests/Integration` convention): build a temp project dir (`sys_get_temp_dir() . '/waaseyaa_dbpath_' . uniqid()`) containing `public/` and `storage/`; set `WAASEYAA_DB=./storage/test.sqlite`; `chdir()` into the temp project's `public/` (docroot CWD — restore original CWD in `finally`); then:
   - HTTP-shaped: `new DatabaseBootstrapper()->boot($tempProjectRoot, [...])` (projectRoot as the front controller would derive it) → create a table, insert a row;
   - CLI-shaped: a second `boot()` with the same projectRoot (as `bin/waaseyaa` would compute from a project-root CWD) → read the row back;
   - assert both touched `{tempProject}/storage/test.sqlite`, the row round-trips, and **no** `*.sqlite` exists anywhere under `{tempProject}/public/` (SC-004's "no stray database").
   - one more case: `WAASEYAA_DB=./public/oops.sqlite` → boots, spy logger captured exactly one warning (FR-008 end-to-end).
   Clean up the temp dir in `tearDown`. Use `APP_ENV=testing` in the config array so the production guard stays out of the way.

**Validation**:

```bash
./vendor/bin/phpunit packages/mcp/tests/Unit/Auth/ packages/foundation/tests/Unit/Kernel/Bootstrap/ packages/cli/tests/Unit/Handler/ tests/Integration/DbPath/ --no-progress
composer phpstan
composer cs-check
bin/check-package-layers
bin/check-dead-code
```

## Edge cases & risks (from the plan premortem)

- **A deployment that *wanted* CWD-relative resolution** changes behavior — that is the bug class being killed; WP03's CHANGELOG line covers the consumer message. Do not add an opt-out flag (C-004).
- **Numeric-string tokens** become int keys; without the `(string)` cast `hash_equals()` throws a TypeError on the first numeric token — the test pins it.
- **Timing assertions don't belong in tests** — pin the *structure* (no early exit verified by match-position behavior + code review), never wall-clock timing.
- **`putenv`/`chdir` leakage** between tests: always restore in `finally`/tearDown; the existing `DatabaseBootstrapperTest` shows the `putenv('WAASEYAA_DB')` reset pattern.
- **Windows separators** in the containment check: normalize both sides before comparing; the unit matrix includes backslash forms even though CI is Linux — the normalizer is pure string logic.
- **`DbInitHandler` minimal-console note** (`:27` comment): db:init deliberately avoids the bootstrapper's production guard; your parity change touches only path *classification*, not its guard-avoidance flow.

## Definition of Done

- [ ] All five subtasks complete; the validation suite above green; existing `BearerTokenAuthTest` (7 tests) and existing `DatabaseBootstrapperTest` cases green UNCHANGED.
- [ ] Contract `bearer-and-dbpath.md` clauses 1–19 each verifiably hold.
- [ ] Blocked-token request → 401 indistinguishable from wrong token (SC-003); zero added DB queries (NFR-003 — no new storage usage anywhere in the diff).
- [ ] With `WAASEYAA_DB=./storage/waaseyaa.sqlite` and docroot CWD: HTTP-shaped and CLI-shaped boots use the same file; nothing under `public/` (SC-004).
- [ ] Absolute paths, `:memory:`, and the unset default resolve byte-identically to pre-mission (scenario 5).
- [ ] `composer phpstan`, `composer cs-check`, `bin/check-package-layers`, `bin/check-dead-code` clean; zero composer.json changes; no changes outside `owned_files`.

## Reviewer guidance

- `BearerTokenAuth` diff: confirm no `break` in the scan, the `(string)` cast, a single return after the loop, and NO import of any `Waaseyaa\User\*` type — the duck-typed `method_exists` is research D4's reviewed decision; reject `instanceof User`.
- Confirm `getTokens()` and the prefix handling are byte-identical (admin fingerprinting + the 7 pinned behaviors).
- `DatabaseBootstrapper`: resolution must run for BOTH config- and env-sourced values (the config path is the easy miss — `DbInitHandler` proves it diverges today); the unset default must remain the same concatenation as before, not routed through new logic that could alter it.
- Verify the warning cannot throw (logger nullable, normalizer pure) and that `AbstractKernel`'s only change is passing `$this->logger`.
- Run the DbPath integration test locally and `ls` the temp docroot yourself — SC-004's "no stray database" is the mission's headline fix for #1650.

## Activity Log

- 2026-06-12T00:00:00Z – spec-kitty.tasks – created
