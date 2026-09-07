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

### CL-14 — audit: `entity.write` emits a `dirty_fields` key that is structurally always `[]`
**Found:** 2026-09-06 (adapter/duck-typing sweep). **HEAD:** `bb5c0d8b72d09da276dbd7b9080bb0b10e140596`.
`packages/audit/src/Listener/EntityLifecycleAuditListener.php:102` writes
`'dirty_fields' => method_exists($entity, 'getDirty') ? $entity->getDirty() : []`.
**Evidence (all at this HEAD):** `grep -rn 'function getDirty(' packages/*/src` → **0 definitions**;
`grep -rn 'dirty' packages/entity/src packages/entity-storage/src` → **0 matches** (the entity system
has no change-tracking concept at all); `grep -rn dirty_fields packages tests` → **1 match, the emitting
line itself** (no test asserts it); `grep -rn getDirty docs/specs docs/adr` → **0** (undocumented, so no
consumer could implement it deliberately); and 0 definitions in the four consumer checkouts on this box
(`minoo`, `claudriel`, `rhtcircle`, `anokii`). `docs/specs/ocap-audit-log.md:144` types `attributes` as
"Freeform metadata per event kind" and never names `dirty_fields`, so **nothing is contractually
promised** — this is a silently-empty key, not a broken contract.
**Impact:** every `entity.write` audit row carries `dirty_fields: []`. A reader of the OCAP audit log
sees a populated-looking field and would reasonably read "no fields changed" on every update. Governance
surface, so the wrong reading is the costly one. No runtime error, no performance cost.
**Smallest coherent repair:** the material for a real value is already in hand — `EntityEvent` carries
`public readonly ?EntityInterface $originalEntity` (`packages/entity/src/Event/EntityEvent.php:28`), and
`EntityRepository::doSave()` loads it unconditionally for updates (`:991-994`) and passes it to the
POST_SAVE event (`:1406`), so computing changed keys costs **zero added queries**. Either (a) compute
`array_keys` of the diff between `$event->entity->toArray()` and `$event->originalEntity?->toArray()`,
or (b) drop the key. Prefer (a); (b) is the fallback if diffing raises a redaction question.
**Acceptance:** a unit test in `packages/audit/tests/Unit/Listener/EntityLifecycleAuditListenerTest.php`
asserting (1) update-with-changes lists exactly the changed keys, (2) create records `[]` or omits the
key, (3) update-with-no-change records `[]`, (4) the value never contains field *values*, only names
(field-access boundary — an audit row must not become a read oracle for a field the reader cannot see).
**Contract/packaging risk:** low. `attributes` is freeform; the listener is `@internal`-shaped and not in
`packages/audit/public-surface.php`. Option (b) removes an emitted key — undocumented, but a downstream
log consumer could still be keying on its presence, so (a) is the safer default.
**Overlap:** none. No open issue matches (`gh search issues --repo waaseyaa/framework dirty_fields` → 0).
No active lease touches `packages/audit/`.
**Confidence:** high that the branch is dead in every known deployment; **medium** that it is dead
universally — the framework is on Packagist and an unknown consumer entity could define `getDirty()`.
**Open question:** should the diff walk `_data` blob sub-keys or only top-level columns? **Risk:** low.

