# Implementation Plan: OIDC Flows Completion

**Mission:** `oidc-flows-completion-01KSEFTP` — see `spec.md`.
**Pattern reference:** M5A cross-layer wiring (controller in lower layer, route lift in `AuthOidcRouteServiceProvider`), M4B `QueueController` / `QueueAdminApiRouter` for the admin JSON:API CRUD shape, `BuiltinRouteRegistrar` string-FQCN style for route registration at L0.
**Five WPs, mostly sequential:** WP01 ships the auth-code happy path that every later WP exercises. WP02 (refresh + revoke) and WP04 (JWKS + discovery) only require WP01's token-issuance code in scope. WP03 (userinfo) depends on WP01 (needs a valid access token to authenticate against) AND WP04 (the bearer-validation path benefits from JWKS shape). WP05 (admin SPA) depends on WP01 + WP04 because the consent screen flow ties to the auth endpoint and clients must be addressable before the SPA can list them.

## WP01 — Authorization-code flow

### oidc (L1) — schema, code issuance, token endpoint

- `migrations/2026_05_25_000001_oidc_authorization_code_schema.php` — table `oidc_authorization_code` (`code` UUID PK, `client_id`, `redirect_uri`, `scope`, `account_id`, `code_challenge`, `code_challenge_method` enum(`S256`), `nonce` nullable, `auth_time` int, `expires_at` int, `used_at` int nullable). Index on `client_id, used_at`.
- `migrations/2026_05_25_000002_oidc_token_schema.php` — tables `oidc_access_token` (`jti` UUID PK, `client_id`, `account_id`, `scope`, `expires_at`, `revoked_at` nullable) + `oidc_refresh_token` (`jti` UUID PK, `access_token_jti` FK, `client_id`, `account_id`, `scope`, `auth_time`, `expires_at`, `revoked_at` nullable, `chain_root_jti` UUID for theft-detection cascade).
- `src/Authorize/AuthorizeController.php` — flesh out `__invoke()`: call existing `AuthorizationRequestValidator`; if validation returns `ValidatedAuthorizationRequest`, require an authenticated session; if no session, redirect to the login URL with `return_to` set to the original authorize URL; once authenticated, hand off to consent flow (see WP05 — for WP01 stub the consent check as "always grant" and add `// TODO(WP05): real consent` so the flow runs end-to-end). Persist an `OidcAuthorizationCode` row; redirect to `redirect_uri?code=<uuid>&state=<verbatim>`.
- `src/Token/TokenController.php` — new. `__invoke(Request)`: parse `grant_type` (route to handler), authenticate client (Basic header → `client_secret_basic`; POST body → `client_secret_post`; PKCE-only public client → `none`). For `authorization_code`: validate via `TokenRequestValidator`, look up the auth code, verify PKCE (`base64url(SHA256(verifier)) === code_challenge`), check `used_at IS NULL`, set `used_at = now()`, issue `{access_token: JWT(RS256), token_type: "Bearer", expires_in, refresh_token: opaque UUID, id_token: JWT(RS256), scope}`. Returns OAuth-shape JSON `{...}` with `Content-Type: application/json`. Errors return RFC 6749 `{error, error_description}` with 400.
- `src/Token/AccessTokenIssuer.php` — service that builds a signed JWT access token + persists the row. Constructor: signing-key store (from WP04 — for WP01 use an injected `KeyMaterialProviderInterface` that has a single static-test implementation; the real implementation lands in WP04). Public method `issue(client, account, scope): AccessTokenPair`.
- `src/Token/IdTokenIssuer.php` — builds the `id_token`. Claims: `iss` (from config), `sub` (account id as string), `aud` (client_id), `exp`, `iat`, `auth_time` (from the code row), `nonce` (from the code row if present).
- `src/Oidc/Config/OidcIssuerConfig.php` — single readonly DTO carrying issuer URL, access-token TTL, refresh-token TTL, code TTL. Loaded by `OidcServiceProvider` from the active config store.
- `src/OidcServiceProvider.php` — register entity types for `oidc_client`, `oidc_authorization_code`, `oidc_access_token`, `oidc_refresh_token`; bind `KeyMaterialProviderInterface` to a `InMemoryKeyMaterialProvider` placeholder in WP01, replaced in WP04. Add the service-provider list to `composer.json`'s `extra.waaseyaa.providers` if not already present.
- `tests/Unit/Authorize/AuthorizeControllerTest.php` — happy path + missing-PKCE rejection + unsupported response_type rejection.
- `tests/Unit/Token/TokenControllerTest.php` — auth-code grant happy path + reused-code rejection + bad-PKCE rejection + wrong-client rejection.

### routing (L4) — route registration

