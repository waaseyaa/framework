---
work_package_id: "WP05"
title: "Admin SPA OIDC client registration UI + consent flow + integration test"
dependencies: ["WP01", "WP04"]
requirement_refs:
  - "FR-009"
  - "FR-010"
  - "FR-011"
  - "NFR-001"
  - "NFR-003"
  - "C-001"
  - "C-002"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. During implement, this WP may branch from a dependency-specific base (WP01 + WP04), but completed changes must merge back into main unless redirected explicitly."
subtasks:
  - "T013"
  - "T014"
  - "T015"
  - "T016"
  - "T017"
phase: "Phase 4 - Admin surface + acceptance"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "packages/admin/app/pages/oidc"
execution_mode: "code_change"
owned_files:
  - "packages/api/src/Controller/OidcClientController.php"
  - "packages/api/src/Http/Router/OidcClientApiRouter.php"
  - "packages/api/src/ApiServiceProvider.php"
  - "packages/foundation/src/Kernel/BuiltinRouteRegistrar.php"
  - "packages/oidc/migrations/2026_05_25_000004_oidc_user_consent_schema.php"
  - "packages/oidc/src/Consent/ConsentRepository.php"
  - "packages/oidc/src/Consent/ConsentScreenController.php"
  - "packages/oidc/templates/consent.html.twig"
  - "packages/oidc/src/Authorize/AuthorizeController.php"
  - "packages/admin/app/composables/useOidcClients.ts"
  - "packages/admin/app/pages/oidc/clients/index.vue"
  - "packages/admin/app/pages/oidc/clients/[id].vue"
  - "packages/admin/app/i18n/en.json"
  - "packages/admin/tests/unit/composables/useOidcClients.test.ts"
  - "packages/admin/e2e/oidc-clients.spec.ts"
  - "packages/routing/src/AuthOidcRouteServiceProvider.php"
  - "tests/Integration/PhaseOidc/EndToEndAuthCodeFlowTest.php"
  - "packages/oidc/README.md"
  - "CHANGELOG.md"
history: []
---

# WP05 — Admin SPA OIDC client registration UI + consent flow + integration test

**Mission:** `oidc-flows-completion-01KSEFTP`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd <path printed by `spec-kitty agent action implement WP05`>
```
Lane has no `vendor/` and no `packages/admin/node_modules/` — run `composer install` and `cd packages/admin && npm install` before phpunit/vitest.

## Read first

- `packages/api/src/Controller/QueueController.php` + `packages/api/src/Http/Router/QueueAdminApiRouter.php` + the `ApiServiceProvider::httpDomainRouters()` queue block — the M4B admin JSON:API pattern you mirror.
- `packages/api/src/Controller/NotificationController.php` — the M4C admin JSON:API pattern (also M5A-style).
- `packages/admin/app/pages/queue/index.vue` + `composables/useQueueJobs.ts` — frontend pattern.
- `packages/admin/app/pages/notifications/index.vue` — the test-action modal pattern (relevant for the one-time client_secret reveal).
- WP01's `AuthorizeController` — you replace the `// TODO(WP05): real consent` stub with a real consent check.
- WP01's `OidcClient` entity (via `OidcClientLookup`) — the data model you expose via JSON:API.

## Subtasks

**T013 — JSON:API CRUD for OIDC clients (api L4)**

- `packages/api/src/Controller/OidcClientController.php` — index/show/create/update/delete + `regenerateSecret($id)`. Uses `EntityTypeManager::getRepository('oidc_client')`. JSON:API envelope.
  - **client_secret handling:** On create + regenerate, generate a random 32-byte URL-safe string, return it ONCE in the response body alongside the new client, AND hash via `password_hash(..., PASSWORD_DEFAULT)` before persisting. On subsequent reads (`index`, `show`), the `client_secret` field is **not in the response at all** — not as `null`, not as `[hidden]`. The SPA shows a placeholder client-side.
- `packages/api/src/Http/Router/OidcClientApiRouter.php` — mirror `QueueAdminApiRouter`. `supports()` matches `OidcClientController::`. Dispatch index/show/create/update/delete/regenerateSecret.
- `packages/api/src/ApiServiceProvider.php` — in `httpDomainRouters()`, add `$routers[] = new OidcClientApiRouter(new OidcClientController($entityTypeManager));` unconditionally (controller is api-local; no resolveOptional needed because the `oidc_client` entity type is registered by L1 oidc which boots before api).
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — register `api.oidc-clients.*`:
  - `GET /api/oidc-clients`, `'Waaseyaa\\Api\\Controller\\OidcClientController::index'`, `_role: admin`.
  - `POST /api/oidc-clients`, `'Waaseyaa\\Api\\Controller\\OidcClientController::create'`, `_role: admin`.
  - `GET /api/oidc-clients/{id}`, `'Waaseyaa\\Api\\Controller\\OidcClientController::show'`, `_role: admin`.
  - `PATCH /api/oidc-clients/{id}`, `'Waaseyaa\\Api\\Controller\\OidcClientController::update'`, `_role: admin`.
  - `DELETE /api/oidc-clients/{id}`, `'Waaseyaa\\Api\\Controller\\OidcClientController::delete'`, `_role: admin`.
  - `POST /api/oidc-clients/{id}/regenerate-secret`, `'Waaseyaa\\Api\\Controller\\OidcClientController::regenerateSecret'`, `_role: admin`.

