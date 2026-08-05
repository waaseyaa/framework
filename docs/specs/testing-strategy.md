# Testing Strategy (Framework)

## Goals

- **Fast feedback:** local pre-push hooks run quick architecture gates; CI runs the complete unit suite.
- **Integration where contracts matter:** HTTP kernel, routing, entity storage, and multi-package flows use SQLite (`DBALDatabase::createSqlite()` or project fixtures) under `tests/Integration/` or package integration suites.
- **No network in unit tests:** Mock HTTP, mail, and external services; reserve real I/O for explicit integration cases.
- **Deterministic replay:** Clock, random, filesystem, database, process, port,
  and global state are explicit test inputs. A CI failure must expose enough
  seed and environment information to reproduce its execution order locally.
- **Measured effectiveness:** Coverage and mutation results are evidence about
  untested behavior, not targets to game. Changed code may not reduce the
  established package baseline without an explicit review.

## Layers

| Layer | Tooling | Scope |
|-------|---------|--------|
| Unit | PHPUnit 10.5, `#[Test]` attributes | Single class, deterministic collaborators |
| Integration | PHPUnit, in-memory SQLite | Kernel boot, migrations, route dispatch smoke |
| Admin SPA | Vitest (`packages/admin`) | Plugins, composables; stub `useRuntimeConfig` / `$fetch` |
| E2E | Playwright (`packages/admin`) | Requires Nuxt + PHP backend; run from main repo |

The behavioral taxonomy is:

- **Unit:** one behavior with no filesystem, network, process, sleep, or
  persistent database dependency.
- **Contract:** a public cross-package contract, independent of one concrete
  implementation.
- **Integration:** real framework collaborators with an isolated SQLite
  database and/or temporary filesystem.
- **Consumer/application:** skeleton, packaged-form, or external application
  compatibility.
- **Architecture:** a static repository or dependency invariant.
- **E2E:** a real browser crossing the production-shaped frontend/backend
  boundary.

## Conventions

- Use `#[CoversClass]` / `#[CoversNothing]` per project rules.
- Prefer real value objects over heavy mocks for logs and DTOs.
- When adding packages, register `autoload-dev` PSR-4 namespaces in the **root** `composer.json` dev autoload map so CI discovers tests.
- Prefer injected clocks and seeded generators. Do not wait for wall-clock time
  when an observable state transition can be driven directly.
- A helper in `waaseyaa/testing` is canonical only when it models a real
  framework contract and has representative consumers outside that package.
  Request-shaped arrays, raw PDO shortcuts, and identity-shaped arrays are not
  substitutes for Symfony requests, the framework DBAL, or decision
  principals.
- Keep PHPUnit, Vitest, and Playwright as the canonical tools. A second test
  syntax or mocking framework requires a demonstrated capability gap.

## CI / Hooks

- **Pre-commit:** the tracked project hook runs the code-style check when PHP is staged.
- **Pre-push:** the tracked project hook runs Composer policy, Symfony import, and package-layer gates sequentially. Spec drift is advisory locally and blocking in CI.
- **Full gate:** `composer verify` is the canonical complete local gate. CI is authoritative for published revisions.
- **Installation:** `composer hooks:install` installs small worktree-aware shims; `composer hooks:doctor` verifies them. Unknown user hooks are never overwritten.
- **Inventory:** `composer test:inventory` reports Git-tracked-only PHP,
  PHPUnit, Vitest, Playwright, nondeterminism, and helper-adoption signals.
  These counts are inputs to review, not quality scores.

## Modernization policy

- Run a supported PHPUnit major for the repository's minimum PHP version.
- Keep the 20 PHPUnit configurations mechanically conformant on schema and
  required strictness while preserving package-specific suites and bootstrap
  needs.
- Publish real PHP and Vitest coverage artifacts before enforcing baselines;
  never advertise an artifact produced by a `--no-coverage` run.
- Introduce random-order execution with a logged replayable seed before
  enabling PHP test parallelization.
- Pilot mutation testing on bounded security/editorial packages. Blocking MSI
  thresholds follow stable evidence; they are not guessed in advance.
- Track first-attempt browser failures even when Playwright retry succeeds.

The baseline evidence and staged exit measures live in
`docs/audits/2026-08-05-test-quality-modernization.md`.