- `packages/routing/src/AuthOidcRouteServiceProvider.php` — add: `oidc.authorize` → `GET|POST /oidc/authorize`, controller string-FQCN `'Waaseyaa\\Oidc\\Authorize\\AuthorizeController::__invoke'`, `_session: true`. `oidc.token` → `POST /oidc/token`, `'Waaseyaa\\Oidc\\Token\\TokenController::__invoke'`, `_public: true` (client auth handled inside controller).

## WP02 — Refresh + revocation

### oidc (L1)

- `src/Token/RefreshTokenGrantHandler.php` — validates refresh-token grant, looks up the refresh row, verifies `revoked_at IS NULL`, verifies client matches. **If revoked, revoke the entire chain** (`UPDATE oidc_refresh_token SET revoked_at = now() WHERE chain_root_jti = ?`; cascade `oidc_access_token` revocation by jti list) and return `invalid_grant`. Otherwise: mark the current pair `revoked_at = now()`, issue a fresh pair with the same `auth_time` + `scope`, return the OAuth JSON.
- `src/Token/TokenController.php` — extend `__invoke()` to dispatch `grant_type=refresh_token` to the new handler.
- `src/Revoke/RevocationController.php` — RFC 7009. Authenticate client. Look up the token by `token` (try refresh table first via hint, then access). On match: mark `revoked_at = now()`; if access token, also revoke its paired refresh; return 200 with empty body. On no match: still return 200 with empty body. Always `Content-Type: application/json`.
- `tests/Unit/Token/RefreshTokenGrantHandlerTest.php` — happy rotate + replay-revokes-chain + foreign-client rejection.
- `tests/Unit/Revoke/RevocationControllerTest.php` — known access revoke + known refresh revoke + unknown token still 200 + cascade verified.

### routing (L4)

- Add `oidc.revoke` → `POST /oidc/revoke`, `'Waaseyaa\\Oidc\\Revoke\\RevocationController::__invoke'`, `_public: true`.

## WP03 — Userinfo

### oidc (L1)

- `src/Userinfo/UserinfoController.php` — `__invoke(Request): JsonResponse`. Steps:
  1. Parse `Authorization: Bearer <jwt>`; if absent → 401 `WWW-Authenticate: Bearer error="invalid_token"`.
  2. Verify the JWT signature against current+previous JWKS keys; check `exp`, `iss` (must match config); look up `oidc_access_token` row by `jti` to confirm not revoked. Any failure → 401 with the same header.
  3. Load `User` entity for the access token's `account_id`. Build an `AccountInterface` for the token subject (this account becomes the "requesting account" for access-checks — NFR-004 binding).
  4. Build the candidate claim set from the granted scopes (see spec WP03 scope→claim map).
  5. For each candidate claim, resolve the corresponding `User` field. Run `FieldAccessPolicyInterface::fieldAccess($user, $fieldName, $subjectAccount)` — if the result is Forbidden, drop the claim from the response. Open-by-default: Neutral and Allowed both include the claim.
  6. Return bare JSON `{sub: "...", email: "...", ...}` with `Content-Type: application/json` (NOT `application/vnd.api+json`).
- `src/Userinfo/UserinfoClaimResolver.php` — pure mapping from scope → list of `User` field names → claim names. Testable in isolation.
- `tests/Unit/Userinfo/UserinfoControllerTest.php` — happy `openid email profile` returns expected claims; field-policy denying `email` omits the key entirely (not present, not null); revoked access token → 401; expired JWT → 401.
- `tests/Unit/Userinfo/UserinfoClaimResolverTest.php` — scope subset returns subset of claims.

### routing (L4)

- Add `oidc.userinfo` → `GET|POST /oidc/userinfo`, `'Waaseyaa\\Oidc\\Userinfo\\UserinfoController::__invoke'`, `_public: true` (bearer auth inside controller).

## WP04 — JWKS + discovery + signing-key storage + rotation CLI

### oidc (L1) — key storage + JWKS + discovery

