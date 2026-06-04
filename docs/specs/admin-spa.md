# Admin SPA

<!-- Spec reviewed 2026-05-24 - #1576 queue dashboard now shows queued + in-flight jobs in addition to failed. `TransportInterface::listJobs(int $limit, int $offset = 0, ?string $status = null): array` was added (M4B follow-up, mandatory on implementors) with two impls: `DbalTransport::listJobs()` issues a COUNT + SELECT against `waaseyaa_queue_jobs` with `reserved_at IS NULL` (queued) / `IS NOT NULL` (in_progress) / no filter (both); `InMemoryTransport::listJobs()` merges `$queues` + `$reserved` sorted by id. Abstract `Waaseyaa\Queue\Tests\Contract\TransportContractTest` (registered under the Unit suite via phpunit.xml.dist) verifies both backends in lockstep — covers empty, all-queued, queued+in_progress mix, status filter, limit/offset pagination, zero-limit, invalid-status. `GET /api/queue/jobs` now reads optional `?status=failed|queued|in_progress|all` (default `failed` — NFR-001 M4B backward compat preserved; meta envelope unchanged at `{page, per_page, total}` so M4B integration assertions pass UNCHANGED). Failed branch keeps the existing FailedJobRepository path; queued/in_progress branches call `TransportInterface::listJobs()`; `all` merges failed-first-then-transport on a single page. When `TransportInterface` is unbound (slimmed-down install), all non-failed statuses fall back to the failed shape. `ApiServiceProvider` extends the queue `resolveOptional()` block to also resolve `TransportInterface` (optional). `QueueController` constructor gains `?TransportInterface $transport = null` as the third arg. Frontend: `useQueueJobs()` returns `status` (`Ref<'failed'|'queued'|'in_progress'|'all'>`) and `fetchJobs(page, perPage, status)` accepts the third arg (default `'failed'`); response row type is now the union `QueueJob = FailedJob | TransportJob` with the `isFailedJob()` guard. `pages/queue/index.vue` adds a chip filter row above the table; failed chip keeps the M4B full-detail columns + retry/discard buttons, the live chips render a lean (id, queue, status pill, attempts, age-seconds) table with NO retry/discard buttons (C-001 — retry/discard remain failed-only). New i18n keys: queue_status_failed, queue_status_queued, queue_status_in_progress, queue_status_all, queue_age_seconds, queue_column_status, queue_column_age. queue_title flipped from "Failed jobs" to "Queue jobs" and queue_empty from "No failed jobs." to "No jobs in this view." to reflect the broader surface. -->
<!-- Spec reviewed 2026-05-24 - M4C WP01 (#1472) admin notifications dashboard at /notifications: new NotificationController + NotificationAdminApiRouter, both gated by `_role: admin` via BuiltinRouteRegistrar. `GET /api/notification/channels` returns `{data: [{type, class}, ...]}` from `NotificationDispatcher::channels()` (new accessor — read-only view of the constructor-supplied channel map; no other dispatcher state touched). `POST /api/notification/channels/{type}/test` looks up the channel by type, builds anonymous `TestRecipient` (reads `_account` from the request, routes mail→email, database→account id) + `TestNotification` (subject `[Waaseyaa test]`, body explains "no action required"; returns a real `Waaseyaa\Mail\Envelope` from `toMail()` so `MailChannel` doesn't crash), and calls `ChannelInterface::send()` inside try/catch. 200 with `{type, status: "success", message: "Test sent."}` on success; 404 JSON:API error envelope on unknown type; 500 with `{type, status: "failed", message, exception_class}` on `\Throwable` — the controller never serialises a throwable directly (FR-010, M4B precedent). `ApiServiceProvider` gains a third `resolveOptional()` block for `NotificationDispatcher` after the queue + scheduler blocks; skips cleanly if absent (slimmed-down install). `packages/api/composer.json` adds `../notification` path repo + `waaseyaa/notification: ^0.1.0-alpha.188` require (L4 → L3, layer-clean). SPA route inventory: `/notifications` Nuxt page mirrors `/queue` shape; columns are channel type, implementation FQCN (truncated short class + tooltip with full FQCN), and a Send-test action. After a test send the page renders either a success chip or a failure card; failure card includes `exception_class` when present. New i18n keys: notifications_title, notifications_empty, notifications_column_type, notifications_column_class, notifications_column_action, notifications_action_test, notifications_confirm_test_title, notifications_confirm_test_body, notifications_status_success, notifications_status_failure, notifications_help. New composable useNotificationChannels (`{channels, loading, error, lastTestResult, fetchChannels, testChannel}`), new component NotificationChannelRow, new page pages/notifications/index.vue. NavBuilder gains a `/notifications` link in the Operations section right after `/scheduler`; NavBuilder test updated to assert 5 nav items + the new `[data-testid=nav-notifications]` link on an empty catalog. Delivery log + per-channel enable/disable are deferred — the notification package does not yet carry the persistence; the follow-up issue tracks adding a `delivery_log` table, a `ChannelConfig` model, an enable/disable flag, and a second tab to `/notifications`. Closes audit C-L3-02 + C-L0-03. -->
<!-- Spec reviewed 2026-05-24 - M4B WP02 (#1471) admin scheduler dashboard at /scheduler: new SchedulerController + SchedulerAdminApiRouter, both gated by `_role: admin` via BuiltinRouteRegistrar. `GET /api/scheduler/tasks` returns `{data: [{name, description, expression, timezone, last_run_at, last_status, next_run_at}, ...]}` — `last_run_at`/`last_status` are nullable (no row in `waaseyaa_schedule_state` yet), `next_run_at` always set. `POST /api/scheduler/tasks/{name}/trigger` calls `ScheduleRunner::runOne()` (new public method that bypasses the cron check, honours the overlap lock, and records run state); 200 with `{status, message, exception_class?}` on success/failure, 404 on unknown task. `ScheduleRunResult` extended with optional `status`/`message`/`exceptionClass` fields so the controller never serialises a `\Throwable` (FR-010). `SchedulerServiceProvider` now binds `ScheduleStateRepository` as a container singleton (database driver only) so the L4 API provider can `resolveOptional()` it. M4B WP01 admin queue routes (landed 2026-05-23) likewise admin-only: `GET /api/queue/jobs` (paginated failed jobs), `POST /api/queue/jobs/{id}/retry`, `POST /api/queue/jobs/{id}/discard`. SPA route inventory under the always-present "Operations" sidebar section: `/queue` (failed jobs) and `/scheduler` (scheduled tasks) — both Nuxt pages at top-level paths, no `/admin/` prefix (matches the existing /workflows, /telescope convention). New i18n keys: scheduler_title, scheduler_empty, scheduler_column_*, scheduler_action_trigger, scheduler_confirm_trigger_*, scheduler_status_*. New composable useScheduledTasks, new component SchedulerTaskRow, new page pages/scheduler/index.vue. NavBuilder test asserts the /scheduler link renders alongside /queue under the Operations heading even with an empty catalog. -->
<!-- Spec reviewed 2026-05-20 - SSE history-replay defense: BroadcastMessage interface in composables/useRealtime.ts now matches what the server actually emits (id: number, created_at: number) — the never-emitted `timestamp` field was removed. SchemaList watch(messages, …) now skips any event whose created_at predates the component's setup-time mountedAtSec; a defensive second line if the server-side cursor ever regresses to history replay. SchemaList's realtimeEnabled check hardened to String(config.public.enableRealtime) === '1' since Nuxt's runtime-config serializer coerces digit-string env vars to numbers, which silently disabled SSE in some builds. Public admin surface contract unchanged. -->
<!-- Spec reviewed 2026-05-20 - local-dev hardening: bump Nuxt 4.4.4 → 4.4.6 (latest 4.4.x patch); add vite.optimizeDeps.include for @vue/devtools-core and @vue/devtools-kit so Vite pre-bundles them at startup rather than discovering them mid-request and restarting the dev server (which kills the vite-node IPC socket and surfaces as "Vite Node IPC socket path not configured" 500 on the first /admin/ request). No runtime behaviour, public contract, or admin surface API change. -->
<!-- Spec reviewed 2026-05-11 - M4A-4 dry-run UI: new TransitionDryRunForm component on /admin/workflows/[id] (third section below transitions matrix); dryRun() method added to useWorkflowDefinitions composable (POST /api/workflow-definitions/dry-run); 18 new i18n keys in en/fr; result rendered inline with allowed/forbidden/neutral states using brand CSS tokens. -->
<!-- Spec reviewed 2026-05-11 - M4A-3 (#1432 / umbrella #1414) per-entity transition-history widget on entity detail pages: new <WorkflowTransitionHistoryTimeline /> component reads `workflow_audit` from the entity's attributes (already surfaced by ResourceSerializer via _data JSON blob round-trip — no backend change), renders reverse-chronological timeline with transition chip / from→to states / uid / timestamp. Wired into pages/[entityType]/[id].vue below SchemaView/SchemaForm. Renders nothing when audit empty. 4 new i18n strings; M4A-4/5 deferred -->
<!-- Spec reviewed 2026-05-11 - M4A-2 (#1430 / umbrella #1414) workflow detail page at /admin/workflows/[id]: states grid (id/label/weight/metadata) + transitions matrix (from×to grid with cell-level transition listing); new findById helper on useWorkflowDefinitions; WorkflowState TS interface gains `metadata: Record<string, unknown>`; backend serializer extended to include `metadata` per state (3-line additive change in WorkflowDefinitionsController); 10 new i18n strings; closes C-L3-01 detail-view portion; M4A-3/4/5 still deferred -->
<!-- Spec reviewed 2026-05-11 - M4A-1 (#1428 / umbrella #1414) workflows list page: new GET /api/workflow-definitions endpoint (admin-role-gated, returns `{data: WorkflowDefinition[]}` shape) wired via WorkflowDefinitionsController (packages/api/src/Workflow/) + WorkflowDefinitionsApiRouter (kernel-adjacent, exempted in bin/check-package-layers); new useWorkflowDefinitions composable + /admin/workflows page list editorial workflow with state/transition counts; api/composer.json now requires waaseyaa/workflows; 7 new i18n strings; closes C-L3-01 list-view portion; detail page / history / dry-run / guard editing deferred to M4A-2..M4A-5 -->
<!-- Spec reviewed 2026-05-11 - M1B-image/fonts (#1411) deferred indefinitely: admin SPA has zero <img>, zero background-image, zero static image assets (only public/favicon.ico), and uses system font stack in AdminShell.vue:109; adopting @nuxt/image and @nuxt/fonts now would add infrastructure for nothing; audit E-Mod-01 updated; M1B umbrella sub-set closed (eslint + icon adopted, image + fonts consciously deferred until SPA grows images or web fonts) -->
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
<!-- Spec reviewed 2026-04-24 - useCodifiedContext + E2E: `/api/telescope/agent-context/…` (legacy HTTP alias on server); Nuxt routes still `/telescope/codified-context/*`; cross-link telescope-agent-context-telemetry.md -->
<!-- Spec reviewed 2026-04-21 - IngestSummaryWidget: NC sync status from `/api/staff/nc-sync-status`; dashboard link `/staff/ingestion` (staff surface, not admin SPA catch-all) -->
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

## SPA bet (DIR-007)

The framework's committed workspace UI surface is the standalone Nuxt 3 + Vue 3 + TypeScript SPA in `packages/admin/`. This is a constitutional commitment (charter directive **DIR-007**, ratified by mission `charter-amendment-anokii-track-01KSEFE0`), not a default-able preference. Distribution maintainers building on Waaseyaa SHOULD consume the framework's Nuxt SPA either as-is or by extending it via the documented composables + page slots.

`packages/inertia` is the alternative protocol adapter, retained as **optional / experimental**. Distributions that prefer server-driven UI (e.g., for large permission trees, classification rule editors, or multi-tenant policy UI) may install `waaseyaa/inertia` explicitly. It is not bundled by `waaseyaa/full`. See `packages/inertia/README.md` for the Inertia entrypoint and `packages/admin/README.md` for the Nuxt entrypoint.

Changes to this commitment require a charter amendment (per `## Amendment Process` in `.kittify/charter/charter.md`), not just a spec edit.

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

No CSS framework. Styles are defined in `packages/admin/app/components/layout/AdminShell.vue` as global CSS using CSS custom properties (`--color-primary`, `--color-surface`, etc.).

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
  create(type: string, attributes: Record<string, any>): Promise<JsonApiResource>
  update(type: string, id: string, attributes: Record<string, any>): Promise<JsonApiResource>
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
- `search()` uses `filter[{labelField}][operator]=STARTS_WITH` with 250ms debounce on the widget side. Minimum 2 characters required.
- All methods should use `apiFetch` from `useApi()` for imperative data fetching (ensures correct `baseURL` and `credentials`).

### useSchema (`packages/admin/app/composables/useSchema.ts`)

Fetches and caches JSON Schema for an entity type. Drives all form rendering.

```ts
function useSchema(entityType: string): {
  schema: Ref<EntitySchema | null>; loading: Ref<boolean>; error: Ref<string | null>
  fetch(scopeId?: string): Promise<void>; invalidate(scopeId?: string): void
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
  'x-target-type'?: string; 'x-access-restricted'?: boolean
}
interface EntitySchema {
  $schema: string; title: string; description: string; type: string
  'x-entity-type': string; 'x-translatable': boolean; 'x-revisionable': boolean
  properties: Record<string, SchemaProperty>; required?: string[]
}
```

- Endpoint: `GET /api/schema/{entityType}` returns `{ meta: { schema: EntitySchema } }`
- **Bundle-aware fetch:** `fetch(scopeId?)` passes the scoping entity id through the
  transport (`transport.schema(type, id?)`) so the backend can scope the schema to
  that entity's bundle and include its per-bundle fields. A node of bundle `page`
  thus exposes `body`/`blocks` in the form, not only the shared core fields
  (title, slug, published). The backend resolution lives in
  `GenericAdminSurfaceHost::handleSchema` (an explicit `bundle` in the payload
  wins, else the bundle is read from the entity named by `id`); non-bundled types
  and a missing id keep the base schema. `SchemaForm`/`SchemaView` pass the record
  id; lists and create forms call `fetch()` with no id and get the base schema.
- Module-level `Map<string, EntitySchema>` cache keyed by `type:scopeId` (so a
  bundled record's field set never collides with the bare type's). Call
  `invalidate(scopeId?)` to clear a single key.
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

**Session UI customization (PHP → SPA):** Hosts extend `GenericAdminSurfaceHost` and override `buildAdminUi(AccountInterface): ?AdminSurfaceUiPayload` to attach non-empty `AdminSurfaceUiPayload` to `AdminSurfaceSessionData`. JSON includes a top-level `ui` object only when the payload has at least one valid header link or sidebar item. Sidebar `group` values that look like i18n keys (`nav_*`) are passed through `t()` in `NavBuilder`; an empty/missing `group` uses `nav_group_custom` (“Shortcuts”). External targets use `external: true` or absolute URLs (`http(s):`, `//`, `mailto:`, `tel:`) and render as `<a target="_blank" rel="noopener noreferrer">`.

**Host extension typing (mission #824 WP04 surface C).** `GenericAdminSurfaceHost` and `AdminSurfaceServiceProvider::routes()` accept `Waaseyaa\Entity\EntityTypeManagerInterface`, never the concrete `EntityTypeManager`. Subclasses extending the host receive the interface and must not narrow that parameter. The acceptance gate is `grep -rn 'EntityTypeManager[^I]' packages/admin*` returning no results — re-run it whenever you touch admin-surface code or its tests.

This plugin is the source of truth for `$admin` injection and for composables that call `useAdmin()`.

`runtime.catalog` preserves each `AdminSurfaceCatalogEntry` field and action declaration and carries the admin-facing metadata used by the SPA (`description`, `disabled`). Components that need action-aware UI state must derive it from the injected catalog rather than by issuing mount-time transport requests to discover whether an action exists. For contract builds, the admin package maintains a local TypeScript mirror of the admin-surface payload shape under `app/contracts/` so generated declarations do not import files from outside `packages/admin/app`.

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
function useRealtime(channels?: string[]): {
  messages: Ref<BroadcastMessage[]>; connected: Ref<boolean>; error: Ref<string | null>
  disconnect(): void; reconnect(): void
}
interface BroadcastMessage {
  channel: string; event: string; data: Record<string, unknown>; timestamp: number
}
```

- Endpoint: `GET /api/broadcast?channels={comma-separated}` (SSE)
- Default channel: `['admin']`
- Runtime constants:
  - `REALTIME_ENDPOINT_PATH = '/api/broadcast'`
  - `DEFAULT_REALTIME_CHANNELS = ['admin']`
- Auto-connects on instantiation; auto-disconnects on `onUnmounted`
- Exponential backoff reconnect: delay = `min(3000 * 2^(retryCount-1), 30000)`, max 10 retries
- Message buffer: last 100 messages (ring buffer via `slice(-99)`)
- Event types: `entity.saved`, `entity.deleted` (used by SchemaList for auto-refresh)
- Invariant: the SPA realtime client targets the canonical backend broadcast SSE endpoint and default admin channel; this contract is asserted in unit tests.

## Schema-Driven Forms

The form rendering pipeline:

1. `SchemaForm` calls `useSchema(entityType).fetch()` to get the JSON Schema
2. `sortedProperties(true)` returns editable fields sorted by `x-weight`
3. For each field, `SchemaField` resolves the widget component from `x-widget`
4. Each widget receives `modelValue`, `label`, `description`, `required`, `disabled`, `schema`

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
| `entity_autocomplete`| `WidgetsEntityAutocomplete`| `<input type="text">` + dropdown |
| `hidden`             | `WidgetsHiddenField`       | (renders nothing)    |
| `image`, `file`      | `WidgetsTextInput`         | `<input type="text">` |

### Access-Restricted Fields

When the PHP `SchemaPresenter` marks a field with `readOnly: true` + `x-access-restricted: true`:
- `sortedProperties(true)` keeps the field (unlike system readOnly which is excluded)
- `SchemaForm` passes `:disabled="!!fieldSchema['x-access-restricted']"`
- The `@update:model-value` handler guards: `if (!fieldSchema['x-access-restricted']) formData[fieldName] = val`
- Result: field is visible but not editable in the UI

### RichText Sanitization

`WidgetsRichText` (`packages/admin/app/components/widgets/RichText.vue`) sanitizes HTML client-side using DOMParser. Allowed tags: `P, BR, B, I, U, STRONG, EM, A, UL, OL, LI, H1-H6, BLOCKQUOTE, PRE, CODE, SUB, SUP, HR`. Links restricted to `http://`, `https://`, or `/` prefixes.

The contenteditable is driven **imperatively**, not via a reactive `v-html`
binding: `innerHTML` is set on mount and only when the model changes from outside
the component, and the component skips the reactive echo of its own `@input`
emit. Binding `v-html` to a contenteditable re-renders it on every keystroke,
resetting the caret to the start and scrambling typed text; the imperative
approach preserves the caret. Because the editor emits sanitized semantic HTML,
editing the body of content migrated as page-builder markup (e.g. a `pb-band`
hero) simplifies that markup to clean prose; structured (blocks) editing that
preserves rich layouts is a separate, future surface.

### EntityAutocomplete Widget

`WidgetsEntityAutocomplete` (`packages/admin/app/components/widgets/EntityAutocomplete.vue`):
- Uses `x-target-type` from schema to determine which entity type to search
- Calls `useEntity().search(targetType, 'title', query)` with 250ms debounce
- Keyboard navigation: ArrowUp/ArrowDown/Enter/Escape
- ARIA: `role="combobox"`, `aria-expanded`, `aria-autocomplete="list"`, dropdown has `role="listbox"`, items have `role="option"`

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
- NC sync: `nc_sync_widget_title`, `nc_sync_last_sync`, `nc_sync_created`, `nc_sync_skipped`, `nc_sync_failed`, `nc_sync_open_dashboard`, `nc_sync_view_teachings`, `nc_sync_view_events`, `na`
- Entity type labels: `entity_type_user`, `entity_type_node`, `entity_type_node_type`, `entity_type_taxonomy_term`, etc.
- Field labels: `field_title`, `field_machine_name`, `field_published`, `field_description`, `field_weight`, `field_email`, etc.
- Parameterized: `create_entity`, `edit_entity` (with `{type}` token)
- Telescope: `telescope_codified_context`, `telescope_cc_sessions`, `telescope_cc_drift_score`, etc. Session telemetry API calls use **`/api/telescope/agent-context/…`** (`useCodifiedContext.ts`); see **`docs/specs/telescope-agent-context-telemetry.md`**.

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
      AdminShell.vue               # Top bar + sidebar + content area + global styles
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
    telescope/
      ContextHeatmap.vue           # Heatmap visualization of codified context events
      DriftScoreChart.vue          # Drift score indicator (0–100 with color intensity)
      EventStreamViewer.vue        # Expandable event log with collapsible rows
      ValidationReportCard.vue     # Validation report display with severity styling
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
    useCodifiedContext.ts          # Codified context session/event tracking
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
2. **IngestSummaryWidget**: Renders ingestion status counters (pending_review, approved, rejected, failed) from the `ingest_log` entity type. Hides silently on 404 (entity type not registered). Each counter links to the filtered ingest_log list. Also includes a North Cloud Search sync panel fetched from `/api/staff/nc-sync-status` with last-sync timestamp, created/skipped/failed counts, and links to the staff ingestion dashboard (`/staff/ingestion`), teachings, and events.
3. **Entity type card grid**: Renders a card for each catalog entry using `entityLabel(et.id, et.label)` for i18n-aware labels.

Error handling uses `TransportError` from `~/contracts/transport` to distinguish 404s from other failures.

## Navigation

`packages/admin/app/components/layout/NavBuilder.vue` and `packages/admin/app/components/pipeline/EntityViewNav.vue` derive action-aware navigation state from `useAdmin().catalog`.

- Sidebar grouping is resolved by `groupEntityTypes(catalog)`.
- The pipeline link for an entity type is visible only when that catalog entry declares an action with `id === 'board-config'`.
- Pipeline visibility is deterministic and must remain a pure function of `runtime.catalog`.
- Navigation components must not call `runAction(type, 'board-config')` or rely on request failures to infer whether pipeline navigation should be shown.
- User-facing navigation labels in `AdminShell` and `NavBuilder` route through `useLanguage()`, including the skip link and pipeline suffix.

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
| `/:entityType/:id`       | `pages/[entityType]/[id].vue`            | Edit form            |

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
- Screen-reader-only class: `.sr-only` for visually hidden announcements
- Responsive: sidebar collapses to off-canvas drawer below 768px with overlay

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
| `packages/admin/app/composables/useCodifiedContext.ts` | Codified context session/event tracking |
| `packages/admin/app/composables/useEntity.ts` | JSON:API CRUD composable |
| `packages/admin/app/composables/useSchema.ts` | Schema fetching and caching |
| `packages/admin/app/composables/useLanguage.ts` | i18n composable |
| `packages/admin/app/composables/useNavGroups.ts` | Navigation group rendering |
| `packages/admin/app/composables/useRealtime.ts` | SSE connection composable |
| `packages/admin/app/adapters/AdminSurfaceTransportAdapter.ts` | AdminSurface API transport |
| `packages/admin/app/adapters/JsonApiTransportAdapter.ts` | JSON:API protocol transport |
| `packages/admin/app/adapters/BootstrapAuthAdapter.ts` | Bootstrap auth during init |
| `packages/admin/app/components/layout/AdminShell.vue` | Shell layout + global CSS |
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
| `packages/admin-surface/src/AdminSurfaceRoutePaths.php` | Canonical `/admin/_surface/*` patterns and `generate()` for PHP |
| `packages/admin/app/i18n/en.json` | English translation strings |
| `packages/admin/app/i18n/fr.json` | French translation strings |
| `packages/admin/playwright.config.ts` | Playwright E2E test configuration |
| `packages/admin/app/composables/useWorkflowGuards.ts` | Fetches `/api/workflow-definitions/{id}/guards` (M4A-5 Phase 1, #1470) |
| `packages/admin/app/components/workflow/WorkflowGuardsTable.vue` | Read-only guards matrix section embedded on `/workflows/{id}` (M4A-5 Phase 1, #1470) |
| `packages/admin/app/composables/useMediaVersions.ts` | Fetches `/api/media/{uuid}/versions` (DIR-005, versioned-blob-media-abstraction-01KSEFTJ WP04) |
| `packages/admin/app/components/media/MediaVersionBrowser.vue` | Read-only version table rendered at `/media/{uuid}/versions` (DIR-005 WP04) |
| `packages/admin/app/pages/media/[uuid]/versions.vue` | Media version browser page (DIR-005 WP04) |

## MCP admin

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB` (#1415, audit C-L6-01).

Read-only admin surface for the MCP endpoint. Three pages under `/mcp/`, accessible via the "MCP" nav group in `NavBuilder.vue`:

| Page | Route | Description |
|------|-------|-------------|
| Tool registry | `/mcp/tools` | Paginated list of registered MCP tools with name, category, capability chips, summary |
| Tool detail | `/mcp/tools/{name}` | Per-tool header card + collapsible input-schema viewer + recent invocations table |
| Server config | `/mcp/server-config` | Transport/protocol banner, server capabilities, registered clients table |

**Composables:** `useMcpTools`, `useMcpTool`, `useMcpServerConfig` — all use `useApi().apiFetch`.

**Security:** `McpRegisteredClient` TypeScript type has no `token` field; only `tokenFingerprint` (16-char hex) is exposed. Enforced by compile-time type assertion in `useMcpServerConfig.test.ts`.

**URL encoding:** `useMcpTool.fetchTool(name)` runs `encodeURIComponent(name)` once before the request so tool names containing dots (e.g. `bimaaji.search_specs`) are safe in path segments.

**M5B interop:** `RecentInvocationsTable.vue` renders `traceUuid` cells as router-links to `/ai/observability/runs/{uuid}` when the M5B route exists; falls back to plain text UUID when it does not (no broken links).

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
<!-- Spec reviewed 2026-05-24 - workflow guards read-only matrix section on /workflows/{id} (M4A-5 Phase 1, #1470) -->
<!-- Spec reviewed 2026-05-25 - inertia-demotion-nuxt-standardisation-01KSEFTS - WP03 - SPA bet section added per DIR-007 -->
<!-- Spec reviewed 2026-05-25 - media version browser page /media/{uuid}/versions (DIR-005 versioned-blob-media-abstraction-01KSEFTJ WP04) -->
