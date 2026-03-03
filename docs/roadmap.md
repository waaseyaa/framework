# Waaseyaa Roadmap

## Access Control Pipeline

The access control system has four layers, being built bottom-up:

| # | Layer | Status | Description |
|---|-------|--------|-------------|
| 1 | Entity access policies | Done | `NodeAccessPolicy`, `TermAccessPolicy`, `ConfigEntityAccessPolicy` wired into `EntityAccessHandler` |
| 2 | Gate wiring | Next | Instantiate `Gate` with policies, pass to `AccessChecker` in `index.php` |
| 3 | Route-level `_gate` options | Planned | Attach `_gate` to entity CRUD routes in `JsonApiRouteProvider` so `AccessChecker` rejects at the route level |
| 4 | Uncovered entity policies | Planned | Add policies for `user`, `media`, `path_alias`, `menu`, `menu_link` — user/media need per-bundle rules |
| 5 | Policy auto-discovery | Planned | Wire `PackageManifest.policies` into `EntityAccessHandler` instead of hardcoding in `index.php` |

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
