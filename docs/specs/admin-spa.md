# Admin SPA

<!-- Spec reviewed 2026-08-20 - #2467 save-advisory Admin envelope: Generic Admin
projects JSON:API errors through AdminSurfaceResultData::fromJsonApiError().
Ordinary errors keep status/title/detail; only SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED
emits code plus allowlisted meta.save_advisories. TransportError.meta is that
closed AdminSurfaceErrorMeta type, not Record<string, unknown>. A missing
mutation-token 428 stays codeless so SchemaForm can distinguish the two without
parsing prose. -->

<!-- Spec reviewed 2026-08-20 - #2464 generic revision recovery: exact reads compose
view_revision plus protected/context-aware authority on the historical snapshot; restore uses
shared RevisionRestoreChangedFields and preserves live pointer/status/credential values; preview
grants reject fixed-point encoded traversal, controls, backslashes, and invalid UTF-8. -->
<!-- Spec reviewed 2026-08-13 - shared workflow history: the entity editor's
TransitionHistoryTimeline reads `meta.workflow_history` from the sanctioned
workflow-discovery endpoint, not the obsolete inline `workflow_audit` field.
The response is shape-validated, limited to successful transitions, ordered
newest first, and refreshed with the shared editor workspace after saves and
transitions. The same component is served in the Admin SPA and embedded Anokii
client. -->

<!-- Spec reviewed 2026-08-06 - #2271 publication-list projection: authenticated admin node lists may expose only workflow_state and status through AdminPublicationFieldReaderInterface after the row passes entity-view authorization. AuditedAdminPublicationFieldReader issues an account-bound StrictAuditProjection capability for exactly those two node fields and revokes its execution boundary after each projection scope. GenericAdminSurfaceHost uses that same projection in memory for display, filters, and sorting instead of SQL-pushing protected fields; boolean query values normalize to the projection's 1/0 representation. Ordinary ResourceSerializer output and NodeProtectedReadPolicy remain unchanged. AdminSurfaceServiceProvider registers the reader for both stock and application-owned host wiring. -->

<!-- Spec reviewed 2026-08-06 - #2275 concurrent publication projection: GenericAdminSurfaceHost primes a cardinality-preserving BatchAdminPublicationFieldReaderInterface for the authorized page or projected filter/sort scope. AuditedAdminPublicationFieldReader keeps one descriptor and receipt per entity but reserves and finalizes the related receipts in two all-or-nothing transactions. No value enters audit storage, no value is returned before all reservations commit, and any failed finalization still fails the list closed. Readers without the optional batch extension retain the strict per-entity path. -->

<!-- Spec reviewed 2026-08-06 - #2273 untitled rows: SchemaList treats the catalog reference.labelField as authoritative. An empty declared label renders the localized untitled placeholder only in that label cell, while the row and action accessible names append the stable entity id. Legacy catalogs without reference metadata keep the first-non-empty-column fallback; unrelated empty cells remain blank. -->

<!-- Spec reviewed 2026-08-06 - #2233 reproducible distribution: bin/build-admin-dist derives Nuxt's build ID and a stable positive metadata timestamp from the complete admin source signature. A narrow post-build normalizer rewrites only the known Nuxt manifest and prerender payload timestamp fields and fails closed if their shapes change. Two clean Node 24 builds must therefore produce byte-identical tracked output, while source-signature freshness and genuine compiled-asset drift remain observable. -->

<!-- Spec reviewed 2026-08-04 - #2181 pre-auth optionality: the authenticated session projects exact optional-package feature booleans. The Wayfinding overlay, SSE consumer, and session-token request activate only when an account exists and `features.wayfinding === true`; auth loss unmounts the overlay and aborts/clears token discovery. -->

<!-- Spec reviewed 2026-08-04 - #2186 self-contained admin: the navigation toggle ships its decorative SVG inline. The SPA has no runtime icon-provider module, dependency, or external icon origin in its committed distribution. -->

<!-- Spec reviewed 2026-08-04 - #2185/#2187 retire NorthCloud: the generic dashboard retains only its catalog-gated ingest_log counters; all NC Sync requests, state, routes, translations, markup, and shipped assets are removed. -->

<!-- Spec reviewed 2026-08-16 - #2113 replaces the Admin-only internal-field list with boot-scoped InternalFieldVisibilityPolicy. Admin form schema/detail projection and generic JSON:API serialization/filter/sort now consume the same framework/application metadata (`entity.internal_fields_by_type` plus FieldDefinition.settings.internal); credential-name floors remain independent defense in depth. -->

<!-- Spec reviewed 2026-07-22 - #2108 browser-channel follow-up: the GenericAdminSurfaceHost itself owns the shipped node.source_status/wp_status form-visibility floor, so application-owned routes that construct the host directly receive the same exclusion as provider-owned routes. Host-declared additions remain supported. The broader JSON:API metadata convergence is tracked in #2113. -->
<!-- Spec reviewed 2026-07-22 - #2108 WP-2: schema metadata can declare an editable slug widget with x-source-field; the widget requests the host's generate-slug action, which delegates to Foundation SlugGenerator so Indigenous orthography is preserved. Integer fields with subtype=timestamp project as string/date-time + datetime widgets. GenericAdminSurfaceHost accepts host-declared per-type internal form fields (the shipped host hides migrated node.wp_status). Failed create validation retains bundle state, and entity detail uses the standard confirmation modal for capability-gated deletion. -->
<!-- Spec reviewed 2026-07-22 - #2108 WP-1: the legacy bundled-list path now emits the same closed {operator,value} filter condition consumed by AdminSurfaceTransportAdapter as host-declared x-list controls. Node lists default to created DESC, retain offset/limit on every request, and therefore keep new content on page 1 while pagination and bundle totals reflect distinct server results. Browser-shaped coverage uses migrated UUID/data-shaped rows. -->
<!-- Spec reviewed 2026-07-21 - #2101 WP-3: taxonomy_vocabulary is the first explicitly mutable generic config-row surface. Its catalog advertises edit/delete (but not create), and mutable list rows retain the delete affordance so state-dependent refusals can surface an operator-readable message at the authoritative action boundary. The schema declares vid/name so saved titles render. Empty vocabulary rows may be deleted; a vocabulary referenced by any taxonomy_term is Forbidden and protected by a restrictive storage foreign key. Other config entity types retain their read-only generic catalog posture. -->
<!-- Spec reviewed 2026-07-21 - #2101 minor sweep: create forms honor declared boolean defaults, including menu_link enabled=true; relationship empty lists span the exact rendered header count. The R2 JSON:API structural filter allowlist remains unchanged and rejects undeclared keys with 400. -->

<!-- Spec reviewed 2026-07-20 - #2088: destructive SPA actions use the reusable accessible ConfirmDialog instead of browser-native confirm; config-entity listings use bounded hydrated access so persisted content-type rows remain visible on the dashboard. -->

<!-- Spec reviewed 2026-07-16 - #2052: optional validated x-list metadata declares inert columns, closed framework formatters, labelled search/filters, and allowed/default sort pairs. Hosts enforce caller filters/operators/sorts server-side through SurfaceQueryPolicy before delegation. Generic list resources expose access-derived view/edit/delete booleans without policy reasons and mutations remain authoritatively checked. SchemaList protects against stale responses, synchronizes declared query state with the URL, resets pagination on control changes, and keeps legacy x-list-display behavior only when x-list is absent. Session UI navigationMode defaults to full; catalog-only suppresses static operational/governance links as presentation only. -->

<!-- Spec reviewed 2026-07-16 - #2053: one --admin-target-size token gives ordinary authenticated-admin links, buttons, inputs, selects, date controls, autocomplete controls/options, rich-text controls, toggle labels, disclosures, actions, and pagination effective 44 by 44 CSS-pixel targets. Autocomplete clear is an adjacent non-overlapping control; the toggle label owns the effective target while focus and state remain on its native checkbox. Geometry is pinned at 360/768/1024/1440 and 200% text enlargement. -->
<!-- Spec reviewed 2026-07-16 - #2051: schema listings retain one semantic table/action set, adapt that markup to labelled cards below 600px, contain wider tables in a named scroll region, and use bounded semantic pagination. Generic ordinary controls use 44px targets. Closed mobile navigation is inert/aria-hidden/pointer-disabled; open navigation manages focus, Escape, scroll, backdrop, route, and breakpoint cleanup. Shell boundaries shrink/wrap long content without document overflow. -->

<!-- Spec reviewed 2026-08-18 - #2419 + #2421 per-record history: new GenericAdminSurfaceHost `history` action returns a record's revisions (revisionId, createdAt, author, log, isCurrent, isLatest) gated by the record's own view access, fail-closed like get(). Metadata only — never field values — so history cannot bypass the record's field-access rules. A refusal exposes no surface rather than an empty one (an empty timeline is itself a disclosure), and the SPA distinguishes refused from genuinely empty; a non-revisionable type is refused 404 rather than raising the repository LogicException. isCurrent (published/default) and isLatest (tip) are reported separately because they diverge under a forward draft; author keeps null (unattributed) distinct from 0 (anonymous). New page pages/[entityType]/[id]/history.vue, addressable at /admin/{entityType}/{id}/history and generated by AdminDestinationPaths::history(). The record editor moved from [id].vue to [id]/index.vue so history can be a sibling rather than nested inside the editor by Nuxt's parent-layout rule; its URL is unchanged. -->
<!-- Spec reviewed 2026-08-18 - #2418 + #2420 bundle-scoped Admin destinations: new Waaseyaa\AdminSurface\AdminDestinationPaths is the canonical generator for Admin SPA page destinations (list/create/edit/pipeline), companion to AdminSurfaceRoutePaths which covers only the _surface HTTP API. It owns its own encoding, refuses an empty entity type or record id, omits an empty bundle rather than emitting `?bundle=`, and makes no access decision. pages/[entityType]/create.vue and index.vue read the scope through app/runtime/bundleScope.ts, whose BUNDLE_QUERY_PARAM is pinned by test to the PHP QUERY_BUNDLE constant, as is each destination's correspondence to its SPA page file. Degradation is contractual: a repeated or blank parameter yields no scope; SchemaForm drops a bundle absent from the base schema's x-bundle-key enum before requesting it, so a stale link degrades to the unscoped create form instead of a refused scoped schema request; SchemaList seeds the visible bundle control (not a hidden filter) only when the value appears in bundleOptions. Per-record History remains without a destination pending #2419/#2421. -->
<!-- Spec reviewed 2026-08-18 - #2422 consumer-supplied Admin Surface host registration: AdminSurfaceServiceProvider::routes() no longer hardcodes GenericAdminSurfaceHost. It resolves an optional AdminSurfaceHostFactoryInterface binding and registers the canonical admin_surface.* routes against the host the factory returns, falling back to the generic host when none is bound. A factory rather than a host binding because routes() runs after every provider's register(), so an application host may depend on sibling bindings. Paths, HTTP methods, authentication requirements, and the #2161 refusal-status promotion are unchanged and now inherited by application hosts instead of privately reimplemented. Registration happens exactly once; WaaseyaaRouter's duplicate-route-name refusal is retained so an accidental second registration still fails at boot. No SPA-side change. -->
<!-- Spec reviewed 2026-08-19 - #2453 / Sheg #110: the five canonical `admin_surface.*` closures now return a compact `application/json` `JsonResponse` at the Admin Surface boundary. Their `{ok,data,error,meta}` envelope is not JSON:API; passing the prior `statusCode`/`body` wrapper through Foundation's generic `ControllerDispatcher` mislabeled it `application/vnd.api+json` and pretty-printed bytes that differed from the consumer contract #2422 was meant to consolidate. Refusal promotion remains fail-closed: only an integer 400–599 reaches the status line; absent, string, or out-of-range values keep HTTP 200 while the envelope is emitted unchanged. All five success and refusal paths pin status, media type, and literal bytes. `admin_surface.page_builder.*` remains out of scope because it uses a different host interface. -->
<!-- Spec reviewed 2026-08-17 - #2161: admin-surface refusals now carry their status on the wire. Every `admin_surface.*` endpoint previously answered a refusal with HTTP 200 and reported the real status only inside the envelope (`{"ok": false, "error": {"status": 403, ...}}`), because all five `AbstractAdminSurfaceHost::handle*` methods return the same flat envelope and all five landed on `ControllerDispatcher::handleCallable()`'s default HTTP 200. The Admin Surface route boundary promotes only a genuine 400-599 integer, so an absent, string, or out-of-range status cannot reach the `Response` constructor and turn a clean refusal into a 500. `admin_surface.session` therefore returns a real 401 when unauthenticated — `AdminSurfaceTransportAdapter.request()` already branches on `!response.ok || !json.ok` and prefers `error.status`, and `plugins/admin.ts` passes `ignoreResponseError: true` so its `error.status === 401` login redirect still runs rather than falling into the bare catch. -->

<!-- Spec reviewed 2026-08-03 - #2177 F1 C1c prerequisite: server-authoritative session capability projection. AdminSurfaceSession gains optional `capabilities: Record<string, boolean>` (always emitted by the PHP host, `{}` when empty), computed in GenericAdminSurfaceHost::resolveSession() from the resolved immutable principal via hasPermission() over an explicit constructor `$capabilityAllowlist` (strict identifier validation, deduplicated, sorted, hard caps CAPABILITY_ALLOWLIST_MAX=32 / CAPABILITY_IDENTIFIER_MAX_LENGTH=128; malformed or oversized lists throw at construction — never a silent broadening, never permission enumeration). AdminSurfaceServiceProvider::defaultCapabilityAllowlist() projects exactly McpApprovalCapabilities::PERMISSION_VIEW/PERMISSION_DECIDE when the MCP package is installed and nothing on slim installs; `features.mcp` stays installation-wide while `capabilities` is per-account. SPA: the admin plugin threads session capabilities into AdminRuntime.capabilities and useAdmin() exposes the canonical fail-closed `can(permission)` helper (true only for exact boolean true; no role fallback). Server middleware/route permissions remain the enforcement boundary — this is a UI affordance signal only. -->

<!-- Spec reviewed 2026-07-15 - #2050: schema fields share stable label/help/error IDs and required/invalid semantics; submission failures use a focused assertive summary, structured-error mapping and single-flight guard. RichText preserves untouched canonical HTML behind an inert visual projection plus explicit source mode. Date-only fields use ISO YYYY-MM-DD without timezone conversion and enforce authoritative x-min/x-max bounds. -->

<!-- Spec reviewed 2026-07-15 - #2048: mounted admin workflow/API transport now keeps the Nuxt app base (`/admin/`) separate from the canonical JSON API base (`/`). The admin SPA catch-all excludes both `_surface` and `api` path segments, so missing `/admin/api/*` requests remain non-success API-looking misses rather than `200 text/html`. Workflow discovery validates the response shape and models loading, bound/no-transition, 403, 404, malformed, network, and server failures explicitly; transition submission is single-flight and errors are announced. GenericAdminSurfaceHost resolves bundle-specific workflow binding metadata as `x-workflow` and removes raw `workflow_state` and `status` properties only from bound schemas; unbound schemas preserve their prior fields. -->

<!-- Spec reviewed 2026-07-15 - #2047: generic bundled create is a two-stage schema flow. The mounted provider supplies SchemaPresenter's field registry; base schemas advertise the schema-declared bundle key plus registered enum, create-mode SchemaForm requests an explicit bundle scope, drops stale prior-bundle values, and submits the selected key/value. Schema transport/cache scopes are typed as {id?, bundle?}; unbundled creation remains one-stage. -->