### CL-15 — ai-tools: three `getValues()` duck-type branches are unreachable, and the comment claims the opposite
**Found:** 2026-09-06. **HEAD:** `bb5c0d8b72d09da276dbd7b9080bb0b10e140596`.
`packages/ai-tools/src/Entity/EntitySearchTool.php:148`, `packages/ai-tools/src/Entity/EntityReadTool.php:162`,
`packages/ai-tools/src/Relationship/RelationshipTraverseTool.php:195` each guard on
`method_exists($entity, 'getValues')`. Same evidence as CL-14: **0 definitions** of `getValues()` anywhere
in `packages/*/src`, in `tests/`, in the specs, or in the four local consumer checkouts.
`EntitySearchTool.php:143-145` carries the comment "Use a curated `getValues()` when present, else the
guaranteed `toArray()`, so search works for every entity type, **not only those defining `getValues()`**" —
which asserts a two-population world that does not exist.
**Impact:** none at runtime — every call falls through to `toArray()`, which is the correct behaviour. The
cost is comprehension (a reader believes a curated projection path exists) and analysis: an unresolved
`getValues` call marks every same-named member as used by the dead-code detector.
**Smallest coherent repair:** delete the three branches, call `toArray()` directly, correct the comment.
Do **not** widen any interface and do **not** touch the `method_exists` sites that specs justify (below).
**Acceptance:** the three tools return identical payloads before/after for a revisionable entity, a
non-revisionable entity, and a translatable entity; existing ai-tools unit tests stay green with no
assertion edits; the field-access filtering that follows the value read in `EntitySearchTool` is untouched.
**Contract/packaging risk:** low — internal tool bodies; none of the three are in
`packages/ai-tools/public-surface.php`. It removes an *undocumented* extension seam, so if the intent was
"consumers may supply a curated projection", the correct repair is instead to define that seam as a real
interface — that is a design decision, not cleanup, and should not be taken inside this entry.
**Overlap:** adjacent to open **#1606** (`ai-vector` non-turnkey / `vector.search` unwirable), which covers
the sibling `method_exists($provider,'embed')` duck-check at `packages/ai-tools/src/Vector/VectorSearchTool.php:88`.
Leave the vector sites to #1606; this entry is the three entity sites only. No active lease touches `packages/ai-tools/`.
**Confidence:** high. **Risk:** low.

### CL-16 — database-legacy: the published Packagist description still says "Drupal DBAL"
**Found:** 2026-09-06. **HEAD:** `bb5c0d8b72d09da276dbd7b9080bb0b10e140596`.
`packages/database-legacy/composer.json` `description` reads
*"Database adapter wrapping Drupal DBAL. Interim until Doctrine migration."* The package requires
`doctrine/dbal ^4.0`, `grep -rli drupal packages/database-legacy/src` returns **nothing**, and the
package's own `README.md:5` already says the correct thing: *"wrapping Doctrine DBAL"*.
**Impact:** the `description` field is what packagist.org renders for `waaseyaa/database-legacy`, so the
one place a prospective consumer looks first states that the package wraps a competitor's database layer
and is pending replacement. 39 of 77 first-party packages depend on it. ADR-007 already ruled the
`-legacy` *name* historical-not-deprecated; the description contradicts that ruling in public.
**Smallest coherent repair:** one line — align `description` with `README.md:5` and ADR-007's wording.
**Acceptance:** `php bin/check-composer-policy` green; `composer validate` green for that package;
`grep -i drupal packages/database-legacy/` returns nothing; description matches the README sentence.
**Contract/packaging risk:** low, but non-zero — it is a published manifest that `split.yml` mirrors, so
it lands on Packagist at the next release cut. No code, constraint, or autoload change.
**Overlap:** ADR-007 (`docs/adr/007-database-legacy-package-naming.md`) decides the name and should be
cited, not reopened. `docs/audits/2026-05-database-legacy-usage.md` is the usage inventory. No open issue.
**Confidence:** high. **Risk:** low.

### CL-17 — tooling: the warn-only composer-dependency audit has drifted and nobody consumes its output
**Found:** 2026-09-06. **HEAD:** `bb5c0d8b72d09da276dbd7b9080bb0b10e140596`.
`bin/audit-composer-deps` runs `shipmonk/composer-dependency-analyser` against
`composer-dependency-analyser.php`; it is wired into `.github/workflows/ci.yml:201` and always exits 0
(warn-only by design — mirrors `bin/audit-dead-code`).
**Evidence:** running it at this HEAD reports 1 unused dependency (`waaseyaa/telescope`), 6 root
dependencies flagged as belonging in `require-dev` (`ext-pdo_sqlite`, `ext-sqlite3`, `waaseyaa/ai-schema`,
`waaseyaa/analytics`, `waaseyaa/deployer`, `waaseyaa/github`), **and 11 configured ignores that "never
occurred"** — including ignores for `waaseyaa/analytics`, `waaseyaa/deployer`, `waaseyaa/engagement`,
`waaseyaa/github`, `symfony/dependency-injection`, `symfony/dotenv`, and an `Unknown class` ignore for
`Waaseyaa\Entity\Storage\SqlEntityStorage` (a class that no longer exists).
**Impact:** developer-facing only. The config now suppresses conditions that stopped occurring while the
live findings scroll past in a green job, so the signal is unread in both directions.
**Smallest coherent repair:** delete the 11 stale ignore entries from `composer-dependency-analyser.php`
(they are provably inert — the tool itself reports they never applied), then triage the live findings in
a *separate* pass. Do **not** move root requires or drop `waaseyaa/telescope` in the same change: the
analyser scans only `packages/*/src` and `tests/`, so a leaf package with no first-party importer is
expected to read as "unused" and that verdict needs confirming against the metapackage graph first.
**Acceptance:** `bash bin/audit-composer-deps` reports zero "ignored issues never occurred" lines; the
live finding set is byte-identical before and after; the job stays warn-only and stays out of
`composer verify`.
**Contract/packaging risk:** none for the ignore-list cleanup (dev tooling config, not shipped).
Deferred `telescope`/require-dev work does carry packaging risk and is deliberately not in scope here.
**Overlap:** none open. **Confidence:** high for the stale ignores; low for the "unused dependency"
verdict, which is why it is split out. **Risk:** low.

