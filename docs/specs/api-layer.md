# API Layer

<!-- Spec reviewed 2026-06-04 - PR #1614 (real content types): schema + serialization become bundle-aware. `SchemaController::show(string $entityTypeId, ?string $bundle = null)` scopes the emitted JSON Schema to a content type's bundle via `EntityTypeManagerInterface::resolveFieldDefinitions`, building the prototype entity with the bundle key, so a bundled entity (e.g. a node of bundle `page`) exposes its per-bundle fields (`body`, `blocks`) and not just the shared core fields; `SchemaRouter` threads an optional `?bundle` query param. `ResourceSerializer` filters/casts attributes through the same bundle-aware `resolveFieldDefinitions($entityTypeId, $entity->bundle())`. The admin AdminSurface schema action (`GenericAdminSurfaceHost::handleSchema`) resolves the bundle from the payload `bundle` or from the entity named by `id` and calls the same controller, so the admin edit form, JSON:API, and GraphQL all read through one bundle-aware path. -->
<!-- Spec reviewed 2026-06-09 - alpha.201 #1603: BroadcastStorageScheduleEntries downgraded its "BroadcastStorage not bound" log from warning to debug (unbound is the normal state for apps that do not opt into SSE broadcasting). Log-level only — no change to the API/broadcasting contract, routes, or schedule-registration behaviour. -->
<!-- Spec reviewed 2026-05-28 - M5C WP01 (mcp-endpoint-admin-01KSEFTL) MCP-admin REST surface: BuiltinRouteRegistrar gains three `_role: admin` routes — GET /api/mcp/tools, GET /api/mcp/tools/{name}, GET /api/mcp/server-config — all dispatched by `McpAdminApiRouter` (supports() matches `_controller` containing `McpAdminController::`) to `McpAdminController` actions `tools`, `tool`, `serverConfig`. Controller deps `ToolRegistryReadModelInterface` and `ServerConfigReadModelInterface` (both `packages/api/src/McpAdmin/`) are nullable: when the bindings are absent the controller returns empty-shape JSON (`{data:{rows:[]}}` / `{data:{tool:null}}` / `{data:{config:null}}`) rather than crashing. The bindings are registered in `packages/mcp/src/McpServiceProvider.php` (Layer 6) via `$this->resolve(...)` / `$this->resolveOptional(...)` — the previous `$this->make(...)` form was retired (no such method on the L0 ServiceProvider base; it crashed boot on installs that exercised the MCP-admin surface). Concrete implementations live in `packages/mcp/src/Admin/{ToolRegistryReadModel,ServerConfigReadModel}.php`. Per-tool detail uses `ToolDetail` (name, summary, description, category, requiredCapabilities, inputSchema JSON Schema 2020-12, recentInvocations list); registry-index rows use `ToolRegistryRow` (name, summary, category, requiredCapabilities). Server-config snapshot uses `ServerConfigSnapshot` (transport `streamable-http|sse`, protocolVersion, registeredClients, serverCapabilities) and per-client `RegisteredClient` (clientId, addedAt, lastSeenAt, tokenFingerprint). `RecentInvocation` carries traceUuid, invokedAt, account, outcome `ok|error`, errorMessage, latencyMs and may be redacted to `_redacted:true` when an `EntityAccessHandler` + `AccountInterface` are wired and the account lacks `ai_observability.view_traces`. NFR-003: no plaintext bearer token ever appears in any response — `tokenFingerprint` is the 16-char lowercase-hex SHA-256 prefix. -->
<!-- Spec reviewed 2026-05-25 - mission ocap-audit-log-substrate-01KSEFTF WP03: JSON:API audit query endpoint `GET /api/audit/events` (admin-only, filterable by kind/account/entity/date-range, page[limit] max 500, default 50, ordered by created_at DESC). New `AuditQueryReadModelInterface` + `AuditEventResource` + `AuditQueryDto` (api-local DTOs); `ApiAuditQueryAdapter implements AuditQueryReadModelInterface` bridges L0 `AuditQueryInterface` into L4 DTOs (api→audit = downward = allowed). `AuditQueryController` is null-safe: null read model → empty `{data:[], meta:{total:0}}` (dead-code guard FR-013). `AuditApiRouter implements DomainRouterInterface` mirrors WorkflowGuardsApiRouter shape. `ApiServiceProvider::register()` adds `singleton(AuditQueryReadModelInterface::class, ApiAuditQueryAdapter(...))` wired via string-based resolution (waaseyaa/audit in require-dev, C-002). `ApiServiceProvider::httpDomainRouters()` gains an `AuditApiRouter` block via `resolveOptional`. Route registered in `BuiltinRouteRegistrar` (WP01). Refs gap-matrix-A3, DIR-004. -->
<!-- Spec reviewed 2026-05-25 - M4A-5 Phase 1 (#1470) read-only workflow guards: new WorkflowGuardsController + WorkflowGuardsApiRouter follow the same DomainRouterInterface shape. ApiServiceProvider gains a fourth resolveOptional() block for AuthoringRoleMatrix. GET /api/workflow-definitions/{workflow_id}/guards returns {data: [{bundle, transition, required_roles}, ...]} or 404 JSON:API error envelope when the workflow id isn't in the registry. Closure-based workflow registry mirrors WorkflowDefinitionsController (M4A-1). Phase 2 (edit) deferred to #1579 (M4A-5b). -->
<!-- Spec reviewed 2026-05-24 - #1576 queue dashboard listJobs extension: QueueController.index() now accepts ?status=failed|queued|in_progress|all (default failed for M4B backward compat). Failed branch keeps the FailedJobRepository path; queued/in_progress branches delegate to TransportInterface::listJobs(); all merges. ApiServiceProvider's queue resolveOptional block also resolves TransportInterface (optional, falls back to failed-only). QueueController constructor gains nullable ?TransportInterface third arg. JSON:API meta envelope unchanged ({page, per_page, total}) so existing callers stay compatible. -->
<!-- Spec reviewed 2026-05-24 - M4C (#1472) admin notification channels dashboard: new NotificationController + NotificationAdminApiRouter follow the established DomainRouterInterface shape. ApiServiceProvider gains a third resolveOptional() block for NotificationDispatcher::class. New endpoints GET /api/notification/channels (lists `{type, class}` map) and POST /api/notification/channels/{type}/test (synthetic test send, never serialises a `\Throwable`). Pattern parity with QueueController. Delivery log + channel enable/disable deferred to follow-up #1578. -->
<!-- Spec reviewed 2026-05-24 - M4B (#1471) admin queue + scheduler dashboards: two new domain routers (QueueAdminApiRouter, SchedulerAdminApiRouter) and matching controllers (QueueController, SchedulerController) land under packages/api/src/. Both follow the existing DomainRouterInterface shape (supports/handle, JSON:API error envelope) and are wired by ApiServiceProvider::httpDomainRouters() via the same resolveOptional() pattern AuthOidcRouteServiceProvider uses — Layer-0 bindings (FailedJobRepositoryInterface + QueueInterface for queue; ScheduleInterface + ScheduleRunner + ScheduleStateRepository for scheduler) resolved at boot, skipped gracefully on slimmed-down installs. Routes registered in BuiltinRouteRegistrar with `_role: admin` (the controllers never re-check). Spec body is otherwise unchanged: JSON:API resource contract, pagination meta, DomainRouterInterface dispatch all carry over verbatim. See docs/specs/admin-spa.md for the consumer-side route inventory. -->
<!-- Spec reviewed 2026-05-20 - M-D scheduler-entry sprint: BroadcastStorage::prune() signature is int $retentionDays = 7 (was $maxAgeSeconds); BroadcastStorageScheduleEntries (packages/api/src/Schedule/) registers a nightly prune task via the new ScheduleEntriesInterface auto-discovery — _broadcast_log retention is now automatic with a 7-day default, configurable via schedule.broadcast_log_retention_days. BroadcastStorage public API surface (push/poll/maxId) otherwise unchanged. -->
<!-- Spec reviewed 2026-05-20 - BroadcastStorage gained public maxId(array $channels = []): int returning the high-water-mark row id (0 when empty), filterable by channel. Used by BroadcastRouter to start new EventSource connections at "now" instead of replaying history — see docs/specs/infrastructure.md for the SSE-side semantics. Storage contract is otherwise unchanged; poll(), push(), prune() unaffected. -->
<!-- Spec reviewed 2026-05-19 - mission sql-entity-query-access-checking-01KRYP15 (#1495): `JsonApiController` index endpoints (:52, :63, :450) now bind the request's authenticated account into `EntityQueryInterface::setAccount($this->account)` so per-row access filtering is applied at the storage layer. Previously these listings leaked rows the requester could not view. Test fixture `InMemoryEntityQuery` got the new `setAccount()` method. The new query-layer enforcement is documented in `docs/specs/access-control.md`; this spec's JSON:API contracts (resource shape, pagination, `meta.total`) are unchanged — `meta.total` now reflects the access-filtered cardinality, which was the intended semantics from the start. -->
<!-- Spec reviewed 2026-05-11 - M4A-4 dry-run endpoint: new POST /api/workflow-definitions/dry-run (admin-role-gated) dispatched by WorkflowDryRunController; routed via updated WorkflowDefinitionsApiRouter (supports() extended); wired in BuiltinRouteRegistrar. Returns AccessResult shape without mutating any entity. -->
<!-- Spec reviewed 2026-05-11 - M4A-2 (#1430 / umbrella #1414) WorkflowDefinitionsController::serializeWorkflow() now includes `metadata: array<string, mixed>` per state in the JSON response (additive 3-line extension; @return type updated; new assertion in WorkflowDefinitionsControllerTest). No change to endpoint shape at the workflow level or to JSON:API entity contracts. -->
<!-- Spec reviewed 2026-05-20 - #1531 ResourceSerializer now strips ALWAYS_INTERNAL_FIELDS (['pass','password','password_hash']) and honors FieldDefinition settings['internal'] => true (e.g. two_factor_secret) before EntityAccessHandler::filterFields(); #1532 api.user.me bumped to priority(10) in AuthOidcRouteServiceProvider so it beats JsonApiRouteProvider's /api/user/{id} catch-all. -->
<!-- Spec reviewed 2026-05-11 - M4A-1 (#1428 / umbrella #1414) new WorkflowDefinitionsController under Waaseyaa\Api\Workflow\ exposing GET /api/workflow-definitions (admin-role-gated, JSON-shaped `{data: WorkflowDefinition[]}`). Not part of the JSON:API entity layer documented in this spec — it is a sibling read-only endpoint mirroring the CodifiedContextController pattern (separate router class, registered in BuiltinRouteRegistrar, dispatched by WorkflowDefinitionsApiRouter). No change to entity JSON:API contracts, ResourceSerializer, or SchemaPresenter. -->
<!-- Spec reviewed 2026-05-13 - M-006 entity-storage-translations-v1: TranslationController updated to call $entity->removeTranslation() directly (interface method, no longer guarded by method_exists since TranslatableInterface now declares it). TranslatableTestEntity + ReadOnlyTranslatableTestEntity fixtures gained `default_langcode` entity key required by the new boot validation. No change to JSON:API entity endpoint contracts, ResourceSerializer, or SchemaPresenter. Full translation surface at docs/specs/entity-storage-translations-v1.md. -->
<!-- Spec reviewed 2026-05-10 - M3B (#1413) SchemaPresenter: when registry yields a bundle enum, the bundle property gains x-widget=select, x-required=true, x-label='Bundle', x-weight=-100 so it renders as a real user-facing field. Default (no registry / empty enum) leaves the property hidden. -->
<!-- Spec reviewed 2026-05-10 - M3A (#1413) SchemaPresenter ctor gains optional FieldDefinitionRegistryInterface; schema endpoint exposes top-level `x-bundle-key` and (when registry wired) `enum` on the bundle property. Documented in admin-spa.md; api-layer contract surface itself unchanged for non-bundle entity types. -->
<!-- Spec reviewed 2026-05-10 - WP05 php-8.5 upgrade: @PHP8x5Migration cs-fixer pass — ApiServiceProvider, JsonApiController, ResourceSerializer, CodifiedContextController, DiscoveryApiHandler, AuthOidcRouteServiceProvider touched by new_expression_parentheses rule only; no semantic change to API layer contracts. -->
<!-- Spec reviewed 2026-05-08 - WaaseyaaRouter::match() maps Symfony UrlMatcher failures to Waaseyaa\Routing\Exception\RouteNotFoundException / RouteMethodNotAllowedException (previous callers expecting Symfony ResourceNotFoundException from match() must migrate); HttpKernel catches those Waaseyaa types for JSON 404/405; RouteBuilder::controller() + normalizeControllerDefault() coerce `[FQCN, method]` to `FQCN::method`; HttpKernel merges match params through normalizeControllerDefault (foundation-symfony-fallback-elimination-01KQZR1 WP03–WP04) -->
<!-- Spec reviewed 2026-04-26 - ResourceSerializer prefers non-integer string id() for JSON:API resource id (config machine names); JsonApiController store machine-name path uses config heuristics (id=bundle, or non-default id without bundle, or no uuid); API integration fixtures now map per-entity metadata classes -->
<!-- Spec reviewed 2026-04-25 - RouteBuilder::bind + RouteFingerprint for SSR app-controller binding metadata; see docs/specs/app-controller-invocation.md -->
<!-- Spec reviewed 2026-04-24 - CodifiedContextController JSON camelCase (admin useCodifiedContext); CodifiedContextApiRouter; agent-context HTTP paths; CodifiedContextSessionStoreInterface + CodifiedContextSessionRow (packages/api); telescope-agent-context-telemetry.md; waaseyaa/telescope ships CodifiedContextSessionStoreAdapter (#1339 L4/L6 boundary) -->
<!-- Spec reviewed 2026-04-24 - Auth and OIDC HTTP route tables: AuthOidcRouteServiceProvider + OidcHttpRoutes in packages/routing (waaseyaa/routing requires auth+oidc); BuiltinRouteRegistrar still calls all providers' routes() -->
<!-- Spec reviewed 2026-04-22 - WaaseyaaRouter: reject duplicate route names; RouteBuilder::priority + sortRoutesByPriority (_waaseyaa_priority) for deterministic ordering -->
<!-- Spec reviewed 2026-04-22 - SchemaPresenter/ResourceSerializer consume normalized FieldDefinitionInterface contracts; legacy array inputs normalized at presenter boundary -->
<!-- Spec reviewed 2026-04-05 - #598 replace instanceof dispatch with JsonApiDocumentException in TranslationController -->
<!-- Spec reviewed 2026-04-08 - composer manifest policy normalization for packages/api and packages/routing; no API/runtime behavior change -->
<!-- Spec reviewed 2026-04-09 - packages/routing/composer.json churn (manifest policy); routing and JSON:API behavior unchanged -->
<!-- Spec reviewed 2026-04-08g - symfony/routing require ^7.0 (#1151); no routing behavior change — symfony-version-floors.md -->
<!-- Spec reviewed 2026-04-09 - Discovery API dispatch: `DiscoveryRouter` lives in `Waaseyaa\Api\Http\Router` and is registered via `ApiServiceProvider::httpDomainRouters()`; foundation `HttpKernel` merges provider routers after built-in routers through `McpRouter` (#1129) -->
<!-- Spec reviewed 2026-04-08 - JSON:API sparse fieldsets filter relationships via `SparseFieldsetApplicator` (#794) -->
<!-- Spec reviewed 2026-04-09k - `ResourceSerializer`, `DiscoveryRouter`, and `DiscoveryApiHandler` build attribute/visibility maps via `EntityValues::toCastAwareMap()` (#1181 ST-8) -->
<!-- Spec reviewed 2026-04-09 ST-9 - JSON:API attribute pipeline cross-linked to docs/specs/jsonapi.md; ResourceSerializer uses toCastAwareMap (#1181) -->
<!-- Spec reviewed 2026-04-09 ST-10 - ResourceSerializer delegates JSON value normalization to EntityValues::normalizeValueForJson() (#1181) -->
<!-- Spec reviewed 2026-04-09 - SchemaPresenter: admin JSON Schema from field definitions, not EntityBase::$casts; cross-link entity-system #1184 -->
<!-- Spec reviewed 2026-05-01 - AccessChecker canonical placement: source lives at packages/access/src/AccessChecker.php with namespace Waaseyaa\Access; routing package table row corrected; routing dir-tree no longer lists AccessChecker.php (mission #824 WP05 surface A, closes #832) -->
<!-- Spec reviewed 2026-05-01 - JsonApiRouteProvider route table now enumerates the public `api.discovery` route alongside the five per-entity-type CRUD routes; ApiDiscoveryController response contract documented (meta {api, version} + links {self, <entity_type>: {href, meta.type}}) and exercised by an end-to-end integration test (mission #824 WP06 surface A, closes #841) -->
<!-- Spec reviewed 2026-05-05 - Controller parameter binding section added: SSR `AppParameterBindingBuilder` implicit-array shim (post-#1390) — unannotated `array $params` → `#[MapRoute]`, `array $query` → `#[MapQuery]`, other unannotated `array $X` → `[]` with `implicit_array_unbound` notice; structured `dispatcher.deprecation` log payload (keys `channel`, `event`, `controller_class`, `method`, `parameter_name`, `recommended_attribute`) amortized to once per `(controller_class, method, parameter_name)` per FPM worker lifetime via the upstream `AppControllerMethodInvoker::$specCache` static (#1392 erratum). Cross-links the canonical contract artifact (mission `post-1390-dispatcher-reconciliation-01KQTTJS`). -->

Technical specification for the Waaseyaa JSON:API layer and routing system. This document covers the `packages/api/` and `packages/routing/` packages, which together provide RESTful CRUD endpoints, resource serialization, query parsing, JSON Schema presentation, route building, and access checking. The current post-M10 baseline uses package-owned service providers for API route registration: `packages/api/composer.json` declares `Waaseyaa\Api\ApiServiceProvider`, and that provider delegates CRUD route registration to `JsonApiRouteProvider` while foundation keeps only shared infrastructure endpoints.

**Cast-aware attributes (#1181):** How `$casts` interact with JSON:API `attributes` (diagrams, invariants, write path) is specified in **`docs/specs/jsonapi.md`**. Entity-level casting and hydration are in **`docs/specs/entity-system.md`**.

## Packages

### Package-owned route registration

`Waaseyaa\Api\ApiServiceProvider` is declared in `packages/api/composer.json` under `extra.waaseyaa.providers`. Its `routes()` method is the authoritative entry point for JSON:API CRUD route registration and delegates to `JsonApiRouteProvider` when an `EntityTypeManager` is available.

Foundation still wires several shared HTTP surfaces that are not entity-package specific (routes and foundation routers), including `/api/schema/{entity_type}`, `/api/openapi.json`, `/api/entity-types`, broadcast, MCP, and SSR catch-all routing. **Discovery** read models are implemented in the API package: `Waaseyaa\Api\Http\Router\DiscoveryRouter` implements `DomainRouterInterface` and is registered from `ApiServiceProvider::httpDomainRouters()` so discovery stays co-located with `DiscoveryApiHandler` and JSON:API tooling.

### Codified context session endpoints

`CodifiedContextController` exposes operator JSON for **agent-context** (codified-context) **session** telemetry. Canonical paths are under `GET /api/telescope/agent-context/sessions` (see **`docs/specs/telescope-agent-context-telemetry.md`**). **`BuiltinRouteRegistrar`** also registers legacy `/api/telescope/codified-context/…` aliases for the same controller actions. Dispatch is handled by **`Waaseyaa\Foundation\Http\Router\CodifiedContextApiRouter`**, which constructs the controller with an optional **`CodifiedContextSessionStoreInterface`** from **`HttpKernel::getCodifiedContextSessionStore()`** (defaults to null until a host wires the store via **`HttpKernel::setCodifiedContextSessionStore()`**). The controller type-hints the port only; row shapes use **`CodifiedContextSessionRow`** (`packages/api/src/CodifiedContext/`). **`Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextSessionStoreAdapter`** bridges Telescope’s SQLite codified-context store to the port; `waaseyaa/telescope` `require`s `waaseyaa/api` for those interfaces. Unit tests may register an in-memory or stub store without loading Telescope.

**Response JSON (admin SPA):** Successful payloads use **camelCase** keys aligned with the admin composable **`useCodifiedContext`** (`packages/admin/app/composables/useCodifiedContext.ts`): session list and `GET …/sessions/{id}` return objects with `sessionId`, `startedAt`, `endedAt`, `durationMs`, `eventCount`, `latestDriftScore`, `latestSeverity`, etc. (timestamps as ISO-8601/RFC3339 strings). `GET …/events` returns rows with `eventType` (semantic type: stored `event_type` underscores mapped to dots, e.g. `context_load` → `context.load`), `createdAt`, and `data`. `GET …/validation` returns a single flat validation object (`driftScore` as 0–100 integer, `components`, `issues`, `recommendation`, `validatedAt`) — not a nested `report` envelope.

### MCP-admin REST surface

The MCP-admin read API powers the admin SPA's MCP-endpoint dashboard (M5C WP01, mission `mcp-endpoint-admin-01KSEFTL`). All three routes are registered in `BuiltinRouteRegistrar` with `_role: admin`; the controller does **not** re-check the role (NFR-001 / DIR-004).

| Route | Method | Controller action | Response shape |
|-------|--------|------------------|----------------|
| `/api/mcp/tools` | `GET` | `McpAdminController::tools` | `{data: {rows: list<{name, summary, category, requiredCapabilities}>}}` |
| `/api/mcp/tools/{name}` | `GET` | `McpAdminController::tool` | `{data: {tool: ToolDetail|null}}` |
| `/api/mcp/server-config` | `GET` | `McpAdminController::serverConfig` | `{data: {config: ServerConfigSnapshot|null}}` |

Dispatch lives in `Waaseyaa\Api\Http\Router\McpAdminApiRouter` (implements `DomainRouterInterface`; `supports()` matches when the `_controller` attribute contains `McpAdminController::`). It mirrors the `MercureMonitorApiRouter` shape and returns `application/vnd.api+json` with status 200, or a JSON:API error envelope for unknown actions (404) / invalid controller refs (500).

The `{name}` segment is `rawurldecode()`-ed once inside `tool()` so tool names containing dots (e.g. `bimaaji.search_specs`) survive double URL encoding by the SPA client.

**Read-model bindings.** The controller depends on two **api-local** interfaces under `Waaseyaa\Api\McpAdmin\`:

- `ToolRegistryReadModelInterface` — `listTools(): list<ToolRegistryRow>` and `findTool(string): ?ToolDetail`.
- `ServerConfigReadModelInterface` — `serverConfig(): ServerConfigSnapshot`.

Both interfaces live in L4 `packages/api/`. The concrete implementations live in L6 `packages/mcp/src/Admin/` (`ToolRegistryReadModel`, `ServerConfigReadModel`) and are bound in `packages/mcp/src/McpServiceProvider.php`. The provider resolves dependencies through `$this->resolve(...)` for required deps (`AgentToolRegistryInterface`, `McpAuthInterface`) and `$this->resolveOptional(...)` for optional deps (`RecentInvocationsQueryInterface`, which is absent on installs without `waaseyaa/ai-observability`). The previous `$this->make(...)` form was retired — that method does not exist on the L0 `ServiceProvider` base, and the unguarded call crashed kernel boot on installs that exercised the MCP-admin surface.

Both controller deps are nullable (`?ToolRegistryReadModelInterface = null`, `?ServerConfigReadModelInterface = null`) so slimmed-down installs without `waaseyaa/mcp` boot cleanly and the endpoints return empty-shape payloads (`{data: {rows: []}}`, `{data: {tool: null}}`, `{data: {config: null}}`) instead of 500.

**DTOs.** All under `packages/api/src/McpAdmin/`:

- `ToolRegistryRow` — `{name, summary, category, requiredCapabilities: list<string>}` (registry-index row).
- `ToolDetail` — `{name, summary, description, category, requiredCapabilities, inputSchema: array<string,mixed> (JSON Schema 2020-12), recentInvocations: list<RecentInvocation>}` (max 25 invocations).
- `RecentInvocation` — `{traceUuid, invokedAt, account, outcome: 'ok'|'error', errorMessage, latencyMs}`.
- `RegisteredClient` — `{clientId, addedAt, lastSeenAt, tokenFingerprint}` (16-char lowercase-hex SHA-256 prefix of the client bearer token).
- `ServerConfigSnapshot` — `{transport: 'streamable-http'|'sse', protocolVersion, registeredClients: list<RegisteredClient>, serverCapabilities: list<string>}`.

**NFR-003 (no plaintext token leak).** No plaintext bearer token ever appears in any response shape. Clients surface only via `tokenFingerprint`, which is enough for operator correlation without exposing the secret.

**Field-access redaction (M-A5 hook).** When the controller is wired with both an `EntityAccessHandler` and an authenticated `AccountInterface`, `serializeInvocations()` checks `ai_observability.view_traces` on the account. If the permission is missing, the row's `account` and `errorMessage` are nulled and `_redacted: true` is set so the SPA can render a placeholder. Without the access-handler+account pair the controller emits full invocation rows (the dashboard then relies on route-level `_role: admin` for protection).

### packages/api/

| File | Namespace | Purpose |
|------|-----------|---------|
| `src/JsonApiController.php` | `Waaseyaa\Api` | CRUD operations on entities (index, show, store, update, destroy) |
| `src/ResourceSerializer.php` | `Waaseyaa\Api` | Entity-to-JsonApiResource conversion with field access filtering |
| `src/JsonApiDocument.php` | `Waaseyaa\Api` | JSON:API document value object (data, errors, meta, links, included) |
| `src/JsonApiResource.php` | `Waaseyaa\Api` | JSON:API resource value object (type, id, attributes, relationships) |
| `src/SparseFieldsetApplicator.php` | `Waaseyaa\Api` | Applies `fields[type]` sparse fieldsets to attributes and relationships |
| `src/JsonApiError.php` | `Waaseyaa\Api` | JSON:API error value object with static factory methods |
| `src/JsonResponseTrait.php` | `Waaseyaa\Api` | Trait providing `json()` helper (returns Symfony `JsonResponse`) and `jsonBody()` request parser |
| `src/JsonApiRouteProvider.php` | `Waaseyaa\Api` | Auto-registers five CRUD routes per entity type |
| `src/Query/QueryParser.php` | `Waaseyaa\Api\Query` | Parses `$_GET` into ParsedQuery (filters, sorts, pagination, sparse fieldsets) |
| `src/Query/QueryApplier.php` | `Waaseyaa\Api\Query` | Applies ParsedQuery to EntityQueryInterface |
| `src/Query/QueryFilter.php` | `Waaseyaa\Api\Query` | Value object for a single filter condition |
| `src/Query/QuerySort.php` | `Waaseyaa\Api\Query` | Value object for a single sort directive |
| `src/Query/ParsedQuery.php` | `Waaseyaa\Api\Query` | Value object holding all parsed query components |
| `src/Query/PaginationLinks.php` | `Waaseyaa\Api\Query` | Generates self/first/prev/next pagination URLs |
| `src/Schema/SchemaPresenter.php` | `Waaseyaa\Api\Schema` | Converts EntityType definitions to JSON Schema with widget hints |
| `src/Controller/SchemaController.php` | `Waaseyaa\Api\Controller` | `GET /api/schema/{entity_type}` endpoint |
| `src/Controller/TranslationController.php` | `Waaseyaa\Api\Controller` | Translation sub-resource CRUD endpoints |
| `src/CodifiedContext/CodifiedContextSessionStoreInterface.php` | `Waaseyaa\Api\CodifiedContext` | Port for listing/querying codified-context session rows (Telescope adapter in `waaseyaa/telescope`) |
| `src/CodifiedContext/CodifiedContextSessionRow.php` | `Waaseyaa\Api\CodifiedContext` | Value object for a single session-oriented telescope row exposed through the port |
| `src/Controller/BroadcastStorage.php` | `Waaseyaa\Api\Controller` | Durable message log feeding the SSE `/broadcast` endpoint owned by foundation's `BroadcastRouter`. Contract: `docs/specs/broadcasting.md`. |
| `src/Cache/ApiCacheMiddleware.php` | `Waaseyaa\Api\Cache` | ETag, If-None-Match, Cache-Control header generation |
| `src/OpenApi/OpenApiGenerator.php` | `Waaseyaa\Api\OpenApi` | Generates OpenAPI 3.1 spec from entity type definitions |
| `src/OpenApi/SchemaBuilder.php` | `Waaseyaa\Api\OpenApi` | Builds component schemas for OpenAPI spec |
| `src/Exception/JsonApiDocumentException.php` | `Waaseyaa\Api\Exception` | Exception carrying a JsonApiDocument error response for controller helpers |
| `src/MutableTranslatableInterface.php` | `Waaseyaa\Api` | Extension of TranslatableInterface with `addTranslation()` |
| `src/Http/Router/DiscoveryRouter.php` | `Waaseyaa\Api\Http\Router` | Discovery topic hub, cluster, timeline, and endpoint pages (`discovery.*` controllers); uses `DiscoveryApiHandler` |
| `src/Http/Router/McpAdminApiRouter.php` | `Waaseyaa\Api\Http\Router` | Dispatches `/api/mcp/{tools,tools/{name},server-config}` to `McpAdminController` actions (M5C WP01) |
| `src/Controller/McpAdminController.php` | `Waaseyaa\Api\Controller` | Admin-only read controller for the MCP-endpoint admin surface; nullable read-model deps return empty-shape on missing bindings (M5C WP01) |
| `src/McpAdmin/ToolRegistryReadModelInterface.php` | `Waaseyaa\Api\McpAdmin` | Read contract for the MCP tool registry — `listTools()` + `findTool(name)`. Implementation in `packages/mcp/src/Admin/ToolRegistryReadModel.php` (L6) |
| `src/McpAdmin/ServerConfigReadModelInterface.php` | `Waaseyaa\Api\McpAdmin` | Read contract for the MCP server-config snapshot. Implementation in `packages/mcp/src/Admin/ServerConfigReadModel.php` (L6) |
| `src/McpAdmin/ToolRegistryRow.php` | `Waaseyaa\Api\McpAdmin` | Registry-index DTO `{name, summary, category, requiredCapabilities}` |
| `src/McpAdmin/ToolDetail.php` | `Waaseyaa\Api\McpAdmin` | Per-tool detail DTO `{name, summary, description, category, requiredCapabilities, inputSchema (JSON Schema 2020-12), recentInvocations}` |
| `src/McpAdmin/RecentInvocation.php` | `Waaseyaa\Api\McpAdmin` | Audit/trace row `{traceUuid, invokedAt, account, outcome: ok\|error, errorMessage, latencyMs}` |
| `src/McpAdmin/RegisteredClient.php` | `Waaseyaa\Api\McpAdmin` | MCP client record `{clientId, addedAt, lastSeenAt, tokenFingerprint}` — `tokenFingerprint` is a 16-char SHA-256 hex prefix (NFR-003) |
| `src/McpAdmin/ServerConfigSnapshot.php` | `Waaseyaa\Api\McpAdmin` | Server-config snapshot `{transport, protocolVersion, registeredClients, serverCapabilities}` |

### packages/routing/

| File | Namespace | Purpose |
|------|-----------|---------|
| `src/WaaseyaaRouter.php` | `Waaseyaa\Routing` | Wraps Symfony UrlMatcher + UrlGenerator; `match()` rethrows matcher failures as Waaseyaa routing exceptions (below) |
| `src/Exception/RouteNotFoundException.php` | `Waaseyaa\Routing\Exception` | Thrown from `WaaseyaaRouter::match()` when no route matches the path (wraps Symfony `ResourceNotFoundException`) |
| `src/Exception/RouteMethodNotAllowedException.php` | `Waaseyaa\Routing\Exception` | Thrown from `WaaseyaaRouter::match()` when the path matches but the HTTP method is not allowed (wraps Symfony `MethodNotAllowedException`) |
| `src/RouteBuilder.php` | `Waaseyaa\Routing` | Fluent API for building Symfony Route objects; `entityParameter()` sets `options.parameters.*.type = entity:{id}`; `bind()` sets `options._waaseyaa_app_bindings` for SSR post-load class checks; `controller()` accepts `string`, `callable`, or `[FQCN, method]` and stores normalized `_controller` via `normalizeControllerDefault()` |
| `src/RouteFingerprint.php` | `Waaseyaa\Routing` | Stable hash of path, methods, parameters, bindings, defaults for app-controller descriptor cache invalidation |
| `src/RouteMatch.php` | `Waaseyaa\Routing` | Value object for matched route (name, route, parameters) |
| `src/AccessChecker.php` (in `waaseyaa/access`, not routing) | `Waaseyaa\Access` | Route-level access checking via route options. Owned by the access package; routing depends on access (mission #824 WP05 surface A). |
| `src/AuthOidcRouteServiceProvider.php` | `Waaseyaa\Routing` | Registers `/api/auth/*`, `/api/user/me`, and OIDC discovery/authorize/token routes; depends on `waaseyaa/auth` and `waaseyaa/oidc` for controllers only. `api.user.me` is registered with `->priority(10)` (#1532) so it beats `JsonApiRouteProvider`'s `/api/user/{id}` catch-all — without the bump, `me` was treated as a literal entity id and returned 404. |

### Route precedence and the SSR `render.page` fallback (#1632)

Route resolution order is governed by `WaaseyaaRouter::sortRoutesByPriority()`, **not** by registration order. The router sorts the whole collection by `RouteBuilder::priority()` (the `_waaseyaa_priority` option, **default 0**) descending, using each route's original registration index only as a tiebreaker among equal priorities. The first matching route (by `Symfony\Component\Routing\Matcher\UrlMatcher` order) wins.

`BuiltinRouteRegistrar` registers the SSR fallback `public.page` (`/{path}` → `render.page`, with `path` constrained to exclude `api/…`) at **default priority 0**, after the provider route loop. Consequently:

- A default-priority (0) app `/{alias}` route registered by a provider sorts ahead of `public.page` *only* because it has a lower registration index — a fragile tiebreaker that can be lost if any route re-sorts the collection or competes at the same priority.
- To make an app catch-all **deterministically** outrank the SSR `render.page` fallback, give it an explicit `->priority(>=1)`. This is the same mechanism used by `api.user.me ->priority(10)` (#1532) to beat `JsonApiRouteProvider`'s `/api/user/{id}` catch-all.

The framework intentionally leaves the fallback at priority 0 (changing the default would silently reorder existing apps); apps opt into precedence explicitly. See the inline comments at the `public.page` registration in `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`.
| `src/OidcHttpRoutes.php` | `Waaseyaa\Routing` | OIDC path table (discovery, jwks, optional authorize/token) used by `AuthOidcRouteServiceProvider` |
| `src/Attribute/GateAttribute.php` | `Waaseyaa\Routing\Attribute` | PHP attribute for gate-based access control on controller methods |
| `src/ParamConverter/EntityParamConverter.php` | `Waaseyaa\Routing\ParamConverter` | Converts route parameter IDs to loaded entity objects |
| `src/Language/LanguageNegotiatorInterface.php` | `Waaseyaa\Routing\Language` | Interface for language negotiation |
| `src/Language/AcceptHeaderNegotiator.php` | `Waaseyaa\Routing\Language` | Language negotiation from Accept-Language header |
| `src/Language/UrlPrefixNegotiator.php` | `Waaseyaa\Routing\Language` | Language negotiation from URL prefix |

## Core Value Objects

### JsonApiDocument

```php
// packages/api/src/JsonApiDocument.php
final readonly class JsonApiDocument
{
    public function __construct(
        public JsonApiResource|array|null $data = null,
        public array $errors = [],
        public array $meta = [],
        public array $links = [],
        public array $included = [],
        public int $statusCode = 200,
    ) {}

    public function toArray(): array;

    // Static factories:
    public static function fromResource(JsonApiResource $resource, array $links = [], array $meta = [], int $statusCode = 200): self;
    public static function fromCollection(array $resources, array $links = [], array $meta = []): self;
    public static function fromErrors(array $errors, array $meta = [], int $statusCode = 400): self;
    public static function empty(array $meta = [], int $statusCode = 200): self;
}
```

`toArray()` always includes `jsonapi.version = "1.1"`. The `data` and `errors` members are mutually exclusive per the JSON:API spec. When `$data` is `null` (e.g., after DELETE), `toArray()` emits `"data": null`.

### JsonApiResource

```php
// packages/api/src/JsonApiResource.php
final readonly class JsonApiResource
{
    public function __construct(
        public string $type,       // entity type ID
        public string $id,         // UUID (preferred) or numeric ID as string
        public array $attributes = [],
        public array $relationships = [],
        public array $links = [],
        public array $meta = [],
    ) {}

    public function toArray(): array;
}
```

### JsonApiError

```php
// packages/api/src/JsonApiError.php
final readonly class JsonApiError
{
    public function __construct(
        public string $status,
        public string $title,
        public string $detail = '',
        public array $source = [],
    ) {}

    public function toArray(): array;

    // Static factories:
    public static function notFound(string $detail = ''): self;      // 404
    public static function forbidden(string $detail = ''): self;     // 403
    public static function unprocessable(string $detail = '', array $source = []): self;  // 422
    public static function badRequest(string $detail = ''): self;    // 400
    public static function conflict(string $detail = ''): self;      // 409
    public static function internalError(string $detail = ''): self; // 500
}
```

## JSON:API Controller

`JsonApiController` is a framework-agnostic PHP class. It receives parsed parameters and returns `JsonApiDocument` objects. The front controller in `public/index.php` handles HTTP concerns (headers, body parsing, status codes).

### Constructor

```php
// packages/api/src/JsonApiController.php
final class JsonApiController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}
}
```

The `$accessHandler` and `$account` follow the **paired nullable** pattern: both must be non-null or both null. When both are null, no access checking is performed.

### CRUD Operations

**`index(string $entityTypeId, array $query = []): JsonApiDocument`**

1. Validates entity type exists via `$entityTypeManager->hasDefinition()`.
2. Creates a `QueryParser` and parses `$query` into `ParsedQuery`.
3. Runs a **count query** with filters only (no sorts/pagination) to get total.
4. Runs the **main query** with filters, sorts, and pagination via `QueryApplier`.
5. Loads entities via `$storage->loadMultiple($ids)`.
6. **Post-fetch access filter**: if access handler is available, filters entities where `$accessHandler->check($entity, 'view', $account)->isAllowed()` is false.
7. Serializes via `$serializer->serializeCollection()`.
8. Applies sparse fieldsets if `fields[type]` is in the query via `SparseFieldsetApplicator::apply()` (filters both `attributes` and `relationships` per JSON:API).
9. Generates pagination links and meta (`total`, `offset`, `limit`).
10. Returns `JsonApiDocument::fromCollection()`.

**`show(string $entityTypeId, int|string $id, array $query = []): JsonApiDocument`**

1. Loads entity by ID or UUID via `loadByIdOrUuid()`.
2. Checks view access. Returns 403 if denied.
3. Serializes via `$serializer->serialize()`.
4. Applies sparse fieldsets if `fields[type]` is in the query (`SparseFieldsetApplicator`, same as `index()`).
5. Returns `JsonApiDocument::fromResource()`.

**`store(string $entityTypeId, array $data): JsonApiDocument`**

1. Validates `data.type` matches `$entityTypeId`.
2. Creates entity via `$storage->create($attributes)`.
3. Checks create access via `$accessHandler->checkCreateAccess()`.
4. Checks **field edit access** for each submitted attribute via `$accessHandler->checkFieldAccess($entity, $fieldName, 'edit', $account)`. Uses `isForbidden()` (field-level semantics).
5. Saves entity and returns document with `statusCode: 201` and `meta.created = true`.

**`update(string $entityTypeId, int|string $id, array $data): JsonApiDocument`**

1. Loads entity, validates `data.type` and optional `data.id` (409 Conflict if UUID mismatch).
2. Checks update access at entity level.
3. Checks field edit access for each submitted attribute.
4. Applies updates via `$entity->set($field, $value)` (requires `FieldableInterface`).
5. Saves and returns updated resource.

**`destroy(string $entityTypeId, int|string $id): JsonApiDocument`**

1. Loads entity, checks delete access.
2. Deletes via `$storage->delete([$entity])`.
3. Returns `JsonApiDocument::empty(meta: ['deleted' => true], statusCode: 204)`.

### ID Resolution

`loadByIdOrUuid()` accepts `int|string`. If the entity type has a UUID key and the value matches UUID regex (`/^[0-9a-f]{8}-...-[0-9a-f]{12}$/i`), it queries by UUID. Otherwise it loads by primary key.

## Resource Serialization

```php
// packages/api/src/ResourceSerializer.php
final class ResourceSerializer
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    public function serialize(
        EntityInterface $entity,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): JsonApiResource;

    public function serializeCollection(
        array $entities,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array;
}
```

### Serialization Logic

1. Uses UUID as resource ID if available, otherwise falls back to numeric ID (config entities: string machine name when UUID is empty).
2. Builds attributes via **`EntityValues::toCastAwareMap($entity)`**, then drops keys that map to entity keys `id` and `uuid` (storage column names from `EntityType::getKeys()`), so every attribute value passes through `EntityInterface::get()` and `EntityBase::$casts` apply (#1181 ST-7 / ST-9). See `docs/specs/jsonapi.md` for the pipeline diagram.
3. **Filters internal/credential fields** (#1531). Two layers, both applied **before** the per-account access handler so credentials never reach policy code:
   - `ResourceSerializer::ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash']` — dropped unconditionally even when no `FieldDefinition` exists. Covers raw `_data` keys that hold credential material (e.g. `User::$pass` is set via `setRawPassword()` with no `#[Field]` attribute).
   - Any `FieldDefinition` whose `getSetting('internal') === true` is dropped (e.g. `User::two_factor_secret`, `User::two_factor_recovery_codes_hash`). New sensitive fields opt in via `#[Field(... settings: ['internal' => true])]`.
4. When access handler + account are provided, calls `$accessHandler->filterFields($entity, array_keys($attributes), 'view', $account)` to remove view-denied fields.
5. Applies field-definition coercions (`boolean`, `timestamp` / `datetime`): timestamps accept integers or `DateTimeInterface` (e.g. after a `datetime_immutable` cast).
6. Normalizes values to JSON-serializable shapes via **`EntityValues::normalizeValueForJson()`** (backed enums → backing value, `DateTimeInterface` → ISO-8601 `ATOM`, `JsonSerializable` → `jsonSerialize()` then recurse, arrays → recurse) — shared with `EntityValues::toJsonReadyMap()` for other presentation sinks (#1181 ST-10).
7. Generates a `self` link: `{basePath}/{entityTypeId}/{resourceId}`.

### Paired Nullable Pattern

`$accessHandler` and `$account` must both be non-null or both null. The guard pattern is:

```php
if ($accessHandler !== null && $account !== null) {
    $allowedFields = $accessHandler->filterFields($entity, array_keys($attributes), 'view', $account);
    $attributes = array_intersect_key($attributes, array_flip($allowedFields));
}
```

Only two of the four possible states (both-null, both-non-null) are meaningful. Passing one without the other silently skips access filtering.

## Schema Presenter

**Field definitions vs `$casts` (#1184):** `SchemaPresenter::present()` builds properties from **EntityType field definitions** (the `$fieldDefinitions` argument, usually from the entity type registry), not from `EntityBase::$casts` on the entity PHP class. A field may still appear in JSON:API `attributes` with correct typing when the entity uses `$casts` and serializers call `EntityValues` (see **`docs/specs/jsonapi.md`** and **`docs/specs/entity-system.md`**). Admin form widgets for cast-only value objects may require explicit field definition metadata in a follow-up.

```php
// packages/api/src/Schema/SchemaPresenter.php
final class SchemaPresenter
{
    public function present(
        EntityTypeInterface $entityType,
        array $fieldDefinitions = [],
        ?EntityInterface $entity = null,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array;
}
```

### JSON Schema Output Format

Follows JSON Schema draft-07 with custom extensions:

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "title": "Content",
    "description": "Schema for Content entities.",
    "type": "object",
    "x-entity-type": "node",
    "x-translatable": false,
    "x-revisionable": false,
    "properties": { ... },
    "required": [ ... ]
}
```

### Custom Extensions

| Extension | Type | Purpose |
|-----------|------|---------|
| `x-widget` | string | Widget type hint for admin SPA (text, textarea, richtext, select, boolean, number, email, url, datetime, entity_autocomplete, image, file, password, hidden) |
| `x-label` | string | Human-readable field label |
| `x-description` | string | Field help text |
| `x-weight` | int | Display order weight |
| `x-required` | bool | Whether field is required in forms |
| `x-access-restricted` | bool | Field is viewable but not editable by current account |
| `x-entity-type` | string | Entity type ID (top-level) |
| `x-translatable` | bool | Whether entity type supports translations (top-level) |
| `x-revisionable` | bool | Whether entity type supports revisions (top-level) |
| `x-target-type` | string | Target entity type for entity_reference fields |
| `x-enum-labels` | object | Human-readable labels for enum values |

### readOnly vs x-access-restricted

These serve different purposes in the admin SPA:

- **`readOnly: true`** (without `x-access-restricted`): System fields like `id`, `uuid`. The admin SPA **hides** these from forms entirely.
- **`readOnly: true` + `x-access-restricted: true`**: The user can **view** the field but cannot **edit** it. The admin SPA shows a disabled widget.

### Field Access Integration

When `$entity`, `$accessHandler`, and `$account` are all non-null:

1. For each non-system field, checks `checkFieldAccess($entity, $fieldName, 'view', $account)`.
2. If `isForbidden()` for view: **removes** the property from the schema entirely.
3. If not forbidden for view, checks `checkFieldAccess($entity, $fieldName, 'edit', $account)`.
4. If `isForbidden()` for edit: marks the property with `readOnly: true` and `x-access-restricted: true`.

System keys (id, uuid, label, bundle, langcode) are always shown as-is.

### Type and Widget Mappings

Field type to JSON Schema type: `string->string`, `text->string`, `boolean->boolean`, `integer->integer`, `float->number`, `decimal->number`, `email->string`, `uri->string`, `timestamp->string`, `entity_reference->string`.

Field type to widget: `string->text`, `text->textarea`, `text_long->richtext`, `boolean->boolean`, `integer->number`, `email->email`, `uri->url`, `timestamp->datetime`, `entity_reference->entity_autocomplete`, `list_string->select`.

Format mappings: `email->email`, `uri->uri`, `timestamp->date-time`, `datetime->date-time`.

### SchemaController

```php
// packages/api/src/Controller/SchemaController.php
final class SchemaController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly SchemaPresenter $schemaPresenter,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}

    public function show(string $entityTypeId): JsonApiDocument;
}
```

Creates a prototype entity (`new $class([])`) for field access checking. Wraps in try-catch and logs failures via `error_log()`. Returns the schema in `meta.schema` of a `JsonApiDocument`.

## Per-Field Auto-Save Endpoint (F3)

Added in mission `single-entity-work-surface-01KQ7M1P`. Enables single-field saves without a full entity PUT.

**Route**: `PUT /api/{entityType}/{id}/field/{key}`

```
Content-Type: application/json
{"value": "<string>"}
```

**Controller**: `Waaseyaa\Api\Controller\FieldAutoSaveController`

```php
new FieldAutoSaveController(
    entityTypeManager: $entityTypeManager,
    accessHandler: $accessHandler,
    fieldRegistry: $fieldRegistry,
    maxBodyBytes: 65536,  // optional
)
```

**Status code matrix** (per contracts/README.md F3):

| Code | Condition |
|------|-----------|
| 200 | Field saved successfully |
| 401 | No `_account` attribute on the request (SessionMiddleware did not run or returned anonymous) |
| 403 | Entity-level `isAllowed()` denied, or field-level `isForbidden()` |
| 404 | Unknown entity type, entity not found, or field key not registered for the entity's bundle |
| 415 | Content-Type is not `application/json` |
| 422 | Body > `maxBodyBytes`, malformed JSON, or `value` key missing or non-string |

**Access semantics**: entity-level uses `isAllowed()` (deny on Neutral); field-level uses `isForbidden()` (allow on Neutral — open-by-default). Field validation against the `edit` operation; `update` used for entity-level check.

**Body-size guard (NFR-002)**: `Content-Length` header is checked before reading the body (fast rejection). If absent, the raw body is checked after `getContent()`. Chunked transfer without `Content-Length` falls through to post-read check.

→ See `docs/specs/work-surface.md` F3 for the full wire-up reference.

## Query Pipeline

### QueryParser

Parses `$_GET`-style arrays into a `ParsedQuery` value object.

**Supported query parameters:**

| Parameter | Format | Example |
|-----------|--------|---------|
| `filter[field]=value` | Simple equality | `filter[status]=published` |
| `filter[field][operator]=op&filter[field][value]=val` | Operator filter | `filter[title][operator]=CONTAINS&filter[title][value]=hello` |
| `filter[field][operator]=IN&filter[field][value][]=v1&filter[field][value][]=v2` | IN filter (batch lookup) | `filter[uuid][operator]=IN&filter[uuid][value][]=abc-123&filter[uuid][value][]=def-456` |
| `sort=field,-field2` | Comma-separated, `-` prefix for DESC | `sort=-created,title` |
| `page[offset]=N` | Offset-based pagination | `page[offset]=20` |
| `page[limit]=N` | Page size | `page[limit]=10` |
| `fields[type]=field1,field2` | Sparse fieldsets | `fields[node]=title,body` |

### QueryFilter

```php
// packages/api/src/Query/QueryFilter.php
final readonly class QueryFilter
{
    private const VALID_OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH', 'IN'];

    public function __construct(
        public string $field,
        public mixed $value,
        public string $operator = '=',
    ) {}
}
```

Throws `InvalidArgumentException` for unsupported operators.

### QuerySort

```php
// packages/api/src/Query/QuerySort.php
final readonly class QuerySort
{
    public function __construct(
        public string $field,
        public string $direction = 'ASC',  // 'ASC' or 'DESC'
    ) {}
}
```

### ParsedQuery

```php
// packages/api/src/Query/ParsedQuery.php
final readonly class ParsedQuery
{
    public function __construct(
        public array $filters = [],           // QueryFilter[]
        public array $sorts = [],             // QuerySort[]
        public ?int $offset = null,
        public ?int $limit = null,
        public array $sparseFieldsets = [],    // array<string, list<string>>
    ) {}
}
```

### QueryApplier

```php
// packages/api/src/Query/QueryApplier.php
final class QueryApplier
{
    private int $defaultLimit = 50;
    private int $maxLimit = 100;

    public function apply(ParsedQuery $query, EntityQueryInterface $entityQuery): EntityQueryInterface;
    public function getEffectiveLimit(ParsedQuery $query): int;
    public function getEffectiveOffset(ParsedQuery $query): int;
    public function getDefaultLimit(): int;
    public function getMaxLimit(): int;
}
```

`apply()` translates each `QueryFilter` to `$entityQuery->condition()`, each `QuerySort` to `$entityQuery->sort()`, and applies `$entityQuery->range($offset, $limit)`. The limit is clamped to `min($requestedLimit, $maxLimit)` with a default of 50.

### PaginationLinks

```php
// packages/api/src/Query/PaginationLinks.php
final class PaginationLinks
{
    public static function generate(string $basePath, int $offset, int $limit, int $total): array;
}
```

Returns `self`, `first`, and optionally `prev` and `next` links. Format: `{basePath}?page[offset]={N}&page[limit]={M}`.

## Post-Fetch Access Filtering

Entity-level access is applied **after** query execution in `JsonApiController::index()`:

```php
if ($this->accessHandler !== null && $this->account !== null) {
    $entities = array_filter(
        $entities,
        fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed(),
    );
}
```

This means:
- The SQL query runs with `accessCheck(false)` -- no access checks in the database layer.
- Entities are loaded, then filtered by view access in PHP.
- The `total` count in pagination meta reflects the **unfiltered** count (from the count query, also with `accessCheck(false)`), and is then **recounted to match the access-filtered visible set** before the response is built (`$total = count($entities)`). So `meta.total` equals the number of resources returned, even when access filtering dropped rows.

**Empty `data` is access-filtering, not missing data.** When a restrictive view policy is registered for the entity type and filters out *every* matched row, `index()` returns HTTP **200** with `data: []` and `meta.total: 0` -- there is no logger on this controller and no error/warning is emitted, by design (an authenticated principal seeing nothing they may view is a normal authorization outcome, not a fault). Consumers debugging an unexpectedly empty collection should therefore not assume the rows are absent: check whether a registered `AccessPolicy` denied `view` for the current account before concluding the data does not exist. A genuinely empty table and a fully access-filtered table are indistinguishable on the wire by intent (no enumeration oracle). To tell them apart during development, re-issue the query in a system context (no account bound, `accessCheck(false)`) or inspect the policy directly.

Access result semantics differ by level:
- **Entity level**: uses `isAllowed()` -- deny unless explicitly granted.
- **Field level**: uses `!isForbidden()` -- allow unless explicitly denied (open by default).

## LIKE Wildcard Escaping

The `CONTAINS` and `STARTS_WITH` filter operators are translated to SQL `LIKE` patterns by `SqlEntityQuery` (in `packages/entity-storage/`). There are two important details:

1. **DBALSelect appends `ESCAPE '\'`** for all LIKE/NOT LIKE operators. This means the backslash character is the escape character in LIKE patterns.

2. **User input must be escaped** before embedding in LIKE patterns:

```php
$escapedValue = str_replace(['%', '_'], ['\\%', '\\_'], $value);
// CONTAINS: "%{$escapedValue}%"
// STARTS_WITH: "{$escapedValue}%"
```

Without this escaping, a user submitting `100%` as a filter value would match unintended rows because `%` is a LIKE wildcard.

## IN Filter Operator

The `IN` operator supports batch lookups by matching a field against a list of values. This is primarily used for batch UUID resolution (e.g., loading multiple entities by UUID in a single request).

```
GET /api/node?filter[uuid][operator]=IN&filter[uuid][value][]=550e8400-...&filter[uuid][value][]=6ba7b810-...
```

The `value` parameter must be an array when using `IN`. `QueryParser` passes the array value through to `QueryFilter`, and `QueryApplier` translates it to a SQL `IN (...)` clause via `EntityQueryInterface::condition()`.

## Route Building

### WaaseyaaRouter

```php
// packages/routing/src/WaaseyaaRouter.php
final class WaaseyaaRouter
{
    public function __construct(?RequestContext $context = null);

    public function addRoute(string $name, Route $route): void;
    public function match(string $pathinfo): array;
    public function generate(string $name, array $parameters = []): string;
    public function getRouteCollection(): RouteCollection;
}
```

Wraps Symfony `UrlMatcher` and `UrlGenerator`. Lazy-initializes matchers/generators and resets them when routes change.

**`match(string $pathinfo): array`:** On success, returns the Symfony matcher parameter array (including `_route`). On failure, **does not** leak Symfony matcher exception types to callers: `Symfony\Component\Routing\Exception\ResourceNotFoundException` becomes `Waaseyaa\Routing\Exception\RouteNotFoundException`, and `Symfony\Component\Routing\Exception\MethodNotAllowedException` becomes `Waaseyaa\Routing\Exception\RouteMethodNotAllowedException` (previous exception is chained). Foundation `HttpKernel` catches the Waaseyaa types to emit JSON **404** / **405** responses for API-style requests without importing Symfony routing exception classes in the hot path.

**`generate(...)`** continues to throw Symfony generator exceptions (`RouteNotFoundException`, `MissingMandatoryParametersException`, `InvalidParameterException`) — only the **match** path is wrapped.

### RouteBuilder

Fluent API for building Symfony Route objects:

```php
// packages/routing/src/RouteBuilder.php
$route = RouteBuilder::create('/node/{node}')
    ->controller('App\Controller\NodeController::view')
    ->entityParameter('node', 'node')
    ->requirePermission('access content')
    ->methods('GET')
    ->build();
```

| Method | Route Option | Purpose |
|--------|-------------|---------|
| `controller(string\|callable\|array{0: string, 1: string})` | `_controller` | Sets the controller; two-element `[FQCN, method]` arrays are normalized to `FQCN::method` (same rule as `RouteBuilder::normalizeControllerDefault()`) |
| `methods(string ...)` | (route methods) | Allowed HTTP methods |
| `entityParameter(string $name, string $entityType)` | `parameters[$name] = ['type' => 'entity:{entityType}']` | Entity param upcasting |
| `requirePermission(string $permission)` | `_permission` | Require specific permission |
| `requireRole(string $role)` | `_role` | Require specific role |
| `allowAll()` | `_public = true` | Public route, no auth required |
| `requirement(string $key, string $regex)` | (route requirements) | Regex requirement for parameter |
| `default(string $key, mixed $value)` | (route defaults) | Default parameter value |
| `build()` | -- | Returns configured Symfony Route |

### Route Access Options

Routes declare access requirements via Symfony Route options. These are checked by `AccessChecker`:

| Option | Type | Meaning |
|--------|------|---------|
| `_public` | `true` | Always allow access (no authentication required) |
| `_permission` | `string` | Account must have the named permission |
| `_role` | `string` | Account must have the named role (comma-separated for multiple) |
| `_gate` | `array{ability: string, subject?: mixed}` | Gate ability check |

Multiple requirements are combined with **AND** logic (all must pass). If no access requirements are present, `AccessChecker::check()` returns `AccessResult::neutral()`.

### AccessChecker

```php
// packages/access/src/AccessChecker.php — Waaseyaa\Access\AccessChecker
final class AccessChecker
{
    public function __construct(
        private readonly ?GateInterface $gate = null,
    ) {}

    public function check(Route $route, AccountInterface $account): AccessResult;
    public static function applyGateToRoute(Route $route, string $ability, mixed $subject = null): void;
}
```

### GateAttribute

PHP attribute for declarative gate checks on controller methods:

```php
// packages/routing/src/Attribute/GateAttribute.php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class GateAttribute
{
    public function __construct(
        public readonly string $ability,   // e.g., 'config.export'
        public readonly mixed $subject = null,
    ) {}
}
```

### EntityParamConverter

```php
// packages/routing/src/ParamConverter/EntityParamConverter.php
final class EntityParamConverter
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function convert(array $parameters, Route $route): array;
}
```

Reads the route `parameters` option for entries with `type => 'entity:{entityTypeId}'`. Loads the entity from storage and replaces the raw ID in the parameter array. Throws `ResourceNotFoundException` if entity not found.

### JsonApiRouteProvider

```php
// packages/api/src/JsonApiRouteProvider.php
final class JsonApiRouteProvider
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    public function registerRoutes(WaaseyaaRouter $router): void;
}
```

Registers a single public discovery route plus five CRUD routes per entity type. The discovery route is always registered, even when no entity types are present.

| Route Name | Method | Path | Controller Method | Access |
|-----------|--------|------|-------------------|--------|
| `api.discovery` | GET | `/api` | `Waaseyaa\Api\ApiDiscoveryController::discover` | `_public` (allowAll) |
| `api.{type}.index` | GET | `/api/{type}` | `JsonApiController::index` | route-default access |
| `api.{type}.show` | GET | `/api/{type}/{id}` | `JsonApiController::show` | route-default access |
| `api.{type}.store` | POST | `/api/{type}` | `JsonApiController::store` | `_authenticated` + `application/vnd.api+json` |
| `api.{type}.update` | PATCH | `/api/{type}/{id}` | `JsonApiController::update` | `_authenticated` + `application/vnd.api+json` |
| `api.{type}.destroy` | DELETE | `/api/{type}/{id}` | `JsonApiController::destroy` | `_authenticated` |

Per-entity-type CRUD routes set `_entity_type` as a default parameter. The discovery route does not — it iterates `EntityTypeManagerInterface::getDefinitions()` at request time.

### ApiDiscoveryController

```php
// packages/api/src/ApiDiscoveryController.php
final class ApiDiscoveryController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    /**
     * @return array{meta: array<string, string>, links: array<string, mixed>}
     */
    public function discover(): array;
}
```

Returns a JSON:API-style discovery document listing every registered entity type's collection endpoint. The response contract is:

| Key | Shape | Notes |
|-----|-------|-------|
| `meta.api` | `'waaseyaa'` (string) | Constant identifier for the API surface. |
| `meta.version` | `'1.0'` (string) | Discovery contract version, not the framework version. |
| `links.self` | `string` | The configured `$basePath` (defaults to `/api`). |
| `links.{entity_type_id}` | `array{href: string, meta: array{type: string}}` | One entry per `EntityTypeManagerInterface::getDefinitions()` entry. `href` is `{basePath}/{entity_type_id}`; `meta.type` echoes the entity type id for client convenience. |

Invariants enforced by the integration test:
- `links.self` is always present.
- `links.{type}.href` always equals the collection path served by `api.{type}.index`.
- The entry set in `links` (excluding `self`) is exactly the set of registered entity type ids — no more, no less.
- When zero entity types are registered, `links` collapses to `['self' => $basePath]`.

The route is dispatched by `JsonApiRouteProvider`'s `api.discovery` registration. At runtime, `DiscoveryRouter` (the `HttpDomainRouter` registered through `ApiServiceProvider::httpDomainRouters()`) recognises the controller string `Waaseyaa\Api\ApiDiscoveryController::discover` via `str_contains($controller, 'ApiDiscoveryController')`, instantiates the controller with the booted `EntityTypeManager`, and wraps the discover payload in a `jsonapi.version` envelope before returning a JSON:API response.

## Translation Sub-Resource

```php
// packages/api/src/Controller/TranslationController.php
final class TranslationController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
    ) {}
}
```

| Method | Route | Description |
|--------|-------|-------------|
| `index(entityTypeId, id)` | `GET /api/{type}/{id}/translations` | List translations |
| `show(entityTypeId, id, langcode)` | `GET /api/{type}/{id}/translations/{langcode}` | Get translation |
| `store(entityTypeId, id, langcode, data)` | `POST /api/{type}/{id}/translations/{langcode}` | Create translation |
| `update(entityTypeId, id, langcode, data)` | `PATCH /api/{type}/{id}/translations/{langcode}` | Update translation |
| `destroy(entityTypeId, id, langcode)` | `DELETE /api/{type}/{id}/translations/{langcode}` | Delete translation |

Creating a translation requires `MutableTranslatableInterface`. Deleting the original language returns 422.

### Error Handling Pattern

`TranslationController::loadTranslatableEntity()` throws `JsonApiDocumentException` when the entity cannot be loaded or is not translatable, rather than returning a union type. Each CRUD method catches the exception once and returns the error document. This eliminates repeated `instanceof JsonApiDocument` dispatch checks and keeps the return type narrow (`TranslatableInterface`).

## API Cache Middleware

```php
// packages/api/src/Cache/ApiCacheMiddleware.php
final class ApiCacheMiddleware
{
    public function __construct(
        private readonly ?int $entityMaxAge = null,     // default: 0
        private readonly ?int $collectionMaxAge = null,  // default: 0
        private readonly ?int $schemaMaxAge = null,      // default: 3600
        private readonly bool $isPrivate = true,
    ) {}

    public function generateETag(JsonApiDocument $document): string;
    public function isNotModified(string $ifNoneMatch, string $etag): bool;
    public function buildHeaders(JsonApiDocument $document, string $responseType = 'entity'): array;
    public function process(JsonApiDocument $document, string $responseType = 'entity', string $ifNoneMatch = ''): array;
}
```

ETags use `W/"..."` (weak validator) with SHA-256 hash of the serialized response. Supports wildcard and multi-value `If-None-Match`. Returns `Vary: Accept, Accept-Language, Authorization`.

## OpenAPI Generation

```php
// packages/api/src/OpenApi/OpenApiGenerator.php
final class OpenApiGenerator
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private string $basePath = '/api',
        private string $title = 'Waaseyaa API',
        private string $version = '0.1.0',
    ) {}

    public function generate(): array;
}
```

Generates OpenAPI 3.1.0 spec. For each entity type, creates four component schemas (`{Type}Resource`, `{Type}Attributes`, `{Type}CreateRequest`, `{Type}UpdateRequest`) and five path operations. Includes shared schemas for `JsonApiDocument`, `JsonApiErrorDocument`, `JsonApiError`, `JsonApiVersion`, and `JsonApiLinks`.

## Discovery API Handler

`DiscoveryApiHandler` encapsulates logic for discovery endpoints (topic hubs, clusters, timelines, entity endpoint pages). It handles discovery cache primitives, relationship type parsing, entity visibility checks, and cache key building.

### Instantiation Lifecycle

`DiscoveryApiHandler` is instantiated in `HttpKernel::handle()` **after** `boot()` completes and after the cache infrastructure is set up. The creation sequence in `handle()` is:

1. `$this->boot()` — bootstraps providers, entity types, access policies, and the event dispatcher.
2. Cache bins are configured (`render`, `discovery`, `mcp_read`) via `CacheFactory`.
3. `$this->discoveryHandler = new DiscoveryApiHandler(...)` is created with three dependencies:
   - `$this->entityTypeManager` — the fully booted `EntityTypeManager` (available after `boot()`).
   - `$this->database` — the `DatabaseInterface` instance (available after `boot()`).
   - `$this->discoveryCache` — a `CacheBackendInterface` (`DatabaseBackend` backed by the `cache_discovery` table), created moments earlier in the same method.

The handler is stored as `$this->discoveryHandler` on the kernel and subsequently passed to both `SsrPageHandler` and `ControllerDispatcher`.

### Constructor

```php
// packages/api/src/Http/DiscoveryApiHandler.php
final class DiscoveryApiHandler
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?CacheBackendInterface $discoveryCache = null,
    ) {}
}
```

### Key Capabilities

| Method | Purpose |
|--------|---------|
| `parseRelationshipTypesQuery(mixed $value): list<string>` | Normalizes comma-separated string or array query param into a list of relationship type IDs |
| `buildDiscoveryCacheKey(string $surface, string $entityType, string $entityId, array $options): string` | Delegates to `DiscoveryCachePrimitives` to build a deterministic cache key |
| `normalizeForCacheKey(mixed $value): mixed` | Recursively sorts associative array keys for stable cache key generation |
| `getDiscoveryCachedResponse(string $cacheKey, AccountInterface $account): ?array` | Returns cached response for anonymous users; bypasses cache for authenticated users |
| `prepareDiscoveryResponse(int $status, array $payload, string $cacheKey, AccountInterface $account): array` | Returns `[payload, headers]` tuple — caches for anonymous (public, max-age=120), sets `no-store` for authenticated |
| `isDiscoveryEndpointPairPublic(string $fromType, string $fromId, string $toType, string $toId): bool` | Checks both endpoints of a relationship exist and are publicly visible via `WorkflowVisibility` |
| `loadDiscoveryEntity(string $entityType, string $entityId): ?EntityInterface` | Loads an entity by type and ID (resolves numeric strings to int), returns null on any failure |
| `isDiscoveryEntityPublic(string $entityType, array $values): bool` | Delegates to `WorkflowVisibility::isEntityPublic()` for publish-state checking |
| `createDiscoveryService(): RelationshipDiscoveryService` | Factory method — creates a `RelationshipDiscoveryService` with a `RelationshipTraversalService` wired to the handler's entity type manager and database |

### Discovery Cache Strategy

- **Anonymous users**: Responses are cached in the `discovery` cache bin with a 120-second TTL. Cache tags are derived from the payload via `DiscoveryCachePrimitives::buildTags()`. Cached responses include `X-Waaseyaa-Discovery-Cache: MISS` on first generation.
- **Authenticated users**: Cache is bypassed entirely (`Cache-Control: private, no-store`) to ensure fresh, access-aware results.
- Cache invalidation is handled by event listeners registered via `EventListenerRegistrar::registerDiscoveryCacheListeners()`.

## File Reference

```
packages/api/
  src/
    Cache/
      ApiCacheMiddleware.php
    CodifiedContext/
      CodifiedContextSessionRow.php
      CodifiedContextSessionStoreInterface.php
    Controller/
      BroadcastStorage.php
      CodifiedContextController.php
      McpAdminController.php
      SchemaController.php
      TranslationController.php
    Exception/
      JsonApiDocumentException.php
    Http/
      DiscoveryApiHandler.php
      Router/
        McpAdminApiRouter.php
    McpAdmin/
      RecentInvocation.php
      RegisteredClient.php
      ServerConfigReadModelInterface.php
      ServerConfigSnapshot.php
      ToolDetail.php
      ToolRegistryReadModelInterface.php
      ToolRegistryRow.php
    OpenApi/
      OpenApiGenerator.php
      SchemaBuilder.php
    Query/
      PaginationLinks.php
      ParsedQuery.php
      QueryApplier.php
      QueryFilter.php
      QueryParser.php
      QuerySort.php
    Schema/
      SchemaPresenter.php
    JsonApiController.php
    JsonApiDocument.php
    JsonApiError.php
    JsonApiResource.php
    JsonApiRouteProvider.php
    MutableTranslatableInterface.php
    ResourceSerializer.php

packages/routing/
  src/
    Attribute/
      GateAttribute.php
    Language/
      AcceptHeaderNegotiator.php
      LanguageNegotiatorInterface.php
      UrlPrefixNegotiator.php
    ParamConverter/
      EntityParamConverter.php
    RouteBuilder.php
    RouteMatch.php
    WaaseyaaRouter.php
```

## Controller parameter binding (SSR app dispatcher)

*Last updated: 2026-05-05 (post-#1390 dispatcher reconciliation, mission `post-1390-dispatcher-reconciliation-01KQTTJS`).*

App-controller parameter binding for SSR-routed controllers lives in `packages/ssr/src/Http/AppController/AppParameterBindingBuilder.php` (namespace `Waaseyaa\SSR\Http\AppController`). Only controllers wired through `SsrPageHandler` go through this dispatcher; JSON:API, auth, MCP, and the routers above use independent pipelines and are not subject to this contract.

After the #1390 reconciliation, the binding builder uses a name-keyed compatibility shim instead of hard-rejecting unannotated `array` parameters:

| Parameter signature                                        | Bound as                | Deprecation event             | `recommended_attribute` |
|------------------------------------------------------------|-------------------------|-------------------------------|-------------------------|
| `array $params` (no `#[MapRoute]` and no `#[MapQuery]`)    | `#[MapRoute]` (implicit) | `implicit_array_shim`         | `MapRoute`              |
| `array $query` (no `#[MapRoute]` and no `#[MapQuery]`)     | `#[MapQuery]` (implicit) | `implicit_array_shim`         | `MapQuery`              |
| `array $X` (any other name, no binding attribute)          | `[]` (injected default) | `implicit_array_unbound`      | `''` (empty)            |
| `#[MapRoute] array $params` or `#[MapQuery] array $query`  | Per attribute           | none                          | n/a                     |

`#[FromRoute]` is a route-key remapper and does NOT suppress the shim. Each shim hit emits one structured `dispatcher.deprecation` notice via `Waaseyaa\Foundation\Log\LoggerInterface` carrying `channel`, `event`, `controller_class`, `method`, `parameter_name`, and `recommended_attribute` keys, deduplicated per `(controller_class, method, parameter_name)` for the binding-builder's lifetime (NFR-002). The effective envelope under FPM is *once per triple per worker lifetime*, because `AppControllerMethodInvoker::$specCache` (`private static`) returns a cached `AppParameterBindingSpec` list on subsequent requests for the same route and never re-invokes the builder; see `kitty-specs/post-1390-dispatcher-reconciliation-01KQTTJS/artifacts/post-1390-dispatcher-contract.md` §7 erratum (#1392) for the full analysis. Methods with no `array` parameters incur zero hash-table lookups (NFR-001 fast-path).

The canonical contract, full edge-case table, log-emission templates, and rationale are owned by the mission artifact: [`kitty-specs/post-1390-dispatcher-reconciliation-01KQTTJS/artifacts/post-1390-dispatcher-contract.md`](../../kitty-specs/post-1390-dispatcher-reconciliation-01KQTTJS/artifacts/post-1390-dispatcher-contract.md). See §3 (trigger conditions), §4 (attribute equivalence), §5 (log emission contract), and §7 (dedup invariant + #1392 erratum on effective scope) of that artifact for the full contract.

## Symfony decoupling (mission 1107)

Per mission 1107-api-symfony-decoupling, the api package gains:

- **`Waaseyaa\Api\Http\JsonApiResponse`** — subclass of Symfony's `JsonResponse` enforcing `application/vnd.api+json` and the canonical encoding flags (`JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR`). App-level controllers should construct `JsonApiResponse` directly when a typed JSON:API response is wanted; foundation routers continue to use `Waaseyaa\Foundation\Http\JsonApiResponseTrait::jsonApiResponse()` (canonical helper, returns base `JsonResponse`).

The mission's charter is **Path R-narrow**: HTTP request/response and event-dispatch only. **Routing internals stay Symfony-coupled** — `RouteBuilder` and friends continue to expose `Symfony\Component\Routing\Route` types in their public method signatures. App code that registers routes via service providers continues to import `Symfony\Component\Routing\Route` after this mission lands; a separate `routing-symfony-decoupling` follow-up mission is filed at mission close.

The duplicate `Waaseyaa\Api\JsonResponseTrait` (a plain JSON helper, not a JSON:API helper) was deleted as orphan; only its own unit test referenced it. Per amended C-004, the canonical JSON:API trait remains in foundation (`Waaseyaa\Foundation\Http\JsonApiResponseTrait`); api consumers may import it directly — L4 → L0 imports are permitted by the layer rule.

The `packages/api/tests/Contract/SymfonyImportBoundaryTest` asserts that a sample app controller fixture produces a JSON:API response without importing any `Symfony\` class — it is the executable contract that backs this Path R-narrow promise for app-side controllers.

### Symfony Import Boundary (linter — #1374)

Per ratified C-005 (b), `bin/check-symfony-imports` is the codebase-wide gate that supplements the per-fixture contract test. It scans every `packages/*/src/**/*.php` file and fails when a `use Symfony\…` import (including `use function Symfony\…` and `use const Symfony\…`) appears outside the allowlist.

**Allowlist** lives in `.symfony-import-allowlist.json` at the repo root (deliberately not hardcoded in the script):

| Field | Purpose |
|---|---|
| `allowed_directories` | Path prefixes whose internal infrastructure is intentionally Symfony-coupled. Currently: `packages/foundation/`, `packages/routing/`, `packages/api/`, `packages/validation/`, `packages/cli/`. Tests are implicitly excluded — the gate only walks `packages/*/src/`, never `packages/*/tests/`. |
| `legacy_files` | Explicit list of source files that pre-date the boundary and still import Symfony. The gate locks the historical surface; new violations in any package fail CI. Refactor a file to use Waaseyaa surfaces, then remove its entry. |

**Wiring.** Runs as part of `composer verify` (between `check-ingestion-defaults` and `test`). Standalone invocations:

```bash
composer check-symfony-imports        # gate run; exits 0 if clean
bin/check-symfony-imports --list-stale # also reports legacy_files entries
                                       # whose file no longer has Symfony
                                       # imports — remove them from the list
```

**Adding a new violation.** If a new file genuinely needs a Symfony import (e.g. a new directory acting as framework infrastructure), do one of:

1. Replace the import with the equivalent Waaseyaa surface (`Waaseyaa\Foundation\Http\Request`, `Waaseyaa\Foundation\Event\EventDispatcherInterface`, `Waaseyaa\Api\Http\JsonApiResponse`).
2. Add the file path to `legacy_files` in the JSON, with the rationale captured in the PR description.
3. If a whole new directory should be allowed, add it to `allowed_directories` — but this should be rare and warrants discussion (every entry weakens the gate).

**Refactoring legacy entries.** Replace the import with the Waaseyaa surface, run `bin/check-symfony-imports --list-stale` to confirm the file is reported as stale, and remove the entry from `legacy_files` in the same commit.

**Soft-rot tradeoff.** The historical 90-file `legacy_files` list captures the implicit Symfony coupling that accumulated before mission #1107. The gate's job is to prevent further drift, not to clean up history — that's a slow incremental refactor. Each refactored file is one less entry; the list shrinks over time.

## Implementation gotchas

- **`SchemaPresenter` `x-access-restricted`**: JSON Schema extension marking fields viewable but not editable. The admin SPA reads this to show disabled widgets instead of hiding the field. Distinct from system `readOnly` (id, uuid) which hides the field from forms entirely.
- **`SchemaController` field definitions**: `SchemaController::show()` passes `$entityType->getFieldDefinitions()` to `SchemaPresenter::present()`. Field definitions are registered per entity type via the `fieldDefinitions:` constructor param on `EntityType`.
- **`JsonApiResource::toArray()` omits empty keys**: `attributes` and `relationships` are omitted from serialized output when empty, not set to `[]`. Tests should use `assertArrayNotHasKey` for empty fields, not `assertEmpty`.
- **Sparse fieldsets**: `index()` and `show()` filter both `attributes` and `relationships` via `SparseFieldsetApplicator` when `fields[type]` is present, matching JSON:API (`fields[type]` applies to sparse fieldsets for that resource type).
- **`toMachineName()` can return empty string**: Labels with only special characters (e.g. `"!!!"`) produce empty machine names after regex replacement and trim. `JsonApiController::store()` guards against this with a 422 response. Any caller of `toMachineName()` must validate the result.
- **Paired nullable parameters**: `ResourceSerializer::serialize()` and `SchemaPresenter::present()` accept `?EntityAccessHandler` + `?AccountInterface`. Both must be non-null or both null — only two of four states are meaningful. Guard with `if ($handler !== null && $account !== null)`.

<!-- Spec reviewed 2026-05-17 - dead-code baseline reduction (#1493 / PR TBD): @api PHPDoc sweep on extension-point classes + WaaseyaaEntrypointProvider extended to recognize EntityBase/ContentEntityBase subclasses and their traits. No behavioural change. -->

<!-- Spec reviewed 2026-05-17 - dead-code Phase 3 Bucket 4: @api PHPDoc sweep on additional public-API classes. No behavioural change. -->

<!-- Spec reviewed 2026-05-18 - mission two-factor-end-to-end-01KRW8TN (#1499): adds 2FA section to access-control, adds 4 new routes documented in routing surface. No behavioural change to existing access pipeline. -->

<!-- Spec reviewed 2026-05-25 - mission oidc-flows-completion-01KSEFTP: adds OIDC HTTP route registration via `Waaseyaa\Routing\AuthOidcRouteServiceProvider` and `Waaseyaa\Routing\OidcHttpRoutes` (L4 routing layer). Authorize / token / userinfo / JWKS / discovery endpoints. Userinfo response consults `FieldAccessPolicyInterface` per DIR-004 — fields with Forbidden access for the resolved account are redacted via the open-by-default semantics. Admin SPA client registration UI lives in `docs/specs/admin-spa.md`. Authoritative DTOs and controller methods live in `packages/oidc/src/`. -->

<!-- Spec reviewed 2026-05-25 - mission mercure-broadcast-monitor-m5d-01KSEFTD (#1415): adds `GET /api/mercure/channels`, `GET /api/mercure/events` (SSE), `GET /api/mercure/subscriptions` read-only admin endpoints. Authoritative contracts in `packages/api/src/MercureMonitor/`. Adapters implementing those contracts live cross-layer in `packages/foundation/src/Http/Inbound/` (Inbound is the documented L0 surface for kernel-bound read-model adapters; exempted in both `bin/check-package-layers` and `packages/foundation/tests/Unit/LayerDependencyTest.php`). Identity-safety invariant NFR-004: subscriber rows redact Authorization, Cookie, User-Agent, and any 64-char hex tokens. -->

<!-- Spec reviewed 2026-05-25 - mission ocap-audit-log-substrate-01KSEFTF: introduces `waaseyaa/audit` package (renamed from `analytics`) as the canonical OCAP audit log. New entity `AuditEvent` with append-only semantics indexed by `(account_uuid, entity_uuid, event_kind, occurred_at)`. Listeners on entity lifecycle, API requests, agent runs, MCP dispatch, and broadcasting; best-effort try-catch wrapping per CLAUDE.md gotcha. Query API `GET /api/audit/events` with filterable `kind`/`account`/`entity`/`date-range`. Retention CLI `bin/waaseyaa audit:prune --older-than=<duration>`. Operationally embodies DIR-004 (OCAP-by-architecture) at the substrate layer; M-A5 is the per-record AI-access wiring on top of it. -->

<!-- Spec reviewed 2026-05-25 - mission per-record-ai-access-flagship-01KSEFT5 (gap-matrix A5): operationally embodies DIR-004. WP02 wires `FieldAccessPolicyInterface` into the MCP entity serializer (`packages/mcp/src/Serializer/McpEntityFieldFilter`); forbidden fields are replaced in MCP responses with `{accessRestricted: true, reason: "field_forbidden_for_account"}` (canonical redaction shape, single source of truth). WP03 adds `AiAccessibleField` tri-state field type (`yes/no/inherit`, default `inherit`) on `media` and `attachment` entities; `AiAccessibilityPolicy` (intersection type implementing `AccessPolicyInterface & FieldAccessPolicyInterface`) returns Forbidden only when `ai_accessible='no'` AND the request is agent-initiated (detected via `_agent_run_id` request attribute, no L1↔L5 coupling). -->

<!-- Spec reviewed 2026-05-25 - mission api-surface-consolidation-jsonapi-primary-01KSEFTV: JSON:API declared the framework's primary API surface (DIR-007). GraphQL demoted from waaseyaa/full require to suggest; README banner added to packages/graphql/. Parity matrix in docs/specs/jsonapi.md confirms zero GAP rows — all GraphQL entity operations (list, show, create, update, delete) have JSON:API equivalents via JsonApiController. Admin-specific endpoints (queue, scheduler, notifications, workflow guards, Mercure monitor, OIDC clients, audit, discovery, broadcast, field auto-save, translations) are JSON:API-only with no GraphQL equivalent (no gap — JSON:API is primary). -->
<!-- Spec reviewed 2026-05-25 - mission versioned-blob-media-abstraction-01KSEFTJ WP03 (DIR-005): adds `GET /api/media/{uuid}/versions` (list) and `GET /api/media/{uuid}/versions/{vid}` (show) read-only endpoints. Gated by `_authenticated` route option. Per-version access filtering via `GateInterface` in `ApiMediaVersionAdapter` — forbidden versions silently omitted from list, 403 on direct show. Binary-stream download deferred (FR-010). DTOs and interface in `packages/api/src/Media/` (`MediaVersionReadModelInterface`, `MediaVersionResource`, `ApiMediaVersionAdapter`). Controller `MediaVersionController` returns typed array payloads; router `MediaVersionApiRouter` maps to JSON:API responses. Routes registered in `BuiltinRouteRegistrar` (`api.media.versions.index`, `api.media.versions.show`). Wired in `ApiServiceProvider::register()` (singleton) and `httpDomainRouters()`. Integration-tested by `PhaseMediaVersioning/MediaVersioningIntegrationTest` (dedup + ordering) and `ForbiddenVersionIntegrationTest` (per-version gate). Refs FR-008, FR-009, FR-013, FR-014. -->