- `migrations/2026_05_25_000003_oidc_signing_key_schema.php` — `oidc_signing_key` (`kid` UUID PK, `algorithm` enum(`RS256`), `private_key_pem` TEXT, `public_key_pem` TEXT, `created_at` int, `rotated_out_at` int nullable). Index on `rotated_out_at NULLS FIRST`.
- `src/Key/SigningKeyRepository.php` — `currentKey(): SigningKey`, `previousKey(): ?SigningKey`, `allActive(): array` (current + previous), `rotate(): SigningKey` (generates new RS256 keypair via `openssl_pkey_new`, inserts as current, marks prior current `rotated_out_at = now()`, deletes anything rotated_out before the new previous).
- `src/Key/RealKeyMaterialProvider.php implements Waaseyaa\Oidc\Token\KeyMaterialProviderInterface` — bridges WP01's placeholder. `OidcServiceProvider::register()` rebinds the interface to this implementation.
- `src/Jwks/JwksDocumentBuilder.php` — turns the repo's active keys into the JWKS document with public-key components (`kty=RSA`, `kid`, `use=sig`, `alg=RS256`, `n` (base64url modulus), `e` (base64url exponent)). Pure function, no I/O.
- `src/Jwks/JwksController.php` — `__invoke(): JsonResponse` returns the JWKS document with `Cache-Control: public, max-age=86400`.
- `src/Discovery/DiscoveryDocumentBuilder.php` — pure function: takes `OidcIssuerConfig` and returns the OIDC discovery payload with every metadata field enumerated in spec.md WP04.
- `src/Discovery/DiscoveryController.php` — `__invoke(): JsonResponse` returns the discovery document, `Cache-Control: public, max-age=3600`.

### cli (L6) — rotation command

- `packages/cli/src/Command/Oidc/RotateSigningKeyCommand.php` — `waaseyaa oidc:rotate-signing-key`. Calls `SigningKeyRepository::rotate()`; prints the new `kid` + the previous `kid` being rotated out. `@api`.
- `tests/Unit/Command/Oidc/RotateSigningKeyCommandTest.php` — `CommandTester` verifies rotation effects via the repo.

### routing (L4)

- Add `oidc.jwks` → `GET /.well-known/jwks.json`, `'Waaseyaa\\Oidc\\Jwks\\JwksController::__invoke'`, `_public: true`.
- Add `oidc.discovery` → `GET /.well-known/openid-configuration`, `'Waaseyaa\\Oidc\\Discovery\\DiscoveryController::__invoke'`, `_public: true`.

## WP05 — Admin SPA: OIDC client registration UI + consent flow

### api (L4) — JSON:API CRUD for oidc_client

- `packages/api/src/Controller/OidcClientController.php` — index/show/create/update/delete for `oidc_client`. JSON:API envelope. Wraps the `oidc_client` entity repository resolved via `EntityTypeManager`. Field `client_secret` is write-only on create/regenerate, hashed via `password_hash` before persistence, returned exactly once in the create/regenerate response, never echoed on subsequent reads.
- `packages/api/src/Http/Router/OidcClientApiRouter.php` — mirror `QueueAdminApiRouter`.
- `packages/api/src/ApiServiceProvider.php` — in `httpDomainRouters()` add the OIDC client router unconditionally (no optional binding — the controller is api-local).
- `packages/api/composer.json` — no new dependency (the `oidc_client` entity type is registered by L1 oidc which api already discovers via entity-type manager).
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — register `api.oidc-clients.*` routes (`GET /api/oidc-clients`, `POST /api/oidc-clients`, `GET /api/oidc-clients/{id}`, `PATCH /api/oidc-clients/{id}`, `DELETE /api/oidc-clients/{id}`, `POST /api/oidc-clients/{id}/regenerate-secret`), all `_role: admin`, controller string-FQCN.

### oidc (L1) — consent storage + screen

