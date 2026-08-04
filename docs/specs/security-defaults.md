# Security Defaults

## Purpose

Defines the security model for Waaseyaa's default content types, API access, and configuration handling. Ensures secrets never leak into version-controlled manifests and that RBAC is enforced at the API layer before entity-level access checks fire.

## Threat Model

The `defaults/` directory contains declarative manifests (YAML) and JSON Schema files. These files are:

- **Version-controlled** — committed to the repository
- **Non-executable** — never `require`d or `eval`d at runtime
- **Read by `DefaultsSchemaRegistry`** — only `*.schema.json` files, only `x-waaseyaa` metadata

Attack surfaces:

| Threat | Mitigation |
|--------|-----------|
| Secret committed anywhere in repository source | Repository-wide `bin/check-no-secrets` CI gate; generated dependency/build trees are excluded |
| Secret embedded structurally in `defaults/` | `DefaultsSecretsIntegrationTest` structural value scan |
| Manifest referencing external endpoint | YAML manifests are governance docs, not runtime config |
| Anonymous write to entity API | Route-level `_authenticated` option on POST/PATCH/DELETE |

## RBAC Enforcement at the API Layer

Access control is enforced in three layers, evaluated in sequence:

| Layer | Mechanism | Failure code |
|-------|-----------|-------------|
| **Route-level authentication** | `_authenticated` route option → `AccessChecker` → `AuthorizationMiddleware` | 401 Unauthorized |
| **Route-level authorization** | `_permission` / `_role` / `_gate` route options → `AccessChecker` | 403 Forbidden |
| **Entity-level access** | `EntityAccessHandler` → `AccessPolicyInterface` implementations | 403 Forbidden |
| **Field-level access** | `EntityAccessHandler::checkFieldAccess()` → `FieldAccessPolicyInterface` | Field omitted from response |

### Route-level authentication

All entity CRUD write routes (POST, PATCH, DELETE) require authentication via the `_authenticated` route option. Anonymous requests receive HTTP 401 with a `WWW-Authenticate: Bearer` header.

Read routes (GET index, GET show) do **not** require authentication at the route level. Entity access policies control read authorization — this preserves the ability for entity types to serve public content.

### Entity-level access for core.note

The `NoteAccessPolicy` enforces:

| Operation | tenant.member | tenant.admin | platform.admin | anonymous |
|-----------|--------------|-------------|---------------|-----------|
| view | Allowed | Allowed | Allowed | Neutral (denied) |
| create | — | Allowed | Allowed | Neutral (denied) |
| update | — | Allowed | Allowed | Neutral (denied) |
| delete | Forbidden | Forbidden | Forbidden | Forbidden |

### Field-level access for core.note

System fields (`id`, `uuid`, `created_at`, `updated_at`) are read-only for all roles except `platform.admin`. `tenant_id` is settable on creation but immutable on update.

See also: `docs/specs/access-control.md`, `docs/specs/field-access.md`.

### GraphQL authentication model

The GraphQL endpoint is registered with `allowAll()` at the route level — no route-level authentication. This is intentional: GraphQL serves both public queries and authenticated mutations through a single endpoint.

Access enforcement happens at the resolver level via `GraphQlAccessGuard`. Mutations that require authentication check the `_account` on the request and return a `UserError` on denial. This follows the same pattern as REST read routes: route is open, entity access policies control authorization.

### HTTP response security headers

`HttpKernel` wires `SecurityHeadersMiddleware` around real controller/domain-router dispatch, so framing / MIME-sniffing headers reach **every dispatched response** during the pipeline's response phase:

| Header | Default | Notes |
|--------|---------|-------|
| `X-Frame-Options` | `SAMEORIGIN` | Blocks cross-origin framing (clickjacking) while preserving same-origin inline previews. Configurable via `security_headers.frame_options`. **Omitted** when the matched route set the `_frame_exempt` request attribute (`SecurityHeadersMiddleware::FRAME_EXEMPT_ATTRIBUTE`) — the per-route opt-out for content meant to be framed cross-origin. |
| `X-Content-Type-Options` | `nosniff` | Always applied. |

