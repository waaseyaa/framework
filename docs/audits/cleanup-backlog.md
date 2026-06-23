# Cleanup Backlog (living document)

A running log of cleanup-worthy findings encountered during normal work: dead code,
old/superseded code, unfinished or built-but-unexposed code, duplicated effort, stale
docs/specs, and bad architecture. **Not** a point-in-time audit (see `AUDIT.md` and the
dated `docs/audits/*.md` for those). Append as you find things; each entry is grounded at
`file:line` and states the fix + a rough risk. Tick items off or move them into a mission
when actioned.

> Convention: `CL-N` ids are stable. Don't renumber; mark `DONE`/`WONTFIX` in place.

---

## Open

### CL-1 — MCP: delete the legacy pre-M3 dispatch path (dead/duplicated)
**Found:** 2026-06-19 (Wayfinding Phase 5 MCP investigation).
`packages/mcp/src/McpController.php` + `packages/mcp/src/Tools/*` (`DiscoveryTools`,
`EntityTools`, `TraversalTools`, `EditorialTools`) + `packages/mcp/src/Cache/*` +
`packages/mcp/src/Rpc/*` are the **pre-M3** tool-class architecture. They are **no longer
reachable from HTTP routing** (the foundation `McpRouter` was retired in M3 WP01; the live
route is `McpRouteProvider` → `McpEndpoint::serve` → `AgentToolRegistryBridge` → ai-tools
`#[AsAgentTool]` registry). They survive only via direct instantiation in
`tests/Integration/Phase14/AiMcpIntegrationTest.php`. Self-documented as deletable in
`packages/mcp/README.md:88-96` ("A future cleanup mission may delete them.").
**Fix:** delete the pre-M3 files + the test that pins them, after confirming the live M3
`#[AsAgentTool]` path covers the equivalent read tools. **Risk:** low (already unrouted).

### CL-2 — MCP: stale docs claim `BearerTokenAuth`/authenticated-only default (wrong)
**Found:** 2026-06-19.
`packages/mcp/README.md:21-25,72-74` and `docs/specs/mcp-endpoint.md` describe the
`McpAuthInterface` default as `BearerTokenAuth(tokens: [])` / "Authenticated only — 401 if
null". The **live** binding is `PublicAnonymousAuth()` (public read-only, never 401) —
`packages/mcp/src/McpServiceProvider.php:41-48`. The docs contradict the code (and the
README's own "Authentication" line contradicts the actual three-layer public read-only
boundary). **Fix:** update README + `mcp-endpoint.md` to the live `PublicAnonymousAuth`
default and the structural/capability/per-tool read-only boundary. **Risk:** docs only.

### CL-3 — MCP: destructive tools are built but unreachable (no live write surface)
**Found:** 2026-06-19.
The only live MCP endpoint (`/mcp`) is hardwired to `ReadOnlyToolRegistry`, which hides all
`destructive: true` tools. Combined with CL-1 (the McpController write path is unrouted),
**no `destructive` MCP tool is reachable on any live surface today** — e.g. the editorial
write tools (`editorial_transition`/`publish`/`archive`) are dead-on-arrival. Wayfinding
Phase 5 introduces the *first* authenticated write tier, but only surfaces wayfinding tools;
the editorial write tools remain unexposed. **Fix (separate mission):** once the Phase-5
authenticated write tier exists, either surface the editorial write tools through it or
delete them. **Risk:** medium (decide expose-vs-remove deliberately).

