# Changelog

All notable changes to Waaseyaa are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Waaseyaa follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [v0.1.0-alpha.36] — 2026-03-20

### Added

- `ServiceProvider::setKernelResolver()` — kernel-level fallback resolver for
  cross-layer DI. Singleton closures can now resolve `EntityTypeManager`,
  `DatabaseInterface`, `EventDispatcherInterface`, and cross-provider bindings
  without manual wiring. Set automatically by `AbstractKernel` during boot.

## [v0.1.0-alpha.35] — 2026-03-20

### Fixed

- `SsrPageHandler::resolveControllerInstance()` now checks if the controller
  class itself is registered via the service resolver before falling back to
  reflection-based parameter resolution. Fixes controllers with ambiguous
  constructor types (e.g., `EntityRepositoryInterface`) that are pre-wired as
  singletons in service providers.

---

## [1.0.0] — 2026-03-13

This release consolidates all v1.0 milestone work including eight pre-release
review sprints. It is the first stable, production-ready release of the
Waaseyaa framework.

### RC Verification (2026-03-13)

Playwright MCP live browser smoke test passed on `release/v1.0-rc`:

| Test | Result |
|------|--------|
| Dashboard renders (header, sidebar, language switcher) | ✅ |
| Login page renders correctly | ✅ |
| Auth middleware: unauthenticated `/` → `/login` | ✅ |
| Auth middleware: all protected routes redirect | ✅ |
| Login error handling shows alert | ✅ |
| SSR homepage renders (dark theme, hero, cards) | ✅ |
| SSR admin link → `http://localhost:3000` | ✅ |
| SSR footer uses `<footer>` element | ✅ |
| JSON:API `/api/node` returns valid response | ✅ |
| SSR 404 page with path interpolation | ✅ |
| Admin SPA production build (2.23 MB / 545 kB gzip) | ✅ |
| PHP test suite (4352 tests) | ✅ |
| TypeScript test suite (78 tests) | ✅ |

**Known issue:** Vue hydration mismatch warning on auth-protected pages (#380,
post-v1.0). Non-blocking — page corrects itself after hydration.

### Added