Provider-contributed HTTP middleware uses the same onion response phase: code after `$next->handle()` receives the final response. This is the supported app hook for response headers, cookies, compression, and equivalent response decoration. See [middleware-pipeline.md](middleware-pipeline.md).

`Content-Security-Policy` and `Strict-Transport-Security` are **opt-in**, NOT applied by the kernel defaults: `default-src 'self'` would break consumer SPAs and same-origin inline previews, and HSTS needs HTTPS certainty. A deployment that wants them contributes an explicitly configured `SecurityHeadersMiddleware($csp, $hstsEnabled, $hstsMaxAge)` from its provider (the constructor defaults remain `default-src 'self'` / `X-Frame-Options: DENY` / HSTS-on for that explicit path).

### Rate limiting

`RateLimitMiddleware` (priority 80) enforces IP-based rate limiting: 60 requests per 60-second window by default. Exceeding the limit returns HTTP 429 with `Retry-After` header.

### Request body limits

`BodySizeLimitMiddleware` (priority 70) rejects payloads exceeding 1 MB with HTTP 413.

### CSRF token cookie

State-changing routes (`POST`, `PUT`, `PATCH`, `DELETE`) require a valid CSRF token accepted from a form field, the `X-CSRF-Token` header, or the `X-XSRF-TOKEN` header (URL-decoded); every `text/html` response sets the `XSRF-TOKEN` cookie so Inertia + Vue consumers get protection automatically with no consumer-side code. See [docs/conventions/csrf-token-cookie.md](../conventions/csrf-token-cookie.md) for runnable examples and the full cookie-attribute table.