**T014 — Consent storage + screen (oidc L1)**

- `migrations/2026_05_25_000004_oidc_user_consent_schema.php` — `oidc_user_consent` (`id` PK, `account_id`, `client_id`, `scope_set_hash` CHAR(64), `granted_at` int). Unique index `(account_id, client_id, scope_set_hash)`.
- `src/Consent/ConsentRepository.php` — `scopeSetHash(array $scopes): string` (sha256 over the sorted scope list joined with spaces); `hasConsent(accountId, clientId, scopes): bool`; `record(accountId, clientId, scopes): void`.
- `src/Consent/ConsentScreenController.php` — `show(Request): Response` renders the Twig template with `client`, `scopes`, `scope_descriptions` (via `UserinfoClaimResolver::scopeDescriptionFor()`), `consent_screen_text`, `state` (from session-stored auth request). `submit(Request): Response`: if Approve → `ConsentRepository::record(...)`, then issue the authorization code and redirect to `redirect_uri?code=...&state=...` (reuses the WP01 code-issuance path — factor it into a `AuthorizationCodeIssuer` if not already). If Deny → redirect to `redirect_uri?error=access_denied&error_description=user_denied_consent&state=<verbatim>`.
- `packages/oidc/templates/consent.html.twig` — minimal: heading `"<client.name> is requesting access"`, scope list as `<ul>` with descriptions, `{{ consent_screen_text }}` rendered as plain text (use `e()` filter — NEVER raw), Approve and Deny submit buttons inside a `<form method="POST" action="/oidc/consent">` with a hidden CSRF token (use the framework's CSRF middleware if present, else document a TODO).
- `src/Authorize/AuthorizeController.php` — replace the WP01 stub with: store the `ValidatedAuthorizationRequest` in the session under a known key (e.g. `_oidc_pending_authorization`); if `ConsentRepository::hasConsent(...)` returns false, redirect to `/oidc/consent`; otherwise issue the code immediately.

**T015 — Admin SPA page + composable + i18n**

- `packages/admin/app/composables/useOidcClients.ts` — TypeScript composable. Methods: `fetchList(): Promise<OidcClient[]>`, `fetchOne(id)`, `createClient(payload)`, `updateClient(id, patch)`, `deleteClient(id)`, `regenerateSecret(id)`. Each calls the corresponding JSON:API endpoint. The create + regenerate responses include `client_secret_once`; the composable exposes this on a `lastSecretReveal: Ref<{id, secret} | null>` ref so the page can render the one-time modal.
- `packages/admin/app/pages/oidc/clients/index.vue` — list view. Columns: name, client_id (truncated, tooltip full), redirect URIs (count), allowed scopes (chips), confidential badge, Edit / Regenerate / Delete buttons. "New client" button → `/oidc/clients/new`.
- `packages/admin/app/pages/oidc/clients/[id].vue` — create/edit form. Fields: name, redirect_uris (multi-line textarea, one per line, validated as URLs), allowed_scopes (multi-select), confidential (checkbox), consent_screen_text (textarea). On create response, render a modal: "Your client_secret is `<secret>`. Save it — this is the only time it will be shown." with copy-to-clipboard and "I have saved this" acknowledge button (modal cannot be dismissed without the acknowledgement).
- Nav: register `/oidc/clients` in the same mechanism used by `/queue` and `/notifications` — READ those first. Likely a top-level "Identity" group entry.
- `packages/admin/app/i18n/en.json` — keys per spec.md WP05.
- `packages/admin/tests/unit/composables/useOidcClients.test.ts` — vitest. Mock fetch; assert each method calls the right endpoint with the right payload; assert `lastSecretReveal` is populated on create + regenerate, null on others.
- `packages/admin/e2e/oidc-clients.spec.ts` — Playwright smoke: list, create, secret-reveal modal, delete. Run deferred (lane worktree pattern).

**T016 — Consent route + admin routes**

- `packages/routing/src/AuthOidcRouteServiceProvider.php`:
  - `oidc.consent.show` → `GET /oidc/consent`, `'Waaseyaa\\Oidc\\Consent\\ConsentScreenController::show'`, `_session: true`.
  - `oidc.consent.submit` → `POST /oidc/consent`, `'Waaseyaa\\Oidc\\Consent\\ConsentScreenController::submit'`, `_session: true`.

**T017 — End-to-end integration test (FR-011) + docs + changelog**

- `tests/Integration/PhaseOidc/EndToEndAuthCodeFlowTest.php` (`#[CoversNothing]`) — boot full kernel against SQLite. See plan.md §"Integration test" for the 8-step assertion script. The test is the single regression anchor for every downstream consumer-app SSO mission.
- `packages/oidc/README.md` — replace the "Non-goals (v1)" line with:
  ```
  ## Status (post-oidc-flows-completion-01KSEFTP)

  All endpoints listed under "Scope" above are implemented and integration-tested:
  - /oidc/authorize (PKCE S256 mandatory for public clients)
  - /oidc/token (authorization_code, refresh_token)
  - /oidc/userinfo (field-access bound per DIR-004)
  - /oidc/revoke (RFC 7009)
  - /.well-known/jwks.json + /.well-known/openid-configuration
  - waaseyaa oidc:rotate-signing-key CLI
  - /admin/oidc/clients SPA UI

  Out-of-band (filed as follow-ups, see kitty-specs/<mission>/spec.md "Out-of-band"):
  - /oidc/end_session (RP-initiated logout)
  - /oidc/introspect (RFC 7662 — JWKS makes this optional)
  - Encrypted ID tokens (JWE)
  - Dynamic client registration (RFC 7591)
  ```
- `CHANGELOG.md` `[Unreleased]` → **Added**:
  - `OIDC issuer: authorization-code flow with PKCE S256 mandatory for public clients.`
  - `OIDC issuer: refresh-token rotation with theft-detection chain cascade + RFC 7009 revocation.`
  - `OIDC issuer: userinfo endpoint with per-claim field-access enforcement (DIR-004).`
  - `OIDC issuer: JWKS + discovery + signing-key storage with rotation CLI.`
  - `Admin SPA: OIDC client registration UI at /admin/oidc/clients.`

## Verification gate (in lane worktree)
1. `composer install && cd packages/admin && npm install && cd -`
2. `vendor/bin/phpunit packages/oidc/tests/ packages/api/tests/ packages/routing/tests/ tests/Integration/PhaseOidc/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `cd packages/admin && npm test && npm run typecheck && npm run lint`
6. **NFR-003 manual check:** `rg -n 'private_key_pem|client_secret_hash' packages/admin/app packages/api/src/Controller/OidcClientController.php` — confirm no path exposes hashed or private material to the admin SPA. The `client_secret_once` field MUST only appear in create + regenerate response payloads.
7. **FR-011 anchor:** Run the integration test and paste the full output. Confirm step 5 asserts `assertArrayNotHasKey('email', ...)` and not `assertNull(...)`.

## Commit + handoff
- Commits (footer `Mission: oidc-flows-completion-01KSEFTP`):
  - `feat(api): OIDC client JSON:API CRUD + regenerate-secret`
  - `feat(oidc): user consent storage + consent screen controller + template`
  - `feat(routing): /api/oidc-clients + /oidc/consent routes`
  - `feat(admin): OIDC clients page + composable + secret-reveal modal`
  - `test(oidc): end-to-end auth-code + userinfo + refresh + revoke integration test`
  - `docs(oidc): README status section + CHANGELOG entries`
- Then:
  ```
  spec-kitty agent tasks mark-status T013 T014 T015 T016 T017 --status done --mission oidc-flows-completion-01KSEFTP
  spec-kitty agent tasks move-task WP05 --to for_review --mission oidc-flows-completion-01KSEFTP --note "Admin SPA + consent + integration test. Mission acceptance gate ready."
  ```

## Report back with
1. Commit SHAs.
2. The full integration test output (`vendor/bin/phpunit tests/Integration/PhaseOidc/`).
3. Confirmation that `rg 'private_key_pem|client_secret_hash' packages/admin/app` returns empty.
4. Screenshot or hand-rendered description of the one-time secret-reveal modal (showing copy-to-clipboard + acknowledge button).
5. Confirmation `bin/check-package-layers` is green (`packages/api` adds NO new `waaseyaa/oidc` require — entity type is auto-discovered).

## Activity Log
- 2026-05-25T05:30:11Z – unknown – subagent shipped retroactively
- 2026-05-25T05:30:21Z – unknown – code already committed: 221ac1248..a7240b47f
- 2026-05-25T05:30:32Z – unknown – Opus review: all 5 OIDC WPs cleanly committed; gates pass; DIR-004 userinfo field-access wiring confirmed; subagent self-corrected DatabaseInterface::getConnection() per CLAUDE.md gotcha
