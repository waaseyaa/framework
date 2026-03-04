# Waaseyaa Roadmap

## Access Control Pipeline

The access control system has four layers, being built bottom-up:

| # | Layer | Status | Description |
|---|-------|--------|-------------|
| 1 | Entity access policies | Done | `NodeAccessPolicy`, `TermAccessPolicy`, `ConfigEntityAccessPolicy` wired into `EntityAccessHandler` |
| 2 | Gate wiring | Done | `EntityAccessGate` instantiated with policies, passed to `AccessChecker` in `index.php` |
| 3 | Route-level `_gate` options | Done | `_gate` attached to entity CRUD routes; `AccessChecker` rejects at the route level |
| 4 | Uncovered entity policies | Done | `UserAccessPolicy`, `MediaAccessPolicy` added; `path_alias`, `menu`, `menu_link` covered by `ConfigEntityAccessPolicy` |
| 5 | Policy auto-discovery | Done | `#[PolicyAttribute]` on policy classes; `PackageManifestCompiler` discovers and `index.php` instantiates from manifest |
| 6 | Manifest classmap bug | Done | PSR-4 fallback added when classmap has no Waaseyaa entries. Logs warning recommending `--optimize`. See #17, PR #20 |

## Authentication

| # | Feature | Status | Description |
|---|---------|--------|-------------|
| 1 | Session-based auth | Done | `SessionMiddleware` + `AuthorizationMiddleware` pipeline |
| 2 | JWT / API key auth | Planned | Bearer token middleware for machine clients |

## Media & Files

| # | Feature | Status | Description |
|---|---------|--------|-------------|
| 1 | Media entity type | Done | Registered with `InMemoryFileRepository` |
| 2 | Disk-backed file storage | Planned | `LocalFileRepository` implementation |
| 3 | Upload endpoint | Planned | `POST /api/media/upload` with file picker widget in admin SPA |

## Admin SPA

| # | Feature | Status | Description |
|---|---------|--------|-------------|
| 1 | Entity CRUD views | Done | List, create, edit, delete for all entity types |
| 2 | i18n locales beyond English | Planned | Infrastructure exists (`useLanguage` composable), needs locale files |
| 3 | Dynamic CORS origin | Planned | CORS in `index.php` hardcodes `localhost:3000`. Nuxt dev server may bind to 3001+ if port is taken. Need dynamic origin handling (e.g., `WAASEYAA_CORS_ORIGIN` env var or pattern-based localhost matching). |
| 4 | Boolean field rendering | Done | `ResourceSerializer` casts booleans to native `true`/`false`; `SchemaList.vue` formats as ✓/—. See #18 |
| 5 | Timestamp auto-population + formatting | Done | `SqlEntityStorage` auto-populates `created`/`changed`; `ResourceSerializer` formats as ISO 8601; `SchemaList.vue` formats with `toLocaleString()`. See #19 |
| 6 | Boolean field defaults on create | Done | SchemaPresenter emits `default` in JSON Schema; SchemaForm initializes from defaults in create mode. See #24 |
| 7 | Entity list headings show machine names | Enhancement | Page headings show `node_type` instead of "Content Type". Should use entity type labels. See #26 |

## Dev Server

| # | Issue | Status | Description |
|---|-------|--------|-------------|
| 1 | SPA navigation hangs | Bug | PHP built-in server is single-threaded; SSE broadcast connection blocks API requests during SPA routing. Full page loads work. See #23 |
| 2 | SSE broadcast disconnects | Bug | SSE connection drops immediately on page load, related to single-threaded server. See #25 |

## Database

| # | Issue | Status | Description |
|---|-------|--------|-------------|
| 1 | User table schema mismatch | Bug | `user` table has `id` column but entity config expects `uid` after commit 2ea5dd55. Causes 500 on `/api/user`. See #22 |
