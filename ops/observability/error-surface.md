# Error Surface — Waaseyaa v1.1

Documents all surfaces where errors can be emitted in production.

## PHP Error Surfaces

| Surface | Handler | Output |
|---------|---------|--------|
| Boot failure (`HttpKernel`) | Top-level catch in `HttpKernel::handle()` | JSON:API error, `application/vnd.api+json`, logged via `error_log()` |
| Controller exception | `ControllerDispatcher` try-catch | JSON:API error response |
| 403 Forbidden | `AccessChecker` → `HttpKernel` | JSON:API error or `403.html.twig` (SSR) |
| 404 Not Found | Router miss → `HttpKernel` | JSON:API error or `404.html.twig` (SSR) |
| 500 Server Error | `RenderController::renderServerError()` | `500.html.twig` fallback |
| Unhandled exception | Top-level catch in `public/index.php` | JSON:API error, stack trace to `error_log()` |

## Admin SPA Error Surfaces

| Surface | Handler | Output |
|---------|---------|--------|
| Auth failure | `useAuth` composable + global middleware | Redirect to `/login` |
| API fetch failure | Composables (no global handler yet) | Component-level — varies |
| Hydration mismatch | Vue runtime warning | Console warning, self-corrects |
| Missing route | Nuxt routing | 404 page (no `error.vue` — tracked in #344) |

## Logging

All PHP errors write to the web server error log via `error_log()`. No `psr/log` dependency.

## v1.1 Goals

- [ ] Add `error.vue` for admin SPA (#344)
- [ ] Centralize API error handling in a composable
- [ ] Add structured logging for access denials
