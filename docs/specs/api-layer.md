# API Layer

<!-- Spec reviewed 2026-08-24 - #2537: If-Match JSON:API mutation surfaces share
`Waaseyaa\Api\Http\EntityMutationPrecondition`, which wraps
`EntityMutationToken::fromHttpIfMatch()` and owns the 428/400/412
`MUTATION_PRECONDITION_*` JsonApiDocument envelope. Admin/page-builder keep
body `mutation_token` / revision-fingerprint transport; they do not switch to
If-Match. Field auto-save now uses the same codes and evaluates a missing
If-Match before entity lookup. -->
<!-- Spec reviewed 2026-08-24 - #2493: purpose-built `PATCH|DELETE /api/oidc-clients/{id}`
now requires the same strong aggregate `If-Match` fence as `JsonApiRouter`
(`MUTATION_PRECONDITION_REQUIRED` 428, `INVALID_MUTATION_PRECONDITION` 400,
`MUTATION_PRECONDITION_FAILED` 412, JsonApiDocument envelope). Authorized
`GET /api/oidc-clients/{id}` emits the current token as `ETag` plus
`meta.mutation_token`. Tokens are derived per request from loaded entity
state. The auto-generated `/api/oidc_client/{id}` surface already enforced
this fence; both mutation surfaces now agree. -->
<!-- Spec reviewed 2026-08-15 - S1-FW-CFG-04: the JWKS endpoint's response is
now governed by the OIDC signing-key lifecycle. `JwksDocumentBuilder` publishes
only keys whose lifecycle state can verify (revoked keys are excluded) and
asserts the closed `SigningAlgorithmPolicy` per key; `JwksController` reads its
`Cache-Control: public, max-age` from `SigningKeyLifecyclePolicy::
jwksCacheLifetimeSeconds()` instead of a hardcoded 86400, so the published
cache lifetime and the rotation/retention arithmetic share one policy. Endpoint
paths and route registration (`OidcHttpRoutes`, `AuthOidcRouteServiceProvider`)
are unchanged. The lifecycle contract (states, staged rotation, retention,
emergency revocation) is docs/specs/s1-signing-key-lifecycle.md. `packages/
oidc/` is now drift-mapped to this spec: it is the enduring home of the OIDC
issuer's HTTP surface (discovery/authorize/token/userinfo/JWKS route tables and
the oidc-flows-completion review record below). -->

<!-- Spec reviewed 2026-08-15 - OIDC issuer surface completion: two previously
undocumented `packages/oidc/src/` concerns are contract-bearing parts of this
spec's OIDC issuer surface. (1) Authorization-code persistence
(`packages/oidc/src/Repository/`): `AuthorizationCodeRepositoryInterface` is
the /token endpoint's only persistence port. Codes are 60-second single-use
grants (`DatabaseAuthorizationCodeRepository::TTL_SECONDS`); `consume()` is
atomic — a single-statement `UPDATE … SET consumed_at WHERE code = ? AND
consumed_at IS NULL AND expires_at > ?` with an affected-rows check, so
exactly one concurrent caller wins and a second consume returns null. Binding
fields (client_id, redirect_uri, PKCE challenge) are stored verbatim and
validated at exchange time, and the optional `nonce` is round-tripped so
/token can embed it in the ID token's `nonce` claim (OIDC Core §3.1.3.6). The
`oidc_authorization_codes` schema is owned by the 2026-08-12 migration
(`2026_08_12_000006_oidc_authorization_code_schema.php`) — no longer installed
by request traffic — with expiry and client-id indexes; `purgeExpired()` is
the scheduled-job cleanup hook. (2) Token revocation
(`packages/oidc/src/Revoke/RevocationController`, `POST /oidc/revoke`,
registered by `OidcHttpRoutes`): RFC 7009 semantics — client-authenticated
(confidential clients must present a verified secret), unknown or missing
tokens always return 200 to prevent token enumeration (RFC 7009 §2.2), and
access-token revocation cascades to the paired refresh token while
refresh-token revocation does not cascade back (asymmetric per RFC 7009
§2.1). -->

<!-- Spec reviewed 2026-08-13 - workflow discovery now includes a bounded
`meta.workflow_history` projection from the API-local audit read model after
the existing authenticated, entity-view and workflow-state field gates pass.
It contains only successful `workflow.transition` records for the exact
`entity:<type>/<id>` subject; denied attempts remain on the admin-only audit
surface. Installs without the optional audit read model return an empty
history without weakening transition authorization. -->

<!-- Spec reviewed 2026-08-06 - #2268: the existing scalar public-content-search availability gate now owns discovery as well as routing. When GET|HEAD /api/content/search is enabled and its optional packages are installed, ApiServiceProvider contributes exactly one same-origin RFC 9727 entry and one ARD/AI Catalog entry with the stable key api:content-search. Disabled or unavailable search withdraws the route and both entries. Catalog construction remains metadata-only and must not resolve database-backed SearchProviderInterface or AtomicRateLimiterInterface services. Applications may attach 2-5 public representative queries to api:content-search. -->

<!-- Spec reviewed 2026-08-05 - #2196: ApiServiceProvider owns an experimental, default-off ARD v0.9 extension of AI Catalog 1.0 at GET/HEAD /.well-known/ai-catalog.json. A separate Foundation capability seam contributes immutable same-origin public artifacts; it is not an overload of RFC 9727 endpoint-link semantics. The byte-pinned ARD schema at commit 4a8a6b8fdd3ac4a50dcb63213573159c1eed7856 is a dev-only validation dependency, while runtime invariants are local and network-free. Application config owns representative queries. Canonical URLs come only from ai_catalog.base_url or APP_URL, never request authority. The surface, Link response, and every entry withdraw when disabled or empty; malformed config fails boot without echoing values. -->

<!-- Spec reviewed 2026-08-05 - #2193: applications may opt in to GET|HEAD /api/content/search when the optional search and auth domains are installed. The endpoint passes the middleware-built immutable principal unchanged to the principal-safe SearchProviderInterface, exposes only a closed JSON:API hit/facet projection, is no-store and anonymous-session-stateless, and uses atomic deployment-global plus fixed-anonymous/hashed-principal rate-limit buckets. It never trusts forwarding headers for identity. Missing optional packages withdraw both route and domain router; installed but missing/failing bindings resolve lazily per request and return a sanitized correlated 503 rather than masquerading as absence. -->

## Optional public content search

`api.content_search.enabled` is a strict boolean and defaults to `false`.
When it is exactly `true`, `ApiServiceProvider` uses Composer autoload presence
for `SearchProviderInterface` and `AtomicRateLimiterInterface` as one cached
scalar gate for both route and router. It does not instantiate either service
at route-build time: both touch `DatabaseInterface`, and the atomic limiter is
a writer covered by the #1611 request-lifecycle invariant. A truly absent
package withdraws the surface. An installed but missing, failing, or wrong-type
binding is resolved inside the request and produces a sanitized, correlated
503. The controller resolver captures only the kernel-services bus and
immutable scalar bounds; it memoizes no request service and is not stored in
the serializable route collection.

Because search and auth remain optional Composer suggestions, API source does
not import their runtime types. API owns `ContentSearchReadModelInterface`,
`ContentSearchRateLimiterInterface`, and validated query/page DTOs. Narrow
adapters verify the string-resolved optional contracts, construct their public
request objects, and copy each permitted result field into the API-owned page;
they never enumerate source properties or copy raw body content. Composer
`conflict` metadata bounds the supported optional-package window on both sides,
`require-dev` drives the real integration in CI, and the architecture suite
forbids Auth/Search imports or runtime requires from returning to API source.

The exact `GET|HEAD /api/content/search` route is public, ordered ahead of
generated `/api/{entity_type}/{id}` routes, and automatically joins the
session-stateless path list. Session-cookie requests still resume normally, so
authenticated search retains its principal. The router accepts only the exact
controller reference and never creates a development or fallback account.

The controller accepts a closed, bounded query vocabulary and refuses invalid
input before consuming quota. It then atomically consumes a global bucket and
one identity bucket. Anonymous traffic shares one fixed bucket; authenticated
keys hash the account id, tenant, community, and claims generation. IP and
forwarding headers are deliberately excluded: deployments therefore have no
proxy-trust precondition and cannot create attacker-controlled bucket
cardinality. Infrastructure or provider failure returns a static JSON:API 503
and logs only the exception class, never its message, request, or indexed
content.

Results are rebuilt field-by-field from the principal-safe search DTOs. The
public hit attributes are title, URL, plain-text highlight, query-local score,
source, content type, quality score, crawl timestamp, topics, and image. Facets
are copied only from the provider's already access-filtered facet set. The
`meta.isComplete` boolean is the provider's bounded raw-candidate-window signal.
When false, totals, pages, and facets are lower bounds, while filters and
non-relevance sorts describe only matches inside that window. It may be false
with an empty principal-safe result after every inspected candidate is denied
or filtered. Raw body, raw indexed metadata, denied identifiers, denied counts,
and execution timing remain unobservable.

<!-- Spec reviewed 2026-08-04 - #2195: ApiServiceProvider now owns an opt-in RFC 9727 catalog at GET/HEAD /.well-known/api-catalog. It assembles a deterministic, closed-world RFC 9264 JSON Linkset from kernel-injected ProvidesApiCatalogEntriesInterface providers. Canonical absolute URLs come only from api_catalog.base_url or APP_URL and must be HTTPS; request Host/X-Forwarded-Host is never reflected. The route is absent without canonical configuration or without an installed public API contribution. Authenticated/write/admin/schema surfaces and non-API discovery documents are never inferred from the route collection. -->

<!-- Spec reviewed 2026-07-24 - #2064 activation follow-up: ResourceSerializer now treats the activated entity accessor as the final Protected-read authority. If legacy field filtering is Neutral but the accessor denies or lacks a read context, the field is omitted without reading its value; an otherwise authorized entity response does not become a 500. Internal fields retain their unconditional outward-denial floors. -->
<!-- Spec reviewed 2026-07-21 - #2101 WP-2: JSON:API create attributes an authenticated creator when an entity type declares a non-identity uid authorization-input field and the client omits it. The shape-based rule covers node, media, note, and future authored quick-entry types without a type-id allowlist, while explicitly excluding User.uid and any other identity key. Explicit uid remains subject to the entity type's field policy. -->
<!-- Spec reviewed 2026-07-21 - #2101 WP-3: the update path accepts ConfigEntityInterface targets after the same declared-field allowlist and access checks used for FieldableInterface targets. This activates bounded PATCH support for explicitly surfaced config rows without changing identity/bookkeeping rejection. -->

