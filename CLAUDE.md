# Waaseyaa

## Project Structure
- Monorepo: 29 PHP packages in `packages/`, 3 meta-packages, 1 JS admin SPA
- 7-layer architecture (Foundation → Core Data → Services → Content Types → API → AI → Interfaces)
- Each package has its own `composer.json` with path repository references
- Root `composer.json` uses `@dev` constraints for all waaseyaa/* packages
- Authorization pipeline in `public/index.php`: SessionMiddleware → AuthorizationMiddleware. Session always sets `_account` on request; authorization reads it.
- Route access control via route options: `_public`, `_permission`, `_role`, `_gate` — checked by `AccessChecker`

## Commands
- `./vendor/bin/phpunit --configuration phpunit.xml.dist` — run all tests (do NOT use `-v`, PHPUnit 10.5 rejects it)
- `./vendor/bin/phpunit --filter Phase10` — run tests for a specific phase
- `./vendor/bin/phpunit --testsuite Unit` — unit tests only
- `bin/waaseyaa` — CLI entry point (SQLite + file config)

## Code Style
- PHP 8.3+, `declare(strict_types=1)` in every file
- Namespace pattern: `Waaseyaa\PackageName\` (e.g., `Waaseyaa\Entity\`, `Waaseyaa\AI\Schema\`)
- Test namespace: `Waaseyaa\PackageName\Tests\Unit\` or `Waaseyaa\Tests\Integration\PhaseN\`
- PHPUnit 10.5 attributes: `#[Test]`, `#[CoversClass(...)]`, `#[CoversNothing]` for integration tests
- Symfony 7.x components (Console, EventDispatcher, Routing, Validator, Uid, Yaml, Messenger)
- Named constructor parameters: `new EntityType(id: 'node', label: 'Content', ...)`
- `final class` by default for concrete implementations
- Admin SPA: Nuxt 3 + Vue 3 + TypeScript. Composables in `packages/admin/app/composables/`, i18n in `packages/admin/app/i18n/en.json`
- Frontend entry point: `public/index.php` (PHP built-in server front controller)

## Architecture Gotchas
- **Entity subclass constructors**: User, Node etc. only accept `(array $values)` and hardcode entityTypeId/entityKeys. SqlEntityStorage uses reflection to detect constructor shape.
- **Dual-state bug pattern**: When data can come from two sources (e.g., attribute vs registry), always use one canonical source. Found repeatedly in ComponentRenderer, Pipeline, entity values.
- **PDO fetch mode**: PdoDatabase sets FETCH_ASSOC to avoid duplicate numeric-indexed columns.
- **_data JSON blob**: SqlSchemaHandler adds a `_data` TEXT column. SqlEntityStorage::splitForStorage() puts non-schema values into it as JSON; mapRowToEntity() merges them back on load.
- **PascalCase conversion**: Use `str_replace('_', '', ucwords($name, '_'))` not `ucfirst()`.
- **InMemoryEntityStorage** (`Waaseyaa\Api\Tests\Fixtures\`) — use for tests. SqlEntityStorage for real storage.
- **EntityTypeManager** takes `(EventDispatcher, callable $storageFactory)` where factory receives `EntityType $definition`.
- **EntityEvent uses public properties**: `$event->entity` and `$event->originalEntity` are public readonly — no getter methods. Common mistake: `$event->getEntity()`.
- **DatabaseInterface vs PdoDatabase**: `DatabaseInterface` does NOT have `getPdo()`. If raw PDO is needed, type-hint `PdoDatabase` directly. Prefer using query builder (`select()`, `insert()`, `delete()`) over raw PDO when possible.
- **LIKE wildcard escaping**: `PdoSelect` appends `ESCAPE '\'` for LIKE/NOT LIKE operators. When building LIKE patterns in `SqlEntityQuery`, escape `%` and `_` in user input with `str_replace(['%', '_'], ['\\%', '\\_'], $value)`.
- **JSON symmetry**: Always pair `json_encode(..., JSON_THROW_ON_ERROR)` with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent `null` on corrupt data.
- **Best-effort side effects**: Event listeners for non-critical operations (broadcasting, logging, cache invalidation) should wrap in try-catch and log via `error_log()` to avoid crashing the primary request.
- **Final classes can't be mocked**: PHPUnit `createMock()` fails on `final class`. Use real instances with temp directories (e.g., `sys_get_temp_dir() . '/waaseyaa_test_' . uniqid()`) instead.
- **Atomic file writes**: Cache files must use write-to-temp-then-rename (`file_put_contents($tmp)` then `rename($tmp, $target)`) to prevent serving partial writes.
- **No psr/log**: Project does not use `psr/log`. For best-effort logging (e.g., in event listeners), use `error_log()`.
- **Middleware interface naming**: Handler interfaces follow `{Type}HandlerInterface` pattern (HttpHandlerInterface, EventHandlerInterface, JobHandlerInterface). Middleware follows `{Type}MiddlewareInterface`.
- **Entity enforceIsNew()**: When creating entities with pre-set IDs (e.g., `new User(['uid' => 2])`), call `$entity->enforceIsNew()` before `save()`. Otherwise `isNew()` returns false, SqlEntityStorage tries UPDATE instead of INSERT, and silently affects 0 rows.
- **Layer discipline for imports**: Foundation (layer 1) must never import from higher layers. When cross-layer attribute scanning is needed, use string constants instead of `::class` references (e.g., `private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute'`). `ReflectionClass::getAttributes()` accepts string class names.
- **Avoid circular package deps**: Access owns `AccountInterface`; User owns `AnonymousUser`. Access must not depend on User. Middleware needing an account should type-hint `AccountInterface`, not concrete `AnonymousUser`.
- **php://input is single-read**: `HttpRequest::createFromGlobals()` consumes `php://input`. For subsequent body reads, use `$httpRequest->getContent()`, not `file_get_contents('php://input')`.
- **Backward-compatible cache evolution**: When adding new properties to cached manifests/configs, make them optional in deserialization (use `$data['key'] ?? []`) to avoid breaking old cached files.

## Testing
- Integration tests in `tests/Integration/PhaseN/` — one directory per implementation phase
- Unit tests in `packages/*/tests/Unit/`
- Use `CommandTester` from Symfony Console for CLI command tests
- Use `ArrayLoader` for Twig tests (no filesystem needed)
- All storage can be in-memory: MemoryStorage (config), MemoryBackend (cache), InMemoryEntityStorage (entities), PdoDatabase::createSqlite() (SQL with :memory:)
- Test cache file handling with corrupt files (`<?php throw new \RuntimeException("corrupt");`) and wrong return types (`<?php return "not an array";`) to verify recovery paths

## Environment
- `WAASEYAA_DB` — SQLite database path (default: `./waaseyaa.sqlite`)
- `WAASEYAA_CONFIG_DIR` — config sync directory (default: `./config/sync`)