- `migrations/2026_05_25_000004_oidc_user_consent_schema.php` — `oidc_user_consent` (`id` PK, `account_id`, `client_id`, `scope_set_hash` (SHA256 of sorted scope list), `granted_at`). Unique index `(account_id, client_id, scope_set_hash)`.
- `src/Consent/ConsentRepository.php` — `hasConsent(account, client, scopes): bool`, `record(account, client, scopes): void`.
- `src/Consent/ConsentScreenController.php` — `show()` renders the Twig template; `submit()` writes a consent row on Approve and resumes the auth-code flow (creates the code, redirects), or redirects with `error=access_denied&error_description=user_denied_consent&state=<verbatim>` on Deny.
- `packages/oidc/templates/consent.html.twig` — minimal template: client name, scope list (human-readable labels via the `UserinfoClaimResolver`'s scope→description map), `consent_screen_text` (rendered as plain text, not raw HTML), Approve / Deny submit buttons.
- `src/Authorize/AuthorizeController.php` — replace the WP01 "always grant" stub with: if `ConsentRepository::hasConsent(...)` returns false, store the validated request in the session and redirect to the consent screen; otherwise proceed to issue the code.

### routing (L4)

- Add `oidc.consent.show` → `GET /oidc/consent`, `'Waaseyaa\\Oidc\\Consent\\ConsentScreenController::show'`, `_session: true`.
- Add `oidc.consent.submit` → `POST /oidc/consent`, `'Waaseyaa\\Oidc\\Consent\\ConsentScreenController::submit'`, `_session: true`.

### admin SPA (L6) — page + composable + i18n

- `packages/admin/app/composables/useOidcClients.ts` — `{items, loading, error, fetchList(), createClient(payload), updateClient(id, patch), deleteClient(id), regenerateSecret(id)}`. Mirror `useQueueJobs.ts`.
- `packages/admin/app/pages/oidc/clients/index.vue` — list view: name, client_id, redirect_uris (count), allowed_scopes (chips), confidential badge, Edit / Delete buttons, "New client" button.
- `packages/admin/app/pages/oidc/clients/[id].vue` — create/edit form with the fields in spec WP05. After create, show a one-time modal with the generated client_secret + a copy-to-clipboard affordance and "I have saved this" acknowledgement.
- Nav: add `/oidc/clients` to the same nav-registration mechanism used by `/queue` and `/notifications` (READ first; do not invent).
- `packages/admin/app/i18n/en.json` — `oidc_clients_title`, `oidc_clients_new`, `oidc_clients_secret_warning`, `oidc_clients_redirect_uris`, `oidc_clients_scopes`, `oidc_clients_confidential`, `oidc_clients_consent_text`, `oidc_clients_regenerate_secret`, plus column labels and validation messages.
- `packages/admin/tests/unit/composables/useOidcClients.test.ts` — vitest covering each method against a fetch mock.
- `packages/admin/e2e/oidc-clients.spec.ts` — Playwright smoke (run deferred per lane-worktree pattern).

### Integration test (FR-011)

- `tests/Integration/PhaseOidc/EndToEndAuthCodeFlowTest.php` (`#[CoversNothing]`) — boots the full kernel against a SQLite in-memory store. Steps:
  1. Create an admin `User` + a regular `User` (target subject) with a `FieldAccessPolicyInterface` denying `email`.
  2. Authenticate as admin, POST `/api/oidc-clients` to create a confidential client with `redirect_uris: ["http://example/cb"]`, `allowed_scopes: ["openid", "profile", "email"]`.
  3. Log in as the regular user; GET `/oidc/authorize?client_id=...&response_type=code&scope=openid+email+profile&state=xyz&code_challenge=<S256>&code_challenge_method=S256&redirect_uri=http://example/cb`. Submit consent.
  4. Exchange the redirected `code` at `POST /oidc/token`. Assert the response contains an `id_token` whose RS256 signature verifies against `GET /.well-known/jwks.json`'s current key, and whose claims match the request.
  5. Call `GET /oidc/userinfo` with the `access_token`. Assert `sub` is present, `name` is present, `email` is **absent** (not null) — the field-policy denial dropped it.
  6. Call `POST /oidc/token` with `grant_type=refresh_token`; assert success + a different `refresh_token`.
  7. Call the original `refresh_token` again; assert `invalid_grant` AND the new refresh token is also revoked (chain cascade).
  8. Call `POST /oidc/revoke` with the latest access token; assert 200; subsequent userinfo with that access token → 401.

## Verification gate (each WP, in lane worktree)

1. `composer install`
2. (WP05 / admin) `cd packages/admin && npm install && cd -`
3. `vendor/bin/phpunit packages/oidc/tests/ packages/cli/tests/ packages/api/tests/ packages/routing/tests/ tests/Integration/PhaseOidc/` (scope shrinks for earlier WPs)
4. `composer cs-check && composer phpstan`
5. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
6. `rg -n 'use Waaseyaa\\(Api|Admin|GraphQL|MCP)' packages/oidc/src` → empty (C-002).
7. `rg -n 'private_key_pem' packages/api/src packages/admin/app` → empty (NFR-003).
8. (WP05) `cd packages/admin && npm test && npm run typecheck && npm run lint`

## Reviewer focus

- (a) **NFR-004 — userinfo account binding.** Read `UserinfoController::__invoke` and confirm the `AccountInterface` passed into the field-access call is the token subject, not a system identity. Reject if the controller resolves `_account` from the request attributes (that's the IdP service caller).
- (b) **C-003 — PKCE S256 mandatory.** Read `AuthorizationRequestValidator` test for `code_challenge_method=plain` rejection AND for the missing-PKCE rejection on public clients.
- (c) **FR-003 — refresh-token chain cascade.** Confirm the integration test step 7 actually asserts the new refresh is revoked, not just that the old one fails.
- (d) **NFR-003 — private key never exposed.** Grep the admin SPA response shapes and confirm no path returns `private_key_pem` or even `client_secret` after the one-time create reveal.
- (e) **C-002 — layer cleanliness.** `bin/check-package-layers` green; `rg` confirms no L2+ imports in oidc source.
- (f) **C-005 — oauth-provider untouched.** `git diff --stat` for the mission's merge MUST show zero changes to `packages/oauth-provider/`.
- (g) **FR-007 — discovery is config-derived.** Reviewer changes `app.url` in a test fixture and confirms the discovery document's `issuer` and endpoint URLs change accordingly.