### CL-4 — entity: `EntityRepositoryInterface` exposes the two-axis API only partially
**Found:** 2026-06-19 (Wayfinding Phase 4).
`packages/entity/src/Repository/EntityRepositoryInterface.php` declares `saveTranslation`,
`loadTranslation`, `listTranslationRevisions` — but **omits** `saveTranslationRevision`,
`loadTranslationTip`, `loadTranslationRevision`, `translationLangcodes` (all present on the
concrete `packages/entity-storage/src/EntityRepository.php`). Consumers that need the full
per-language revision API (e.g. wayfinding's `TrailStore`, for the draft-revision path) must
therefore depend on the **concrete** `EntityRepository`, an L4→L1 concrete coupling instead
of the interface. **Fix:** complete the two-axis surface on `EntityRepositoryInterface` so
the per-language revision API is fully abstracted; then relax `TrailStore` to the interface.
**Risk:** low-moderate (interface broadening on a core L1 type; touches the revision spec).

### CL-5 — wayfinding: beacon-emit logic duplicated (controller vs MCP tool)
**Found:** 2026-06-19 (Wayfinding Phase 5). The beacon validate-and-publish logic (anchor
validity via `AnchorRegistry::isValid`, content length cap, build `{anchor_id, content,
order, emitted_by}`, `BroadcastStorage::push(SessionChannel::forToken(...), 'wayfinding.beacon', …)`)
now lives in BOTH `packages/wayfinding/src/Http/EmitBeaconController.php` (Phase 2) and
`packages/ai-agent/src/Tool/Wayfinding/EmitBeaconTool.php` (Phase 5). Deliberately not
DRY'd in Phase 5 to avoid refactoring the working, tested Phase-2 controller mid-phase.
**Fix:** extract a `Waaseyaa\Wayfinding\Beacon\BeaconEmitter` (constructor `AnchorRegistry`;
`emit(BroadcastStorage, sessionToken, anchorId, content, order, emittedBy)`) and have both
the controller and the tool delegate to it, preserving the controller's exact 403/422/202
responses. **Risk:** low (behavior-preserving extraction + re-run the Phase-2 controller test).

### CL-6 — auth: `AuthTokenRepository::ensureSchema()` is not idempotent (boot 500)
**Found:** 2026-06-20 (alpha.233 wayfinding hands-on, my-app). `AuthServiceProvider::register()`
resolves `AuthTokenRepository->ensureSchema()` → `DBALSchema->createTable()` with a plain
`CREATE TABLE auth_tokens` (no `IF NOT EXISTS` / existence guard). Under classic FrankenPHP
`php-server` (per-request boot — the documented dev serve mode), two near-simultaneous
first-boots raced to create the table and one threw `table auth_tokens already exists`,
500ing `/api/broadcast`. It self-heals once the table exists, but the create path is racy /
non-idempotent. **Fix:** make `ensureSchema()` idempotent (create-if-not-exists, or catch
"already exists"). **Risk:** low. Source: `packages/auth/src/Token/AuthTokenRepository.php:29`,
`AuthServiceProvider.php:32`.
**DONE (alpha.238):** `ensureSchema()` now tolerates the concurrent create — existence guard
+ catch the race-loser's "already exists" (rethrow only if the table is still absent on a
fresh re-check). Reproduced on .237 (30 parallel cold `/api/broadcast` → `auth_tokens already
exists` in the log) and verified gone after the fix; the other three boot-ish `ensureSchema`
(audit / ai-vector / search) already used `CREATE … IF NOT EXISTS`, so were race-safe.
Acceptance: `AuthTokenRepositoryTest`. **Follow-up (CL-11) still open:** the DDL still runs on
every request.

### CL-11 — auth: schema DDL runs on the request hot path (design smell)
**Found:** 2026-06-20 (CL-6 fix). `AuthServiceProvider::register()` resolves
`AuthTokenRepository::ensureSchema()` during route registration, so a `tableExists()` probe
(and, on a cold DB, a `CREATE TABLE`) runs on **every** HTTP request under classic FrankenPHP
per-request boot. The CL-6 fix makes it race-safe, but the bootstrap still belongs in
`db:init`/`migrate`, not the request path. **Fix:** move `auth_tokens` provisioning into the
install/migrate path (like other tables) and drop the per-request `ensureSchema()` resolve
from `AuthServiceProvider::register()`. **Risk:** low-moderate (must guarantee the table is
provisioned before first auth use). Source: `packages/auth/src/AuthServiceProvider.php:32`.

### CL-7 — cli: `migrate:defaults` is not container-auto-wirable (latent, like the alpha.236 migrate bug)
**Found:** 2026-06-20 (alpha.236 migrate-command fix). `migrate`/`migrate:rollback`/
`migrate:status` were fixed (bound explicitly in `MigrateServiceProvider`), but
`migrate:defaults` is still wired as `[MigrateDefaultsHandler::class, 'execute']` and its
ctor takes a non-auto-wirable `string $projectRoot` (+ `EntityTypeLifecycleManager`,
`?EntityAuditLogger`) — so a real invocation fails the same way (`unresolvable parameter`).
Not exercised by the skeleton smoke. **Fix:** bind `MigrateDefaultsHandler` explicitly in
`MigrateServiceProvider` (resolve the entity-lifecycle deps + pass projectRoot), mirroring
the migrate handlers. **Risk:** low. Source: `packages/cli/src/Handler/MigrateDefaultsHandler.php`,
`packages/cli/src/Provider/MigrateServiceProvider.php`.