<!-- Spec reviewed 2026-07-21 - #2064 media hotfix: controller-dispatched JSON:API 500 responses are generic in every environment. Debug mode may add debug headers and rich HTML error pages on their separate surfaces, but API error bodies never include exception classes, messages, source paths/lines, or stack traces. Full exception detail remains server-side in the configured logger. -->
<!-- Spec reviewed 2026-07-19 - Sheguiandah Gap 1 (#2078): JsonApiController's UUID lookup is identity resolution, not authorization. It now uses an explicit accessCheck(false) query, matching numeric repository find(); show/update/destroy then apply their existing view/update/delete checks respectively. This lets edit-any/delete-any principals target view-hidden drafts without weakening the read-path 404 oracle. -->

<!-- Spec reviewed 2026-07-18 - #2064 WP4 retains only bounded structural route templates and stable priority buckets as route-build optimizations. JsonApiRouteProvider keys templates by base path, exact entity-type exposure shape, and base/workflow mode, then clones every Route into a fresh WaaseyaaRouter. No request, account, entity, authorization decision, provider/service instance, runtime controller capture, or mutable RouteCollection is cached. WaaseyaaRouter preserves descending priority and registration-order ties while reading each priority once. -->

<!-- Spec reviewed 2026-07-15 - #2050: SchemaPresenter maps authoritative field type date to JSON Schema string/format date/x-widget date and projects date settings min/max as x-min/x-max presentation bounds; timestamp/datetime and ordinary strings remain distinct. -->
<!-- Spec reviewed 2026-07-15 - #2047: SchemaPresenter exposes its sorted registry-backed bundle roster to mounted generic admin callers; null means no registry, [] means registry present/no registered bundles. SchemaController rejects a non-empty explicit bundle outside that authoritative roster with 422 instead of silently returning the base schema. -->
<!-- Spec reviewed 2026-07-14 - #2018 authoring spine: EntityValidationException is mapped to 422 on store(), plain update(), and expectation-stated update(); repository validation can no longer escape as an admin/API HTTP 500. -->
<!-- Spec reviewed 2026-07-14 - R21 WP4 (#2010): GraphQlRouter propagates GraphQlEndpoint's statusCode instead of forcing HTTP 200, so parse/auth/method failures reach clients as 400/401/405. withMutationOverrides() remains supported, but a custom update/delete resolver replaces the generated EntityResolver path and therefore owns the enduring not-found/access-denied collapse obligation; delegating to EntityResolver is the preferred way to preserve it. -->
<!-- Spec reviewed 2026-07-18 - field-read boundary WP2 (#2064): POST /api/queue/jobs/{id}/retry preserves and replays the exact authenticated persistent payload through PersistentPayloadReplayInterface, including its versioned authority envelope and correlation metadata. Invalid authentication remains 422; claim, 409 contention, 502 release, 204 success, and failed-row deletion semantics remain unchanged. -->
<!-- Spec reviewed 2026-07-14 - R21 WP6 (#2010): POST /api/queue/jobs/{id}/retry validates the payload, atomically claims the failed row through FailedJobRepositoryInterface, and dispatches only for the claim winner. A competing retry returns JSON:API 409; corrupt payload remains 422, dispatch failure remains 502 and releases the claim, success remains 204 and forgets the row. -->

<!-- Spec reviewed 2026-07-13 - CW-v1 WP-5 WP1 (#1920): deleted the retired read-only workflow
     dry-run/guards machinery — no compat shim, no feature flag. Removed `WorkflowDryRunController`
     (`POST /api/workflow-definitions/dry-run`), `WorkflowGuardsController` + `WorkflowGuardsApiRouter`
     (`GET /api/workflow-definitions/{workflow_id}/guards`), their `ApiServiceProvider` route
     registrations and router wiring, and the `WorkflowDryRunController` dispatch branch in
     `WorkflowDefinitionsApiRouter`. The historical "Spec reviewed" entries further below describing
     these endpoints as live are retained as a changelog record, not current state — see the
     "Workflow Transition Endpoints (CW-v1 WP-4)" section below for the shipped surface. -->

<!-- Spec reviewed 2026-07-13 - CW-v1 option-1 PR-3 (#1920, design §4 "Surface pointer-awareness"):
     JSON:API becomes working-copy-aware on the write/edit surfaces. `show()` gains `?workingCopy=1`
     (serves `loadWorkingCopy()` to an account with entity UPDATE access, 403 otherwise — not an
     existence oracle, since the view gate above already 404'd a missing/view-denied entity; equals
     the plain GET byte-for-byte when no draft exists). `update()`'s PATCH TARGET becomes
     `loadWorkingCopy()` (identical to `find()` for undisciplined entities, pinned by regression test) —
     the echo-tolerant write-allowlist comparison (`EntityWritePayloadGuard::evaluateForUpdate()`)
     now compares against the WORKING COPY's own `toArray()`, not the gate entity's, so a client that
     read the working copy and echoes ITS `revision_id` back is not spuriously refused. Entity/field
     access gates are unchanged (still evaluated against the `find()`-loaded gate entity — type/bundle-
     scoped, no behavior change). `WorkflowTransitionController`'s GET/POST now source the workflow
     POSITION (`meta.workflow_state`, available transitions, the POST target) from `loadWorkingCopy()`
     too — the R8 view gate stays pinned to `find()`, byte-identical. See "GET single" and "PATCH —
     update" below (updated) and the new "Working-copy targeting (CW-v1 option-1 PR-3)" subsection.
     `GenericAdminSurfaceHost::get()` (admin-spa.md), SSR preview, `FieldAutoSaveController`, and
     GraphQL `resolveUpdate()` gain the same targeting — see docs/specs/content-workflow.md "Deferred:
     forward drafts on the shipped workflow" (bullet now fully CLOSED) and docs/specs/admin-spa.md. -->
<!-- Spec reviewed 2026-07-13 - CW-v1 option-1 PR-4 rework (#1920, security): a fresh-context review found the just-shipped write-side allowlist's HARD refusal of `revision_id`/`published_revision_id`/`langcode` was itself a BLOCKER — `ResourceSerializer` emits those as ordinary read attributes (FR-008), and the admin SPA's `SchemaForm.vue` submits the FULL loaded attribute object back on save, so every ordinary node edit through the admin UI 422s. Fix (Drupal JSON:API parity): echo-tolerant rejection on `update()` only — `EntityWritePayloadGuard::evaluateForUpdate()` refuses an identity/bookkeeping key ONLY when its submitted value DIFFERS (type-lenient comparison) from the entity's current stored value; a pure echo passes but is stripped before both the field-access loop and the apply loop (belt: an allowed echo must never reach `$entity->set()`). `store()` (create) is unchanged — hard refuse, no stored value to echo against. Applied to `JsonApiController::update()` and GraphQL `EntityResolver::resolveUpdate()`; `store()`/`resolveCreate()` untouched. New `Waaseyaa\Entity\Write\EntityWritePayloadGuardResult` value object (`refusedKeys`/`echoedKeys`) is the second static method's return shape alongside the unchanged `refusedKeys()`. See "Echo-tolerant rejection on update() (PR-4 rework)" under the "Write-side field allowlist" subsection. -->
<!-- Spec reviewed 2026-07-13 - CW-v1 option-1 PR-4 (#1920, security): closes the write-side field allowlist / pointer-column write hole (`.superpowers/sdd/final-review-findings.md` findings #1 CRITICAL / #2 IMPORTANT) — store()/update() applied every submitted attribute with only per-field ACCESS as the gate, so an account holding plain entity `update` access (no workflow permission) could move the published pointer or forge the current-revision id directly through a PATCH body attribute, since neither `revision_id` nor `published_revision_id` carries a field definition or a shipped field-access policy. New shared `Waaseyaa\Entity\Write\EntityWritePayloadGuard` (modeled on ai-tools' EntityKeyGuard, adapted for payload-key-presence + bundle-scoped resolveFieldDefinitions()) rejects (422, `code: FIELD_NOT_WRITABLE`, `meta.refused_keys`) any payload key that is neither a declared field nor a writable entity key, or that is an identity/bookkeeping column regardless of declaration — reject, never strip. Applied at JsonApiController::store()/update() (GenericAdminSurfaceHost inherits it for free via delegation), GraphQL EntityResolver::resolveCreate()/resolveUpdate() (defense-in-depth). FieldAutoSaveController verified already-safe, not modified. ai-tools EntityKeyGuard's LITERAL_FLOOR gained `revision_id`/`published_revision_id` (empirically did NOT already cover published_revision_id — a real gap, fixed alongside this PR). See new "Write-side field allowlist (CW-v1 option-1 PR-4)" subsection. -->
<!-- Spec reviewed 2026-08-20 - #2461 additive transition success metadata: POST /api/{type}/{id}/workflow/transition may include boolean data.public_changed; omitting it is compatible for older or application-provided controllers. See "Workflow Transition Endpoints (CW-v1 WP-4)". -->
<!-- Spec reviewed 2026-07-10 - CW-v1 WP-4 (#1920): new per-entity-type workflow transition endpoints — GET /api/{type}/{id}/workflow/transitions and POST /api/{type}/{id}/workflow/transition (WorkflowTransitionController + WorkflowTransitionApiRouter, both registered only when resolveOptional(TransitionService::class) resolves). View access enforced in-controller under the R8 oracle standard (view-denied ≡ missing, byte-identical 404 from one factory; fail-closed 404 when no EntityAccessHandler is wired). TransitionDeniedException keeps the WP-2 mapping (permission → 403, all other reasons → 422, code WORKFLOW_TRANSITION_DENIED + meta.reason, duplicated locally — JsonApiController::workflowTransitionDeniedError() stays private). See new "Workflow Transition Endpoints (CW-v1 WP-4)" section. -->
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
<!-- Spec reviewed 2026-06-29 - WP5 foundation wave-2 (route-table inversion): the 14 Waaseyaa\Api\* route groups previously hard-coded in BuiltinRouteRegistrar moved to ApiServiceProvider::routes() via the existing provider loop. Route names, paths, methods, and access gates are UNCHANGED — behavior-neutral. The spec body has been updated at the package-owned route registration and schema self-description sections to reflect that routes now originate from ApiServiceProvider::routes() rather than BuiltinRouteRegistrar. -->
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
<!-- Spec reviewed 2026-05-11 - M4A-2 (#1430 / umbrella #1414) WorkflowDefinitionsController::serializeWorkflow() now includes `metadata: array<string, mixed>` per state in the JSON response (additive 3-line extension; @return type updated; new assertion in WorkflowDefinitionsControllerTest). No change to endpoint shape at the workflow level or to JSON:API entity contracts. -->
<!-- Spec reviewed 2026-05-20 - #1531 ResourceSerializer now strips ALWAYS_INTERNAL_FIELDS (['pass','password','password_hash']) and honors FieldDefinition settings['internal'] => true (e.g. two_factor_secret) before EntityAccessHandler::filterFields(); #1532 api.user.me bumped to priority(10) in AuthOidcRouteServiceProvider so it beats JsonApiRouteProvider's /api/user/{id} catch-all. -->
<!-- Spec reviewed 2026-05-11 - M4A-1 (#1428 / umbrella #1414) new WorkflowDefinitionsController under Waaseyaa\Api\Workflow\ exposing GET /api/workflow-definitions (admin-role-gated, JSON-shaped `{data: WorkflowDefinition[]}`). Not part of the JSON:API entity layer documented in this spec — it is a sibling read-only endpoint dispatched by WorkflowDefinitionsApiRouter. No change to entity JSON:API contracts, ResourceSerializer, or SchemaPresenter. -->
<!-- Spec reviewed 2026-05-13 - M-006 entity-storage-translations-v1: TranslationController updated to call $entity->removeTranslation() directly (interface method, no longer guarded by method_exists since TranslatableInterface now declares it). TranslatableTestEntity + ReadOnlyTranslatableTestEntity fixtures gained `default_langcode` entity key required by the new boot validation. No change to JSON:API entity endpoint contracts, ResourceSerializer, or SchemaPresenter. Full translation surface at docs/specs/entity-storage-translations-v1.md. -->
<!-- Spec reviewed 2026-05-10 - M3B (#1413) SchemaPresenter: when registry yields a bundle enum, the bundle property gains x-widget=select, x-required=true, x-label='Bundle', x-weight=-100 so it renders as a real user-facing field. Default (no registry / empty enum) leaves the property hidden. -->
<!-- Spec reviewed 2026-05-10 - M3A (#1413) SchemaPresenter ctor gains optional FieldDefinitionRegistryInterface; schema endpoint exposes top-level `x-bundle-key` and (when registry wired) `enum` on the bundle property. Documented in admin-spa.md; api-layer contract surface itself unchanged for non-bundle entity types. -->
<!-- Spec reviewed 2026-05-08 - WaaseyaaRouter::match() maps Symfony UrlMatcher failures to Waaseyaa\Routing\Exception\RouteNotFoundException / RouteMethodNotAllowedException (previous callers expecting Symfony ResourceNotFoundException from match() must migrate); HttpKernel catches those Waaseyaa types for JSON 404/405; RouteBuilder::controller() + normalizeControllerDefault() coerce `[FQCN, method]` to `FQCN::method`; HttpKernel merges match params through normalizeControllerDefault (foundation-symfony-fallback-elimination-01KQZR1 WP03–WP04) -->
<!-- Spec reviewed 2026-04-26 - ResourceSerializer prefers non-integer string id() for JSON:API resource id (config machine names); JsonApiController store machine-name path uses config heuristics (id=bundle, or non-default id without bundle, or no uuid); API integration fixtures now map per-entity metadata classes -->
<!-- Spec reviewed 2026-04-25 - RouteBuilder::bind + RouteFingerprint for SSR app-controller binding metadata; see docs/specs/app-controller-invocation.md -->
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

`Waaseyaa\Api\ApiServiceProvider` is declared in `packages/api/composer.json` under `extra.waaseyaa.providers`. Its `routes()` method is the authoritative entry point for API-package routes. The media-version routes remain an internal parked surface tracked by #1742; they are not a supported API contract until real upload bytes are proven durable across the production request boundary.

Foundation registers only the framework-substrate routes it owns (regardless of which higher-layer packages are installed): `/api/openapi.json`, entity-type catalog/lifecycle, broadcast SSE, media upload, attachment download, semantic search, four discovery endpoints, and the SSR catch-alls. **Discovery** read models are implemented in the API package: `Waaseyaa\Api\Http\Router\DiscoveryRouter` implements `DomainRouterInterface` and is registered from `ApiServiceProvider::httpDomainRouters()` so discovery stays co-located with `DiscoveryApiHandler` and JSON:API tooling.

### MCP-admin REST surface

The MCP-admin read API powers the admin SPA's MCP-endpoint dashboard (M5C WP01, mission `mcp-endpoint-admin-01KSEFTL`). All three routes are registered in `ApiServiceProvider::routes()` with `_role: admin` (moved from `BuiltinRouteRegistrar` in WP5); the controller does **not** re-check the role (NFR-001 / DIR-004).

| Route | Method | Controller action | Response shape |
|-------|--------|------------------|----------------|
| `/api/mcp/tools` | `GET` | `McpAdminController::tools` | `{data: {rows: list<{name, summary, category, requiredCapabilities}>}}` |
| `/api/mcp/tools/{name}` | `GET` | `McpAdminController::tool` | `{data: {tool: ToolDetail|null}}` |
| `/api/mcp/server-config` | `GET` | `McpAdminController::serverConfig` | `{data: {config: ServerConfigSnapshot|null}}` |

Dispatch lives in `Waaseyaa\Api\Http\Router\McpAdminApiRouter` (implements `DomainRouterInterface`; `supports()` matches when the `_controller` attribute contains `McpAdminController::`). It mirrors the `MercureMonitorApiRouter` shape and returns `application/vnd.api+json` with status 200, or a JSON:API error envelope for unknown actions (404) / invalid controller refs (500).

The `{name}` segment is `rawurldecode()`-ed once inside `tool()` so tool names containing dots (e.g. `bimaaji.search_specs`) survive double URL encoding by the SPA client.

**MCP approval decision surface (#2177 F1, slice C1b).** Two further `/api/mcp/*` admin routes back the write-tier human-approval gate's operator decisions — `GET /api/mcp/approvals` (`McpApprovalController::index`, permission `mcp.approval.view`) and `POST /api/mcp/approvals/{id}/decision` (`McpApprovalController::decide`, permission `mcp.approval.decide`, `requireCsrf()`). Both demand `requireAuthentication()` + `requireSession(['waaseyaa_uid'])` (a real login session — bearer-only identities are refused), and both are registered in `ApiServiceProvider::routes()` under the `mcpInstalled()` gate. Dispatch: `McpApprovalApiRouter` (mirrors `McpAdminApiRouter`; the controller returns full `Response` objects because the status vocabulary is wider — 200/204/400/403/404/409/503). The controller resolves `Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface` lazily per request via a closure over `ServiceProvider::resolve()` (NOT `resolveOptional()`, which would swallow a bound store's `ApprovalStoreException`), reads the exact-origin allowlist from `cors_origins`, and `mcp.write_tier.approval.allow_self_approval` (default false; PHP-bool-only — any non-bool, including boolean-shaped strings/integers, throws a type-only `ConfigException`). Every 400 body is STATIC controller text — a store adapter's `\InvalidArgumentException` message is never echoed (pagination refusals map to one fixed body; an adapter IAE from `decide()` after the controller's own validation is treated as a nonconforming adapter and fails closed 503). Full contract: `docs/specs/mcp-endpoint.md` §"Admin decision surface".

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
| `src/RedirectResponse.php` | `Waaseyaa\Routing` | Waaseyaa-owned redirect response type for application signatures; remains compatible with the kernel's Symfony transport boundary |
| `src/Redirector.php` | `Waaseyaa\Routing` | Request-scoped composition API for fail-closed local redirects and redirects generated from the complete named route table |
| `src/Controller.php` | `Waaseyaa\Routing` | Optional thin app-controller base exposing only protected `redirect()` and `redirectToRoute()` helpers; plain controllers inject `Redirector` directly |
| `src/Exception/RouteNotFoundException.php` | `Waaseyaa\Routing\Exception` | Thrown from `WaaseyaaRouter::match()` when no route matches the path (wraps Symfony `ResourceNotFoundException`) |
| `src/Exception/RouteMethodNotAllowedException.php` | `Waaseyaa\Routing\Exception` | Thrown from `WaaseyaaRouter::match()` when the path matches but the HTTP method is not allowed (wraps Symfony `MethodNotAllowedException`) |
| `src/RouteBuilder.php` | `Waaseyaa\Routing` | Fluent API for building Symfony Route objects; `entityParameter()` sets `options.parameters.*.type = entity:{id}`; `bind()` sets `options._waaseyaa_app_bindings` for SSR post-load class checks; `controller()` accepts `string`, `callable`, or `[FQCN, method]` and stores normalized `_controller` via `normalizeControllerDefault()` |
| `src/RouteFingerprint.php` | `Waaseyaa\Routing` | Stable hash of path, methods, parameters, bindings, defaults for app-controller descriptor cache invalidation |
| `src/RouteMatch.php` | `Waaseyaa\Routing` | Value object for matched route (name, route, parameters) |
| `src/AccessChecker.php` (in `waaseyaa/access`, not routing) | `Waaseyaa\Access` | Route-level access checking via route options. Owned by the access package; routing depends on access (mission #824 WP05 surface A). |
| `src/AuthOidcRouteServiceProvider.php` | `Waaseyaa\Routing` | Registers `/api/auth/*`, `/api/user/me`, and OIDC discovery/authorize/token routes; depends on `waaseyaa/auth` and `waaseyaa/oidc` for controllers only. `api.user.me` is registered with `->priority(10)` (#1532) so it beats `JsonApiRouteProvider`'s `/api/user/{id}` catch-all — without the bump, `me` was treated as a literal entity id and returned 404. |

### Route precedence and the SSR `render.page` fallback (#1632)

Route resolution order is governed by `WaaseyaaRouter::sortRoutesByPriority()`, **not** by registration order. The router sorts the whole collection by `RouteBuilder::priority()` (the `_waaseyaa_priority` option, **default 0**) descending, using each route's original registration index only as a tiebreaker among equal priorities. The first matching route (by `Symfony\Component\Routing\Matcher\UrlMatcher` order) wins.

The implementation groups routes into descending numeric priority buckets and
replays each bucket in original registration order. This is behaviorally
identical to the stable comparison contract above, while reading each route's
priority exactly once; negative priorities and duplicate priorities retain the
same ordering semantics.

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

### SSR path-alias canonicalization (#1983)

`PathAlias::normalizeAlias()` defines the shared persistence and lookup form:
Unicode stays NFC, `/` remains `/`, and trailing slashes are removed from every
other alias. `PathAliasResolver` applies that same normalizer to inbound alias
paths after language-prefix handling. Consequently `/about` and `/about/`
resolve the same stored alias and entity; query strings remain request metadata
and do not participate in the alias key. This is lookup equivalence, not a new
redirect policy.

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
    public static function forbidden(string $detail = '', string $code = 'FORBIDDEN', array $meta = []): self;     // 403
    public static function unprocessable(string $detail = '', array $source = [], string $code = '', array $meta = []): self;  // 422
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
between the two 409s (see "Conditional update" below). `forbidden()`/
`unprocessable()` gained the same optional `code`/`meta` passthrough (CW-v1
WP-2 rework task 4, #1920) — `forbidden()`'s `code` defaults to the
pre-existing `'FORBIDDEN'` string and `unprocessable()`'s defaults to `''`
(codeless), so every pre-existing caller's response body is byte-identical;
see "Workflow transition denial (#1920)" below for the first consumer.

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

Create and update resource objects may carry
`data.meta.save_advisory_acknowledgements`, a list of at most 32 exact
lowercase 64-hex tokens (#2467). Malformed meta is a 400 and never reaches
storage. `JsonApiController` threads valid tokens through `SaveContext` on
create, plain update, and expected-revision update. A repository
`SaveAdvisoryAcknowledgementRequiredException` becomes one 428 JSON:API error
with code `SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` and the deterministic
advisory payload in `meta.save_advisories`. Validation remains 422 and cannot be
acknowledged. Repositories outside the concrete context-aware
`EntityRepository` accept the legacy no-token path only and fail closed when a
caller supplies tokens.

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
3. **`?workingCopy=1` (CW-v1 option-1, #1920 PR-3 — a SEPARATE gate from step 2, checked only after it passes):** when the query param is truthy (`workingCopyRequested()` accepts the same shapes `SsrPageHandler::isPreviewRequested()` does for `?preview` — `'1'`/`'true'`/`'yes'`, case-insensitive, plus native bool/int), the account must hold entity **UPDATE** access (`EntityAccessHandler::check($entity, 'update', $account)`) or the request 403s (`JsonApiError::forbidden`) — this is NOT an existence oracle, because the view gate in step 2 has already 404'd a missing or view-denied id before the param is ever consulted. On success the served entity becomes `$repository->loadWorkingCopy((string) $entity->id())` (falling back to `$entity` if it returns null) instead of the `find()`-loaded one. `loadWorkingCopy()` is mechanically safe on any entity type — for an undisciplined entity (or a disciplined one with no draft in flight) it equals `find()`, so the response is byte-for-byte identical to the plain GET (pinned by test). `JsonApiRouter::handle()` now threads `$ctx->query` into `show()` (previously only `index()` received it — a pre-existing gap where sparse fieldsets silently never reached a single-resource GET over HTTP; fixed as part of this wiring).
4. Serializes via `$serializer->serialize()` (allowed entities unchanged; the working-copy entity when step 3 applied).
5. Applies sparse fieldsets if `fields[type]` is in the query (`SparseFieldsetApplicator`, same as `index()`).
6. Returns `JsonApiDocument::fromResource()`.

**`store(string $entityTypeId, array $data): JsonApiDocument`**

1. Validates `data.type` matches `$entityTypeId`.
2. **Write-side field allowlist (CW-v1 option-1 PR-4, see the dedicated subsection below)**: `EntityWritePayloadGuard::refusedKeys()` runs over `array_keys($attributes)` — any refused key → 422, before `create()` is even called.
3. Creates entity via `$storage->create($attributes)`. For `node` creates by an authenticated principal, an omitted `uid` is filled from that principal before save; an explicitly supplied create-time author remains unchanged for authorized create-on-behalf flows.
4. Checks create access via `$accessHandler->checkCreateAccess()`.
5. Checks **field edit access** for each submitted attribute via `$accessHandler->checkFieldAccess($entity, $fieldName, 'edit', $account)`. Uses `isForbidden()` (field-level semantics).
6. Saves entity. `EntityValidationException` maps to 422; it never escapes as HTTP 500.
7. Returns document with `statusCode: 201` and `meta.created = true`.

**`update(string $entityTypeId, int|string $id, array $data): JsonApiDocument`**

1. Loads entity (the "gate entity" `$entity`, via `find()`/`loadByIdOrUuid()`), validates `data.type` and optional `data.id` (409 Conflict if UUID mismatch).
2. Parses the optional `data.meta.expected_revision_id` expectation (see "Conditional update" below): invalid value → 400; type not single-axis revisionable → 422. Both screens are type-level (definition reads only) — no entity state is revealed before the access check.
3. Checks update access at entity level, against the gate entity.
4. **PATCH TARGET becomes the WORKING COPY (CW-v1 option-1, #1920 PR-3):** `$target = $repository->loadWorkingCopy((string) $entity->id()) ?? $entity` — the tip revision when the entity is disciplined and a draft exists, else exactly `$entity` (mechanically safe for every undisciplined entity, pinned by regression test — `WorkingCopyPointerAwarenessFlowTest::patch_on_an_undisciplined_entity_is_byte_identical_to_pre_pr3_behavior`). Every step from here on operates on `$target`, **except** the entity/field-access gates (steps 3 and 6 below), which intentionally still evaluate `$entity` — access decisions are type/bundle-scoped, not revision-scoped, so this is no behavior change.
5. **Write-side field allowlist (CW-v1 option-1 PR-4)**: same guard as `store()`, run against `$target->bundle()`/`$target->toArray()` — any refused key → 422, before the field-access loop and before any `set()`/`save()`. **PR-3 judgment note:** the echo-comparison basis (`evaluateForUpdate()`'s `$currentValues`) is the WORKING COPY's own stored values, not the gate entity's — a client that read the working copy (`?workingCopy=1`) and echoes ITS `revision_id` back is echoing the TIP's value, which differs from the published pointer's `revision_id` whenever a draft is in flight; comparing against the gate entity would misclassify that legitimate echo as a differing (refused) value.
6. Checks field edit access for each submitted attribute, against the gate entity `$entity` (see step 4's note).
7. Applies updates via `$target->set($field, $value)` (requires `$target instanceof FieldableInterface`).
8. Saves through `getRepository()->save($target)` in both cases (C-22 WP3 unified the two save paths onto the canonical repository) — **without** an expectation, the plain form; **with** an expectation, `getRepository()->save($target, context: SaveContext::default()->withExpectedRevisionId($n))` — and returns the resource serialized from `$target`. **A stated `expected_revision_id` against a DIVERGED working copy** (the tip has moved since the client's expectation was formed) hits the existing storage `\LogicException`/`RevisionConflictException` rejection matrix (`revision-system-unified.md` §3b) exactly as any other expectation mismatch does — no new machinery; the controller's `catch (\LogicException $e)` → 422 / `catch (RevisionConflictException $e)` → 409 mapping in `saveWithExpectation()` is unchanged.
9. **Both save paths catch `Doctrine\DBAL\Exception\UniqueConstraintViolationException` → 409** (added 2026-07-02, audit-remediation WP2 review — previously only `store()` had this mapping and a PATCH tripping a uniqueness constraint, e.g. the attachment one-active-per-parent partial index under a race, surfaced a raw 500 with driver SQL). Same status/title shape as `store()`'s duplicate-ID 409, codeless (so `code: 'REVISION_CONFLICT'` stays the discriminator for the optimistic-locking 409), detail `"Updating entity of type '<type>' with ID '<id>' violated a uniqueness constraint."` — names the REAL entity id, not the request locator (locator honesty, contract §15). Pinned by `JsonApiControllerConflictTest::patchWithoutExpectationMapsUniqueConstraintViolationTo409` / `::patchWithExpectationMapsUniqueConstraintViolationTo409`. A repository `BundleUniqueKeyConflictException` is the more specific first catch: it returns 409 with code `BUNDLE_UNIQUE_KEY_CONFLICT` and bounded meta naming the bundle, declared key, fields, and submitted values. Create, plain update, and expected-revision update share this mapping; unrelated driver violations retain the codeless response.
10. **Both save paths catch `EntityValidationException` → 422.** The plain and expectation-stated PATCH paths share the same validation-error factory, so invalid input is rejected without becoming an HTTP 500.

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

> **DB-03 aggregate-mutation contract supersedes the historical revision-only
> request seam below.** Every externally routed update or delete of an existing
> aggregate now requires exactly one strong `If-Match` value containing the
> opaque aggregate mutation token returned by an authorized read. Missing
> preconditions return 428, weak/wildcard/list or malformed validators return
> 400, and an identity mismatch or stale token returns 412. The repository CAS
> is authoritative at commit and a successful mutation returns the successor
> token in both resource metadata and `ETag`. `expected_revision_id` remains a
> compatibility input for the narrower historical revision-head contract; it
> is not a substitute for the aggregate precondition. Because
> `WaaseyaaContext` does not carry headers, the public HTTP router parses and
> validates `If-Match` before dispatching to `JsonApiController` through
> `Waaseyaa\Api\Http\EntityMutationPrecondition` (the L4 JSON:API envelope
> adapter; `EntityMutationToken::fromHttpIfMatch()` remains the policy
> authority). The same helper is used by the purpose-built admin OIDC client
> controller (`PATCH|DELETE /api/oidc-clients/{id}`), translation mutations,
> workflow transition POST, and field auto-save. Missing preconditions return
> 428 before entity lookup on the JSON:API router and OIDC client mutations,
> so a missing header cannot distinguish an unknown id from an existing one.
> Field auto-save returns 404 for an unknown entity type first (F3 catalog
> contract), then applies the same missing-If-Match 428 before entity-id
> lookup. Translation and workflow POST still apply the view/load
> gate first (403/404, including R8 byte-identical missing vs view-denied),
> then the same envelope. Authorized
> `GET /api/oidc-clients/{id}` returns the current token as a strong `ETag`
> and `meta.mutation_token`. The auto-generated `/api/oidc_client/{id}`
> JSON:API route remains fenced by `JsonApiRouter`; the two surfaces agree.
> Conflict documents never include the winning token.

**Historical revision-head seam.** The optional revision expectation rides the
PATCH body:

```json
{ "data": { "type": "<type>", "attributes": { "...": "..." },
            "meta": { "expected_revision_id": 5 } } }
```

Headers do not reach `JsonApiController` (`WaaseyaaContext` carries
`account/parsedBody/query/method` — no headers), which is why the aggregate
precondition is enforced by the HTTP router rather than by this controller
method. The body seam remains available only for revision-head compatibility.

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
(NFR-003). **409 catalogue:** this controller emits four 409 shapes — the
pre-existing codeless `data.id`-vs-uuid mismatch, the codeless generic
uniqueness-constraint trip on create/update saves (2026-07-02, WP2 review —
see `update()` step 7 above), the specific bundle-key 409 with code
`BUNDLE_UNIQUE_KEY_CONFLICT`, and this one; `code: 'REVISION_CONFLICT'` is the
optimistic-lock discriminator. **Locator honesty:** uuid-routed PATCHes
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

### Workflow transition denial (#1920, CW-v1 WP-2 rework task 4)

`Waaseyaa\Workflows\Listener\WorkflowStateGuard` throws
`Waaseyaa\Workflows\Transition\TransitionDeniedException` from `PRE_SAVE`
inside `EntityRepository::save()` (docs/specs/content-workflow.md,
"Save-path guard") whenever a raw write attempts an illegal or unpermitted
workflow-state change. All three save sites in this controller — `store()`,
`update()`'s plain save, and the expectation-stated save — catch it and map
it through a shared `workflowTransitionDeniedError()` helper rather than
letting it surface as an uncaught 500:

| `TransitionDeniedException::$reason` | Response |
|---|---|
| `permission` | 403 Forbidden |
| `illegal_edge` / `unknown_transition` / `unbound` | 422 Unprocessable Entity |

Both shapes carry `code: 'WORKFLOW_TRANSITION_DENIED'` and
`meta: ['reason' => <reason>]` (mirrors the `REVISION_CONFLICT` code/meta
pattern above) — the machine-readable discriminator; the exception's message
is already operator-friendly and passes through as `detail` unchanged.
`waaseyaa/workflows` (L3) is a declared runtime `require` of `waaseyaa/api`
(L4) — importing downward is layer-legal — so the catch is a real,
always-resolvable dependency rather than a class-name-string guess. Pinned by
`JsonApiControllerWorkflowDeniedTest`.

### Write-side field allowlist (CW-v1 option-1 PR-4)

Root cause (`.superpowers/sdd/final-review-findings.md` findings #1 CRITICAL /
#2 IMPORTANT): `store()`/`update()` used to apply every attribute in
`data.attributes` with only per-field ACCESS as the gate (`checkFieldAccess`)
— no allowlist restricted writes to declared, non-bookkeeping fields, in
contrast to the read-path query allowlist (`validateQueryFields()` above).
Neither `revision_id` nor `published_revision_id` (real base columns WP-2
added) carries a field definition or a shipped field-access policy, so an
account holding only plain entity `update` access — no workflow/publish
permission at all — could move the published pointer (or forge the
current-revision id) directly through a PATCH body attribute, bypassing
`WorkflowPointerMoveGuard` and every transition permission entirely
(`docs/specs/content-workflow.md` "Write-side field allowlist / pointer-column
write hole", now closed).

**The guard — `Waaseyaa\Entity\Write\EntityWritePayloadGuard::refusedKeys(EntityTypeInterface $definition, string $bundle, list<string> $payloadKeys, EntityTypeManagerInterface $entityTypeManager): list<string>`**
(`@api`, `packages/entity/src/Write/EntityWritePayloadGuard.php`). Modeled on
ai-tools' `EntityKeyGuard` (`packages/ai-tools/src/Entity/EntityKeyGuard.php`)
but adapted for the field-map write surfaces: keyed by payload KEY presence
(the callers already have `array_keys($attributes)`), and checked against the
bundle-scoped declared-field set
(`EntityTypeManagerInterface::resolveFieldDefinitions()` — the exact source
`validateQueryFields()` already uses for reads) rather than only entity keys,
so an ordinary bundle field (e.g. a per-bundle `body`) is writable even though
it has no base-type FieldDefinition. A payload key is refused when either:

- it is an identity/bookkeeping column: the entity-key KINDS `uuid`,
  `revision`, `langcode`, `default_langcode` (resolved via
  `EntityTypeInterface::getKeys()`, so a renamed column is caught under its
  real name), unioned with the literal floor `revision_id`,
  `published_revision_id`, `uuid`, `langcode`, `default_langcode` — refused
  **regardless of field declaration**. The literal floor is what closes
  findings #1/#2: `published_revision_id` carries NO entity-key kind on any
  shipped entity type, so only the literal name catches it; OR
- it is NOT a declared field (bundle-scoped `resolveFieldDefinitions()`) and
  NOT a writable entity key (`label`/`bundle` — ordinary content and
  create-time structure respectively, deliberately never refused, mirroring
  `EntityKeyGuard`'s docblock).

`status`/`workflow_state` are ordinary declared fields on `node`, so they pass
this guard untouched — their write stays gated by field-level access
(`NodeAccessPolicy::PUBLISH_GATED_FIELDS`, the WP-0 interim gate above)
exactly as before. This guard does not double-gate them.

**Deliberate deviation from `EntityKeyGuard`: the `id` kind is not force-refused.**
`store()` has a pre-existing, tested contract for config-style entities (e.g.
`node_type`) where the id key IS the client-settable machine name at create
time (`$usesConfigMachineIds` branch above,
`JsonApiControllerConfigEntityTest::storePreservesExplicitMachineNameForConfigEntity`).
Refusing `id` unconditionally (as `EntityKeyGuard` does for its own,
config-entity-naive, agent-tool callers) would break that contract. Instead,
`store()` excludes the resolved config machine-name key from the keys it
hands to the guard only inside that existing branch; a numeric/uuid-keyed
content entity's id column (e.g. `node`'s `nid`) is simply never a declared
field and never `label`/`bundle`, so it is still refused via the general
declared-field-or-writable-key branch — the same effective protection,
achieved without special-casing every entity type. `update()` carries no such
exception (a PATCH never legitimately renames a config entity's own id).

**Echo-tolerant rejection on `update()` (PR-4 rework, Drupal JSON:API
parity).** A fresh-context review of the guard above found its HARD,
unconditional refusal was itself a BLOCKER on `update()`: **`revision_id` is
a documented load-bearing READ attribute** (FR-008, "ID Resolution" /
optimistic-locking section below — `GET`/`show()` emits it on every
revisionable type on purpose, and `published_revision_id`/`langcode` ride
along the same generic `toArray()` path), and the admin SPA's
`SchemaForm.vue` submits the FULL loaded attribute object back on every save
(`formData.value = { ...entityResult.value.attributes }` →
`update(props.entityType, props.entityId, formData.value)`). Hard-refusing
every one of those on every ordinary edit 422s the admin UI's own
read-modify-write round trip — CI was green only because no test round-tripped
a serialized revisionable entity end-to-end.

The fix, `EntityWritePayloadGuard::evaluateForUpdate()` — used ONLY by
`update()` call sites, never by `store()`/`resolveCreate()` (create has no
stored value to echo against, and the create surface does not round-trip a
prior read):

- For the identity/bookkeeping set (LITERAL_FLOOR ∪ registered refused-kind
  columns) ONLY: a submitted key is refused **only when its value DIFFERS**
  from the target entity's current stored value (`$currentValues`, the
  caller's already-loaded `EntityInterface::toArray()`). A pure echo passes.
- Value comparison is **type-lenient**: `(string)`-normalized, so a
  JSON-decoded int and a string-hydrated storage column compare equal. `null`
  and absent (`!array_key_exists`) both count as "stored null" — a submitted
  `null` is an echo only against a null/absent stored value, never against a
  non-null one.
- A passing echo is reported separately (`EntityWritePayloadGuardResult::$echoedKeys`,
  alongside the unchanged `$refusedKeys`) so the caller can **strip it before
  the field-access loop AND the apply loop** — belt: even an ALLOWED echo
  must never reach `$entity->set()`, so a stale in-memory pointer read before
  a concurrent transition can never be written back over the real current
  value. `JsonApiController::update()` strips immediately after the guard
  call, before the field-edit-access loop; GraphQL's `resolveUpdate()` strips
  after its (unchanged, R11-collapsed) field-access loop, still before the
  apply loop — both land the strip before `$entity->set()` runs for any
  attribute.
- The **undeclared/unknown-field branch is unchanged and hard-refused
  either way** — echo tolerance applies ONLY to the identity/bookkeeping set.
  A field that is neither declared nor a writable entity key 422s
  unconditionally, even when its submitted value happens to equal a
  same-named key in `$currentValues`.
- `refusedKeys()` itself is untouched (same signature, same hard-refuse
  behavior) — it remains what `store()`/`resolveCreate()` call.

**Reconciliation with FR-008.** FR-008 requires `revision_id` to keep riding
reads (the optimistic-locking client contract: read it, later state it back
via `data.meta.expected_revision_id` — never as a plain attribute). Nothing
here weakens that: a **plain-attribute** `revision_id`/`published_revision_id`
carrying a **different** value than the current stored one still 422s
exactly as before (the security core, findings #1/#2 stay closed) —
echo-tolerance only recognizes "the client read this and is handing it back
unchanged," which is the read contract's own round trip, not a new write
surface.

**Interaction with PR-2's same-state republish (empirically verified during
this rework's own rebase).** Once a node carries a published pointer, it is
"default-revision-disciplined" for every later save
(`WorkflowStateGuard::setDiscipline()`, `docs/specs/content-workflow.md`
"Default-revision discipline"): an AUTHORIZED same-state edit of
already-published content legitimately RE-PUBLISHES what it just saved
(same-state republish, through the `setPublishedRevision()` choke point),
independent of anything in the PATCH body or of this guard. Concretely, an
accepted echo PATCH of a published node's `published_revision_id` does
**not** leave the base row byte-unmoved — the pointer correctly advances
together with the newly-cut revision (verified empirically:
`revision_id === published_revision_id` before AND after such a save). This
is intended engine behavior, not a regression this guard should fight: the
guard's job is only to ensure the pointer never moves to an
UNAUTHORIZED/ARBITRARY value the client supplied (findings #1/#2, still
closed — see `eve_cannot_move_the_published_pointer_through_a_patch_body()`),
never to freeze it. The round-trip pin test therefore asserts
SELF-CONSISTENCY (`published_revision_id === revision_id` after the save),
not byte-immutability; the dedicated "value provably not rewritten by the
save" pin (rework brief test #3) uses a NEVER-published node instead, where
discipline never engages and no independent pointer mechanism can mask
whether the guard's own strip-before-apply did its job.

**Applied surfaces:**

| Surface | Call site | Notes |
|---|---|---|
| JSON:API create | `JsonApiController::store()` | Unchanged: hard reject via `EntityWritePayloadGuard::refusedKeys()`, no echo tolerance (no stored value exists yet). |
| JSON:API update (primary) | `JsonApiController::update()` | Echo-tolerant via `EntityWritePayloadGuard::evaluateForUpdate($definition, $bundle, $attributes, $entityTypeManager, $entity->toArray())`. Refused keys → 422 `code: FIELD_NOT_WRITABLE`, `meta.refused_keys` (unchanged shape). Allowed echoes are stripped from `$attributes` before the field-access loop and the apply loop. Unconditional — runs even with no access handler/account bound (a structural validation, not an access decision, mirroring `validateQueryFields()`). |
| `GenericAdminSurfaceHost` create/update | `handleCreate()`/`handleUpdate()` | No separate change needed — both fully delegate to `JsonApiController::store()`/`update()`, so the (echo-tolerant, for update) guard applies for free. Pinned by `GenericAdminSurfaceHostWriteAllowlistTest` (structural refusal, lightweight fixture) and `GenericAdminSurfaceHostWriteAllowlistFlowTest` (PR-4 rework: full round-trip pin, real Node + workflow wiring). |
| GraphQL create | `EntityResolver::resolveCreate()` | Unchanged: hard `assertWritable()` (defense-in-depth; the generated GraphQL input type already bounds the surface). |
| GraphQL update | `EntityResolver::resolveUpdate()` | Echo-tolerant via the new private `assertWritableForUpdate()`, mirroring the JSON:API shape: refusal throws `GraphQL\Error\UserError` naming the refused keys before `set()`/`save()`; an allowed echo is stripped from `$input` before the apply loop. |
| `FieldAutoSaveController` | unchanged | Already declared-field-allowlisted at step 5 (`$allFields[$key]`, the bundle field registry) — `published_revision_id` 404s (`field_not_registered`) exactly like any other undeclared key. Verified, not modified; regression-pinned. |
| ai-tools `EntityKeyGuard` | `LITERAL_FLOOR` gains `revision_id`/`published_revision_id` | `EntityKeyGuard` did **not** already cover `published_revision_id` before this PR (empirically confirmed red, then fixed) — no entity-key kind names it, so only a literal-floor addition closes it. `EntityCreateTool`/`EntityUpdateTool` are otherwise unchanged; this class has no echo-tolerance concept (agent tools do not round-trip a prior serialized read the same way). |

422 body shape (`FIELD_NOT_WRITABLE`) — unchanged by the rework, only the
refusal CONDITION changed on `update()`:

```json
{ "errors": [ { "status": "422", "title": "Unprocessable Entity",
    "code": "FIELD_NOT_WRITABLE",
    "detail": "The following attribute(s) are not writable: published_revision_id.",
    "meta": { "refused_keys": ["published_revision_id"] } } ] }
```

Pinned end-to-end (real SQLite, real `NodeServiceProvider` +
`WorkflowServiceProvider` wiring, real `NodeAccessPolicy`) by
`packages/api/tests/Integration/WriteAllowlistPointerBypassFlowTest.php`:
`eve_cannot_move_the_published_pointer_through_a_patch_body()` re-pins
finding #1's exact scenario against the echo-tolerant logic (Eve's submitted
`published_revision_id` is a DIFFERENT value than the live pointer, so it
still 422s and the base row is byte-unmoved); the PR-4 rework adds
`full_attribute_round_trip_patch_persists_the_changed_title_and_the_pointer_stays_self_consistent()`
(the admin-SPA-shaped oracle: GET → PATCH the full attribute set back with
one field changed → 200, title persisted; the published pointer is proven
SELF-CONSISTENT with the new tip rather than byte-unmoved — see the
"Interaction with PR-2's same-state republish" note below),
`echo_equal_published_revision_id_is_accepted_and_the_pointer_is_not_rewritten_by_an_ordinary_edit()`
(a NEVER-published node, where no independent pointer mechanism engages: an
echo of the null pointer alongside a genuine content edit that legitimately
cuts a new revision proves the PUBLISHED pointer stays put — still null —
even while the TIP revision id correctly advances), and
`account_with_only_the_type_level_create_permission_cannot_create_an_article()`
(the MINOR create-access bundle-key fix, deny path). Unit coverage:
`EntityWritePayloadGuardTest` (the guard in isolation, including the
echo-tolerance value-comparison matrix and a translatable-shape `langcode`
case), `JsonApiControllerWriteAllowlistTest` (undeclared attribute — still
hard-refused even when its value matches a same-named stored key — both
pointer columns, declared fields still writable, echoed-null acceptance),
`EntityResolverTest` (GraphQL parity, including echo acceptance and
differing-value refusal), `GenericAdminSurfaceHostWriteAllowlistTest` +
`GenericAdminSurfaceHostWriteAllowlistFlowTest` (admin-surface parity).

### ID Resolution

`loadByIdOrUuid()` accepts `int|string`. If the entity type has a UUID key and the value matches UUID regex (`/^[0-9a-f]{8}-...-[0-9a-f]{12}$/i`), it queries by UUID with `accessCheck(false)`; otherwise it loads by primary key. Both branches perform identity resolution only and return the same underlying entity regardless of locator form. The caller then applies the operation-specific authorization gate: `show()` checks `view` and collapses denial to the canonical 404, `update()` checks `update`, and `destroy()` checks `delete`. A mutation target is never pre-filtered through the query layer's `view` decision.

## Editing representation (#2552)

`show()` accepts `?representation=editing`, which opts one serialization out of
the `RichTextSanitizer` pass so HTML-bearing fields return the stored bytes
byte-for-byte. Every `show()` response carries `meta.representation`
(`rendered` or `editing`), so a read-modify-write client can tell which
projection it holds before writing it back.

Structural validation runs before the entity is loaded, so it is not an
existence oracle:

- an unsupported value → 400,
- `representation=editing` without `?workingCopy=1` → 400 (the two are paired),
- `representation=editing` on `index()` → 400; a collection establishes no
  single entity's update access.

Authorization is layered on the existing read and write boundaries. The pairing
rule means the `?workingCopy=1` gate in step 3 of the access pipeline above has
already required entity **UPDATE** access. Before lossless serialization,
`show()` takes the effective outgoing attributes from the rendered projection
(after internal-field, field-view and sparse-fieldset filtering) and requires
field **edit** access for each HTML-bearing attribute, evaluated on the same
`find()`-loaded gate entity PATCH uses. Any denial fails the whole request with
a generic 403 and no resource body. View-hidden HTML fields, non-HTML read-only
fields, and HTML fields excluded by the sparse fieldset remain compatible
because their stored HTML bytes are not part of the outgoing projection.

`ResourceSerializer::serialize()` grew a `$losslessHtml` flag for this, default
`false`, set only after those gates pass. Every other serialization path —
`index()`, the translation controller, the markdown presenter, the admin surface
host — keeps the sanitized projection unchanged. The serializer performs no
authorization of its own: a caller passing `true` asserts it has already
established both entity-update and effective outgoing HTML field-edit access.

## Mutation echoes (#2553)

`store()` and `update()` accept `$query` and honour the same
`?representation=` toggle, selecting the projection of the RESPONSE ECHO only —
never what is written. `JsonApiRouter::handle()` threads `$ctx->query` into both
(it already did for `show()`).

Without this, a client that keeps the mutation response as its next edit state —
the normal SPA pattern — was safe on its first round trip and destructive on the
second, because the echo had been through the sanitizer.

Three differences from the read side, each deliberate:

1. **No `?workingCopy=1` pairing.** That pairing exists solely as the
   authorization anchor for a read; a successful `PATCH`/`POST` has already
   passed the entity `update`/`create` gate, so `representationError()` takes a
   `$writeAnchored` flag that waives it. Requiring it on a write would be
   ceremony with nothing behind it, and `workingCopy` has no read-side meaning
   there anyway.
2. **Structural validation runs before anything is written**, so an
   unrecognized value is a 400 and not a write followed by a complaint.
3. **A field-`edit` denial downgrades the echo instead of failing.**
   `mutationEcho()` runs the same `losslessHtmlFieldEditDenied()` check the read
   uses, but by the time the echo is built the write has committed — a 403 would
   tell a client its successful mutation failed. It serves `rendered` and states
   that in `meta.representation`, which every mutation response now carries
   (as every single-entity read already did), so the downgrade is detectable.

The projection stays opt-in rather than unconditional. A caller that may `PATCH`
holds the access the editing read is gated on, so an unconditional lossless echo
would disclose nothing new — but the sanitized projection is also what protects
a consumer that renders the echo directly, and every existing `PATCH` consumer
was written against it.

`FieldAutoSaveController` honours the same flag on its single-field echo, where
the argument is stronger still: it has already required field-`edit` access on
that one field and just wrote the caller's own bytes to it. It deliberately does
NOT mirror the structural 400 — refusing a completed write over a query-string
typo would be worse than ignoring the typo — so an unrecognized value there is
simply not the opt-in.

The admin SPA does not opt in; see [jsonapi.md](jsonapi.md) for why adopting it
there is a CW-v1 decision rather than a rider.

The shared sanitizer allowlist was deliberately not widened; see
[jsonapi.md](jsonapi.md) for why a looser baseline would have exposed
protocol-relative off-site URLs on anonymous output.

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
2. Iterates the resolved field names, drops keys that map to entity keys `id` and `uuid` (storage column names from `EntityType::getKeys()`), and reads each remaining value through `EntityInterface::get()`, so `EntityBase::$casts` apply (#1181 ST-7 / ST-9). If the activated accessor denies a Protected read (or no read context is available), that field is omitted without reading its value. This accessor check is the final authority when the legacy field policy is Neutral and cannot turn an otherwise authorized entity response into a 500. See `docs/specs/jsonapi.md` for the pipeline diagram.
3. **Filters internal/credential fields** (#1531). Two layers, both applied **before** the per-account access handler so credentials never reach policy code:
   - `ResourceSerializer::ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash']` — dropped unconditionally even when no `FieldDefinition` exists. Covers raw `_data` keys that hold credential material (e.g. `User::$pass` is set via `setRawPassword()` with no `#[Field]` attribute).
   - `InternalFieldVisibilityPolicy` is the single boot-scoped metadata authority
     shared with Admin schema/detail and JSON:API query validation. It combines
     `FieldDefinition.settings.internal`, the framework migration floor, and
     application declarations under `entity.internal_fields_by_type`. The
     credential-name floor above deliberately remains independent.
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
    /** @return list<string>|null */
    public function availableBundles(string $entityTypeId): ?array;

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
| `x-widget` | string | Widget type hint for admin SPA (text, textarea, richtext, select, boolean, number, email, url, date, datetime, entity_autocomplete, image, file, password, hidden) |
| `x-label` | string | Human-readable field label |
| `x-description` | string | Field help text |
| `x-weight` | int | Display order weight |
| `x-required` | bool | Whether field is required in forms |
| `x-access-restricted` | bool | Field is viewable but not editable by current account |
| `x-entity-type` | string | Entity type ID (top-level) |
| `x-translatable` | bool | Whether entity type supports translations (top-level) |
| `x-revisionable` | bool | Whether entity type supports revisions (top-level) |
| `x-target-type` | string | Target entity type for entity_reference fields |
| `x-cardinality` | int | Authoritative field cardinality (`1` scalar; negative means unbounded) |
| `x-enum-labels` | object | Human-readable labels for enum values |
| `x-min` / `x-max` | string | ISO `YYYY-MM-DD` presentation bounds for a date-only string field; draft-07 numeric `minimum`/`maximum` do not apply to formatted strings |

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

Field type to JSON Schema type: `string->string`, `text->string`, `boolean->boolean`, `integer->integer`, `float->number`, `decimal->number`, `email->string`, `uri->string`, `date->string`, `timestamp->string`, `datetime->string`, `entity_reference->string`.

Field type to widget: `string->text`, `text->textarea`, `text_long->richtext`, `boolean->boolean`, `integer->number`, `email->email`, `uri->url`, `date->date`, `timestamp->datetime`, `datetime->datetime`, `entity_reference->entity_autocomplete`, `list_string->select`.

Format mappings: `email->email`, `uri->uri`, `date->date`, `timestamp->date-time`, `datetime->date-time`. Date values are transported as ISO `YYYY-MM-DD`; schema presentation does not introduce a default, null coercion, timezone conversion, or inference from field names/values.

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

    public function show(string $entityTypeId, ?string $bundle = null): JsonApiDocument;
}
```

When a presenter has a `FieldDefinitionRegistryInterface`,
`availableBundles()` returns its sorted `bundleNamesFor()` roster. A null return
means no registry was supplied and membership cannot be validated; an empty list
means the registry is authoritative and no bundle has registered fields. When a
non-empty bundle is supplied to `show()`, a registry-backed presenter rejects a
value outside that roster with 422 and emits no schema. The base (`bundle=null`)
request remains the bundle-discovery schema. The mounted generic admin provider
resolves the kernel registry and supplies it to the presenter; bare callers that
intentionally omit the registry keep their compatibility behavior.

With a complete access context, the base discovery schema filters that structural
roster through `EntityAccessHandler::checkCreateAccess(entityType, bundle, account)`
and exposes only allowed bundle enum values. If none are allowed, the bundle
property is hidden/read-only with no enum, never an editable free-text field. A
non-empty requested bundle remains a structural schema/edit scope and is not
create-gated; the actual create endpoint independently rechecks bundle-aware
create access before persistence.

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

**Save target is the working copy (CW-v1 option-1, #1920 PR-3):** entity/field-level access (steps 4-8 above, unaffected) still evaluate the `find()`-loaded entity, but the value is applied and persisted through `$target = $repository->loadWorkingCopy($id) ?? $entity` — same targeting pattern as `JsonApiController::update()`. The 200 response echoes `$target`'s post-save value, so an autosave against a forward draft lands on and reports the TIP, never the served/published row. `loadWorkingCopy()` is mechanically safe for undisciplined entities (`=== find()`).

→ See `docs/specs/work-surface.md` F3 for the full wire-up reference.

## Workflow Transition Endpoints (CW-v1 WP-4)

The user-facing surface of the content-workflow engine (`docs/specs/content-workflow.md` "Integration → API (WP-4)"). Registered per entity type (literal type segment + `->default('_entity_type', …)`, like field auto-save), **only when `TransitionService` resolves** — `ApiServiceProvider::routes()` and `httpDomainRouters()` both gate on `resolveOptional(TransitionService::class)`, so an install without `waaseyaa/workflows` wired registers neither the routes nor the router and requests 404 naturally.

**Routes** (both `requireAuthentication()`):
- `GET /api/{entityType}/{id}/workflow/transitions` (`api.{type}.workflow_transitions`) → `{"data": [{"id","label","to"}…], "meta": {"workflow_state": <string|null>, "mutation_token": "<opaque>"}}`. `data` is exactly `TransitionService::getAvailableTransitions()` — the one sanctioned UI read side (permission- AND group-filtered; never offers what the write side would refuse). When at least one transition is available, the response also carries the working-copy token as a strong `ETag`; callers with no available mutation receive neither token nor ETag. An unbound entity type returns 200 with empty `data` (no buttons is the correct UI), never 404/422.
- `POST /api/{entityType}/{id}/workflow/transition` (`api.{type}.workflow_transition`), body `{"transition": "<id>"}` plus the exact strong `If-Match` returned by a mutation-capable read → 200 `{"data": {"transition","from","to","public_changed"?}, "meta": {"mutation_token": "<successor>"}}` plus the successor `ETag`. `public_changed` is additive: the current framework controller always includes the boolean, but older packages or application-provided controllers may omit it. Clients must not treat a missing optional field as a failed transition.

**Controller**: `Waaseyaa\Api\Controller\WorkflowTransitionController` (deps: `EntityTypeManagerInterface`, `?EntityAccessHandler`, `TransitionService`), dispatched by `WorkflowTransitionApiRouter` (`DomainRouterInterface`, same shape as the other resolveOptional-gated admin routers).

**View access is enforced in the controller, not via a route `_gate` option** — a deliberate deviation from the original CW-v1 sketch: both endpoints apply the R8 oracle standard, so an entity the account cannot `view` (entity-level `isAllowed()`, deny on Neutral) returns the **same canonical 404 document, byte-identical**, as a missing id — one private factory serves both branches; a route-option gate's 403 would break that. Fails closed (404) when no `EntityAccessHandler` is wired: these are workflow-state-revealing surfaces, not generic reads.

The view gate includes the additive workflow-authority policy (#2081): an authenticated principal who has at least one currently outgoing transition after permission and group checks may reach the exact working copy. A permission for another state's transition, a failed/missing group constraint, an unbound entity, or an anonymous principal grants nothing. This changes no endpoint-specific authorization code; detail/list/edit-load and these endpoints consume the same entity-access policy. Field filtering remains separate, including the `meta.workflow_state` gate below.

**Tip-state semantics (CW-v1 option-1, #1920 PR-3):** the R8 view gate above stays pinned to the `find()`-loaded gate entity, byte-identical to WP-4's shipped shape. Once it passes, the WORKFLOW POSITION comes from `loadWorkingCopy()`, not the gate entity: `meta.workflow_state` (still subject to the field-level gate below), `TransitionService::getAvailableTransitions()`'s argument, and the POST's `transition()` target all resolve `$workingCopy = $repository->loadWorkingCopy((string) $entity->id()) ?? $entity` and use it in place of the gate entity. Under default-revision discipline the gate entity always reports the PUBLISHED pointer's state while a forward draft is in flight, so sourcing the position from it would report a stale state (and, for the GET, an empty or wrong transition list — see `WorkflowTransitionControllerWorkingCopyTest`). Passing the working copy explicitly to `TransitionService::transition()` also means the passed object's revision id trivially agrees with what the service's own internal `loadWorkingCopy()` resolves, avoiding `RevisionConflictException` for this first-party caller (the service still re-resolves and re-validates independently — see `docs/specs/content-workflow.md` "TransitionService" — so a genuine race between the controller's and the service's `loadWorkingCopy()` calls still 409s, see `WorkflowTransitionControllerRevisionConflictTest`). `loadWorkingCopy()` is mechanically safe on any entity/type — an undisciplined one (or a disciplined one with no draft) degrades to `find()`, so unbound/undrafted entities see no behavior change.

**Status code matrix**:

| Code | Condition |
|------|-----------|
| 200 | GET always (empty `data` for unbound types); POST when the transition applied |
| 400 | POST body invalid, or `If-Match` malformed, weak, wildcard, or a list |
| 401 | No `_account` on the request |
| 403 | `TransitionDeniedException` with `reason === 'permission'` |
| 404 | Unknown entity type, entity not found, or view access denied (byte-identical, R8) |
| 409 | Historical revision-head race between controller and transition service |
| 412 | Aggregate token is stale or bound to another identity |
| 422 | `TransitionDeniedException` with any other reason (`illegal_edge`, `unknown_transition`, `unbound`, `group_constraint`) |
| 428 | POST omitted the required aggregate `If-Match` precondition |

403/422 bodies carry the WP-2 contract: JSON:API error `code: 'WORKFLOW_TRANSITION_DENIED'`, `meta: {reason}` (same policy as `JsonApiController::workflowTransitionDeniedError()`, duplicated locally — that method stays private).

**Account semantics**: the controller passes `_account` explicitly to `TransitionService`; for HTTP requests the ambient `AccountContextInterface` the save/pointer guards re-gate against is already synced by `SessionMiddleware` (outermost scope, unconditional set), so no controller-side sync is needed — non-HTTP callers (CLI, queue, MCP) must sync it themselves (content-workflow.md "Caveat: ambient vs. explicit account").

**Field-level gate on `meta.workflow_state`** (PR #1956 reviewer follow-up): after the entity-level view check passes, `transitions()` additionally calls `EntityAccessHandler::checkFieldAccess($entity, 'workflow_state', 'view', $account)` and returns `meta.workflow_state: null` when it `isForbidden()` — `data` (the transition list) is unaffected, since it remains gated by the entity-level view check plus `TransitionService::getAvailableTransitions()`'s own permission/group filtering. Residual caveat: the current workflow state is still partially inferable from `data` itself — which transitions are offered narrows down which state(s) they can fire from — so this gate narrows but does not fully close the disclosure.

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

## Access Filtering

The storage query applies deny-by-default entity-level access before its
authorized range is sliced. `JsonApiController::index()` then repeats that
entity gate while applying the API-specific field-read gate:

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
- On the authenticated path the SQL query binds the request account via `setAccount($this->account)`, performs a deny-by-default per-row check, and applies page offset/limit to the authorized survivors. `accessCheck(false)` is used only on the system / no-account path.
- Entities for the authorized page are loaded, then re-filtered by view access in PHP with `isAllowed()` (a `Neutral` row is not visible), mirroring `show()`. The controller also excludes a row when the account may not read a field used to filter or sort it (R14).
- `meta.total` reflects the **access-filtered total of matching rows the current account may view ACROSS ALL PAGES** — computed via `accessFilteredTotal()` using the same entity and R14 field-read predicates as the per-page filter — **not** the size of the current page (audit C-26: the previous `$total = count($entities)` recount collapsed it to page size on the authenticated path). On a paginated collection `count($data) <= meta.limit` while `meta.total` may be larger, and `meta.total` is page-invariant for a fixed query + account.

<!-- Spec reviewed 2026-06-21 - issue #1702 (audit C-7): the GraphQL list resolver now matches the REST `meta.total` contract above. `GraphQL\Resolver\EntityResolver::resolveList()` previously took `total` from the then-open-by-default storage `COUNT` while `items` were deny-by-default via `GraphQlAccessGuard::canView()`. It now recomputes `total` across all matching rows with the same guard predicate. The historical query-layer characterization in this note was superseded by audit C-6 and #2541: storage is deny-by-default and ranged access-checked queries page authorized survivors. Acceptance: EntityResolverTest (`resolveListFiltersOutDeniedEntities`, `resolveListTotalReconcilesAcrossPagesWithAccessFilteredItems`). -->


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

## RFC 9727 API Catalog

`ApiServiceProvider` owns the optional `api.catalog` route at
`GET|HEAD /.well-known/api-catalog`. It is registered only when both conditions
hold:

1. `api_catalog.base_url` (falling back to `APP_URL`) is a canonical HTTPS URL;
2. at least one installed provider contributes an intentionally public endpoint
   through `ProvidesApiCatalogEntriesInterface`.

The catalog never derives an origin from `Host` or `X-Forwarded-Host`. An
explicit `api_catalog.enabled: true` without a base URL fails boot; an absent
setting and absent URL leaves the route absent. The response is an RFC 9264
JSON Linkset with `linkset` as its sole top-level member, deterministic
path-sorted `item` targets, and optional RFC 8631 `service-desc`, `service-doc`,
and `service-meta` contexts. Exact duplicate definitions collapse; two
different definitions for one endpoint fail boot. Contributor targets are
root-relative, same-origin paths with no fragments, foreign schemes, control
characters, or header-injectable media types.

`GET` returns
`application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"`.
`HEAD` returns no body, the representation headers and content length GET would
have returned, and the RFC 9727 `Link: <...>; rel="api-catalog"` discovery
relation. Unsupported `Accept` media ranges receive 406. Both methods are
anonymous and stateless, with a deterministic ETag, five-minute public cache
policy, `Vary: Accept`, and `X-Content-Type-Options: nosniff`. Matching weak or
strong `If-None-Match` validators receive a bodyless 304; a 406 names the one
available representation without disclosing application state.

The stock catalog is deliberately narrower than the set of public URLs. MCP
contributes `/mcp` and its server card only while the anonymous tier is enabled;
Wayfinding contributes its public JSON anchor catalog. `/mcp/write`, OAuth
metadata, admin APIs, authenticated OpenAPI/schema enumeration, sitemap.xml,
robots.txt, and llms.txt are not catalog items. Public content search joins this
catalog only when its separate access-checked endpoint ships.

## Experimental ARD AI Catalog

`ApiServiceProvider` separately owns `GET|HEAD
/.well-known/ai-catalog.json`. It is absent unless
`ai_catalog.enabled` is the PHP boolean `true`, a canonical HTTPS base URL is
available, and at least one installed provider contributes an intentionally
public artifact through `ProvidesAiCatalogEntriesInterface`. This explicit
opt-in is independent of RFC 9727 and does not affect API or MCP operation.
The route joins the anonymous session-stateless path set only under that same
strict enablement gate, so disabled or absent configuration creates neither a
route nor a hidden session-policy exception.

The emitted document uses `application/ai-catalog+json` and literal
`specVersion: "1.0"` as required by the schema shipped with the draft ARD v0.9
repository. Waaseyaa pins that schema to commit
`4a8a6b8fdd3ac4a50dcb63213573159c1eed7856` and validates representative output
against a checksum-pinned fixture in the test suite. ARD extends the separate
Linux Foundation AI Catalog draft with domain-anchored `urn:air` identifiers,
capabilities, and `representativeQueries`. Where the drafts differ, the pinned
ARD schema is the implemented compatibility target: `displayName` is always
emitted because ARD requires it, even though the base draft recommends omitting
it for some self-describing artifacts. This is an experimental compatibility
promise, not a claim of final or universal adoption.

Providers contribute deployment-neutral keys, public labels, media types,
same-origin artifact paths, and optional generic capabilities. Applications
alone may configure 2-5 public natural-language examples under
`ai_catalog.representative_queries[entry-key]`. An unknown key is a boot error,
so a typo cannot silently create misleading discovery metadata. Entries remain
valid when no representative-query overlay exists. Queries are public data and
must never contain private prompts, credentials, personal information, or
production records.

The initial closed set contains the RFC 9727 catalog when it actually exists
and the public MCP compatibility card when the anonymous MCP tier is enabled.
It never infers entries from the route collection and never advertises
`/mcp/write`, OAuth or approval surfaces, admin APIs, authenticated OpenAPI or
schema endpoints, internal filesystem paths, or tokens. Conflicting definitions
for one key fail boot; exact duplicates collapse; providers and entries are
sorted for stable bytes.

The response supports strict content negotiation, GET/HEAD parity, ETag/304,
five-minute public caching, `nosniff`, and public CORS without credentials. Its
response advertises the draft `ai-catalog` link relation. Canonical artifact and
Link URLs use only configured authority; hostile `Host` and forwarded-host
headers are ignored. Artifact contributions reject absolute, protocol-relative,
backslash, traversal, encoded-separator, query, fragment, and control-character
paths. Disabling the feature or removing every contribution withdraws both the
route and its response metadata.

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
| `csrfExempt()` | `_csrf = false` | Skip CSRF validation — route has its own auth model (MCP bearer, API keys) |
| `requireCsrf()` | `_csrf = true` | Force CSRF validation on state-changing methods even for the JSON content types that are exempt by default (cookie-authenticated JSON endpoints, e.g. MCP write-tier approvals). See `docs/specs/security-defaults.md` "CSRF token cookie" |
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

Reads the route `parameters` option for entries with `type => 'entity:{entityTypeId}'`. `HttpKernel` invokes the converter immediately after route matching, before request attributes and controller dispatch. It loads the entity from storage and replaces the raw ID in the parameter array; a missing entity becomes the normal HTTP 404 response.

### JsonApiRouteProvider

```php
// packages/api/src/JsonApiRouteProvider.php
final class JsonApiRouteProvider
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
    ) {}

    public function registerRoutes(WaaseyaaRouter $router): void;
}
```

Registers a single public discovery route plus generic routes only for entity
types that deliberately opt in through `ApiExposableEntityTypeInterface` and
remain enabled by the boot-scoped `EntityTypeApiExposurePolicy`. `EntityType`
sources the capability ceiling from `api: true` on `#[ContentEntityType]` or the
imperative constructor; the default is false. If `api.entity_type_allowlist` is
absent, behavior is unchanged. If present (including `[]`), it is a closed-world
exact-id list and the effective predicate is `registered && declared api:true &&
allowlisted`. It may narrow but never elevate package/app metadata.

The allowlist is validated after provider/app entity registration and before
HTTP serving. It must be a duplicate-free list of non-empty registered ids whose
canonical declaration is `api: true`; unknown, malformed, duplicate, stale, or
declared-false entries abort boot with one bounded id/reason diagnostic. This is
intentionally per install shape: removing an optional package requires removing
its ids from that deployment's list. Reusing a full-install config unchanged on
a minimal install is expected to fail.

Effectively unexposed types receive no CRUD, field auto-save, translation, or
workflow controller routes. Anonymous and authenticated API callers receive the
ordinary not-found response byte-identically to an unregistered type; no
exposure-specific code or package-registration fact appears on the generic API.
Operator visibility belongs to the admin-only entity-type catalogue. Discovery,
entity schema, and OpenAPI apply the same predicate. The discovery envelope
remains registered even when the effective exposed set is empty.

Filter, sort, and include validation runs before storage. Dotted relationship
paths are currently unsupported and rejected generically; include paths are
resolved segment-by-segment and a path reaching an unknown or effectively
unexposed target is byte-indistinguishable from an unknown relationship path on
both collection and single-resource reads. Before reading any field value, the
resource serializer omits entity-reference fields whose target type is not
effectively exposed; this prevents an attribute, linkage object, type, id, link,
count, or existence flag from disclosing the suppressed target. Entity schema
likewise omits `x-target-type` for such reference edges.
The adapter currently ships no related-resource or relationship-linkage route,
so both remain ordinary route-not-found surfaces and cannot hydrate linkage.
If those route families or compound serialization are added later, every target
edge must reapply the same effective policy before load and serialization.
OpenAPI starts only from effective roots and emits no paths or components for a
suppressed target.

Route construction may reuse a bounded process-lifetime structural template.
The key contains the configured base path, the sorted exact map of entity type
ids to API-exposure decisions, and whether base or workflow routes were
requested. A changed id, exposure decision, base path, or route family is a
cache miss. The cache retains at most two template sets and every registration
clones each `Route` into a fresh `WaaseyaaRouter` and fresh mutable route
collection, so one kernel cannot mutate another kernel's routes. Templates may
contain controller strings and opaque not-found closures, but the closures
capture no state and templates contain no request, account, entity,
authorization decision, provider/service instance, runtime-bound controller,
router, matcher, generator, or mutable route collection. Route access options are cloned
unchanged, so template reuse does not weaken route authorization or turn a
missing/changed structural key into a permissive fallback.

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
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
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
listed(type, account) = effectivelyApiExposed(type)
                      ∧ isDiscoverableDuckTyped(type)   // false → hidden from EVERYONE, admin included
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
- For an **authenticated** caller, the entry set in `links` (excluding `self`) is exactly the set of effectively exposed, registered *discoverable* entity type ids — no more, no less.
- For an **anonymous** caller, `links` collapses to `['self' => $basePath]` regardless of registered types.
- When zero entity types are registered, `links` collapses to `['self' => $basePath]` for every caller.
- The route shape (`_public`, path, methods) is unchanged.

The route is dispatched by `JsonApiRouteProvider`'s `api.discovery` registration. At runtime, `DiscoveryRouter` (the `HttpDomainRouter` registered through `ApiServiceProvider::httpDomainRouters()`) recognises the controller string `Waaseyaa\Api\ApiDiscoveryController::discover` via `str_contains($controller, 'ApiDiscoveryController')`, instantiates the controller with the booted `EntityTypeManager` **and the request account** — `WaaseyaaContext::fromRequest($request)->account`, i.e. the `_account` attribute set by `SessionMiddleware`; no other account source is consulted — and wraps the discover payload in a `jsonapi.version` envelope before returning a JSON:API response.

#### Schema self-description surface requires authentication

`GET /api/openapi.json` and `GET /api/schema/{entity_type}` **require authentication** (`_authenticated`), so `AccessChecker` returns `unauthenticated` and `AuthorizationMiddleware` 401s an anonymous caller. `/api/openapi.json` is registered by foundation's `BuiltinRouteRegistrar`; `/api/schema/{entity_type}` is registered by `ApiServiceProvider::routes()` (moved in WP5). They are also constrained by the effective exposure policy: OpenAPI omits suppressed roots/components and schema treats a suppressed type exactly like an unregistered type. They are the self-description of an API whose data routes are already auth-gated, and (per the field-access caveat below) they over-disclosed instance-state-gated field *definitions* to anonymous; gating them closes that for unauthenticated callers and is consistent with #1649's auth-gating of the `GET /api` discovery index. Pinned by `tests/Integration/SchemaSurfaceRequiresAuthTest` and the #2115 exposure-policy tests.

`/api/entity-types` is an operator catalogue and requires the `admin` role. It is not an anonymous package-enumeration surface; suppressed and unregistered type probes remain indistinguishable to unauthenticated callers. Operator diagnostics and the complete installed-type catalogue belong on this admin surface, not in anonymous JSON:API responses.

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

Translation creation, update, and deletion mutate the existing aggregate and
therefore require the same strong aggregate `If-Match` precondition as other
existing-resource mutations. Authorized mutation-capable reads expose the
opaque token in resource metadata and `ETag`; view-only callers receive neither.
Missing, malformed, and stale preconditions return 428, 400, and 412
respectively without changing the entity. Each successful translation mutation
returns the successor aggregate token and `ETag`.

### Error Handling Pattern

Unhandled exceptions caught by `ControllerDispatcher` produce a JSON:API 500 with the fixed detail `An unexpected error occurred.` This response shape is environment-independent: `APP_DEBUG`/`WAASEYAA_DEBUG` must not add the exception class, exception message, filesystem path or line, or stack frames to an API response. The dispatcher logs the complete exception and trace server-side before returning the generic document. Rich debug HTML is owned by the separate error-page renderer and does not authorize response-body trace disclosure on JSON:API routes.

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
| `isDiscoveryEntityPublic(EntityInterface $entity, ?AccountInterface $account = null): bool` | Served-projection check via `WorkflowVisibility::isEntityServedPublicForEntity()`, AND (when `$account` is given and the handler is wired) `EntityAccessHandler::check($entity, 'view', $account)->isAllowed()`. The workflow-state id is not consulted. |
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
    Controller/
      BroadcastStorage.php
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
| `allowed_directories` | Path prefixes whose internal infrastructure is intentionally Symfony-coupled. Currently: `packages/foundation/`, `packages/routing/`, `packages/api/`, `packages/validation/`, `packages/cli/`, `packages/frankenphp/`. Tests are implicitly excluded — the gate only walks `packages/*/src/`, never `packages/*/tests/`. |
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
2. Reference the class by its inline leading-backslash FQCN at the callsite instead of importing it — e.g. `\Symfony\Component\Uid\Uuid::v4()->toRfc4122()`. The gate matches `^\s*use\s+Symfony\\`, so a single-callsite dependency on a leaf utility does not need an allowlist entry at all. This is the established form in `packages/audit` (`AuditEventWriter`, `AuditCheckpointBuilder`) and, since #2492, in `packages/oidc` and `packages/ai-agent` too. Prefer it over widening the allowlist when the coupling is one expression deep; it keeps the file's `use` block free of Symfony and leaves the dependency visible at the point of use.
3. Add the file path to `legacy_files` in the JSON, with the rationale captured in the PR description.
4. If a whole new directory should be allowed, add it to `allowed_directories` — but this should be rare and warrants discussion (every entry weakens the gate).

Note that the gate is an **import**-boundary gate, not a dependency gate: option 2 satisfies it without reducing the runtime coupling. The package's `composer.json` must still declare the Symfony package it calls — see `packages/oidc` and `packages/audit`, which both gained `symfony/uid` in #2492.

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