The `_csrf` route option is three-valued (#2177 F1 prerequisite):

| `_csrf` | Set by | Behaviour on state-changing methods |
|---|---|---|
| *(unset)* | default | Validate, **except** for the exempt content types `application/json` / `application/vnd.api+json` (browsers cannot send those from HTML forms) |
| `false` | `RouteBuilder::csrfExempt()` | Never validate — the route has its own authentication model (MCP bearer, API keys) |
| `true` | `RouteBuilder::requireCsrf()` | **Always validate, JSON content types included.** The explicit opt-in takes precedence over the content-type exemption, because a cookie-authenticated JSON endpoint *is* CSRF-reachable: the content-type exemption only holds while the session cookie is not the sole authenticator of a JSON request whose side effects matter (`fetch` can send `application/json` cross-origin; the browser will attach the session cookie unless SameSite blocks it) |

No framework route sets `_csrf = true` yet — the first consumer is the MCP write-tier approval controller (#2177 F1). Opted-in routes still accept all three token sources; SPA callers use the `X-XSRF-TOKEN` header fed from the cookie.

Cookie delivery: `CsrfMiddleware` writes the `XSRF-TOKEN` cookie on every `text/html` response (unchanged), **and** on any response — JSON included — whose request carries an authenticated `_account` plus a non-empty `waaseyaa_uid` login-session marker (`CsrfMiddleware::attachCookieIfAuthenticated()`). The admin SPA boots against JSON endpoints (`GET /api/user/me`) and never sees a kernel HTML response, so the session-authenticated path seeds its token without exposing it to bearer-only requests. Cookie flags are identical on both paths (`HttpOnly=false`, `SameSite=Lax`, `Path=/`, `Secure` follows the request scheme); anonymous and bearer-only non-HTML responses stay cookie-free. A CSRF 403 on an opted-in route also re-delivers the cookie to an authenticated login session so a stale-token client can recover without a page reload.

Origin checking is **not** part of this middleware: the CORS allowlist lives at the kernel level (`CorsHandler`), and a middleware-level same-origin guard would break the supported Nuxt-dev deployment (SPA on `localhost:3000` calling the PHP API cross-port). Endpoints whose side effects warrant defense-in-depth beyond the token — the MCP approval controller — should verify the `Origin`/`Referer` header against the deployment's allowed origins in the controller itself (see `docs/specs/mcp-endpoint.md`).

## Encryption Policy

### Current (pre-v1)

- `body` field is stored as plaintext in the `_data` JSON blob column
- No at-rest encryption is applied by `SqlEntityStorage`
- Transport-layer encryption (TLS) is the operator's responsibility
- The `core.note.yaml` manifest declares `encryption_policy: none`

### Future opt-in path

When field-level encryption is implemented:

- The manifest value will become the algorithm name (e.g., `aes-256-gcm`)
- Key management will be the operator's responsibility via a `WAASEYAA_ENCRYPTION_KEY` env var
- Existing plaintext data will require a migration command

## Secrets Handling

### Invariant: no secrets in repository source

Version-controlled source — including `defaults/`, packages, scripts, documentation, and workflows — must **never** contain credentials, tokens, or connection strings. All secrets enter the application exclusively via environment variables. `bin/check-no-secrets` scans the repository root and excludes only generated or dependency trees (`.git`, `.worktrees`, `vendor`, `node_modules`, `dist`, `build`, and `tmp`). Test fixtures assemble secret-shaped dummy values from fragments so the repository contains no static token-like payload.

### Environment variable contract

| Variable | Purpose | Required |
|----------|---------|----------|
| `WAASEYAA_JWT_SECRET` | HS256 shared secret for bearer auth | Only if bearer auth is used |
| `OPENAI_API_KEY` | OpenAI API key for embeddings | Only if `embedding_provider=openai` |
| `WAASEYAA_DEV_FALLBACK_ACCOUNT` | Dev-only auto-auth as platform admin | **Must be false in production** |

Full listing: `.env.example`.

### Configuration secrets

`api_keys` in `config/waaseyaa.php` maps raw API keys to UIDs. This file is **not** version-controlled in deployments (only `skeleton/config/waaseyaa.php` is committed as a template). Deployments must override it via environment-specific config.

### Enforcement

| Check | Type | Location |
|-------|------|----------|
| `bin/check-no-secrets` | Repository-wide shell scan for token patterns | CI: `security-defaults` job |
| `DefaultsSecretsIntegrationTest` | Structural YAML/JSON value scanning | CI: PHPUnit `--filter Phase22` |

Patterns checked: `sk-*` (OpenAI), `ghp_*` (GitHub), `xox[bp]-*` (Slack), `ya29.*` (Google OAuth), `AIza*` (Google API), PEM private keys, DSN with embedded credentials.

## File Reference

| File | Purpose |
|------|---------|
| `packages/access/src/AccessStatus.php` | `UNAUTHENTICATED` enum case |
| `packages/access/src/AccessResult.php` | `unauthenticated()` factory, `isUnauthenticated()` predicate |
| `packages/access/src/Middleware/AuthorizationMiddleware.php` | 401 response branch |
| `packages/routing/src/AccessChecker.php` | `_authenticated` route option evaluation |
| `packages/routing/src/RouteBuilder.php` | `requireAuthentication()` fluent method |
| `packages/api/src/JsonApiRouteProvider.php` | Authentication on write routes |
| `packages/graphql/src/GraphQlRouteProvider.php` | GraphQL route with `allowAll()` |
| `packages/graphql/src/GraphQlAccessGuard.php` | Resolver-level access enforcement |
| `packages/foundation/src/Middleware/SecurityHeadersMiddleware.php` | CSP, HSTS, X-Frame-Options |
| `packages/foundation/src/Middleware/RateLimitMiddleware.php` | IP-based rate limiting (60/60s) |
| `packages/foundation/src/Middleware/BodySizeLimitMiddleware.php` | 1 MB body size limit |
| `packages/user/src/Middleware/BearerAuthMiddleware.php` | JWT + API key bearer auth |
| `packages/note/src/NoteAccessPolicy.php` | Entity + field access for core.note |
| `defaults/core.note.yaml` | Governance manifest with encryption_policy |
| `bin/check-no-secrets` | CI shell gate for secret patterns |
| `tests/Integration/Phase22/DefaultsSecretsIntegrationTest.php` | Structural secrets scan |
| `.env.example` | Canonical env var reference |
| `.github/workflows/ci.yml` | `security-defaults` CI job |
