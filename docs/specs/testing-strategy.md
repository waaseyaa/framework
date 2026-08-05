# Testing Strategy (Framework)

## Goals

- **Fast feedback:** local pre-push hooks run quick architecture gates; CI runs the complete unit suite.
- **Integration where contracts matter:** HTTP kernel, routing, entity storage, and multi-package flows use SQLite (`DBALDatabase::createSqlite()` or project fixtures) under `tests/Integration/` or package integration suites.
- **No network in unit tests:** Mock HTTP, mail, and external services; reserve real I/O for explicit integration cases.

## Layers

| Layer | Tooling | Scope |
|-------|---------|--------|
| Unit | PHPUnit 10.5, `#[Test]` attributes | Single class, deterministic collaborators |
| Integration | PHPUnit, in-memory SQLite | Kernel boot, migrations, route dispatch smoke |
| Admin SPA | Vitest (`packages/admin`) | Plugins, composables; stub `useRuntimeConfig` / `$fetch` |
| E2E | Playwright (`packages/admin`) | Requires Nuxt + PHP backend; run from main repo |

## Conventions

- Use `#[CoversClass]` / `#[CoversNothing]` per project rules.
- Prefer real value objects over heavy mocks for logs and DTOs.
- When adding packages, register `autoload-dev` PSR-4 namespaces in the **root** `composer.json` dev autoload map so CI discovers tests.

## CI / Hooks

- **Pre-commit:** the tracked project hook runs the code-style check when PHP is staged.
- **Pre-push:** the tracked project hook runs Composer policy, Symfony import, and package-layer gates sequentially. Spec drift is advisory locally and blocking in CI.
- **Full gate:** `composer verify` is the canonical complete local gate. CI is authoritative for published revisions.
- **Installation:** `composer hooks:install` installs small worktree-aware shims; `composer hooks:doctor` verifies them. Unknown user hooks are never overwritten.
