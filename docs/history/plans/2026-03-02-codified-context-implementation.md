# Codified Context Infrastructure — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build three-tier codified context infrastructure (constitution + specialist skills + subsystem specs with MCP retrieval) for the Waaseyaa CMS monorepo.

**Architecture:** Tier 3 specs first (knowledge base that everything references), then Tier 3 MCP retrieval server (TDD), then Tier 2 skills (reference specs), then Tier 1 constitution (references both), then maintenance tooling, then the repeatable skill.

**Tech Stack:** Markdown (specs/skills), Node.js + @modelcontextprotocol/sdk (MCP server), Bash (drift detector)

**Source design:** `docs/history/plans/2026-03-02-codified-context-design.md`

---

## Phase 1: Subsystem Specs (Tier 3 Content)

Write 10 subsystem specs in `docs/specs/`. Each spec is written for AI consumption: explicit file paths, code patterns, interface signatures, parameter names. NOT prose for humans.

### Task 1: Create specs directory and write entity-system.md

**Files:**
- Create: `docs/specs/entity-system.md`

**Step 1: Create directory**

```bash
mkdir -p docs/specs
```

**Step 2: Write entity-system.md**

Mine from: `docs/history/plans/2026-02-27-aurora-cms-design.md`, `docs/history/plans/2026-02-28-aurora-architecture-v2-design.md`, and source code.

Structure (~300 lines):

```markdown
# Entity System

## Packages
- `packages/entity/` — EntityInterface, EntityBase, EntityType, EntityTypeManager
- `packages/entity-storage/` — SqlEntityStorage, SqlEntityQuery, SqlSchemaHandler
- `packages/field/` — FieldType interface, field definitions
- `packages/config/` — ConfigEntity, ConfigManager

## Key Interfaces

### EntityInterface (packages/entity/src/EntityInterface.php)
- `id(): int|string|null`
- `uuid(): string`
- `label(): string`
- `getEntityTypeId(): string`
- `bundle(): string`
- `isNew(): bool`
- `toArray(): array`
- `language(): string`

### EntityType (packages/entity/src/EntityType.php)
Final readonly class. Named constructor params:
`new EntityType(id: 'node', label: 'Content', class: Node::class, ...)`
Keys: id, uuid, label, bundle, revision, langcode

### EntityTypeManager (packages/entity/src/EntityTypeManager.php)
Constructor: `(EventDispatcherInterface, callable $storageFactory)`
Storage factory receives `EntityType $definition`, returns `EntityStorageInterface`.

### SqlEntityStorage (packages/entity-storage/src/SqlEntityStorage.php)
- 336 lines. Implements EntityStorageInterface.
- Constructor: `(EntityTypeInterface, DatabaseInterface, EventDispatcherInterface)`
- Uses reflection to detect entity constructor shape (array $values vs named params)
- `splitForStorage()` — separates schema columns from _data JSON blob
- `mapRowToEntity()` — merges _data JSON back into entity values on load

## Architecture

### Entity Construction
Entity subclasses (User, Node) accept `(array $values)` and hardcode entityTypeId/entityKeys:
```php
final class User extends ContentEntityBase {
    public function __construct(array $values) {
        parent::__construct('user', ['uid', 'uuid', 'name', ...], $values);
    }
}
```
SqlEntityStorage uses reflection to detect this pattern.

### _data JSON Blob
SqlSchemaHandler adds a `_data` TEXT column to every entity table.
- On save: `splitForStorage()` puts non-schema values into `_data` as JSON
- On load: `mapRowToEntity()` merges `_data` back into the values array
- Always use `json_encode(..., JSON_THROW_ON_ERROR)` paired with `json_decode(..., JSON_THROW_ON_ERROR)`

### Entity Events
EntityEvent uses public readonly properties: `$event->entity`, `$event->originalEntity`
Common mistake: calling `$event->getEntity()` — no such method exists.

### Entity Keys Mapping
Each EntityType defines which fields contain standard values:
- `id` — primary key field name (e.g., 'uid' for User, 'nid' for Node)
- `uuid` — UUID field name
- `label` — human-readable label field
- `bundle` — bundle discriminator (optional)
- `revision` — revision ID (optional)
- `langcode` — language code (optional)

## Common Mistakes

1. **Forgetting `enforceIsNew()`** — When creating entities with pre-set IDs (e.g., `new User(['uid' => 2])`), call `$entity->enforceIsNew()` before `save()`. Otherwise `isNew()` returns false, SqlEntityStorage tries UPDATE, silently affects 0 rows.
2. **Using `$event->getEntity()`** — EntityEvent has public properties, not getters.
3. **PascalCase conversion** — Use `str_replace('_', '', ucwords($name, '_'))` not `ucfirst()`.
4. **Asymmetric JSON** — Always pair `json_encode(JSON_THROW_ON_ERROR)` with `json_decode(JSON_THROW_ON_ERROR)`.
5. **Dual-state bug pattern** — When data can come from two sources (attribute vs registry), use one canonical source. Found repeatedly in entity values.

## Testing Patterns

- Use `InMemoryEntityStorage` from `Waaseyaa\Api\Tests\Fixtures\` for unit tests
- Use `PdoDatabase::createSqlite()` with `:memory:` for SQL-level tests
- Final classes can't be mocked — use real instances with `sys_get_temp_dir() . '/waaseyaa_test_' . uniqid()`
- Entity subclass constructors only accept `(array $values)` — always construct with array
```

**Step 3: Verify file references are accurate**

Spot-check 3-4 file paths mentioned in the spec against the actual codebase.

Run: `ls packages/entity/src/EntityInterface.php packages/entity/src/EntityType.php packages/entity-storage/src/SqlEntityStorage.php`
Expected: all three exist

---

### Task 2: Write access-control.md and field-access.md

**Files:**
- Create: `docs/specs/access-control.md`
- Create: `docs/specs/field-access.md`

**Step 1: Write access-control.md (~350 lines)**

Mine from: `docs/history/plans/2026-03-01-authorization-wiring-design.md`, `docs/history/plans/2026-03-01-field-level-access-design.md`.

