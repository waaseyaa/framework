# Infrastructure

<!-- Spec reviewed 2026-06-04 - PR #1614: kernel + foundation changes. New Foundation capability interface `AcceptsMigrationProvidersInterface` (Tier 3, documented in the ServiceProvider extension-hooks table below) gates `AbstractKernel::injectMigrationProviders()`, so the kernel hands the discovered migration providers to the migration ServiceProvider via a named interface instead of a concrete-FQCN `instanceof` (keeps the contract test's "kernel call sites resolve to an interface" invariant). `HttpKernel` gained `shouldUseDevFallbackAccount()` (development APP_ENV + `auth.dev_fallback_account` opt-in) for the local dev admin account. `PackageManifestCompiler` broadened its provider-scan `catch (\ReflectionException)` to `catch (\Throwable)` so a package shipping a class under `src/` that extends a dev-only symbol cannot crash a consumer's kernel boot. -->
<!-- Spec reviewed 2026-06-09 - alpha.201 #1630: backfilled real READMEs for the add-on packages (inertia, groups, notification, mercure, workflows). Documentation only — no change to any infrastructure contract, service-provider wiring, or runtime behaviour. -->
<!-- Spec reviewed 2026-05-28 - M5C WP01 (mcp-endpoint-admin-01KSEFTL) BuiltinRouteRegistrar gains three `_role: admin` routes for the MCP-admin REST surface — GET /api/mcp/tools, GET /api/mcp/tools/{name}, GET /api/mcp/server-config — all registered via string FQCN ('Waaseyaa\\Api\\Controller\\McpAdminController'), same L0-safe pattern that CodifiedContextController / WorkflowGuardsController / QueueController already use. Controller-side contract (DomainRouterInterface dispatch via McpAdminApiRouter, DTOs, NFR-003 no-plaintext-token guarantee) is owned by docs/specs/api-layer.md; this entry records only the route-registrar surface change. Pre-existing built-in routes unchanged. Also notes: McpServiceProvider (Layer 6) now binds ToolRegistryReadModelInterface + ServerConfigReadModelInterface via `$this->resolve(...)` / `$this->resolveOptional(...)` (the previous `$this->make(...)` form was retired — that method does not exist on the L0 ServiceProvider base and crashed kernel boot on installs exercising the MCP-admin surface). -->
<!-- Spec reviewed 2026-05-24 - M4A-5 Phase 1 (#1470) workflow guards routes: BuiltinRouteRegistrar gets one more `_role: admin` route — GET /api/workflow-definitions/{workflow_id}/guards — registered via string FQCN ('Waaseyaa\\Api\\Controller\\WorkflowGuardsController'), same L0→L4 layer-safe pattern. WorkflowServiceProvider (packages/workflows, Layer 3) binds AuthoringRoleMatrix as a container singleton with editorial workflow guards seeded from EditorialTransitionAccessResolver::allowedRolesForTransition() — without this binding the dashboard surface was dead code in production (cycle-2 fix). Existing entity-type registration in WorkflowServiceProvider unchanged. -->
<!-- Spec reviewed 2026-05-24 - #1576 post-merge fixup: DbalTransport::applyStatusFilter() now captures the fluent return from DBALSelect::isNull/isNotNull. No contract change — pure code-style cleanup to satisfy DBAL's fluent-builder warning under failOnWarning=true. -->
<!-- Spec reviewed 2026-05-24 - #1576 TransportInterface::listJobs() extension: new mandatory method on the queue Transport contract (`@api`). DbalTransport implements via separate COUNT+SELECT against waaseyaa_queue_jobs (reserved_at IS NULL → queued, NOT NULL → in_progress). InMemoryTransport merges $queues + $reserved sorted by id. Existing push/pop/ack/reject/release/size/purge unchanged. New abstract contract test in packages/queue/tests/Contract/ (AbstractTransportContract base + concrete subclasses per backend) exercises both implementations against the same expectations. -->
<!-- Spec reviewed 2026-05-24 - M4C (#1472) admin notification channels: BuiltinRouteRegistrar gains two more `_role: admin` routes registered via the same string-FQCN pattern (`'Waaseyaa\\Api\\Controller\\NotificationController'`) — index at /api/notification/channels and test at /api/notification/channels/{type}/test. NotificationDispatcher (packages/notification) gains one public read accessor (`channels(): array`) so the L4 ApiServiceProvider can enumerate registered channels for the dashboard. No other notification-package behaviour changes; send/sendAsync/sendToMany unchanged. -->
<!-- Spec reviewed 2026-05-24 - M4B (#1471) admin queue + scheduler dashboards: BuiltinRouteRegistrar gains five new `_role: admin` routes (three for the queue surface — index/retry/discard at /api/queue/jobs — and two for the scheduler — index at /api/scheduler/tasks and trigger at /api/scheduler/tasks/{name}/trigger). All five register via string FQCN ('Waaseyaa\\Api\\Controller\\QueueController' / 'Waaseyaa\\Api\\Controller\\SchedulerController') — the same Layer-0-safe pattern that CodifiedContextController already uses to avoid an upward L0→L4 import. SchedulerServiceProvider (packages/scheduler) additionally binds ScheduleStateRepository as a container singleton in the database driver so the L4 ApiServiceProvider can resolveOptional() it for the dashboard's last_run / last_status display; pre-existing ScheduleRunner factory is now passed the resolved instance instead of `new`-ing it inline — behaviour-preserving refactor. ScheduleRunner gained a new public `runOne(string $taskName, \\DateTimeInterface $now)` for the dashboard "Run now" action (bypasses isDue() per operator intent; still honours preventOverlap). See docs/specs/api-layer.md for the controller-side contract and docs/specs/admin-spa.md for the SPA route inventory. -->
<!-- Spec reviewed 2026-05-22 - M3 WP01 (bimaaji-mcp-bridge-01KS5VS8): retired the dead foundation McpRouter intercept (deleted packages/foundation/src/Http/Router/McpRouter.php + its entry in HttpKernel::$foundationRouters at line 411 + McpRouterTest). The router guarded a literal `_controller === 'mcp.endpoint'` string that no real route ever set — only artificial unit-test fixtures did. Production /mcp dispatch already flows through SSR's AppControllerRouter to Waaseyaa\Mcp\McpEndpoint::handle. HttpKernel.php otherwise unchanged (the $mcpReadCache field + cache-listener registrar paths remain — still consumed by legacy McpController via direct instantiation in tests/Integration/Phase14/AiMcpIntegrationTest.php). See docs/specs/mcp-endpoint.md for the corresponding MCP-side stamp. -->
<!-- Spec reviewed 2026-05-20 - M-D scheduler-entry sprint: new Waaseyaa\Scheduler\ScheduleEntriesInterface (L0) is auto-discovered by PackageManifestCompiler (scanPsr4Classes filters /testing/ dirs to keep autoload-dev classes out of production scans). AbstractKernel::boot() calls bootScheduleEntries() after discoverAccessPolicies(); ScheduleEntryRegistry instantiates implementors via M-B's PolicyDependencyResolverInterface and registers them on a single Schedule instance. Fail-closed: an unresolvable dep throws ScheduleEntryInstantiationException and aborts boot. schedule.disabled_entries opt-out honored. Implementors today: AgentScheduleEntries (ai:purge-runs, ai:reap-stalled-runs), BroadcastStorageScheduleEntries (broadcast_log_prune nightly). See docs/specs/operations-playbooks.md for the operator-facing surface. -->
<!-- Spec reviewed 2026-05-20 - BroadcastRouter no longer replays _broadcast_log history on EventSource connect. New SSE connection cursor: BroadcastRouter::resolveInitialCursor(Request, int $highWaterMark) — resumes from numeric `Last-Event-ID` header when present (auto-reconnect path), else returns BroadcastStorage::maxId($channels) (start-from-now). Each emitted SSE frame now carries `id: {row_id}` so EventSource sends Last-Event-ID on its native reconnect. BroadcastStorage gained public maxId(array $channels = []): int. Behavioral change visible to consumers: a fresh EventSource no longer receives historical entity.saved/entity.deleted events — clients that need backlog must query their own state. -->
<!-- Spec reviewed 2026-05-18 - DiagnosticCode::MANIFEST_VERSIONING_MISSING remediation message edited to drop the `bin/check-milestones` hint sentence (script deleted as part of the GitHub-milestones decommission). Diagnostic code identity, severity, and triggering call sites unchanged; no infrastructure contract surface affected. -->
<!-- Spec reviewed 2026-05-13 - #1394 HttpKernel::applyTrustedProxiesFromConfig() invoked at the start of serveHttpRequest() before any code reads $request->isSecure(). Sources: $this->config['trusted_proxies'] (typed; wins) -> TRUSTED_PROXIES env var (comma-separated CIDRs / IPs / Symfony's REMOTE_ADDR sentinel). When the resolved list is non-empty, calls Request::setTrustedProxies with HEADER_X_FORWARDED_{FOR,HOST,PROTO,PORT}. Empty list preserves the pre-fix default (X-Forwarded-* headers ignored — the safe default for setups without a TLS terminator). Internal kernel boot logic; not part of the public surface map. No infrastructure contract surface changed. -->
<!-- Spec reviewed 2026-05-12 - #1443 AbstractKernel::validateQueryDefinitions() added after discoverAccessPolicies() in boot(); wires DefinitionValidator (entity-storage L1) via BackendRegistrarFactory+BackendResolver for FR-021 fail-fast enforcement. Kernel bootstrapper exemption applies (CLAUDE.md). No infrastructure contract surface changed. -->
<!-- Spec reviewed 2026-05-11 - M4A-1 (#1428 / umbrella #1414) new kernel-adjacent route registrar `WorkflowDefinitionsApiRouter` in foundation/src/Http/Router/ wired alongside CodifiedContextApiRouter et al. in HttpKernel::$foundationRouters; route entry added to BuiltinRouteRegistrar for `GET /api/workflow-definitions` (admin-role-gated). Pure wiring change; no infrastructure contract surface affected. -->
<!-- Spec reviewed 2026-05-11 - M4A-4 (#1434 / umbrella #1414) BuiltinRouteRegistrar and WorkflowDefinitionsApiRouter extended to dispatch `POST /api/workflow-definitions/dry-run` to the new WorkflowDryRunController in waaseyaa/api (admin-role-gated). Pure wiring change; no infrastructure contract surface affected. -->
<!-- Spec reviewed 2026-05-10 - M3A (#1413) SchemaRouter ctor takes optional FieldDefinitionRegistryInterface and forwards to SchemaPresenter; HttpKernel passes $this->fieldRegistry. Pure wiring change; no infrastructure contract surface affected. -->
<!-- Spec reviewed 2026-05-10 - WP05 php-8.5 upgrade: @PHP8x5Migration cs-fixer pass — AbstractKernel, HttpKernel, ExceptionHandler, WaaseyaaException, IngestionLogger, IngestionLogEntry, InertiaServiceProvider, RootTemplateRenderer, StreamHttpClient touched by new_expression_parentheses + octal_notation rules only; no semantic change to infrastructure contracts. -->
<!-- Spec reviewed 2026-05-10 - WP03 php-8.5 upgrade: DBALSelect fluent methods (fields/addField/condition/isNull/isNotNull/orderBy/range/join/leftJoin/countQuery) gained #[\NoDiscard] — no change to query builder semantics or DBAL abstraction contract. -->
<!-- Spec reviewed 2026-05-08 - issue #1397: `Worker::run()` enforces `WorkerOptions::$memoryLimit` as MiB of **additional** allocation since the start of each `run()` call (`memory_get_usage(true)` baseline), not total PHP process RSS — embedded hosts (PHPUnit full suite, long-lived FPM) may already exceed the cap before `run()` begins; see `packages/queue/src/Worker/Worker.php`. -->
<!-- Spec reviewed 2026-05-04d - issue #1301 (deferred mission #1257 WP09): portable orphan-bundle-subtable detection. SchemaInterface gained `listTableNames(): list<string>` (DBALSchema delegates to Doctrine's AbstractSchemaManager::listTableNames()). HealthChecker::findOrphanSubtables() replaced the SQLite-only `sqlite_master` LIKE query with `$this->database->schema()->listTableNames()` + `str_starts_with($name, $baseTable . '__')` filter — portable across SQLite, MySQL, PostgreSQL, and any other DBAL-supported driver. The `\Throwable` swallow path is gone (no longer needed). See docs/specs/bundle-scoped-storage.md §"Drift diagnostic" and docs/specs/operator-diagnostics.md §"Bundle Subtable Drift" for the operator-facing contract update. -->
<!-- Spec reviewed 2026-05-04c - issue #1376 (deferred WP07-A from mission #1257): AbstractKernel::bootEntityTypeManager() now passes a bundle-subtable existence probe to EntityTypeManager so addBundleFields() can emit a once-per-(entity_type_id, bundle) `[BUNDLE_SUBTABLE_MISSING]` notice when the per-bundle subtable is not yet materialized on disk. Probe uses `$database->schema()->tableExists(SqlSchemaHandler::resolveSubtableName(...))`. See docs/specs/entity-system.md (header) for the full contract. -->
<!-- Spec reviewed 2026-05-04b - issue #1309: added `COLUMN_DATA_STORAGE_DRIFT` to the DiagnosticCode table. HealthChecker gained `checkColumnDataStorageDrift()` (called from `checkRuntime()`), which warns when a field registered with `FieldStorage::Data` still has a backing column on the base table or a registered bundle subtable. Severity: warning. -->
<!-- Spec reviewed 2026-05-04 - cs-fixer sweep applied to AbstractKernel.php (alphabetical import sort only, no behavior change). Spec contents verified accurate; no infrastructure-spec contract changed. -->
<!-- Spec reviewed 2026-05-02b - mission #1257 WP10 review hardening (PR #1367): `AbstractKernel::resolveCommunityScope()` no longer falls back to a null scope unconditionally when tenancy is declared but no `CommunityContextInterface` is bound. In production (any environment NOT in {local, dev, development, testing}), missing context throws `[TENANCY_MISCONFIGURED] RuntimeException` — silent disablement of community isolation in production is a data-leak posture, not a tolerable misconfiguration. In development the prior log-once + null-scope path stays so tests / CLI / bare bootstrap don't crash. -->
<!-- Spec reviewed 2026-05-02 - mission #1257 WP10: AbstractKernel gained `setCommunityContext(?CommunityContextInterface)` plus a private `resolveCommunityScope(EntityTypeInterface)` helper that the EntityRepository factory uses to inject CommunityScope into SqlStorageDriver when the EntityType declares `tenancy: ['scope' => 'community']`. When tenancy is declared but no context is bound, the kernel logs a once-per-type warning and falls back to a null scope (no boot crash; superseded by the 2026-05-02b production-throw guard above). EntityTypeManager now also receives the kernel logger so it can emit the C1 deprecation warning. Wiring contract details live in docs/specs/entity-system.md §C1 / §Community Scoping; no infrastructure-spec contract changed. -->
<!-- Spec reviewed 2026-05-10 - StreamHttpClient migrated from $http_response_header (deprecated in PHP 8.5) to http_get_last_response_headers(); behaviour unchanged (mission php-8-5-upgrade WP02) -->
<!-- Spec reviewed 2026-05-01b - BootFailureMessageFormatter extracted as a public seam in packages/foundation/src/Kernel/: HttpKernel::bootFailureJsonResponse delegates clientSafeBootFailureDetail mapping to the formatter, eliminating 3× setAccessible() in HttpKernelBootFailureTest; behavior unchanged (mission #824 WP09 surface H) -->
<!-- Spec reviewed 2026-05-01 - README skeletons added under packages/billing/, packages/deployer/, packages/github/, packages/http-client/, packages/inertia/ (purpose, layer, key classes only); StripeClientInterface, GitHubClient, HttpClientInterface, Inertia adapter, and Deployer recipe contracts unchanged from prior review (mission #824 WP09 surface F, closes #849) -->
<!-- Spec reviewed 2026-04-26 - Package-declared migrations: extra.waaseyaa.migrations path, MigrationLoader glob order, OIDC exemplar migration (#1286) -->
<!-- Spec reviewed 2026-04-26 - AbstractKernel bootEntityTypeManager: SqlSchemaHandler constructed with kernel LoggerInterface for deriveColumnSpec unknown-type warnings (#1305); see docs/specs/field/column-derivation.md -->
<!-- Spec reviewed 2026-04-25 - packages/testing stub entities: constructor/metadata alignment for EntityTypeManager parity tests only; no kernel/bootstrap contract change -->
<!-- Spec reviewed 2026-04-24 - CodifiedContextApiRouter + HttpKernel codified-context store getters/setters; BuiltinRouteRegistrar telescope agent-context + codified-context HTTP routes -->
<!-- Spec reviewed 2026-04-24 - packages/http-client StreamHttpClient; packages/inertia InertiaServiceProvider (PHPStan-only); packages/queue timestamped migrations + CreateQueueTables DDL (waaseyaa_queue_jobs / waaseyaa_failed_jobs) -->
<!-- Spec reviewed 2026-04-24 - Layer 0 env variable contract subsection (APP_ENV, APP_DEBUG, WAASEYAA_DB, WAASEYAA_CONFIG_DIR, .env/EnvLoader) + assert/IO review note after boot guard -->
<!-- Spec reviewed 2026-04-22 - PackageManifest: removed persisted commands/routes (ADR docs/adr/0001); legacy extra.waaseyaa.commands|routes log warning only; fromArray strips legacy cache keys; mergeRootWaaseyaa merges providers+permissions only; attributeEntityTypes; ProviderRegistry entity_auto_register; ServiceProvider::mergeChildProvider; BuiltinRouteRegistrar: MCP route owned by mcp package only, sortRoutesByPriority after provider routes; MigrationLoader InstalledVersions; queue/notification/scheduler extra.waaseyaa.migrations -->
<!-- Spec reviewed 2026-04-30 - layer-graph file-level scan + named-file kernel exemption surface (mission #824 WP02 surface C) -->
<!-- Spec reviewed 2026-04-22 - require-dev layer audit script + CI integration (warn-only), plus composer layer graph docs -->
<!-- Spec reviewed 2026-04-21 - Composer layer graph (bin/check-package-layers), HTTP JSON-first error surface, database-legacy ADR 007 cross-link -->
<!-- Spec reviewed 2026-04-05 - SovereigntyProfile/Config added to foundation, FoundationServiceProvider registers SovereigntyConfig singleton; CommunityContext/CommunityMiddleware added for community-scoped query isolation; SsrResponse removed, all controllers return Symfony Response/JsonResponse; ControllerDispatcher now delegates to DomainRouterInterface chain; both callable and router dispatch paths wrapped in try-catch returning 500 JSON:API errors; MediaRouter file move wrapped in try-catch; ViteAssetManager gained assetTags() method with devServerUrl constructor param for dev mode support; ControllerDispatcher uses Inertia::getRenderer() instead of hardcoded new RootTemplateRenderer(); RootTemplateRenderer accepts optional ViteAssetManager and injects Vite asset tags in default template; InertiaServiceProvider auto-configures renderer with ViteAssetManager for zero-config Inertia SPA support; AppControllerRouter added to dispatch Class::method controllers from ServiceProvider::routes() — delegates to SsrPageHandler::dispatchAppController, wired after SsrRouter in HttpKernel router chain (#1119); AppControllerRouter handle() relies on dispatchAppController's typed array shape contract (no runtime defensive casts); MediaRouter mkdir warning suppressed via @-prefix double-check idiom so a non-directory ancestor produces a clean 500 from the move catch block instead of a PHP warning under --fail-on-warning -->
<!-- Spec reviewed 2026-04-05 - AbstractKernel extracted: AppEntityTypeLoader, ContentTypeValidator, KnowledgeExtensionBootstrapper join existing Bootstrap/ classes (DatabaseBootstrapper, ManifestBootstrapper, ProviderRegistry, AccessPolicyRegistry) -->
<!-- Spec reviewed 2026-04-06 - GraphQlRouter: inline `new \Waaseyaa\GraphQL\GraphQlEndpoint(...)` replaced with a proper `use Waaseyaa\GraphQL\GraphQlEndpoint` import (#1091 cleanup, no behavior change). Also reverted an incorrect fix that had added `waaseyaa/ssr` as a hard `require` of foundation's composer.json — that violated the layer rule (layer 0 must not depend on layer 6) and tripped `LayerDependencyTest::foundationDoesNotDependOnHigherLayerPackages`. The pre-existing architectural debt it was trying to paper over (HttpKernel directly imports `Waaseyaa\SSR\RenderCache`, `SsrPageHandler`, `SsrServiceProvider`, `TwigErrorPageRenderer`; `SsrRouter` and `AppControllerRouter` live in foundation but require `SsrPageHandler`; `EventListenerRegistrar::registerRenderCacheListeners()` type-hints `RenderCache`) is tracked as a separate P1 refactor follow-up to #571. -->
<!-- Spec reviewed 2026-04-07 - ControllerDispatcher and DiscoveryRouter: import ordering corrected to satisfy PHP-CS-Fixer alphabetical rule (no behavior change). ControllerDispatcher now uses `use Waaseyaa\Inertia\Inertia` import instead of inline FQCN for `Inertia::getRenderer()` call. LanguageResolver extracted from SsrPageHandler (#572): language detection, negotiation, and path prefix stripping now live in a dedicated service; HttpKernel delegates to SsrPageHandler::getLanguageResolver()->stripLanguagePrefixForRouting(). -->
<!-- Spec reviewed 2026-04-07 - packages/billing and packages/inertia composer.json: waaseyaa/foundation requires use ^0.1 for split/Packagist consumers (#1138); no runtime change -->
<!-- Spec reviewed 2026-04-08 - composer manifest policy normalization across infrastructure-layer packages; no infrastructure runtime behavior change -->
<!-- Spec reviewed 2026-04-15 - packages/github composer.json now matches the standard split-package metadata shape for infrastructure packages: minimum-stability stable plus dev-main/dev-develop branch aliases so canonical path repos can satisfy ^0.1 during local app development and metapackage resolution -->
<!-- Spec reviewed 2026-04-08b - restored packages/foundation, packages/search, and packages/testing Symfony floors (^7.3 -> ^7.0) where no runtime/API requirement justified tighter constraints -->
<!-- Spec reviewed 2026-04-08c - entity, entity-storage, queue, routing, typed-data, validation Symfony floors to ^7.0; see symfony-version-floors.md (#1151) -->
<!-- Spec reviewed 2026-04-09 - typed-data: EntityCastCoercion, CoercionException, CastTokenMapper; entity ValueCaster delegates builtins (#1185); public surface map extended -->
<!-- Spec reviewed 2026-04-10 - testing package EntityTypeFixtureValues + EntityFactory::defineFromEntityType (#1186) -->
<!-- Spec reviewed 2026-04-11 - AbstractKernel::bootEntityTypeManager passes a third closure to EntityTypeManager wiring SqlSchemaHandler, SqlStorageDriver, optional RevisionableStorageDriver, and EntityRepository for getRepository() (#1128) -->
<!-- Spec reviewed 2026-04-08 - #1129/#1134: HttpKernel::finalizeBoot() wires DB cache bins and discovery handler; SSR owns RenderCache listeners + SsrPageHandler via SsrServiceProvider::configureHttpKernel; ErrorPageRendererInterface bound in SSR; provider httpDomainRouters() merged after foundation routers through McpRouter and before BroadcastRouter; DiscoveryRouter/GraphQlRouter/MediaRouter live in api/graphql/media packages; ControllerDispatcher uses Inertia foundation interfaces + optional InertiaFullPageRendererInterface; LayerDependencyTest gates non-Router Foundation Http/ against non-Foundation Waaseyaa imports -->
<!-- Spec reviewed 2026-04-22 - HttpKernel boot failures now always return JSON:API (DevExceptionRenderer branch removed) -->
<!-- Spec reviewed 2026-04-22 - HttpKernel bootFailureJsonResponse: clientSafeBootFailureDetail maps known boot failures to operator-safe JSON detail (no DB paths); raw message when debug; critical log retains full exception -->
<!-- Spec reviewed 2026-04-08 - DX P2: HttpKernel boot catch returns HTML via DevExceptionRenderer when debug+package present else JSON:API bootFailureJsonResponse (non-empty body, #1117); ControllerDispatcher render.page returns 501 JSON when SsrPageHandler class unavailable (#1130); LogManager gains daily + fingers_crossed channel types -->
<!-- Spec reviewed 2026-04-08 - LogManager: handler key string = type synonym only; fingers_crossed nested config via nested, inner, or array handler; channel buffer_limit caps FingersCrossedHandler in-memory buffer (drops oldest); handlerTypeFromConfig + fingersCrossedBufferLimit helpers -->
<!-- Spec reviewed 2026-04-09 - Monorepo toolchain: PHPStan 2.x + phpstan-strict-rules 2.x; symfony/html-sanitizer ^8 required by waaseyaa/ssr (HtmlFormatter) and root composer (#1158 / #808/#809) -->
<!-- Spec reviewed 2026-04-09 - InboundHttpRequestInterface + InboundHttpRequest snapshot DTO in foundation Http/Inbound for SSR app-controller boundary; body merge from Request bag + _parsed_body; public-surface map lists interface -->
<!-- Spec reviewed 2026-04-09 - App controller Inertia: SsrPageHandler::dispatchAppController handles InertiaPageResultInterface (X-Inertia JSON + full HTML via InertiaFullPageRendererInterface, matching ControllerDispatcher); HttpKernel::getInertiaFullPageRenderer(); SsrServiceProvider injects that renderer when constructing SsrPageHandler in configureHttpKernel() -->
<!-- Spec reviewed 2026-04-10 - inertia RootTemplateRenderer: JSON script tag uses data-page="app" (mount id) so @inertiajs/core getInitialPageFromDOM finds the initial page -->
<!-- Spec reviewed 2026-04-09 - HttpKernel::serveHttpRequest: auth middleware short-circuit — return pipeline response whenever status !== 200 (302 login redirect, 401/403 JSON), not only when status >= 400, so unauthenticated SSR routes cannot fall through to controller dispatch -->

<!-- Spec reviewed 2026-04-20 - ServiceProvider now preserves entity-type registrant provenance and ProviderRegistry rethrows entity-type collision exceptions after logging so duplicate canonical registrations fail boot deterministically (#1313) -->
<!-- Spec reviewed 2026-04-30 - ServiceProvider extension-hook enumeration: 10 interface methods, 6 abstract-base capability-split candidates, 1 capability interface (LanguagePathStripperInterface); lockstep enforced by ServiceProviderContractTest (mission #824 WP03 surface C) -->
<!-- Spec reviewed 2026-04-30b - ServiceProvider capability split: graphqlMutationOverrides lifted from abstract base into HasGraphqlMutationOverridesInterface; GraphQlServiceProvider guards the call with instanceof; tier 2 down to 5 candidates, tier 3 up to 2 (mission #824 WP03 surface D) -->
<!-- Spec reviewed 2026-04-30c - ServiceProvider capability split: commands lifted from abstract base into HasCommandsInterface; ConsoleKernel guards the call with instanceof; NorthCloudServiceProvider implements the new interface; tier 2 down to 4 candidates, tier 3 up to 3 (mission #824 WP03 surface E) -->
<!-- Spec reviewed 2026-05-08 - HasCommandsInterface deleted by mission native-cli-kernel-01KR2NR7 WP23 hard-cut; ConsoleKernel now wires exclusively via HasNativeCommandsInterface (Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface) backed by CliKernel; row removed from capability table below -->
<!-- Spec reviewed 2026-04-30d - ServiceProvider capability split: registerRenderCacheListeners lifted from abstract base into HasRenderCacheListenersInterface; HttpKernel finalizeBoot guards with instanceof; SsrServiceProvider implements the new interface; tier 2 down to 3 candidates, tier 3 up to 4 (mission #824 WP03 surface F) -->
<!-- Spec reviewed 2026-04-30e - ServiceProvider capability split: configureHttpKernel lifted from abstract base into ConfiguresHttpKernelInterface (verb-led name because it mutates the kernel rather than contributing values); HttpKernel finalizeBoot guards with instanceof; GenealogyServiceProvider and SsrServiceProvider implement the new interface; tier 2 down to 2 candidates, tier 3 up to 5 (mission #824 WP03 surface G) -->
<!-- Spec reviewed 2026-04-30f - ServiceProvider capability split: middleware lifted from abstract base into HasMiddlewareInterface; HttpKernel buildMiddlewarePipeline guards with instanceof; AuthServiceProvider, DebugServiceProvider, and InertiaServiceProvider implement the new interface; tier 2 down to 1 candidate (httpDomainRouters), tier 3 up to 6 (mission #824 WP03 surface H) -->
<!-- Spec reviewed 2026-04-30g - ServiceProvider capability split COMPLETE: httpDomainRouters lifted from abstract base into HasHttpDomainRoutersInterface; HttpKernel buildDomainRouterChain guards with instanceof; ApiServiceProvider, GraphQlServiceProvider, MediaServiceProvider, and SsrServiceProvider implement the new interface; tier 2 down to 0 candidates (now empty by design), tier 3 up to 7 (mission #824 WP03 surface I — the final capability split) -->

Specification for the foundational infrastructure layer of Waaseyaa CMS: domain events, cache system, database abstraction, query builder, migration system, kernel bootstrapping (including environment resolution and debug mode), service provider discovery, and queue workers.

## Public Surface

Authoritative dispositions are in `docs/public-surface-map.php`, verified by `PublicSurfaceVerificationTest`.

**Public API** (stable, semver-protected):

| Package | Interfaces/Classes |
|---------|-------------------|
| foundation | `AssetManagerInterface`, `HealthCheckerInterface`, `LoggerInterface`, `HandlerInterface`, `FormatterInterface`, `ProcessorInterface`, `LoggerTrait`, `HttpHandlerInterface`, `HttpMiddlewareInterface`, `JobHandlerInterface`, `JobMiddlewareInterface`, `RateLimiterInterface`, `SchemaRegistryInterface`, `ServiceProviderInterface`, `ServiceProvider`, `DomainEvent`, `WaaseyaaException`, `JsonApiResponseTrait`, `InboundHttpRequestInterface`, `DomainRouterInterface`, `LanguagePathStripperInterface`, `InertiaPageResultInterface`, `InertiaFullPageRendererInterface`, `Migration` |
| cache | `CacheBackendInterface`, `CacheFactoryInterface`, `CacheTagsInvalidatorInterface`, `TagAwareCacheInterface` |
| database-legacy | `DatabaseInterface`, `SelectInterface`, `InsertInterface`, `UpdateInterface`, `DeleteInterface`, `SchemaInterface`, `TransactionInterface` |
| plugin | `PluginInspectionInterface`, `PluginManagerInterface`, `PluginBase` |
| typed-data | `TypedDataInterface`, `DataDefinitionInterface`, `ComplexDataInterface`, `ListInterface`, `PrimitiveInterface`, `TypedDataManagerInterface`, `CastTokenMapper`, `CoercionException`, `EntityCastCoercion` |
| i18n | `LanguageManagerInterface`, `TranslatorInterface` |
| queue | `QueueInterface` |
| testing | `CreatesApplication`, `InteractsWithApi`, `InteractsWithAuth`, `InteractsWithEvents`, `RefreshDatabase`, `EntityFactory`, `EntityTypeFixtureValues` |

**`@internal`** (implementation details, may change without notice):

| Package | Interface/Class | Reason |
|---------|----------------|--------|
| foundation | `AbstractKernel` | Entry-point orchestrator, not a consumer contract |
| foundation | `TenantResolverInterface` | Multi-tenancy seam not yet stabilized |
| plugin | `PluginDiscoveryInterface`, `KnowledgeToolingExtensionInterface`, `PluginFactoryInterface` | Discovery/factory internals |
| queue | `HandlerInterface`, `TransportInterface`, `FailedJobRepositoryInterface`, `Job` | Queue backend internals |
| scheduler | `LockInterface`, `ScheduleInterface` | Scheduler internals |
| state | `StateInterface` | State machine internals |
| mail | `MailerInterface`, `TransportInterface` | `@internal` foundation seam (#798 closed — single `Mailer` + transport stack) |
| http-client | `HttpClientInterface` | Minimal wrapper, not yet stable |
| ingestion | `PayloadValidatorInterface`, `EnvelopeValidator` | Ingestion validation internals |
| testing | `WaaseyaaTestCase`, `AbstractGraphQlSchemaContractTestCase` | Test base classes, not consumer API |

## Packages

| Package | Namespace | Layer | Purpose |
|---------|-----------|-------|---------|
| `packages/foundation/` | `Waaseyaa\Foundation\` | 0 (Foundation) | DomainEvent, ServiceProvider, middleware interfaces, migration system, attribute discovery |
| `packages/cache/` | `Waaseyaa\Cache\` | 0 (Foundation) | CacheBackendInterface, MemoryBackend, DatabaseBackend, NullBackend, tag invalidation |
| `packages/database-legacy/` | `Waaseyaa\Database\` | 0 (Foundation) | DatabaseInterface, DBALDatabase (Doctrine DBAL), query builder (select/insert/update/delete), schema, transactions. Composer name keeps the `-legacy` suffix for historical reasons; see [ADR 007](../adr/007-database-legacy-package-naming.md). |
| `packages/plugin/` | `Waaseyaa\Plugin\` | 0 (Foundation) | PluginManager, attribute-based plugin discovery, plugin factory |
| `packages/mail/` | `Waaseyaa\Mail\` | 0 (Foundation) | `MailerInterface` + `Envelope`; pluggable `TransportInterface` (array, local file, SendGrid API when configured) |
| `packages/http-client/` | `Waaseyaa\HttpClient\` | 0 (Foundation) | Minimal HTTP client for JSON APIs and webhooks, zero external dependencies |

Infrastructure-layer split packages that ship as Packagist libraries are expected to carry the normal release metadata shape in `composer.json`: `minimum-stability: stable` and branch aliases for `dev-main` plus the active maintenance branch. That invariant matters for local path-repository workflows because canonical path repos must still satisfy `^0.1` constraints when apps override published packages during development.

### Composer layer graph

The monorepo enforces the seven-layer rule from `CLAUDE.md` on **runtime** Composer edges: `bin/check-package-layers` walks `packages/*/composer.json` and fails if any `require` entry `waaseyaa/*` targets a package **strictly above** the declaring package’s layer. Metapackages (`cms`, `core`, `full`) are skipped. `require-dev` remains non-fatal; use `bin/audit-require-dev-layers` for a warn-only report that surfaces upward dev-only edges without blocking merges. The canonical short-name → layer map lives in `bin/check-package-layers`; when you add a new first-party package, extend the map and the Layer Architecture table in `CLAUDE.md` together. This supersedes ad-hoc checks for historical issues such as foundation → path or validation → entity at the manifest level.

The PHP layer graph covers the **62 PHP packages** under `packages/` plus the three metapackages (`cms`, `core`, `full`, all skipped). `packages/admin/` is a Nuxt SPA (no `composer.json`, zero PHP source) and is not part of the PHP layer hierarchy; its PHP host extension is `waaseyaa/admin-surface`, which is what L6 actually means here.

The same script also scans every package's `src/**/*.php` for `use Waaseyaa\X\…` imports and fails on any import whose target package sits **above** the importing package's layer. This catches cross-layer leaks that don't show up in `composer.json` (e.g. a Foundation listener referencing an L1 entity event class without declaring an upward `require`). Diagnostics are emitted as `FAIL [PL005]` and name the offending file, the importing package's layer, and the imported package's layer.

### Kernel exemption surface (named files)

Some bootstrapper code intentionally sees across layers — kernels wire entity-type managers, route registrars register controllers from any layer, cache invalidators subscribe to events from higher layers. Two complementary exemption tiers in `bin/check-package-layers` codify this:

| Tier | Mechanism | Use for |
|------|-----------|---------|
| Implicit | `KERNEL_EXEMPT_DIR_SUFFIXES` (currently `/src/Kernel/`) | Files inside `<pkg>/src/Kernel/` — the canonical kernel/bootstrapper boundary documented in `CLAUDE.md` "Layer Architecture > Exemption" |
| Explicit | `KERNEL_EXEMPT_FILES` (path → rationale) | Kernel-adjacent files that must live outside `Kernel/` (route registrars wired only from `HttpKernel`, diagnostics wired only from `ConsoleKernel`, listeners wired only from a `ServiceProvider`) |

Each `KERNEL_EXEMPT_FILES` entry carries a one-line rationale string so future readers can audit whether the exemption is still load-bearing. To land a new kernel-adjacent file, either move it under `<pkg>/src/Kernel/` (preferred — the Composer-graph picture stays clean) or add an explicit named entry; the gate refuses to merge an unjustified cross-layer leak.

The named-file surface was added in mission #824 WP02 surface C and is the prerequisite for mission #1257 K6(c) (`HealthChecker` codified as kernel-adjacent rather than relocated).

### ServiceProvider extension hooks

Every `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` exposes a fixed set of hooks the kernel calls during bootstrap. Three tiers exist; the contract test at `packages/foundation/tests/Contract/ServiceProviderContractTest.php` keeps all three in lockstep with the actual kernel call sites (mission #824 WP03 surface B). Adding a Tier 1 method, removing a Tier 2 method, or wiring a new `instanceof`-guarded call site without an allowlist entry fails the contract test — drift cannot land silently.

**Tier 1 — `ServiceProviderInterface` (the public contract).** Every provider implements these; removing or signature-changing one breaks third-party providers.

| Method | Caller | Purpose |
|--------|--------|---------|
| `register(): void` | `ProviderRegistry::discoverAndRegister()` | Bind services. Called once per registration pass after `setKernelContext` and `setKernelServices`. |
| `boot(): void` | `ProviderRegistry::boot()` | Late wiring after every provider has registered. Subscribe to events, warm caches. |
| `routes(WaaseyaaRouter, EntityTypeManager): void` | `BuiltinRouteRegistrar::register()` | Contribute HTTP routes. |
| `provides(): list<string>` | `ProviderRegistry` (deferred check) | Service ids the provider intends to bind. |
| `isDeferred(): bool` | `ProviderRegistry` (deferred check) | Whether `register()` may be deferred until a `provides()` service is requested. |
| `getBindings(): array<string, array{concrete, shared}>` | `HttpKernel`, `HttpKernelServiceResolver`, `ProviderRegistryKernelServices` | Local bindings registered by this provider. |
| `resolve(string): object` | `HttpKernel`, `HttpKernelServiceResolver`, `ProviderRegistryKernelServices` | Resolve a binding; throws when neither local bindings nor the kernel-services bus knows the abstract. |
| `setKernelContext(string $projectRoot, array $config, array $manifestFormatters): void` | `ProviderRegistry::discoverAndRegister()` | Inject project root, config, and manifest formatters before `register()`. |
| `setKernelServices(KernelServicesInterface): void` | `ProviderRegistry::discoverAndRegister()` | Inject the kernel-services bus before `register()`. |
| `getEntityTypeRegistrations(): list<array{entityType, registrant}>` | `ProviderRegistry::discoverAndRegister()` | Entity-type registrations contributed during `register()`. |

> **`routes()` runs once at boot — resolve request-scoped services lazily inside the handler, not at route-build (#1611).** `BuiltinRouteRegistrar::register()` calls every provider's `routes()` exactly once during kernel boot. Any controller you construct *inside* `routes()` — and any service that controller captures via `$this->resolve(...)` at that point — is built a single time and reused for the lifetime of the process. If a captured service is request-scoped or otherwise ephemeral (e.g. `DatabaseInterface` / a connection that is replaced per request), the controller will keep using the stale boot-time instance and its **writes can be silently lost**. Do **not** resolve and `new` controllers eagerly in `routes()` when they depend on request-scoped state. Instead defer construction so resolution happens per dispatch: register a closure controller that resolves dependencies when the route is matched. See `Waaseyaa\AI\Agent\Routing\AgentRouteServiceProvider` for the canonical lazy-factory pattern (`$factory = fn() => $this->buildController();` wired into each route's controller callable). The eager style in `Waaseyaa\Routing\AuthOidcRouteServiceProvider` is only safe because the controllers it builds do not capture request-scoped DB state.

**Tier 2 — abstract `ServiceProvider` capability methods.** Empty after mission #824 WP03 surfaces D–I lifted every kernel-invoked hook into a capability interface. New kernel-invoked hooks should enter as capability interfaces, never as no-op defaults on the abstract base. The contract test's `ABSTRACT_BASE_ONLY` allowlist enforces the empty invariant.

**Tier 3 — capability interfaces (`instanceof`-guarded).** A provider opts in by implementing the named interface; the call site (kernel for foundation-owned hooks, the owning subsystem's bootstrap for cross-package hooks) checks `instanceof` before calling. The contract test's `CAPABILITY_INTERFACES` allowlist names which method belongs to which interface.

| Method | Capability interface | Caller |
|--------|---------------------|--------|
| `stripLanguagePrefixForRouting(string): string` | `Waaseyaa\Foundation\Http\LanguagePathStripperInterface` | `HttpKernel::stripLanguagePrefixForHttpRouting()` |
| `graphqlMutationOverrides(EntityTypeManager): array<string, array{args?, resolve?}>` | `Waaseyaa\Foundation\ServiceProvider\Capability\HasGraphqlMutationOverridesInterface` | `Waaseyaa\GraphQL\GraphQlServiceProvider::httpDomainRouters()` |
| `nativeCommands(): list<CommandDefinition>` | `Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface` | `ConsoleKernel::handle()` via `CliKernel` |
| `registerRenderCacheListeners(EventDispatcherInterface, ?CacheBackendInterface): void` | `Waaseyaa\Foundation\ServiceProvider\Capability\HasRenderCacheListenersInterface` | `HttpKernel::finalizeBoot()` |
| `configureHttpKernel(HttpKernel): void` | `Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface` | `HttpKernel::finalizeBoot()` |
| `middleware(EntityTypeManager): list<HttpMiddlewareInterface>` | `Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface` | `HttpKernel::buildMiddlewarePipeline()` |
| `httpDomainRouters(HttpKernel): iterable<DomainRouterInterface>` | `Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface` | `HttpKernel::buildDomainRouterChain()` |
| `withMigrationProviders(list<object>): void` | `Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsMigrationProvidersInterface` | `AbstractKernel::injectMigrationProviders()` |

The `withMigrationProviders` hook lets the kernel hand the discovered migration providers (objects exposing application migrations, found via the Layer-3 `HasMigrationsInterface`) to the provider that owns the migration registry, before that provider's `boot()` resolves the registry. The capability interface lives in Foundation so the kernel guards the call site with a named interface (not a concrete FQCN) while the Layer-3 migration `ServiceProvider` opts in via a downward dependency; the interface param is `list<object>` and the implementation filters to migration providers.

### ServiceProvider kernel-services bus

Service providers receive a typed bus of kernel-owned services through `Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface` (Contract A from mission #824 WP02). The interface declares a single method:

```php
public function get(string $abstract): ?object;
```

`KernelServicesInterface` replaces the legacy `\Closure(string): ?object` field that providers previously received via `setKernelResolver()`. The named contract makes the kernel surface explicit, restores PHPStan-visible types at the call boundary, and gives test doubles a stable seam.

**Wiring.** `ProviderRegistry` constructs one `ProviderRegistryKernelServices` instance per registration pass and hands it to every discovered provider via `ServiceProvider::setKernelServices()`. The default implementation resolves these abstracts:

| Abstract | Resolves to |
|----------|-------------|
| `Waaseyaa\Entity\EntityTypeManager` | The kernel’s entity-type manager |
| `Waaseyaa\Database\DatabaseInterface` | The kernel’s `DBALDatabase` |
| `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` | The kernel’s event dispatcher |
| `Waaseyaa\Foundation\Log\LoggerInterface` | The kernel’s logger |
| `\PDO` | The native PDO connection beneath `DBALDatabase` |
| anything else | The first sibling provider whose `getBindings()` declares the abstract, or `null` |

The provider list is read through a closure accessor so resolution sees the live registration state — necessary when a provider’s `register()` resolves a binding declared by a sibling registered earlier in the same pass.

**Resolution order in `ServiceProvider::resolve()`.** Local bindings (`singleton`/`bind`) win first; only when the abstract is unbound locally does the provider delegate to `KernelServicesInterface::get()`; if that returns `null`, the provider throws `RuntimeException("No binding registered for {$abstract}.")`.

**Propagation through `mergeChildProvider()`.** When a stack provider merges a child via `mergeChildProvider()`, the child receives the same `KernelServicesInterface` instance so `resolve()` keeps working inside the child’s `register()`.

### HTTP service resolver (SSR controller-method DI)

<!-- Spec reviewed 2026-05-07 - foundation-symfony-fallback-elimination-01KQZR1 WP03–WP04: resolver + routing exceptions + RouteBuilder/_controller normalization -->

`HttpKernel::getHttpServiceResolver()` returns `Waaseyaa\Foundation\Http\HttpServiceResolverInterface` (default impl `Waaseyaa\Foundation\Kernel\Http\HttpKernelServiceResolver`). SSR uses this seam to satisfy app-controller method dependencies — given a class name from a `\ReflectionNamedType` parameter, the resolver returns an instance or `null`. Replaces the legacy `\Closure(string): ?object` shape; mirrors the typed-resolver pattern introduced for `KernelServicesInterface` above. The default resolver walks provider bindings first; the narrow `DatabaseInterface` fallback delegates to the same `KernelServicesInterface` implementation used at bootstrap (`ProviderRegistryKernelServices`), not a second hard-coded map.

`HttpServiceResolverInterface` is intentionally separate from `KernelServicesInterface`: the latter is finite, kernel-internal, and provider-scoped, while this surface is open-ended and driven by user-authored controller signatures. Naming the seam allows future tightening (allowed-types enforcement, caching, tracing) without affecting the kernel-services bus.

**Routing miss surface.** `Waaseyaa\Routing\WaaseyaaRouter::match()` wraps Symfony `UrlMatcher` failures as `Waaseyaa\Routing\Exception\RouteNotFoundException` and `RouteMethodNotAllowedException` so `HttpKernel` handles 404/405 without importing `Symfony\Component\Routing\Exception\*` for expected misses.

**`_controller` shape.** Symfony `Route` defaults may use `[FQCN, method]`; `RouteBuilder::controller()` normalizes that to `FQCN::method`, and `HttpKernel` applies `RouteBuilder::normalizeControllerDefault()` when copying matcher parameters onto the request so `ControllerDispatcher` always sees a string or invokable for `_controller`.

### HTTP error surface (JSON-first)

Machine clients (Admin SPA, MCP, curl scripts) should assume **JSON:API-shaped errors** unless they explicitly negotiated HTML.

| Phase | Content-Type | When |
|-------|----------------|------|
| Boot failure (non-debug) | `application/vnd.api+json` | `HttpKernel::handle()` catch around `boot()` — `bootFailureJsonResponse()` uses `clientSafeBootFailureDetail()` so JSON `errors[].detail` names known cases (e.g. debug-in-production guard, missing production SQLite, PHPUnit on production autoload) without echoing filesystem paths; the matching `logger->critical` boot line still carries the full message and trace |
| Unhandled exception after successful boot | `application/vnd.api+json` | Outer `handle()` catch — generic 500 JSON:API body |
| Controller pipeline | JSON:API or negotiated Inertia/SSR | `ControllerDispatcher` and domain routers |

**Policy:** New HTTP surfaces must not introduce ad-hoc HTML error snippets for API-shaped routes. SSR and browser-document routes may return HTML via dedicated renderers. MCP stays on JSON-RPC as defined in `docs/specs/mcp-endpoint.md` — boot failures still pass through `HttpKernel` first, so MCP inherits the same boot behavior as other routes until the kernel is healthy.

### Testing fixture factories (`packages/testing/`)

`EntityFactory` remains the lightweight defaults/overrides + sequence helper for value arrays.
`EntityTypeFixtureValues` adds metadata-aware dummy generation for tests/seeds by reading
`EntityTypeValidationConstraints::forEntityType()` from `waaseyaa/entity`, so generated values
follow the same merged field-definition + manual constraint map used by `EntityRepository` save
validation. This is explicitly a fixture path (not production hydration via `EntityInstantiator`).

## Domain Events

### DomainEvent base class

File: `packages/foundation/src/Event/DomainEvent.php`

```php
namespace Waaseyaa\Foundation\Event;

abstract class DomainEvent extends Event
{
    public readonly string $eventId;          // UUIDv7, auto-generated
    public readonly \DateTimeImmutable $occurredAt;  // auto-set to now

    public function __construct(
        public readonly string $aggregateType,   // e.g., 'node', 'user', 'config'
        public readonly string $aggregateId,     // entity ID or config name
        public readonly ?string $tenantId = null,
        public readonly ?string $actorId = null,
    );

    abstract public function getPayload(): array;
}
```

All properties are `public readonly`. There are no getter methods.

### Event dispatch

Domain events use Symfony's `EventDispatcherInterface` directly. There is no custom EventBus wrapper. Service providers register listeners via `$dispatcher->addListener()` or `$dispatcher->addSubscriber()`.

Real-time SSE delivery to the admin SPA is handled by the durable-log path: `EventListenerRegistrar::registerBroadcastListeners` subscribes to entity `post_save`/`post_delete` and writes rows into `BroadcastStorage` (DB-backed). `BroadcastRouter` polls that store for the `/broadcast` endpoint. See `docs/specs/broadcasting.md` for the full contract.

### Best-effort side effects

Event listeners for non-critical operations (broadcasting, logging, cache invalidation) must wrap in try-catch and log via `LoggerInterface` to avoid crashing the primary request. The project does not use `psr/log`; use `Waaseyaa\Foundation\Log\LoggerInterface` with `NullLogger` as the default fallback. Reserve `error_log()` only for last-resort fallbacks inside the logging infrastructure itself.

## Cache System

### CacheBackendInterface

File: `packages/cache/src/CacheBackendInterface.php`

```php
namespace Waaseyaa\Cache;

interface CacheBackendInterface
{
    public const PERMANENT = -1;

    public function get(string $cid): CacheItem|false;
    public function getMultiple(array &$cids): array;   // pass-by-reference; $cids narrowed to misses
    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void;
    public function delete(string $cid): void;
    public function deleteMultiple(array $cids): void;
    public function deleteAll(): void;
    public function invalidate(string $cid): void;       // marks invalid but does not delete
    public function invalidateMultiple(array $cids): void;
    public function invalidateAll(): void;
    public function removeBin(): void;                   // drops the entire bin
}
```

### CacheItem

File: `packages/cache/src/CacheItem.php`

```php
final readonly class CacheItem
{
    public function __construct(
        public string $cid,
        public mixed $data,
        public int $created,
        public int $expire = CacheBackendInterface::PERMANENT,
        public array $tags = [],
        public bool $valid = true,
    ) {}
}
```

### TagAwareCacheInterface

File: `packages/cache/src/TagAwareCacheInterface.php`

Extends `CacheBackendInterface` with:

```php
interface TagAwareCacheInterface extends CacheBackendInterface
{
    /** @param string[] $tags */
    public function invalidateByTags(array $tags): void;
}
```

### Backend implementations

| Backend | File | Tag-aware | Notes |
|---------|------|-----------|-------|
| `MemoryBackend` | `packages/cache/src/Backend/MemoryBackend.php` | Yes | In-memory array; use for tests. Implements `TagAwareCacheInterface`. |
| `DatabaseBackend` | `packages/cache/src/Backend/DatabaseBackend.php` | Yes | PDO-backed; auto-creates table on first use. `INSERT OR REPLACE`. Tags stored comma-separated. |
| `NullBackend` | `packages/cache/src/Backend/NullBackend.php` | No | All gets return false; all writes are no-ops. Use for disabled bins. |

### CacheFactory and CacheConfiguration

File: `packages/cache/src/CacheFactory.php`, `packages/cache/src/CacheConfiguration.php`

```php
interface CacheFactoryInterface
{
    public function get(string $bin): CacheBackendInterface;
}
```

`CacheFactory` creates backends per bin. `CacheConfiguration` maps bin names to backend classes or factory callables. Factory callables take precedence over class names for backends that need constructor arguments (e.g., DatabaseBackend needs a `\PDO`).

```php
$config = new CacheConfiguration(
    defaultBackend: MemoryBackend::class,
    binFactories: [
        'cache_entity' => fn() => new DatabaseBackend($pdo, 'cache_entity'),
    ],
);
$factory = new CacheFactory($config);
$cache = $factory->get('cache_entity');  // returns DatabaseBackend
$cache = $factory->get('cache_other');   // returns MemoryBackend
```

### Tag invalidation

File: `packages/cache/src/CacheTagsInvalidator.php`

`CacheTagsInvalidator` holds references to all registered cache bins and delegates `invalidateTags()` to those that implement `TagAwareCacheInterface`.

### Cache event listeners

| Listener | File | Listens to | Tags invalidated |
|----------|------|-----------|------------------|
| `EntityCacheInvalidator` | `packages/cache/src/Listener/EntityCacheInvalidator.php` | `EntityEvent` (post-save, post-delete) | `entity:{type}`, `entity:{type}:{id}` |
| `ConfigCacheInvalidator` | `packages/cache/src/Listener/ConfigCacheInvalidator.php` | `ConfigEvent` (post-save, post-delete) | `config`, `config:{name}` |
| `TranslationCacheInvalidator` | `packages/cache/src/Listener/TranslationCacheInvalidator.php` | Translation events | Translation-specific tags |

### Cache initialization timing in HttpKernel

Cache setup follows a two-stage lifecycle:

1. **Boot phase** (`AbstractKernel::boot()`): Core services are initialized (database, config, entity type manager, dispatcher, access handler). No cache bins or cache-related objects are created yet.

2. **Handle phase** (`HttpKernel::handle()`, after `boot()` returns):
   - `CacheConfigResolver` is instantiated with the loaded config array.
   - `CacheConfiguration` is created and bin factories are registered for `render`, `discovery`, and `mcp_read` bins (all database-backed).
   - `CacheFactory` creates the three cache backends.
   - `RenderCache` wraps the render backend; `discoveryCache` and `mcpReadCache` are stored as `CacheBackendInterface` references.
   - `EventListenerRegistrar` registers invalidation listeners in this order:
     1. `registerRenderCacheListeners(renderCache)`
     2. `registerDiscoveryCacheListeners(discoveryCache)`
     3. `registerMcpReadCacheListeners(mcpReadCache)`
   - All three listener methods subscribe to `EntityEvents::POST_SAVE->value` and `EntityEvents::POST_DELETE->value` (the string-backed enum values from `Waaseyaa\Entity\Event\EntityEvents`, e.g. `'waaseyaa.entity.post_save'`).

This means `CacheConfigResolver` is **not** available during boot — it requires the config array which is populated by boot, and is only needed by the SSR page handler created later in `handle()`.

### Atomic file writes pattern

Cache files and compiled artifacts must use write-to-temp-then-rename to prevent serving partial writes:

```php
$tmpPath = $cachePath . '.tmp.' . getmypid();
file_put_contents($tmpPath, $content);
rename($tmpPath, $cachePath);
```

This pattern is used in `PackageManifestCompiler::compileAndCache()` and must be used anywhere the cache system writes PHP files to disk.

Attribute instances built via `ReflectionAttribute::newInstance()` — used throughout `PackageManifestCompiler` for `AsFormatter`, `AsMiddleware`, `AsEntityType`, etc. — reflect their constructor declarations verbatim. Required typed properties are guaranteed initialized, so `isset()` / `??` guards on them are dead code; PHPStan flags them as `isset.property` / `nullCoalesce.property`. Gate on `!== ''` (or an explicit nullability check against the declared type) instead.

## Database Layer

### DatabaseInterface

File: `packages/database-legacy/src/DatabaseInterface.php`

```php
namespace Waaseyaa\Database;

interface DatabaseInterface
{
    public function select(string $table, string $alias = ''): SelectInterface;
    public function insert(string $table): InsertInterface;
    public function update(string $table): UpdateInterface;
    public function delete(string $table): DeleteInterface;
    public function schema(): SchemaInterface;
    public function transaction(string $name = ''): TransactionInterface;
    public function query(string $sql, array $args = []): \Traversable;
}
```

**CRITICAL**: `DatabaseInterface` does NOT have `getConnection()`. If the DBAL `Connection` is needed, type-hint `DBALDatabase` directly. Prefer using the query builder (`select()`, `insert()`, `update()`, `delete()`) over raw DBAL.

### DBALDatabase

File: `packages/database-legacy/src/DBALDatabase.php`

```php
final class DBALDatabase implements DatabaseInterface
{
    public function __construct(private readonly Connection $connection);
    public static function createSqlite(string $path = ':memory:'): self;
    public function getConnection(): Connection;   // ONLY on DBALDatabase, NOT on DatabaseInterface
}
```

`DBALDatabase` wraps a Doctrine DBAL `Connection`. The `createSqlite()` factory enables WAL mode for non-memory databases. Query results use `fetchAssociative()` (equivalent to FETCH_ASSOC — no duplicate numeric-indexed columns).

### TransactionInterface

File: `packages/database-legacy/src/TransactionInterface.php`

```php
interface TransactionInterface
{
    public function commit(): void;
    public function rollBack(): void;
}
```

`DBALTransaction` begins the transaction in its constructor. Calling `commit()` or `rollBack()` after the transaction is no longer active throws `\RuntimeException`.

## Query Builder

### SelectInterface

File: `packages/database-legacy/src/SelectInterface.php`

```php
interface SelectInterface
{
    public function fields(string $tableAlias, array $fields = []): static;
    public function addField(string $tableAlias, string $field, string $alias = ''): static;
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function isNull(string $field): static;
    public function isNotNull(string $field): static;
    public function orderBy(string $field, string $direction = 'ASC'): static;
    public function range(int $offset, int $limit): static;
    public function join(string $table, string $alias, string $condition): static;
    public function leftJoin(string $table, string $alias, string $condition): static;
    public function countQuery(): static;  // clones + wraps in COUNT(*)
    public function execute(): \Traversable;
}
```

### DBALSelect condition operators

File: `packages/database-legacy/src/Query/DBALSelect.php`

Supported operators in `condition()`:
- `=`, `!=`, `<`, `>`, `<=`, `>=` -- standard comparison, single `?` placeholder
- `IN`, `NOT IN` -- value must be array, generates `(?, ?, ...)` placeholders
- `BETWEEN` -- value must be array of exactly 2
- `LIKE`, `NOT LIKE` -- appends `ESCAPE '\'` automatically
- `IS NULL`, `IS NOT NULL` -- use `isNull()`/`isNotNull()` methods instead

**LIKE wildcard escaping**: When building LIKE patterns in application code (e.g., `SqlEntityQuery`), escape `%` and `_` in user input:
```php
$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $userInput);
$query->condition('title', '%' . $escaped . '%', 'LIKE');
```

All conditions are ANDed together. No OR support at this level.

## Discovery Response Caching (v1.0)

The HTTP kernel now maintains a dedicated `discovery` cache bin (database-backed, table `cache_discovery`) for anonymous public discovery API surfaces:

- `/api/discovery/hub/{entity_type}/{id}`
- `/api/discovery/cluster/{entity_type}/{id}`
- `/api/discovery/timeline/{entity_type}/{id}`
- `/api/discovery/endpoint/{entity_type}/{id}`

Cache key contract:

- Stable hash of `{surface, entity_type, entity_id, options}`.
- `options` are recursively normalized with deterministic associative-key sorting.
- Key dimensions include relationship filters, direction, temporal filters (`at/from/to`), pagination (`limit/offset`), and status mode.
- Shared primitive: `Waaseyaa\Foundation\Cache\DiscoveryCachePrimitives`.

Runtime behavior:

- Anonymous requests: cache read-through with `Cache-Control: public, max-age=120`.
- Cache hit header: `X-Waaseyaa-Discovery-Cache: HIT`.
- Cache miss header: `X-Waaseyaa-Discovery-Cache: MISS`.
- Authenticated requests bypass persistence and return `Cache-Control: private, no-store`.
- Discovery payloads carry a stable metadata envelope:
  - `meta.contract_version = v1.0`
  - `meta.contract_stability = stable`
  - `meta.surface = discovery_api` (default when not supplied by the caller)

Invalidation:

- Preferred path (tag-aware backends): targeted `invalidateByTags()` on save/delete using tags such as:
  - `discovery`
  - `discovery:entity:{type}`
  - `discovery:entity:{type}:{id}`
  - related-entity tags extracted from discovery payload edges/clusters/browse surfaces
  - plus broad discovery-surface tags for relationship/node graph-impact changes
- Fallback path (non tag-aware backends): `deleteAll()` for correctness.

## MCP Read-Path Caching (v1.1)

The HTTP kernel maintains a dedicated MCP read cache bin (database-backed, table `cache_mcp_read`) for read-heavy tool calls served by `Waaseyaa\Mcp\McpController`:

- `search_entities` / `search_teachings`
- `ai_discover`
- `traverse_relationships`
- `get_related_entities`
- `get_knowledge_graph`

Cache key contract:

- Stable hash of `{contract_version, tool, arguments, account_context}`.
- `arguments` are recursively normalized with deterministic associative-key sorting.
- `account_context` includes:
  - `authenticated` flag
  - account ID
  - sorted role list

This prevents cross-account and anonymous/authenticated cache leakage while preserving deterministic replay for identical callers and inputs.

Runtime behavior:

- Tool result payloads are cached with 120-second TTL.
- Payload contract remains unchanged (`meta.contract_version`, `meta.contract_stability`, tool metadata).
- Cache writes include tags:
  - `mcp_read`
  - `mcp_read:contract:v1.0`
  - `mcp_read:tool:{tool}`
  - entity tags extracted from arguments/payload (`mcp_read:entity:{type}` and `mcp_read:entity:{type}:{id}`).

Invalidation:

- Preferred path (tag-aware backends): targeted `invalidateByTags()` on entity save/delete:
  - `mcp_read`
  - `mcp_read:entity:{type}`
  - `mcp_read:entity:{type}:{id}`
- Fallback path (non tag-aware backends): `deleteAll()`.

## SSR Render Cache Variant Contract (v1.1)

SSR render cache keys include a deterministic variant suffix built from:

- language (`langcode`)
- view mode (`view_mode`)
- preview/public mode (`preview`)
- workflow state (`workflow_visibility.state`)
- graph-context hash (normalized `relationship_navigation`)
- contract version

The variant payload is normalized and hashed, then emitted with a readable prefix:

- `v2:{langcode}:{view_mode}:{public|preview}:{workflow_state}:{hash}`

This hardens cache partitioning and prevents future cache-key ambiguity while preserving deterministic replay under equivalent context inputs.

Security boundary:

- preview requests and public requests resolve to distinct variant keys,
- preview render paths are not persisted to shared public cache storage,
- public cache reads/writes remain restricted to unauthenticated, non-preview requests.

Render cache invalidation is broadened for relationship-aware pages:

- entity-specific invalidation still occurs on save/delete,
- when `node` or `relationship` entities change, type-wide node/relationship render tags are invalidated to prevent stale relationship-navigation output.

## Public SSR CDN Strategy (v1.4)

Public SSR routes now expose deterministic HTTP cache profiles aligned with workflow and graph-context invariants:

- `Cache-Control` for anonymous/public SSR responses:
  - `public, max-age={cache_max_age}, s-maxage={cache_shared_max_age}, stale-while-revalidate={cache_stale_while_revalidate}, stale-if-error={cache_stale_if_error}`
- Authenticated SSR responses remain private:
  - `private, no-store`

Default values when no explicit config is provided:

- `cache_max_age`: `300`
- `cache_shared_max_age`: fallback to `cache_max_age`
- `cache_stale_while_revalidate`: `60`
- `cache_stale_if_error`: `600`

### Surrogate-key contract

Public SSR entity responses also emit CDN-oriented surrogate keys:

- `Surrogate-Key` includes:
  - `waaseyaa:ssr`
  - entity scope: `waaseyaa:ssr:entity:{type}` and `waaseyaa:ssr:entity:{type}:{id}`
  - workflow scope: `waaseyaa:ssr:workflow:{workflow_state}`
  - view/lang scope: `waaseyaa:ssr:view:{view_mode}`, `waaseyaa:ssr:lang:{langcode}`
  - graph scope: `waaseyaa:ssr:graph:{graph_hash}`
- Debug/trace headers:
  - `X-Waaseyaa-Render-Variant`
  - `X-Waaseyaa-Render-Workflow`

### Invalidation behavior

SSR cache invalidation remains workflow/graph-aware and deterministic:

- save/delete of the rendered entity invalidates its entity-specific SSR cache entries,
- save/delete of `node` and `relationship` entities triggers broader invalidation for relationship-aware public surfaces,
- emitted surrogate keys are aligned with these invariants so CDN purge tooling can target entity/workflow/graph scopes without contract drift.

### InsertInterface

File: `packages/database-legacy/src/InsertInterface.php`

```php
interface InsertInterface
{
    public function fields(array $fields): static;      // column names
    public function values(array $values): static;      // can be called multiple times for batch
    public function execute(): int|string;              // returns lastInsertId
}
```

If `fields()` is not called, field names are inferred from the first `values()` call's array keys. Indexed arrays require prior `fields()` call.

### UpdateInterface

File: `packages/database-legacy/src/UpdateInterface.php`

```php
interface UpdateInterface
{
    public function fields(array $fields): static;      // ['column' => value]
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function execute(): int;                     // returns affected row count
}
```

### DeleteInterface

File: `packages/database-legacy/src/DeleteInterface.php`

```php
interface DeleteInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function execute(): int;                     // returns affected row count
}
```

### Usage examples

```php
// Select with join
$results = $db->select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->leftJoin('node_field_data', 'nfd', 'n.nid = nfd.nid')
    ->condition('n.type', 'article')
    ->orderBy('n.created', 'DESC')
    ->range(0, 10)
    ->execute();

// Insert
$db->insert('users')
    ->values(['uid' => 1, 'name' => 'admin', 'mail' => 'admin@example.com'])
    ->execute();

// Update
$affected = $db->update('users')
    ->fields(['name' => 'superadmin'])
    ->condition('uid', 1)
    ->execute();

// Delete
$affected = $db->delete('sessions')
    ->condition('expire', time(), '<')
    ->execute();

// Transaction
$txn = $db->transaction();
try {
    $db->insert('audit_log')->values([...])->execute();
    $txn->commit();
} catch (\Throwable $e) {
    $txn->rollBack();
    throw $e;
}
```

## Schema System

### SchemaInterface (database DDL)

File: `packages/database-legacy/src/SchemaInterface.php`

```php
interface SchemaInterface
{
    public function tableExists(string $table): bool;
    public function fieldExists(string $table, string $field): bool;
    public function createTable(string $name, array $spec): void;
    public function dropTable(string $table): void;
    public function addField(string $table, string $field, array $spec): void;
    public function dropField(string $table, string $field): void;
    public function addIndex(string $table, string $name, array $fields): void;
    public function dropIndex(string $table, string $name): void;
    public function addUniqueKey(string $table, string $name, array $fields): void;
    public function addPrimaryKey(string $table, array $fields): void;
}
```

`DBALSchema` uses Doctrine DBAL's schema introspection and DDL generation. Type mapping: `serial` -> INTEGER AUTOINCREMENT, `varchar` -> TEXT, `int`/`integer` -> INTEGER, `text` -> TEXT, `float`/`numeric`/`decimal` -> REAL, `blob` -> BLOB.

Note: SQLite cannot add a primary key to an existing table. `addPrimaryKey()` throws `\RuntimeException`.

**Distinction from SchemaPresenter**: `SchemaInterface` is a database DDL abstraction in `packages/database-legacy/` for creating/altering tables. It is unrelated to `SchemaPresenter` (`packages/api/src/Schema/SchemaPresenter.php`), which generates JSON Schema output from entity field definitions for the API layer. `SchemaPresenter` works with `EntityType::getFieldDefinitions()` and does not use `SchemaInterface`.

### SchemaRegistryInterface (ingestion payload schemas)

File: `packages/foundation/src/Schema/SchemaRegistryInterface.php`

```php
interface SchemaRegistryInterface
{
    /** @return list<SchemaEntry> Schemas sorted by entity type ID */
    public function list(): array;

    public function get(string $id): ?SchemaEntry;
}
```

Registry of JSON Schema definitions used to validate ingestion payloads. `DefaultsSchemaRegistry` loads schemas from the `defaults/` directory and caches them on first access. Consumers use this interface when they need to look up or enumerate available payload schemas — for example, the `SchemaListCommand` CLI command and `PayloadValidator`.

**Note:** This is the ingestion schema registry, not the database DDL schema system above. See `docs/specs/ingestion-defaults.md` for ingestion contract details.

## Migration System

The migration system uses Doctrine DBAL (same as the database layer). It lives in `packages/foundation/src/Migration/`.

### Migration base class

File: `packages/foundation/src/Migration/Migration.php`

```php
abstract class Migration
{
    public array $after = [];  // package names this migration must run after

    abstract public function up(SchemaBuilder $schema): void;
    public function down(SchemaBuilder $schema): void {}  // optional rollback
}
```

### SchemaBuilder

File: `packages/foundation/src/Migration/SchemaBuilder.php`

Uses Doctrine DBAL `Connection` + `Schema`. Creates tables via `TableBuilder` closure pattern:

```php
$schema->create('nodes', function (TableBuilder $table) {
    $table->id();                           // string('id', 128)
    $table->string('type', 64);
    $table->text('title');
    $table->json('_data')->nullable();
    $table->timestamps();                   // created + changed timestamps
    $table->primary(['id']);
    $table->index(['type']);
});
```

Other `SchemaBuilder` methods: `drop()`, `dropIfExists()`, `hasTable()`, `hasColumn()`.

### TableBuilder column types

File: `packages/foundation/src/Migration/TableBuilder.php`

| Method | Column Type | Doctrine Type |
|--------|------------|---------------|
| `id(name)` | `string(name, 128)` | STRING |
| `string(name, length)` | varchar | STRING |
| `text(name)` | text | TEXT |
| `integer(name)` | integer | INTEGER |
| `boolean(name)` | boolean | BOOLEAN |
| `float(name)` | float | FLOAT |
| `json(name)` | json | JSON |
| `timestamp(name)` | datetime_immutable | DATETIME_IMMUTABLE |

Convenience methods: `timestamps()` (creates `created` + `changed`), `entityBase()` (id + entity_type + bundle + _data + timestamps), `translationColumns()` (langcode + default_langcode + translation_source), `revisionColumns()` (revision_id + revision_created + revision_log).

For multi-bundle entity types with bundle-scoped fields, `SqlSchemaHandler` additionally provisions `{base_table}__{bundle}` subtables (1:1 with the base, FK `ON DELETE CASCADE`). `SqlEntityStorage` partitions values by `FieldDefinition::$targetBundle` on save and two-query-loads (base row first, subtable by PK). `SqlEntityQuery` injects INNER JOINs for bundle-scoped conditions. See [`bundle-scoped-storage.md`](./bundle-scoped-storage.md) for the full contract.

### ColumnDefinition

File: `packages/foundation/src/Migration/ColumnDefinition.php`

Fluent modifiers: `->nullable()`, `->default(value)`, `->unique()`.

### Migrator

File: `packages/foundation/src/Migration/Migrator.php`

```php
final class Migrator
{
    public function __construct(Connection $connection, MigrationRepository $repository);

    /** @param array<string, array<string, Migration>> $migrations  package => [name => Migration] */
    public function run(array $migrations): MigrationResult;
    public function rollback(array $migrations): MigrationResult;
    public function status(array $migrations): array;  // ['pending' => [...], 'completed' => [...]]
}
```

Migrations are topologically sorted by `Migration::$after` dependencies. Each batch gets an incrementing batch number. Rollback undoes the last batch in reverse order.

### MigrationRepository

File: `packages/foundation/src/Migration/MigrationRepository.php`

Tracks executed migrations in the `waaseyaa_migrations` table:
- `id` INTEGER PRIMARY KEY AUTOINCREMENT
- `migration` VARCHAR(255) -- migration name
- `package` VARCHAR(128) -- originating package
- `batch` INTEGER -- batch number
- `ran_at` TIMESTAMP

### MigrationResult

File: `packages/foundation/src/Migration/MigrationResult.php`

```php
final readonly class MigrationResult
{
    public function __construct(
        public int $count,
        public array $migrations = [],
    ) {}
}
```

### Package-declared migrations (`extra.waaseyaa.migrations`)

Packages ship SQL DDL migrations under a **single directory** registered in `composer.json`:

```json
"extra": {
    "waaseyaa": {
        "migrations": "migrations"
    }
}
```

`PackageManifestCompiler` copies `packageName => relativePath` into `PackageManifest::$migrations`. `MigrationLoader` resolves the directory (vendor install path or monorepo path), loads every `*.php` file in **lexicographic filename order**, and requires each file to return a `Migration` instance. Migration identity in the ledger is `"{package}:{filename_without_extension}"` (e.g. `waaseyaa/oidc:2026_04_26_000001_oidc_client_schema`).

**Ordering:** Prefer numeric or ISO-date prefixes in filenames so `glob` + `sort` order matches intended apply order. Use `Migration::$after` with **Composer package names** when one package’s migrations must run after another’s (see `Migrator::topologicalSort()`).

#### v2 array form (mission #529 / WP11)

Per spec §15 Q9, `extra.waaseyaa.migrations` also accepts an **ordered list** of mixed FQCN namespace prefixes and path strings, enabling packages that ship v2 migrations (`MigrationInterfaceV2`) alongside (or instead of) legacy directory-based ones:

```json
"extra": {
    "waaseyaa": {
        "migrations": [
            "Vendor\\Pkg\\Migrations\\v2",
            "../patches/v2"
        ]
    }
}
```

`PackageManifestCompiler::validateMigrationsEntry()` rejects malformed entries (associative arrays, non-string elements, non-string-or-array top-level values) with stable code `INVALID_MIGRATION_ENTRY` (`InvalidMigrationEntryException`). `MigrationLoader` exposes two methods that walk the same manifest in order: `loadAll()` resolves path entries to legacy `Migration` instances; `loadAllV2()` resolves FQCN entries via Composer's classmap to `MigrationInterfaceV2` instances. The string form (`"migrations": "migrations"`) is preserved indefinitely (Q9 — no removal date) and is internally normalised to a single-element list.

**Entry classification heuristic (v1):** entries containing a backslash are FQCN namespace prefixes; everything else is a path string. Full discovery rules — including the no-match warning, classmap optimization requirement, and Windows-path caveat — live in `docs/adr/009-migration-manifest-discovery.md`.

**v2 ordering:** within a package, list entries traverse left-to-right. Across packages, the unified migration DAG (mission #529 / WP06) reorders nodes by their declared `dependencies()`; raw discovery order is only the input. Within an FQCN entry, classmap iteration order is implementation-defined — v2 plans should not depend on it. Use explicit `MigrationInterfaceV2::dependencies()` for cross-migration ordering.

**Entity tables:** Kernel `SqlSchemaHandler::ensureTable()` creates base columns (`id`, `uuid`, `bundle`, label/langcode keys, `_data`, …) when storage is first resolved. **Additive** columns (field-backed lookups, indexes) belong in package migrations so they run on **`db:init`** / `migrate` paths that do not eagerly touch every entity type. Do **not** add recurring `SqlSchemaHandler::addFieldColumns()` calls in `ServiceProvider::boot()` for the same DDL — that duplicates schema truth and runs every request.

**SQLite / `down()`:** Additive column migrations may use a no-op `down()` when portable `DROP COLUMN` is not guaranteed; prefer compensating migrations for breaking changes.

**Reference packages:** `waaseyaa/queue`, `waaseyaa/notification`, `waaseyaa/scheduler`, `waaseyaa/ai-observability` register `migrations`; `waaseyaa/oidc` registers `migrations/2026_04_26_000001_oidc_client_schema.php` for the `oidc_client` table and lookup columns (#1286).

## HTTP Client

Minimal HTTP client with no external dependencies (uses PHP streams). Zero composer dependencies — requires only `php: >=8.4`.

### HttpClientInterface

File: `packages/http-client/src/HttpClientInterface.php`

```php
interface HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse;
    public function get(string $url, array $headers = []): HttpResponse;
    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse;
}
```

### HttpResponse

File: `packages/http-client/src/HttpResponse.php`

```php
final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    );

    public function json(): array;      // json_decode with JSON_THROW_ON_ERROR
    public function isSuccess(): bool;  // 200-299
}
```

### StreamHttpClient

File: `packages/http-client/src/StreamHttpClient.php`

Implementation using `file_get_contents()` with stream contexts. Throws `HttpRequestException` on failure. Response headers are read via `http_get_last_response_headers()` (PHP 8.5+); the legacy magic predefined `$http_response_header` was deprecated in 8.5.

### HttpRequestException

File: `packages/http-client/src/HttpRequestException.php`

```php
final class HttpRequestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $url,
        public readonly string $method,
        public readonly ?HttpResponse $response = null,
        ?\Throwable $previous = null,
    );
}
```

Carries the failed request's URL, method, and optionally the response (when the server responded but with an error status). This allows callers to inspect both transport failures and HTTP error responses uniformly.

## Logging

Waaseyaa provides its own logging interfaces (not `psr/log`). All loggers implement `Waaseyaa\Foundation\Log\LoggerInterface`.

### LoggerInterface

File: `packages/foundation/src/Log/LoggerInterface.php`

```php
interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void;
}
```

### LogLevel

File: `packages/foundation/src/Log/LogLevel.php`

String-backed enum: `EMERGENCY`, `ALERT`, `CRITICAL`, `ERROR`, `WARNING`, `NOTICE`, `INFO`, `DEBUG`.

### LogRecord

File: `packages/foundation/src/Log/LogRecord.php`

Immutable value object carrying a single log entry: `level` (LogLevel), `message` (string), `context` (array), `channel` (string, defaults to `'default'`), `timestamp` (DateTimeImmutable, defaults to now).

### LogManager

File: `packages/foundation/src/Log/LogManager.php`

Central log orchestrator. Implements `LoggerInterface` — calling `log()` delegates to the default channel. Constructor accepts `LoggerInterface|HandlerInterface` for the default handler (legacy loggers are wrapped in `LegacyLoggerHandler`). `channel(string $name)` returns a `ChannelLogger` for the named channel; unknown channels fall back to the default. `fromConfig(array $config)` static factory builds channels from config (two-pass: non-stack handlers first, then stack handlers that reference other channels). `addGlobalProcessor(ProcessorInterface $processor)` allows runtime registration of processors (used by `HttpKernel` to add `RequestContextProcessor` after request resolution).

The kernel constructs `LogManager(new Handler\ErrorLogHandler())` at startup, then upgrades it after config loads: if `config['logging']['channels']` exists, uses `LogManager::fromConfig()`; otherwise falls back to `log_level` config with a single `Handler\ErrorLogHandler(minimumLevel: $level)`.

### ChannelLogger

File: `packages/foundation/src/Log/ChannelLogger.php`

Scoped `LoggerInterface` that stamps a channel name on every `LogRecord`, runs processors (global + per-channel), then delegates to a `HandlerInterface`. Created by `LogManager::channel()`. Constructor: `(string $channel, HandlerInterface $handler, array $processors = [])`. Processor failures are best-effort: caught, logged via `error_log()`, pipeline continues.

### Handler pipeline

| Interface/Class | File | Purpose |
|-------|------|---------|
| `HandlerInterface` | `Log/Handler/HandlerInterface.php` | Contract: `handle(LogRecord $record): void` |
| `ErrorLogHandler` | `Log/Handler/ErrorLogHandler.php` | Delegates to `error_log()`. Constructor: `(?FormatterInterface $formatter = null, LogLevel $minimumLevel = LogLevel::DEBUG, ?\Closure $writer = null)`. Discards messages below `minimumLevel`. |
| `FileHandler` | `Log/Handler/FileHandler.php` | Appends formatted record to a file with `LOCK_EX`. Constructor: `(string $path, ?FormatterInterface $formatter = null, LogLevel $minimumLevel = LogLevel::DEBUG)`. |
| `StackHandler` | `Log/Handler/StackHandler.php` | Fan-out to multiple handlers. Constructor: `(HandlerInterface ...$handlers)`. Best-effort: catches `\Throwable` per handler so one failure doesn't stop others. |
| `NullHandler` | `Log/Handler/NullHandler.php` | Discards all records — for testing and disabled logging. |
| `StreamHandler` | `Log/Handler/StreamHandler.php` | Writes to `php://stderr` or any stream resource. Constructor validates resource type; throws `\InvalidArgumentException` on non-resource. |
| `LegacyLoggerHandler` | `Log/LegacyLoggerHandler.php` | Adapts Phase A `LoggerInterface` implementations to `HandlerInterface`. Internal, used by `LogManager` for backward compatibility. |

### Formatter pipeline

| Interface/Class | File | Purpose |
|-------|------|---------|
| `FormatterInterface` | `Log/Formatter/FormatterInterface.php` | Contract: `format(LogRecord $record): string` |
| `TextFormatter` | `Log/Formatter/TextFormatter.php` | Format: `[timestamp] [level] [channel] message {context}`. Omits context braces when empty. |
| `JsonFormatter` | `Log/Formatter/JsonFormatter.php` | One JSON object per line with all fields: timestamp, level, channel, message, context. |

### Processor pipeline

Processors enrich `LogRecord` context before handlers receive the record. Execution order: global processors first, then per-channel processors.

| Interface/Class | File | Purpose |
|-------|------|---------|
| `ProcessorInterface` | `Log/Processor/ProcessorInterface.php` | Contract: `process(LogRecord $record): LogRecord`. Must return a new record, not mutate input. |
| `RequestIdProcessor` | `Log/Processor/RequestIdProcessor.php` | Adds `request_id` (UUID hex) to context. Same ID for all records within a single processor instance. |
| `HostnameProcessor` | `Log/Processor/HostnameProcessor.php` | Adds `hostname` to context. Defaults to `gethostname()`. |
| `MemoryUsageProcessor` | `Log/Processor/MemoryUsageProcessor.php` | Adds `memory_peak_mb` (float) to context. |
| `RequestContextProcessor` | `Log/Processor/RequestContextProcessor.php` | Adds `http_method`, `uri`, and optional `request_id` to context. Registered by `HttpKernel` during request handling. |

### Legacy logger implementations

| Class | File | Purpose |
|-------|------|---------|
| `NullLogger` | `Log/NullLogger.php` | No-op — for testing and disabled logging. Widely used across packages. |

`LoggerTrait` provides convenience methods (`emergency()`, `error()`, etc.) that delegate to `log()`.

Removed in Phase C: `FileLogger`, `CompositeLogger`, legacy `ErrorLogHandler` (at `Log/ErrorLogHandler.php`). Use `Handler\ErrorLogHandler`, `Handler\FileHandler`, `Handler\StackHandler` instead.

## Rate Limiting

### RateLimiterInterface

File: `packages/foundation/src/RateLimit/RateLimiterInterface.php`

```php
interface RateLimiterInterface
{
    /** @return array{allowed: bool, remaining: int, retryAfter: ?int} */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array;
}
```

Single method: `attempt(key, maxAttempts, windowSeconds)` returns a result array with `allowed` (bool), `remaining` (int), and `retryAfter` (?int seconds). Consumers use this interface when they need to enforce per-key rate limits — e.g. `RateLimitMiddleware` wraps HTTP endpoints, and auth controllers use it for login attempt throttling. Inject `RateLimiterInterface`; the default binding is `InMemoryRateLimiter`.

### InMemoryRateLimiter

File: `packages/foundation/src/RateLimit/InMemoryRateLimiter.php`

Sliding-window rate limiter stored in memory. Resets per-process. Used by `RateLimitMiddleware`.

## Asset Management

### AssetManagerInterface

File: `packages/foundation/src/Asset/AssetManagerInterface.php`

```php
interface AssetManagerInterface
{
    public function url(string $path, string $bundle = 'admin'): string;
}
```

Resolves logical asset paths to hashed, cache-busted URLs. Consumers use this interface when generating `<script>` or `<link>` tags for frontend bundles — primarily SSR and the admin SPA host. Inject `AssetManagerInterface`; the default binding is `ViteAssetManager`.

### ViteAssetManager

File: `packages/foundation/src/Asset/ViteAssetManager.php`
Implements: `AssetManagerInterface`

```php
final class ViteAssetManager implements AssetManagerInterface
{
    public function __construct(
        private readonly string $basePath,              // dist directory path
        private readonly string $baseUrl = '/dist',
        private readonly ?string $devServerUrl = null,  // e.g., 'http://localhost:5173'
    );

    public function url(string $path, string $bundle = 'admin'): string;
    public function preloadLinks(string $bundle = 'admin'): array;
    public function assetTags(string $bundle = 'build', string $entrypoint = 'resources/js/app.ts'): string;
}
```

Reads Vite `manifest.json` files to resolve source paths to hashed asset URLs. Manifests are cached per bundle.

`assetTags()` generates HTML `<script>` and `<link>` tags for a bundle's entry assets. In production (manifest exists), it emits hashed asset tags. In dev mode (no manifest, `devServerUrl` set), it emits Vite dev server HMR tags. All attribute values are escaped via `htmlspecialchars()`. Returns empty string when neither manifest nor dev server is available.

`TenantAssetResolver` (`packages/foundation/src/Asset/TenantAssetResolver.php`) resolves tenant-specific asset paths.

## Sovereignty Configuration

File: `packages/foundation/src/Sovereignty/SovereigntyConfig.php`

Provides deployment-mode defaults so applications can declare a sovereignty profile (`local`, `self_hosted`, `northops`) and get sane defaults for storage, embeddings, LLM provider, transcriber, vector store, and queue backend.

### SovereigntyProfile

File: `packages/foundation/src/Sovereignty/SovereigntyProfile.php`

```php
enum SovereigntyProfile: string
{
    case Local = 'local';
    case SelfHosted = 'self_hosted';
    case NorthOps = 'northops';
}
```

### SovereigntyDefaults

File: `packages/foundation/src/Sovereignty/SovereigntyDefaults.php`

Maps each profile to its default settings:

| Setting | `local` | `self_hosted` | `northops` |
|---|---|---|---|
| storage | filesystem | filesystem | s3 |
| embeddings | sqlite | sqlite | pgvector |
| llm_provider | ollama | ollama | api |
| transcriber | whisper_ollama | whisper_ollama | api |
| vector_store | sqlite | sqlite | pgvector |
| queue_backend | sync | database | redis |

### SovereigntyConfigInterface / SovereigntyConfig

File: `packages/foundation/src/Sovereignty/SovereigntyConfigInterface.php`

```php
interface SovereigntyConfigInterface
{
    public function get(string $key): ?string;
    public function getProfile(): SovereigntyProfile;
    /** @return array<string, string> */
    public function all(): array;
}
```

`SovereigntyConfig` resolves effective settings: profile defaults merged with per-key overrides from app config. `SovereigntyConfig::fromArray($appConfig)` reads `sovereignty_profile` from the config array (defaults to `local`) and extracts recognized override keys.

Registered as a singleton in `FoundationServiceProvider`:

```php
$this->singleton(SovereigntyConfigInterface::class, fn() => SovereigntyConfig::fromArray($this->config));
```

## Community Context

Request-scoped community isolation for multi-tenant sovereign apps. When a `CommunityContext` is active, entity storage drivers that are wired with `CommunityScope` automatically restrict all queries to the active community.

### CommunityContextInterface / CommunityContext

File: `packages/foundation/src/Community/CommunityContextInterface.php`
File: `packages/foundation/src/Community/CommunityContext.php`

```php
interface CommunityContextInterface
{
    public function set(string $communityId): void;
    public function get(): ?string;
    public function clear(): void;
    public function isActive(): bool;
}
```

`CommunityContext` is a mutable singleton registered in `FoundationServiceProvider`:

```php
$this->singleton(CommunityContextInterface::class, CommunityContext::class);
```

### CommunityMiddleware

File: `packages/foundation/src/Community/CommunityMiddleware.php`
Attribute: `#[AsMiddleware(pipeline: 'http', priority: 20)]`

Resolves the active community from the incoming request and sets it on `CommunityContextInterface` for the duration of the request. Clears the context in a `finally` block after the response.

**Resolution order (first match wins):**
1. Route parameter `community_id` (e.g. `/community/{community_id}/...`)
2. Session key `waaseyaa_community_id` (requires `SessionMiddleware` priority 30 to have run first)

When no community is resolved (CLI, admin superuser, unauthenticated), the context remains inactive and queries are unscoped.

## HTTP Utilities

### ControllerDispatcher and Domain Routers

File: `packages/foundation/src/Http/ControllerDispatcher.php`

Routes a matched controller name to the appropriate handler. Central dispatch hub for `HttpKernel`.

Handles callable controllers (objects with `__invoke(Request): Response`) directly. String controller keys are delegated to domain-specific routers in `packages/foundation/src/Http/Router/`. All controller return types are Symfony `Response` or `JsonResponse` (no custom response DTOs).

**Controller key normalization:** Routes declared with Symfony's array-callable form (`'_controller' => [FooController::class, 'bar']`) are normalized to `FooController::bar` string form before the domain router chain runs. This keeps downstream routers' `supports()` checks (which use `str_contains()` / `str_starts_with()` against `_controller`) simple — they never have to handle both shapes. `JsonApiRouter::supports()` additionally has a defensive `match()` so any misrouted array callable that slipped through produces a clean miss rather than a string-function type error.

**Inertia response handling:** When a callable controller returns a value implementing `InertiaPageResultInterface`, the dispatcher checks for the `X-Inertia` request header. XHR requests get a JSON response with the page object. Non-XHR (initial page load) requests are rendered to full HTML via the injected `InertiaFullPageRendererInterface` (bound by `InertiaServiceProvider`). If that interface is not registered, full-page Inertia requests return 500.

**RootTemplateRenderer default HTML:** `packages/inertia/src/RootTemplateRenderer.php` emits `<div id="app"></div>` and a following `<script type="application/json" data-page="app">` whose text content is the JSON page object. The `data-page` attribute value must match the root element id (default `app`) so `@inertiajs/core` `getInitialPageFromDOM()` can load the initial page on the first visit.

**Error handling:** Both the callable controller path and the router dispatch path are wrapped in try-catch. Unhandled exceptions produce a 500 JSON:API error response via `handleException()`, which includes stack trace details when debug mode is enabled.

#### DomainRouterInterface

File: `packages/foundation/src/Http/Router/DomainRouterInterface.php`

```php
interface DomainRouterInterface
{
    public function supports(Request $request): bool;
    public function handle(Request $request): Response;
}
```

Deterministic chain: `HttpKernel` iterates routers in order; first `supports()` match wins.

**Merge order:** Built-in foundation routers (in `HttpKernel::serveHttpRequest`) run as: `JsonApiRouter`, `EntityTypeLifecycleRouter`, `SchemaRouter`, **`CodifiedContextApiRouter`**, `SearchRouter`, `McpRouter`, then each discovered `ServiceProvider::httpDomainRouters(?HttpKernel)` in **package manifest order**, then **`BroadcastRouter`** last. Example provider routers: `ApiServiceProvider` (`DiscoveryRouter` in `Waaseyaa\Api\Http\Router`), `MediaServiceProvider` (`MediaRouter`), `GraphQlServiceProvider` (`GraphQlRouter`, merging `graphqlMutationOverrides()` from all providers), `SsrServiceProvider` (`SsrRouter`, `AppControllerRouter`).

**Kernel hooks:** After access policies are registered and before `$kernel->booted` is set, `HttpKernel::finalizeBoot()` prepares shared cache backends, discovery handler, MCP/render cache listeners from `EventListenerRegistrar`, per-provider `registerRenderCacheListeners()` and `configureHttpKernel()` (SSR builds `SsrPageHandler` there so `EntityAccessGate` sees a fully wired `EntityAccessHandler`).

#### WaaseyaaContext

File: `packages/foundation/src/Http/Router/WaaseyaaContext.php`

Typed value object built once from the request via `WaaseyaaContext::fromRequest()`. Provides `account`, `parsedBody`, `query`, `method`, and `broadcastStorage` to routers.

#### SSR app controllers: inbound HTTP boundary

`SsrPageHandler::dispatchAppController()` invokes app methods as `($params, $query, $account, $httpRequest)` where `$httpRequest` is Symfony’s `Request`. That fourth argument stays the dispatcher contract.

Return values: **`HttpResponse`** is returned as-is (with render `Cache-Control` applied). **`InertiaPageResultInterface`** is converted like `ControllerDispatcher`: when `X-Inertia: true`, respond with JSON:API content type and Inertia headers; otherwise render full HTML via `InertiaFullPageRendererInterface` from `HttpKernel::getInertiaFullPageRenderer()` (wired from `SsrServiceProvider::configureHttpKernel()`). If Inertia is returned but no full-page renderer is registered, dispatch yields a 500 JSON:API error. Any other return type still produces the legacy 500 HTML snippet.

Below the controller entrypoint, **do not** pass `Symfony\Component\HttpFoundation\Request` into application or domain services. Build **`InboundHttpRequest::fromSymfonyRequest($httpRequest, $params, $query)`** once per action and pass **`InboundHttpRequestInterface`** (or the concrete snapshot for construction only) downward.

`InboundHttpRequest` is an immutable snapshot: route and query bags are the arrays the router already extracted (not re-read from the request); the body merges `$request->request->all()` with the `_parsed_body` attribute when it is an array (JSON keys overlay form keys). Headers and cookies are copied at construction time.

Optional follow-ups (full header map API, lazy adapter, JSON:API adoption) are tracked as [#1174](https://github.com/waaseyaa/framework/issues/1174), [#1175](https://github.com/waaseyaa/framework/issues/1175), and [#1176](https://github.com/waaseyaa/framework/issues/1176) and do not block this convention.

#### Domain Routers

| Router | Controller key(s) | Purpose |
|--------|-------------------|---------|
| `JsonApiRouter` | `jsonapi.*` | JSON:API CRUD delegation to `JsonApiController` |
| `EntityTypeLifecycleRouter` | `entity_types`, `entity_type.disable`, `entity_type.enable` | Entity type listing and lifecycle management |
| `SchemaRouter` | `openapi`, `schema.*` | OpenAPI and JSON Schema endpoints |
| `CodifiedContextApiRouter` | `Waaseyaa\Api\Controller\CodifiedContextController::*` | Telescope agent-context session JSON (`BuiltinRouteRegistrar` routes under `/api/telescope/agent-context/…` plus legacy `/api/telescope/codified-context/…`); optional `CodifiedContextSessionStoreInterface` from `HttpKernel::getCodifiedContextSessionStore()` |
| `DiscoveryRouter` (`Waaseyaa\Api\Http\Router`) | `discovery.topic_hub`, `discovery.cluster`, `discovery.timeline`, `discovery.endpoint` | Discovery API for topic hubs, clusters, timelines (registered from `ApiServiceProvider::httpDomainRouters()`) |
| `SearchRouter` | `search.semantic` | Semantic search via embedding storage |
| `MediaRouter` (`Waaseyaa\Media\Http\Router`) | `media.upload` | File upload with MIME validation, size limits, sanitization, move error handling (`MediaServiceProvider`) |
| `GraphQlRouter` (`Waaseyaa\GraphQL\Http\Router`) | `graphql.endpoint` | GraphQL query/mutation execution (`GraphQlServiceProvider`) |
| `McpRouter` | `mcp.endpoint` | MCP JSON-RPC endpoint |
| `SsrRouter` (`Waaseyaa\SSR\Http\Router`) | `render.page` | Server-side page rendering (`SsrServiceProvider`) |
| `AppControllerRouter` (`Waaseyaa\SSR\Http\Router`) | `Class::method` strings | App-level controllers registered via `ServiceProvider::routes()`. Delegates to `SsrPageHandler::dispatchAppController()` which uses reflection-based constructor injection (EntityTypeManager, Twig, HttpRequest, AccountInterface, plus the kernel's `serviceResolver` fallback). Wired after `SsrRouter` so `render.page` retains its existing precedence. `supports()` claims a controller only when it contains `::`, has no whitespace, both class and method segments are non-empty, and the class segment is namespaced or starts with an uppercase letter. |
| `BroadcastRouter` | `broadcast.stream` | SSE broadcast stream via `StreamedResponse` |

#### BuiltinRouteRegistrar (named routes)

File: `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`

This kernel-adjacent registrar runs once at boot (called from `HttpKernel`) and writes every framework-built-in named route onto the `WaaseyaaRouter`. It lives under `<pkg>/src/Kernel/` so it falls under the implicit Kernel exemption tier of `bin/check-package-layers` — controllers from higher layers are referenced by **string FQCN** (e.g. `'Waaseyaa\\Api\\Controller\\McpAdminController'`) rather than `::class`, so foundation never holds a hard import to L1+ classes. App-provider routes are layered in (one call to `$provider->routes(...)` per provider) before the SSR catch-alls so app controllers can claim explicit paths; `sortRoutesByPriority()` is called once at the end so `priority()` overrides settle deterministically.

Route groups currently registered (read directly from HEAD on `main`):

| Group | Paths | Access gate |
|-------|-------|-------------|
| Schema + discovery (read) | `/api/schema/{entity_type}`, `/api/openapi.json`, `/api/entity-types`, `/api/discovery/{hub,cluster,timeline,endpoint}/{entity_type}/{id}` | `allowAll` / default |
| Entity-type lifecycle | `POST /api/entity-types/{entity_type}/{enable,disable}` | `_role: admin` |
| Broadcast (SSE) | `GET /api/broadcast` | default |
| Media | `POST /api/media/upload` (`access media`); `GET /api/media/{uuid}/versions[/{vid}]` (authenticated) | mixed |
| Semantic search | `GET /api/search` | `allowAll` |
| Workflow admin | `GET /api/workflow-definitions`; `POST /api/workflow-definitions/dry-run`; `GET /api/workflow-definitions/{workflow_id}/guards` | `_role: admin` |
| Queue admin | `GET /api/queue/jobs`; `POST /api/queue/jobs/{id}/{retry,discard}` | `_role: admin` |
| Scheduler admin | `GET /api/scheduler/tasks`; `POST /api/scheduler/tasks/{name}/trigger` | `_role: admin` |
| Notification admin | `GET /api/notification/channels`; `POST /api/notification/channels/{type}/test` | `_role: admin` |
| Telescope agent-context + legacy codified-context | `GET /api/telescope/{agent-context,codified-context}/sessions[/{id}[/events\|/validation]]` | `_role: admin` |
| Mercure monitor | `GET /api/mercure/{channels,events,subscribers}` | `_role: admin` |
| OCAP audit log | `GET /api/audit/events` | `_role: admin` |
| **MCP-admin REST (M5C WP01)** | `GET /api/mcp/tools`; `GET /api/mcp/tools/{name}`; `GET /api/mcp/server-config` | `_role: admin` |
| OIDC client CRUD | `GET\|POST\|PATCH\|DELETE /api/oidc-clients[/{id}[/regenerate-secret]]` | `_role: admin` |
| Classification retention policies | `GET\|POST\|PATCH\|DELETE /api/classification/policies[/{id}]` (proxied to `JsonApiController` with `_entity_type=retention_policy`) | `_role: governance-viewer,admin` (read) / `admin` (write) |
| SSR catch-all | `GET /`, `GET /{path}` with negative-lookahead on `/api` | `allowAll` (render) |

Dispatch contracts for each named route live in the API package (`packages/api/src/Http/Router/*` — e.g. `McpAdminApiRouter`, `QueueAdminApiRouter`, `WorkflowGuardsApiRouter`). See `docs/specs/api-layer.md` for per-router DTOs and response shapes.

**Layer-discipline rationale.** Foundation is L0 but `BuiltinRouteRegistrar` references controller and route names that resolve to L1–L6 classes. This is acceptable because the registrar is an entry-point orchestrator, not reusable library code — controllers are passed as **string FQCNs** so foundation never `use`s a higher layer. Per `CLAUDE.md` Layer Architecture, kernels and built-in route registrars are explicit Kernel exemptions: they wire all layers together by design and are not imported by any other package.

### CorsHandler

File: `packages/foundation/src/Http/CorsHandler.php`

```php
final class CorsHandler
{
    public function __construct(
        private readonly array $allowedOrigins = ['http://localhost:3000', 'http://127.0.0.1:3000'],
        private readonly bool $allowDevLocalhostPorts = false,
        ?LoggerInterface $logger = null,
    );

    public function resolveCorsHeaders(string $origin): array;
    public function handlePreflight(string $origin, string $requestMethod): array;
    public function isCorsPreflightRequest(string $method): bool;
}
```

CORS origin resolution in `HttpKernel::handleCors()`:
1. Reads `cors_origins` from config (defaults to `localhost:3000` and `127.0.0.1:3000`).
2. Checks `WAASEYAA_CORS_ORIGIN` env var — if set, overrides the config array with a single-origin list.
3. Passes `allowDevLocalhostPorts: true` when the kernel is in development mode (env is `dev`, `development`, `local`, or `testing`), allowing any localhost port.

### HTTP authorization pipeline (`HttpKernel::serveHttpRequest`)

After routing matches, `HttpKernel` builds an `HttpPipeline` of HTTP middleware (Bearer auth, session, CSRF, `AuthorizationMiddleware`, provider middleware). The inner handler is a stub that returns **200** with an empty body when the entire chain allows the request through.

If any middleware short-circuits — for example `AuthorizationMiddleware` returning **302** to `/login` for unauthenticated `_authenticated` render routes, or **401** JSON:API for API routes — that response **must** be returned to the client immediately. The kernel treats any pipeline response whose status is **not 200** as final and does not continue to `ControllerDispatcher`. Only **200** from the pipeline means proceed to dispatch.

### XSRF-TOKEN cookie hook (`HttpKernel::serveHttpRequest`)

After `ControllerDispatcher` returns the final controller response, `serveHttpRequest` calls `CsrfMiddleware::attachCookieIfHtml($httpRequest, $finalResponse)` (around line 419). This pairs with the request-side CSRF middleware step earlier in the auth pipeline.

The separation exists because the auth pipeline inner-handler stub returns a bare 200 with no Content-Type; `CsrfMiddleware::attachXsrfCookie` therefore sees no `text/html` response and cannot write the cookie. The kernel post-dispatch hook runs against the real controller response, which carries the correct Content-Type and headers.

Behaviour of the hook:

- **Restricted to `text/html`** — skips JSON, octet-stream, and any other primary Content-Type.
- **Idempotent** — no-ops if an `XSRF-TOKEN` cookie is already present on the response (the middleware may have set it for non-validating GET requests that pass through without the 200-stub issue).
- **Session guard** — returns immediately if no PHP session is active or the session token key is absent.

This hook is the only post-dispatch mutation `serveHttpRequest` applies to the controller response; everything else (CORS, debug headers) is handled earlier in the pipeline.

### Dev fallback account

`HttpKernel::shouldUseDevFallbackAccount()` controls whether `DevAdminAccount` is injected as the session fallback. All three conditions must be true:
- PHP SAPI is `cli-server` (built-in dev server)
- Application is in development mode (`config.environment` or `APP_ENV` is dev/development/local/testing)
- `config.auth.dev_fallback_account` is explicitly `true`

## Operator Diagnostics

### DiagnosticCode

File: `packages/foundation/src/Diagnostic/DiagnosticCode.php`

String-backed enum of operator-facing error codes:

| Code | Trigger |
|------|---------|
| `DEFAULT_TYPE_MISSING` | No entity types registered at boot |
| `DEFAULT_TYPE_DISABLED` | All registered types disabled |
| `DATABASE_UNREACHABLE` | Database file missing or corrupt |
| `DATABASE_SCHEMA_DRIFT` | Entity table columns don't match expected schema (base or bundle subtable) |
| `MISSING_BUNDLE_SUBTABLE` | A bundle with registered fields has no `{base}__{bundle}` subtable |
| `ORPHAN_BUNDLE_SUBTABLE` | A `{base}__{bundle}` subtable exists with no registered bundle fields |
| `FK_ENFORCEMENT_DISABLED` | Foreign-key enforcement off at the connection level (e.g. SQLite without `PRAGMA foreign_keys = ON`) |
| `STORAGE_DIRECTORY_MISSING` | `storage/framework/` does not exist |
| `CACHE_DIRECTORY_UNWRITABLE` | Cache directory not writable |
| `INGESTION_LOG_OVERSIZED` | Ingestion log exceeds retention threshold |
| `INGESTION_RECENT_FAILURES` | High ingestion failure rate |
| `COLUMN_DATA_STORAGE_DRIFT` | A field registered with `FieldStorage::Data` still has a backing column on the base table or a bundle subtable (new writes go to `_data`; the column holds stale values) |

Each code has a `defaultMessage()` method for human-readable descriptions. Severity: `MISSING_BUNDLE_SUBTABLE` and `FK_ENFORCEMENT_DISABLED` are errors; `ORPHAN_BUNDLE_SUBTABLE` and `COLUMN_DATA_STORAGE_DRIFT` are warnings (the base row is still reachable, the lingering surface is merely stale).

### DiagnosticEmitter

File: `packages/foundation/src/Diagnostic/DiagnosticEmitter.php`

```php
final class DiagnosticEmitter
{
    public function __construct(?LoggerInterface $logger = null);
    public function emit(DiagnosticCode $code, string $message, array $context = []): DiagnosticEntry;
}
```

Emits structured JSON diagnostic log entries. Returns `DiagnosticEntry` for callers that need to inspect or re-throw.

### HealthCheckerInterface

File: `packages/foundation/src/Diagnostic/HealthCheckerInterface.php`

Contract for running operator health checks. Consumers use this interface when they need to programmatically query system health — e.g. the `health:check` CLI command and any monitoring integration. Inject `HealthCheckerInterface`; the default binding is `HealthChecker`. Results are `HealthCheckResult` value objects with pass/warn/fail status.

### HealthChecker

File: `packages/foundation/src/Diagnostic/HealthChecker.php`
Implements: `HealthCheckerInterface`

```php
final class HealthChecker implements HealthCheckerInterface
{
    public function __construct(
        private readonly BootDiagnosticReport $bootReport,
        private readonly DatabaseInterface $database,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $projectRoot,
        ?LoggerInterface $logger = null,
        ?FieldDefinitionRegistryInterface $fieldRegistry = null,
    );

    public function runAll(): array;          // list<HealthCheckResult>
    public function checkBoot(): array;       // entity type registry state
    public function checkRuntime(): array;    // database, schema drift, storage, cache dirs, FK enforcement
    public function checkSchemaDrift(): array; // base + bundle subtable drift
    public function checkIngestion(): array;  // ingestion log health, error rates
}
```

Three check groups: boot (entity type registry), runtime (database connectivity, schema drift, storage directories, foreign-key enforcement), and ingestion (log size, error rate). Results are `HealthCheckResult` value objects with pass/warn/fail status.

#### Subtable-aware schema drift

For any entity type whose `EntityType::getBundleEntityType()` is non-null, `checkSchemaDrift()` does not stop at the base table. It enumerates the registered bundles via `$this->fieldRegistry->bundleNamesFor($entityTypeId)`, and for each bundle:

- If the bundle has registered fields (`bundleFieldsFor()` is non-empty) but the `{base}__{bundle}` subtable is absent, emits `MISSING_BUNDLE_SUBTABLE` (fail).
- If a `{base}__{bundle}` subtable exists but no fields are registered for that bundle, emits `ORPHAN_BUNDLE_SUBTABLE` (warn). Orphan detection scans `sqlite_master LIKE '{base_table}__%'` (ESCAPE-aware) and compares against the registry.
- If the subtable exists but its columns do not match the registered field shape, the existing `DATABASE_SCHEMA_DRIFT` code is emitted with the subtable name in the message so the operator can distinguish base-table drift from bundle-table drift.

The `fieldRegistry` parameter is optional to preserve the prior constructor contract for callers that predate per-bundle storage; when null, `HealthChecker` degrades to base-table-only drift detection (its former behaviour).

#### FK enforcement health check

`checkRuntime()` probes `PRAGMA foreign_keys` on SQLite connections. If the pragma reports `0`, it emits `FK_ENFORCEMENT_DISABLED` (fail), since `ON DELETE CASCADE` from the base table to bundle subtables silently becomes a no-op. MySQL/InnoDB is on by default but can be disabled per-session; any new driver added to `DBALDatabase` must be audited for FK-default behaviour.

#### Wiring

Both `AbstractKernel` and `ConsoleKernel` expose the `FieldDefinitionRegistry` they construct during `bootEntityTypeManager()` via a protected `$fieldRegistry` property, and pass it through when instantiating `HealthChecker` for CLI health commands. The same registry instance is shared with `SqlSchemaHandler`, `SqlEntityStorage`, and `ContentEntityBase::setFieldRegistry()`, so drift detection sees exactly the bundle set the storage layer is materializing.

Authoritative contracts: `docs/specs/bundle-scoped-storage.md §Drift diagnostic` and `docs/specs/operator-diagnostics.md` define the codes and their operator-facing semantics; this section describes how `HealthChecker` surfaces them.

## Internal Interfaces

These foundation interfaces are `@internal` and not part of the public consumer API. They are listed here for completeness and to prevent accidental exposure.

### TenantResolverInterface

File: `packages/foundation/src/Tenant/TenantResolverInterface.php`

`@internal` — tenant resolution is not yet a consumer-facing contract. The interface exists for framework use only and may change without notice. Do not inject or implement this interface in application code.

### Mail interfaces

Files: `packages/mail/src/MailerInterface.php`, `packages/mail/src/Transport/TransportInterface.php`

`@internal` — foundation seam. **`AuthMailer`**, **`MailChannel`** (notifications), and app commands send mail via **`MailerInterface::send(Envelope)`**. **`MailServiceProvider`** binds `TransportInterface`: when `mail.sendgrid_api_key` and `mail.from_address` are both non-empty after trim, **`SendGridTransport`** is used; otherwise `mail.transport` selects **`ArrayTransport`** or **`LocalTransport`**. Application code should not depend on these interfaces directly where a higher-level API exists — use **`AuthMailer`**, notification channels, or the shared mailer binding.

## Queue System

File: `packages/queue/`
Namespace: `Waaseyaa\Queue\`

### QueueInterface

File: `packages/queue/src/QueueInterface.php`

Queue implementations: `DbalQueue` (DBAL-backed persistent), `InMemoryQueue` (testing), `MessageBusQueue` (Symfony Messenger bridge), `SyncQueue` (immediate execution).

### Worker

File: `packages/queue/src/Worker/Worker.php`
Class: `final class Worker`

Constructor: `(TransportInterface $transport, FailedJobRepositoryInterface $failedJobRepository, array $handlers)`

Long-running daemon that processes jobs from a queue transport.

**Public API:**
- `run(string $queue, WorkerOptions $options): int` — daemon loop, returns count of jobs processed
- `runNextJob(string $queue, WorkerOptions $options): bool` — process single job (non-looping, useful for tests)
- `stop(): void` — request graceful shutdown (finishes current job, then exits)
- `addHandler(HandlerInterface $handler): void` — prepend a handler (first added = highest priority)

**Stop conditions** (checked in `shouldContinue()`):
- `$shouldQuit` flag set (via `stop()` or POSIX signal)
- `maxJobs` reached (`$options->maxJobs > 0 && $processed >= $options->maxJobs`)
- `maxTime` elapsed (`$options->maxTime > 0 && (time() - $startTime) >= $options->maxTime`)
- Memory growth budget exhausted (`$options->memoryLimit > 0` and `(memory_get_usage(true) - baselineBytes) >= $options->memoryLimit * 1024 * 1024`, where `baselineBytes` is captured once at the start of `run()` — **not** total process RSS; avoids exiting immediately when the host process is already large)

**POSIX signal handling:** `listenForSignals()` registers SIGTERM/SIGINT handlers that set `$shouldQuit = true`. `pcntl_signal_dispatch()` is called each iteration in `shouldContinue()`. Gracefully degrades when `pcntl` extension is unavailable.

**Job processing pipeline:**
1. `transport->pop($queue)` — dequeue raw message (`{id, payload, attempts}`)
2. `@unserialize($raw['payload'])` — deserialize (failures recorded to `FailedJobRepository`)
3. First matching `HandlerInterface::supports($message)` handles the job
4. If `Job::isReleased()`, release back to queue with delay; otherwise `transport->ack()`
5. On exception: retry with exponential backoff (`min(baseDelay * 2^(attempts-1), 3600)`) if under `maxTries`, otherwise record failure and call `Job::failed($e)` (best-effort)

**WorkerOptions** (`packages/queue/src/Worker/WorkerOptions.php`): Controls `maxJobs`, `maxTime`, `memoryLimit` (MiB of heap growth allowed during each `run()` call), `sleep` (seconds between polls), `maxTries`.

### Transport layer

`TransportInterface` (`packages/queue/src/Transport/TransportInterface.php`) abstracts job serialization/deserialization. Implementations: `DbalTransport` (database-backed), `InMemoryTransport` (testing).

### Failed job tracking

`FailedJobRepositoryInterface` with implementations: `DatabaseFailedJobRepository` (DBAL-backed), `InMemoryFailedJobRepository` (testing).

The legacy concrete `FailedJobRepository` class (a thin facade delegating to `InMemoryFailedJobRepository`) is **deprecated**. Its constructor carries a PHP 8.4 `#[\Deprecated(message: …, since: '0.1')]` attribute so that any `new FailedJobRepository(...)` call emits `E_USER_DEPRECATED` and surfaces in PHPStan/IDEs/Reflection. The class-level `@deprecated` docblock is retained because PHP 8.4's `\Deprecated` attribute disallows class targets (`Attribute::TARGET_FUNCTION | TARGET_METHOD | TARGET_CLASS_CONSTANT` only), so docblock and constructor-attribute together provide full coverage. New code MUST type-hint `FailedJobRepositoryInterface` and inject `InMemoryFailedJobRepository` or `DatabaseFailedJobRepository` directly.

### Message types

| Message | Purpose |
|---------|---------|
| `EntityMessage` | Entity lifecycle events for async processing |
| `ConfigMessage` | Config change propagation |
| `GenericMessage` | Arbitrary payload |

### Job attributes

| Attribute | Purpose |
|-----------|---------|
| `#[OnQueue('name')]` | Route job to a specific queue |
| `#[RateLimited]` | Apply rate limiting to job execution |
| `#[UniqueJob]` | Prevent duplicate concurrent execution |

### Job composition

`BatchedJobs` groups multiple jobs for parallel execution. `ChainedJobs` runs jobs sequentially — failure stops the chain.

### Migration

`CreateQueueTables` (`packages/queue/src/Migration/CreateQueueTables.php`) and timestamped migrations under `packages/queue/migrations/` (registered via `extra.waaseyaa.migrations` in `packages/queue/composer.json`) create **`waaseyaa_queue_jobs`** and **`waaseyaa_failed_jobs`**. Older docs may refer to unprefixed names; the DDL above is authoritative.

## Kernel Bootstrap

The kernel boot sequence is decomposed into extracted bootstrapper classes in `packages/foundation/src/Kernel/Bootstrap/`. `AbstractKernel` delegates to these rather than inlining the logic.

### AbstractKernel

File: `packages/foundation/src/Kernel/AbstractKernel.php`

Constructor: `(string $projectRoot, ?LoggerInterface $logger = null)`

Default logger is `LogManager(new Handler\ErrorLogHandler())`. After config loads, the kernel rebuilds it: if `config['logging']['channels']` exists, uses `LogManager::fromConfig()`; otherwise uses `Handler\ErrorLogHandler(minimumLevel: $level)` from `config['log_level']`.

Boot sequence (idempotent — guarded by `$this->booted` flag, set only after all steps succeed):

```
EnvLoader::load(.env)
  → ConfigLoader::load(config/waaseyaa.php)
  → rebuild LogManager (fromConfig if logging.channels exists, else log_level fallback)
  → debug/environment safety guard
  → new EventDispatcher()
  → new EntityTypeLifecycleManager($projectRoot)
  → new EntityAuditLogger($projectRoot)
  → register EntityWriteAuditListener on PRE_SAVE, POST_SAVE, POST_DELETE
  → bootDatabase()           // DatabaseBootstrapper
  → bootEntityTypeManager()  // inline: storage factory (SqlEntityStorage) + repository factory (EntityRepository)
  → compileManifest()        // ManifestBootstrapper
  → bootMigrations()         // reuses DBAL connection from bootDatabase
  → discoverAndRegisterProviders()  // ProviderRegistry
  → loadAppEntityTypes()     // reads config/entity-types.php
  → validateContentTypes()   // DiagnosticEmitter check
  → bootProviders()          // calls boot() on all registered providers
  → discoverAccessPolicies() // AccessPolicyRegistry
  → bootKnowledgeExtensionRunner() // plugin discovery for knowledge tooling extensions
  → $this->booted = true
```

Early boot initializes the entity lifecycle manager (for disabling entity types at runtime) and the entity audit logger (for write audit trails). The `EntityWriteAuditListener` is registered on the event dispatcher before any entity storage is created, ensuring all entity writes are audited from boot onward.

`bootEntityTypeManager()` wires storage for each registered entity type. Every `SqlSchemaHandler` instantiated in that path receives the kernel's `LoggerInterface` as its fifth constructor argument (after entity type, database, shared `FieldDefinitionRegistry`, and optional `null` bundle enumerator) so schema derivation can log unknown field types without failing boot. Column mapping contract: [`field/column-derivation.md`](./field/column-derivation.md).

`loadAppEntityTypes()` reads `config/entity-types.php` and registers any `EntityTypeInterface` instances found there. Non-conforming entries are logged as warnings. Registration failures (duplicate IDs, invalid definitions) are logged as errors but do not halt boot.

`validateContentTypes()` checks that at least one entity type is registered and enabled. If no types exist, it emits `DEFAULT_TYPE_MISSING` and throws. If all registered types are disabled via the lifecycle manager, it emits `DEFAULT_TYPE_DISABLED` and throws.

`bootKnowledgeExtensionRunner()` reads `config.extensions.plugin_directories` and `config.extensions.plugin_attribute`, discovers plugins via `AttributeDiscovery`, and builds a `KnowledgeToolingExtensionRunner`. On failure, falls back to an empty runner. The runner is accessible via `getKnowledgeToolingExtensionRunner()` and provides `applyWorkflowContext()`, `applyTraversalContext()`, and `applyDiscoveryContext()` extension hooks.

#### Environment and debug introspection

Three protected methods provide environment awareness to all kernel subclasses:

| Method | Resolution | Returns |
|--------|-----------|---------|
| `resolveEnvironment(): string` | Config `'environment'` key → `APP_ENV` env var → `'production'` | Canonical environment name (e.g., `'production'`, `'local'`, `'development'`) |
| `isDevelopmentMode(): bool` | Calls `resolveEnvironment()`, checks if value is `dev`, `development`, `local`, or `testing` (case-insensitive) | `true` in dev environments |
| `isDebugMode(): bool` | `APP_DEBUG` env var → config `'debug'` key → `false` | `true` when debug is enabled |

**Boot guard:** Immediately after loading configuration, `boot()` checks `isDebugMode() && !isDevelopmentMode()`. If debug is enabled outside a development environment, it throws `RuntimeException` with the message `APP_DEBUG must not be enabled in production (APP_ENV=...)`. This prevents accidentally deploying with debug mode active.

#### Layer 0 environment variable contract

These variables and config keys are the primary **bootstrap surface** for operators and Layer 0 code. Prefer reading configuration from `ConfigLoader` output after `EnvLoader::load()`; direct `getenv` / `$_ENV` / `$_SERVER` reads in foundation-adjacent packages should stay limited to the seams below or be documented here when extended.

| Name | Role |
|------|------|
| `APP_ENV` | Canonical environment name; falls back to config `environment`, then `'production'`. Drives `isDevelopmentMode()` and the production SQLite existence guard. |
| `APP_DEBUG` | Boolean debug flag; falls back to config `debug`. **Must not be true** when the resolved environment is non-development (see boot guard above). |
| `WAASEYAA_DB` | Optional override for the SQLite database file path when `config['database']` is not set (see `DatabaseBootstrapper`). |
| `WAASEYAA_CONFIG_DIR` | Optional override for the sync config directory (used by `ConsoleKernel` alongside `config['config_dir']`). |
| `.env` (file) | Loaded first from `$projectRoot/.env` via `EnvLoader::load()` before `config/waaseyaa.php`. `EnvLoader` writes to `putenv()`, `$_ENV`, and `$_SERVER` without overwriting keys already present in any of those stores (see source listing under Kernel Bootstrap file index). |

**Review note (assert / IO):** Layer 0 code may use `assert()` for internal invariants and file/stream helpers for logging, caches, or HTTP clients. Production should assume `zend.assertions` may be off; hot paths must not rely on assertions for security. When adding `file_put_contents`, `fopen`, `unserialize`, or `base64_decode` in Layer 0 packages, document the trust boundary (operator-only paths vs request-derived input) in package-level docblocks or this spec.

### DatabaseBootstrapper

File: `packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`
Class: `final class DatabaseBootstrapper`

```php
public function boot(string $projectRoot, array $config): DatabaseInterface
```

Creates `DBALDatabase::createSqlite()` using path resolution: `$config['database']` → `WAASEYAA_DB` env → `$projectRoot/storage/waaseyaa.sqlite`. In non-production environments, ensures the parent directory exists via `@mkdir()` (warning-suppressed — failure is expected in tests with inaccessible paths; SQLite will throw a proper exception downstream).

Production safety contract:
- environment resolution matches the kernel contract: config `'environment'` key → `APP_ENV` env var → `'production'`
- when the resolved environment is `production`, file-backed SQLite paths must already exist before boot continues
- if the resolved production SQLite file is missing, bootstrap throws `RuntimeException` naming `bin/waaseyaa db:init` as the sanctioned first-deploy path (`Database not found at {path}. In production, the database must already exist. Run "bin/waaseyaa db:init" to create the database file and apply migrations. The command is idempotent and safe to run on every deploy.`). The guard itself is unchanged; `db:init` bypasses it by running through the minimal-console path (see `ConsoleKernel::shouldUseMinimalConsole()` and the `DbInitCommand` reference below).
- when that production guard fires, bootstrap does not create the parent directory as a side effect
- non-production environments (`local`, `dev`, `development`, `testing`, etc.) keep the existing auto-create behavior
- `:memory:` remains allowed in all environments for explicit in-memory bootstrap/test cases

### ManifestBootstrapper

File: `packages/foundation/src/Kernel/Bootstrap/ManifestBootstrapper.php`
Class: `final class ManifestBootstrapper`

```php
public function boot(string $projectRoot): PackageManifest
```

Instantiates `PackageManifestCompiler` with `storagePath: $projectRoot . '/storage'` and calls `load()` (cache-first, compile on miss).

`storage/framework/packages.php` includes metadata key `_manifest_inputs_fp`: an `xxh128` digest of the raw contents of the project `composer.json` and `vendor/composer/installed.json`. When present and not equal to a freshly computed digest, `load()` discards the cache and recompiles (covers new/removed Composer packages and copied stale caches). After loading a cached manifest, `assertProvidersExist()` validates that all declared provider classes can be autoloaded. If any are missing, the manifest auto-recovers by logging a warning and recompiling from disk, no manual `optimize:manifest` needed. `StaleManifestException` is still thrown by `assertProvidersExist()` but is caught internally by `load()` as a recompile trigger. If the recompiled manifest still contains missing providers (e.g., stale `composer.json` declarations), `load()` logs an error with actionable remediation guidance, stamps the missing provider list into the cache via `_known_missing_providers`, and returns the manifest without rethrowing. On subsequent requests, `validateCachedProviders()` compares the current missing set against the stamped known-missing set: if they match, recompilation is skipped (only an error is logged). If `composer.json` changes (fingerprint mismatch), the stamp is naturally cleared by a fresh compile. This prevents repeated full-compile cost on every request when a provider is permanently misconfigured (#9). If the stamp cannot be persisted (missing cache file, write failure), `stampKnownMissing()` logs a warning so operators can diagnose why recompilation continues.

The compiled manifest now also carries `packageDeclarations`, derived from package-local `composer.json` metadata and merged installed-package metadata. This is the post-M10 baseline used to normalize provider ownership and to verify that declared provider classes still exist before the manifest is trusted.

On every successful cache read, root `extra.waaseyaa` **providers** and **permissions** are merged again from `composer.json` so a structurally valid cache cannot omit app-level declarations that match the current fingerprint. Composer keys `extra.waaseyaa.commands` and `extra.waaseyaa.routes` are **deprecated**: they are not compiled into `PackageManifest`, and `PackageManifest::fromArray()` ignores legacy `commands`/`routes` keys if present in an older `packages.php`. The compiler logs a warning when any installed package or root `composer.json` still declares those keys (see `docs/adr/0001-manifest-routes-commands-removal.md`). HTTP routes and console commands are owned by `ServiceProvider::routes()` / `ServiceProvider::commands()` and the core CLI registry — not the manifest lists.

### ProviderRegistry

File: `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php`
Class: `final class ProviderRegistry`

Constructor: `(LoggerInterface $logger)`

```php
public function discoverAndRegister(
    PackageManifest $manifest,
    string $projectRoot,
    array $config,
    EntityTypeManager $entityTypeManager,
    DatabaseInterface $database,
    EventDispatcherInterface $dispatcher,
): array  // list<ServiceProvider>
```

Discovery and registration follows a multi-phase process:

1. **Instantiation**: Each provider class from `$manifest->providers` is instantiated. Missing classes are logged with actionable remediation guidance (fix `composer.json` or run `optimize:manifest`) and skipped. Non-`ServiceProvider` instances are also logged and skipped.
2. **Context injection**: Each provider receives kernel context via `setKernelContext($projectRoot, $config, $manifest->formatters)` and a kernel resolver closure via `setKernelResolver()`. The resolver provides cross-provider DI — it resolves `EntityTypeManager`, `DatabaseInterface`, `EventDispatcherInterface`, `LoggerInterface`, and any binding registered by previously-loaded providers.
3. **Registration**: `register()` is called on each provider, allowing them to bind interfaces to implementations.
4. **Entity type collection**: After all providers register, entity types from `$provider->getEntityTypeRegistrations()` are registered with the `EntityTypeManager` together with the provider class that declared them. Generic registration failures are still logged as errors. `EntityTypeRegistrationCollisionException` is special-cased: the failure is logged and then rethrown so duplicate or shadow registrations stop boot deterministically.
5. **Provider-owned surfaces**: Route and command ownership stays with the package provider or package registry that declared it. Foundation now declares only its own baseline provider (`Waaseyaa\Foundation\FoundationServiceProvider`), while package-level providers such as `ApiServiceProvider`, `UserServiceProvider`, and `McpServiceProvider` own their respective HTTP surfaces.

The method returns the full list of instantiated providers. Handles instantiation failures gracefully with error logging.

### AccessPolicyRegistry

File: `packages/foundation/src/Kernel/Bootstrap/AccessPolicyRegistry.php`
Class: `final class AccessPolicyRegistry`

Constructor: `(LoggerInterface $logger)`

```php
public function discover(PackageManifest $manifest): EntityAccessHandler
```

Reads `$manifest->policies` (keyed by class name → entity type list), instantiates each policy class, and returns a wired `EntityAccessHandler`. Uses reflection heuristic: policies with required constructor parameters are inspected — if the first parameter is typed `array` (e.g., `ConfigEntityAccessPolicy`), the entity type list is passed; if the first parameter is a service type (e.g., `EntityTypeManagerInterface`), the policy cannot be auto-instantiated and is skipped with an error log (register it manually in your service provider instead). No-arg policies are instantiated directly. Missing classes and instantiation failures are logged, not fatal.

## File Reference

### packages/foundation/src/

```
Kernel/
    AbstractKernel.php           -- boot orchestrator, delegates to Bootstrap/ classes
    HttpKernel.php               -- HTTP request handling, cache setup, CORS
    ConsoleKernel.php            -- CLI bootstrapping; delegates command graph assembly to `Waaseyaa\CLI\CliCommandRegistry`
    EnvLoader.php                -- .env file parser; writes to putenv(), $_ENV, and $_SERVER (each destination guarded independently — preset keys in any destination are never overwritten)
    ConfigLoader.php             -- config/waaseyaa.php loader
    EventListenerRegistrar.php   -- registers cache invalidation listeners
    BuiltinRouteRegistrar.php    -- registers shared foundation-owned HTTP routes (schema, discovery, entity-types, broadcast SSE, media upload/versions, semantic search, workflow/queue/scheduler/notification admin, telescope agent-context JSON, Mercure monitor, OCAP audit log, MCP-admin REST `/api/mcp/{tools,server-config}`, OIDC client CRUD, classification retention policies, SSR catch-all)
    Bootstrap/
        DatabaseBootstrapper.php     -- creates DBALDatabase connection
        ManifestBootstrapper.php     -- loads/compiles PackageManifest
        ProviderRegistry.php         -- discovers, instantiates, and registers service providers
        AccessPolicyRegistry.php     -- discovers access policies and wires EntityAccessHandler
Event/
    DomainEvent.php              -- abstract base for all domain events
Middleware/
    HttpMiddlewareInterface.php  -- process(Request, HttpHandlerInterface): Response
    HttpHandlerInterface.php     -- handle(Request): Response
    HttpPipeline.php             -- onion-pattern HTTP middleware stack
    DebugHeaderMiddleware.php    -- X-Debug-Time/Memory/Request-Id headers (APP_DEBUG only)
    BodySizeLimitMiddleware.php  -- rejects oversized request bodies (413)
    JobMiddlewareInterface.php   -- process(Job, JobHandlerInterface): void
    JobHandlerInterface.php      -- handle(Job): void
    JobPipeline.php              -- onion-pattern job middleware stack
Migration/
    Migration.php                -- abstract base, up()/down() + $after deps
    SchemaBuilder.php            -- Doctrine DBAL table creation
    TableBuilder.php             -- fluent column definition DSL
    ColumnDefinition.php         -- nullable/default/unique modifiers
    Migrator.php                 -- topological sort + batch execution
    MigrationRepository.php      -- tracks completed migrations in DB
    MigrationResult.php          -- count + list of ran migrations
ServiceProvider/
    ServiceProviderInterface.php -- register()/boot()/provides()/isDeferred()
    ServiceProvider.php          -- abstract base with singleton/bind/tag helpers and provider-owned entity-type provenance capture
    ProviderDiscovery.php        -- reads composer installed.json extra.waaseyaa
    ContainerCompiler.php        -- register phase -> boot phase -> Symfony DI container
Discovery/
    PackageManifest.php          -- typed DTO for cached manifest data
    PackageManifestCompiler.php  -- reads composer metadata + scans PHP attributes -> packages.php
Attribute/
    AsFieldType.php              -- #[AsFieldType(id: '...', label: '...')]
    AsEntityType.php             -- #[AsEntityType(id: '...', label: '...')]
    AsMiddleware.php             -- #[AsMiddleware(pipeline: '...', priority: 0)]
Log/
    LoggerInterface.php          -- log contract (emergency through debug + log)
    LogLevel.php                 -- string-backed enum (EMERGENCY..DEBUG)
    LoggerTrait.php              -- convenience methods delegating to log()
    LogRecord.php                -- immutable VO: level, message, context, channel, timestamp
    LogManager.php               -- channel registry, implements LoggerInterface, fromConfig() factory
    ChannelLogger.php            -- scoped LoggerInterface: stamps channel, runs processors, delegates
    LegacyLoggerHandler.php      -- adapts LoggerInterface to HandlerInterface (internal)
    NullLogger.php               -- no-op for testing (widely used)
    Handler/
        HandlerInterface.php     -- handle(LogRecord): void
        ErrorLogHandler.php      -- error_log() with formatter + minimumLevel
        FileHandler.php          -- append to file with LOCK_EX
        StackHandler.php         -- fan-out, best-effort per handler
        NullHandler.php          -- discard all records
        StreamHandler.php        -- write to php://stderr or stream resource
    Formatter/
        FormatterInterface.php   -- format(LogRecord): string
        TextFormatter.php        -- [timestamp] [level] [channel] message {context}
        JsonFormatter.php        -- one JSON object per line
    Processor/
        ProcessorInterface.php   -- process(LogRecord): LogRecord (immutable enrichment)
        RequestIdProcessor.php   -- adds request_id (UUID hex) to context
        HostnameProcessor.php    -- adds hostname to context
        MemoryUsageProcessor.php    -- adds memory_peak_mb to context
        RequestContextProcessor.php -- adds http_method, uri, request_id to context (HTTP requests)
RateLimit/
    RateLimiterInterface.php     -- attempt(key, max, window): {allowed, remaining, retryAfter}
    InMemoryRateLimiter.php      -- sliding-window in-memory implementation
Asset/
    AssetManagerInterface.php    -- url(path, bundle): string
    ViteAssetManager.php         -- reads Vite manifest.json for hashed URLs; assetTags() generates HTML script/link tags with dev mode support
    TenantAssetResolver.php      -- tenant-specific asset path resolution
Http/
    ControllerDispatcher.php     -- routes controller names to domain routers; Inertia responses use Inertia::getRenderer()
    JsonApiResponseTrait.php     -- shared JSON:API response builder
    CorsHandler.php              -- CORS preflight and header resolution
    Router/
        DomainRouterInterface.php        -- supports(Request)/handle(Request) contract
        WaaseyaaContext.php              -- typed request context value object
        JsonApiRouter.php                -- JSON:API CRUD delegation
        EntityTypeLifecycleRouter.php    -- entity type listing and lifecycle
        SchemaRouter.php                 -- OpenAPI and JSON Schema endpoints
        DiscoveryRouter.php              -- topic hub, cluster, timeline, endpoint
        SearchRouter.php                 -- semantic search
        MediaRouter.php                  -- file upload with validation
        GraphQlRouter.php                -- GraphQL execution
        McpRouter.php                    -- MCP JSON-RPC endpoint
        SsrRouter.php                    -- server-side page rendering
        BroadcastRouter.php              -- SSE broadcast stream
Sovereignty/
    SovereigntyProfile.php       -- enum: Local, SelfHosted, NorthOps
    SovereigntyDefaults.php      -- profile → default settings mapping
    SovereigntyConfigInterface.php -- get/getProfile/all contract
    SovereigntyConfig.php        -- effective config: profile defaults + overrides
Diagnostic/
    DiagnosticCode.php           -- string-backed enum of operator error codes
    DiagnosticEntry.php          -- structured diagnostic log entry
    DiagnosticEmitter.php        -- emits structured JSON diagnostic entries
    HealthCheckerInterface.php   -- health check contract
    HealthChecker.php            -- boot/runtime/ingestion health checks
    HealthCheckResult.php        -- pass/warn/fail result value object
    BootDiagnosticReport.php     -- entity type registry snapshot
```

### packages/cache/src/

```
CacheBackendInterface.php        -- get/set/delete/invalidate contract
CacheItem.php                    -- readonly DTO: cid, data, created, expire, tags, valid
CacheFactoryInterface.php        -- get(bin): CacheBackendInterface
CacheFactory.php                 -- bin resolution via CacheConfiguration
CacheConfiguration.php           -- bin->backend mapping, factory callables
TagAwareCacheInterface.php       -- extends CacheBackendInterface + invalidateByTags()
CacheTagsInvalidatorInterface.php -- invalidateTags(tags)
CacheTagsInvalidator.php         -- delegates to all registered TagAwareCacheInterface bins
Backend/
    MemoryBackend.php            -- in-memory, tag-aware (use for tests)
    DatabaseBackend.php          -- PDO-backed, auto-creates table, tag-aware
    NullBackend.php              -- no-op backend
Listener/
    EntityCacheInvalidator.php   -- entity:{type}, entity:{type}:{id}
    ConfigCacheInvalidator.php   -- config, config:{name}
    TranslationCacheInvalidator.php
```

### packages/database-legacy/src/

```
DatabaseInterface.php            -- select/insert/update/delete/schema/transaction/query
DBALDatabase.php                 -- implements DatabaseInterface, wraps Doctrine DBAL Connection
SelectInterface.php              -- fluent select builder
InsertInterface.php              -- fluent insert builder
UpdateInterface.php              -- fluent update builder
DeleteInterface.php              -- fluent delete builder
SchemaInterface.php              -- DDL operations (createTable, addField, etc.)
TransactionInterface.php         -- commit/rollBack
DBALTransaction.php              -- DBAL transaction wrapper
Query/
    DBALSelect.php               -- SELECT with joins, conditions, ordering, pagination
    DBALInsert.php               -- INSERT with field inference from values
    DBALUpdate.php               -- UPDATE with conditions
    DBALDelete.php               -- DELETE with conditions
Schema/
    DBALSchema.php               -- DDL implementation via Doctrine DBAL
```

### packages/http-client/src/

```
HttpClientInterface.php          -- request/get/post contract
HttpResponse.php                 -- readonly DTO: statusCode, body, headers, json(), isSuccess()
StreamHttpClient.php             -- file_get_contents + stream context implementation
HttpRequestException.php         -- thrown on request failure
```

### packages/queue/src/

```
QueueInterface.php               -- push/pop/acknowledge contract
DbalQueue.php                    -- DBAL-backed persistent queue
InMemoryQueue.php                -- in-memory queue for testing
MessageBusQueue.php              -- Symfony Messenger bridge
SyncQueue.php                    -- immediate synchronous execution
Job.php                          -- job value object
Worker/
    Worker.php                   -- processes jobs from queue
    WorkerOptions.php            -- max jobs, memory limit, sleep, timeout
Transport/
    TransportInterface.php       -- job serialization/deserialization
    DbalTransport.php            -- DBAL-backed transport
    InMemoryTransport.php        -- in-memory transport for testing
Handler/
    HandlerInterface.php         -- job handler contract
    JobHandler.php               -- default handler dispatch
Message/
    EntityMessage.php            -- entity lifecycle async message
    ConfigMessage.php            -- config change message
    GenericMessage.php           -- arbitrary payload message
Storage/
    DatabaseFailedJobRepository.php  -- DBAL-backed failed job store
    InMemoryFailedJobRepository.php  -- in-memory failed job store
FailedJobRepository.php          -- failed job base class
FailedJobRepositoryInterface.php -- failed job tracking contract
QueueServiceProvider.php         -- registers queue services
AttributeGuard.php               -- enforces job attributes at runtime
BatchedJobs.php                  -- parallel job group
ChainedJobs.php                  -- sequential job chain
Attribute/
    OnQueue.php                  -- #[OnQueue('name')] route to specific queue
    RateLimited.php              -- #[RateLimited] rate-limit job execution
    UniqueJob.php                -- #[UniqueJob] prevent duplicates
Migration/
    CreateQueueTables.php        -- creates queue_jobs + failed_jobs tables
```

## Symfony decoupling (mission 1107)

Mission 1107-api-symfony-decoupling introduces three Waaseyaa-owned framework surfaces wrapping Symfony primitives. Per the ratified Path R-narrow charter, the mission scope is **HTTP request/response and event-dispatch only** — routing internals stay Symfony-coupled.

### Public types (app code)

| Waaseyaa surface | Wraps | Ratified contract |
|---|---|---|
| `Waaseyaa\Foundation\Http\Request` | `Symfony\Component\HttpFoundation\Request` (class alias) | C-002 (a) |
| `Waaseyaa\Api\Http\JsonApiResponse` | `Symfony\Component\HttpFoundation\JsonResponse` (subclass) | C-001 (a) |
| `Waaseyaa\Foundation\Event\EventDispatcherInterface` | PSR-14 + Symfony-style listener / subscriber methods | C-003 (a) |

The default binding for `EventDispatcherInterface` is `Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter`, instantiated by `AbstractKernel::boot()` and exposed via `getEventDispatcher(): EventDispatcherInterface`. The adapter implements both Waaseyaa's interface and Symfony's component `EventDispatcherInterface`, so framework-internal call sites that still type-hint Symfony continue to accept the same instance — the abstraction added without forcing a foundation-wide type-hint sweep.

### Framework-internal contract (C-003)

`Waaseyaa\Foundation\Event\DomainEvent` continues to `extends \Symfony\Contracts\EventDispatcher\Event`. Only the dispatcher gains a Waaseyaa-owned interface; event subclasses still inherit Symfony's `Event` via `DomainEvent`. This is an **explicit framework-internal contract**: the Symfony parent class is part of foundation's stable surface and changing it is a major-version concern. Subscriber-discovery semantics also stay Symfony-typed (`Symfony\Component\EventDispatcher\EventSubscriberInterface`) — the mission's surface-area calculus deliberately keeps these standard so existing subscriber idioms work unchanged.

This closes drift flag **D4** ("DomainEvent extends Symfony Event is an undocumented public surface") raised in the mission decomposition.

### Trait ownership (amended C-004)

The canonical JSON:API response trait is `Waaseyaa\Foundation\Http\JsonApiResponseTrait`. Its 9 in-package consumers (HttpKernel, ControllerDispatcher, 7 routers) plus 4 cross-layer consumers (graphql, ssr×2, media) make it the natural canonical home. The previous duplicate `Waaseyaa\Api\JsonResponseTrait` (a plain JSON helper, not a JSON:API helper) was deleted as orphan; api consumers that need typed responses now construct `Waaseyaa\Api\Http\JsonApiResponse` directly. L4 → L0 imports of foundation's trait are allowed by the layer rule.

### Out of scope (Path R-narrow)

- **Routing decoupling.** `Symfony\Component\Routing\Route` continues to leak through `RouteBuilder` public signatures. App code that registers routes via service providers will still import Symfony's Route after this mission lands. Filed as a follow-up mission `routing-symfony-decoupling` (referenced from `#1107` at acceptance).
- **Bimaaji decoupling.** Independent surface; out of scope for this mission.
- **Symfony-import boundary linter.** Per ratified C-005 (b), the `bin/check-symfony-imports` script is deferred to a follow-up issue — the soft-rot tradeoff is documented there. Until that linter ships, the mission's executable contract is `packages/api/tests/Contract/SymfonyImportBoundaryTest`, which asserts a sample app-controller fixture produces a JSON:API response without `use Symfony\` imports.

## Implementation gotchas

- **Backward-compatible cache evolution**: When adding new properties to cached manifests/configs, make them optional in deserialization (use `$data['key'] ?? []`) to avoid breaking old cached files.
- **`PackageManifestCompiler` prefers optimized autoloader**: `scanClasses()` tries `autoload_classmap.php` first, then falls back to PSR-4 directory scanning with a warning log. The classmap under default `composer install` has Composer/polyfill entries but no `Waaseyaa\` classes — the fallback triggers on missing Waaseyaa entries, not an empty classmap. Run `composer dump-autoload --optimize` for faster, more reliable discovery.
- **Kernel Bootstrap directory**: Extracted bootstrappers live in `packages/foundation/src/Kernel/Bootstrap/` — `DatabaseBootstrapper`, `ManifestBootstrapper`, `ProviderRegistry`, `AccessPolicyRegistry`. AbstractKernel delegates to these.
- **Debug boot guard**: `AbstractKernel::boot()` throws `RuntimeException` if `isDebugMode()` is true and `isDevelopmentMode()` is false. Both methods and the boot guard use `resolveEnvironment()` as the single canonical source for `APP_ENV` resolution. Tests that boot with `debug => true` in config must also set `APP_ENV=local`.
- **Kernel boot flag ordering**: `AbstractKernel::boot()` sets `$this->booted = true` *after* all initialization steps succeed. Setting it before would create a zombie state where boot failure prevents retry. If adding new boot steps, add them before the flag assignment.
- **Migration system boot order**: `bootMigrations()` runs after `compileManifest()` (requires `PackageManifest`) and before `discoverAndRegisterProviders()`. It reuses the DBAL `Connection` from `DBALDatabase` (via `getConnection()`) — single connection, no duplication.
- **`ServiceProvider` has no `$dispatcher` property**: Event subscriber registration must resolve the dispatcher via `$this->resolve(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)` and check `instanceof Symfony\Component\EventDispatcher\EventDispatcherInterface` before calling `addSubscriber()`.
- **`RateLimiter`: check before hit**: Always call `tooManyAttempts()` BEFORE `hit()`. Calling `hit()` first counts the current request before checking, reducing the effective limit by 1.

<!-- Spec reviewed 2026-05-17 - dead-code baseline reduction (#1493 / PR TBD): @api PHPDoc sweep on extension-point classes + WaaseyaaEntrypointProvider extended to recognize EntityBase/ContentEntityBase subclasses and their traits. No behavioural change. -->

<!-- Spec reviewed 2026-05-17 - dead-code Phase 3 Bucket 4: @api PHPDoc sweep on additional public-API classes. No behavioural change. -->

<!-- Spec reviewed 2026-05-18 - WP07 (agent-executor mission) rebase + rewire: no behavioural change to this subsystem; touch refreshes drift-detector timestamp. -->