### CL-8 — wayfinding: the human-owned trail latch has no app surface (SC-005 unreachable)
**Found:** 2026-06-20 (alpha.233 wayfinding hands-on). The no-silent-overwrite guarantee
rides on `TrailStore::editAsHuman()` setting `origin = human`, but that method has NO MCP,
HTTP, admin, or CLI surface. The write tier exposes record / re-record / get / emit only —
an app/agent can never make a trail human-owned, so the "human edits preserved on re-record"
branch (SC-005) is reachable **only** from `TrailStoreTest`, not from a running app. **Fix:**
expose an authenticated human-edit path (e.g. a `wayfinding_edit_trail` write tool or an
admin trail editor that routes through `editAsHuman`). **Risk:** medium (feature gap — the
flagship's headline "human edits are never overwritten" can't actually be demonstrated end
to end). Source: `packages/wayfinding/src/Trail/TrailStore.php`.
**DONE (alpha.245, #1705):** shipped `wayfinding_edit_trail` — a fifth `#[AsAgentTool]`
adapter (`packages/ai-agent/src/Tool/Wayfinding/EditTrailTool.php`, `destructive: true`,
`present guided content`) routing to `TrailStore::editAsHuman()` on the authenticated
`/mcp/write` tier. `WayfindingTrailToolsTest::editing_as_human_via_the_tool_latches_origin_and_survives_rerecord`
now drives SC-005 end-to-end through tools only (human edit → re-record lands a draft →
live value intact), replacing the `new TrailStore(...)->editAsHuman()` back-channel.

### CL-9 — admin: `enableRealtime` is build-baked, not serve-time configurable
**Found:** 2026-06-20 (alpha.233 wayfinding hands-on). The prebuilt admin SPA bakes
`config.public.enableRealtime` from `NODE_ENV` at `nuxt generate` time
(`packages/admin/nuxt.config.ts:54`). A consumer serving the committed
`packages/admin-surface/dist` bundle cannot toggle realtime per-deploy without rebuilding
the SPA — so the live beacon overlay / SSE auto-connect is on-or-off by build, not by app
config. **Fix:** inject `enableRealtime` at serve time (e.g. the admin-surface host writes a
runtime-config `<script>` into the served index.html from a waaseyaa config key), so apps
flip it without a rebuild. **Risk:** low-moderate. Source: `packages/admin/nuxt.config.ts`,
`packages/admin-surface/src/AdminSurfaceServiceProvider.php`.

### CL-10 — release infra: `split.yml` git push has no retry → transient auth half-publishes a release
**Found:** 2026-06-20 (alpha.236 release). The per-package `split (packages/<pkg>, <remote>)`
matrix job pushes each tag to its split repo with no retry. A transient
`Authentication failed for <pkg>.git` on ONE job (error-handler, alpha.236) left the tag
unpushed → not crawled to Packagist → `waaseyaa/framework@alpha.236` was briefly
uninstallable until the failed job was re-run. **Fix:** wrap the split git push in a bounded
retry (idempotent on an already-present tag). **Risk:** moderate (a single flaky push can
ship a broken/uninstallable release; the skeleton smoke now catches it but only after the
fact). Tracked: spawned background task. Source: `.github/workflows/split.yml`.

### CL-12 — api: `MercureMonitorController::events()` SSE loop is unbounded (worker-pin risk)
**Found:** 2026-06-20 (Failure B broadcast SSE teardown). `events()` streams with
`while (connection_aborted() === 0)` and **no time-budget cap** at
`packages/api/src/Controller/MercureMonitorController.php:110` — unlike `BroadcastRouter`,
which bounds its loop with a 30s `streamShouldContinue()` backstop. Keepalive is every 15s
(line 145) and `ignore_user_abort()` is never cleared, so the `/api/mercure/events` admin
monitor stream is susceptible to the *same* missed-disconnect worker pinning that Failure B
fixed in `BroadcastRouter` — and worse, with no durable backstop a binary that never flips
`connection_aborted()` pins the worker indefinitely. **Fix:** port the Failure B teardown to
this loop — clear `ignore_user_abort()` at stream start, re-probe the abort state after each
write, and add a bounded time-budget cap mirroring `BroadcastRouter::streamShouldContinue()`.
**Risk:** low (admin-only debug endpoint, gated by the monitor role) but unbounded. Source:
`packages/api/src/Controller/MercureMonitorController.php:80-155`.

### CL-13 — media/attachment: file BYTES have no authorized download path (SCOPED, flagged before diving)
**Found:** 2026-06-23 (Track A grounding). **Entity-row access policies protect the record,
not the bytes.** `MediaAccessPolicy` and attachment's `ParentDelegatedAccessPolicy` gate the
entity row, but the file contents are not access-checked on any serving path. Grounding the
"add an authorized download + private scheme" fix revealed it is **larger than a contained
change** — three structural gaps, none a clean drop-in:

1. **The framework ships NO byte-serving at all.** Uploads write bytes to
   `MediaRouter::resolveFilesRootDir()` = `config['files_root']` or `<projectRoot>/storage/files`
   (`packages/media/src/Http/Router/MediaRouter.php:147,95`), already **outside** the web root,
   and return a `/files/<name>` URL (`:221-234`). But nothing in the framework or the skeleton
   serves `/files/` — `skeleton/public/` contains only `index.php` (no symlink, no Caddyfile, no
   route, no static handler; repo-wide grep finds no `/files` route). So the `public://` scheme +
   `/files/` URL is a **convention a consuming app must wire** (e.g. symlink `public/files →
   storage/files`), which is precisely what would make bytes world-readable. There is no existing
   public-byte-serving in-framework to migrate — but also no safe authorized path to serve from.
2. **No file→entity→policy linkage.** The upload endpoint creates a `File` metadata sidecar
   (`LocalFileRepository`, which stores only `.meta.json`, not bytes) carrying `ownerId` = the
   *uploader user id* — NOT a link to the `media`/`attachment` ENTITY whose `AccessPolicy` would
   gate it. The `media` entity is a generic `ContentEntityBase` with no explicit file-uri field;
   attachment stores `storage_uri` in its `_data` blob. So "serve bytes under the SAME policy
   gating the owning entity" cannot be done without first **modeling the ownership link** (which
   entity owns this file) and a per-entity-type file-uri resolution (media field vs attachment
   `_data`).
