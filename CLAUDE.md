# Waaseyaa

## Project Structure
- Monorepo: 29 PHP packages in `packages/`, 3 meta-packages, 1 JS admin SPA
- 7-layer architecture (Foundation → Core Data → Content Types → Services → API → AI → Interfaces)
- Each package has its own `composer.json` with path repository references
- Root `composer.json` uses `@dev` constraints for all waaseyaa/* packages
- Authorization pipeline in `public/index.php`: SessionMiddleware → AuthorizationMiddleware. Session always sets `_account` on request; authorization reads it.
- Route access control via route options: `_public`, `_permission`, `_role`, `_gate` — checked by `AccessChecker`
- Field-level access: `FieldAccessPolicyInterface` (companion to `AccessPolicyInterface`). Classes must implement both — `EntityAccessHandler` finds field policies via `instanceof` check. Open-by-default: Neutral = accessible, only Forbidden restricts.
- Access result semantics differ by level: entity-level uses `isAllowed()` (deny unless granted), field-level uses `!isForbidden()` (allow unless denied). This asymmetry is intentional.

## Orchestration

When working on files matching these patterns, retrieve the spec for deep context. **Orchestration skills are not Skill-tool skills**: The `waaseyaa:*` entries below are conceptual — use `waaseyaa_get_spec` / `waaseyaa_search_specs` MCP tools to retrieve the context they reference, not the `Skill` tool.

| File pattern | Specialist skill | Cold memory spec |
|---|---|---|
| `packages/entity/*`, `packages/entity-storage/*`, `packages/field/*`, `packages/config/*` | `waaseyaa:entity-system` | `docs/specs/entity-system.md` |
| `packages/access/*`, `packages/user/src/Middleware/*` | `waaseyaa:access-control` | `docs/specs/access-control.md`, `docs/specs/field-access.md` |
| `packages/api/*`, `packages/routing/*` | `waaseyaa:api-layer` | `docs/specs/api-layer.md` |
| `packages/admin/*` | `waaseyaa:admin-spa` | `docs/specs/admin-spa.md` |
| `packages/ai-*/*` | `waaseyaa:ai-integration` | `docs/specs/ai-integration.md` |
| `packages/foundation/*`, `packages/cache/*`, `packages/database-legacy/*`, `packages/plugin/*` | `waaseyaa:infrastructure` | `docs/specs/infrastructure.md`, `docs/specs/package-discovery.md` |
| `packages/mcp/*` | `waaseyaa:mcp-endpoint` | `docs/specs/mcp-endpoint.md` |
| `public/index.php`, `packages/*/src/Middleware/*` | `waaseyaa:middleware-pipeline` | `docs/specs/middleware-pipeline.md` |

Use `waaseyaa_search_specs` MCP tool to find specs affected by a change when the mapping isn't obvious.

**MCP tools available:**
- `waaseyaa_list_specs` — list all subsystem specs with descriptions
- `waaseyaa_get_spec <name>` — retrieve full spec content (e.g., `waaseyaa_get_spec entity-system`)
- `waaseyaa_search_specs <query>` — keyword search across all specs (e.g., `waaseyaa_search_specs EntityAccessHandler`)

## Layer Architecture

| Layer | Name | Packages |
|---|---|---|
| 0 | Foundation | foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, state, validation |
| 1 | Core Data | entity, entity-storage, access, user, config, field |
| 2 | Content Types | node, taxonomy, media, path, menu |
| 3 | Services | workflows |
| 4 | API | api, routing |
| 5 | AI | ai-schema, ai-agent, ai-pipeline, ai-vector |
| 6 | Interfaces | cli, admin, mcp, ssr, telescope |

**Rule:** Packages can only import from their own layer or lower. Upward communication via DomainEvents.

**Exemption:** The `Kernel/` classes in Foundation (`AbstractKernel`, `HttpKernel`, `ConsoleKernel`) are application bootstrappers that wire all layers together. They intentionally import from all layers. This is acceptable because kernels are entry-point orchestrators, not reusable library code — no other package imports from them.

## Operation Checklists

**Adding an entity type:**
1. Define `EntityType` with id, label, entity keys, entity class
2. Create entity class extending `EntityBase` — constructor takes `(array $values)`, hardcodes `entityTypeId` and `entityKeys`
3. Register in `EntityTypeManager` via service provider's `register()` method
4. Create storage schema via `SqlSchemaHandler` — define columns, `_data` blob is automatic
5. Add `AccessPolicyInterface` (+ `FieldAccessPolicyInterface` if field-level control needed)
6. Add API routes in `RouteBuilder`, wire controller, set route access options (`_gate` for entity access)
7. Test: use `InMemoryEntityStorage` or `PdoDatabase::createSqlite()` for in-memory testing

**Adding an access policy:**
1. Create class implementing `AccessPolicyInterface` (add `FieldAccessPolicyInterface` if field access needed — same class, intersection type)
2. Register via `#[PolicyAttribute(entityType: 'entity_type_id')]` attribute on the class
3. Implement `access()` returning `AccessResult` — use `::allowed()`, `::neutral()`, `::forbidden()`
4. For field access: implement `fieldAccess()` — Neutral = accessible (open-by-default), only Forbidden restricts
5. Test with anonymous classes implementing both interfaces (PHPUnit `createMock()` can't mock intersection types)
6. Run `waaseyaa optimize:manifest` (or restart dev server) to pick up the new policy

**Adding an API endpoint:**
1. Add route in `RouteBuilder` with access options (`_public`, `_permission`, `_role`, or `_gate`)
2. Implement controller method following `JsonApiController` CRUD patterns
3. Wire access via route options — `AccessChecker` evaluates them from the matched route
4. For entity endpoints: use `ResourceSerializer` with paired nullable `?EntityAccessHandler` + `?AccountInterface`
5. Add to `SchemaPresenter` if JSON Schema output is needed — set `x-access-restricted` for view-only fields

**Adding middleware:**
1. Implement `HttpMiddlewareInterface` (or `EventMiddlewareInterface` / `JobMiddlewareInterface`)
2. Add `#[AsMiddleware(priority: N)]` attribute — higher priority runs first (outer onion layer)
3. Middleware is auto-discovered by `PackageManifestCompiler` via attribute scanning
4. Follow handler naming: `{Type}HandlerInterface` for handler, `{Type}MiddlewareInterface` for middleware

**Adding a service provider:**
1. Create class extending `ServiceProvider` in the package's root namespace
2. `register()` — bind interfaces to implementations, register entity types, set up factories
3. `boot()` — subscribe to events, register routes, warm caches (after all providers registered)
4. Add `extra.waaseyaa.providers` to the package's `composer.json` for auto-discovery

## Codified Context

This project uses three-tier codified context infrastructure ([arxiv.org/abs/2602.20478](https://arxiv.org/abs/2602.20478)):
- **Tier 1 (Constitution):** This CLAUDE.md file — loaded every session, orchestration triggers, checklists
- **Tier 2 (Skills):** Domain specialist skills in `skills/waaseyaa/` — loaded on demand per the orchestration table
- **Tier 3 (Specs):** Subsystem specs in `docs/specs/` — retrieved via `waaseyaa_*` MCP tools for deep context

Design docs in `docs/plans/` are session artifacts (implementation history). Specs in `docs/specs/` are enduring architectural knowledge (kept current). When refactoring a subsystem, update its spec — run `tools/drift-detector.sh` to find stale specs.

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
- **EntityTypeManager** takes `(EventDispatcherInterface, ?\Closure $storageFactory = null)` where factory receives `EntityTypeInterface $definition`.
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
- **Layer discipline for imports**: Foundation (layer 0) must never import from higher layers. When cross-layer attribute scanning is needed, use string constants instead of `::class` references (e.g., `private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute'`). `ReflectionClass::getAttributes()` accepts string class names.
- **Avoid circular package deps**: Access owns `AccountInterface`; User owns `AnonymousUser`. Access must not depend on User. Middleware needing an account should type-hint `AccountInterface`, not concrete `AnonymousUser`.
- **php://input is single-read**: `HttpRequest::createFromGlobals()` consumes `php://input`. For subsequent body reads, use `$httpRequest->getContent()`, not `file_get_contents('php://input')`.
- **Backward-compatible cache evolution**: When adding new properties to cached manifests/configs, make them optional in deserialization (use `$data['key'] ?? []`) to avoid breaking old cached files.
- **Avoid double `$storage->create()` in access checks**: When checking field access before persisting a new entity, create once and reuse for both the access check and the save. Don't create a throwaway temp entity.
- **Paired nullable parameters**: `ResourceSerializer::serialize()` and `SchemaPresenter::present()` accept `?EntityAccessHandler` + `?AccountInterface`. Both must be non-null or both null — only two of four states are meaningful. The guard pattern is `if ($handler !== null && $account !== null)`.
- **SchemaPresenter `x-access-restricted`**: JSON Schema extension marking fields viewable but not editable. The admin SPA reads this to show disabled widgets instead of hiding the field. Distinct from system `readOnly` (id, uuid) which hides the field from forms entirely.
- **Stale specs cause bad code**: When refactoring a subsystem, update the relevant `docs/specs/` file. Stale specs cause agents to generate code conflicting with recent changes. Run `tools/drift-detector.sh` to find affected specs.
- **`sendJson()` in `index.php` exits**: The front controller's `sendJson()` helper sends a JSON:API response and calls `exit`. Code after a `sendJson()` call is unreachable — no `return` needed.
- **Account sentinel IDs**: `AnonymousUser` uses `id: 0`, `DevAdminAccount` uses `PHP_INT_MAX`. Never use `1` or other low integers for non-real accounts — they collide with auto-increment UIDs.
- **`PackageManifestCompiler` prefers optimized autoloader**: `scanClasses()` tries `autoload_classmap.php` first, then falls back to PSR-4 directory scanning with a warning log. The classmap under default `composer install` has entries (Composer internals, polyfill stubs) but no `Waaseyaa\` classes — the fallback triggers on missing Waaseyaa entries, not an empty classmap. Run `composer dump-autoload --optimize` for faster, more reliable discovery.
- **Dev-mode SAPI guard**: Use `PHP_SAPI === 'cli-server'` to gate dev-only behavior (e.g., `DevAdminAccount` in `index.php`). Classes with constructor guards must also allow `cli` SAPI for PHPUnit to instantiate them.
- **CORS origins configurable**: `HttpKernel::handleCors()` reads `cors_origins` from `config/waaseyaa.php`. Defaults to `localhost:3000` and `127.0.0.1:3000`. Mismatched origins are logged. If Nuxt dev server binds to a non-standard port, add it to the config array.
- **SchemaController field definitions**: `SchemaController::show()` passes `$entityType->getFieldDefinitions()` to `SchemaPresenter::present()`. Field definitions are registered per entity type via the `fieldDefinitions:` constructor param on `EntityType`.
- **`discoverAccessPolicies()` constructor heuristic**: `ConfigEntityAccessPolicy` takes `array $entityTypeIds` as a required constructor parameter (from `#[PolicyAttribute]`). The reflection-based heuristic in `AbstractKernel::discoverAccessPolicies()` that passes entity types to constructors with required params exists for this reason — do not remove it.
- **`toMachineName()` can return empty string**: Labels with only special characters (e.g. `"!!!"`) produce empty machine names after regex replacement and trim. `JsonApiController::store()` guards against this with a 422 response. Any caller of `toMachineName()` must validate the result.
- **Kernel boot flag ordering**: `AbstractKernel::boot()` sets `$this->booted = true` *after* all initialization steps succeed. Setting it before would create a zombie state where boot failure prevents retry. If adding new boot steps, add them before the flag assignment.

## Testing
- Integration tests in `tests/Integration/PhaseN/` — one directory per implementation phase
- Unit tests in `packages/*/tests/Unit/`
- Use `CommandTester` from Symfony Console for CLI command tests
- Use `ArrayLoader` for Twig tests (no filesystem needed)
- All storage can be in-memory: MemoryStorage (config), MemoryBackend (cache), InMemoryEntityStorage (entities), PdoDatabase::createSqlite() (SQL with :memory:)
- Test cache file handling with corrupt files (`<?php throw new \RuntimeException("corrupt");`) and wrong return types (`<?php return "not an array";`) to verify recovery paths
- Test access policies with anonymous classes implementing intersection types (`AccessPolicyInterface & FieldAccessPolicyInterface`) — PHPUnit `createMock()` can't mock intersection types, so use real anonymous classes with inline logic
- Frontend tests: `cd packages/admin && npm test` — Vitest with `@nuxt/test-utils` nuxt environment; 55 tests covering composables and components
- Frontend build verification: `cd packages/admin && npm run build` — TypeScript compilation check
- Frontend E2E: `cd packages/admin && npm run test:e2e` — Playwright specs in `e2e/`; requires `nuxt dev` on port 3000

## Environment
- `WAASEYAA_DB` — SQLite database path (default: `./waaseyaa.sqlite`)
- `WAASEYAA_CONFIG_DIR` — config sync directory (default: `./config/sync`)
