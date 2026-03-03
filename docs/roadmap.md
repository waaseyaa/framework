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
| 6 | Manifest classmap bug | Bug | `PackageManifestCompiler::scanClasses()` only reads classmap, which is empty under default PSR-4. Requires `composer dump-autoload --optimize`. See #17 |

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
| 4 | Boolean field rendering | Bug | Boolean fields return as `1`/`0` integers instead of `true`/`false`. Causes Vue prop warnings in checkbox widgets. See #18 |
| 5 | Timestamp auto-population + formatting | Bug | `created`/`changed` not set on save. Datetime widget shows raw `0` instead of formatted dates. See #19 |