### CL-18 — WONTFIX (evidence recorded): enabling `usageOverMixed` on the dead-code gate is not viable
**Found:** 2026-09-06. **HEAD:** `bb5c0d8b72d09da276dbd7b9080bb0b10e140596`.
Recorded so the idea is not re-proposed from the README alone. `shipmonk/dead-code-detector` suppresses
dead-code findings for any call it cannot resolve to a class
(`vendor/shipmonk/dead-code-detector/src/Excluder/MixedUsageExcluder.php`: `shouldExclude()` returns true
when `getMemberRef()->getClassName() === null`). Turning the excluder **on** therefore *removes* that
blanket suppression and *increases* findings — it does not hide evidence.
**Measured at this HEAD** (isolated config in a scratch dir; `phpstan-dead-code.neon` and
`phpstan-dead-code-baseline.neon` were not modified):
control `phpstan analyse -c phpstan-dead-code.neon` → **`[OK] No errors`**;
experiment, same config plus `shipmonkDeadCode.usageExcluders.usageOverMixed.enabled: true` →
**`[ERROR] Found 59 errors`**.
**Classification of all 59:** 41 are `__construct` — dominated by attribute classes instantiated by the
PHP engine (`Foundation\Attribute\AsEntityType`, `AsFieldType`, `Queue\Attribute\OnQueue`, `RateLimited`,
`UniqueJob`, `SSR\Attribute\Component`, `FromRoute`, `Field\Attribute\BundleTemplate`) and
container-resolved listeners/services (`Cache\Listener\EntityCacheInvalidator`,
`Config\Listener\ConfigCacheInvalidator`, `Workflows\DomainValidationListener`); 4 are properties on
those same attribute classes, read reflectively (`AsEntityType::$id/$label`, `AsFieldType::$id/$label`);
2 are interface contract methods (`SSR\ThemeInterface::id`, `Ingestion\PayloadValidatorInterface::validate`);
the remaining 12 are polymorphic implementations of those contracts (four `EnvelopeValidator::validate`
variants) plus public test surface (`Testing\Traits\InteractsWithApi::get`). **No true positive was
identified in the set.**
**Conclusion:** the setting is a one-off measure of the framework's reflective/dynamic surface, not a
source of actionable findings. Enabling it on the blocking gate would inject 59 false positives, and
suppressing them would mean baselining reflection conventions that `tools/phpstan/WaaseyaaEntrypointProvider.php`
deliberately handles by design. **Do not enable.** If the reflective surface is ever to be measured
again, re-run it as an isolated diagnostic, never as gate config.
**Overlap:** `docs/audits/2026-05-17-dead-code-baseline-audit.md` owns the gate's history. No open issue.
**Confidence:** high (measured, both arms, full-repo). **Risk of acting:** medium-high — hence WONTFIX.

