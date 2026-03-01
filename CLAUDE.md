# Waaseyaa

## Project Structure
- Monorepo: 29 PHP packages in `packages/`, 3 meta-packages, 1 JS admin SPA
- 7-layer architecture (Foundation → Core Data → Services → Content Types → API → AI → Interfaces)
- Each package has its own `composer.json` with path repository references
- Root `composer.json` uses `@dev` constraints for all waaseyaa/* packages

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

## Architecture Gotchas
- **Entity subclass constructors**: User, Node etc. only accept `(array $values)` and hardcode entityTypeId/entityKeys. SqlEntityStorage uses reflection to detect constructor shape.
- **Dual-state bug pattern**: When data can come from two sources (e.g., attribute vs registry), always use one canonical source. Found repeatedly in ComponentRenderer, Pipeline, entity values.
- **PDO fetch mode**: PdoDatabase sets FETCH_ASSOC to avoid duplicate numeric-indexed columns.
- **_data JSON blob**: SqlSchemaHandler adds a `_data` TEXT column. SqlEntityStorage::splitForStorage() puts non-schema values into it as JSON; mapRowToEntity() merges them back on load.
- **PascalCase conversion**: Use `str_replace('_', '', ucwords($name, '_'))` not `ucfirst()`.
- **InMemoryEntityStorage** (`Waaseyaa\Api\Tests\Fixtures\`) — use for tests. SqlEntityStorage for real storage.
- **EntityTypeManager** takes `(EventDispatcher, callable $storageFactory)` where factory receives `EntityType $definition`.

## Testing
- Integration tests in `tests/Integration/PhaseN/` — one directory per implementation phase
- Unit tests in `packages/*/tests/Unit/`
- Use `CommandTester` from Symfony Console for CLI command tests
- Use `ArrayLoader` for Twig tests (no filesystem needed)
- All storage can be in-memory: MemoryStorage (config), MemoryBackend (cache), InMemoryEntityStorage (entities), PdoDatabase::createSqlite() (SQL with :memory:)

## Environment
- `WAASEYAA_DB` — SQLite database path (default: `./waaseyaa.sqlite`)
- `WAASEYAA_CONFIG_DIR` — config sync directory (default: `./config/sync`)