<!-- Spec reviewed 2026-07-14 - R21 WP4 (#2010): GenericAdminSurfaceHost now enforces configured readOnlyTypes at the server write boundary for create/update/delete, not only as catalogue UI capabilities. list() pushes filters, sort, pagination, and access-bound candidate selection through EntityQueryInterface instead of unconditionally hydrating the whole table with findBy([]). For a requested filter/sort, it resolves per-entity field access first and, when a filter field is Forbidden on some entities, constrains both page and count queries to the resulting ID scope before caller conditions/range run; fully viewable filters retain the unscoped SQL fast path. A dynamically Forbidden filter field therefore excludes that entity without consuming the visible page, and a dynamic Forbidden sort is rejected with 400 value-independently. Access-checked count() returns [survivorCount], which the host consumes as a scalar rather than misreading it as entity IDs. -->

<!-- Spec reviewed 2026-07-13 - CW-v1 WP-5 WP1 (#1920): deleted the retired read-only workflow
     dry-run/guards admin UI — `TransitionDryRunForm.vue`, `WorkflowGuardsTable.vue`,
     `useWorkflowGuards.ts`, their usage on `/workflows/{id}`, and the orphaned `workflow_guards_*`
     / `dry_run_*` i18n keys (en + fr). `useWorkflowDefinitions`'s `dryRun()` method and its
     `DryRunRequest`/`DryRunResult` types are also removed. File-map table entries for the deleted
     files are dropped. -->
<!-- Spec reviewed 2026-07-13 - CW-v1 option-1 PR-3 (#1920, design §4): GenericAdminSurfaceHost::get()
     now serves the entity's WORKING COPY, unconditionally, to any account with entity UPDATE access
     (view-only accounts keep seeing the published/find() entity) — see "useEntity" below (new note).
     handleUpdate()/action('update', ...) needed no code change: it already delegates entirely to
     JsonApiController::update(), whose PATCH target is now the working copy too (#1920 PR-3). No SPA
     (TypeScript) code change — the transport contract (AdminSurfaceTransportAdapter::get()) is
     unchanged; the server decides transparently. Full contract: docs/specs/api-layer.md "GET single"/
     "PATCH - update", docs/specs/content-workflow.md "Deferred: forward drafts on the shipped workflow"
     (Read-side pointer awareness, now fully CLOSED). -->
<!-- Spec reviewed 2026-07-05 - R13 WP1 (audit A11, admin-surface list filter/sort field-access oracle): GenericAdminSurfaceHost::list() previously applied caller-supplied filter/sort field names to the raw entity value with no field-level access check, so a low-tier account (e.g. holding only "access user profiles") could filter the auto-cataloged `user` list on a Forbidden credential field (`pass`, or the 2FA fields) and read per-row presence/absence as a one-bit oracle. Fixed with two layers, matching the REST `JsonApiController::validateQueryFields` gate ("audit R2 WP1"): (a) a structural allowlist (`validateSurfaceQueryFields()`) that rejects a filter/sort field which is not a declared field or entity key, is in the mirrored `ALWAYS_INTERNAL_FIELDS` list, or carries the `internal` field-setting (returns a 400 `AdminSurfaceResultData::error()`); (b) per-entity `EntityAccessHandler::checkFieldAccess()` enforcement inside `applyFilter()` and the sort comparator, needed because a field can be Forbidden only for some entities of the type (e.g. classification/clearance-gated fields), which a static allowlist cannot express (a Forbidden field never matches a filter, entity excluded, and never drives sort order, neutral placeholder). Legitimate filters/sorts are unaffected. No public admin-surface contract change. -->
<!-- Spec reviewed 2026-06-20 - list-view column policy (UX-1, mission admin-list-column-policy-01KVH8MT): SchemaList no longer dumps full long-text / rich-text bodies into table columns. `columns` now applies a framework-wide policy: rich-text / text-format fields (x-widget 'richtext', from the 'text_long' field type) are dropped from the DEFAULT column set entirely (they stay on SchemaView/SchemaForm, which select fields independently); an explicit `x-list-display:true` opt-in still wins. Every text cell is collapsed to one line and truncated to a 120-char snippet (truncateSnippet) regardless of widget, and a CSS max-width on `.entity-table td:not(.actions)` bounds column width as defense-in-depth. New subsection "List-View Column Policy" under Schema-Driven Forms. Acceptance: SchemaListColumnPolicy.test.ts. Dist rebuilt (freshness gate). No public admin surface contract change. -->
<!-- Spec reviewed 2026-06-19 - Wayfinding Phase 3 (mission wayfinding-01KVGH5X): the flagship beacon overlay. New global component app/components/wayfinding/WayfindingOverlay.vue mounted in app.vue as a persistent sibling of the layout. New composable app/composables/useBeacons.ts builds a live trail from `wayfinding.beacon` SSE events (delivered on this connection's own per-session channel from Phase 2) — useRealtime.ts now also listens for the 'wayfinding.beacon' event. The overlay renders the active beacon as an aria-live role="status" region (built on the alpha.226 busy-region primitive): fully keyboard-navigable (←/→ or ↑/↓ move, Esc dismisses; nav buttons reuse t('previous'/'next'/'dismiss')), it spotlights the declared data-anchor element (adds a global .wf-anchored outline ring, scrolls it into view, and moves focus to it WITHOUT trapping — non-focusable anchors get a transient tabindex=-1), is dismissable at any time (a new beacon re-shows a dismissed overlay), and honours prefers-reduced-motion (no transitions / instant scroll). No new i18n keys; no public admin surface contract change. The overlay ships in the prebuilt bundle (dist rebuilt; freshness gate + served-bundle 'wf-beacon' assertion). -->
<!-- Spec reviewed 2026-06-19 - admin CRUD correctness + Wayfinding Phase-1 groundwork (missions admin-crud-correctness-01KVGEPD, wayfinding-01KVGH5X). (1) Delete UX: a failed delete in SchemaList no longer blanks the table with a misleading list-level error — `deleteError` is now a separate ref from `listError`, rendered as a non-blocking inline notice (`.error--inline`, role="alert") ABOVE the table and framed as a delete failure (t('error_deleting') + detail) rather than echoing the raw backend title. (The coupled backend fix — GenericAdminSurfaceHost::handleDelete resolving by UUID like get() so the SPA's UUID-keyed delete actually persists — lives in packages/admin-surface, see docs/specs/access-control.md-adjacent host behavior.) (2) Wayfinding Phase-1 anchor groundwork: SchemaList/SchemaView/SchemaForm emit stable, inert `data-anchor` IDs derived from schema field identity — see the new "Element anchors" subsection under Schema-Driven Forms. No public admin surface contract change. -->
<!-- Spec reviewed 2026-06-19 - entity-editor open feedback (clicking a list "Edit" was a silent ~6s wait): app.vue adds a route-level <NuxtLoadingIndicator> for immediate navigation feedback; SchemaList's Edit link goes aria-busy + disabled with an "Opening…" label the instant it's activated and swallows repeat-activation (no double-navigation); SchemaView/SchemaForm now render one accessible busy region (role="status", aria-busy) spanning the WHOLE load instead of going blank in the gap between the schema and entity fetches. Latency reduction: SchemaView/SchemaForm fetch schema + entity CONCURRENTLY (Promise.allSettled) rather than sequentially, and AdminSurfaceTransportAdapter.get() now dedupes concurrent identical in-flight GETs (in-flight only, cleared on settle — no persistent cache, so a read after a save still hits the server) so the viewer and the <WorkflowTransitionHistoryTimeline> widget share a single entity read instead of issuing duplicate GETs. New i18n key `opening` (en/fr). Public admin surface contract unchanged. -->
<!-- Spec reviewed 2026-05-24 - #1576 queue dashboard now shows queued + in-flight jobs in addition to failed. `TransportInterface::listJobs(int $limit, int $offset = 0, ?string $status = null): array` was added (M4B follow-up, mandatory on implementors) with two impls: `DbalTransport::listJobs()` issues a COUNT + SELECT against `waaseyaa_queue_jobs` with `reserved_at IS NULL` (queued) / `IS NOT NULL` (in_progress) / no filter (both); `InMemoryTransport::listJobs()` merges `$queues` + `$reserved` sorted by id. Abstract `Waaseyaa\Queue\Tests\Contract\TransportContractTest` (registered under the Unit suite via phpunit.xml.dist) verifies both backends in lockstep — covers empty, all-queued, queued+in_progress mix, status filter, limit/offset pagination, zero-limit, invalid-status. `GET /api/queue/jobs` now reads optional `?status=failed|queued|in_progress|all` (default `failed` — NFR-001 M4B backward compat preserved; meta envelope unchanged at `{page, per_page, total}` so M4B integration assertions pass UNCHANGED). Failed branch keeps the existing FailedJobRepository path; queued/in_progress branches call `TransportInterface::listJobs()`; `all` merges failed-first-then-transport on a single page. When `TransportInterface` is unbound (slimmed-down install), all non-failed statuses fall back to the failed shape. `ApiServiceProvider` extends the queue `resolveOptional()` block to also resolve `TransportInterface` (optional). `QueueController` constructor gains `?TransportInterface $transport = null` as the third arg. Frontend: `useQueueJobs()` returns `status` (`Ref<'failed'|'queued'|'in_progress'|'all'>`) and `fetchJobs(page, perPage, status)` accepts the third arg (default `'failed'`); response row type is now the union `QueueJob = FailedJob | TransportJob` with the `isFailedJob()` guard. `pages/queue/index.vue` adds a chip filter row above the table; failed chip keeps the M4B full-detail columns + retry/discard buttons, the live chips render a lean (id, queue, status pill, attempts, age-seconds) table with NO retry/discard buttons (C-001 — retry/discard remain failed-only). New i18n keys: queue_status_failed, queue_status_queued, queue_status_in_progress, queue_status_all, queue_age_seconds, queue_column_status, queue_column_age. queue_title flipped from "Failed jobs" to "Queue jobs" and queue_empty from "No failed jobs." to "No jobs in this view." to reflect the broader surface. -->
<!-- Spec reviewed 2026-05-24 - M4C WP01 (#1472) admin notifications dashboard at /notifications: new NotificationController + NotificationAdminApiRouter, both gated by `_role: admin` via BuiltinRouteRegistrar. `GET /api/notification/channels` returns `{data: [{type, class}, ...]}` from `NotificationDispatcher::channels()` (new accessor — read-only view of the constructor-supplied channel map; no other dispatcher state touched). `POST /api/notification/channels/{type}/test` looks up the channel by type, builds anonymous `TestRecipient` (reads `_account` from the request, routes mail→email, database→account id) + `TestNotification` (subject `[Waaseyaa test]`, body explains "no action required"; returns a real `Waaseyaa\Mail\Envelope` from `toMail()` so `MailChannel` doesn't crash), and calls `ChannelInterface::send()` inside try/catch. 200 with `{type, status: "success", message: "Test sent."}` on success; 404 JSON:API error envelope on unknown type; 500 with `{type, status: "failed", message, exception_class}` on `\Throwable` — the controller never serialises a throwable directly (FR-010, M4B precedent). `ApiServiceProvider` gains a third `resolveOptional()` block for `NotificationDispatcher` after the queue + scheduler blocks; skips cleanly if absent (slimmed-down install). `packages/api/composer.json` adds `../notification` path repo + `waaseyaa/notification: ^0.1.0-alpha.188` require (L4 → L3, layer-clean). SPA route inventory: `/notifications` Nuxt page mirrors `/queue` shape; columns are channel type, implementation FQCN (truncated short class + tooltip with full FQCN), and a Send-test action. After a test send the page renders either a success chip or a failure card; failure card includes `exception_class` when present. New i18n keys: notifications_title, notifications_empty, notifications_column_type, notifications_column_class, notifications_column_action, notifications_action_test, notifications_confirm_test_title, notifications_confirm_test_body, notifications_status_success, notifications_status_failure, notifications_help. New composable useNotificationChannels (`{channels, loading, error, lastTestResult, fetchChannels, testChannel}`), new component NotificationChannelRow, new page pages/notifications/index.vue. NavBuilder gains a `/notifications` link in the Operations section right after `/scheduler`; NavBuilder test updated to assert 5 nav items + the new `[data-testid=nav-notifications]` link on an empty catalog. Delivery log + per-channel enable/disable are deferred — the notification package does not yet carry the persistence; the follow-up issue tracks adding a `delivery_log` table, a `ChannelConfig` model, an enable/disable flag, and a second tab to `/notifications`. Closes audit C-L3-02 + C-L0-03. -->
<!-- Spec reviewed 2026-05-24 - M4B WP02 (#1471) admin scheduler dashboard at /scheduler: new SchedulerController + SchedulerAdminApiRouter, both gated by `_role: admin` via BuiltinRouteRegistrar. `GET /api/scheduler/tasks` returns `{data: [{name, description, expression, timezone, last_run_at, last_status, next_run_at}, ...]}` — `last_run_at`/`last_status` are nullable (no row in `waaseyaa_schedule_state` yet), `next_run_at` always set. `POST /api/scheduler/tasks/{name}/trigger` calls `ScheduleRunner::runOne()` (new public method that bypasses the cron check, honours the overlap lock, and records run state); 200 with `{status, message, exception_class?}` on success/failure, 404 on unknown task. `ScheduleRunResult` extended with optional `status`/`message`/`exceptionClass` fields so the controller never serialises a `\Throwable` (FR-010). `SchedulerServiceProvider` now binds `ScheduleStateRepository` as a container singleton (database driver only) so the L4 API provider can `resolveOptional()` it. M4B WP01 admin queue routes (landed 2026-05-23) likewise admin-only: `GET /api/queue/jobs` (paginated failed jobs), `POST /api/queue/jobs/{id}/retry`, `POST /api/queue/jobs/{id}/discard`. SPA route inventory under the always-present "Operations" sidebar section: `/queue` (failed jobs) and `/scheduler` (scheduled tasks) — both Nuxt pages at top-level paths, no `/admin/` prefix (matches the existing /workflows, /telescope convention). New i18n keys: scheduler_title, scheduler_empty, scheduler_column_*, scheduler_action_trigger, scheduler_confirm_trigger_*, scheduler_status_*. New composable useScheduledTasks, new component SchedulerTaskRow, new page pages/scheduler/index.vue. NavBuilder test asserts the /scheduler link renders alongside /queue under the Operations heading even with an empty catalog. -->
<!-- Spec reviewed 2026-08-12 - S1-FW-DB-03 manual scheduler triggers require one `Idempotency-Key`. Missing/blank returns JSON:API 428 before execution; retries with the same key resolve to the same durable occurrence. Tasks that have not adopted durable occurrence and fence protection return 409 instead of accepting an idempotency promise they cannot honor. The SPA creates one UUID when the operator confirms and uses it for that request/retry. -->
<!-- Spec reviewed 2026-05-20 - SSE history-replay defense: BroadcastMessage interface in composables/useRealtime.ts now matches what the server actually emits (id: number, created_at: number) — the never-emitted `timestamp` field was removed. SchemaList watch(messages, …) now skips any event whose created_at predates the component's setup-time mountedAtSec; a defensive second line if the server-side cursor ever regresses to history replay. SchemaList's realtimeEnabled check hardened to String(config.public.enableRealtime) === '1' since Nuxt's runtime-config serializer coerces digit-string env vars to numbers, which silently disabled SSE in some builds. Public admin surface contract unchanged. -->
<!-- Spec reviewed 2026-05-20 - local-dev hardening: bump Nuxt 4.4.4 → 4.4.6 (latest 4.4.x patch); add vite.optimizeDeps.include for @vue/devtools-core and @vue/devtools-kit so Vite pre-bundles them at startup rather than discovering them mid-request and restarting the dev server (which kills the vite-node IPC socket and surfaces as "Vite Node IPC socket path not configured" 500 on the first /admin/ request). No runtime behaviour, public contract, or admin surface API change. -->
<!-- Spec reviewed 2026-05-11 - M4A-3 (#1432 / umbrella #1414) per-entity transition-history widget on entity detail pages: new <WorkflowTransitionHistoryTimeline /> component reads `workflow_audit` from the entity's attributes (already surfaced by ResourceSerializer via _data JSON blob round-trip — no backend change), renders reverse-chronological timeline with transition chip / from→to states / uid / timestamp. Wired into pages/[entityType]/[id].vue below SchemaView/SchemaForm. Renders nothing when audit empty. 4 new i18n strings; M4A-4/5 deferred -->
<!-- Spec reviewed 2026-05-11 - M4A-2 (#1430 / umbrella #1414) workflow detail page at /admin/workflows/[id]: states grid (id/label/weight/metadata) + transitions matrix (from×to grid with cell-level transition listing); new findById helper on useWorkflowDefinitions; WorkflowState TS interface gains `metadata: Record<string, unknown>`; backend serializer extended to include `metadata` per state (3-line additive change in WorkflowDefinitionsController); 10 new i18n strings; closes C-L3-01 detail-view portion; M4A-3/4/5 still deferred -->
<!-- Spec reviewed 2026-05-11 - M4A-1 (#1428 / umbrella #1414) workflows list page: new GET /api/workflow-definitions endpoint (admin-role-gated, returns `{data: WorkflowDefinition[]}` shape) wired via WorkflowDefinitionsController (packages/api/src/Workflow/) + WorkflowDefinitionsApiRouter (kernel-adjacent, exempted in bin/check-package-layers); new useWorkflowDefinitions composable + /admin/workflows page list editorial workflow with state/transition counts; api/composer.json now requires waaseyaa/workflows; 7 new i18n strings; closes C-L3-01 list-view portion; detail page / history / dry-run / guard editing deferred to M4A-2..M4A-5 -->
<!-- Spec reviewed 2026-05-11 - M1B-image/fonts (#1411) deferred indefinitely: admin SPA has zero <img>, zero background-image, zero static image assets (only public/favicon.ico), and uses the system font stack declared in app/assets/admin.css; adopting @nuxt/image and @nuxt/fonts now would add infrastructure for nothing; audit E-Mod-01 updated; M1B umbrella sub-set closed (eslint + icon adopted, image + fonts consciously deferred until SPA grows images or web fonts) -->
<!-- Spec reviewed 2026-05-11 - M2B-build-pipeline (#1412) E-Pkg-05 closed as stale: admin/contracts CI job at .github/workflows/admin.yml:22 already runs nuxi typecheck + npm run build:contracts (with dist/ artifact upload, 14-day retention) + ajv-cli bootstrap schema validation + vitest on every PR; dist/ correctly gitignored; build:contracts retained because it verifies emittability beyond what nuxi typecheck catches; documentation-only correction to audit and README -->
<!-- Spec reviewed 2026-05-10 - M1B-icon (#1411) @nuxt/icon adoption: module registered in nuxt.config.ts with mode=css and cssLayer=base; AdminShell mobile sidebar toggle's `&#9776;` HTML entity replaced with `<Icon name="heroicons:bars-3" />`; other unicode glyphs in SchemaList/pages and styled SVGs in auth flows kept as-is (out of XS scope) -->
<!-- Spec reviewed 2026-05-10 - M3B (#1413) SchemaForm bundle picker: when SchemaPresenter has a FieldDefinitionRegistry and the entity type has declared bundles, the bundle property now also carries x-widget=select, x-required=true, x-label='Bundle', x-weight=-100; SchemaForm renders it automatically as a top-of-form required select on create. Bundle stays hidden when no enum (pre-M3B behavior preserved). No SPA code change. -->
<!-- Spec reviewed 2026-05-10 - M3A (#1413) bundle filter wiring: SchemaPresenter exposes top-level `x-bundle-key` and (when FieldDefinitionRegistry is wired) `enum` of bundle names on the bundle property; SchemaRouter forwards the registry from HttpKernel; SchemaList renders a bundle-filter dropdown above the entity table and passes `filter[<bundleKey>]=<value>` to the list query; EntitySchema TS gains `'x-bundle-key'?: string | null`; en/fr i18n strings added; tenancy + SchemaForm bundle picker deferred to follow-ups -->
<!-- Spec reviewed 2026-05-10 - M2A (#1412) envelope tightening: packages/admin/package.json marked `"private": true` (no downstream consumers found in workspace); exports map and files array removed; engines added (`node: ">=22.12.0"`); README rewritten to a ~55-line publishable summary; build:contracts script retained as forward-compat type-check; admin contracts CI gate unchanged -->
<!-- Spec reviewed 2026-05-10 - #1419 follow-up: Playwright webServer in CI uses `npm run build && npm run preview` (production-mode, ~3s startup) instead of `npm run dev` (>240s in CI, dev-mode-specific stall; local devs keep dev mode for HMR) -->
<!-- Spec reviewed 2026-05-10 - #1419 follow-up: Playwright webServer timeout 120s → 240s to absorb CI cold-start of nuxt prepare + Vite optimize + Nitro build; local dev unchanged -->
<!-- Spec reviewed 2026-05-10 - Nuxt 4.4.5 dev-server regression (#1419): pinned `"nuxt": "4.4.4"` exact in packages/admin/package.json; Tech Stack table version unchanged; rationale and unpin condition in CHANGELOG -->
<!-- Spec reviewed 2026-05-10 - M1B (#1411) @nuxt/eslint adoption: nuxt.config.ts gains modules and eslint config; new packages/admin/eslint.config.mjs imports `.nuxt/eslint.config.mjs`; lint/lint:fix scripts wired; @typescript-eslint/no-explicit-any et al. set to warn (61 deferred baseline warnings); admin contracts unchanged -->
<!-- Spec reviewed 2026-05-10 - M1A (#1411) dep bumps: Tech Stack table refreshed to nuxt ^4.4.4, vue ^3.5.34, vue-router ^5.0.6, typescript ^6.0.3, @types/node ^25.6.2; admin contracts unchanged -->
<!-- Spec reviewed 2026-04-08 - normalizeAppBaseURL (ufo cleanDoubleSlashes + joinURL): shared by admin plugin and auth.global so adminPathBase matches normalized base; surface $fetch uses joinURL paths; packages/admin/app/runtime/normalizeAppBaseURL.ts -->
<!-- Spec reviewed 2026-04-08 - Admin fetch baseURL: useRuntimeConfig().app.baseURL (trailing slash) for $fetch/apiFetch and auth.global navigateTo; plugins/admin tests stub app.baseURL (#814); ufo joinURL for path joins -->
<!-- Spec reviewed 2026-04-08 - Admin SPA DX alignment; vue-router ^5 for Volar `sfc-route-blocks` + `nuxi typecheck`; IngestSummaryWidget typed ingest_log status guard for strict JSON:API attributes -->
<!-- Spec reviewed 2026-04-08 - merge-conflict resolution kept @types/node at ^25.5.2 in packages/admin/package.json and package-lock.json; no runtime/admin contract change -->
<!-- Spec reviewed 2026-04-08 - AdminSurfaceRoutePaths (waaseyaa/admin-surface PHP) + adminSurfaceRoutes.ts: named routes admin_surface.session|catalog|list|get|action; plugin bootstrap uses adminSurfaceFetchUrl(base, name); paths must stay aligned with WaaseyaaRouter registration (#815) -->
<!-- Spec reviewed 2026-04-08 - Optional session `ui` (headerLinks, sidebarItems): AdminSurfaceUiPayload + AdminSurfaceSessionData; GenericAdminSurfaceHost::buildAdminUi(); SPA maps via normalizeSurfaceUi into AdminRuntime.ui; AdminShell + NavBuilder (#756) -->
<!-- Spec reviewed 2026-04-08 - Session UI TypeScript mirror: `packages/admin/app/contracts/surface-ui.ts` duplicates admin-surface `contract/types.ts` ui shapes for `npm run build:contracts` (rootDir app only); keep in sync with PHP/contract (#756) -->
<!-- Spec reviewed 2026-04-09 - AdminSurfaceTransportAdapter: constructor takes normalizedAppBase; all CRUD/action URLs via adminSurfaceFetchUrl (parity with plugin bootstrap; #1161) -->
<!-- Spec reviewed 2026-04-09 ST-9 - JSON:API attribute contract: SPA consumes cast-aware payloads from ResourceSerializer (#1181) -->
<!-- Spec reviewed 2026-04-30 - Host extension typing: GenericAdminSurfaceHost constructor and AdminSurfaceServiceProvider::routes() accept EntityTypeManagerInterface only; concrete EntityTypeManager bindings forbidden in packages/admin* (mission #824 WP04 surface C, closes #836) -->
<!-- Spec reviewed 2026-05-01 - Admin-surface session contract: AdminSurfaceAccount.emailVerified?: boolean is now part of packages/admin-surface/contract/types.ts (camelCase, matching the PHP host payload at AdminSurfaceSessionData::toArray() and the SPA runtime read sites in auth.global and VerificationBanner). Spec language no longer uses snake_case email_verified (mission #824 WP07 surface A, closes #839) -->
<!-- Spec reviewed 2026-05-01 - Admin-surface catalog contract: AdminSurfaceCatalogEntry.description?: string is preserved in packages/admin-surface/contract/types.ts and locked in by CatalogBuilderTest regression assertions (description emitted when set, omitted when unset, matching the optional contract field) (mission #824 WP07 surface B, closes #840) -->
<!-- Spec reviewed 2026-05-01 - Admin-surface authority: payload shape is defined exclusively in packages/admin-surface/contract/types.ts (see packages/admin-surface/contract/README.md). This spec describes SPA runtime behaviour and references contract type names but does not redefine them; cross-boundary tests at tests/Integration/AdminSurface/ enforce conformance (mission #824 WP07 surface E, closes #851) -->

<!-- Spec reviewed 2026-08-07 - #2293: dashboard catalog cards are link-based
touch targets and therefore inherit the shell's generic flex link treatment.
The dashboard owns their component layout explicitly: column direction and
stretched children at every viewport. Browser coverage pins the computed
cascade and title/description geometry. No host-to-SPA contract changes. -->

## SPA bet (DIR-007)

The framework's committed workspace UI surface is the standalone Nuxt 3 + Vue 3 + TypeScript SPA in `packages/admin/`. This is a constitutional commitment (charter directive **DIR-007**, ratified by mission `charter-amendment-anokii-track-01KSEFE0`), not a default-able preference. Distribution maintainers building on Waaseyaa SHOULD consume the framework's Nuxt SPA either as-is or by extending it via the documented composables + page slots.

`packages/inertia` is the alternative protocol adapter, retained as **optional / experimental**. Distributions that prefer server-driven UI (e.g., for large permission trees, classification rule editors, or multi-tenant policy UI) may install `waaseyaa/inertia` explicitly. It is not bundled by `waaseyaa/full`. See `packages/inertia/README.md` for the Inertia entrypoint and `packages/admin/README.md` for the Nuxt entrypoint.

Changes to this commitment require a charter amendment (per `## Amendment Process` in `docs/governance/charter.md`), not just a spec edit.

## Authority

The host-to-SPA payload shape is defined in **`packages/admin-surface/contract/types.ts`** (see [`packages/admin-surface/contract/README.md`](../../packages/admin-surface/contract/README.md)). This document is the subsystem spec for the admin SPA runtime — components, composables, routes, schema-driven forms, auth flow — and references contract type names (`AdminSurfaceSession`, `AdminSurfaceCatalogEntry`, etc.) rather than redefining them. When this spec and the contract package disagree, the contract package wins; raise an issue against this spec to bring it back into alignment.

Two cross-boundary tests under `tests/Integration/AdminSurface/` enforce structural conformance between the backend emit and the contract; the audit (#851) flagged the prior governance drift where snake_case variants in this spec contradicted the camelCase contract. Use camelCase everywhere (e.g. `emailVerified`, `requireVerifiedEmail`).

## Optionality

- **`waaseyaa/admin-surface` (PHP)** is optional. Add it when you want the HTTP admin surface at **`/admin/_surface/*`** (fixed prefix, not configurable). Apps can omit it for headless or API-only setups.
- **The Nuxt admin UI** (`packages/admin`, `@waaseyaa/admin`) is optional. You may run **`admin-surface`** API routes without building or serving the SPA; production can use **`nuxt generate`** output copied to `public/admin/` when you want the UI.
- **When the SPA is not built**, visiting the app’s admin HTML route shows fallback HTML owned by **`admin-surface`** (`AdminSpaFallback`), which documents `/admin/_surface/*` endpoints and links to this spec. Apps should not duplicate that fallback unless intentionally overriding.

### Admin package path (CLI and CI)

Resolution order:

1. **`WAASEYAA_ADMIN_PATH`** — absolute path, or relative to the PHP project root; overrides Composer.
2. **`composer.json` → `extra.waaseyaa.admin_path`** — e.g. `packages/admin` in the framework monorepo, or `../waaseyaa/packages/admin` for a sibling checkout (Minoo).

**CLI** (from the Waaseyaa app root): `vendor/bin/waaseyaa admin:dev` runs `npm run dev` in the resolved admin directory; `vendor/bin/waaseyaa admin:build` runs `npm run generate`. For `admin:dev`, set **`NUXT_BACKEND_URL`** to the PHP app’s base URL (e.g. `http://127.0.0.1:8081`). If unset, it defaults to `http://{APP_HOST}:{APP_PORT}` (`127.0.0.1` and `8080` when those env vars are empty).

`admin:build` is a hermetic production-artifact operation rather than a dev
process. It resolves an absolute Node executable and npm CLI module, runs that
module through the same pinned Node binary, validates an exact
`package-lock.json`, performs `npm ci --include=dev` offline with lifecycle scripts disabled,
and runs generation with an empty explicit dotenv file from a disposable copy
containing only declared Nuxt source inputs. Local `.env`, `.nuxtrc`, arbitrary
scripts, dependency trees, and generated state are neither copied nor read by
the child. The child receives a
new closed environment: fixed CI/production/telemetry policy, a validated PATH,
disposable home, temp, npm configuration and c12 cache paths, a dedicated
project-local content-addressed npm cache under `storage/framework/admin-build/`, and only the
documented non-secret `NUXT_*`/build-ID inputs. Linux and Windows have distinct
closed OS allowlists. Project or ancestor `.nuxtrc`, project dotenv files,
unknown or credential-bearing project npm settings, unresolved npm environment
expansion, relative executables, and invalid OS paths refuse before launch.
An empty dedicated cache fails closed by default. An operator may explicitly
set `WAASEYAA_ADMIN_BUILD_ALLOW_PUBLIC_REGISTRY=1` to authorize one retry
against exactly `https://registry.npmjs.org/`; the distribution builder does so
for its supervised rebuild. That retry uses the same lock, dedicated cache, empty user/global/project credential
configuration, and disabled lifecycle scripts. The authorization flag itself
never enters the child environment, and no alternate registry is accepted.
Successful credential-free retrieval seeds the dedicated cache, so subsequent
builds can complete offline; lockfile integrity remains authoritative over
cached bytes.

Child stdout and stderr are captured to a fixed maximum and a 15-minute runtime
bound before crossing the kernel's mandatory sink sanitizer; overflow or timeout
drops the raw output and emits only a fixed code. npm error codes are classified
inside that boundary before line-scoped sanitization, so a sensitive dependency
name cannot suppress the closed `ENOTCACHED` retry decision. Buffering the complete bounded stream prevents a registered value
split across child writes—or across stdout and stderr—from bypassing exact
replacement. Registered values shorter than eight bytes are deliberately
ineligible for exact replacement to avoid destructive false positives, so all
synthetic build canaries exceed that floor.

After generation, every generated top-level tree, source map, Nuxt cache,
dependency build cache, and the final `admin-surface/dist` publishable tree is
inventoried in stable path order and scanned as bytes. Internal generated
aliases may point only within the Admin package; publishable-tree symlinks and external symlinks, unreadable or
changing files, known provider/private-key forms, registered synthetic canaries,
and credential-labelled high-entropy, 32+-character hexadecimal, and UUID-shaped material fail closed without putting the
matched bytes into evidence. Supported sibling/Composer Admin package paths use
a stable `admin-package/` logical evidence prefix. Published static directories
and files are normalized to `0755` and `0644` so a separate serving account can
read the verified output.

### WSL / Windows browser against a WSL-hosted dev server

Use **`npm run dev:wsl`** in `packages/admin` (Nuxt listens on `0.0.0.0`) when you need to open the admin dev UI from a Windows browser while Node runs in WSL. Equivalent: `nuxt dev --host 0.0.0.0` with your usual `NUXT_BACKEND_URL` pointing at the PHP server.

### Pre-built SPA distribution (no Node.js required)

The `waaseyaa/admin-surface` Composer package ships pre-built Nuxt SPA assets in its `dist/` directory. When a consumer installs `admin-surface` via Composer, the PHP `AdminSurfaceServiceProvider` serves the SPA from `vendor/waaseyaa/admin-surface/dist/` automatically — no Node.js build step needed.

**Two-tier SPA lookup** (in the `/admin/{path}` catch-all controller):

1. **App override:** `$projectRoot/public/admin/index.html` — checked first. Apps can build their own SPA here to override vendor assets.
2. **Vendor fallback:** `vendor/waaseyaa/admin-surface/dist/index.html` — pre-built by CI, ships via Composer.
3. **AdminSpaFallback:** If neither exists, a plain HTML page listing the `/_surface/*` API endpoints is returned.

Static assets (`_nuxt/*.js`, `_nuxt/*.css`, fonts, images) are served from the same two-tier lookup with explicit MIME types via `serveStaticFile()`, since PHP's built-in server defaults to `text/html` for all routed responses.

**CI automation:** The `.github/workflows/admin-dist.yml` workflow runs `nuxt generate` when `packages/admin/` changes on `main`, commits the output to `packages/admin-surface/dist/`, and opens a PR. After merge, the next tag distributes the assets via the splitsh-lite pipeline to Packagist.

**Freshness and reproducibility gates (blocking):** `bin/check-admin-dist-fresh` (D6) compares a line-ending-normalised content signature of the admin SPA source set (`packages/admin/app/**`, dependency/configuration files, `.nvmrc`, and the complete distribution/hermetic-build tool roster) against the committed `packages/admin-surface/dist.signature`, written by `bin/build-admin-dist` whenever the bundle is rebuilt. It fails when source or its build procedure advances without a rebuild, including a dependabot bump to the admin lockfile, so a stale committed bundle can never be tagged into a release. The build entrypoint derives Nuxt's build ID and stable positive metadata timestamp from a separate content-only signature; policy-only edits still stale the freshness marker but do not rewrite every prerendered HTML file. `bin/normalize-admin-dist` rewrites only the known wall-clock fields in Nuxt's build manifests and prerender payloads; an unexpected manifest count, identifier, timestamp type, HTML build identity, or payload shape fails the build. The checked-in normalization fixture proves that known volatile fields converge while real compiled-asset drift remains visible; the hermetic pipeline separately proves a stable inventory roster and scan verdict for identical declared inputs. Bit-identical whole-toolchain output is claimed only when two clean builds under the pinned Node 24/npm lock independently demonstrate it. The gate runs in `composer verify` **and** in the blocking `ci/verify-gates` CI job, so staleness fails the PR rather than depending on the out-of-band `admin-dist.yml` fix-up workflow to catch it after merge. Rebuild and re-sign with `bin/build-admin-dist`, then commit `packages/admin-surface/dist/` together with `packages/admin-surface/dist.signature`.

### Dev fallback account (auto-login for local development)

When running `composer run dev` (PHP built-in server), the framework can auto-authenticate as a `DevAdminAccount` with admin privileges — no login required.

**Three conditions must ALL be true:**

1. `PHP_SAPI === 'cli-server'` (i.e., running via `composer run dev` or `php -S`)
2. `APP_ENV=local` (development mode)
3. `WAASEYAA_DEV_FALLBACK_ACCOUNT=true` in `.env`

The skeleton's `.env.example` sets `WAASEYAA_DEV_FALLBACK_ACCOUNT=true` by default, so fresh `create-project` installs auto-authenticate. If any condition is missing, the admin SPA shows the login page instead — with no error message indicating why.

**To disable:** Set `WAASEYAA_DEV_FALLBACK_ACCOUNT=false` or remove it from `.env`. The account uses sentinel ID `PHP_INT_MAX` and is never persisted.

## Package

- Path: `packages/admin/`
- Package name: `@waaseyaa/admin` (private, version 0.1.0)
- Entry point: `packages/admin/app/app.vue` wraps `<NuxtLayout>` + `<NuxtPage />`
- Default layout: `packages/admin/app/layouts/default.vue` renders `<LayoutAdminShell>`
- Source directory: `packages/admin/app/` (configured via `srcDir: 'app/'` in nuxt.config.ts)

## Tech Stack

| Dependency     | Version   | Purpose                         |
|----------------|-----------|---------------------------------|
| Nuxt           | ^4.4.4    | SSR/SPA framework, file-based routing, auto-imports |
| Vue            | ^3.5.34   | Composition API, reactivity     |
| vue-router     | ^5.0.6    | Client-side routing (v5 exports Volar `sfc-route-blocks`; required for `nuxi typecheck` with Nuxt 4) |
| TypeScript     | ^6.0.3    | Type checking (devDependency)   |
| @types/node    | ^25.6.2   | Node type definitions           |
| @playwright/test | ^1.59.1 | E2E browser testing in CI and local `test:e2e` runs |

No CSS framework. The application-level stylesheet at
`packages/admin/app/assets/admin.css` defines the global controls and CSS
custom properties (`--color-primary`, `--color-surface`, etc.) for every route,
including shell-free entity-editor and page-builder embeds.

## API Proxy

Configured in `packages/admin/nuxt.config.ts`:

```ts
const backendUrl = process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080'

routeRules: {
  '/api/**': { proxy: `${backendUrl}/api/**` },
  '/admin/_surface/**': { proxy: `${backendUrl}/admin/_surface/**` },
},
```

All `/api/*` requests and `/admin/_surface/*` requests proxy directly to the PHP backend defined by `NUXT_BACKEND_URL`. The admin runtime no longer bootstraps through a bare `/_surface/` alias. The default backend is `http://127.0.0.1:8080`, matching the repo's PHP dev server and CI workflows.

### Optional page-builder surface (#2344)

Applications may register one or more named page-builder surfaces. The PHP
Admin Surface then exposes authenticated definitions, draft, command, and
exact-revision preview routes below
`/admin/_surface/page-builder/{surface}`. The transport converts the framework
HTTP request into `PageBuilderSurfaceRequest`; the page-builder host itself has
no direct Symfony request dependency. The configured surface permission is
checked server-side for every operation. Unknown surfaces and malformed,
oversized, extra, or missing command fields fail closed.

The command route additionally accepts an optional
`save_advisory_acknowledgements` body key: a list of at most 32 lowercase
64-character hexadecimal receipts. The required-key set is unchanged, so a
client that does not send receipts is unaffected. A malformed receipt is a `400`
before any surface call. An edit held for review answers `428` with
`code: SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` and the same allowlisted
`meta.save_advisories` projection the entity save path uses, so a client
branches on one machine code regardless of which surface raised it. Receipts
sent to a gateway that cannot carry them answer `501` with
`code: SAVE_ADVISORY_UNSUPPORTED` and no token in the payload. See
`docs/specs/save-advisories.md` §11.

#### Layout save-advisory review in the editor (#2475)

`PageBuilderSurfaceError` carries the closed `code` and `meta` allowlist, so the
page-builder transport reads the advisory contract without widening to an index
signature. `app/runtime/layoutSaveAdvisory.ts` is the only place the SPA reads
those two machine codes; it is a *reader* and mints nothing.

A held layout edit installs `advisoryReview` on `usePageBuilder`, holding the
exact pending command together with the advisories that candidate produced. The
workspace renders each advisory's `field` and `message` — never its token — and
blocks further editing while the review is open, exactly as an optimistic-
concurrency conflict does. It is **not** an embed lifecycle failure: nothing was
written, so the pending edit stays dirty and no `failure` event is emitted.

Confirming returns **exactly** the acknowledgement values received, on the same
command, document fingerprint, entity revision, and a fresh idempotency key, via
the client's optional `saveAdvisoryAcknowledgements` argument. The client omits
the body key entirely when there are none, so an ordinary save sends the
byte-identical body it always sent. The review is dropped before the retry, so a
second `428` — the candidate moved underneath the author — installs the new
advisory and its new receipts rather than replaying a superseded one. Tokens are
never synthesized, rewritten, persisted, or carried to another candidate.

Declining clears the prompt and changes nothing else: no write, no draft
replacement, and the edit stays dirty and unsaved. A rejected, superseded, or
wrong receipt returns another `428` or an ordinary refusal; either way nothing
is written. A malformed `meta.save_advisories` projection — one bad entry in the
list, a token that is not lowercase 64-hex, more than 32 entries — is a refusal,
not a partial review, so the editor can never present a review it cannot
faithfully acknowledge.

`501 SAVE_ADVISORY_UNSUPPORTED` sets `advisoryUnsupported` instead, rendered as
a distinct configuration/capability notice with **no confirm affordance**. It is
not an author-fixable validation error, and it is a real lifecycle failure
(`{ kind: 'server', status: 501 }`).

`AdminSpaLayoutAdvisoryContractTest` pins the two-language vocabulary — body
keys, machine codes, the five projected advisory fields, the token shape, and
the 32-receipt bound — against the real PHP host, so a rename on either side
fails rather than silently breaking the editor.
`e2e/page-builder-save-advisory.spec.ts` drives the five acceptance paths
through the real editor in a browser. A page-builder surface is registered by
the application rather than the framework, so there is no live surface in this
repository to point at; the spec serves the command endpoint itself using the
exact envelopes that contract test pins against the host.

The Admin SPA client added by the subsequent work package consumes this same
contract as Anokii. It must not use generic entity PATCH, a direct repository
save, or a client-private command vocabulary for layout edits.

An application may also declare `x-preview: { action: string }` on an
ID-scoped editable entity schema. The detail page then exposes a draft-preview
action and calls that exact host-declared Admin Surface action with the entity
ID. A successful response must contain a same-origin absolute-path
`preview_url`; the SPA rejects other URL shapes and renders the accepted URL in
an application dialog. The host remains authoritative for bundle eligibility,
access, revision selection, signature lifetime, non-indexing, and cache policy.
Absence of `x-preview` means the client exposes no preview control. This is a
capability extension, not a hard-coded content-type list.

### Cast-aware entity attributes (#1181)

Entity CRUD and catalog responses under `/api/*` use **`ResourceSerializer`**, which builds `attributes` from **`EntityValues::toCastAwareMap()`** (see `docs/specs/jsonapi.md` and `docs/specs/entity-system.md`). Implications for the SPA:

| Concern | Contract |
|---------|----------|
| **Read** | JSON `attributes` reflect domain types after server-side normalization (e.g. ISO-8601 strings for datetimes, backing scalars for enums). Do not assume the raw SQLite/JSON blob shape the entity stores internally. |
| **Write** | `PATCH`/`POST` bodies use JSON-native scalars; the PHP `set()` path runs `castOut` so the client does not send PHP objects. |
| **Forms / widgets** | Align displayed values with JSON:API types returned by the API; when adding new entity fields with `$casts`, extend serializers only if a new JSON shape is required beyond `normalizeAttributesForJson()`. |

Presentation map (server): `EntityValues::toCastAwareMap` → `ResourceSerializer` → admin `useApi` / generated clients. Persistence map stays `toArray()` on the server only — the SPA never receives that shape for standard CRUD.

### Base URL

The admin SPA is served under the `/admin/` subpath, configured via `app.baseURL: '/admin/'` in nuxt.config.ts. Playwright E2E tests also use `http://localhost:3000/admin` as the base URL.

The app mount is not an API prefix. Framework JSON API routes remain rooted at
`/api/*`, so `useApi()` always supplies `baseURL: '/'`. The PHP admin SPA
catch-all accepts ordinary client-side routes below `/admin/*` but excludes the
first path segments `_surface` and `api`. Consequently an unknown
`/admin/api/*` path cannot be converted into a successful HTML shell response;
the router's normal non-success missing-route response remains visible, including
its actual content type.

### Runtime Config

Exposed via `useRuntimeConfig().public`:

| Key | Env Var | Default | Purpose |
|-----|---------|---------|---------|
| `enableRealtime` | `NUXT_PUBLIC_ENABLE_REALTIME` | `'0'` in dev, `'1'` in production | Disable SSE in dev to avoid php -S single-process request starvation |
| `appName` | `NUXT_PUBLIC_APP_NAME` | `'Waaseyaa'` | Override site name (e.g. "Minoo"). Also feeds `app.head.title` so the static prerendered `<title>` matches the runtime brand before JS hydration. |
| `docsUrl` | `NUXT_PUBLIC_DOCS_URL` | `'https://github.com/jonesrussell/waaseyaa'` | Quickstart docs link used by onboarding prompt |
| `baseUrl` | `NUXT_PUBLIC_BASE_URL` | `'/admin'` | Base URL for subpath mounting, used by admin plugin to prefix surface API paths |

Private runtimeConfig (server-side only, not exposed to the browser):

| Key | Env Var | Default | Purpose |
|-----|---------|---------|---------|
| `backendUrl` | `NUXT_BACKEND_URL` | `'http://127.0.0.1:8080'` | PHP backend URL used by Nitro proxy rules at server startup; not accessible via `useRuntimeConfig().public` |

### Nitro Prerender

`nitro.prerender.failOnError` is set to `false` because `/login` is proxied to PHP during `nuxt generate` and the backend may be unreachable in CI.

## Composables

All composables are in `packages/admin/app/composables/`. Nuxt auto-imports them.

### useApi (`packages/admin/app/composables/useApi.ts`)

Shared fetch wrapper for all `/api/*` calls. Ensures `baseURL: '/'` (bypasses Nuxt's `app.baseURL` prefix) and `credentials: 'include'` (sends session cookie).

CSRF (#2177 F1 prerequisite): on non-safe methods (anything but `GET`/`HEAD`/`OPTIONS`), `apiFetch` reads the `XSRF-TOKEN` cookie (URL-decoding it) and sends it as the `X-XSRF-TOKEN` header — but **only to same-origin destinations**; absolute or protocol-relative URLs pointing at another origin never receive the token. Safe methods and token-less sessions send no header, and a caller-supplied `X-XSRF-TOKEN` header is never overwritten. The cookie is seeded by API responses carrying both an authenticated account and the `waaseyaa_uid` login-session marker (the boot `GET /api/user/me` in practice; bearer-only requests are excluded — see `docs/specs/security-defaults.md` "CSRF token cookie"). This is what lets routes declared with `RouteBuilder::requireCsrf()` (JSON content type included) accept SPA mutations.

```ts
function useApi(): {
  apiFetch<T>(path: string, options?: Record<string, unknown>): Promise<T>
}
```

**All `/api/*` calls must use `apiFetch`** — raw `$fetch` breaks when `app.baseURL` is set to a subpath like `/admin/`. Surface API calls are handled separately by the admin plugin, which resolves bootstrap URLs via `adminSurfaceFetchUrl(normalizedAppBase, 'admin_surface.session' | 'admin_surface.catalog')` in `packages/admin/app/runtime/adminSurfaceRoutes.ts`, aligned with `Waaseyaa\AdminSurface\AdminSurfaceRoutePaths` and the `admin_surface.*` route names on the PHP router. The plugin uses `$fetch` with implicit absolute paths from `joinURL` (equivalent to `baseURL: '/'`) since async Nuxt plugins can't call composables.

### useEntity (`packages/admin/app/composables/useEntity.ts`)

CRUD operations against the JSON:API backend. Returns plain functions (not reactive state).

```ts
function useEntity(): {
  list(type: string, query?: { page?: { offset: number; limit: number }; sort?: string }):
    Promise<{ data: JsonApiResource[]; meta: Record<string, any>; links: Record<string, string> }>
  get(type: string, id: string): Promise<JsonApiResource>
  create(type: string, attributes: Record<string, any>, saveAdvisoryAcknowledgements?: string[]): Promise<JsonApiResource>
  update(type: string, id: string, attributes: Record<string, any>, saveAdvisoryAcknowledgements?: string[]): Promise<JsonApiResource>
  remove(type: string, id: string): Promise<void>
  search(type: string, labelField: string, query: string, limit?: number): Promise<JsonApiResource[]>
}
```

Key types:
```ts
interface JsonApiResource {
  type: string; id: string; attributes: Record<string, any>
  relationships?: Record<string, any>; links?: Record<string, string>; meta?: Record<string, any>
}
interface JsonApiDocument {
  jsonapi: { version: string }; data: JsonApiResource | JsonApiResource[] | null
  errors?: Array<{ status: string; title: string; detail?: string }>
  meta?: Record<string, any>; links?: Record<string, string>
}
```

- `list()` uses offset-based pagination: `page[offset]`, `page[limit]`
- `search()` uses catalog `reference.search` and optional `reference.sort` metadata with 250ms debounce on the widget side. The generic canonical operator is `STARTS_WITH`; missing or malformed metadata disables the search. Minimum 2 characters required.
- All methods should use `apiFetch` from `useApi()` for imperative data fetching (ensures correct `baseURL` and `credentials`).
- **`get()` serves the working copy to editors (CW-v1 option-1, #1920 PR-3).** Both `SchemaView` (the entity page's "view" sub-mode) and `SchemaForm` (its "edit" sub-mode) call the SAME `useEntity().get()` — there is no client-side signal distinguishing them, and `AdminSurfaceTransportAdapter.get()`'s request shape/URL is unchanged. The server (`GenericAdminSurfaceHost::get()`) decides transparently: an account with entity UPDATE access receives the entity's working copy (the tip revision — draft content, if a forward draft is in flight); a view-only account keeps receiving the published (`find()`) entity. This is a deliberate deviation from JSON:API's `?workingCopy=1` opt-in param — documented as "unconditional for editors" — because the admin surface's single GET call backs both sub-modes, so a param would need a signal the transport does not carry. `update()`/`SchemaForm`'s save round trip needs no change either: it already delegates through `JsonApiController::update()`, whose PATCH target is now the working copy too. Full contract: `docs/specs/api-layer.md` "GET single"/"PATCH — update" (`?workingCopy=1`, the JSON:API-surface equivalent).
- **Workflow authority reaches the working copy (#2081).** The shared entity-view policy admits a node only when the authenticated principal has a permission- and group-valid transition outgoing from that node's current working-copy state. Generic detail, access-checked list/count, edit-load, and workflow discovery/execution therefore agree without an admin-host bypass. Existing update permission is still required to edit; the workflow rule grants no create/update/delete operation. Sealed fields remain filtered by the existing node field policy, and ordinary published content remains visible through `access content` even when the principal has no outgoing workflow authority.

### useSchema (`packages/admin/app/composables/useSchema.ts`)

Fetches and caches JSON Schema for an entity type. Drives all form rendering.

```ts
function useSchema(entityType: string): {
  schema: Ref<EntitySchema | null>; loading: Ref<boolean>; error: Ref<string | null>
  fetch(scope?: { id?: string; bundle?: string }): Promise<void>
  invalidate(scope?: { id?: string; bundle?: string }): void
  sortedProperties(editable?: boolean): [string, SchemaProperty][]
}
```

Key types:
```ts
interface SchemaProperty {
  type: string; description?: string; format?: string; readOnly?: boolean
  enum?: string[]; minimum?: number; maximum?: number; maxLength?: number
  'x-widget'?: string; 'x-label'?: string; 'x-description'?: string
  'x-weight'?: number; 'x-required'?: boolean; 'x-enum-labels'?: Record<string, string>
  'x-target-type'?: string; 'x-access-restricted'?: boolean; 'x-cardinality'?: number
}
interface EntitySchema {
  $schema: string; title: string; description: string; type: string
  'x-entity-type': string; 'x-translatable': boolean; 'x-revisionable': boolean
  'x-workflow'?: { bound: boolean; id: string | null }
  properties: Record<string, SchemaProperty>; required?: string[]
}
```

- Endpoint: `GET /api/schema/{entityType}` returns `{ meta: { schema: EntitySchema } }`
- **Bundle-aware fetch:** `fetch(scope?)` accepts either `{ id }` for an existing
  record or `{ bundle }` for the second stage of create. The backend resolution
  lives in `GenericAdminSurfaceHost::handleSchema`: explicit `bundle` wins, else
  the bundle is read from the entity named by `id`. The base create schema exposes
  `x-bundle-key` plus a required select whose enum is the registry's bundle roster
  filtered through `checkCreateAccess(entityType, bundle, account)`. Selecting a value requests `{ bundle }`, replaces the schema with its
  bundle-specific fields, preserves shared values/the selected bundle, and drops
  fields belonging only to a previously selected bundle before submission. A
  rejected scope replaces the form with its clear transport error. Types without
  a non-empty bundle enum retain the existing one-stage create behavior.
- The generic host validates a bundled create against that same authoritative
  roster before repository creation: missing, empty, or unknown values under the
  entity type's actual bundle key return 422. An explicit create-schema request
  (`bundle` with no `id`) rechecks bundle-aware `checkCreateAccess()` before the
  selected bundle's fields are presented and returns a generic 403 with no bundle
  or field detail when denied; persistence checks the same boundary again. When no bundle is authorized, the base
  schema retains the structural bundle key but hides and locks its property rather
  than exposing an empty or free-text selector. An explicitly requested bundle
  can also scope an edit schema, so that path validates structural membership but
  does not require create permission. For id-scoped schemas the stored entity
  bundle is authoritative over any caller-supplied bundle hint, preventing an
  edit request from being redirected into another bundle's schema.
- **Workflow-owned fields:** when the workflows binding resolver is available,
  a scoped schema includes `x-workflow: { bound, id }`. A bound schema omits
  `workflow_state` and `status` from `properties`: state and publication are
  changed only through server-returned transitions, never a free-text field or
  unrelated checkbox. An unbound schema reports `bound: false` and preserves
  those ordinary fields exactly. Exact bundle bindings use the explicit/edit
  bundle scope; the base schema can identify wildcard bindings without guessing
  an exact bundle.
- Module-level `Map<string, EntitySchema>` cache keys include entity type, id, and
  bundle independently, so base, edit, and per-bundle schemas cannot collide.
  `invalidate(scope?)` clears the matching scoped key.
- `sortedProperties(true)` filters out system `readOnly` fields (id, uuid) and hidden widgets, but keeps `x-access-restricted` fields (rendered as disabled inputs). Sorted by `x-weight` ascending.
- `sortedProperties(false)` returns all properties sorted by weight.

### Runtime Bootstrap (`packages/admin/app/plugins/admin.ts`)

The root Nuxt plugin is the authoritative bootstrap for `$admin`. On non-public auth pages it:

1. Normalizes `useRuntimeConfig().app.baseURL` and uses `adminSurfaceFetchUrl` for bootstrap `$fetch` URLs (`admin_surface.session`, `admin_surface.catalog`) and for **`AdminSurfaceTransportAdapter`** (list, get, and `admin_surface.action` for create/update/delete/schema/custom actions) — single path contract with PHP `AdminSurfaceRoutePaths` (#1161).
2. Fetches `SurfaceResult<AdminSurfaceSession>` from the session route URL.
3. Fetches `SurfaceResult<{ entities: AdminSurfaceCatalogEntry[] }>` from the catalog route URL after a successful session.
4. Hydrates the shared auth-state keys `waaseyaa.auth.user` and `waaseyaa.auth.checked` from the authoritative session bootstrap before returning the runtime.
5. Builds `AdminRuntime` from `SessionAuthAdapter`, `AdminSurfaceTransportAdapter`, the resolved account/tenant, a local admin runtime catalog contract derived from the surface bootstrap payload, and **`ui`** — normalized from optional session `ui` (`headerLinks`, `sidebarItems`) via `normalizeSurfaceUi()` in `packages/admin/app/runtime/normalizeSurfaceUi.ts` (defensive filtering; defaults to empty arrays when absent).
6. Returns `{ provide: { admin: runtime } }`, or `{ provide: { admin: null } }` for public auth pages and unauthenticated redirects.

**Session capability projection (PHP → SPA, #2177):** `AdminSurfaceSession.capabilities`
is a server-authoritative `Record<string, boolean>` computed by
`GenericAdminSurfaceHost::resolveSession()` from the resolved principal's
`hasPermission()` over an explicit, bounded constructor allowlist
(`$capabilityAllowlist`; empty by default, `{}` in JSON when unconfigured).
Only allowlisted identifiers are ever serialized. The framework default
(`AdminSurfaceServiceProvider::defaultCapabilityAllowlist()`) projects exactly
`mcp.approval.view` and `mcp.approval.decide` when the MCP package is
installed. Pages and navigation consume it through `useAdmin().can(permission)`,
which is fail-closed: it returns `true` only when the value is exactly boolean
`true`, and there is no role-based fallback. This gates UI affordances only —
route-level `_permission` checks remain the enforcement boundary.

**Session UI customization (PHP → SPA):** Hosts extend `GenericAdminSurfaceHost` and override `buildAdminUi(AccountInterface): ?AdminSurfaceUiPayload` to attach non-empty `AdminSurfaceUiPayload` to `AdminSurfaceSessionData`. JSON includes a top-level `ui` object only when the payload has at least one valid header link or sidebar item. Sidebar `group` values that look like i18n keys (`nav_*`) are passed through `t()` in `NavBuilder`; an empty/missing `group` uses `nav_group_custom` (“Shortcuts”). External targets use `external: true` or absolute URLs (`http(s):`, `//`, `mailto:`, `tel:`) and render as `<a target="_blank" rel="noopener noreferrer">`.

The same payload accepts the closed optional `navigationMode`. Missing or `full`
preserves the static MCP, Operations, and Governance sections. `catalog-only`
renders dashboard/custom/catalog navigation plus shell-owned session, locale, and
logout controls, but omits those static sections. Unknown values normalize to
`full`. This changes presentation only: route access and every controller policy
remain unchanged.

**Per-record history (#2419 / #2421).** `/admin/{entityType}/{id}/history` is a
record's own addressable history surface, generated by
`AdminDestinationPaths::history()`. It exists because neither of the nearby
surfaces answers the question: the record editor answers "what does this record
say now", and `pipeline.vue` answers for the whole entity type.

Served by the `history` action on `GenericAdminSurfaceHost`, which returns the
record's revisions with `revisionId`, `createdAt`, `author`, `log`, `isCurrent`,
and `isLatest`.

Three properties are load-bearing:

- **Gated by the record's own view access**, fail-closed exactly as `get()` is.
- **Metadata only, never field values.** A revision row carries the record's
  field content, and echoing it here would let history bypass the field-access
  rules the record's read path enforces.
- **A refusal exposes no surface, not an empty one.** An empty timeline is
  itself a disclosure — "this record exists and has never been touched" — so
  the page distinguishes *refused* (`history-unavailable`) from *genuinely
  empty* (`history-empty`). A non-revisionable entity type is refused with 404
  rather than raising.

`isCurrent` (the published/default revision) and `isLatest` (the tip) are
reported separately rather than collapsed: they differ whenever a forward draft
is in flight, which is the case an editor most often opens history to
understand. `author` distinguishes `null` (written without an acting context)
from `0` (the anonymous account acted); collapsing the two would attribute an
unattributed revision to a real account.

**Revision recovery (#2464).** Metadata history stays content-free. Selecting a
row invokes the distinct `revision` action, which first authorizes the record
itself and only then loads the saved revision. Historical attributes are
released only after `EntityAccessHandler` composes `view_revision` through
`RevisionPolicyComposition` against that snapshot; current-entity view is not
enough. Protected entity-read authority and context-aware policy evaluation use
the same historical snapshot. A denied revision is concealed as a missing revision. The ordinary
`ResourceSerializer` then projects the snapshot with the acting account, so
dynamically forbidden and internal fields never enter the response. A
record-view refusal occurs before revision storage is consulted and therefore
exposes no revision-existence oracle.

`restore-revision` requires both record view and update access, `view_revision`
on the source snapshot, a positive observed latest revision id, and the opaque
mutation token cached by the Admin transport's prior record GET. Changed-field
edit authority is the same `RevisionRestoreChangedFields` set used by the AI
restore tools: revision metadata and live-preserved keys are excluded, while
workflow and every other written privilege-bearing field—including current-only
keys removed by restore—are not. Storage preserves the live publication pointer,
status, and credential hashes rather than copying their historical values. The explicit
revision comparison produces the operator-readable stale-history 409; the token
claim is the atomic storage fence that closes a race after that comparison. The
host delegates to `EntityRepository::rollback()`: selected content is copied
into a successor draft, later history remains intact, and workflow/publication
pointers are not moved by the surface. The response contains the source and
resulting revision ids plus a newly fenced entity. The rollback audit
distinguishes the pre-operation revision, selected source revision, and
resulting revision and never records field content.

Exact preview is absent unless the application binds
`AdminRevisionPreviewAuthorityInterface`. The host proves record/revision
existence and `view_revision` access before calling it, and accepts only a
grant naming the exact selected revision. Grant URLs must be root-relative or
HTTPS and must not contain `\` or `..`. Applications own signing, expiry,
audience, and the render route. Desktop/mobile controls resize the same
exact-revision iframe and have no storage or publication semantics.

`EntityRevisionRecovery` owns loading, selection, field comparison, restore
conflict recovery, and preview. Both the addressable full history page and
shell-free `EntityEditorWorkspace` render that component; consumers must not
fork either path. Structured date/time values are compared as serialized
canonical field values and remain editable only through the schema editor.

**Route layout.** The record page moved from `pages/[entityType]/[id].vue` to
`pages/[entityType]/[id]/index.vue` so `history.vue` can be its sibling. Nuxt
would otherwise treat `[id].vue` as a parent layout for `[id]/history.vue`,
nesting history *inside* the editor, which is not what the surface is. The
editor's URL is unchanged.

**Bundle-scoped destinations (#2418 / #2420).** `AdminSurfaceRoutePaths` is the
single source for the `_surface` HTTP API; its companion
`Waaseyaa\AdminSurface\AdminDestinationPaths` is the single source for the SPA's
own **pages** — the destinations a consumer links to from outside the SPA:

| Generator | Destination | SPA page |
|---|---|---|
| `list($type, $bundle = null)` | `/admin/{type}` · `?bundle={b}` | `pages/[entityType]/index.vue` |
| `filteredList($type, $filters)` | `/admin/{type}` · declared `filter[field][operator/value]` query | `pages/[entityType]/index.vue` |
| `create($type, $bundle = null)` | `/admin/{type}/create` · `?bundle={b}` | `pages/[entityType]/create.vue` |
| `edit($type, $id)` | `/admin/{type}/{id}` | `pages/[entityType]/[id]/index.vue` |
| `pipeline($type)` | `/admin/{type}/pipeline` | `pages/[entityType]/pipeline.vue` |
| `history($type, $id)` | `/admin/{type}/{id}/history` | `pages/[entityType]/[id]/history.vue` |

Encoding is the generator's job, and an empty entity type or record id is
refused rather than producing a malformed path. Generating a destination is
**not** an access decision — callers keep performing their own capability checks,
exactly as they do today.

The bundle query parameter is one contract spanning PHP and the SPA:
`AdminDestinationPaths::QUERY_BUNDLE` and `app/runtime/bundleScope.ts`'s
`BUNDLE_QUERY_PARAM` are pinned to each other by test, as is each destination's
correspondence to the page file that serves it — moving a page breaks the build
rather than every consumer's links.

The same list page restores schema-declared filters from
`filter[field][operator]` / `filter[field][value]` query controls.
`filteredList()` owns their deterministic RFC3986 encoding so a downstream
shell can link to, for example, the declared workflow-state Draft view without
assembling private-looking bracket keys. It accepts one tuple per field, sorts
fields before encoding, and refuses an empty field, operator, value, tuple, or
filter set. The generator does not declare a filter or widen query authority:
the list metadata, query policy, and per-entity access checks still decide what
the destination may show.

**Field names are one closed grammar.** A declared list field and a generated
filter key must both match `\A[A-Za-z_][A-Za-z0-9_.]*\z`. `ListMetadata` and
`AdminDestinationPaths` read that pattern from the same `SurfaceFieldName`
constant, so a name one accepts the other cannot refuse. Dotted and underscored
names are canonical; brackets, whitespace, control characters, leading digits,
slashes, and backslashes are not. A bracket in a field name would otherwise
forge a differently shaped query key, and a space would address a field no
declaration can name.

**A serialized operator is compared, never executed.** `filteredList()` emits
only canonical `SurfaceFilterOperator` values, and the list restores a control
only when the URL carries both members of the pair *and* the URL operator is
exactly the operator that field declares:

| URL pair | Result |
|---|---|
| operator matches the declaration, value present | value restored |
| operator absent, or value absent | ignored; control keeps its default |
| operator disagrees with the declaration | ignored |
| operator names no `SurfaceFilterOperator` case | ignored |
| field is not declared by the metadata | never consulted |

The executed query always uses the metadata-declared operator, so a hand-edited
link can preselect a declared view but can never widen, narrow, or redefine the
comparison the list performs. This applies identically to the search control and
to ordinary filters.

**Degradation is the contract, not a nicety.** A bundle-scoped link can go stale
or be hand-edited, so both pages fall back to the unscoped view rather than
erroring:

- `readBundleScope()` accepts only a non-empty string. A repeated parameter
  arrives as an array and a blank one as an empty string; both mean "no usable
  scope".
- `SchemaForm` drops a bundle the base schema does not advertise in its
  `x-bundle-key` enum **before** requesting it. Issuing a scoped schema request
  the server will refuse would turn a stale link into an error page, which is
  the opposite of the requirement. Schemas advertising no enum keep the prior
  behaviour, there being nothing to check against.
- `SchemaList` seeds the **visible** bundle control rather than a hidden filter,
  so a scoped listing is apparent and the operator can widen it. A value outside
  `bundleOptions`, or an entity type that is not bundle-shaped, lists unscoped.

Per-record **History** is covered above (#2419 / #2421).

**Consumer-supplied host registration (#2422).** An application replaces the
default `GenericAdminSurfaceHost` by binding an
`AdminSurfaceHostFactoryInterface` in its service provider's `register()`.
`AdminSurfaceServiceProvider::routes()` resolves that factory and registers the
canonical `admin_surface.*` routes against the host it returns — same paths,
methods, and authentication requirements, through the framework's own
`promoteRefusalStatus()`, registered **exactly once**. An install that binds no
factory keeps the generic host, unchanged.

It is a *factory*, not the host itself, because `routes()` runs after every
provider has registered (`BuiltinRouteRegistrar`), so a host built there may
depend on sibling bindings; a host constructed during `register()` cannot.
Exactly one factory is supported.

Applications must not register those paths themselves. `WaaseyaaRouter::addRoute()`
refuses a duplicate route name, and the workaround that refusal used to force —
shadowing all five paths under application-specific names at `->priority(100)`
and privately reimplementing the refusal-status promotion — forks the refusal
contract that #2161 established, leaving two implementations only one of which
framework tests see. That duplicate-route refusal is deliberately retained: an
accidental second registration still fails loudly at boot.

**Host extension typing (mission #824 WP04 surface C).** `GenericAdminSurfaceHost` and `AdminSurfaceServiceProvider::routes()` accept `Waaseyaa\Entity\EntityTypeManagerInterface`, never the concrete `EntityTypeManager`. Subclasses extending the host receive the interface and must not narrow that parameter. The acceptance gate is `grep -rn 'EntityTypeManager[^I]' packages/admin*` returning no results — re-run it whenever you touch admin-surface code or its tests.

This plugin is the source of truth for `$admin` injection and for composables that call `useAdmin()`.

`runtime.catalog` preserves each `AdminSurfaceCatalogEntry` field and action declaration and carries the admin-facing metadata used by the SPA (`description`, `disabled`). The optional `reference` member is the authoritative entity-reference presentation/query contract: `labelField` names the display attribute, while nullable `search` (`field`, canonical `STARTS_WITH` operator) and `sort` (`field`, direction) members explicitly enable those operations. Missing or malformed metadata disables lookup; clients must never guess `title`, retry an unfiltered list, or fall back to an ID-derived catalogue. The generic host derives safe metadata from `EntityTypeInterface::getKeys()['label']`, omits malformed/internal/credential label keys, and leaves entity/field authorization to the existing server list boundary. Components that need action-aware UI state must derive it from the injected catalog rather than by issuing mount-time transport requests to discover whether an action exists. For contract builds, the admin package maintains a local TypeScript mirror of the admin-surface payload shape under `app/contracts/` so generated declarations do not import files from outside `packages/admin/app`.

#### Admin Runtime Availability Contract

- Admin composables that depend on `$admin` (`useAdmin()`, `useEntity()`, and `useSchema()`) require a bootstrapped admin runtime.
- They must fail with one explicit invariant error when `$admin` is unavailable instead of relying on implicit cast failures or null dereferences.
- Runtime absence is therefore a governed bootstrap violation, not an undefined composable state.
- Focused unit tests assert this contract in `packages/admin/tests/unit/composables/useAdminRuntime.test.ts`.

#### Shared Auth-State Hydration Contract

- Shared auth state uses the stable keys:
  - `waaseyaa.auth.user`
  - `waaseyaa.auth.checked`
- The admin plugin must hydrate these keys from the server-side `/admin/_surface/session` bootstrap.
- Hydration must occur before composables or components consume shared auth state.
- Public auth routes clear these keys to `null` / `false` and skip runtime bootstrap.
- Redirecting unauthenticated flows clear the user value and mark the auth check as completed for the current bootstrap attempt.
- Invariant:
  - Admin SPA runtime must initialize and hydrate shared auth state using the authoritative session bootstrap keys. These keys must remain stable and consistent across runtime, composables, and components.
- Tests assert this hydration behavior in `packages/admin/tests/unit/plugins/admin.test.ts`.
- Degraded bootstrap coverage also asserts:
  - client-side public auth routes return `admin: null` without fetching the surface API
  - 401 session bootstrap and missing catalog bootstrap return `admin: null`, clear the shared user, and mark auth as checked
  - unreachable surface API bootstrap fails with a fatal 503 error

### useLanguage (`packages/admin/app/composables/useLanguage.ts`)

Simple i18n with token replacement.

```ts
function useLanguage(): {
  t(key: string, replacements?: Record<string, string>): string
  locale: ComputedRef<string>; setLocale(locale: string): void
}
```

- Translation files: `packages/admin/app/i18n/en.json`, `packages/admin/app/i18n/fr.json`
- Replacement syntax: `{token}` in translation strings
- Module-level `currentLocale` ref shared across all callers
- Falls back to the key itself when no translation is found

### useRealtime (`packages/admin/app/composables/useRealtime.ts`)

Server-Sent Events connection for real-time entity updates.

```ts
function useRealtime(channels?: string[], options?: { autoConnect?: boolean }): {
  messages: Ref<BroadcastMessage[]>; connected: Ref<boolean>; error: Ref<string | null>
  sessionToken: Ref<string | null>
  connect(): void; disconnect(): void; reconnect(): void
}
interface BroadcastMessage {
  id: number; channel: string; event: string; data: Record<string, unknown>; created_at: number
}
```

- Endpoint: `GET /api/broadcast?channels={comma-separated}` (SSE)
- Default channel: `['admin']`
- Runtime constants:
  - `REALTIME_ENDPOINT_PATH = '/api/broadcast'`
  - `DEFAULT_REALTIME_CHANNELS = ['admin']`
- **Shared connection (one per channel set).** Consumers asking for the same
  channel set share a single, module-level `EventSource` and the same
  `messages`/`connected`/`sessionToken` refs (`wayfinding-showcase-hardening`,
  P0-1). The admin SPA mounts several consumers at once — the persistent
  `WayfindingOverlay` (via `useBeacons`) plus each `SchemaList` — and an
  EventSource pins a FrankenPHP worker for the life of the stream; one connection
  per consumer multiplied worker pressure into a hydration "reconnect storm".
  Sharing is ref-counted: the connection is torn down only when its LAST consumer
  unmounts, so a `SchemaList` leaving on navigation never kills the overlay's
  stream. `connect()` is idempotent. `__resetRealtime()` is a test-only reset.
- Exponential backoff reconnect: delay = `min(3000 * 2^(retryCount-1), 30000)`, max 10 retries
- Message buffer: last 100 messages (ring buffer via `slice(-99)`)
- Event types: `entity.saved`, `entity.deleted` (used by SchemaList for auto-refresh), `wayfinding.beacon` (consumed by `useBeacons`; the server replays still-active beacons on (re)connect, so a beacon survives reconnects/reloads)
- **Session pairing token (Wayfinding presenter pairing):** the `connected` SSE frame carries this connection's own non-secret `sessionToken` (`substr(sha256(session_id), 0, 32)`, server-derived). `useRealtime` captures it into the `sessionToken` ref; `useBeacons` re-exposes it. The **supported, race-free read path** (no SSE interception) is **`GET /api/wayfinding/session`** → `{ data: { sessionToken, channel } }`, surfaced in-page as **`data-wf-session`** on the document root (`plugins/wayfindingSession.client.ts`). A presenter-pairing UI / guiding agent reads either to target this exact session's beacon channel via `POST /api/wayfinding/beacons` — see [wayfinding.md](wayfinding.md). (alpha.234 exposed it in the composable, mission `wayfinding-stress-remediation-01KVGK4Q`; the supported handle was added by `wayfinding-showcase-hardening`, P0-2.)
- Invariant: the SPA realtime client targets the canonical backend broadcast SSE endpoint and default admin channel; this contract is asserted in unit tests.

## Schema-Driven Forms

The form rendering pipeline:

1. `SchemaForm` calls `useSchema(entityType).fetch()` to get the JSON Schema
2. `sortedProperties(true)` returns editable fields sorted by `x-weight`
3. Optional `x-form-sections` presentation metadata groups those fields into
   operator tasks without changing their schema, access, validation, or write
   authority
4. For each field, `SchemaField` resolves the widget component from `x-widget`
5. Each widget receives `modelValue`, `label`, `description`, `required`, `disabled`, `schema`

`SchemaForm` also handles candidate-bound save advisories (#2467). A 428
`SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` transport failure is rendered in a
focusable `role=status` review panel. The explicit confirmation button retries
the JSON-captured writable candidate with only the returned acknowledgement
tokens. Any field or bundle edit, or an ordinary new submit, clears the pending
review; the normal Save button never implies acknowledgement. The Admin surface
projects only the allowlisted advisory `code` and `meta.save_advisories` onto
the envelope; `TransportError.meta` is that same closed `AdminSurfaceErrorMeta`
type, not an open `Record<string, unknown>`. A missing mutation-token HTTP 428
stays a codeless precondition error so the two 428s are distinguishable without
parsing prose.

`x-form-sections` is an ordered list of `{id, label, description?, fields,
collapsible?, collapsed?}` objects. IDs and labels are non-empty strings and
`fields` contains schema property names. The client ignores malformed sections,
duplicate assignments, and unavailable/read-only fields. Every remaining
editable field is rendered once in an `Other details` section, so incomplete
presentation metadata can never hide writable data. A collapsed section opens
when one of its fields has a validation error. Section metadata is inert
presentation: it cannot add fields, bypass field access, change submission
payloads, inject templates, or execute callbacks.

Task-oriented shells such as Anokii and the full Admin SPA receive exactly the
same sections because both mount the shared `SchemaForm`. Applications own the
operator-facing labels and assignments for their content bundles; they do not
fork the Vue editor.

### List-View Column Policy (`packages/admin/app/components/schema/SchemaList.vue`)

Destructive actions open the application-owned `ConfirmDialog` alert dialog.
The dialog provides explicit confirm/cancel controls, initial safe focus, Escape
and backdrop cancellation, and dangerous-action styling. Native
`window.confirm()` is not part of the admin interaction contract.

#### Host-declared `x-list`

A schema may add an optional validated `x-list` object. Its closed shape is:

- `columns[]`: `field`, non-empty plain-text `label`, `sortable`, formatter ID
  `text|date|datetime|boolean/status|enum`, and optional string `valueLabels`;
- `search`: one `field`, existing `SurfaceFilterOperator`, label, and optional
  accessible description;
- `filters[]`: `field`, one existing operator, label, optional finite inert
  `{value,label}` options, and optional scalar default;
- `sorts[]`: explicit field/direction (`ASC|DESC`) pairs and human labels, plus
  an optional `defaultSort` that must match a declared pair.

Unknown keys, formatter IDs, operators, directions, callbacks, templates, URLs,
format strings, or malformed structures invalidate the whole declaration. The
client renders all labels with Vue text interpolation and never uses metadata as
HTML. A present but invalid `x-list` does not fall back to unrestricted declared
controls. When `x-list` is absent, the existing `x-list-display`/first-six column
policy and bundle filter remain unchanged.

The declaring host passes the parsed `SurfaceQuery` and validated `ListMetadata`
to `SurfaceQueryPolicy::validate()` before adding internal scope filters or
delegating. Any undeclared filter field/operator or sort field/direction returns
the same generic 400 response. Client visibility is never the enforcement layer.

Declared control changes reset the page offset, issue one request, and synchronize
the supported filter/sort/page query keys with the current URL. A monotonically
increasing request ID prevents a late response from overwriting newer results.
Loading/result changes are announced and controls remain keyboard-operable with
44 px targets.

Each generic list row contains `capabilities: {view, edit, delete}`. `view` reuses
the authoritative decision that admitted the row; `edit` and `delete` are derived
from update/delete access after hydration. Only booleans are serialized—never
policy reasons. Under `x-list`, row actions render solely from this object. Legacy
hosts without `x-list` retain catalog-level action presentation. Every direct
mutation still repeats server authorization.

The list table (`SchemaList`) is schema-driven: by default it renders the first six
non-hidden fields as columns. Without a bound, a content type with a long-text /
rich-text body (the `text` / `text_long` field types) dumps the whole body into a
cell, blowing out row height and making the list nearly unusable (UX-1). The
column policy bounds the table framework-wide for every content type:

- **Rich-text / text-format columns are dropped from the default set.** Fields
  with `x-widget: 'richtext'` (from the `text_long` field type) are excluded from
  the default `columns`. They are **not** removed from the data — `SchemaView`
  (detail) and `SchemaForm` (edit) select their own fields via
  `sortedProperties()` and are unaffected, so the body stays fully viewable and
  editable.
- **An explicit `x-list-display: true` opt-in still wins.** When any field
  declares it, exactly those fields are the columns (the author chose them) —
  including a rich-text field if they opt it back in. Cells are still truncated.
- **Every text cell is truncated to a snippet.** `formatCellValue` routes string
  values through `truncateSnippet`, which collapses internal whitespace/newlines
  to one line and caps the length at `SNIPPET_MAX_CHARS` (120) with an ellipsis.
  This bounds a long plain-text field (`x-widget: 'textarea'`) that remains a
  column, and any opted-in rich-text column. Boolean (`✓`/`—`) and `date-time`
  cells are already short and return before truncation.
- **CSS defense-in-depth.** `.entity-table td:not(.actions)` carries a `max-width`
  with `overflow: hidden; text-overflow: ellipsis` so column width stays bounded
  even if a value ever slips past `truncateSnippet`; the actions column is exempt
  so its buttons stay on one line.

Acceptance: `tests/components/schema/SchemaListColumnPolicy.test.ts` proves a
long-text/rich-text content type renders bounded columns — the rich-text column
is absent and the long-text cell is a truncated snippet, never the full body.

### Responsive list and pagination contract

`SchemaList` renders one semantic table and one action group per row at every
breakpoint. At widths through 600px, CSS adapts those same rows into cards: the
table header remains available to assistive technology and each cell repeats its
authoritative header through `data-label`. No duplicate mobile action copy is
rendered. Above that breakpoint, or whenever arbitrary columns still need more
room, the table is contained by a named, keyboard-focusable horizontal-scroll
region; it must never expand the document.

The list region owns populated, empty, loading, and failure states. Long and
unbroken values wrap or remain clipped inside their cell/card; row identity and
the single labelled action group stay explicit. Pagination renders labelled
previous/next controls, a bounded boundary/current/neighbour page window,
non-interactive ellipses, and `aria-current="page"`. Navigation restores focus
to the newly current page control. All ordinary list and pagination controls
meet the shared 44 by 44 CSS-pixel target contract.

### Element anchors (Wayfinding Phase-1 groundwork)

The schema-driven components emit stable, **inert** `data-anchor` attributes derived
from schema field identity, seeding the future Wayfinding anchor catalog (mission
`wayfinding-01KVGH5X`, FR-006/FR-007). They have no behaviour in the admin SPA today —
they are a published targeting contract for element-anchored *beacons*. The scheme:

| Component | Element | `data-anchor` |
|-----------|---------|---------------|
| `[entityType]/index` (list page) | Create-new button | `action:{entityType}:create` |
| `SchemaList` | container | `list:{entityType}` |
| `SchemaList` | column header | `list-field:{entityType}:{fieldName}` |
| `SchemaList` | Edit / Delete action | `action:{entityType}:edit` / `action:{entityType}:delete` |
| `SchemaView` | container | `view:{entityType}` |
| `SchemaView` | field row | `field:{entityType}:{fieldName}` |
| `SchemaForm` | container | `form:{entityType}` |
| `SchemaForm` | field wrapper | `field:{entityType}:{fieldName}` |
| `SchemaForm` | submit action | `action:{entityType}:submit` |

These are now validated against the published Wayfinding anchor catalog
(`/.well-known/waaseyaa-anchors.json`, `AnchorRegistry`); an emit referencing an
anchor not in the catalog is rejected (FR-005). The list-level
`action:{entityType}:create` row was added so a presenter can beacon the
"Create new" control directly (`wayfinding-showcase-hardening`, P1-3) — it
mirrors the per-row `action:*:edit`/`:delete` scheme. The shipped bundle is
asserted to contain `data-anchor` by `AdminDistContentTest` (see "Pre-built SPA
distribution").

### Widget Resolution (`packages/admin/app/components/schema/SchemaField.vue`)

`x-widget` value maps to a component via `widgetMap`:

| x-widget             | Component                  | HTML element         |
|----------------------|----------------------------|----------------------|
| `text` (default)     | `WidgetsTextInput`         | `<input type="text">` |
| `email`              | `WidgetsTextInput`         | `<input type="email">` |
| `url`                | `WidgetsTextInput`         | `<input type="url">` |
| `password`           | `WidgetsTextInput`         | `<input type="text">` |
| `textarea`           | `WidgetsTextArea`          | `<textarea>`         |
| `richtext`           | `WidgetsRichText`          | `<div contenteditable>` |
| `number`             | `WidgetsNumberInput`       | `<input type="number">` |
| `boolean`            | `WidgetsToggle`            | `<input type="checkbox">` |
| `select`             | `WidgetsSelect`            | `<select>`           |
| `datetime`           | `WidgetsDateTimeInput`     | `<input type="datetime-local">` |
| `slug`               | `WidgetsSlugInput`         | `<input type="text">` |
| `date`               | `WidgetsDateInput`         | `<input type="date">` |
| `entity_autocomplete`| `WidgetsEntityAutocomplete`| `<input type="text">` + dropdown |
| `hidden`             | `WidgetsHiddenField`       | (renders nothing)    |
| `image`, `file`      | `WidgetsTextInput`         | `<input type="text">` |

### Access-Restricted Fields

When the PHP `SchemaPresenter` marks a field with `readOnly: true` + `x-access-restricted: true`:
- `sortedProperties(true)` keeps the field (unlike system readOnly which is excluded)
- `SchemaForm` passes `:disabled="!!fieldSchema['x-access-restricted']"`
- The `@update:model-value` handler guards: `if (!fieldSchema['x-access-restricted']) formData[fieldName] = val`
- Result: field is visible but not editable in the UI

### Rich-text preservation and editing

`WidgetsRichText` keeps a canonical source string separate from its visual
editing projection. Mounting, rerendering, or switching to source mode emits
nothing, so untouched migrated HTML remains byte-for-byte identical, including
images, supported embeds, and unfamiliar valid attributes. Empty and null inputs
render empty without placeholder markup. No editor dependency is added.

The visual projection is parsed in an inert `<template>` and replaces scripts,
remote-fetching media/embed elements, and form controls before inserting markup
into the contenteditable; it is an editor, never an executable preview. A user
who edits visually accepts deterministic projection HTML as the new value.
Explicit HTML source mode preserves and emits the exact edited string and is
reachable by button or Ctrl+Shift+S. Both modes share field label/help/error/
required associations and visible focus treatment. Server-side rich-text
sanitization at the read/render boundary remains authoritative for content
delivered to clients.

### Field validation and date-only values

`SchemaForm` renders with `novalidate` so browser bubbles are not the sole
feedback. It maps client and structured transport failures to associated field
messages plus an assertive, programmatically focused summary. Unknown/malformed,
forbidden, network, and server failures use safe global messages. Corrections,
bundle changes, and reloads clear stale errors; a synchronous latch prevents
duplicate submission. Requiredness comes only from `x-required` or the schema
`required` roster.

Date widgets transport a real date as ISO `YYYY-MM-DD`, preserve an untouched
null in form data, emit null when cleared, and perform no timezone conversion.
Calendar syntax plus `x-min`/`x-max` bounds are validated into the same associated
error contract. Ordinary strings and `date-time` widgets are not reclassified.

### EntityAutocomplete Widget

`WidgetsEntityAutocomplete` (`packages/admin/app/components/widgets/EntityAutocomplete.vue`):
- Uses `x-target-type` from schema to determine which entity type to search
- Resolves display, search, and sort fields from the target type's catalog `reference` metadata; it never assumes `title` or requests an unfiltered fallback list
- Keyboard navigation: ArrowUp/ArrowDown/Enter/Escape
- ARIA: `role="combobox"`, `aria-expanded`, `aria-autocomplete="list"`, dropdown has `role="listbox"`, items have `role="option"`
- The combobox and each option use the shared 44 px target floor. A present clear
  button is an adjacent flex item with its own 44 px target, so its rectangle
  never overlaps the combobox, obscures entered text, or steals result selection.

## SSE Integration

### Frontend Flow

1. `SchemaList` instantiates `useRealtime(['admin'])` on mount
2. `useRealtime` opens `EventSource` to `GET /api/broadcast?channels=admin`
3. Incoming messages are parsed as JSON and appended to `messages` ref
4. `SchemaList` watches `messages` and auto-refreshes entity list when:
   - `latest.event === 'entity.saved'` or `'entity.deleted'`
   - `latest.data?.entityType === props.entityType`

### Connection Status

- Green pulsing dot indicator in pagination bar when connected
- Error message with reconnect button when connection lost after max retries
- CSS animation: `@keyframes pulse` on `.sse-status`

## i18n

Translation files: `packages/admin/app/i18n/en.json` (English), `packages/admin/app/i18n/fr.json` (French)

Key categories:
- UI chrome: `app_name`, `dashboard`, `content`, `sidebar_nav`, `toggle_menu`, `language`
- CRUD actions: `save`, `create`, `create_new`, `edit`, `delete`, `back_to_list`, `actions`, `cancel`
- States: `loading`, `saving`
- Feedback: `entity_created`, `entity_saved`, `confirm_delete`
- Pagination: `showing`, `of`, `previous`, `next`, `no_items`
- Errors: `error_generic`, `error_not_found`, `error_page_title`, `error_page_back`, `error_loading_schema`, `error_loading_types`, `error_loading_entities`, `error_deleting`, `error_nav`
- Autocomplete: `autocomplete_placeholder`, `autocomplete_no_results`, `autocomplete_loading`
- Realtime: `realtime_connected`
- Onboarding: `onboarding_title`, `onboarding_body`, `onboarding_use_note`, `onboarding_create_type`, `onboarding_quickstart`
- Type management: `disable_type`, `enable_type`, `type_disabled`, `disable_type_title`, `disable_type_body`, `disable_type_warning`, `disable_anyway`
- Navigation groups: `nav_group_people`, `nav_group_content`, `nav_group_taxonomy`, `nav_group_media`, `nav_group_structure`, `nav_group_workflows`, `nav_group_ai`, `nav_group_events`, `nav_group_community`, `nav_group_communities`, `nav_group_knowledge`, `nav_group_language`, `nav_group_ingestion`, `nav_group_contributor`, `nav_group_editorial`, `nav_group_elders`, `nav_group_engagement`, `nav_group_games`, `nav_group_groups`, `nav_group_messaging`, `nav_group_newsletter`, `nav_group_oidc`, `nav_group_user`, `nav_group_other`, `nav_group_custom`. Consumer apps register entity-type nav-group attributes whose values resolve to `nav_group_{value}` keys; missing translations leak the raw key in the sidebar, so any new group value introduced by a consumer must add a matching translation here.
- Ingestion widget: `ingest_widget_title`, `ingest_widget_empty`, `ingest_status_pending_review`, `ingest_status_approved`, `ingest_status_rejected`, `ingest_status_failed`
- Entity type labels: `entity_type_user`, `entity_type_node`, `entity_type_node_type`, `entity_type_taxonomy_term`, etc.
- Field labels: `field_title`, `field_machine_name`, `field_published`, `field_description`, `field_weight`, `field_email`, etc.
- Parameterized: `create_entity`, `edit_entity` (with `{type}` token)

Token replacement pattern: `t('key', { token: 'value' })` replaces `{token}` in the string.

The `useLanguage` composable also exposes `entityLabel(id, fallback)` for resolving `entity_type_{id}` keys with a fallback to the raw label.

## Component Patterns

### Directory Structure

```
packages/admin/app/
  app.vue                          # Root: <NuxtLayout> + <NuxtPage />
  layouts/
    default.vue                    # Wraps content in <LayoutAdminShell>
  components/
    layout/
      AdminShell.vue               # Top bar + sidebar + content area
      NavBuilder.vue               # Dynamic sidebar nav from /api/entity-types
    schema/
      SchemaForm.vue               # Entity create/edit form driven by JSON Schema
      SchemaField.vue              # Single field: resolves x-widget to widget component
      SchemaList.vue               # Entity list table with sort, pagination, SSE auto-refresh
    widgets/
      TextInput.vue                # text/email/url/password/image/file
      TextArea.vue                 # textarea
      RichText.vue                 # contenteditable with HTML sanitization
      NumberInput.vue              # number with min/max
      Toggle.vue                   # checkbox for booleans
      Select.vue                   # dropdown from enum + x-enum-labels
      DateTimeInput.vue            # datetime-local
      EntityAutocomplete.vue       # Typeahead search for entity references
      HiddenField.vue              # Renders nothing (excluded from editable forms)
      MachineNameInput.vue         # Machine-readable name generator from label
      FileUpload.vue               # File upload input
    auth/
      LoginForm.vue                # Username/password form with error/loading props
      RegisterForm.vue             # Name/email/password/confirm form
      ForgotPasswordForm.vue       # Email-only form with success state
      ResetPasswordForm.vue        # New password + confirm form
      BrandPanel.vue               # App branding sidebar with optional logo/tagline
      VerificationBanner.vue       # Email verification banner with resend + dismiss
    IngestSummaryWidget.vue        # Ingestion status counters + NC sync panel
    onboarding/
      OnboardingPrompt.vue         # Onboarding guide prompt
  adapters/
    AdminSurfaceTransportAdapter.ts  # AdminSurface API transport layer
    BootstrapAuthAdapter.ts          # Bootstrap authentication during app init
    JsonApiTransportAdapter.ts       # JSON:API protocol transport
    index.ts                         # Re-exports all adapters
  composables/
    useAdmin.ts                    # Admin panel context & utilities
    useAuth.ts                     # Authentication state & login/logout
    useEntity.ts                   # JSON:API CRUD + search
    useSchema.ts                   # Schema fetch/cache/sort
    useLanguage.ts                 # i18n
    useNavGroups.ts                # Navigation group rendering & humanize() helper
    useRealtime.ts                 # SSE connection
  pages/
    index.vue                      # Dashboard: catalog-aware onboarding + entity type cards + IngestSummaryWidget
    [entityType]/
      index.vue                    # Entity list (delegates to SchemaList)
      create.vue                   # Entity create form (delegates to SchemaForm)
      [id].vue                     # Entity edit form (delegates to SchemaForm with entityId)
  i18n/
    en.json                        # English translations
    fr.json                        # French translations
```

### Naming Conventions

- Components use PascalCase in Nuxt auto-import paths: `LayoutAdminShell`, `SchemaForm`, `WidgetsTextInput`
- Composables follow Vue convention: `use{Name}` returning an object of refs and functions
- Pages use Nuxt file-based routing with `[param]` dynamic segments

### Widget Interface Contract

Every widget component must accept these props:
```ts
{
  modelValue: any       // Current field value
  label?: string        // Human label from x-label or field name
  description?: string  // Help text from x-description or description
  required?: boolean    // From x-required
  disabled?: boolean    // True when x-access-restricted
  schema?: SchemaProperty  // Full schema property for widget-specific behavior
}
```
And emit: `'update:modelValue'` with the new value.

## Dashboard (`pages/index.vue`)

The dashboard page uses the `useAdmin()` catalog (from the AdminSurface bootstrap endpoint) to render entity type cards. It includes:

1. **Onboarding detection**: On mount, probes for existing content by listing the first listable catalog type (prefers `node_type`). If no content exists, shows `OnboardingPrompt` with links to create a Note, create a custom type, or open the quickstart guide. Paths are computed from catalog capabilities.
2. **IngestSummaryWidget**: Renders ingestion status counters (pending_review, approved, rejected, failed) from the `ingest_log` entity type. The server-owned catalog is the activation boundary: when `ingest_log` is absent, the widget hides without making a list request. Each counter links to the filtered ingest_log list. The generic dashboard does not call application-specific staff endpoints or hardcode consumer routes.
3. **Entity type card grid**: Renders a card for each catalog entry using `entityLabel(et.id, et.label)` for i18n-aware labels.

Error handling uses `TransportError` from `~/contracts/transport` to distinguish 404s from other failures.

## Navigation

`packages/admin/app/components/layout/NavBuilder.vue` and `packages/admin/app/components/pipeline/EntityViewNav.vue` derive action-aware navigation state from `useAdmin().catalog`.

- Sidebar grouping is resolved by `groupEntityTypes(catalog)`.
- The pipeline link for an entity type is visible only when that catalog entry declares an action with `id === 'board-config'`.
- Pipeline visibility is deterministic and must remain a pure function of `runtime.catalog`.
- Navigation components must not call `runAction(type, 'board-config')` or rely on request failures to infer whether pipeline navigation should be shown.
- User-facing navigation labels in `AdminShell` and `NavBuilder` route through `useLanguage()`, including the skip link and pipeline suffix.
- `ui.navigationMode === 'catalog-only'` suppresses the static MCP, Operations,
  and Governance sections. Missing/invalid/`full` keeps them, preserving legacy
  hosts. This condition is not consulted by routing or authorization.
- Below 768px the closed sidebar is `inert`, `aria-hidden`, translated off-canvas,
  pointer-disabled, and absent from sequential focus. The toggle exposes
  `aria-controls`/`aria-expanded`. Opening locks page scroll and focuses the
  in-panel close control; Tab remains in the panel, Escape/backdrop/close return
  focus to the opener, and route or desktop-breakpoint changes clear open and
  scroll-lock state. Desktop navigation remains persistently available.

## SchemaForm / MachineNameInput Contract

`packages/admin/app/components/schema/SchemaForm.vue` is the sole provider of machine-name widget coordination context.

- `SchemaForm` provides a typed `SchemaFormContext` using the `schemaFormContextKey` injection key from `packages/admin/app/components/schema/schemaFormContext.ts`.
- The context contains:
  - `formData`
  - `isEditMode`
- `packages/admin/app/components/widgets/MachineNameInput.vue` requires this provider context and throws immediately when mounted outside `SchemaForm`.
- `MachineNameInput` also requires `schema['x-source-field']` and throws immediately when that schema extension is missing.
- Edit-mode locking is deterministic:
  - locked when `isEditMode` is true
  - locked when the widget `disabled` prop is true
- Auto-generation is deterministic and derived from the declared `x-source-field` value in provided `formData`.
- The widget must not degrade silently in production or rely on dev-only warnings for missing context.
- Focused tests assert this contract in:
  - `packages/admin/tests/components/widgets/MachineNameInput.test.ts`
  - `packages/admin/tests/components/schema/SchemaForm.test.ts`
  - `packages/admin/tests/components/schema/SchemaField.test.ts`

## Routing

File-based routing via Nuxt 3:

| Route                    | Page File                                | Purpose              |
|--------------------------|------------------------------------------|----------------------|
| `/`                      | `pages/index.vue`                        | Dashboard            |
| `/:entityType`           | `pages/[entityType]/index.vue`           | Entity list          |
| `/:entityType/create`    | `pages/[entityType]/create.vue`          | Create form          |
| `/:entityType/:id`       | `pages/[entityType]/[id]/index.vue`      | Edit form            |
| `/:entityType/:id/history` | `pages/[entityType]/[id]/history.vue`  | Per-record history   |

## Auth Phase 2 — Registration, Password Reset, Email Verification

### New Pages

| Route | Page File | Access | Purpose |
|-------|-----------|--------|---------|
| `/register` | `pages/register.vue` | Public | Open/invite registration form |
| `/forgot-password` | `pages/forgot-password.vue` | Public | Request password reset email |
| `/reset-password` | `pages/reset-password.vue` | Public | Consume reset token, set new password |
| `/verify-email` | `pages/verify-email.vue` | Public | Verify email; auto-submits `?token=` if present |

All new pages use the Split Panel layout with CSS variable theming (`--color-primary` deep teal palette) matching the Phase 1 login page. None use `AdminShell`.

### Post-Login Reload

**File:** `packages/admin/app/pages/login.vue`

After successful login, the page calls `reloadNuxtApp({ path: returnTo })` — NOT `navigateTo()`. This is required because the admin plugin (`admin.ts`) runs once at app initialization and caches the `/_surface/session` result. An SPA navigation would leave `$admin` as `null` (the plugin already ran and got a 401 before login). A full reload forces the plugin to re-run with the new session cookie.

The `returnTo` value comes from the `returnTo` query parameter, falling back to `config.app.baseURL` (e.g. `/admin/`). Both the fallback and the open-redirect guard use `app.baseURL` rather than a hardcoded `/`, so the redirect respects the configured subpath.

### publicAuthPaths — Plugin Auth Skip

**File:** `packages/admin/app/plugins/admin.ts`

The admin plugin fetches the admin surface session endpoint (same path as PHP route `admin_surface.session`, default `/admin/_surface/session` under the normalized app base) on every page load to resolve the current user. Pages that must be reachable before authentication are listed in a `publicAuthPaths` array:

```ts
const publicAuthPaths = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email']
```

The plugin and global auth middleware both use the shared runtime normalizer in `packages/admin/app/runtime/publicAuthPaths.ts` to evaluate public auth paths.

Normalization rules:
- trailing slashes are removed before matching;
- admin subpath prefixes (for example `/admin/login`) are reduced to canonical route paths (`/login`);
- the governed public auth set remains `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`.

This keeps client bootstrap, server bootstrap, and route middleware aligned on the same public-route contract and prevents the 401 → redirect → 401 loop that would otherwise occur on public auth pages.

### ensureVerifiedEmail Middleware

**File:** `packages/admin/app/middleware/auth.global.ts`

When `runtimeConfig.public.requireVerifiedEmail` is true, the global auth middleware enforces email verification gating:

- If `currentUser.emailVerified` is false and the current path is not in the skip list, `navigateTo('/verify-email')`.
- Skipped paths: `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`.

When `requireVerifiedEmail` is false (default), unverified users reach the AdminShell but see `VerificationBanner`.

### VerificationBanner Component

**File:** `packages/admin/app/components/auth/VerificationBanner.vue`

Rendered inside `AdminShell` when `auth.requireVerifiedEmail` is false and the current user's `emailVerified` is false. Features:

- Persistent but dismissible. Dismissal stored in `localStorage` keyed by user ID to prevent cross-account leakage on shared machines.
- Inline "Resend verification" button; reflects `Retry-After` header for cooldown display.
- Disappears reactively when `useAuth().currentUser.emailVerified` becomes true.
- User-facing banner text, resend state text, and dismiss `aria-label` route through `useLanguage()`.

### Runtime Config Additions

New keys exposed via `useRuntimeConfig().public`:

| Key | Env Var | Default | Purpose |
|-----|---------|---------|---------|
| `registrationMode` | `NUXT_PUBLIC_REGISTRATION_MODE` | `'admin'` | Controls whether `/register` link appears on login page |
| `requireVerifiedEmail` | `NUXT_PUBLIC_REQUIRE_VERIFIED_EMAIL` | `'0'` | Drives `ensureVerifiedEmail` middleware |

### useAuth Extensions

`packages/admin/app/composables/useAuth.ts` extended with:

```ts
register(data: { name: string; email: string; password: string; invite_token?: string }): Promise<void>
forgotPassword(email: string): Promise<void>
resetPassword(data: { token: string; password: string; password_confirmation: string }): Promise<void>
verifyEmail(token: string): Promise<void>
resendVerification(): Promise<void>
```

All methods use `$fetch` with `credentials: 'include'` targeting `/api/auth/*` (proxied to PHP backend).

`useAuth()` shares state through the same stable keys hydrated by the admin plugin:
- `waaseyaa.auth.user`
- `waaseyaa.auth.checked`

That means `useAuth()` does not establish an independent session source of truth. It consumes and updates the shared bootstrap state established by `packages/admin/app/plugins/admin.ts`.

### Routing — Updated Table

| Route | Page File | Purpose |
|-------|-----------|---------|
| `/` | `pages/index.vue` | Dashboard |
| `/:entityType` | `pages/[entityType]/index.vue` | Entity list |
| `/:entityType/create` | `pages/[entityType]/create.vue` | Create form |
| `/:entityType/:id` | `pages/[entityType]/[id].vue` | Edit form |
| `/register` | `pages/register.vue` | Registration (open/invite mode) |
| `/forgot-password` | `pages/forgot-password.vue` | Request password reset |
| `/reset-password` | `pages/reset-password.vue` | Consume reset token |
| `/verify-email` | `pages/verify-email.vue` | Email verification |

## Accessibility

- Skip-to-content link: `<a href="#main-content" class="skip-link">`
- ARIA landmarks: `role="banner"` (topbar), `role="navigation"` (sidebar), `role="main"` (content)
- Sidebar label: `aria-label` bound to `t('sidebar_nav')`
- Autocomplete: full `combobox` pattern with `listbox`/`option` roles
- Delete buttons include entity label in `aria-label`
- Live region: `<div role="status" aria-live="polite">` announces pagination changes
- Workflow discovery announces loading and a bound no-transition state through
  polite status regions. Fetch/apply failures use assertive alert regions;
  native transition buttons retain keyboard activation and explicit accessible
  names, and all controls are disabled behind a same-tick single-flight guard
  during submission.
- Screen-reader-only class: `.sr-only` for visually hidden announcements
- Every schema control has a stable unique ID, associated label, help/error
  `aria-describedby` chain, semantic required state, and `aria-invalid` only
  while invalid. Error text includes a non-color cue.
- Form failures use an assertive focused validation summary; focus indicators use
  a solid high-contrast outline, and form help/error tokens meet normal-text AA
  contrast on their framework backgrounds.
- Responsive: sidebar collapses to off-canvas drawer below 768px with overlay
- Ordinary authenticated-admin links, buttons, inputs, selects, date controls,
  autocomplete controls/options, rich-text controls, toggle labels,
  disclosures, row/workflow actions, pagination, menu/close, and navigation
  controls consume `--admin-target-size` and use at least a 44 by 44 CSS-pixel
  effective target with a visible focus outline. Native checkbox/radio glyphs
  may remain visually smaller inside their associated 44 px label target; label
  whitespace activates the native control, which retains focus and state.
  Disabled and read-only controls retain the same geometry and native semantics.
  Generic content and action containers may shrink; long identifiers, URLs, alerts,
  breadcrumbs, tables, and preformatted output are contained rather than
  widening the document. At 200% text enlargement, targets may grow but must not
  overlap or create document-level horizontal overflow.

## Build & Testing

### Build

```bash
cd packages/admin && npm run build
```

The build step verifies TypeScript compilation and Nuxt module resolution. Build scripts from `packages/admin/package.json`:
- `dev`: `nuxt dev` (development server with HMR)
- `build`: `nuxt build` (production build)
- `generate`: `nuxt generate` (static site generation)
- `preview`: `nuxt preview` (preview production build)
- `postinstall`: `nuxt prepare` (generate `.nuxt` types)

### E2E Testing (Playwright)

Playwright config: `packages/admin/playwright.config.ts`. Tests live in `packages/admin/e2e/`.

- Base URL: `http://localhost:3000/admin` (matches the `/admin/` base URL)
- Browsers: Chromium, Firefox
- Web server: auto-starts `npm run dev` with 120s timeout; reuses existing server outside CI
- CI: `forbidOnly` enforced, 2 retries, trace on first retry; dashboard tests use `networkidle` wait and `main`-scoped role-based selectors to avoid sidebar duplicates
- Reports: HTML reporter; `playwright-report/` and `test-results/` are gitignored

### Vitest (Component & Composable Tests)

Config: `packages/admin/vitest.config.ts`. Environment: `nuxt` (via `@nuxt/test-utils`). Coverage: v8 provider.

```bash
cd packages/admin && npm test          # single run
cd packages/admin && npm run test:watch # watch mode
cd packages/admin && npm run test:coverage # with coverage
```

Test files live in `packages/admin/tests/`:
- `tests/components/auth/LoginForm.spec.ts` — login form rendering, emit, error/loading states
- `tests/components/auth/BrandPanel.spec.ts` — brand panel rendering, logo, tagline
- `tests/components/auth/RegisterForm.spec.ts` — registration form fields, emit, error/loading states
- `tests/components/auth/ForgotPasswordForm.spec.ts` — email field, emit, success/error states
- `tests/components/auth/ResetPasswordForm.spec.ts` — password fields, emit, error/loading states
- `tests/components/auth/VerificationBanner.spec.ts` — visibility, dismiss, localStorage persistence, resend
- `tests/composables/useAuth.spec.ts` — auth composable state and methods
- `tests/unit/composables/useAuth.test.ts` — auth composable unit tests
- `tests/unit/plugins/admin.test.ts` — runtime bootstrap shape, shared auth-state hydration invariant, and degraded bootstrap branches
- `tests/unit/runtime/adminSurfaceRoutes.test.ts` — admin surface named-route path helpers vs normalized app base
- `tests/unit/composables/useAdmin.test.ts` — runtime-backed admin catalog access and missing-runtime invariant
- `tests/unit/composables/useEntity.test.ts` — transport delegation and missing-runtime invariant
- `tests/unit/composables/useSchema.test.ts` — schema caching/error handling and missing-runtime invariant
- `tests/components/layout/NavBuilder.test.ts` — deterministic navigation rendering for empty and action-aware catalogs using capability-minimal fixtures
- `tests/pages/dashboard.test.ts` — onboarding prompt capability fallbacks (`node_type` create path, first create-capable fallback, root fallback when note is absent, first-listable probe when `node_type` is absent)
- `tests/unit/composables/useMcpApprovals.test.ts` — bounded 25-row pages, verbatim opaque-cursor traversal (next/previous/refresh), 403-view vs generic load errors, decision body shape (blank reason omitted), 400/403/404/409/503 → typed refusal kinds
- `tests/components/mcp/ApprovalDecisionDialog.test.ts` — alertdialog semantics, hostile server strings rendered as text, 500-Unicode-char reason boundary (astral-safe), single-line validation, remaining counter, double-submit guard, focus-in on open, Tab/Shift+Tab trap, Escape/overlay/cancel dismissal refused while submitting
- `tests/pages/mcpApprovals.test.ts` — capability-aware rendering (`can('mcp.approval.view')` / `can('mcp.approval.decide')`), loading/empty/error/forbidden states (load errors `role="alert"`), labelled keyboard-focusable table region, pagination + refresh wiring, decision flow with honest 404/409 stale refetch, focus restoration to the triggering Approve/Deny control (Refresh fallback when the row is gone)

Pattern: `mountSuspended()` from `@nuxt/test-utils/runtime` for component mounting. Props via `props: {}`, emits via `wrapper.emitted()`.

### Backend Testing

Backend JSON:API and schema endpoints are tested via PHPUnit integration tests in `tests/Integration/PhaseN/`. The admin SPA relies on these endpoints being correct.

## OIDC Client Registration (mission oidc-flows-completion-01KSEFTP)

Admin surface for registering external OIDC clients (apps that authenticate via the framework's OIDC IdP). Backs the WP05 leg of the OIDC flows completion mission.

- **Pages:** `packages/admin/app/pages/oidc/clients/index.vue` (list + create), `[id].vue` (detail + edit + revoke).
- **Composable:** `packages/admin/app/composables/useOidcClients.ts` — CRUD wrapper over `/api/oidc/clients` JSON:API endpoints + consent-revocation actions.
- **Backing API:** `packages/api/src/Controller/OidcClientController.php` + `packages/api/src/Http/Router/OidcClientApiRouter.php` (admin-only routes, AccessChecker-gated).
- **Permission:** `oidc.client.administer` (granted to admin role by default; configurable per Nation in distribution charters).
- **Distinct from end-user surfaces:** the consent screen lives at `packages/oidc/src/Consent/ConsentScreenController.php` (server-rendered, NOT admin SPA).

## Mercure Broadcast Monitor (M5D)

Real-time SSE monitor for the Mercure broadcasting layer (gap-matrix C-L0-04, mission `mercure-broadcast-monitor-m5d-01KSEFTD`). Single-page admin tool surfacing channels, live events, and subscribers.

- **Page:** `packages/admin/app/pages/mercure/monitor.vue` — 3-section dashboard (channels with chip filter, events table, subscribers table with anonymous-label fallback).
- **Composable:** `packages/admin/app/composables/useMercureMonitor.ts` — wraps `useApi` and `useRealtime`; provides channel multi-select state and a `refresh()` action.
- **Nav:** `NavBuilder.vue` exposes the monitor link under the admin nav root.
- **i18n:** 20 keys under `mercure_monitor.*` in `packages/admin/app/i18n/en.json`.
- **Endpoint contract (camelCase, as shipped):**
  - `GET /api/mercure/channels` → `{ data: ChannelInspectorRow[] }`
  - `GET /api/mercure/events` (SSE stream; mirrors `BroadcastRouter` shape — keepalive 15s, `X-Accel-Buffering: no`)
  - `GET /api/mercure/subscriptions` → `{ data: SubscriberRow[] }`
- **Identity safety (NFR-004):** subscriber rows redact Authorization, Cookie, User-Agent, and any 64-char hex tokens.
- **Tests:** Vitest unit coverage for `useMercureMonitor`; Playwright e2e under `packages/admin/e2e/mercure-monitor.spec.ts`.

## File Reference

| File | Purpose |
|------|---------|
| `packages/admin/package.json` | NPM package definition |
| `packages/admin/nuxt.config.ts` | Nuxt configuration, API proxy |
| `packages/admin/app/app.vue` | Root component |
| `packages/admin/app/layouts/default.vue` | Default layout (AdminShell wrapper) |
| `packages/admin/app/composables/useAdmin.ts` | Admin panel context & utilities |
| `packages/admin/app/composables/useAuth.ts` | Authentication state & login/logout |
| `packages/admin/app/composables/useEntity.ts` | JSON:API CRUD composable |
| `packages/admin/app/composables/useSchema.ts` | Schema fetching and caching |
| `packages/admin/app/composables/useLanguage.ts` | i18n composable |
| `packages/admin/app/composables/useNavGroups.ts` | Navigation group rendering |
| `packages/admin/app/composables/useRealtime.ts` | SSE connection composable |
| `packages/admin/app/adapters/AdminSurfaceTransportAdapter.ts` | AdminSurface API transport |
| `packages/admin/app/adapters/JsonApiTransportAdapter.ts` | JSON:API protocol transport |
| `packages/admin/app/adapters/BootstrapAuthAdapter.ts` | Bootstrap auth during init |
| `packages/admin/app/assets/admin.css` | Global admin tokens, controls, and shell-independent editor styles |
| `packages/admin/app/components/layout/AdminShell.vue` | Shell layout and navigation frame |
| `packages/admin/app/components/layout/NavBuilder.vue` | Dynamic sidebar navigation |
| `packages/admin/app/components/schema/SchemaForm.vue` | Schema-driven entity form |
| `packages/admin/app/components/schema/SchemaField.vue` | Widget resolver for a single field |
| `packages/admin/app/components/schema/SchemaList.vue` | Entity list with sort/pagination/SSE |
| `packages/admin/app/components/widgets/TextInput.vue` | Text/email/url input widget |
| `packages/admin/app/components/widgets/TextArea.vue` | Textarea widget |
| `packages/admin/app/components/widgets/RichText.vue` | Contenteditable rich text widget |
| `packages/admin/app/components/widgets/NumberInput.vue` | Number input widget |
| `packages/admin/app/components/widgets/Toggle.vue` | Checkbox toggle widget |
| `packages/admin/app/components/widgets/Select.vue` | Dropdown select widget |
| `packages/admin/app/components/widgets/DateTimeInput.vue` | Datetime-local input widget |
| `packages/admin/app/components/widgets/EntityAutocomplete.vue` | Typeahead entity reference widget |
| `packages/admin/app/components/widgets/HiddenField.vue` | Hidden field (renders nothing) |
| `packages/admin/app/components/auth/LoginForm.vue` | Login form component |
| `packages/admin/app/components/auth/RegisterForm.vue` | Registration form component |
| `packages/admin/app/components/auth/ForgotPasswordForm.vue` | Forgot password form component |
| `packages/admin/app/components/auth/ResetPasswordForm.vue` | Reset password form component |
| `packages/admin/app/components/auth/BrandPanel.vue` | Auth page branding panel |
| `packages/admin/app/components/auth/VerificationBanner.vue` | Email verification banner (banner mode) |
| `packages/admin/app/pages/index.vue` | Dashboard page |
| `packages/admin/app/pages/[entityType]/index.vue` | Entity list page |
| `packages/admin/app/pages/[entityType]/create.vue` | Entity create page |
| `packages/admin/app/pages/[entityType]/[id].vue` | Entity edit page |
| `packages/admin/app/components/IngestSummaryWidget.vue` | Ingestion status counters + NC sync panel |
| `packages/admin/app/components/auth/VerificationBanner.vue` | Email verification banner (banner mode) |
| `packages/admin/app/pages/register.vue` | Registration page (open/invite mode) |
| `packages/admin/app/pages/forgot-password.vue` | Forgot password page |
| `packages/admin/app/pages/reset-password.vue` | Reset password page (consumes token) |
| `packages/admin/app/pages/verify-email.vue` | Email verification page |
| `packages/admin/app/middleware/auth.global.ts` | Global auth + ensureVerifiedEmail middleware |
| `packages/admin/app/plugins/admin.ts` | Admin plugin with publicAuthPaths auth skip |
| `packages/admin/app/runtime/adminSurfaceRoutes.ts` | Named `admin_surface.*` fetch URL builders (mirror PHP paths) |
| `packages/admin/app/runtime/pageBuilderClient.ts` | Typed page-builder transport shared by the Admin SPA and downstream shells |
| `packages/admin/app/composables/usePageBuilder.ts` | Revision-guarded page-builder state and command lifecycle |
| `packages/admin/app/components/page-builder/PageBuilderWorkspace.vue` | Governed visual editor with block library, exact-revision preview, inspector, and outline |
| `packages/admin/app/pages/page-builder/[surface]/[id].vue` | Generic registered-surface page-builder route |
| `packages/admin-surface/src/AdminSurfaceRoutePaths.php` | Canonical `/admin/_surface/*` patterns and `generate()` for PHP |
| `packages/admin/app/i18n/en.json` | English translation strings |
| `packages/admin/app/i18n/fr.json` | French translation strings |
| `packages/admin/playwright.config.ts` | Playwright E2E test configuration |
| `packages/admin/app/composables/useMediaVersions.ts` | Internal parked media-version reader; reactivate only after #1742's byte-persistence boundary test |
| `packages/admin/app/components/media/MediaVersionBrowser.vue` | Internal parked media-version table |
| `packages/admin/app/pages/media/[uuid]/versions.vue` | Internal parked media-version page |

## MCP admin

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB` (#1415, audit C-L6-01).

Admin surface for the MCP endpoint. Four pages under `/mcp/`, accessible via the "MCP" nav group in `NavBuilder.vue`:

| Page | Route | Description |
|------|-------|-------------|
| Tool registry | `/mcp/tools` | Paginated list of registered MCP tools with name, category, capability chips, summary |
| Tool detail | `/mcp/tools/{name}` | Per-tool header card + collapsible input-schema viewer + recent invocations table |
| Server config | `/mcp/server-config` | Transport/protocol banner, server capabilities, registered clients table |
| MCP approvals | `/mcp/approvals` | #2177 F1 C1c operator queue for the write-tier human-approval gate: bounded 25-row pages of pending requests (server order, oldest first) with safe projections only (`safeArguments`, fingerprint, correlation id — never raw arguments), opaque-`nextCursor` pagination (Previous = client-side cursor stack, cursors never decoded), manual refresh, and approve/deny through `<McpApprovalDecisionDialog>` (optional ≤500-Unicode-char single-line reason with live remaining count, double-submit guard). Decision refusals map 400/403/404/409/503 to static non-secret messages; 404/409 refresh the queue honestly instead of pretending success. |

**Capability gating (#2177 F1 C1c):** the approvals page and its NavBuilder link are gated by the server-authoritative session projection via `useAdmin().can('mcp.approval.view')`; decision actions additionally require `can('mcp.approval.decide')` (view-only operators see the queue with no decision buttons). Never inferred from roles; the PHP routes stay the enforcement boundary. There is deliberately no UI for `mcp.write_tier.approval.allow_self_approval` — that stays deployment config.

**Composables:** `useMcpTools`, `useMcpTool`, `useMcpServerConfig`, `useMcpApprovals` — all use `useApi().apiFetch` (so decisions inherit the CSRF `X-XSRF-TOKEN` header behaviour pinned in `tests/composables/useApi.test.ts`).

**Security:** `McpRegisteredClient` TypeScript type has no `token` field; only `tokenFingerprint` (16-char hex) is exposed. Enforced by compile-time type assertion in `useMcpServerConfig.test.ts`.

**URL encoding:** `useMcpTool.fetchTool(name)` runs `encodeURIComponent(name)` once before the request so tool names containing dots (e.g. `bimaaji.search_specs`) are safe in path segments.

**M5B interop:** `RecentInvocationsTable.vue` renders `traceUuid` cells as router-links to `/ai/observability/runs/{uuid}` when the M5B route exists; falls back to plain text UUID when it does not (no broken links).

## Governed page builder

The Admin SPA exposes registered page-builder surfaces at `/page-builder/{surface}/{id}`. The workspace combines Drupal-style governed structure with a direct visual editing interaction: the left library contains only backend-registered block definitions, the centre iframe renders a signed preview of the exact persisted revision, and the right inspector edits only schema-declared configuration. The outline remains a keyboard-accessible selection path when preview selection is unavailable.

Library insertion follows the editor's current context: when a block is
selected in the exact preview or outline, a newly chosen block is inserted
immediately after it in the same section and region. Without a selection the
document's initial region remains the deterministic fallback. Section creation
shows every backend-registered layout as an explicit choice and creates the
declared regions for that layout. Internal block and layout identifiers are not
shown as ordinary editor labels; registered block labels and readable layout
names keep application namespaces out of the Communications Officer workflow.

The same workspace is also exposed without the Admin SPA navigation shell at
`/page-builder-embed/{surface}/{id}`. This route is authenticated by the same
global middleware, uses the exact `PageBuilderWorkspace` component, and exists
for same-origin application shells such as Anokii. It is not a second editor
and has no separate persistence or transport path. The ordinary
`X-Frame-Options: SAMEORIGIN` response default remains in force, so a remote
site cannot frame an authenticated editor.

The schema-driven structured editor is likewise available without the Admin SPA
navigation shell at `/entity-editor-embed/{entityType}/{id}`. The reserved
`create` id opens create mode, and an optional `bundle` query selects a
server-declared bundle through the ordinary two-stage schema flow. This route
mounts the same `SchemaForm`, rich-text, date/time, file, slug, validation, and
entity-autocomplete widgets as the default Admin SPA. Existing entities also
receive the same workflow transitions, transition history, capability-gated
delete action, and authoritative API enforcement. A same-origin parent receives
only the bounded lifecycle and resource identity notifications described below;
content values and policy decisions never move through `postMessage`.

### Same-origin embed lifecycle protocol

Both shell-free editor routes expose versioned lifecycle messages to a
same-origin parent. Each logical lifecycle observation is delivered **once** on
the versioned protocol. Ordinary events use
`waaseyaa.admin.embed.lifecycle.v1`; the closed v1 vocabulary remains
byte-for-byte limited to `ready`, `dirty`, `saved`, `deleted`, and `failure`.
The v2 envelope `waaseyaa.admin.embed.lifecycle.v2` is used only for the
additive `transitioned` event. An existing v1 host that already accepted origin,
source, and the v1 vocabulary therefore receives exactly one versioned message
per ordinary event and is not required to deduplicate a parallel v2 copy. A v2
host consumes those same v1 ordinary events plus the v2 `transitioned` event.
The surface is `entity-editor` or `page-builder`. Identity fields are limited to
the applicable entity type, surface id, and entity id. A dirty event contains
one boolean. A failure contains only a closed kind (`session-expired`,
`permission-denied`, `conflict`, `validation`, `network`, or `server`) and an
optional HTTP status. Content values, field names, validation details, policy
reasons, response bodies, credentials, and tokens are forbidden.

The entity editor emits a v2 `transitioned` event only after the workflow API
accepts a transition and returns a structurally valid authoritative result. Its
bounded `transition` member contains exactly the resulting workflow `state` and
the boolean `publicChanged`. The state comes from the accepted transition
result, not the clicked button or any client-side inference. The current
framework API computes `publicChanged` by comparing the served projection before
and after the transition: it is true only when either projection is public and
its public status, workflow state, served revision, or published-revision
pointer changed. The Admin client requires string `transition`, `from`, and
`to` members; a present `public_changed` value must be boolean. An omitted
`public_changed` member is compatible, not malformed: the committed transition
is reported as success and `publicChanged` is true so hosts refresh public
rendering the same way they did before the signal existed. A denied,
conflicted, failed, or malformed required response emits no transitioned event.
Hosts may use `publicChanged` to decide whether a public-content view needs
refreshing; they must not interpret it as an access decision.

The child posts only to `window.parent` with `window.location.origin`, and only
when it is framed. A host must accept a lifecycle event only when both
`event.origin === window.location.origin` and `event.source` is the exact iframe
window it created. The protocol is observation-only: it adds no command channel,
mutation, authentication, or access path. Canonical workspaces retain all save,
delete, validation, workflow, revision, and conflict authority.

`ready` means that the canonical workspace completed its initial authoritative
reads. Entity-form edits and unsaved page-builder configuration emit dirty state;
a successful persistence boundary returns dirty to false before `saved`. Initial
or later authentication loss is `session-expired`; a 403 is
`permission-denied`; an optimistic-concurrency refusal is `conflict`; a
non-advisory HTTP 422 is `validation`; a request that received no HTTP response
is `network`; other failures collapse to `server`. An advisory-acknowledgement
HTTP 428 is not a lifecycle failure: the canonical form keeps dirty state and
retries the same candidate after acknowledgement. The parent may use these
states to render chrome, refresh identity-only lists, and confirm close. It must
not infer access from readiness or inspect the iframe DOM.

During the compatibility interval the entity editor also emits the historical
`waaseyaa.entity-editor.saved` and `waaseyaa.entity-editor.deleted` identity-only
messages as a **separate** channel. Those historical messages are not additional
versioned-lifecycle deliveries; an existing host that already consumed both the
v1 envelope and the legacy saved/deleted types continues to see that same pair
and is not required to start deduplicating versioned messages. No new legacy
message types may be added.

Role-focused shells such as Anokii may supply navigation, branding, list views,
and content-type shortcuts around this route. They must not reimplement the
schema widgets, workflow transitions, validation, or mutations. This keeps
high-volume structured authoring consistent with the default Waaseyaa SPA while
allowing the application shell to remain simple and task-specific.

The inspector reuses the Admin SPA's governed field widgets rather than maintaining page-builder-only controls. A block configuration property with `x-widget: richtext` renders `WidgetsRichText`; a property with `x-widget: entity_autocomplete` renders `WidgetsEntityAutocomplete`. Entity-autocomplete properties may declare an `x-target-filter` object whose exact field/value pairs are added as server-side equality filters. This lets a media block, for example, expose only `media` entities with `bundle: image` while preserving the common accessible combobox, validation, and entity-reference behaviour. Properties without a registered widget continue to use the schema-driven native boolean, numeric, select, text, or multiline fallback. Required state and stable control IDs come from the block configuration schema in every case.

These widgets are a shared presentation seam: Waaseyaa's default Admin SPA and downstream shells such as Anokii embed the same page-builder workspace and backend contract. A downstream shell may brand and navigate the workspace, but it must not fork block semantics, validation, revision handling, media filtering, or mutation behaviour.

Every explicit change is sent through `PageBuilderClient` with the observed entity revision, document fingerprint, and a cryptographically generated idempotency key. The server remains authoritative for access, validation, revision creation, and conflict handling. Neither the Admin SPA nor downstream shells can submit arbitrary renderer names, free-form executable markup, or bypass the common page-builder surface.

Block configuration is also recovered server-side after a short idle delay. The browser does not store page content in local storage or IndexedDB; the same revision-guarded command creates a recoverable draft revision and reports whether the editor is saving, saved, or waiting to save. Revision history is exposed by the shared surface when the application supplies a history gateway. Editors can compare a historical layout with the current draft and restore it only by creating a new conflict-checked draft revision. Restore never deletes history, moves the published pointer, or bypasses the application's normal review and publication workflow.

## Implementation gotchas

- **Browser `fetch` loses binding when stored**: Passing `fetch` as a default parameter (`private fetchFn = fetch`) detaches it from `window`, causing "illegal invocation" at call time. Wrap in an arrow function: `(...args) => fetch(...args)`.
- **Nuxt `$fetch` doesn't send cookies by default**: Admin SPA fetch calls to PHP endpoints need `credentials: 'include'` to send the PHPSESSID cookie. Without it, session-based auth fails silently.
- **Nuxt async plugins can't call composables**: `defineNuxtPlugin(async () => ...)` runs outside the composable lifecycle. Use raw `$fetch` with explicit `baseURL: '/'` and `credentials: 'include'` in plugins. Composables like `useApi()` work only in `<script setup>`, composables, and middleware.
- **Admin plugin runs on ALL pages including public auth pages**: The async admin plugin (`packages/admin/app/plugins/admin.ts`) fetches `/_surface/session` on every page. It must skip auth check for all public auth paths (`/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`) via the `publicAuthPaths` array, otherwise 401 → redirect → 401 loop. `useRoute()` is unreliable in async plugin context — use `window.location.pathname` on client.
- **Nuxt `[entityType]` catch-all matches single-segment paths**: In E2E tests, navigating to `/some-path` hits the dynamic `[entityType]/index.vue` route instead of showing a 404. Use multi-segment paths (`/no/such/deep/route`) to test error pages.
- **Auth config in admin SPA**: `runtimeConfig.public.auth` provides `registration` (admin/open/invite) and `requireVerifiedEmail` (boolean). Cast as `Record<string, unknown>` in TypeScript to safely access nested keys. Controlled by `NUXT_PUBLIC_AUTH_REGISTRATION` and `NUXT_PUBLIC_AUTH_REQUIRE_VERIFIED_EMAIL` env vars.
- **Nuxt `.env` changes require dev server restart**: HMR picks up source file changes but NOT `.env` changes. Runtime config from `.env` is read at server startup only. Clear `.nuxt/` cache if values seem stale after restart.
- **Git worktrees can't run Nuxt dev server**: Worktrees share source via symlinks but not `node_modules/.vite/` or `.nuxt/`. Vite module resolution fails with MIME type errors. Run E2E tests against the main repo's dev server, not from worktrees.

<!-- Spec reviewed 2026-05-25 - mcp-endpoint-admin-m5c-01KSEFTB: MCP admin surface — tool registry browser (/mcp/tools), per-tool detail (/mcp/tools/{name}), server config viewer (/mcp/server-config). Nav group "MCP" added to NavBuilder.vue. -->
<!-- Spec reviewed 2026-08-03 - #2177 F1 C1c: MCP approvals operator page (/mcp/approvals) — useMcpApprovals composable, McpApprovalDecisionDialog, capability-gated NavBuilder link via useAdmin().can('mcp.approval.view'); dist content pinned by AdminDistContentTest::shipped_bundle_contains_the_mcp_approvals_page. -->
<!-- Spec reviewed 2026-05-24 - workflow guards read-only matrix section on /workflows/{id} (M4A-5 Phase 1, #1470) -->
<!-- Spec reviewed 2026-05-25 - inertia-demotion-nuxt-standardisation-01KSEFTS - WP03 - SPA bet section added per DIR-007 -->
<!-- Spec reviewed 2026-05-25 - media version browser page /media/{uuid}/versions (DIR-005 versioned-blob-media-abstraction-01KSEFTJ WP04) -->
<!-- Spec reviewed 2026-07-10 - CW-v1 WP-4 (#1920): workflow transition UI. New useWorkflowTransitions composable (apiFetch over GET /api/{type}/{id}/workflow/transitions + POST .../workflow/transition; a GET 404 is absorbed into an empty list per the R8 oracle contract — missing/unviewable renders no buttons, not an error). New components/workflow/TransitionControls.vue (<WorkflowTransitionControls>, nested-dir prefix) mounted in pages/[entityType]/[id].vue page-header-actions: one button per available transition, pending-disable, inline errors[0].detail on denial, emits `transitioned` (page re-fetches SchemaView via a refresh key + success message). SchemaList renders workflow_state as a status-pill badge (inside the schema column, or a synthetic trailing column when entities carry the attribute but the schema column set omits it). i18n keys workflow_transitioned / workflow_transition_error_generic / workflow_state_column_label in en+fr. -->
<!-- Spec reviewed 2026-08-13 - #2344 shared client adapter: authenticated shell-free /page-builder-embed route mounts the exact PageBuilderWorkspace for same-origin Anokii integration while SAMEORIGIN framing protection remains active. -->
<!-- Spec reviewed 2026-08-13 - shared structured editor: authenticated shell-free /entity-editor-embed route mounts the exact schema widgets and workflow controls for same-origin role-focused shells; create bundle selection remains server-schema-driven and parent notifications carry identity only. -->
<!-- Spec reviewed 2026-08-20 - #2461 authoritative embedded transition presentation: v2 adds a success-only transitioned event carrying the API-confirmed destination state and server-derived public projection change flag; ordinary events remain one v1 delivery; omitted public_changed is compatible. -->