**Second lens, same session (`usageExcluders.tests.enabled: true`, "dead tested code"):** run full-repo
with the baseline removed, alongside a no-excluder control. Both arms returned the **same 29 findings** —
i.e. the current baseline contents exactly, with **no additional findings under the tested configuration**.
This is not a proof that no dead code exists: it is scoped to the two excluder configurations tested, to
PHP source under the analysed paths, and it inherits the detector's documented limits (anonymous-class
methods, abstract trait methods, most magic methods are never reported). Of the 29, 14 are the
`@deprecated since alpha.288` testing traits and 15 are production members; spot-checked entries are
correctly baselined — `Foundation\Tenant\TenantMiddleware` is self-documented
`@internal Not wired in v1.0 — reserved for v2.0`, and `Entity\Community\HasCommunityTrait` is a declared
public extension point. **Reflection-discovered compatibility types and declared public extension points
are retained, not deletion candidates** — including the six `#[FieldType(category: 'compatibility')]`
legacy items in `packages/field/src/Item/`, which read as unreferenced only because they are resolved by
string id.

### CL-19 — SUSPECTED: packages promise public test/IO helpers that a packaged consumer cannot autoload
**Forge mirror:** waaseyaa/framework#2961. **Found:** 2026-09-06. **Status: suspected defect, repair deliberately held** pending a packaging design
and ownership decision (see "Why no repair is proposed yet").
**Exact source identity:** candidate `bb5c0d8b72d09da276dbd7b9080bb0b10e140596`, reproduced from
`git archive HEAD` (tracked bytes only), not from the working tree.

