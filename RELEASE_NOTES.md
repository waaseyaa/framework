# Waaseyaa v0.1.0-alpha — Release Notes

**Tag:** `v0.1.0-alpha.4`
**Date:** 2026-03-14
**Status:** Alpha — API surface is unstable and may change between releases.

---

## What is Waaseyaa?

Waaseyaa is a PHP 8.3+ content framework built on a strict 7-layer architecture
(Foundation → Core Data → Content Types → Services → API → AI → Interfaces). It
ships JSON:API endpoints, server-side rendering via Twig, a Nuxt 3 admin SPA, an
MCP server for AI tooling, and a pluggable ingestion pipeline — all in a single
monorepo of 40 composable packages.

---

## Alpha highlights

### Authentication

The admin SPA ships a login/logout flow backed by PHP session authentication.
The `useAuth` composable deduplicates `GET /auth/me` calls across navigations,
the global middleware runs client-side only (no SSR-time fetches), and logout
performs a full PHP session destroy + ID regeneration to prevent session fixation.

### Access control on every entity type

`menu`, `path_alias`, and `relationship` entity types have registered access
policies that gate read/write by role. All policies are covered by
anonymous-account tests.

### SSR rendering

- `RenderController` has `renderServerError()` with `500.html.twig` fallback.
- `403.html.twig` and `500.html.twig` bundled into `packages/ssr/templates/`.
- `useLanguage` uses `useCookie()` + `useState()` instead of `localStorage`,
  eliminating SSR hydration mismatches on language-aware pages.

### Admin SPA stabilization (alpha.4)

- Fixed SsrPageHandler DI resolution — controllers can now receive dependencies
  from registered service providers via a resolver closure (#407).
- Bootstrap URL corrected to `/admin/bootstrap` with dev proxy rule (#408).
- Admin runs as client-only SPA (`ssr: false`) — removed experimental
  `viteEnvironmentApi` flag (#406).
- Error page no longer depends on i18n plugin initialization (#409).
- `composer dev` script sets `APP_ENV` and `WAASEYAA_DEV_FALLBACK_ACCOUNT`
  automatically (#410).

### Admin bridge package

PHP bridge (`waaseyaa/admin-bridge`) provides bootstrap controller, value
objects, and service provider for the admin SPA host contract. Published to
Packagist via monorepo split.

### i18n infrastructure

`Translator` and `TranslationTwigExtension` with Twig dependency for
server-side localization.

### Resilient boot failure handling

`HttpKernel` catches exceptions during boot and returns a well-formed JSON:API
error response. Stack traces are written to the error log.

### Security fixes

| Category | Fix |
|----------|-----|
| Stored XSS | `HtmlFormatter` sanitizes output |
| Session fixation | `session_destroy()` + `session_regenerate_id(true)` on logout |
| CSRF bypass | JSON:API and MCP content types correctly exempted |
| Layer violations | Foundation→Path and Validation→Entity cross-layer imports removed |

---

## Known issues

- **#380** — Vue hydration mismatch on auth-protected pages (SSR/client timing).
  Non-blocking — page corrects itself after hydration.
- Pre-existing frontend test gaps around error handling in `admin.ts` (silent
  catch on network failures) — tracked for future improvement.

---

## Verification summary

| Check | Result |
|-------|--------|
| PHP test suite (3,919 tests / 8,657 assertions) | Pass |
| Frontend test suite (98 tests / 20 files) | Pass |
| Admin SPA production build | Pass |
| SSR resolver unit tests (3 new) | Pass |

---

## Installation

```bash
composer create-project waaseyaa/waaseyaa my-site
cd my-site
composer dev
```

---

## What's next

- Relationship modeling refinements (inference contract, UI)
- Ingestion source connectors and cross-source identity
- GraphQL endpoint (currently a stub)
- Public extension SDK stabilization

See [docs/specs/workflow.md](docs/specs/workflow.md) for the full roadmap.