Must include:
- AccessPolicyInterface full signature: `access(EntityInterface, string $operation, AccountInterface): AccessResult`, `createAccess(string $entityTypeId, string $bundle, AccountInterface): AccessResult`, `appliesTo(string $entityTypeId): bool`
- AccessResult factory methods: `::allowed()`, `::neutral()`, `::forbidden()` with `isAllowed()`, `isForbidden()`, `isNeutral()`, `andIf()`, `orIf()`
- AccessStatus enum: ALLOWED, NEUTRAL, FORBIDDEN
- EntityAccessHandler: `check()`, `checkCreateAccess()` — OR logic, Forbidden short-circuits
- Gate and PermissionHandler
- Route access control: `_public`, `_permission`, `_role`, `_gate` route options
- AccessChecker reads route options and delegates
- Policy discovery via `#[PolicyAttribute]` (string constant, not ::class — layer discipline)
- Open-by-default: routes without access requirements pass through
- Entity-level semantics: deny-unless-granted via `isAllowed()`
- AccountInterface (in access package) vs concrete User/AnonymousUser (in user package) — access must NOT depend on user package

**Step 2: Write field-access.md (~250 lines)**

Mine from: `docs/history/plans/2026-03-01-field-level-access-design.md`, `docs/history/plans/2026-03-01-field-access-wiring-design.md`.