**Root cause.** Composer's `autoload-dev` is honoured **only for the root package**. A package's own
`autoload-dev` never activates when that package is installed as a dependency — including under
`require-dev`. Every first-party consumer-facing test/contract helper is currently published behind a
package-local `autoload-dev` mapping (or, for `waaseyaa/cli`, behind the *monorepo root's* `autoload-dev`),
so none of them are reachable downstream.

**Reproduction** (`tests/PackagedForm/check-bimaaji-skill-resources` recipe: `git archive HEAD` → temp
source tree → consumer with path repositories at `symlink=false`, so `vendor/waaseyaa/*` holds installed
bytes; packagist enabled for third-party deps only; **no monorepo autoloader, no hand-added namespace
mappings**; consumer requires `waaseyaa/{cli,entity,entity-storage,migration,graphql,testing}` plus
`phpunit/phpunit ^13` in its own `require-dev`). After `composer install`, `require vendor/autoload.php`:

```
CONTROL cli src class    Waaseyaa\CLI\WaaseyaaConsoleApplication                     LOADS
CONTROL testing pkg      Waaseyaa\Testing\WaaseyaaTestCase                           LOADS
CONTROL entity src       Waaseyaa\Entity\EntityType                                  LOADS
cli   StdinSource   [PS] Waaseyaa\CLI\Io\StdinSource                                 *** NOT FOUND ***
cli   CliTester          Waaseyaa\CLI\Testing\CliTester                              *** NOT FOUND ***
ent   TranslContract[PS] Waaseyaa\Entity\Testing\Translation\TranslatableEntityContractTest  *** NOT FOUND ***
es    ContractBase       Waaseyaa\EntityStorage\Testing\Contract\FieldStorageGatewayContractTest  *** NOT FOUND ***
migr  DestConform   [PS] Waaseyaa\Migration\Testing\DestinationConformanceTestCase   *** NOT FOUND ***
migr  SrcConform    [PS] Waaseyaa\Migration\Testing\SourceConformanceTestCase        *** NOT FOUND ***
```
`[PS]` = declared `'disposition' => 'public'` in that package's `public-surface.php`. Three positive
controls load, so the harness is sound and the `Waaseyaa\CLI\` / `Waaseyaa\Entity\` prod mappings work.
`vendor/composer/autoload_psr4.php` in the consumer contains **no `Waaseyaa\…\Testing\` or
`Waaseyaa\CLI\Io\` prefix at all**. The **files ship** — `vendor/waaseyaa/cli/tests/{Testing,Io}/`,
`vendor/waaseyaa/migration/testing/`, `vendor/waaseyaa/entity/testing/Translation/` are all present in
the installed packages — so nothing is `export-ignore`d; only the mapping is absent.
**Execution proof, not file existence:** a consumer PHPUnit test written to the pattern CLAUDE.md
prescribes (`use Waaseyaa\CLI\Testing\CliTester;`) fails — `CliTester must autoload / Failed asserting
that false is true` — while a control test asserting `Waaseyaa\Testing\WaaseyaaTestCase` passes in the
same run (`Tests: 2, Assertions: 2, Failures: 1`).

**Affected contracts.** Four entries are **declared public surface** and are therefore promises the
packaged form does not keep: `Waaseyaa\CLI\Io\StdinSource` (cli), `Waaseyaa\Migration\Testing\{Destination,
Source}ConformanceTestCase` (migration), `Waaseyaa\Entity\Testing\Translation\TranslatableEntityContractTest`
(entity). Conformance/contract test cases exist precisely so third-party implementors can prove their own
source, destination, or translatable entity conforms — undeliverable today.
Two are **package-internal test infrastructure**, not downstream API, and are recorded only for scope:
`Waaseyaa\CLI\Testing\CliTester` and `Waaseyaa\EntityStorage\Testing\Contract\*` are absent from every
`public-surface.php`, and no shipped consumer skill (`packages/bimaaji/resources/skills/`) or cookbook
mentions `CliTester`; CLAUDE.md prescribes it for in-repo agents only.
`waaseyaa/testing` is the **working precedent**: helpers live in `src/` under production `autoload`, with
`phpunit/phpunit` in that package's `require-dev`, and consumers require the package under `require-dev`.

**Revalidation against current `origin/main` (`c0a8d5d4dab09d9bb527ec502f5c61b88b027564`).** The
reproduction above ran at `bb5c0d8b7`, which is **45 commits behind**. Re-checked: the packaged-consumer
failure is unchanged — no `autoload` mapping was added to any affected package. Three affected files did
move: `packages/cli/composer.json` (optional `mcp`/`api` dev edges only), `packages/cli/public-surface.php`
(**+12 Studio blueprint entries, #2787/#2788 — this file is under active Studio-lane edit**), and
`packages/foundation/src/Discovery/PackageManifestCompiler.php` (+123/-14, see below). `StdinSource` and the
three conformance helpers remain declared `public`; **those declared contracts stand and are preserved
here.** The finding is the packaged-consumer failure itself, independent of what any author intended.

**The boot-scanner hazard is now mitigated on `origin/main` — by work done for another reason.**
Commit `181a699ae feat(#2788): compile blueprint governance through canonical enforcement (#2949)` added
two skips to the PSR-4 walk: namespaces containing `Tests\` are skipped, and any PSR-4 entry whose
directory contains `/testing/` or ends with `/testing` is skipped (`PackageManifestCompiler.php:1449-1450`
and `:1611-1627`). Its inline comment names the alpha.106→107 pattern explicitly. **This must be reviewed
on its own architectural merits, not adopted merely because it makes this repair convenient** — review
notes: (1) it is a silent skip, so a package that legitimately needs discovery from a `testing/` directory
loses it with no diagnostic; (2) it matches a path substring anywhere, so an unrelated `src/…/testing/…`
directory would also vanish from discovery. An earlier draft of this entry additionally called the
lowercase-only `testing` matching a defect — **withdrawn**: no supported configuration exhibits an
observable failure from it, because nothing maps a `tests/Testing/`-shaped path under production `autoload`
today, so the asymmetry is unreachable. It is recorded as an **untested asymmetry** conditional on a future
design mapping such a path, and the cheap answer there is to keep helper files under a lowercase `testing/`
directory. No scanner change is proposed. **Do not extend or rely on this scanner change as part of this repair without that
independent review.**

**Design comparison (recorded, not chosen).** The governing insight is the `waaseyaa/testing` precedent:
it maps `Waaseyaa\Testing\ => src/` under **production `autoload`** and ships
`WaaseyaaTestCase extends TestCase`; its dev-scoping comes from the **consumer's** `require-dev`, never
from package-side `autoload-dev`. Relocation does **not** require renaming — a PSR-4 prefix is independent
of the package that maps it.

| Option | FQCNs | Layers / ownership | Cost | Verdict |
|---|---|---|---|---|
| (a) dedicated sibling packages (`waaseyaa/migration-testing`, …) mapping the same namespace under their own `autoload`, consumer-required under `require-dev` | preserved | helper stays with its own types; absent entirely in `--no-dev`, so no reliance on the scanner skip | N new packages + `split.yml`, CP007, metapackage entries | strongest isolation |
| (b) house the public helpers in `waaseyaa/testing` **keeping existing namespaces** | preserved | **fails for two of three.** `Migration\Testing\*` imports `Waaseyaa\Migration\*` (L3) — housing it in `waaseyaa/testing` (L1) needs an L1→L3 `require` and upward file imports. `CLI\Testing\*` is worse (L1→L6). Only `Entity\Testing\Translation\*` fits: entity is L1 and `waaseyaa/testing` **already requires `waaseyaa/entity`** | low | **not uniform — rejected as a single answer** |
| (c) change the boot scanner to accommodate helpers | preserved | — | — | **already landed independently (#2949); not to be extended for this purpose** |
| (e) flip each package's own mapping from `autoload-dev` to `autoload`, files staying put | preserved | no layer change at all: PL005 scopes to `<pkg>/src/`, and PL010 already permits `testing/**` self-references and declared upward edges | one manifest line per package (+ CLI file moves) | cheapest; but makes a `TestCase` subclass PSR-4-reachable inside a **production** dependency, so its safety rests on the scanner skip reviewed above |

`StdinSource` is separable from all of this: it is a plain `interface` (`readLine()`, `isInteractive()`)
with no PHPUnit parent and no dev-only dependency — arguably production API misfiled under `tests/`.
Moving it to `packages/cli/src/Io/StdinSource.php` **preserves its FQCN exactly** (`Waaseyaa\CLI\Io\`
already resolves to `src/` under the existing production mapping, so no manifest change is needed at all),
carries no scanner hazard, and needs no new package. It is the one piece with an unambiguous smallest repair.

**Acceptance — autoload success is explicitly insufficient.** Any repair must land with a
`tests/PackagedForm/` proof that does BOTH: (1) **installed-consumer PHPUnit execution** — a consumer built
from the exact candidate via path repositories at `symlink=false`, with no root-level namespace mapping,
running a real PHPUnit test that *uses* each `public`-declared helper (e.g. driving a conformance case to a
pass and to a deliberate fail), not merely asserting `class_exists`; and (2) a **real `--no-dev` kernel boot
proof** — `composer install --no-dev` in a consumer, then an actual kernel boot, demonstrating that no
PHPUnit-extending class is reflected during discovery. Neither alone is sufficient.

**Deliberately out of scope for the public-contract repair** (recorded separately, not to be bundled):
the two package-internal helpers (`CLI\Testing\CliTester`, `EntityStorage\Testing\Contract\*`), which
are in no `public-surface.php` and referenced by no shipped consumer skill or cookbook; and the CLAUDE.md
orchestration-table drift (`packages/cli/src/CommandDefinition.php` and `src/CliKernel.php` were removed by
the Symfony Console migration; `Io/` is under `tests/`, not `src/`).

**Coordination — correcting an earlier claim.** An earlier draft inferred these packages were unowned
because no lease row covered them. **That inference was wrong**: the lease registry records worktree
custody, not file ownership, and an absent row establishes nothing. Codex holds active CLI/Studio work
including #2857 (`plan-2857-studio-activation`), and `packages/cli/public-surface.php` took 12 new entries
from #2787/#2788 during this session. What was verified, at file granularity: no local branch and no Studio
worktree carries a committed or uncommitted change to `packages/cli/tests/Io`, `packages/cli/src/Io`, root
`composer.json`, or `packages/cli/composer.json`. Contention **was** found on shared surfaces and is being
avoided — `docs/specs/public-surface-declarations.md` (`codex/2901-integration`: 9 unmerged commits,
+219 lines) and `docs/specs/cli-kernel.md` (16 branches). Shared compiler, public-surface, CI and existing
packaged-harness files stay untouched until ownership is reconciled with the Codex orchestrator.

**Status update (lane 1 landed).** The `StdinSource` slice is implemented on
`fix/2961-stdinsource-packaged-reachability` — see `docs/change-records/FW-CONSUMER-TEST-SUPPORT-01.md`
for the conformance-helper design. The conformance-helper scope remains open.

**Confidence:** high on the reproduction (measured, with three passing positive controls) and on the
layer analysis (read from the manifests and the rule text). The declared public contracts are preserved;
no contract change is proposed. **Open question — a design choice, not an intent guess:** whether option
(a) or (e) is the right isolation level, which turns on how much weight the `/testing/` scanner skip should
carry. **Risk:** low to record; medium to repair, hence held pending design and ownership sign-off.
