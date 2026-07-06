# API Layer

<!-- Spec reviewed 2026-07-06 - CW-v1 WP-0 (#1920, #1927, security): closes a self-publish gap where an account holding only edit/create permissions (no publish permission) could set a node live — either explicitly via `status`/`workflow_state` in the request body, or implicitly through the entity constructor's born-published default (`Node::__construct` defaults `status = 1`). Two-part fix: (1) `NodeAccessPolicy::fieldAccess()` now edit-Forbids `status`/`workflow_state` for any account lacking the new `NodeAccessPolicy::PUBLISH_PERMISSION` constant (`'use editorial transition publish'`), on BOTH create and update — no `isNew()` carve-out, unlike the `uid`/`type`/`created`/`changed` admin-only-edit gate documented in field-access.md; `promote`/`sticky` remain ungated pending the editorial engine. (2) `JsonApiController::store()` adds an explicit floor: when the client omits `status` from the create payload AND the constructor-defaulted entity already has a non-null `status` AND the acting account is field-edit-Forbidden on `status`, the controller sets `status = 0` before save, so a create cannot silently inherit a published default the account could not have set explicitly. A client-supplied `status` value is unaffected by this floor — it still goes through the existing per-attribute access-check loop above (Forbidden → 403), unchanged. This is the WP-0 slice of the CW-v1 content-workflow initiative; `docs/specs/content-workflow.md` (tracking the full editorial state machine) has not merged yet, so this note is the interim record — the WP-0 status row there should be flipped to reflect this once that spec lands. See CHANGELOG "Security" and #1915/R16 batch context. -->
<!-- Spec reviewed 2026-07-06 - audit-remediation batch R15 (security, audit A11; structural sibling of R14, closing the residual the R14 entry flagged): EntityResolver::resolveList() accepted a filter/sort on ANY field-name string. The R14 gate only fires for a field a dynamic FieldAccessPolicy Forbids, so two STRUCTURAL classes REST's validateQueryFields() rejects were live GraphQL oracles: (1) an undeclared _data JSON key (no policy -> Neutral -> not Forbidden) resolved to json_extract(_data,'$.<field>') (SQL injection itself contained by JsonFieldName::assertQueryable, R2 WP1) and became a filter-presence/sort-rank oracle over arbitrary blob keys; (2) a declared field flagged settings['internal']=>true (User.two_factor_secret, OidcClient.client_secret_hash) plus the credential floor (pass/password/password_hash) -- internal is a settings flag, not a policy, so R14 never fired. The /graphql route is allowAll() (public), so the oracle was reachable anonymous-and-up over any entity-viewable row. Fixed by porting the REST allowlist: EntityResolver::assertQueryableFields() runs at the top of resolveList() before any storage query, throwing UserError for any filter/sort field that is not a declared field or entity key, is in ALWAYS_INTERNAL_FIELDS, or has getSetting('internal')===true. Value/account-independent. GraphQlDataBlobTest::testFilterOnNonExistentFieldReturnsEmpty (which documented the vulnerable silent-empty behavior) replaced by testFilterOnNonExistentFieldIsRejected. See "Field-access gate on filter/sort fields (audit R14)" subsection (GraphQL parity paragraph). Pinned by EntityResolverStructuralFieldAllowlistTest. -->
<!-- Spec reviewed 2026-07-05 - audit-remediation batch R14 (security, audit A11; class-mate of R13 WP1): a caller-supplied collection filter/sort on a field gated only by a dynamic FieldAccessPolicy (a classification/clearance field with no static internal flag) passed the R2 WP1 structural allowlist (validateQueryFields) yet was applied as a raw storage condition, so meta.total and the row set leaked the forbidden field's per-value count/ordering to a caller who may list the type and view its rows but lacks the field's clearance. The GraphQL sibling EntityResolver::resolveList() had the identical gap on its filter-argument path. Fixed with two value-independent fail-closed gates: (1) FILTER/data — JsonApiController::index() excludes a row from BOTH the page and accessFilteredTotal() when any filter/sort field is view-Forbidden (queryFieldForbidden()), and EntityResolver::resolveList() does the same in its count and item loops via GraphQlAccessGuard::isFieldViewForbidden(); (2) SORT — because QueryApplier runs sort()+range() in storage before the drop, a Forbidden row still occupies a pagination rank (empty-vs-populated slot across offsets = ordering oracle, found in adversarial review, missed by the all-rows-forbidden tests), so a sort on a field view-Forbidden on any viewable matched row is REJECTED (JsonApiController::rejectForbiddenSort() -> 400; EntityResolver::rejectForbiddenSort() -> UserError). See new "Field-access gate on filter/sort fields (audit R14)" subsection under Query Pipeline. Reported class-mate left out of scope (R2, not R14): EntityResolver::resolveList() still has no structural allowlist on filter/sort field names. Pinned by JsonApiControllerFieldFilterOracleTest and EntityResolverFieldFilterOracleTest. -->
<!-- Spec reviewed 2026-07-05 - audit-remediation batch R13 WP2 (security, audit A11): text_long ("richtext") field values were never sanitized server-side, so an authenticated author's saved script/event-handler markup round-tripped unmodified through ResourceSerializer::castAttributes() (JSON:API, admin-surface, SSR Markdown), GraphQL's EntityTypeBuilder plain-field resolver, and FieldAutoSaveController's echoed response, then executed in another admin's session via the v-html richtext sink in the admin SPA (SchemaView.vue). Fixed with a single shared Waaseyaa\Api\Sanitizer\RichTextSanitizer (packages/api/src/Sanitizer/RichTextSanitizer.php), applied non-lossily at the read/serialization boundary only (stored bytes are left unchanged) at all three chokepoints. See new "Richtext Sanitization" subsection under Resource Serialization below. -->
<!-- Spec reviewed 2026-07-05 - audit-remediation batch R8 WP2 (security, audit R8-c): `DiscoveryRouter::handleTopicHub()`/`handleCluster()`/`handleTimeline()` did not gate the SOURCE entity's own view access before doing any work — `handleEndpoint()` already did (`loadDiscoveryEntity()` + `isDiscoveryEntityPublic()` before building the discovery service), but the other three read the cache and built the discovery service straight after param validation, so a caller who could not view the source entity still got a 200 (or a cached 200) with hub/cluster/timeline data: an existence/access oracle. Fixed by adding the identical gate (`$resolvedEntity === null || !isDiscoveryEntityPublic($resolvedEntity, $ctx->account)` → 404) to all three, placed BEFORE the cache read so a denied/absent source is never even consulted against the cache, matching `handleEndpoint()`'s ordering. The 404 is intentionally indistinguishable between "source does not exist" and "source exists but is not viewable" — same status, same generic detail shape (`"Discovery <action> not publicly visible: <type>:<id>"`), consistent with `handleEndpoint()`'s existing "not publicly visible" phrasing which already conflates the two cases. `DiscoveryCachePrimitives::CACHE_KEY_GENERATION` bumped 2->3 to orphan every pre-fix cached hub/cluster/timeline 200 for a now-gated source (the new gate runs before the cache read going forward, so this only clears the pre-fix backlog). See "Discovery API Handler" section below (updated). Pinned by `DiscoveryRouterTest` (hub/cluster/timeline restricted-source-404, absent-source-404, indistinguishability, and viewable-source-200 positive-control cases). -->
<!-- Spec reviewed 2026-07-04 - audit-remediation batch R7 WP1 (entity label/title field-access channel): `EntityMarkdownPresenter::present()`'s H1 (`# ' . label . '\n`) was built from `$entity->label()` directly, bypassing field-level access even though the rest of the document is filtered through the required `$accessHandler`/`$account` (see the WP4 entry below). A viewable entity whose label-key field is Forbidden still leaked the real title into the H1. Fixed by calling `$accessHandler->viewableLabel($entity, $account, $this->entityTypeManager)` (new method, see field-access.md) and falling back to the entity type id when it returns null. Same fix applied at the SSR HTML `<title>` and schema.org JSON-LD `name` sites — see docs/specs/seo.md and CHANGELOG "Security". Public `present()` signature unchanged. Pinned by `EntityMarkdownPresenterTest::label_is_replaced_with_a_placeholder_when_the_label_field_is_forbidden`. -->
<!-- Spec reviewed 2026-07-05 - audit-remediation batch R7 WP2 (security, audit R5 residual #1): `DiscoveryApiHandler` gained a 4th constructor param `?EntityAccessHandler $accessHandler = null` (wired from `HttpKernel::finalizeBoot()`'s `$this->accessHandler`), so `createDiscoveryService()` (now `createDiscoveryService(AccountInterface $account)`) and `isDiscoveryEntityPublic()`/`isDiscoveryEndpointPairPublic()` (both gained an `?AccountInterface $account = null` param; `isDiscoveryEntityPublic()`'s signature also changed from `(string $entityType, array $values)` to `(EntityInterface $entity, ...)`) can gate a disclosed endpoint's identity on per-account 'view' access, additive to the pre-existing publish-status gate. `DiscoveryRouter` threads `$ctx->account` into all four `createDiscoveryService()` call sites plus the two `isDiscoveryEntityPublic()`/`isDiscoveryEndpointPairPublic()` call sites. `DiscoveryCachePrimitives::CACHE_KEY_GENERATION` bumped 1->2 (private, embedded in `buildKey()`'s hashed input, never in the response payload) to bust pre-fix anonymous discovery-cache entries immediately rather than waiting out the 120s TTL. See "Discovery API Handler" section below (updated) and relationship-modeling.md "Endpoint visibility (traverse and browse, fail-closed)" for the underlying `RelationshipTraversalService` mechanism. -->
<!-- Spec reviewed 2026-07-02 - audit-remediation batch R2 WP4 (api M4, two sibling ResourceSerializer per-account-filter-skip leaks): `ResourceSerializer::serialize()` applies the dynamic per-account `FieldAccessPolicyInterface` filter only when BOTH `$accessHandler` and `$account` are non-null; with both null it does only the static internal-field strip. Two call sites serialized with a null account and leaked entity-view-restricted fields. (1) SSR Markdown: `EntityMarkdownPresenter::present()` documented a per-account field-access guarantee that was false because its only caller, `SsrPageHandler::renderEntityMarkdown()`, always passed `$accessHandler = null, $account = null`. Fixed by making `present()`'s `$viewMode`/`$accessHandler`/`$account` REQUIRED and non-nullable (guarantee enforced structurally — a null argument is now a `TypeError`, not a silent bypass); `renderEntityMarkdown()` takes the viewing `AccountInterface` and threads it + `$this->accessHandler` into `present()`, failing closed with a 500 when the handler is null (unreachable in production — `SsrServiceProvider` wires it from the kernel's non-nullable `getAccessHandler()`). (2) Translation CRUD: `TranslationController::index()`/`show()`/`store()`/`update()` (packages/api/src/Controller/TranslationController.php, the four `serialize($translation)` sites at lines 63/104/184/252 pre-fix) serialized with no handler/account, leaking restricted fields to any account with entity view/create/update access. Fixed by resolving the account the same way `checkAccess()` does (`$request->attributes->get('_account')`, guarded non-null) and threading `$this->accessHandler` + that account into all four `serialize()` calls (store/update hoist the already-resolved account so the field-edit gate and the response filter share one account). Acceptance: `EntityMarkdownPresenterTest::access_filtering_omits_forbidden_fields_identically_to_serializer` + `present_throws_when_called_with_a_null_account_or_handler` and `tests/Integration/Phase29/TranslationFieldAccessTest.php` (packages/api), `SsrEntityMarkdownAccessTest` (packages/ssr). -->
<!-- Spec reviewed 2026-07-02 - audit-remediation batch R2 WP2 (api M2, unauthenticated discovery leaks unpublished identities): the four `DiscoveryRouter` handlers (hub/cluster/timeline/endpoint) forwarded a raw `?status=` value into `RelationshipTraversalService::browse()`, where `status=all` is the system-context spelling that bypasses the per-account endpoint-visibility filter, so an anonymous caller could pass `?status=all` (or `?status=unpublished`) and receive unpublished/private related-entity identities. New `DiscoveryRouter::resolveDiscoveryStatus()` clamps any requested status other than `published` back to `published` unless the account `isAuthenticated()` AND `hasPermission('administer nodes')` (the same admin bypass `RelationshipAccessPolicy` uses); applied at all four sites, the endpoint-pair path reuses the clamped value. `published` mode remains endpoint-visibility-safe via the live `WorkflowVisibilityFilter`. Discovery envelope/edge shapes unchanged; extends the alpha245/WP06 discovery-visibility line below. Account-gating rationale recorded in relationship-modeling.md "Discovery Surfaces Contract". -->
<!-- Spec reviewed 2026-07-02 - audit-remediation batch R2 WP1 (anonymous SQL injection via unvalidated JSON:API filter/sort field name, the audit headline): `JsonApiController::rejectInternalQueryFields()` (deny-list only) is replaced by `validateQueryFields()` (allowlist: resolveFieldDefinitions() keys UNION entity-type getKeys(), with the same internal/credential rejection layered on top). See new "Filter/sort field allowlist (audit R2 WP1)" section under Query Pipeline. Storage-side sink guard documented in docs/specs/entity-system.md "SQL identifier hardening". -->
<!-- Spec reviewed 2026-07-02 - audit-remediation batch 2026-07-02 R2, WP3 (api M3): SchemaController::show() now (a) SEEDS the field-access prototype with a placeholder for every declared field + entity key so constructor-strict types (UserBlock, engagement Comment, messaging thread types) keep a working filtered schema endpoint, and (b) fails CLOSED (500, no schema) as a last-resort backstop only when construction STILL throws after seeding — instead of the pre-fix fall-through to SchemaPresenter::present() with a null $entity, which silently skipped ALL field-access filtering and emitted an unfiltered schema (see CHANGELOG [Unreleased] Security). Updated the "SchemaController" paragraph (line ~556) and added a new caveat under "Schema field-access caveat (D-16)" (line ~940). Success path unchanged; only the prototype-construction-failure branch changes, and only for a type that throws even with all fields seeded (200-with-unfiltered-schema → 500-with-no-schema). -->
<!-- Spec reviewed 2026-06-29 - WP5 foundation wave-2 (route-table inversion): the 14 Waaseyaa\Api\* route groups previously hard-coded in BuiltinRouteRegistrar moved to ApiServiceProvider::routes() via the existing provider loop. Route names, paths, methods, and access gates are UNCHANGED — behavior-neutral. The spec body has been updated at the "Package-owned route registration" paragraph (line ~61), the "Codified context session endpoints" paragraph (line ~65), the "MCP-admin REST surface" paragraph (line ~71), and the "Schema self-description surface requires authentication" paragraph (line ~930) to reflect that routes now originate from ApiServiceProvider::routes() rather than BuiltinRouteRegistrar. -->
<!-- Spec reviewed 2026-06-23 - §3.4 residual-security-sweep (GateAttribute false @api promise): #[GateAttribute]'s docblock claimed the AccessChecker reads the attribute and enforces the Gate, but nothing scans it in production (routes are built programmatically; no controller-attribute scanner exists), so a route relying on it was unprotected. Resolved by adding the real RouteBuilder::gate() setter (sets the _gate option AccessChecker::checkGate already enforces) and correcting+deprecating the attribute docblock to stop the false claim. _gate enforcement path unchanged. -->
<!-- spec reviewed 2026-06-23: graphql internal-field schema-drop (§2.3 residual-security-sweep); output builder now mirrors ResourceSerializer internal-field filter. No contract change to documented behavior. -->
<!-- Spec reviewed 2026-06-22 - WP06 (alpha245 security, audit #36): `DiscoveryApiHandler::createDiscoveryService()` built `RelationshipTraversalService` with no `VisibilityFilterInterface`, which (combined with the service's then fail-open default) leaked related-entity labels/paths for unpublished endpoints through the discovery API. It now passes `WorkflowVisibilityFilter` so related entities are gated on publication state; the traversal service itself now fails closed without a filter (see relationship-modeling.md). Discovery envelope/edge shapes unchanged. -->
<!-- Spec reviewed 2026-06-21 - issue #1704 (CL-12): `MercureMonitorController::events()` SSE stream is now BOUNDED. It previously looped on `while (connection_aborted() === 0)` with no time-budget cap — the same missed-disconnect worker-pin class BroadcastRouter fixed (a never-surfaced disconnect under FrankenPHP worker mode pinned the worker indefinitely). The stream now: releases the PHP session lock (`session_write_close()`), clears `ignore_user_abort(false)`, re-probes the abort signal immediately after each event/keepalive write, and exits on disconnect OR a per-connection time budget (new `DEFAULT_MAX_DURATION_SEC` = 30s) — whichever comes first. New optional ctor params (`maxDurationSec`/`keepaliveIntervalSec`/`pollIntervalUs`/`clock`/`abortSignal`, all defaulted) make the bound injectable; the continuation rule is the pure static `MercureMonitorController::streamShouldContinue(abortStatus, elapsedSec, maxDurationSec)`. Frame shape (id/event/data, 15s keepalive, channels) and the null-stream `disabled` frame are unchanged. Acceptance: MercureMonitorControllerTest. -->
<!-- Spec reviewed 2026-06-15 - B-3 (security): JsonApiController::index() now returns 400 for a collection query that filters or sorts on an internal field (FieldDefinition settings['internal'] => true) or an ALWAYS_INTERNAL_FIELDS credential key (pass/password/password_hash), via rejectInternalQueryFields() applied before BOTH the count and main queries. This mirrors the response-side internal-field policy (see "Filters internal/credential fields") onto the query side, closing a value-enumeration oracle where an anonymous collection request could filter on a never-serialised field and read match/no-match. Filtering/sorting on ordinary non-internal fields is unchanged. Pinned by JsonApiControllerInternalFieldQueryTest. -->

<!-- Spec reviewed 2026-06-12 - mission optimistic-locking-01KTXCHY WP03 (#1647): new "Conditional update — optimistic locking" section under the JSON:API controller. PATCH accepts `data.meta.expected_revision_id` (positive integer; invalid → 400, non-single-axis-revisionable type → 422; If-Match explicitly NOT supported — headers don't reach the controller; additive follow-up may map it onto the same SaveContext seam). Stale expectation → 409 `code: REVISION_CONFLICT` with `meta {expected_revision_id, current_revision_id}` (`current_revision_id` null = no readable head); the codeless data.id-vs-uuid 409 keeps its shape — `code` is the discriminator. An expectation-stated PATCH persists through the revision-aware repository pipeline (cuts a revision, dispatches repository lifecycle events; repository EntityValidationException and the storage LogicException rejection backstop both map to 422); a no-expectation PATCH is byte-identical to before. JsonApiError gains the additive `meta` ctor member (emitted only when non-empty) and `conflict()` gains optional code/meta passthrough — class snippet updated. `revision_id` on reads of revisionable types is now documented LOAD-BEARING (FR-008, pinned by JsonApiControllerConflictTest) — removing/renaming it is a consumer break. -->
<!-- Spec reviewed 2026-06-12 - mission request-surface-hardening-01KTX7F2 WP03 (#1649): two consumer-visible hardening changes. (1) Discovery filtering — `ApiDiscoveryController` gains an optional `?AccountInterface $account = null` ctor param (passed by `DiscoveryRouter::handle()` from `WaaseyaaContext::fromRequest($request)->account`, the `_account` attribute); per-type links are emitted only when `$account?->isAuthenticated() === true` (anonymous/absent account → envelope only, zero type links), and definitions whose duck-typed `isDiscoverable()` returns false are absent for EVERY caller (the new `EntityType` `discoverable: bool = true` flag; `EntityTypeInterface` deliberately not widened). Route stays `_public`/allowAll. No categorical per-type view check exists in the access API — authenticated-only is the documented fallback (research D1), NOT per-account type filtering. (2) Denied-as-404 — `JsonApiController::show()` returns the canonical not-found document for a view-denied entity, byte-identical to the missing-id response for the same probe (single private `notFoundDocument()` factory, no `code` member, no debug variant; NFR-002 pinned by `JsonApiControllerDeniedNotFoundTest`). Mutations keep genuine 403s (FR-004). Known boundary: `/api/entity-types`, `/api/openapi.json`, `/api/schema/{entity_type}` still enumerate anonymously — see "Adjacent enumeration surfaces". -->
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

`Waaseyaa\Api\ApiServiceProvider` is declared in `packages/api/composer.json` under `extra.waaseyaa.providers`. Its `routes()` method is now the authoritative entry point for all API-package routes, including CRUD (via `JsonApiRouteProvider`), schema self-description, workflow/queue/scheduler/notification admin, telescope endpoints, Mercure monitor, OCAP audit, MCP-admin, OIDC client CRUD, media versions, and classification policies (WP5 route-table inversion, foundation wave-2).

Foundation registers only the framework-substrate routes it owns (regardless of which higher-layer packages are installed): `/api/openapi.json`, entity-type catalog/lifecycle, broadcast SSE, media upload, attachment download, semantic search, four discovery endpoints, and the SSR catch-alls. **Discovery** read models are implemented in the API package: `Waaseyaa\Api\Http\Router\DiscoveryRouter` implements `DomainRouterInterface` and is registered from `ApiServiceProvider::httpDomainRouters()` so discovery stays co-located with `DiscoveryApiHandler` and JSON:API tooling.

### Codified context session endpoints

`CodifiedContextController` exposes operator JSON for **agent-context** (codified-context) **session** telemetry. Canonical paths are under `GET /api/telescope/agent-context/sessions` (see **`docs/specs/telescope-agent-context-telemetry.md`**). **`ApiServiceProvider::routes()`** also registers legacy `/api/telescope/codified-context/…` aliases for the same controller actions (moved from `BuiltinRouteRegistrar` in WP5). Dispatch is handled by **`Waaseyaa\Foundation\Http\Router\CodifiedContextApiRouter`**, which constructs the controller with an optional **`CodifiedContextSessionStoreInterface`** from **`HttpKernel::getCodifiedContextSessionStore()`** (defaults to null until a host wires the store via **`HttpKernel::setCodifiedContextSessionStore()`**). The controller type-hints the port only; row shapes use **`CodifiedContextSessionRow`** (`packages/api/src/CodifiedContext/`). **`Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextSessionStoreAdapter`** bridges Telescope’s SQLite codified-context store to the port; `waaseyaa/telescope` `require`s `waaseyaa/api` for those interfaces. Unit tests may register an in-memory or stub store without loading Telescope.

**Response JSON (admin SPA):** Successful payloads use **camelCase** keys aligned with the admin composable **`useCodifiedContext`** (`packages/admin/app/composables/useCodifiedContext.ts`): session list and `GET …/sessions/{id}` return objects with `sessionId`, `startedAt`, `endedAt`, `durationMs`, `eventCount`, `latestDriftScore`, `latestSeverity`, etc. (timestamps as ISO-8601/RFC3339 strings). `GET …/events` returns rows with `eventType` (semantic type: stored `event_type` underscores mapped to dots, e.g. `context_load` → `context.load`), `createdAt`, and `data`. `GET …/validation` returns a single flat validation object (`driftScore` as 0–100 integer, `components`, `issues`, `recommendation`, `validatedAt`) — not a nested `report` envelope.

### MCP-admin REST surface

The MCP-admin read API powers the admin SPA's MCP-endpoint dashboard (M5C WP01, mission `mcp-endpoint-admin-01KSEFTL`). All three routes are registered in `ApiServiceProvider::routes()` with `_role: admin` (moved from `BuiltinRouteRegistrar` in WP5); the controller does **not** re-check the role (NFR-001 / DIR-004).

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
        public string $code = '',
        public array $source = [],
        public array $meta = [],   // additive (#1647) — JSON:API error-object `meta`
    ) {}

    public function toArray(): array;

    // Static factories:
    public static function notFound(string $detail = ''): self;      // 404
    public static function forbidden(string $detail = ''): self;     // 403
    public static function unprocessable(string $detail = '', array $source = []): self;  // 422
    public static function badRequest(string $detail = ''): self;    // 400
    public static function conflict(string $detail = '', string $code = '', array $meta = []): self;  // 409
    public static function internalError(string $detail = ''): self; // 500
}
```

The `meta` member (added by #1647, mission optimistic-locking-01KTXCHY) is a
trailing ctor param emitted by `toArray()` **only when non-empty**, so every
pre-existing error response is byte-identical. `conflict()` gained optional
`code`/`meta` passthrough the same way: the pre-existing `data.id`-vs-uuid 409
keeps its codeless shape, and `code` is the machine-readable discriminator
between the two 409s (see "Conditional update" below).

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
6. **Post-fetch access filter**: if access handler is available, filters entities where `$accessHandler->check($entity, 'view', $account)->isAllowed()` is false, and (audit R14) where any caller-supplied filter/sort field is view-`Forbidden` for that entity (`queryFieldForbidden()`).
7. Serializes via `$serializer->serializeCollection()`.
8. Applies sparse fieldsets if `fields[type]` is in the query via `SparseFieldsetApplicator::apply()` (filters both `attributes` and `relationships` per JSON:API).
9. Generates pagination links and meta (`total`, `offset`, `limit`).
10. Returns `JsonApiDocument::fromCollection()`.

**`show(string $entityTypeId, int|string $id, array $query = []): JsonApiDocument`**

1. Loads entity by ID or UUID via `loadByIdOrUuid()`. A missing entity returns the canonical not-found document.
2. Checks view access (`EntityAccessHandler::check($entity, 'view', $account)` — the check still runs; the result's reason is never surfaced). **A denied view returns the same canonical not-found document as a missing id** — byte-identical body for the same `(entityTypeId, id)` probe: status `'404'`, title `'Not Found'`, detail `"Entity of type '<type>' with ID '<id>' not found."`, **no `code` member** (the old `403` + `code: FORBIDDEN` shape is gone — #1649). Both branches call one private `notFoundDocument(string $entityTypeId, int|string $id)` factory, so the bytes cannot drift apart; the pin test `JsonApiControllerDeniedNotFoundTest` asserts `json_encode` equality of the two documents plus equal status codes (NFR-002). The denied entity is **never serialized**. There is no debug/development variant — the 404 is uniform in all environments (mission request-surface-hardening research D3). Headers are identical by construction: both documents exit through the single `jsonApiResponse()` emitter in `JsonApiRouter`.
3. Serializes via `$serializer->serialize()` (allowed entities unchanged).
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
2. Parses the optional `data.meta.expected_revision_id` expectation (see "Conditional update" below): invalid value → 400; type not single-axis revisionable → 422. Both screens are type-level (definition reads only) — no entity state is revealed before the access check.
3. Checks update access at entity level.
4. Checks field edit access for each submitted attribute.
5. Applies updates via `$entity->set($field, $value)` (requires `FieldableInterface`).
6. Saves through `getRepository()->save($entity)` in both cases (C-22 WP3 unified the two save paths onto the canonical repository) — **without** an expectation, the plain form; **with** an expectation, `getRepository()->save($entity, context: SaveContext::default()->withExpectedRevisionId($n))` — and returns the updated resource.
7. **Both save paths catch `Doctrine\DBAL\Exception\UniqueConstraintViolationException` → 409** (added 2026-07-02, audit-remediation WP2 review — previously only `store()` had this mapping and a PATCH tripping a uniqueness constraint, e.g. the attachment one-active-per-parent partial index under a race, surfaced a raw 500 with driver SQL). Same status/title shape as `store()`'s duplicate-ID 409, codeless (so `code: 'REVISION_CONFLICT'` stays the discriminator for the optimistic-locking 409), detail `"Updating entity of type '<type>' with ID '<id>' violated a uniqueness constraint."` — names the REAL entity id, not the request locator (locator honesty, contract §15). Pinned by `JsonApiControllerConflictTest::patchWithoutExpectationMapsUniqueConstraintViolationTo409` / `::patchWithExpectationMapsUniqueConstraintViolationTo409`.

**`destroy(string $entityTypeId, int|string $id): JsonApiDocument`**

1. Loads entity, checks delete access.
2. Deletes via `getRepository()->delete($entity)` (C-22 WP3: canonical repository).
3. Returns `JsonApiDocument::empty(meta: ['deleted' => true], statusCode: 204)`.

### Denial responses per operation (#1649, FR-003/FR-004)

| Operation | Denied check | Response |
|---|---|---|
| GET single (`show`) | `view` not allowed | **404 not-found shape (changed — C-001)** |
| GET single, id does not exist | — | 404 not-found shape (unchanged; byte-identical to the denied case) |
| GET collection (`index`) | row-level filter | 200 with filtered `data[]` (unchanged; #1605 out of scope) |
| POST (`store`) | `createAccess` not allowed | 403 forbidden (unchanged) |
| PATCH (`update`) | `update` not allowed | 403 forbidden (unchanged) |
| DELETE (`destroy`) | `delete` not allowed | 403 forbidden (unchanged) |
| Field edit (store/update paths) | field forbidden | 403 forbidden (unchanged) |

FR-003's scope is deliberately the single read only — there is no blanket 404-ing of the API. Residual, accepted: a mutation (PATCH/DELETE) against a view-denied-but-existing entity still 403s, signalling existence to *authenticated* callers only (all mutation routes carry `requireAuthentication()`). An unknown entity type on any operation keeps its pre-existing distinct 404 (`"Unknown entity type: <type>."`) — it reveals only that a *type* is unregistered, which the discovery surface governs.

### Conditional update — optimistic locking (#1647, FR-006/FR-008)

Mission `optimistic-locking-01KTXCHY`. Canonical contract:
`kitty-specs/optimistic-locking-01KTXCHY/contracts/conflict-surfaces.md`. The
controller translates the storage contract (`revision-system-unified.md` §3b);
it implements no conflict check of its own.

**Request seam — resource-object meta, not `If-Match`.** The expectation rides
the PATCH body:

```json
{ "data": { "type": "<type>", "attributes": { "...": "..." },
            "meta": { "expected_revision_id": 5 } } }
```

Headers do not reach `JsonApiController` (`WaaseyaaContext` carries
`account/parsedBody/query/method` — no headers), so `If-Match`/ETag is
**explicitly not part of this contract**. A future additive change may map
`If-Match` onto the same `SaveContext` seam without altering the body seam.

**Request-state table:**

| Request state | Response |
|---|---|
| `expected_revision_id` absent (or no `data.meta` at all) | (superseded 2026-07-01, C-22 WP3) update applies through the SAME `getRepository()->save($entity)` pipeline as the "head matches" row below, just without the `SaveContext::withExpectedRevisionId()` guard — same checks, same responses, same events; a revision IS now cut for revisionable types (previously routed through the legacy `getStorage()->save()` path and did not cut one) |
| present, not a positive integer | 400 `Bad Request` |
| present, type not single-axis revisionable | 422 `Unprocessable Entity` (controller screen; the storage `\LogicException` rejection matrix remains the invariant backstop, also mapped to 422 — never a 500) |
| present, head moved | **409** — body below |
| present, head matches | update applies through `getRepository()->save(…, context:)` — the revision-aware repository pipeline; 200 with the updated resource (attributes include the new `revision_id`) |
| present, repository validation fails | 422 (`EntityValidationException` mapped) |

**Stated plainly: a PATCH on a revisionable type now ALWAYS cuts a new
revision and dispatches the repository lifecycle events**, whether or not an
expectation is stated — C-22 WP3 removed the separate legacy `getStorage()`
save path entirely. Only the `SaveContext::withExpectedRevisionId()` guard
(and the resulting `RevisionConflictException` possibility) is conditional on
whether the caller stated an expectation.

**409 body** (the `meta` member is the new additive `JsonApiError` field):

```json
{ "errors": [ { "status": "409", "title": "Conflict",
    "code": "REVISION_CONFLICT",
    "detail": "Entity of type '<type>' with ID '<id>' was modified: expected revision 5, current revision is 6.",
    "meta": { "expected_revision_id": 5, "current_revision_id": 6 } } ] }
```

`current_revision_id` is `null` when no readable head exists (the row vanished
concurrently, or a pre-backfill row carries no revision pointer). Deterministic
and assertable: the two revision ids plus static identity, no timestamps
(NFR-003). **409 catalogue:** this controller now emits three 409 shapes — the
pre-existing codeless `data.id`-vs-uuid mismatch, the codeless
uniqueness-constraint trip on create/update saves (2026-07-02, WP2 review —
see `update()` step 7 above), and this one; `code: 'REVISION_CONFLICT'` is
the machine-readable discriminator. **Locator honesty:** uuid-routed PATCHes
resolve to the real entity id before the save; the conflict payload names the
real id, not the request locator.

**`revision_id` is a load-bearing read attribute (FR-008).** `GET
/api/{type}/{id}` (and collection reads) emit `revision_id` as an attribute on
revisionable types — the serializer excludes only the id/uuid keys, so the
base row's pointer column rides `toArray()`. This was previously incidental;
it is now pinned by test (`JsonApiControllerConflictTest`) and documented
load-bearing: **removing or renaming it is a consumer break** — it is the
value expectation-forming clients read. The conflict body's
`meta.current_revision_id` is itself a read: the re-read-and-retry loop can
skip a round-trip.

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
        ?RichTextSanitizer $richTextSanitizer = null,
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
5. Applies field-definition coercions (`boolean`, `timestamp` / `datetime`, `text_long`): timestamps accept integers or `DateTimeInterface` (e.g. after a `datetime_immutable` cast); a `text_long` value is run through `RichTextSanitizer` (see "Richtext Sanitization" below).
6. Normalizes values to JSON-serializable shapes via **`EntityValues::normalizeValueForJson()`** (backed enums → backing value, `DateTimeInterface` → ISO-8601 `ATOM`, `JsonSerializable` → `jsonSerialize()` then recurse, arrays → recurse) — shared with `EntityValues::toJsonReadyMap()` for other presentation sinks (#1181 ST-10).
7. Generates a `self` link: `{basePath}/{entityTypeId}/{resourceId}`.

### Richtext Sanitization (R13 WP2, audit A11, SECURITY)

`text_long` is the only field type whose value is rendered as HTML by a server-side consumer (`SchemaPresenter::WIDGET_MAP` maps it to the `richtext` widget, and the admin SPA's `SchemaView.vue` renders any `x-widget === 'richtext'` field via `v-html`). No sanitizer was previously attached to it on either the write or the read path, so an authenticated author could store `<script>`/event-handler markup in a `text_long` field and have it execute in another admin's session -- a cross-admin stored XSS.

The fix is a single shared class, `Waaseyaa\Api\Sanitizer\RichTextSanitizer` (`packages/api/src/Sanitizer/RichTextSanitizer.php`), wrapping `symfony/html-sanitizer`'s `HtmlSanitizerConfig::allowSafeElements()->forceHttpsUrls()` -- the same allowlist config `Waaseyaa\SSR\Formatter\HtmlFormatter` already uses for SSR rendering. It is applied **only at the read/serialization boundary, never at write time**, so the value as stored in the entity/database is left byte-for-byte as the author submitted it (non-lossy at rest):

- `ResourceSerializer::castAttributes()` sanitizes any attribute whose field type is in `RichTextSanitizer::HTML_FIELD_TYPES` (currently `['text_long']` only -- plain-text types like `string`/`text` are excluded, since they render as literal text and sanitizing them would corrupt legitimate content).
- `EntityTypeBuilder::buildOutputFields()` (GraphQL, `packages/graphql/src/Schema/EntityTypeBuilder.php`) wraps the plain-field resolver for a `text_long` field with the same sanitizer, covering both queries and the create/update mutation response (both resolve through the same field resolver). Safe to share one stateless `RichTextSanitizer` instance across the R12 per-process schema cache, since it carries no per-request/account state.
- `FieldAutoSaveController::update()` sanitizes the value it echoes back in its 200 response when the target field's type is `text_long`.

All three classes take an optional `?RichTextSanitizer` constructor parameter (default: a fresh instance, resolved in the constructor body), so every existing call site (JSON:API routers, `GenericAdminSurfaceHost`, `SsrPageHandler`'s Markdown presenter, `SchemaFactory`) is covered without a wiring change, and a caller with a container-resolved instance can inject one explicitly instead.

**Product trade-off (flagged for review, not an oversight):** `allowSafeElements()` permits a fixed set of "safe" elements/attributes (headings, paragraphs, lists, links, emphasis, tables, etc.) and strips everything else, including `<iframe>` embeds. An author who intentionally stored a non-"safe" embed will see it stripped on every serve.

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

Creates a prototype entity for field access checking when `accessHandler`/`account` are both supplied. To keep the endpoint available for entity types whose constructors require certain fields to be present (`isset()`-gated invariants — e.g. `UserBlock`'s `blocker_id`, engagement `Comment`'s `user_id`/`body`), `show()` **seeds** `$protoValues` with a non-null, type-appropriate placeholder (`0` for integer, `false` for boolean, `''` otherwise — presence, not value validity, is what constructor gates test) for every declared field (`resolveFieldDefinitions($entityTypeId, $bundle)`) and every entity key (`getKeys()`), plus the requested bundle key. **Fails closed** as a last-resort backstop (audit-remediation batch 2026-07-02 R2, WP3): if construction STILL throws after seeding, `show()` logs the failure via `LoggerInterface` at ERROR (entity type, class, exception message — server-side only) and returns a `JsonApiError::internalError()` 500 document with a generic detail; it does NOT fall through to `SchemaPresenter::present()` with a null entity. This matters because `present()`'s field-access-filtering block is itself gated on `$entity !== null` (see "Field Access Filtering" below) — passing a null entity there silently skips ALL per-account field filtering, which would emit an unfiltered schema instead of an error. In the non-access-context path (`accessHandler`/`account` both null, no account to filter for) the base schema is presented as before — that path is unaffected. Returns the schema in `meta.schema` of a `JsonApiDocument` on success.

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

### Filter/sort field allowlist (audit R2 WP1)

`QueryParser` takes the filter/sort field name straight from the raw query-string array key with **no validation** — `filter[<anything>]=value` or `sort=<anything>` is syntactically accepted. `JsonApiController::index()` therefore validates every parsed filter/sort field **before** either the count query or the main query runs, via `JsonApiController::validateQueryFields()`:

- **Allowed** — a field name that is either a key of `EntityTypeManagerInterface::resolveFieldDefinitions($entityTypeId)` (every declared `#[Field]`, class-declared base field, and registry/bundle field) **or** one of the entity type's structural keys (`EntityTypeInterface::getKeys()` — `id`/`uuid`/`label`/`bundle`/`langcode`/`revision`/...).
- **Rejected (400)** — any other field name, unconditionally. This includes a syntactically ordinary but never-declared `_data` key (previously silently accepted) and any field name carrying SQL metacharacters (previously silently accepted and forwarded to storage).
- **Rejected (400) even when allowed** — a field in `JsonApiController::ALWAYS_INTERNAL_FIELDS` (`pass`, `password`, `password_hash`) or a declared field whose `FieldDefinition::getSetting('internal') === true` (e.g. `two_factor_secret`). A field can be both "declared" and "off-limits to query" at the same time.

The allowlist resolves `resolveFieldDefinitions($entityTypeId)` **without a bundle argument**, so it admits base + core fields (and entity keys) but not fields declared only on a specific bundle. A bundle-only field is therefore not filterable/sortable on this cross-bundle collection endpoint — a deliberate tightening; no current caller relies on it, and the allowlist is intentionally not widened to bundle fields.

This replaced a pre-R2 **deny-list-only** check (`rejectInternalQueryFields()`, same internal/credential rules, but no allowlist) that let every other field name — including one designed to break out of a downstream SQL string literal — pass straight through to `QueryApplier` → `EntityQueryInterface::condition()`/`sort()` → `SqlEntityQuery::resolveField()`, which interpolates an unresolved field name RAW into a `json_extract(<alias>._data, '$.<field>')` fragment. Note this API-layer allowlist only guards `JsonApiController`; the **storage layer has two raw json_extract sinks** (`SqlEntityQuery::resolveField()` and its twin `SqlStorageDriver::resolveField()` on the `EntityRepository::findBy()`/`count()` path), and both now carry an independent shared identifier guard — see `docs/specs/entity-system.md` "SQL identifier hardening (audit R2 WP1)". Pinned by `packages/api/tests/Unit/Query/JsonApiControllerFieldAllowlistTest.php`.

**Consequence for test fixtures**: an entity-type fixture with zero declared fields (e.g. a bare `TestEntity` used only for entity keys) can no longer filter/sort on an arbitrary undeclared `_data` field — the fixture must declare the field via `EntityType`'s internal `_fieldDefinitions` constructor slot (or, for a real content type, a `#[Field]` attribute) for the test to keep passing. This mirrors production reality: `node`/`user` declare `status`/`body`/`created` etc. as real fields.

### Field-access gate on filter/sort fields (audit R14)

The allowlist above is **structural**: it admits any declared, non-`internal` field. That is not sufficient for a field whose visibility is decided at runtime by a dynamic `FieldAccessPolicy` (a classification / clearance field is a structurally ordinary `#[Field]` with no static `internal` flag, forbidden per row by the account's clearance). Such a field passes `validateQueryFields()` and is applied as a raw storage `condition()`/`sort()`. Entity-level access filters the *rows* (`setAccount` on the query, plus the deny-by-default `isAllowed()` re-filter and `accessFilteredTotal()` recount), but nothing checked **field-level** access, so `meta.total` and the returned rows still reflected the forbidden field's value — a caller who may list the type and view its rows, but lacks a field's clearance, could read `filter[classification_field]=secret` as that value's row count, or sort on it to order rows by the hidden value.

`JsonApiController::index()` closes this by gating every caller-supplied filter/sort field through **per-entity** field-level view access, in addition to the structural allowlist:

- `queryFieldNames()` collects the distinct filter + sort field names for the request.
- `queryFieldForbidden($entity, $fields)` is true when **any** of those fields is `checkFieldAccess($entity, $field, 'view', $account)->isForbidden()` for that entity.
- A row is excluded from **both** the returned page and `accessFilteredTotal()` when `queryFieldForbidden()` holds — in addition to the existing entity-level `isAllowed()` test.

The exclusion is **value-independent**: a row is dropped because the caller may not *read* the queried field, never because of the field's value, so no operator (including `NOT_EQUALS`) and no probe value can turn the row set or `meta.total` into a presence oracle. This is the per-entity companion to the structural allowlist (which cannot express per-row gating), mirroring R13 WP1's admin-surface shape. The credential floor (`ALWAYS_INTERNAL_FIELDS` + `internal`-flagged fields) stays rejected outright by the unchanged structural gate; the no-account system context keeps the storage-derived total and does no field gating.

**Sort is rejected, not dropped.** The per-entity drop is sufficient for a *filter* but NOT for a *sort*: `QueryApplier::apply()` runs `sort()` and `range(offset, limit)` at the **storage layer, before** the post-fetch drop, so a Forbidden row still occupies a pagination **rank** even after it is dropped from the returned page. A caller sorting on a Forbidden field with a small `page[limit]` and scanning `page[offset]` reads the empty-vs-populated pattern of those ranks and reconstructs the hidden field's ordering — an ordering oracle the drop alone leaves open (found in adversarial review; the initial all-rows-Forbidden tests missed it because they collapse to `total: 0` on a single page). Storage cannot evaluate per-row field-access policy, so `JsonApiController::rejectForbiddenSort()` returns a **400** when a `sort` targets a field that is view-`Forbidden` on **any** entity-level-viewable matched row, refusing to order rows the caller cannot fully read. The reject is likewise **value-independent** — it depends only on *which* viewable rows carry a Forbidden sort field, never on the field's value or the sort direction — so it adds no oracle beyond the per-row "you may not read this field" boundary `show()` already exposes. A sort on a field the caller can read on every viewable matched row is unaffected.

**GraphQL parity**: `GraphQL\Resolver\EntityResolver::resolveList()` had the identical oracle on its filter-argument path (`total` computed with only the entity-level `guard->canView()` predicate). It now applies the same value-independent exclusion in both its count loop and its item loop via `GraphQlAccessGuard::isFieldViewForbidden()`, and the same sort reject via `EntityResolver::rejectForbiddenSort()` (throws a `UserError`), both gated to the bound-account path (the system-context bypass keeps the raw storage `COUNT`, unchanged). **Structural allowlist closed (R15, audit A11):** the R14-flagged residual is now fixed. `EntityResolver::assertQueryableFields()` runs at the top of `resolveList()` (before any storage query, unconditionally, mirroring REST's `validateQueryFields()`) and throws a `UserError` for any filter/sort field that is not a declared field (`resolveFieldDefinitions()`) or entity key, is in `ALWAYS_INTERNAL_FIELDS` (`pass`/`password`/`password_hash`), or is a declared field with `getSetting('internal') === true`. This closes the two structural oracles R14's per-policy gate could not express: an undeclared `_data` key (which reached the `json_extract('$.<field>')` sink; SQL injection itself already contained by `JsonFieldName::assertQueryable`) and a declared `internal`-flagged secret (`User.two_factor_secret`, `OidcClient.client_secret_hash`). Pinned by `JsonApiControllerFieldFilterOracleTest` (REST), `EntityResolverFieldFilterOracleTest` (GraphQL R14) and `EntityResolverStructuralFieldAllowlistTest` (GraphQL R15).

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
        fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed()
            // audit R14: exclude a row whose filter/sort field the caller may not read.
            && !$this->queryFieldForbidden($entity, $gatedQueryFields),
    );
}
```

This means:
- On the authenticated path the SQL query binds the request account via `setAccount($this->account)`, so the storage layer performs per-row access checking (open-by-default: it drops only `Forbidden` rows). `accessCheck(false)` is used only on the system / no-account path.
- Entities for the current page are loaded, then re-filtered by view access in PHP with `isAllowed()` (deny-by-default entity-level semantics — a `Neutral` row is not visible), mirroring `show()`.
- `meta.total` reflects the **access-filtered total of matching rows the current account may view ACROSS ALL PAGES** — computed via `accessFilteredTotal()` using the same `isAllowed()` predicate as the per-page filter — **not** the size of the current page (audit C-26: the previous `$total = count($entities)` recount collapsed it to page size on the authenticated path) and **not** the open-by-default storage `COUNT` (which would inflate it with `Neutral` rows). On a paginated collection `count($data) <= meta.limit` while `meta.total` may be larger, and `meta.total` is page-invariant for a fixed query + account.

<!-- Spec reviewed 2026-06-21 - issue #1702 (audit C-7): the GraphQL list resolver now matches the REST `meta.total` contract above. `GraphQL\Resolver\EntityResolver::resolveList()` previously took `total` from the open-by-default storage `COUNT` (admits `Allowed` AND `Neutral`) while `items` were deny-by-default via `GraphQlAccessGuard::canView()` — so a restricted collection's `total` leaked its full cardinality (Neutral/policy-less rows inflated it) even though those rows never appeared in `items`. `resolveList()` now recomputes `total` across ALL matching rows (filters only, no pagination) with the SAME `guard->canView()` predicate as the per-item filter, so `total` and `items` reconcile and `total` is page-invariant. The query-layer survivor test (Layer 3) is unchanged and remains the open-by-default candidate window (see access-control.md "Layer 3 contract details"); deny-by-default stays a serializer/consumer concern. Acceptance: EntityResolverTest (`resolveListFiltersOutDeniedEntities`, `resolveListTotalReconcilesAcrossPagesWithAccessFilteredItems`). -->


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
        private readonly ?AccountInterface $account = null,
    ) {}

    /**
     * @return array{meta: array<string, string>, links: array<string, mixed>}
     */
    public function discover(): array;
}
```

Returns a JSON:API-style discovery document. Since mission request-surface-hardening-01KTX7F2 (#1649) the per-type links are **account-dependent**; the envelope is caller-independent. The response contract is:

| Key | Shape | Notes |
|-----|-------|-------|
| `meta.api` | `'waaseyaa'` (string) | Constant identifier for the API surface. Present for every caller. |
| `meta.version` | `'1.0'` (string) | Discovery contract version, not the framework version. Present for every caller. |
| `links.self` | `string` | The configured `$basePath` (defaults to `/api`). Present for every caller. |
| `links.{entity_type_id}` | `array{href: string, meta: array{type: string}}` | **Authenticated callers only.** One entry per *discoverable* `EntityTypeManagerInterface::getDefinitions()` entry. `href` is `{basePath}/{entity_type_id}`; `meta.type` echoes the entity type id for client convenience. |

Visibility decision per type:

```
listed(type, account) = isDiscoverableDuckTyped(type)   // false → hidden from EVERYONE, admin included
                      ∧ account !== null
                      ∧ account->isAuthenticated()      // anonymous/absent → zero type links (fail closed)
```

- **Authenticated-only default (research D1):** no categorical per-type view check exists in the access API (`AccessPolicyInterface::access()` requires a concrete entity; `createAccess()` is create-only), so per-account/per-type granularity is **not implementable today** — this is the spec's documented fallback, not per-account type filtering. Any authenticated account sees every discoverable type, gated types included. An anonymous account (`AnonymousUser`, id 0), a null account, or a controller constructed without an account yields zero type links; no type id appears anywhere in the response body (SC-001).
- **`discoverable` flag (FR-002):** `EntityType` carries an additive `discoverable: bool = true` ctor param + `isDiscoverable(): bool` accessor (+ `fromClass()` passthrough). The controller reads it duck-typed (`method_exists($definition, 'isDiscoverable') && !$definition->isDiscoverable()` → skip) — `EntityTypeInterface` is deliberately **not** widened (seven anonymous-class test implementors outside the mission surface; research D2); definitions without the method are discoverable. The flag is **visibility, not authorization**: CRUD routes for non-discoverable types keep registering and keep enforcing entity access unchanged.
- **Cost bound (NFR-001):** one `isAuthenticated()` call per request, at most one accessor read per registered type — no access-policy invocation, no row loading, no queries.
- **Route access unchanged:** `api.discovery` stays `allowAll()` (`_public`) — the endpoint answers all callers; only the per-type links vary.

Invariants enforced by the integration test (`tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php`):
- `links.self` is always present, for every caller.
- `links.{type}.href` always equals the collection path served by `api.{type}.index`.
- For an **authenticated** caller, the entry set in `links` (excluding `self`) is exactly the set of registered *discoverable* entity type ids — no more, no less.
- For an **anonymous** caller, `links` collapses to `['self' => $basePath]` regardless of registered types.
- When zero entity types are registered, `links` collapses to `['self' => $basePath]` for every caller.
- The route shape (`_public`, path, methods) is unchanged.

The route is dispatched by `JsonApiRouteProvider`'s `api.discovery` registration. At runtime, `DiscoveryRouter` (the `HttpDomainRouter` registered through `ApiServiceProvider::httpDomainRouters()`) recognises the controller string `Waaseyaa\Api\ApiDiscoveryController::discover` via `str_contains($controller, 'ApiDiscoveryController')`, instantiates the controller with the booted `EntityTypeManager` **and the request account** — `WaaseyaaContext::fromRequest($request)->account`, i.e. the `_account` attribute set by `SessionMiddleware`; no other account source is consulted — and wraps the discover payload in a `jsonapi.version` envelope before returning a JSON:API response.

#### Schema self-description surface requires authentication

`GET /api/openapi.json` and `GET /api/schema/{entity_type}` **require authentication** (`_authenticated`), so `AccessChecker` returns `unauthenticated` and `AuthorizationMiddleware` 401s an anonymous caller. `/api/openapi.json` is registered by foundation's `BuiltinRouteRegistrar`; `/api/schema/{entity_type}` is registered by `ApiServiceProvider::routes()` (moved in WP5). They are the self-description of an API whose data routes are already auth-gated, and (per the field-access caveat below) they over-disclosed instance-state-gated field *definitions* to anonymous; gating them closes that for unauthenticated callers and is consistent with #1649's auth-gating of the `GET /api` discovery index. Pinned by `tests/Integration/SchemaSurfaceRequiresAuthTest`.

`/api/entity-types` remains registered option-less (anonymous-reachable, enumerates entity type ids only — no field definitions); it is the remaining adjacent enumeration surface and a separate, narrower decision (type-id secrecy) left for a follow-up. Do not assume anonymous type-id secrecy on `/api/entity-types` until that lands.

**Schema field-access caveat (D-16).** `GET /api/schema/{entity_type}` filters field visibility (`x-access-restricted`, view-denied removal) by running `SchemaPresenter::present()` against a *prototype* entity — `SchemaController::show()` constructs a bare `new $class([...])` carrying only the requested bundle key, with no field values. The rendered field set therefore reflects only **static, type/bundle-level** `FieldAccessPolicy` decisions; instance-level gates (owner-only fields, row-state/workflow gates) cannot be evaluated and are not represented. The surface is now authenticated (above), so this static-contract exposure is limited to authenticated callers — but consumers (admin SPA, agents) must still treat the schema's field visibility as a static contract, not a per-record access oracle: actual per-record field access is enforced separately at the JSON:API serializer boundary.

**Prototype-construction failure fails closed (audit-remediation batch 2026-07-02 R2, WP3).** `SchemaController::show()` seeds `$protoValues` with a placeholder for every declared field + entity key before constructing the prototype (see "SchemaController" above), so constructor-strict-but-seedable types (e.g. `UserBlock`, engagement `Comment`) keep a working, filtered schema endpoint. If construction STILL throws after seeding (a genuinely pathological type), `show()` does **not** fall through to `present()` with a null entity — doing so would skip `present()`'s entire field-access-filtering block (gated on `$entity !== null`, see "Field Access Filtering" above) and emit a fully unfiltered schema. Instead the endpoint returns a `500` `JsonApiError` with a generic client-facing detail; the underlying exception is logged server-side only. No unfiltered schema is ever emitted when the access-check prototype cannot be built. Pinned by `SchemaControllerTest::showFailsClosedWhenPrototypeConstructionThrows` (unconditional-throw → 500) and `::showSeedsRequiredFieldsSoConstructorStrictTypeReturnsFilteredSchema` (constructor-strict-but-seedable → 200 filtered).

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
3. `$this->discoveryHandler = new DiscoveryApiHandler(...)` is created with four dependencies:
   - `$this->entityTypeManager` — the fully booted `EntityTypeManager` (available after `boot()`).
   - `$this->database` — the `DatabaseInterface` instance (available after `boot()`).
   - `$this->discoveryCache` — a `CacheBackendInterface` (`DatabaseBackend` backed by the `cache_discovery` table), created moments earlier in the same method.
   - `$this->accessHandler` — the kernel's `EntityAccessHandler` (populated by `discoverAccessPolicies()` earlier in `boot()`, before `finalizeBoot()` runs). Added in R7 WP2 (audit R5 residual #1) so the discovery/browse endpoint-visibility gate can be per-account access-aware, not just publish-status-aware — see relationship-modeling.md "Endpoint visibility (traverse and browse, fail-closed)".

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
        private readonly ?EntityAccessHandler $accessHandler = null,
    ) {}
}
```

`$accessHandler` defaults to `null` (e.g. legacy/unwired test construction) — when absent, `isDiscoveryEntityPublic()`/`isDiscoveryEndpointPairPublic()`/`createDiscoveryService()` all behave exactly as before R7 WP2 (publish-status only). Production wiring (`HttpKernel::finalizeBoot()`) always passes the real handler.

### Key Capabilities

| Method | Purpose |
|--------|---------|
| `parseRelationshipTypesQuery(mixed $value): list<string>` | Normalizes comma-separated string or array query param into a list of relationship type IDs |
| `buildDiscoveryCacheKey(string $surface, string $entityType, string $entityId, array $options): string` | Delegates to `DiscoveryCachePrimitives` to build a deterministic cache key |
| `normalizeForCacheKey(mixed $value): mixed` | Recursively sorts associative array keys for stable cache key generation |
| `getDiscoveryCachedResponse(string $cacheKey, AccountInterface $account): ?array` | Returns cached response for anonymous users; bypasses cache for authenticated users |
| `prepareDiscoveryResponse(int $status, array $payload, string $cacheKey, AccountInterface $account): array` | Returns `[payload, headers]` tuple — caches for anonymous (public, max-age=120), sets `no-store` for authenticated |
| `isDiscoveryEndpointPairPublic(string $fromType, string $fromId, string $toType, string $toId, ?AccountInterface $account = null): bool` | Checks both endpoints of a relationship exist, are publicly visible via `WorkflowVisibility`, AND (when `$account` is given and the handler is wired) viewable by that account — delegates to `isDiscoveryEntityPublic()` per endpoint |
| `loadDiscoveryEntity(string $entityType, string $entityId): ?EntityInterface` | Loads an entity by type and ID (resolves numeric strings to int), returns null on any failure |
| `isDiscoveryEntityPublic(EntityInterface $entity, ?AccountInterface $account = null): bool` | Publish-status check via `WorkflowVisibility::isEntityPublic()`, AND (when `$account` is given and the handler is wired) `EntityAccessHandler::check($entity, 'view', $account)->isAllowed()`. **Signature changed in R7 WP2** — previously `(string $entityType, array $values): bool`; both call sites already had the loaded entity, so the signature now takes it directly instead of re-deriving from a values map. |
| `createDiscoveryService(AccountInterface $account): RelationshipDiscoveryService` | Factory method — creates a `RelationshipDiscoveryService` with a `RelationshipTraversalService` wired to the handler's entity type manager, database, `WorkflowVisibilityFilter`, and (R7 WP2) `$this->accessHandler` + `$account` for the per-account endpoint-visibility gate. **Signature changed in R7 WP2** — previously took no arguments; `DiscoveryRouter` now passes `$ctx->account` (the `_account` request attribute) at all four call sites. |

### Discovery Cache Strategy

- **Anonymous users**: Responses are cached in the `discovery` cache bin with a 120-second TTL. Cache tags are derived from the payload via `DiscoveryCachePrimitives::buildTags()`. Cached responses include `X-Waaseyaa-Discovery-Cache: MISS` on first generation. The cache key itself (`DiscoveryCachePrimitives::buildKey()`) is NOT account-scoped — safe because it is only ever read/written for the anonymous class of caller (`getDiscoveryCachedResponse()`/`prepareDiscoveryResponse()` both gate on `!$account->isAuthenticated()`), and every anonymous caller resolves to the same access decisions.
- **Authenticated users**: Cache is bypassed entirely (`Cache-Control: private, no-store`) to ensure fresh, access-aware results.
- Cache invalidation is handled by event listeners registered via `EventListenerRegistrar::registerDiscoveryCacheListeners()`.
- **Cache-key generation (R7 WP2, R8 WP2):** `DiscoveryCachePrimitives::CACHE_KEY_GENERATION` (private, embedded in `buildKey()`'s hashed input, never surfaced in a response payload or tag — distinct from the public `CONTRACT_VERSION`) is bumped whenever a change narrows what a cached entry is allowed to disclose, so every pre-bump entry becomes an orphaned miss instead of being read back with stale, over-permissive data. Bumped 1->2 alongside the R7 WP2 access-awareness fix above, and 2->3 for R8 WP2 (audit R8-c) alongside the `handleTopicHub()`/`handleCluster()`/`handleTimeline()` source-entity view gate described in the top-of-file spec-review note — a generation-2-era cache entry could disclose a 200 hub/cluster/timeline payload for a source the caller could not view.

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

<!-- Spec reviewed 2026-06-22 - cleanup WP03 (audit #23): GraphQL over HTTP is now query-only on GET. The `/graphql` route stays `GET,POST` (GET queries remain cacheable), but `GraphQlEndpoint::handle()` parses the selected operation and returns 405 ("Mutations are not allowed over GET; use POST.") for a mutation requested over GET *before* execution — closing the CSRF vector where `GET /graphql?query=mutation{...}` ran state-changing operations under the victim's session cookie (GET is a simple cross-site request, no preflight). `selectsMutation()` honours `operationName` (only the named op executes) and conservatively blocks any mutation when none is given; an unparseable query falls through to the normal error path. POST mutations and GET queries are unchanged. Acceptance: `GraphQlEndpointTest::{getMutationIsRejectedAndNotExecuted, postMutationIsStillAllowed, getQueryIsStillAllowed}`. Residual (out of scope, noted in PR #1721): the route's `csrfExempt()` still allows a `Content-Type: text/plain` cross-site POST to reach a mutation because `parseRequest()` json-decodes the POST body regardless of content type — a follow-up should thread Content-Type and require `application/json` (or a CSRF token) for POST mutations. -->
<!-- Spec reviewed 2026-07-05 - audit-remediation R11 (audit A9): closes the GraphQL anonymous mutation existence oracle. `/graphql` is `allowAll()` (`GraphQlRouteProvider`) and executes mutations for the anonymous account; `EntityResolver::resolveUpdate()`/`resolveDelete()` threw "Entity not found: {type}/{id}" for an absent id but `GraphQlAccessGuard` threw the textually distinct "Access denied: cannot update/delete entity" for a real entity the caller could not modify, an anonymous (or any unauthorized) caller could enumerate entity existence by diffing the two error messages, despite every per-entity access policy being correct. REST's PATCH/DELETE are `requireAuthentication()`-gated and so were not anonymously exploitable; GraphQL had no equivalent operation-type-aware gate. Two-layer fix: (a) `GraphQlEndpoint::handle()` now rejects ANY mutation (`selectsMutation()`, already used for the GET-mutation CSRF check above) for an unauthenticated account (`!$this->account->isAuthenticated()`) BEFORE building the schema or invoking any resolver, for every HTTP method, the security-relevant property is that anonymous mutation operations are rejected with a UNIFORM error ("Authentication required for mutation operations.") that names no entity id/type, before any resolver runs, and the mutation never executes; no distinguishable path, no timing side channel, matching REST's `requireAuthentication()` parity. (The endpoint sets `statusCode` 401 in its return array, but the HTTP-status envelope is a SEPARATE pre-existing issue out of R11 scope and untouched here: `GraphQlRouter::handle()` hardcodes `jsonApiResponse(200, $result)`, serving every GraphQL response as HTTP 200 regardless of `statusCode`, so a client does not currently see a real HTTP 401, orthogonal to this oracle, tracked as a follow-up.) `parseRequest()` accepts only a single `{query, variables, operationName}` document (a JSON-array body finds no `query` key and falls through to "Missing query"), so there is no batched-request path that could smuggle a mutation past this check. (b) `EntityResolver::resolveUpdate()`/`resolveDelete()` catch the guard's `UserError` and rethrow the SAME "Entity not found: {type}/{id}" the absent-entity branch already throws, so an AUTHENTICATED-but-unauthorized caller (not blocked by (a)) also gets an indistinguishable not-found response, mirroring the pre-existing `resolveSingle()` read-path convention (already returns `null` for both absent and view-denied) and the R8 existence-oracle-closure pattern. This collapse covers BOTH the entity-level `update`/`delete` denial AND the per-FIELD `edit` denial: `resolveUpdate()`'s `assertFieldEditAccess()` loop runs INSIDE the same try/catch, because entity-level `update` can be ALLOWED while a specific field's `edit` is FORBIDDEN (e.g. `NodeAccessPolicy` grants `edit any {type} content` but field-forbids `uid`/`created`/`changed` for non-admins, and those non-`readOnly` fields are in the update input), and a distinguishable "Access denied: cannot edit field '{name}'" fires only for a real entity, re-opening the oracle for any ordinary editor. Only the two access-guard calls are inside the catch; the `FieldableInterface` support check and `set()`/`save()` field-validation stay outside, so a genuine validation/support error for an authorized caller is surfaced accurately, never masked as not-found; both access checks still run (a forbidden field-edit is still refused), only their denial wording is collapsed. `resolveCreate()` was audited and left unchanged: it builds a NEW entity (no caller-supplied existing id to probe), so its "cannot create {type}" and any field-edit denial reveal nothing id-specific, no oracle shape. Acceptance: `GraphQlMutationOracleTest` (`tests/Integration/GraphQL/`, anonymous + authenticated-low-privilege oracle closure, field-level residual `testAuthenticatedFieldEditOracleIsClosed` + `testEditorCanStillUpdateAnAllowedField` control via `FieldOraclePolicy`, anonymous-public-read control), `EntityResolverTest::{resolveUpdateDeniedAndAbsentAreIndistinguishable, resolveDeleteDeniedAndAbsentAreIndistinguishable}`, `GraphQlEndpointTest::{anonymousPostMutationIsRejectedBeforeExecution, anonymousGetMutationIsRejectedBeforeExecution, anonymousQueryIsNotAffectedByTheMutationGate, authenticatedPostMutationIsNotBlockedByTheAnonymousGate}`. -->
<!-- Spec reviewed 2026-07-05 - audit-remediation R12 (audit A10): closes a cross-account bleed in the GraphQL static per-process schema cache. `SchemaFactory::$schemaCache` (`packages/graphql/src/Schema/SchemaFactory.php`) is keyed only by entity-type ids + mutation-override keys, not by account, and `GraphQlEndpoint::handle()` builds a fresh per-request `GraphQlAccessGuard`/`EntityResolver`/`ReferenceLoader` on every call, but `SchemaFactory::build()` returned the CACHED `Schema` on a hit; that cached `Schema`'s query/mutation resolver closures and the `EntityTypeBuilder` entity-reference field resolver had captured the FIRST request's `EntityResolver`/`ReferenceLoader` by closure. Under FrankenPHP worker mode (the documented production runtime) a process serves many requests without teardown, so every request after the first to build a given schema shape ran under the first request's account and saw its loaded-entity cache, defeating every per-entity access policy. Invisible under php-fpm/`php -S`/the test suite (per-process teardown/reset hides it): this is why the exploit test explicitly drives two accounts through one process with no `SchemaFactory::resetCache()` between them, the way two sequential worker-mode requests would. Fixed, fail-closed, by making the cached `Schema` itself account-free and request-free: the per-request `EntityResolver`/`ReferenceLoader` now travel as a new `GraphQlExecutionContext` (`packages/graphql/src/GraphQlExecutionContext.php`) passed as the GraphQL `contextValue` (the resolver's 3rd argument) to `GraphQL::executeQuery()`; every default resolver closure in `SchemaFactory`/`EntityTypeBuilder` reads its collaborator from that per-request context, never from a captured constructor property, and both classes dropped the `EntityResolver`/`ReferenceLoader` constructor dependencies entirely so the built schema is structurally proven to hold no per-request state. The static schema cache itself is KEPT (a deliberate worker-mode optimization, not the defect); `withMutationOverrides()` is unchanged (an override still receives the execution context as its 3rd resolver argument). R11's "Entity not found" collapse in `EntityResolver::resolveUpdate()`/`resolveDelete()` is untouched and reached identically via the context. Static-state sweep of `packages/graphql/src/`: the ONLY process-level mutable static is `SchemaFactory::$schemaCache`; `TypeRegistry` is a per-build instance (constructed fresh in `SchemaFactory::build()`), holding only structural `Type` objects, safely reusable once the schema itself is account-free. Acceptance: `GraphQlSchemaCacheCrossAccountBleedTest` (`tests/Integration/GraphQL/`, query-side and mutation-side two-account-one-process exploit, both red pre-fix). -->