Must include:
- FieldAccessPolicyInterface: `fieldAccess(EntityInterface, string $fieldName, string $operation, AccountInterface): AccessResult`
- Policies must implement BOTH AccessPolicyInterface AND FieldAccessPolicyInterface
- EntityAccessHandler: `checkFieldAccess()`, `filterFields()` — OR logic, Forbidden short-circuits, skips non-FieldAccess policies
- **Critical semantics:** field-level uses `!isForbidden()` (allow-unless-denied), NOT `isAllowed()`. This asymmetry with entity-level is intentional.
- View denial: field omitted from JSON:API response
- Edit denial: field shown as read-only with `x-access-restricted: true` in schema
- Paired nullable pattern: `?EntityAccessHandler` + `?AccountInterface` — both non-null or both null
- Prototype entity for schema evaluation: `$prototype = new $class([])`
- No new discovery — same `#[PolicyAttribute]`, `appliesTo()` scopes
- Testing: anonymous classes implementing intersection types (PHPUnit can't mock intersections)

**Step 3: Verify file references**

Run: `ls packages/access/src/AccessPolicyInterface.php packages/access/src/FieldAccessPolicyInterface.php packages/access/src/EntityAccessHandler.php packages/access/src/AccessResult.php`

---

### Task 3: Write api-layer.md

**Files:**
- Create: `docs/specs/api-layer.md`

**Step 1: Write api-layer.md (~350 lines)**

Mine from: `docs/history/plans/2026-03-01-admin-spa-completion.md`, source code.

Must include:
- JsonApiController (packages/api/src/JsonApiController.php, 400 lines): `index()`, `show()`, `store()`, `update()`, `destroy()`
- Constructor: `(EntityTypeManagerInterface, ResourceSerializer, ?EntityAccessHandler, ?AccountInterface)`
- ResourceSerializer (packages/api/src/ResourceSerializer.php, 105 lines): `serialize(EntityInterface, ?EntityAccessHandler, ?AccountInterface): JsonApiResource`
- Paired nullable pattern: both handler + account must be non-null or both null
- QueryParser → QueryApplier → EntityQuery pipeline
- Post-fetch access filtering: access checked after query execution, not in SQL
- SchemaPresenter (packages/api/src/Schema/SchemaPresenter.php, 331 lines): `present(EntityTypeInterface, ?EntityAccessHandler, ?AccountInterface): array`
- JSON Schema extensions: x-widget, x-label, x-description, x-weight, x-required, x-access-restricted
- x-access-restricted vs system readOnly: restricted = viewable but not editable (disabled widget), readOnly = hidden from forms (id, uuid)
- LIKE wildcard escaping: `str_replace(['%', '_'], ['\\%', '\\_'], $value)` — PdoSelect appends `ESCAPE '\'`
- SchemaController: constructor accepts optional `?EntityAccessHandler` + `?AccountInterface`, creates prototype entity for schema evaluation
- Query operators: CONTAINS → `%value%`, STARTS_WITH → `value%`, ENDS_WITH → `%value`

**Step 2: Verify file references**

Run: `ls packages/api/src/JsonApiController.php packages/api/src/ResourceSerializer.php packages/api/src/Schema/SchemaPresenter.php`

---

### Task 4: Write middleware-pipeline.md and package-discovery.md

**Files:**
- Create: `docs/specs/middleware-pipeline.md`
- Create: `docs/specs/package-discovery.md`

**Step 1: Write middleware-pipeline.md (~250 lines)**

Mine from: `docs/history/plans/2026-03-01-authorization-wiring-design.md`, `docs/history/plans/2026-03-01-laravel-integration-layer-design.md`.

Must include:
- Three typed pipeline interfaces: Http, Event, Job — each has MiddlewareInterface + HandlerInterface
- HttpMiddlewareInterface: `process(Request, HttpHandlerInterface): Response`
- HttpHandlerInterface: `handle(Request): Response`
- Same pattern for Event (DomainEvent) and Job
- Onion pattern: middleware array reversed, each wraps next in closure
- public/index.php pipeline: SessionMiddleware → AuthorizationMiddleware → RouteHandler
- SessionMiddleware: `session_start()`, reads `$_SESSION['waaseyaa_uid']`, loads User, falls back to AnonymousUser, sets `_account` on request attributes
- AuthorizationMiddleware: reads `_account`, reads matched Route, delegates to AccessChecker, returns 403 on Forbidden
- Middleware discovery via `#[AsMiddleware(pipeline: 'http', priority: 100)]` attribute
- MiddlewarePipelineCompiler compiles to `storage/framework/middleware.php`
- php://input is single-read: `HttpRequest::createFromGlobals()` consumes it, use `$request->getContent()` after

**Step 2: Write package-discovery.md (~300 lines)**

Mine from: `docs/history/plans/2026-03-01-laravel-integration-layer-design.md`.

Must include:
- ServiceProvider abstract class: `register()` (pure binding, no side effects), `boot()` (post-registration, cross-layer safe)
- Auto-discovery: `composer.json` → `extra.waaseyaa.providers`
- Three compilers: PackageManifestCompiler, MiddlewarePipelineCompiler, ConfigCacheCompiler
- Compilation order: manifest → middleware → config
- Compiled artifacts in `storage/framework/`: `packages.php`, `middleware.php`, `config.php`
- Dev: auto-compile on first use. Prod: pre-compiled via `waaseyaa optimize`
- Attribute scanning: `#[AsFieldType]`, `#[AsListener]`, `#[AsMiddleware]`, `#[AsCommand]`, `#[AsEntityType]`
- PackageManifest properties: providers, commands, routes, migrations, field_types, listeners, middleware, permissions, policies
- Permission discovery: `extra.waaseyaa.permissions` in composer.json
- Policy discovery: `#[PolicyAttribute]` → `policies` in manifest (entity type ID => FQCN)
- Config caching: CachedConfigFactory decorator pattern, environment overrides via `WAASEYAA_CONFIG__SYSTEM_SITE__NAME`
- CLI: `waaseyaa optimize`, `waaseyaa optimize:clear`, `waaseyaa optimize:manifest`, `waaseyaa optimize:middleware`, `waaseyaa optimize:config`
- Backward-compatible cache evolution: use `$data['key'] ?? []` for new properties

**Step 3: Verify file references**

Run: `ls packages/foundation/src/ServiceProvider/ServiceProvider.php packages/foundation/src/Discovery/PackageManifestCompiler.php 2>/dev/null; ls packages/foundation/src/Middleware/HttpMiddlewareInterface.php 2>/dev/null || echo "check Middleware dir structure"`

---

### Task 5: Write infrastructure.md

**Files:**
- Create: `docs/specs/infrastructure.md`

**Step 1: Write infrastructure.md (~300 lines)**

Mine from: `docs/history/plans/2026-02-28-aurora-architecture-v2-design.md`, source code.

Must include:
- DomainEvent (packages/foundation/src/Event/DomainEvent.php): abstract class extending Symfony Event, properties: eventId (UUIDv7), occurredAt, aggregateType, aggregateId, tenantId, actorId. Abstract `getPayload(): array`.
- Three dispatch channels: sync (EventDispatcher, immediate), async (Messenger, queued), broadcast (SSE, real-time UI)
- EventBus: dispatches to all three channels. `eventStore?->append()` → eventPipeline → syncDispatcher → asyncBus → broadcaster
- Cache system: CacheBackendInterface, MemoryBackend (testing), DatabaseBackend, RedisBackend. Tag-based invalidation.
- Atomic file writes: write to temp file, then `rename()`. Never `file_put_contents()` directly to cache target.
- DatabaseInterface (packages/database-legacy/src/DatabaseInterface.php): query builder abstraction. Does NOT have `getPdo()`.
- PdoDatabase: concrete implementation. Has `getPdo()`. Only type-hint this when raw PDO is needed.
- Query builder: `select()`, `insert()`, `update()`, `delete()` — prefer over raw PDO
- PdoDatabase::createSqlite() for in-memory test databases
- PDO fetch mode: FETCH_ASSOC (avoids duplicate numeric-indexed columns)
- Plugin system: PluginInterface, attribute-based discovery
- No psr/log: use `error_log()` for best-effort logging
- Best-effort side effects: event listeners for non-critical ops wrap in try-catch with `error_log()`
- Layer discipline: Foundation (layer 0) must never import from higher layers. Use string constants for cross-layer attribute scanning.

**Step 2: Verify file references**

Run: `ls packages/foundation/src/Event/DomainEvent.php packages/database-legacy/src/DatabaseInterface.php packages/database-legacy/src/PdoDatabase.php`

---

### Task 6: Write mcp-endpoint.md

**Files:**
- Create: `docs/specs/mcp-endpoint.md`

**Step 1: Write mcp-endpoint.md (~200 lines)**

Mine from: `docs/history/plans/2026-02-28-webmcp-design.md`.

Must include:
- McpEndpoint (packages/mcp/src/McpEndpoint.php): `handle(ServerRequestInterface): ResponseInterface`
- McpAuthInterface (packages/mcp/src/Auth/McpAuthInterface.php): `authenticate(ServerRequestInterface): ?AccountInterface`
- BearerTokenAuth: reads `Authorization: Bearer <token>`, maps tokens to AccountInterface instances
- Bridge adapters: AuroraToolAdapter (McpToolDefinition → SDK MetadataInterface), AuroraToolExecutorAdapter (→ SDK ToolExecutorInterface)
- Routes: `POST|GET /mcp` (auth required), `GET /.well-known/mcp.json` (public)
- McpServerCard: name, version, endpoint, transport, capabilities, authentication
- Request flow: HTTP → McpRouteProvider → McpEndpoint → McpAuthInterface → JsonRpcHandler → ToolListHandler/ToolCallHandler → Bridge → SchemaRegistry → McpToolExecutor → Entity Storage
- Audit: piggybacks on AgentAuditLog via McpToolExecutor → AgentExecutor::executeTool()
- MCP transport: Streamable HTTP (spec 2025-03-26), single endpoint, POST for requests, GET for SSE
- Auth roadmap: MVP bearer tokens → OAuth 2.1 → scoped permissions

**Step 2: Verify file references**

Run: `ls packages/mcp/src/McpEndpoint.php packages/mcp/src/Auth/McpAuthInterface.php packages/mcp/src/Auth/BearerTokenAuth.php`

---

### Task 7: Write admin-spa.md and ai-integration.md

**Files:**
- Create: `docs/specs/admin-spa.md`
- Create: `docs/specs/ai-integration.md`

**Step 1: Write admin-spa.md (~200 lines)**

Mine from: `docs/history/plans/2026-03-01-admin-spa-completion.md`, source files.

Must include:
- Nuxt 3 + Vue 3 + TypeScript
- Composables in `packages/admin/app/composables/`: useEntity.ts, useSchema.ts, useLanguage.ts, useRealtime.ts
- useEntity: reactive entity CRUD operations against JSON:API backend
- useSchema: loads JSON Schema for entity types, `sortedProperties(editable)` filters system readOnly but keeps x-access-restricted
- useRealtime: SSE connection to PHP backend for live entity updates
- useLanguage: language selection and i18n
- i18n: `packages/admin/app/i18n/en.json`
- Schema-driven forms: SchemaForm.vue renders from JSON Schema, SchemaField.vue passes `disabled` for x-access-restricted fields
- x-access-restricted: field is viewable but not editable — renders disabled widget. Distinct from system readOnly (id, uuid) which hides field entirely.
- Build verification: `cd packages/admin && npm run build` — no test framework, build verifies TypeScript compilation

**Step 2: Write ai-integration.md (~200 lines)**

Mine from: `docs/history/plans/2026-02-28-aurora-architecture-v2-design.md`, source code.

Must include:
- ai-schema: EntityJsonSchemaGenerator (draft 2020-12), `generate(entityTypeId): array`, `generateAll(): array<string, array>`, SchemaRegistry
- ai-agent: AgentInterface, AgentExecutor (`execute()`, `dryRun()`, `executeTool()`), AgentContext, AgentResult, AgentAuditLog (in-memory)
- ai-pipeline: Pipeline (ConfigEntity, ordered steps by weight), PipelineExecutor (executes in weight order, output → next input), PipelineStepInterface, PipelineContext, PipelineDispatcher, StepResult
- ai-vector: Vector embeddings, similarity search (planned)
- Pipeline pattern: `addStep(name, weight)`, `removeStep()`, executor passes `StepResult.output` as next step's input

**Step 3: Verify file references**

Run: `ls packages/admin/app/composables/useEntity.ts packages/ai-schema/src/EntityJsonSchemaGenerator.php packages/ai-agent/src/AgentExecutor.php packages/ai-pipeline/src/Pipeline.php 2>/dev/null`

---

### Task 8: Commit all specs

**Step 1: Review spec count**

Run: `ls docs/specs/*.md | wc -l`
Expected: 10

**Step 2: Commit**

```bash
git add docs/specs/
git commit -m "docs: add 10 subsystem specs for codified context Tier 3

Mined from existing plan docs and source code. Written for AI
consumption with explicit file paths, interface signatures, and
code patterns.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 2: MCP Retrieval Server (Tier 3 Tooling)

Build a local MCP server in `tools/spec-retrieval/` that Claude Code uses to query subsystem specs.

### Task 9: Scaffold MCP retrieval server

**Files:**
- Create: `tools/spec-retrieval/package.json`
- Create: `tools/spec-retrieval/.gitignore`

**Step 1: Create directory and package.json**

```bash
mkdir -p tools/spec-retrieval
```

Write `tools/spec-retrieval/package.json`:
```json
{
  "name": "waaseyaa-spec-retrieval",
  "version": "1.0.0",
  "private": true,
  "type": "module",
  "scripts": {
    "start": "node server.js",
    "test": "node --test test.js"
  },
  "dependencies": {
    "@modelcontextprotocol/sdk": "^1.0.0"
  }
}
```

Write `tools/spec-retrieval/.gitignore`:
```
node_modules/
```

**Step 2: Install dependencies**

Run: `cd tools/spec-retrieval && npm install`
Expected: package-lock.json created, @modelcontextprotocol/sdk installed

**Step 3: Add to root .gitignore if not already there**

Check and add `tools/spec-retrieval/node_modules/` to root `.gitignore` if needed.

---

### Task 10: Write failing test for list and get tools

**Files:**
- Create: `tools/spec-retrieval/test.js`

**Step 1: Write test file**

Use Node.js built-in test runner (`node:test`). Test the server's tool handlers directly (unit test, no MCP transport).

```javascript
import { describe, it, before } from 'node:test';
import assert from 'node:assert/strict';
import { createToolHandlers } from './server.js';

describe('waaseyaa_list_specs', () => {
  let handlers;

  before(() => {
    handlers = createToolHandlers('../../docs/specs');
  });

  it('returns all specs with name and description', async () => {
    const result = await handlers.waaseyaa_list_specs();
    assert.ok(Array.isArray(result));
    assert.ok(result.length >= 10);
    for (const spec of result) {
      assert.ok(spec.name, 'each spec has a name');
      assert.ok(spec.description, 'each spec has a description');
      assert.ok(spec.file, 'each spec has a file path');
    }
  });
});

describe('waaseyaa_get_spec', () => {
  let handlers;

  before(() => {
    handlers = createToolHandlers('../../docs/specs');
  });

  it('returns full content for a valid spec', async () => {
    const result = await handlers.waaseyaa_get_spec({ name: 'entity-system' });
    assert.ok(typeof result === 'string');
    assert.ok(result.includes('# Entity System'));
    assert.ok(result.length > 100);
  });

  it('returns error for unknown spec', async () => {
    const result = await handlers.waaseyaa_get_spec({ name: 'nonexistent' });
    assert.ok(result.includes('not found'));
  });
});

describe('waaseyaa_search_specs', () => {
  let handlers;

  before(() => {
    handlers = createToolHandlers('../../docs/specs');
  });

  it('returns matching sections for a query', async () => {
    const result = await handlers.waaseyaa_search_specs({ query: 'EntityAccessHandler' });
    assert.ok(typeof result === 'string');
    assert.ok(result.includes('EntityAccessHandler'));
  });

  it('returns no matches message for garbage query', async () => {
    const result = await handlers.waaseyaa_search_specs({ query: 'xyzzy_nonexistent_term' });
    assert.ok(result.includes('No matches'));
  });
});
```

**Step 2: Run test to verify it fails**

Run: `cd tools/spec-retrieval && node --test test.js`
Expected: FAIL — `createToolHandlers` not exported from server.js

---

### Task 11: Implement MCP server

**Files:**
- Create: `tools/spec-retrieval/server.js`

**Step 1: Write server.js (~150 lines)**

```javascript
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { readdir, readFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

/**
 * Create tool handler functions for the spec retrieval tools.
 * Exported for testing. specsDir is relative to this file.
 */
export function createToolHandlers(specsDir) {
  const dir = resolve(__dirname, specsDir);

  async function listSpecs() {
    const files = (await readdir(dir)).filter(f => f.endsWith('.md'));
    const specs = [];
    for (const file of files) {
      const content = await readFile(join(dir, file), 'utf-8');
      const name = file.replace('.md', '');
      // Extract first heading as description
      const match = content.match(/^#\s+(.+)$/m);
      const description = match ? match[1] : name;
      specs.push({ name, description, file: `docs/specs/${file}` });
    }
    return specs;
  }

  async function getSpec({ name }) {
    const filePath = join(dir, `${name}.md`);
    try {
      return await readFile(filePath, 'utf-8');
    } catch {
      return `Spec "${name}" not found. Use waaseyaa_list_specs to see available specs.`;
    }
  }

  async function searchSpecs({ query }) {
    const files = (await readdir(dir)).filter(f => f.endsWith('.md'));
    const results = [];
    const lowerQuery = query.toLowerCase();

    for (const file of files) {
      const content = await readFile(join(dir, file), 'utf-8');
      const lines = content.split('\n');
      const matches = [];

      for (let i = 0; i < lines.length; i++) {
        if (lines[i].toLowerCase().includes(lowerQuery)) {
          // Include 2 lines of context before and after
          const start = Math.max(0, i - 2);
          const end = Math.min(lines.length - 1, i + 2);
          matches.push(lines.slice(start, end + 1).join('\n'));
        }
      }

      if (matches.length > 0) {
        const name = file.replace('.md', '');
        results.push(`## ${name}\n\n${matches.join('\n\n---\n\n')}`);
      }
    }

    if (results.length === 0) {
      return `No matches found for "${query}".`;
    }
    return results.join('\n\n===\n\n');
  }

  return {
    waaseyaa_list_specs: listSpecs,
    waaseyaa_get_spec: getSpec,
    waaseyaa_search_specs: searchSpecs,
  };
}

// MCP server setup (only runs when executed directly, not when imported for tests)
const isMain = process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url));

if (isMain) {
  const handlers = createToolHandlers('../../docs/specs');

  const server = new Server(
    { name: 'waaseyaa-specs', version: '1.0.0' },
    { capabilities: { tools: {} } }
  );

  server.setRequestHandler('tools/list', async () => ({
    tools: [
      {
        name: 'waaseyaa_list_specs',
        description: 'List all available Waaseyaa subsystem specifications',
        inputSchema: { type: 'object', properties: {} },
      },
      {
        name: 'waaseyaa_get_spec',
        description: 'Get the full content of a named subsystem specification',
        inputSchema: {
          type: 'object',
          properties: { name: { type: 'string', description: 'Spec name (e.g., "entity-system")' } },
          required: ['name'],
        },
      },
      {
        name: 'waaseyaa_search_specs',
        description: 'Search across all subsystem specs by keyword',
        inputSchema: {
          type: 'object',
          properties: { query: { type: 'string', description: 'Search query' } },
          required: ['query'],
        },
      },
    ],
  }));

  server.setRequestHandler('tools/call', async (request) => {
    const { name, arguments: args } = request.params;
    const handler = handlers[name];
    if (!handler) {
      return { content: [{ type: 'text', text: `Unknown tool: ${name}` }], isError: true };
    }
    const result = await handler(args || {});
    const text = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
    return { content: [{ type: 'text', text }] };
  });

  const transport = new StdioServerTransport();
  await server.connect(transport);
}
```

**Step 2: Run tests**

Run: `cd tools/spec-retrieval && node --test test.js`
Expected: All tests pass

**Step 3: Commit**

```bash
git add tools/spec-retrieval/
git commit -m "feat: MCP spec retrieval server for codified context Tier 3

Three tools: waaseyaa_list_specs, waaseyaa_get_spec,
waaseyaa_search_specs. Keyword substring matching over docs/specs/.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 12: Configure MCP server in Claude Code settings

**Files:**
- Modify: `.claude/settings.local.json`

**Step 1: Add MCP server configuration**

Add `mcpServers` key to the existing settings file (merge with existing content):

```json
{
  "mcpServers": {
    "waaseyaa-specs": {
      "command": "node",
      "args": ["tools/spec-retrieval/server.js"],
      "cwd": "/home/fsd42/dev/waaseyaa"
    }
  }
}
```

Note: merge with existing `permissions` key, don't overwrite.

**Step 2: Verify configuration is valid JSON**

Run: `python3 -c "import json; json.load(open('.claude/settings.local.json'))"`
Expected: no error

**Step 3: Commit**

```bash
git add .claude/settings.local.json
git commit -m "config: register MCP spec retrieval server in Claude Code settings

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 3: Domain Specialist Skills (Tier 2)

Write 7 project-specific skills. Each skill is a markdown file installed in the Claude Code skills directory. Content is >50% domain knowledge.

Skills location: determine based on the project's skill configuration. Check how existing project-specific skills are configured (if any) or create a `skills/` directory in the project root.

### Task 13: Determine skill installation location

**Step 1: Check how Claude Code discovers project skills**

Look for skill configuration in `.claude/` directory or CLAUDE.md. Check the superpowers plugin structure for reference on how skills are registered.

Run: `find .claude/ -name "*.json" -exec grep -l skill {} \; 2>/dev/null; ls skills/ 2>/dev/null || echo "no skills dir"`

**Step 2: Create skills directory**

```bash
mkdir -p skills/waaseyaa
```

Skills will be markdown files: `skills/waaseyaa/<name>.md`

Configure skill discovery in `.claude/settings.local.json` if needed (add skills path).

---

### Task 14: Write waaseyaa:entity-system skill

**Files:**
- Create: `skills/waaseyaa/entity-system.md`

**Step 1: Write skill (~300 lines)**

Front matter:
```yaml
name: waaseyaa:entity-system
description: Use when working on entity types, entity storage, field types, config entities, or any code in packages/entity/, packages/entity-storage/, packages/field/, packages/config/
```

Sections (per design template):
1. **Scope** — packages/entity/, packages/entity-storage/, packages/field/, packages/config/
2. **Key Interfaces** — EntityInterface, EntityType, EntityTypeManager, EntityStorageInterface, SqlEntityStorage, FieldTypeInterface, ConfigEntity
3. **Architecture** — Entity construction patterns, _data JSON blob split/merge, entity keys mapping, UnitOfWork, SqlSchemaHandler auto-creates tables
4. **Common Mistakes** — enforceIsNew(), getEntity() vs public properties, PascalCase, JSON symmetry, dual-state pattern, final class mocking
5. **Testing Patterns** — InMemoryEntityStorage, PdoDatabase::createSqlite(), real instances for final classes, entity constructor always array
6. **Related Specs** — `waaseyaa_get_spec entity-system` via MCP

Content draws from the entity-system.md spec but adds behavioral guidance (when to use which storage, how to add a new entity type step by step, etc).

---

### Task 15: Write waaseyaa:access-control skill

**Files:**
- Create: `skills/waaseyaa/access-control.md`

**Step 1: Write skill (~350 lines)**

Front matter:
```yaml
name: waaseyaa:access-control
description: Use when working on access policies, permissions, entity/field access checking, authorization middleware, or any code in packages/access/, packages/user/src/Middleware/
```

Key content:
- Asymmetric semantics (the most critical knowledge): entity uses `isAllowed()`, field uses `!isForbidden()`
- Policy implementation checklist: implement both interfaces, use PolicyAttribute, test with anonymous intersection-type classes
- EntityAccessHandler combining logic: OR, Forbidden short-circuits
- AccountInterface lives in access package, concrete User/AnonymousUser in user package — never import user from access
- Layer discipline: access uses string constants for policy attribute scanning, not `::class` references
- Route access: `_public`, `_permission`, `_role`, `_gate` options
- Related specs: `waaseyaa_get_spec access-control`, `waaseyaa_get_spec field-access`

---

### Task 16: Write waaseyaa:api-layer skill

**Files:**
- Create: `skills/waaseyaa/api-layer.md`

**Step 1: Write skill (~300 lines)**

Front matter:
```yaml
name: waaseyaa:api-layer
description: Use when working on JSON:API endpoints, resource serialization, query parsing, schema generation, or any code in packages/api/, packages/routing/
```

Key content:
- JsonApiController CRUD method signatures
- ResourceSerializer paired nullable pattern
- QueryParser → QueryApplier → EntityQuery → post-fetch filtering pipeline
- SchemaPresenter extensions (x-widget, x-access-restricted, etc.)
- LIKE wildcard escaping pattern
- SchemaController prototype entity pattern
- Related spec: `waaseyaa_get_spec api-layer`

---

### Task 17: Write waaseyaa:admin-spa skill

**Files:**
- Create: `skills/waaseyaa/admin-spa.md`

**Step 1: Write skill (~200 lines)**

Front matter:
```yaml
name: waaseyaa:admin-spa
description: Use when working on the admin SPA, Vue components, composables, i18n, or any code in packages/admin/
```

Key content:
- Nuxt 3 + Vue 3 + TypeScript stack
- Four composables and their responsibilities
- Schema-driven form rendering pattern
- x-access-restricted → disabled widget (not hidden)
- i18n in packages/admin/app/i18n/en.json
- Build verification: `cd packages/admin && npm run build`
- No test framework — TypeScript compilation is the verification
- Related spec: `waaseyaa_get_spec admin-spa`

---

### Task 18: Write waaseyaa:ai-integration skill

**Files:**
- Create: `skills/waaseyaa/ai-integration.md`

**Step 1: Write skill (~200 lines)**

Front matter:
```yaml
name: waaseyaa:ai-integration
description: Use when working on AI schema generation, agent execution, pipeline orchestration, vector embeddings, or any code in packages/ai-schema/, packages/ai-agent/, packages/ai-pipeline/, packages/ai-vector/
```

Key content:
- EntityJsonSchemaGenerator: generate() and generateAll() signatures
- AgentExecutor: execute(), dryRun(), executeTool() with audit logging
- Pipeline: ConfigEntity with ordered steps by weight, PipelineExecutor chains output→input
- SchemaRegistry for tool/schema lookup
- Related spec: `waaseyaa_get_spec ai-integration`

---

### Task 19: Write waaseyaa:infrastructure skill

**Files:**
- Create: `skills/waaseyaa/infrastructure.md`

**Step 1: Write skill (~300 lines)**

Front matter:
```yaml
name: waaseyaa:infrastructure
description: Use when working on foundation services, events, cache, database queries, plugins, migrations, or any code in packages/foundation/, packages/cache/, packages/database-legacy/, packages/plugin/, packages/queue/
```

Key content:
- ServiceProvider register() vs boot() lifecycle
- DomainEvent three-channel dispatch (sync/async/broadcast)
- DatabaseInterface vs PdoDatabase — when to use which
- Query builder preferred over raw PDO
- Cache atomic writes pattern
- No psr/log — use error_log()
- Best-effort side effects in event listeners
- Layer discipline: Foundation never imports from higher layers
- Related specs: `waaseyaa_get_spec infrastructure`, `waaseyaa_get_spec package-discovery`

---

### Task 20: Write waaseyaa:middleware-pipeline skill

**Files:**
- Create: `skills/waaseyaa/middleware-pipeline.md`

**Step 1: Write skill (~250 lines)**

Front matter:
```yaml
name: waaseyaa:middleware-pipeline
description: Use when working on HTTP/event/job middleware, the request pipeline, public/index.php, or any middleware in packages/*/src/Middleware/
```

Key content:
- Three typed pipeline interfaces (Http, Event, Job)
- Onion pattern implementation
- public/index.php pipeline: Session → Authorization → Route
- Middleware discovery via #[AsMiddleware] attribute
- MiddlewarePipelineCompiler
- php://input single-read gotcha
- Middleware interface naming: {Type}MiddlewareInterface, {Type}HandlerInterface
- Related spec: `waaseyaa_get_spec middleware-pipeline`

---

### Task 21: Commit all skills

**Step 1: Review skill count**

Run: `ls skills/waaseyaa/*.md | wc -l`
Expected: 7

**Step 2: Commit**

```bash
git add skills/
git commit -m "feat: add 7 domain specialist skills for codified context Tier 2

Project-specific skills encoding subsystem knowledge for entity system,
access control, API layer, admin SPA, AI integration, infrastructure,
and middleware pipeline.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 4: Constitution Expansion (Tier 1)

### Task 22: Expand CLAUDE.md with orchestration and checklists

**Files:**
- Modify: `CLAUDE.md`

**Step 1: Add Orchestration section after Project Structure**

Insert after the `## Project Structure` section (after line 11):

```markdown
## Orchestration

When working on a subsystem, invoke the matching specialist skill and retrieve the cold memory spec for deep context.

| File pattern | Specialist skill | Cold memory spec |
|---|---|---|
| packages/entity/*, packages/entity-storage/*, packages/field/* | waaseyaa:entity-system | entity-system |
| packages/access/*, packages/user/src/Middleware/* | waaseyaa:access-control | access-control, field-access |
| packages/api/*, packages/routing/* | waaseyaa:api-layer | api-layer |
| packages/admin/* | waaseyaa:admin-spa | admin-spa |
| packages/ai-*/* | waaseyaa:ai-integration | ai-integration |
| packages/foundation/*, packages/cache/*, packages/database-legacy/* | waaseyaa:infrastructure | infrastructure, package-discovery |
| packages/mcp/* | — | mcp-endpoint |
| public/index.php, packages/*/src/Middleware/* | waaseyaa:middleware-pipeline | middleware-pipeline |

To retrieve a spec: use the `waaseyaa_get_spec` MCP tool (e.g., `waaseyaa_get_spec entity-system`).
To search across specs: use `waaseyaa_search_specs` MCP tool.
```

**Step 2: Add Layer Architecture section after Orchestration**

```markdown
## Layer Architecture

- Layer 0 (Foundation): foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, state, validation
- Layer 1 (Core Data): entity, entity-storage, access, user, config, field
- Layer 2 (Content Types): node, taxonomy, media, path, menu
- Layer 3 (Services): workflows
- Layer 4 (API): api, routing
- Layer 5 (AI): ai-schema, ai-agent, ai-pipeline, ai-vector
- Layer 6 (Interfaces): cli, admin, mcp, ssr, telescope

Rule: packages can only import from their own layer or lower. Upward communication via DomainEvents.
```

**Step 3: Add Operation Checklists section before Commands**

```markdown
## Operation Checklists

**Adding an entity type:**
1. Define EntityType with named constructor params (id, label, class, keys)
2. Create entity class extending ContentEntityBase, constructor accepts `(array $values)`, hardcodes entityTypeId and entityKeys
3. Register in EntityTypeManager
4. SqlSchemaHandler auto-creates table from EntityType definition
5. Add AccessPolicyInterface implementation with `#[PolicyAttribute]`
6. Add routes in RouteBuilder with access options (_permission, _role, etc.)

**Adding an access policy:**
1. Create class implementing AccessPolicyInterface (+ FieldAccessPolicyInterface if field-level needed)
2. Add `#[PolicyAttribute]` attribute
3. Implement `appliesTo(string $entityTypeId): bool` to scope
4. Test with anonymous classes implementing intersection types (PHPUnit can't mock intersections)

**Adding an API endpoint:**
1. Add route in RouteBuilder with access options
2. Implement controller method
3. Wire EntityAccessHandler + AccountInterface if access-aware
4. Update SchemaPresenter if schema changes

**Adding middleware:**
1. Implement HttpMiddlewareInterface (or Event/Job variant)
2. Add `#[AsMiddleware(pipeline: 'http', priority: N)]` attribute
3. Middleware discovered by MiddlewarePipelineCompiler
```

**Step 4: Add drift warning to Architecture Gotchas section**

Append to the end of the Architecture Gotchas section:
```markdown
- **Spec drift**: When refactoring a subsystem, update the relevant `docs/specs/` file. Stale specs cause agents to generate code conflicting with recent changes. Use `waaseyaa_search_specs` MCP tool to find affected specs.
```

**Step 5: Verify CLAUDE.md is well-formed**

Run: `wc -l CLAUDE.md`
Expected: ~170-200 lines

**Step 6: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: expand CLAUDE.md with orchestration, layers, and checklists

Codified context Tier 1: trigger table routing file patterns to
specialist skills and specs, 7-layer architecture reference,
operation checklists for common tasks, drift warning.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 5: Maintenance Tooling

### Task 23: Write drift detection script

**Files:**
- Create: `tools/drift-detector.sh`

**Step 1: Write drift-detector.sh (~80 lines)**

```bash
#!/usr/bin/env bash
# Drift detector: maps recent git changes to subsystem specs that may need updating.
# Usage: tools/drift-detector.sh [N_COMMITS]
#        Defaults to 10 commits.

set -euo pipefail

N=${1:-10}
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# File pattern → spec mapping (matches CLAUDE.md orchestration table)
declare -A SPEC_MAP
SPEC_MAP["packages/entity/"]="entity-system"
SPEC_MAP["packages/entity-storage/"]="entity-system"
SPEC_MAP["packages/field/"]="entity-system"
SPEC_MAP["packages/access/"]="access-control field-access"
SPEC_MAP["packages/user/src/Middleware/"]="access-control"
SPEC_MAP["packages/api/"]="api-layer"
SPEC_MAP["packages/routing/"]="api-layer middleware-pipeline"
SPEC_MAP["packages/admin/"]="admin-spa"
SPEC_MAP["packages/ai-schema/"]="ai-integration"
SPEC_MAP["packages/ai-agent/"]="ai-integration"
SPEC_MAP["packages/ai-pipeline/"]="ai-integration"
SPEC_MAP["packages/ai-vector/"]="ai-integration"
SPEC_MAP["packages/foundation/"]="infrastructure package-discovery middleware-pipeline"
SPEC_MAP["packages/cache/"]="infrastructure"
SPEC_MAP["packages/database-legacy/"]="infrastructure"
SPEC_MAP["packages/mcp/"]="mcp-endpoint"
SPEC_MAP["public/index.php"]="middleware-pipeline"

# Get changed files in last N commits
CHANGED_FILES=$(cd "$PROJECT_DIR" && git diff --name-only HEAD~${N}..HEAD 2>/dev/null || echo "")

if [ -z "$CHANGED_FILES" ]; then
  echo "No changes in last $N commits."
  exit 0
fi

# Find affected specs
declare -A AFFECTED_SPECS
while IFS= read -r file; do
  for pattern in "${!SPEC_MAP[@]}"; do
    if [[ "$file" == "$pattern"* ]]; then
      for spec in ${SPEC_MAP[$pattern]}; do
        AFFECTED_SPECS[$spec]=1
      done
    fi
  done
done <<< "$CHANGED_FILES"

if [ ${#AFFECTED_SPECS[@]} -eq 0 ]; then
  echo "No specs affected by changes in last $N commits."
  exit 0
fi

echo "Files changed in last $N commits affect these specs:"
echo ""
for spec in "${!AFFECTED_SPECS[@]}"; do
  SPEC_FILE="$PROJECT_DIR/docs/specs/${spec}.md"
  if [ -f "$SPEC_FILE" ]; then
    SPEC_MTIME=$(stat -c %Y "$SPEC_FILE" 2>/dev/null || stat -f %m "$SPEC_FILE" 2>/dev/null)
    LAST_COMMIT_TIME=$(cd "$PROJECT_DIR" && git log -1 --format=%ct -- "docs/specs/${spec}.md" 2>/dev/null || echo "0")
    LATEST_CODE_CHANGE=$(cd "$PROJECT_DIR" && git log -1 --format=%ct HEAD~${N}..HEAD 2>/dev/null || echo "0")
    if [ "$LAST_COMMIT_TIME" -lt "$LATEST_CODE_CHANGE" ]; then
      echo "  ⚠  docs/specs/${spec}.md — may be stale (last updated before recent code changes)"
    else
      echo "  ✓  docs/specs/${spec}.md — recently updated"
    fi
  else
    echo "  ✗  docs/specs/${spec}.md — MISSING"
  fi
done
```

**Step 2: Make executable**

Run: `chmod +x tools/drift-detector.sh`

**Step 3: Test it**

Run: `tools/drift-detector.sh 5`
Expected: Lists specs affected by recent commits with staleness indicators

**Step 4: Commit**

```bash
git add tools/drift-detector.sh
git commit -m "feat: add drift detection script for spec staleness

Maps recent git changes to subsystem specs and reports which
may need review. Usage: tools/drift-detector.sh [N_COMMITS]

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 6: Repeatable Skill

### Task 24: Write codified-context skill

**Files:**
- Create: `skills/codified-context.md`

**Step 1: Write skill (~200 lines)**

This skill encodes the process from the paper for applying to any project. Front matter:

```yaml
name: codified-context
description: Use when setting up or auditing a project's codified context infrastructure (CLAUDE.md constitution, domain specialist skills, subsystem specs with MCP retrieval). Based on arxiv.org/abs/2602.20478.
```

Content:
1. **Audit existing context** — Check for CLAUDE.md, memory files, docs/plans, existing skills. Count lines of code vs knowledge.
2. **Analyze codebase structure** — Identify packages/modules, map to layers, find natural domain groupings (5-8 groups typical).
3. **Tier 1: Expand constitution** — Add orchestration trigger table (file patterns → skills → specs), layer reference, operation checklists, drift warning.
4. **Tier 2: Create domain specialist skills** — One skill per domain group. Structure: scope, key interfaces, architecture, common mistakes, testing patterns, related specs. >50% domain knowledge.
5. **Tier 3: Write subsystem specs** — Mine existing docs/plans for enduring architectural knowledge. Write for AI consumption (file paths, code patterns, interface signatures). Build MCP retrieval server (3 tools: list, get, search).
6. **Maintenance** — Drift detection script mapping git changes to spec staleness. Session discipline: update specs when behavior changes.

Include the checklist from the paper's practitioner guidelines:
- Basic constitution yields outsized returns
- Repeated explanation signals codification need
- Stale specs silently mislead
- Specialist agents resolve stuck sessions

**Step 2: Commit**

```bash
git add skills/codified-context.md
git commit -m "feat: add codified-context skill for repeatable infrastructure setup

Encodes the process from arxiv.org/abs/2602.20478 for applying
three-tier codified context to any project.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

## Phase 7: Final Verification

### Task 25: End-to-end verification

**Step 1: Verify all artifacts exist**

Run:
```bash
echo "=== Specs ===" && ls docs/specs/*.md | wc -l && \
echo "=== Skills ===" && ls skills/waaseyaa/*.md skills/codified-context.md | wc -l && \
echo "=== MCP Server ===" && ls tools/spec-retrieval/server.js tools/spec-retrieval/package.json && \
echo "=== Drift Detector ===" && ls tools/drift-detector.sh && \
echo "=== CLAUDE.md ===" && wc -l CLAUDE.md
```

Expected:
- 10 specs
- 8 skills (7 domain + 1 codified-context)
- MCP server files present
- Drift detector present
- CLAUDE.md ~170-200 lines

**Step 2: Run MCP server tests**

Run: `cd tools/spec-retrieval && node --test test.js`
Expected: All tests pass

**Step 3: Run drift detector**

Run: `tools/drift-detector.sh 5`
Expected: Output without errors

**Step 4: Run existing project tests (regression check)**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All existing tests still pass (we only added docs/tools, no PHP changes)

**Step 5: Final commit log review**

Run: `git log --oneline -10`
Expected: Clean sequence of commits for each phase
