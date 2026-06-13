# v1.0 Contract Lock Prerequisites Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare the framework for v1.0 contract lock by upgrading to PHP 8.4 with CI quality gates (#299), then decomposing HttpKernel (#297) and McpController (#298) into testable, maintainable components.

**Architecture:** Three sequential phases. Phase 1 (#299) establishes the CI safety net (PHPStan, CS-Fixer, coverage) that protects the refactoring in Phases 2-3. Phase 2 (#297) extracts 7 handler classes from HttpKernel (2262L → ~800L). Phase 3 (#298) extracts 6 tool classes from McpController (1650L → ~400L). Each extraction follows the same pattern: extract class → add unit tests → wire into orchestrator → verify no regressions.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, PHPStan 1.10, PHP-CS-Fixer 3.x, GitHub Actions

---

## Dependency Graph

```
#299 PHP 8.4 + CI Quality Gates
  │
  ├──► #297 Decompose HttpKernel (2262L)
  │      Phase 1: CorsHandler + CacheConfigResolver (isolated, parallel-safe)
  │      Phase 2: BuiltinRouteRegistrar + EventListenerRegistrar
  │      Phase 3: DiscoveryApiHandler → SsrPageHandler → ControllerDispatcher
  │
  └──► #298 Decompose McpController (1650L)
         Phase 1: McpResponseFormatter + McpReadCache (shared infrastructure)
         Phase 2: McpEntityTools + McpDiscoveryTools (simple tools)
         Phase 3: McpTraversalTools + McpEditorialTools (complex tools)
```

#297 and #298 are independent of each other — they can run in parallel after #299 completes. Within each, extraction phases are sequential (later phases depend on earlier extractions).

---

## Chunk 1: Issue #299 — PHP 8.4 Bump + CI Quality Gates

### File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `composer.json` | Modify | Bump PHP >=8.4, add php-cs-fixer to require-dev, add composer scripts |
| `.php-cs-fixer.dist.php` | Create | CS-Fixer ruleset (PER-CS2.0, strict_types, ordered imports) |
| `phpstan.neon` | Create | PHPStan config (level 5, scan packages/*/src) |
| `phpunit.xml.dist` | Modify | Add coverage configuration |
| `.github/workflows/ci.yml` | Modify | Add phpstan, cs-fixer, coverage jobs |

### Task 1: Bump PHP to 8.4

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update PHP constraint**

In `composer.json`, change the `require.php` value from `">=8.3"` to `">=8.4"`.

- [ ] **Step 2: Update composer.lock**

Run: `composer update --lock`
Expected: Lock file updated, no errors

- [ ] **Step 3: Verify tests still pass**

Run: `php -v` (confirm 8.4+)
Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All 3942+ tests pass

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore(#299): bump PHP minimum to 8.4"
```

### Task 2: Add PHP-CS-Fixer

**Files:**
- Modify: `composer.json` (add require-dev + scripts)
- Create: `.php-cs-fixer.dist.php`

- [ ] **Step 1: Install PHP-CS-Fixer**

Run: `composer require --dev friendsofphp/php-cs-fixer:^3.50`

- [ ] **Step 2: Create CS-Fixer config**

Create `.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/packages/*/src',
        __DIR__ . '/packages/*/tests',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
```

- [ ] **Step 3: Add composer scripts**

Add to `composer.json` `scripts` section:

```json
"cs-check": "php-cs-fixer fix --dry-run --diff",
"cs-fix": "php-cs-fixer fix"
```

- [ ] **Step 4: Run dry-run to see current violations**

Run: `composer cs-check 2>&1 | tail -5`
Expected: Lists files needing fixes (informational — do NOT auto-fix yet)

- [ ] **Step 5: Commit config only (do not auto-fix existing code)**

```bash
git add .php-cs-fixer.dist.php composer.json composer.lock
git commit -m "chore(#299): add PHP-CS-Fixer config and composer scripts"
```

### Task 3: Configure PHPStan

**Files:**
- Create: `phpstan.neon`
- Modify: `composer.json` (add script)

PHPStan is already in `require-dev`. Just needs config and a CI job.

- [ ] **Step 1: Create PHPStan config**

Create `phpstan.neon`:

```neon
parameters:
    level: 5
    paths:
        - packages/foundation/src
        - packages/entity/src
        - packages/access/src
        - packages/api/src
        - packages/cli/src
        - packages/config/src
        - packages/cache/src
        - packages/field/src
        - packages/user/src
        - packages/routing/src
        - packages/plugin/src
        - packages/mcp/src
    excludePaths:
        - packages/*/tests
    reportUnmatchedIgnoredErrors: false
```

- [ ] **Step 2: Add composer script**

Add to `composer.json` `scripts` section:

```json
"phpstan": "phpstan analyse --memory-limit=512M"
```

- [ ] **Step 3: Run PHPStan and assess baseline**

Run: `composer phpstan 2>&1 | tail -10`

If errors exist, generate a baseline:

Run: `./vendor/bin/phpstan analyse --generate-baseline --memory-limit=512M`

This creates `phpstan-baseline.neon`. Add `includes: [phpstan-baseline.neon]` to `phpstan.neon`.

- [ ] **Step 4: Verify PHPStan passes (with baseline if needed)**

Run: `composer phpstan`
Expected: No errors (or 0 errors beyond baseline)

- [ ] **Step 5: Commit**

```bash
git add phpstan.neon composer.json
git add phpstan-baseline.neon 2>/dev/null  # only if baseline was generated
git commit -m "chore(#299): configure PHPStan level 5 with baseline"
```

### Task 4: Add coverage configuration to PHPUnit

**Files:**
- Modify: `phpunit.xml.dist`

- [ ] **Step 1: Read current phpunit.xml.dist**

Read the file to understand existing structure.

- [ ] **Step 2: Add coverage configuration**

Add a `<coverage>` element to `phpunit.xml.dist` inside `<phpunit>`:

```xml
<coverage>
    <report>
        <clover outputFile="coverage/clover.xml"/>
        <text outputFile="php://stdout" showOnlySummary="true"/>
    </report>
</coverage>
```

Note: PHPUnit 10.5 uses the `<source>` element (already present) to define coverage scope.

- [ ] **Step 3: Verify coverage runs**

Run: `./vendor/bin/phpunit --coverage-text --configuration phpunit.xml.dist 2>&1 | tail -20`

Note: Requires Xdebug or PCOV. If neither is available, the CI job will handle it (PCOV in container). Verify the config is valid even if coverage can't run locally.

- [ ] **Step 4: Commit**

```bash
git add phpunit.xml.dist
git commit -m "chore(#299): add PHPUnit coverage configuration"
```

### Task 5: Add CI workflow jobs

**Files:**
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Read current CI workflow**

Read `.github/workflows/ci.yml` to understand existing job structure.

- [ ] **Step 2: Add phpstan job**

Add after the existing `lint` job:

```yaml
  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          tools: composer:v2
      - run: composer install --no-progress --prefer-dist
      - run: composer phpstan
```

- [ ] **Step 3: Add cs-check job**

```yaml
  cs-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          tools: composer:v2
      - run: composer install --no-progress --prefer-dist
      - run: composer cs-check
```

- [ ] **Step 4: Add coverage to existing test job**

In the existing `test` job, update the `setup-php` step to include `coverage: pcov`, and add a coverage upload step after test execution:

```yaml
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          tools: composer:v2
          coverage: pcov
```

Add after the test run step:

```yaml
      - name: Generate coverage report
        run: ./vendor/bin/phpunit --configuration phpunit.xml.dist --coverage-clover coverage/clover.xml --coverage-text
        if: always()
```

- [ ] **Step 5: Verify CI config is valid YAML**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" && echo "Valid YAML"`
Expected: "Valid YAML"

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "chore(#299): add PHPStan, CS-Fixer, and coverage CI jobs"
```

### Task 6: Verify and close #299

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass

- [ ] **Step 2: Run PHPStan**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 3: Run CS-Fixer dry-run**

Run: `composer cs-check`
Expected: Reports violations (acceptable — existing code not yet fixed)

- [ ] **Step 4: Push and verify CI**

Push to a feature branch and verify all CI jobs run.

---

## Chunk 2: Issue #297 — Decompose HttpKernel (2262L)

### File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `packages/foundation/src/Http/CorsHandler.php` | Create | CORS preflight + origin validation (~70L) |
| `packages/cache/src/CacheConfigResolver.php` | Create | Cache-Control header values from config (~60L) |
| `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` | Create | Route table registration (~150L) |
| `packages/foundation/src/Kernel/EventListenerRegistrar.php` | Create | Event subscriber wiring (~120L) |
| `packages/foundation/src/Http/DiscoveryApiHandler.php` | Create | Discovery/relationship API with caching (~300L) |
| `packages/ssr/src/SsrPageHandler.php` | Create | SSR rendering pipeline (~500L) |
| `packages/foundation/src/Http/ControllerDispatcher.php` | Create | Route→controller dispatch hub (~520L) |
| `packages/foundation/src/Kernel/HttpKernel.php` | Modify | Reduce to ~800L orchestrator |
| `packages/foundation/tests/Unit/Http/CorsHandlerTest.php` | Create | Unit tests |
| `packages/cache/tests/Unit/CacheConfigResolverTest.php` | Create | Unit tests |
| `packages/foundation/tests/Unit/Kernel/BuiltinRouteRegistrarTest.php` | Create | Unit tests |
| `packages/foundation/tests/Unit/Kernel/EventListenerRegistrarTest.php` | Create | Unit tests |
| `packages/foundation/tests/Unit/Http/DiscoveryApiHandlerTest.php` | Create | Unit tests |

### Extraction Order Rationale

1. **CorsHandler + CacheConfigResolver** — Zero cross-dependencies, pure logic, safe parallel extraction
2. **BuiltinRouteRegistrar + EventListenerRegistrar** — Resolve shared `registerBroadcastListeners` first
3. **DiscoveryApiHandler** — Depends on cache config, but self-contained endpoint
4. **SsrPageHandler** — Depends on CacheConfigResolver, biggest extraction
5. **ControllerDispatcher** — Last because it's the hub; extract after all handlers exist

### Task 7: Extract CorsHandler

**Files:**
- Create: `packages/foundation/src/Http/CorsHandler.php`
- Create: `packages/foundation/tests/Unit/Http/CorsHandlerTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Write CorsHandler tests**

Create `packages/foundation/tests/Unit/Http/CorsHandlerTest.php` with tests for:
- `preflightRequestReturnsCorrectHeaders` — OPTIONS request with Origin header returns 204 with CORS headers
- `allowedOriginPassesValidation` — configured origin returns true
- `disallowedOriginFailsValidation` — unconfigured origin returns false
- `nonPreflightRequestIsNotCors` — GET request is not identified as preflight
- `defaultOriginsIncludeLocalhost` — empty config defaults to localhost:3000 and 127.0.0.1:3000

Read HttpKernel.php lines 204-271 to understand the exact method signatures and logic for `handleCors`, `resolveCorsHeaders`, `isOriginAllowed`, `isCorsPreflightRequest`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/CorsHandlerTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Extract CorsHandler class**

Create `packages/foundation/src/Http/CorsHandler.php` — move the 4 CORS methods from HttpKernel into a new `final class CorsHandler`. Constructor takes `array $corsOrigins = []`.

Public methods:
- `handleCors(Request): ?Response` — returns 204 Response for preflights, null otherwise
- `isOriginAllowed(string $origin): bool`

Private methods:
- `resolveCorsHeaders(string $origin): array`
- `isCorsPreflightRequest(Request): bool`

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/CorsHandlerTest.php`
Expected: All pass

- [ ] **Step 5: Wire CorsHandler into HttpKernel**

In HttpKernel, replace the 4 CORS methods with a `CorsHandler` instance. Create it in the constructor or `handle()` method from the existing config. Replace `$this->handleCors($request)` with `$this->corsHandler->handleCors($request)`.

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (no behavior change)

- [ ] **Step 7: Commit**

```bash
git add packages/foundation/src/Http/CorsHandler.php packages/foundation/tests/Unit/Http/CorsHandlerTest.php packages/foundation/src/Kernel/HttpKernel.php
git commit -m "refactor(#297): extract CorsHandler from HttpKernel"
```

### Task 8: Extract CacheConfigResolver

**Files:**
- Create: `packages/cache/src/CacheConfigResolver.php`
- Create: `packages/cache/tests/Unit/CacheConfigResolverTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Write CacheConfigResolver tests**

Tests for:
- `resolveMaxAgeReturnsConfiguredValue`
- `resolveMaxAgeReturnsDefaultWhenNotConfigured`
- `resolveSharedMaxAgeReturnsConfiguredValue`
- `staleWhileRevalidateReturnsConfiguredValue`
- `buildCacheVariantKeyIncludesLangcode`

Read HttpKernel.php lines 1786-1947 to understand the exact methods.

- [ ] **Step 2: Run tests — expect fail**

- [ ] **Step 3: Extract CacheConfigResolver class**

Move the ~8 cache config methods from HttpKernel. Constructor takes `array $config`. All methods are pure — config in, values out.

- [ ] **Step 4: Run tests — expect pass**

- [ ] **Step 5: Wire into HttpKernel, replace method calls**

- [ ] **Step 6: Run full test suite — expect all pass**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor(#297): extract CacheConfigResolver from HttpKernel"
```

### Task 9: Extract BuiltinRouteRegistrar

**Files:**
- Create: `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`
- Create: `packages/foundation/tests/Unit/Kernel/BuiltinRouteRegistrarTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Write tests**

Tests for:
- `registersApiRoutes` — verify expected route patterns are registered
- `registersSsrRoutes` — verify SSR catch-all route
- `registersMediaRoutes` — verify media upload route

Read HttpKernel.php lines 302-476 (`registerRoutes` method).

- [ ] **Step 2-4: TDD cycle**

- [ ] **Step 5: Wire into HttpKernel**

- [ ] **Step 6: Full test suite — expect all pass**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor(#297): extract BuiltinRouteRegistrar from HttpKernel"
```

### Task 10: Extract EventListenerRegistrar

**Files:**
- Create: `packages/foundation/src/Kernel/EventListenerRegistrar.php`
- Create: `packages/foundation/tests/Unit/Kernel/EventListenerRegistrarTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Write tests**

Tests for:
- `registersBroadcastListeners` — verify event subscriptions are wired
- `registersRenderCacheListeners` — verify cache invalidation listeners
- `registersDiscoveryCacheListeners`
- `registersMcpReadCacheListeners`

Read HttpKernel.php lines 449-1784 for the 5 `register*Listeners()` methods.

**Note:** Resolve the `registerBroadcastListeners` duplication between route registrar and event registrar. It should live in EventListenerRegistrar only.

- [ ] **Step 2-4: TDD cycle**

- [ ] **Step 5: Wire into HttpKernel**

- [ ] **Step 6: Full test suite — expect all pass**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor(#297): extract EventListenerRegistrar from HttpKernel"
```

### Task 11: Extract DiscoveryApiHandler

**Files:**
- Create: `packages/foundation/src/Http/DiscoveryApiHandler.php`
- Create: `packages/foundation/tests/Unit/Http/DiscoveryApiHandlerTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Write tests**

Tests for:
- `handlesDiscoveryEndpointWithCaching`
- `buildsCacheKeyFromArguments`
- `sendsJsonResponseWithContractMeta`
- `checksEntityVisibilityForPublicEndpoints`
- `returnsNullForNonDiscoveryRoutes`

Read HttpKernel.php lines 1000-1157 for the 11 discovery methods.

- [ ] **Step 2-4: TDD cycle**

- [ ] **Step 5: Wire into HttpKernel — replace discovery dispatch in `dispatch()` with handler call**

- [ ] **Step 6: Full test suite — expect all pass**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor(#297): extract DiscoveryApiHandler from HttpKernel"
```

### Task 12: Extract SsrPageHandler

**Files:**
- Create: `packages/ssr/src/SsrPageHandler.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Read and understand SSR methods**

Read HttpKernel.php lines 1159-1672 for the 6 SSR methods:
- `handleRenderPage`
- `dispatchAppController`
- `resolveControllerInstance`
- `isPreviewRequested`
- `buildRelationshipRenderContext`
- `resolveRenderLanguageAndAliasPath` + language helpers

- [ ] **Step 2: Extract SsrPageHandler**

Move SSR methods to `SsrPageHandler`. Constructor takes dependencies needed for rendering (entityTypeManager, config, CacheConfigResolver, database, renderCache).

- [ ] **Step 3: Wire into HttpKernel — replace `handleRenderPage()` calls**

- [ ] **Step 4: Full test suite — expect all pass**

- [ ] **Step 5: Commit**

```bash
git commit -m "refactor(#297): extract SsrPageHandler from HttpKernel"
```

### Task 13: Extract ControllerDispatcher

**Files:**
- Create: `packages/foundation/src/Http/ControllerDispatcher.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 1: Read dispatch() method**

Read HttpKernel.php lines 478-1462 to understand the route→handler mapping.

- [ ] **Step 2: Extract ControllerDispatcher**

Move the `dispatch()` method and its direct helpers. The dispatcher receives the extracted handlers (DiscoveryApiHandler, SsrPageHandler) and routes to them. It becomes a thin match/switch over route names.

- [ ] **Step 3: Wire into HttpKernel**

HttpKernel's `handle()` method calls `$this->controllerDispatcher->dispatch($request, $route)`.

- [ ] **Step 4: Full test suite — expect all pass**

- [ ] **Step 5: Verify HttpKernel is now ~800L**

Run: `wc -l packages/foundation/src/Kernel/HttpKernel.php`
Expected: ~800 lines or fewer

- [ ] **Step 6: Commit**

```bash
git commit -m "refactor(#297): extract ControllerDispatcher from HttpKernel"
```

### Task 14: Final verification for #297

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass

- [ ] **Step 2: Run PHPStan**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Verify line counts**

Run: `wc -l packages/foundation/src/Kernel/HttpKernel.php packages/foundation/src/Http/*.php packages/ssr/src/SsrPageHandler.php packages/cache/src/CacheConfigResolver.php packages/foundation/src/Kernel/*Registrar.php`

Expected: HttpKernel ≤800L, each extracted file focused on one responsibility.

---

## Chunk 3: Issue #298 — Decompose McpController (1650L)

### File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `packages/mcp/src/Rpc/ResponseFormatter.php` | Create | JSON-RPC response envelopes + contract meta (~75L) |
| `packages/mcp/src/Cache/ReadCache.php` | Create | Tool result caching + invalidation (~175L) |
| `packages/mcp/src/Tools/McpTool.php` | Create | Abstract base for tool classes |
| `packages/mcp/src/Tools/EntityTools.php` | Create | get_entity, list_entity_types (~54L) |
| `packages/mcp/src/Tools/DiscoveryTools.php` | Create | search_entities, ai_discover (~216L) |
| `packages/mcp/src/Tools/TraversalTools.php` | Create | traverse, get_related, knowledge_graph (~438L) |
| `packages/mcp/src/Tools/EditorialTools.php` | Create | editorial workflow transitions (~239L) |
| `packages/mcp/src/McpController.php` | Modify | Reduce to ~400L RPC orchestrator |
| `packages/mcp/tests/Unit/Rpc/ResponseFormatterTest.php` | Create | Unit tests |
| `packages/mcp/tests/Unit/Cache/ReadCacheTest.php` | Create | Unit tests |
| `packages/mcp/tests/Unit/Tools/EntityToolsTest.php` | Create | Unit tests |
| `packages/mcp/tests/Unit/Tools/DiscoveryToolsTest.php` | Create | Unit tests |
| `packages/mcp/tests/Unit/Tools/TraversalToolsTest.php` | Create | Unit tests |
| `packages/mcp/tests/Unit/Tools/EditorialToolsTest.php` | Create | Unit tests |

### Extraction Order Rationale

1. **ResponseFormatter + ReadCache** — Shared infrastructure used by all tools; extract first so tool classes can depend on them
2. **McpTool base class + EntityTools** — Establish the tool pattern with the simplest tool
3. **DiscoveryTools** — Moderate complexity, standalone
4. **TraversalTools** — Most complex (collectTraversalRows is 123L), extract carefully
5. **EditorialTools** — Tightly coupled internal helpers, keep together

### Task 15: Extract McpResponseFormatter

**Files:**
- Create: `packages/mcp/src/Rpc/ResponseFormatter.php`
- Create: `packages/mcp/tests/Unit/Rpc/ResponseFormatterTest.php`
- Modify: `packages/mcp/src/McpController.php`

- [ ] **Step 1: Write tests**

Tests for:
- `resultWrapsInJsonRpcEnvelope` — verify `{jsonrpc: "2.0", id, result}` structure
- `errorWrapsWithCodeAndMessage` — verify error envelope
- `withStableContractMetaInjectsVersion` — verify meta.contract_version, meta.stability, meta.tool_invoked
- `canonicalToolNameResolvesAliases` — verify deprecated names map to canonical
- `formatToolContentExtractsContentArray`

Read McpController.php lines 1173-1234 and 1463-1475.

- [ ] **Step 2-4: TDD cycle**

- [ ] **Step 5: Wire into McpController — replace method calls with `$this->formatter->method()`**

- [ ] **Step 6: Full test suite**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor(#298): extract McpResponseFormatter from McpController"
```

### Task 16: Extract McpReadCache

**Files:**
- Create: `packages/mcp/src/Cache/ReadCache.php`
- Create: `packages/mcp/tests/Unit/Cache/ReadCacheTest.php`
- Modify: `packages/mcp/src/McpController.php`

- [ ] **Step 1: Write tests**

Tests for:
- `buildsCacheKeyFromToolAndArguments` — deterministic key generation
- `identifiesCacheableTools` — whitelist verification (search_*, get_*, traverse_*)
- `returnsNullForExpiredCache` — TTL check (120s)
- `storesAndRetrievesResult`
- `buildsCacheTagsFromEntityMentions` — recursive entity detection
- `normalizeForCacheKeyIsStable` — sorting, type normalization

Read McpController.php lines 1476-1650.

- [ ] **Step 2-4: TDD cycle**

- [ ] **Step 5: Wire into McpController**

- [ ] **Step 6: Full test suite**

- [ ] **Step 7: Commit**

```bash
git commit -m "refactor(#298): extract McpReadCache from McpController"
```

### Task 17: Create McpTool base class + Extract EntityTools

**Files:**
- Create: `packages/mcp/src/Tools/McpTool.php`
- Create: `packages/mcp/src/Tools/EntityTools.php`
- Create: `packages/mcp/tests/Unit/Tools/EntityToolsTest.php`
- Modify: `packages/mcp/src/McpController.php`

- [ ] **Step 1: Create McpTool abstract base**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\MCP\Tools;

use Waaseyaa\MCP\Cache\ReadCache;
use Waaseyaa\MCP\Rpc\ResponseFormatter;

abstract class McpTool
{
    public function __construct(
        protected readonly ReadCache $cache,
        protected readonly ResponseFormatter $formatter,
    ) {}

    /** @return list<array{name: string, description: string, inputSchema: array}> */
    abstract public function definitions(): array;

    /** @return array<string, mixed> */
    abstract public function execute(string $tool, array $args): array;
}
```

- [ ] **Step 2: Write EntityTools tests**

Tests for:
- `getEntityReturnsSerializedEntity`
- `getEntityReturnsErrorForUnknownType`
- `listEntityTypesReturnsAllDefinitions`
- `definitionsReturnsExpectedToolList`

Read McpController.php lines 354-390 and 1119-1135.

- [ ] **Step 3-5: TDD cycle for EntityTools**

- [ ] **Step 6: Wire into McpController — route `get_entity` and `list_entity_types` to EntityTools**

- [ ] **Step 7: Full test suite**

- [ ] **Step 8: Commit**

```bash
git commit -m "refactor(#298): create McpTool base + extract EntityTools"
```

### Task 18: Extract DiscoveryTools

**Files:**
- Create: `packages/mcp/src/Tools/DiscoveryTools.php`
- Create: `packages/mcp/tests/Unit/Tools/DiscoveryToolsTest.php`
- Modify: `packages/mcp/src/McpController.php`

- [ ] **Step 1: Write tests**

Tests for:
- `searchEntitiesReturnsResults`
- `aiDiscoverBlendsSemanticAndGraphResults`
- `resolveDiscoveryAnchorLoadsByTypeAndId`
- `definitionsReturnsSearchAndDiscoverTools`

Read McpController.php lines 230-353 and 1027-1102.

- [ ] **Step 2-5: TDD cycle**

- [ ] **Step 6: Wire into McpController**

- [ ] **Step 7: Full test suite**

- [ ] **Step 8: Commit**

```bash
git commit -m "refactor(#298): extract DiscoveryTools from McpController"
```

### Task 19: Extract TraversalTools

**Files:**
- Create: `packages/mcp/src/Tools/TraversalTools.php`
- Create: `packages/mcp/tests/Unit/Tools/TraversalToolsTest.php`
- Modify: `packages/mcp/src/McpController.php`

- [ ] **Step 1: Write tests**

Tests for:
- `traverseRelationshipsReturnsFilteredResults`
- `getRelatedEntitiesResolvesOppositeEnd`
- `getKnowledgeGraphReturnsBidirectionalSurface`
- `parseTraversalArgumentsValidatesDirection`
- `temporalFilteringRespectsActiveAt`
- `collectTraversalRowsAppliesAccessChecks`

Read McpController.php lines 391-588 and 680-739 and 888-1010 and 1107-1172.

**Note:** `collectTraversalRows` is 123 lines — the biggest single method. Keep it intact in TraversalTools but consider future decomposition.

- [ ] **Step 2-5: TDD cycle**

- [ ] **Step 6: Wire into McpController**

- [ ] **Step 7: Full test suite**

- [ ] **Step 8: Commit**

```bash
git commit -m "refactor(#298): extract TraversalTools from McpController"
```

### Task 20: Extract EditorialTools

**Files:**
- Create: `packages/mcp/src/Tools/EditorialTools.php`
- Create: `packages/mcp/tests/Unit/Tools/EditorialToolsTest.php`
- Modify: `packages/mcp/src/McpController.php`

- [ ] **Step 1: Write tests**

Tests for:
- `editorialTransitionAppliesStateChange`
- `editorialValidateChecksEligibilityWithoutMutation`
- `editorialPublishIsShorthandForPublishedTransition`
- `editorialArchiveIsShorthandForArchivedTransition`
- `loadEditorialNodeVerifiesAccess`
- `editorialNodeSnapshotIncludesAvailableTransitions`

Read McpController.php lines 589-887.

- [ ] **Step 2-5: TDD cycle**

- [ ] **Step 6: Wire into McpController**

- [ ] **Step 7: Full test suite**

- [ ] **Step 8: Commit**

```bash
git commit -m "refactor(#298): extract EditorialTools from McpController"
```

### Task 21: Final verification for #298

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass

- [ ] **Step 2: Run PHPStan**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Verify line counts**

Run: `wc -l packages/mcp/src/McpController.php packages/mcp/src/Rpc/*.php packages/mcp/src/Cache/*.php packages/mcp/src/Tools/*.php`

Expected: McpController ≤400L, each tool class focused on one responsibility.

---

## Acceptance Criteria Summary

### #299 — PHP 8.4 + CI Quality Gates
- [ ] `composer.json` requires PHP >=8.4
- [ ] PHPStan level 5 passes (with baseline if needed)
- [ ] PHP-CS-Fixer config present and CI job runs
- [ ] PHPUnit coverage config present and CI job runs
- [ ] All existing tests pass on PHP 8.4

### #297 — Decompose HttpKernel
- [ ] HttpKernel.php reduced to ≤800 lines
- [ ] 7 extracted classes each have unit tests
- [ ] Each extracted class has a single clear responsibility
- [ ] No behavior regressions (all existing tests pass)
- [ ] PHPStan passes with no new errors

### #298 — Decompose McpController
- [ ] McpController.php reduced to ≤400 lines (RPC orchestrator)
- [ ] 6 extracted classes each have unit tests
- [ ] McpTool base class establishes consistent pattern
- [ ] Tool classes are independently instantiable and testable
- [ ] No behavior regressions (all existing tests pass)
- [ ] PHPStan passes with no new errors