3. **No public-vs-private classification.** Every upload is `public://`; there is no `private://`
   notion, no per-upload private flag, and no policy deciding which assets (avatars vs. sensitive
   docs) are public.

**Proposed approach (deliberate, not yet implemented):** (a) add a `private://` scheme that
`LocalFileRepository`/`MediaRouter` route to a `storage/files/private/` tree never exposed under
the public `/files/` prefix; (b) add an **entity-keyed** authorized download controller
(`GET /media/{id}/download`, `GET /attachment/{id}/download`) that loads the entity, runs
`EntityAccessHandler::check($entity, 'view', $account)->isAllowed()` (deny-by-default,
fail-closed, 404-on-deny to avoid an existence oracle — mirroring `JsonApiController`), resolves
the entity's file uri, and streams bytes via `StreamedResponse`; (c) let uploads opt into
`private://`; (d) leave legitimately-public assets (avatars) on `public://`, unaffected.
**Tradeoffs / decisions needed before diving:** the file↔entity ownership model (today `File`
is decoupled from the entity, and `File.ownerId` is the uploader, a weaker notion than the
entity's view policy); whether to cover `attachment` (different storage shape) in the same pass;
whether to keep the `public://`+`/files/` convention or document it as "host must serve, and must
NOT serve `private://`". **No existing-URL breakage / no storage migration** for current public
files (they're already outside the web root and unserved in-framework), so the change is additive
— but it is **new capability spanning an ownership model + two entity types + a serve controller**,
not a one-route gate. **Risk:** medium (new authorized-download surface; getting the deny-by-default
+ existence-oracle semantics right; per-entity-type file resolution). Source:
`packages/media/src/Http/Router/MediaRouter.php`, `packages/media/src/LocalFileRepository.php`,
`packages/attachment/src/Schema/AttachmentSchema.php`. **Note:** not a claim-vs-code defect — no
README/spec promises gated downloads — so this is a latent design gap, not a false guarantee.
