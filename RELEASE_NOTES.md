# Waaseyaa v1.0 — Release Notes

**Branch:** `release/v1.0-rc` → `main` (PR #379, pending merge approval)
**Date:** 2026-03-13
**Milestone:** [v1.0 Release](https://github.com/waaseyaa/framework/milestone/22)

---

## What is Waaseyaa?

Waaseyaa is a PHP 8.3+ content framework built on a strict 7-layer architecture
(Foundation → Core Data → Content Types → Services → API → AI → Interfaces). It
ships JSON:API endpoints, server-side rendering via Twig, a Nuxt 3 admin SPA, an
MCP server for AI tooling, and a pluggable ingestion pipeline — all in a single
monorepo of 40 composable packages.

---

## v1.0 Highlights

### Production-ready authentication

The admin SPA now ships a complete login/logout flow backed by PHP session
authentication. The `useAuth` composable deduplicates `GET /auth/me` calls across
navigations, the global middleware runs client-side only (no SSR-time fetches), and
logout performs a full PHP session destroy + ID regeneration to prevent session
fixation.

### Access control on every entity type

`menu`, `path_alias`, and `relationship` entity types now have registered access
policies that gate read/write by role. All policies are covered by anonymous-account
tests that verify public-facing entities behave correctly for unauthenticated users.

### SSR rendering — complete and hardened

- `RenderController` gained `renderServerError()` with a `500.html.twig` template
  fallback — the framework no longer leaks PHP exceptions to the browser on 5xx.
- `403.html.twig` and `500.html.twig` bundled into `packages/ssr/templates/`.
- `useLanguage` uses `useCookie()` + `useState()` instead of `localStorage`,
  eliminating SSR hydration mismatches on language-aware pages.
- The default homepage correctly links to the admin SPA dev server
  (`http://localhost:3000`) instead of the PHP-served `/admin` route.

### Resilient boot failure handling

`HttpKernel` catches exceptions thrown during the boot phase and returns a
well-formed JSON:API error response with the correct `application/vnd.api+json`
content type. Stack traces are written to the error log. Before this fix, a boot
failure would produce a blank 500 page.

### Comprehensive package documentation

All 40 framework packages ship a `README.md` with accurate class names, interface
lists, and usage examples that match the current source code.

### Observability — Telescope integration

The admin SPA includes a Telescope panel showing real-time codified context health:
spec drift scores, CLAUDE.md coverage gaps, and per-subsystem freshness indicators.

### Security fixes

| CVE-class | Fix |
|-----------|-----|
| Stored XSS | `HtmlFormatter` now sanitizes output |
| Session fixation | `session_destroy()` + `session_regenerate_id(true)` on logout |
| CSRF bypass | JSON:API and MCP content types correctly exempted; all others enforced |
| Layer violations | Foundation→Path and Validation→Entity cross-layer imports removed |

---

## Breaking changes from v0.x

- HTTP 403 (not 404) is now returned for unpublished nodes accessed by
  unauthenticated users. Update any client-side error handling that expected 404.
- `ControllerDispatcher` no longer passes `null` values in the payload array.
  Controllers that tested for key existence (vs. `isset()`) may need adjustment.
- `session_destroy()` clears all session data on logout. If you stored non-auth
  session state, migrate it to a cookie or database before calling logout.

---

## Known issues

- **#380** — Vue hydration mismatch on auth-protected pages (SSR/client timing).
  Non-blocking — page corrects itself after hydration. Scheduled for v1.1.

---

## RC verification summary

| Check | Result |
|-------|--------|
| PHP test suite (4352 tests) | ✅ Pass |
| TypeScript test suite (78 tests) | ✅ Pass |
| Admin SPA production build | ✅ 2.23 MB / 545 kB gzip |
| Playwright MCP browser smoke (11 checks) | ✅ 10 pass / 0 fail / 1 warning |
| Repository drift (main vs RC) | ✅ None |
| Untracked/stray files | ✅ None |

---

## Upgrade path

This release requires no schema migrations from v0.4.0. Run:

```bash
composer update
./vendor/bin/phpunit --configuration phpunit.xml.dist
cd packages/admin && npm install && npm test
```

---

## What's next — v1.1

- Relationship modeling refinements (inference contract, UI)
- Ingestion source connectors and cross-source identity
- GraphQL endpoint (currently a stub)
- Public extension SDK stabilization

See [docs/specs/workflow.md](docs/specs/workflow.md) for the full roadmap.

---

## Contributors

Thank you to everyone who filed issues, reviewed PRs, and tested the RC.