- **Admin Auth UI** (`packages/admin`, `packages/user`) — Login page, `useAuth`
  composable with `checkAuth()` deduplication, global auth middleware with SSR
  guard, and backend `AuthController` with `/auth/login`, `/auth/logout`,
  `/auth/me` endpoints (PR #374, closes #330).
- **Access policies** for `menu`, `path_alias`, and `relationship` entity types
  with anonymous-account test coverage (PR #375, closes #327).
- **`RenderController::renderServerError()`** — 500 response with `500.html.twig`
  template fallback; bundled `500.html.twig` and `403.html.twig` templates
  (PR #377, closes #322/#334).
- **Top-level exception handler** — `HttpKernel` catches boot failures and returns
  a structured JSON:API error response without crashing the process (PR #376,
  closes #329).
- **Package READMEs** — every one of the 40 framework packages now ships a
  `README.md` with accurate class names and usage examples (PR #378, closes #333).
- **Telescope codified context observability** — real-time spec/CLAUDE.md diff
  view, context health scores, and drift detection in the admin SPA (#353).
- **SSR `InteractsWithRenderer` test trait** — assertion helpers for template
  rendering in integration tests (#313).
- **Base SSR layout template** and framework Twig extension (#312, #311).
- **`waaseyaa/mail` package** — basic mailer abstraction (#307).
- **MCP codified context server** — exposes subsystem specs to AI tooling (#212).
- **`waaseyaa/note` package** — `core.note` default content type with full RBAC
  and field-level ACL (#195, #199).
- **Operator diagnostics** — `DiagnosticCode` enum, `DiagnosticEmitter`,
  health CLI commands, schema drift detection (#227, #256–#260).
- **Ingestion pipeline defaults** — envelope format, strict validation, logging,
  canonical error codes (#225, #246–#250).
- **Schema registry** — `DefaultsSchemaRegistry`, `schema:list` CLI (#229).
- **Entity type lifecycle** — enable/disable with audit log and CLI (#198).
- **RBAC** — full role-based access control via `#[PolicyAttribute]` (#199).
- **Entity write audit trail** — `EntityAuditLogger` and listener (#208).
- **`waaseyaa/search` package** — DTOs, `SearchProviderInterface`, Twig extension
  (#193).
- **Security defaults** — route-level auth enforcement, CI secrets check (#234).
- **Application Directory Convention v1.0** in skeleton and docs (#276).

### Fixed

- **SSR hydration errors** — `useLanguage` replaced `localStorage` with
  `useCookie()` + `useState()` for SSR-safe persistent locale (PR #370, closes
  #325).
- **SSR homepage admin link** — `/admin` link updated to `http://localhost:3000`
  (the SPA dev server URL); broken 404 resolved (PR #367, closes #328).
- **`Content-Type` header in outer catch** — corrected from `application/json` to
  `application/vnd.api+json`; added stack trace to `error_log()` (PR #376, closes
  #329).
- **Auth dead ternary** — `me()` returned identical branches regardless of account
  type; simplified to single `getRoles()` call (PR #374, closes #330).
- **Auth `findUserByName`** — added `.condition('status', 1)` to exclude disabled
  accounts from login (PR #374, closes #330).
- **Logout security** — replaced `unset($_SESSION['waaseyaa_uid'])` with
  `session_destroy()` + `session_regenerate_id(true)` (PR #374, closes #330).
- **`MenuAccessPolicy` reason string** — misleading message corrected to
  `'View access not granted to unauthenticated users.'` (PR #375, closes #327).
- **SSR integration tests** — updated to expect HTTP 403 for unpublished nodes and
  unauthenticated preview (PR #377, closes #322).
- **`home.html.twig` footer element** — `<div>` corrected to `<footer>` (PR #377).
- **Foundation→Path layer violation** — removed cross-layer import (#357, closes
  #315).
- **Validation→Entity layer violation** — removed cross-layer import (#360, closes
  #316).
- **Stored XSS** — HTML sanitized in `HtmlFormatter` (#355, closes #318).
- **CSRF exemption** — JSON:API `application/vnd.api+json` and MCP endpoint
  correctly bypass CSRF validation (#354, #359, closes #317, #320).
- **Single-tenant enforcement** — multi-tenant registration blocked for v1.0
  (#358, closes #321).
- **`NoteInMemoryStorage::create()` return type** (#356, closes #314).

### Changed

- `SsrHttpKernelIntegrationTest` — forbidden-access assertions updated from 404
  to 403 to match intentional PR #371 behaviour change.
- `ControllerDispatcher` — replaced `array_filter()` with explicit `isset()`
  payload construction; null-guarded body parameter.
- `useAuth` middleware — uses `checkAuth()` instead of `fetchMe()` to avoid
  redundant network requests on every navigation.
- `login.vue` — removed SSR-time redirect (caused hydration mismatch); redirect
  now happens client-side only.

### Security

- Logout now invalidates the full PHP session (`session_destroy()`) and regenerates
  the session ID to prevent session fixation attacks.
- HTML output sanitized in `HtmlFormatter` to prevent stored XSS.
- CSRF token checked for all non-exempt content types.
- MCP endpoint explicitly exempted from CSRF (machine-to-machine).

---

## [0.4.0] — 2025-xx-xx

Previous stable milestone. See git history for details.

## [0.2.0] — 2025-xx-xx

See git history for details.

## [0.1.0] — 2025-xx-xx

Initial public release.

[1.0.0]: https://github.com/waaseyaa/framework/compare/v0.4.0...release/v1.0-rc
[0.4.0]: https://github.com/waaseyaa/framework/compare/v0.2.0...v0.4.0
[0.2.0]: https://github.com/waaseyaa/framework/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/waaseyaa/framework/releases/tag/v0.1.0
