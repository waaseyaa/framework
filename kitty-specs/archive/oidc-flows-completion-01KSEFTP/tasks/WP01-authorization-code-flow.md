---
work_package_id: "WP01"
title: "Authorization-code flow (code issuance + token exchange)"
dependencies: []
requirement_refs:
  - "FR-001"
  - "FR-002"
  - "NFR-001"
  - "NFR-002"
  - "C-001"
  - "C-002"
  - "C-003"
  - "C-004"
  - "C-005"
planning_base_branch: "main"
merge_target_branch: "main"
branch_strategy: "Planning artifacts for this mission were generated on main. During implement, this WP may branch from a dependency-specific base, but completed changes must merge back into main unless redirected explicitly."
subtasks:
  - "T001"
  - "T002"
  - "T003"
  - "T004"
phase: "Phase 1 - Substrate"
assignee: ""
agent: ""
shell_pid: ""
authoritative_surface: "packages/oidc/src/Authorize"
execution_mode: "code_change"
owned_files:
  - "packages/oidc/migrations/2026_05_25_000001_oidc_authorization_code_schema.php"
  - "packages/oidc/migrations/2026_05_25_000002_oidc_token_schema.php"
  - "packages/oidc/src/Authorize/AuthorizeController.php"
  - "packages/oidc/src/Token/TokenController.php"
  - "packages/oidc/src/Token/AccessTokenIssuer.php"
  - "packages/oidc/src/Token/IdTokenIssuer.php"
  - "packages/oidc/src/Token/KeyMaterialProviderInterface.php"
  - "packages/oidc/src/Token/InMemoryKeyMaterialProvider.php"
  - "packages/oidc/src/Oidc/Config/OidcIssuerConfig.php"
  - "packages/oidc/src/OidcServiceProvider.php"
  - "packages/oidc/tests/Unit/Authorize/AuthorizeControllerTest.php"
  - "packages/oidc/tests/Unit/Token/TokenControllerTest.php"
  - "packages/routing/src/AuthOidcRouteServiceProvider.php"
history: []
---

# WP01 — Authorization-code flow (code issuance + token exchange)

**Mission:** `oidc-flows-completion-01KSEFTP`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd <path printed by `spec-kitty agent action implement WP01`>
```
The lane worktree has no `vendor/`; run `composer install` first.

## THE pattern to mirror (read these before writing anything)

- READ `packages/oidc/src/Authorize/AuthorizationRequestValidator.php` + `ValidatedAuthorizationRequest.php` — they already validate the inbound request; you only need to wire the controller around them.
- READ `packages/oidc/src/Token/TokenRequestValidator.php` — same: it validates token requests; you wire the controller around it.
- READ `packages/oidc/src/ClientRegistry/OidcClientLookup.php` + `OidcClientAccessPolicy.php` — the client identity surface (do NOT reimplement client lookup).
- READ `packages/auth/src/Controller/LoginController.php` — the session-aware controller pattern (how an L1 controller reads `_account` from the request).
- READ `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — the string-FQCN route registration style.
- READ `packages/routing/src/AuthOidcRouteServiceProvider.php` — where the new oidc routes register (it already lifts auth + existing oidc surfaces to L4).
- READ `packages/oidc/migrations/2026_04_26_000001_oidc_client_schema.php` — the table style + naming conventions to follow for the new migrations.

## Subtasks

**T001 — Schema: authorization code + token tables**
- `migrations/2026_05_25_000001_oidc_authorization_code_schema.php` — table `oidc_authorization_code` with PK `code` (UUID string), columns `client_id`, `redirect_uri`, `scope`, `account_id`, `code_challenge`, `code_challenge_method` (enum, S256 only), `nonce` nullable, `auth_time` int, `expires_at` int, `used_at` int nullable. Index `(client_id, used_at)`.
- `migrations/2026_05_25_000002_oidc_token_schema.php` — tables `oidc_access_token` (`jti` UUID PK, `client_id`, `account_id`, `scope`, `expires_at`, `revoked_at` nullable) and `oidc_refresh_token` (`jti` UUID PK, `access_token_jti` FK, `client_id`, `account_id`, `scope`, `auth_time`, `expires_at`, `revoked_at` nullable, `chain_root_jti` UUID — initialised to the first refresh in the chain, preserved across rotations).

**T002 — Controllers + issuers**
- `src/Authorize/AuthorizeController.php` — flesh out `__invoke(Request)`: call existing validator; if `_account` is missing → redirect to login URL with `return_to`; if consent stub returns false → leave a `// TODO(WP05)` and proceed; persist a row in `oidc_authorization_code` and redirect to `redirect_uri?code=...&state=...`. Reject `response_type != "code"` with `unsupported_response_type` (C-004). Reject missing/`plain` `code_challenge_method` (C-003).
- `src/Token/TokenController.php` — `__invoke(Request)`: parse `grant_type`. WP01 implements `authorization_code` only (WP02 adds refresh). Authenticate client via Basic header / POST body / public-client PKCE-only (`none`). Validate via `TokenRequestValidator`. Verify PKCE: `base64url(SHA256(verifier)) === code_challenge`. Atomic single-use: `UPDATE oidc_authorization_code SET used_at = now() WHERE code = ? AND used_at IS NULL` — if 0 rows affected → `invalid_grant`. Issue tokens via `AccessTokenIssuer` + `IdTokenIssuer`. Return RFC 6749 JSON.
- `src/Token/AccessTokenIssuer.php` — constructor takes `KeyMaterialProviderInterface`, `OidcIssuerConfig`, `DatabaseInterface`. `issue(clientId, accountId, scope): AccessTokenPair { jti, jwt, expiresIn }`. JWT payload `{iss, sub, aud, jti, exp, iat, scope}` signed RS256.
- `src/Token/IdTokenIssuer.php` — constructor takes the same. `issue(clientId, accountId, authTime, nonce): string`. Claims `{iss, sub, aud, exp, iat, auth_time, nonce?}`.
- `src/Token/KeyMaterialProviderInterface.php` — `@api`. `currentKey(): KeyMaterial { kid, privatePem, publicPem }`, `allActive(): array`.
- `src/Token/InMemoryKeyMaterialProvider.php` — generates a single RS256 keypair at construction; for WP01 and test use only. Replaced by `RealKeyMaterialProvider` in WP04.
- `src/Oidc/Config/OidcIssuerConfig.php` — readonly DTO `{issuerUrl, accessTokenTtlSeconds, refreshTokenTtlSeconds, codeTtlSeconds}`. Loaded from the active config store in `OidcServiceProvider`.

**T003 — Service provider + routes**
- `src/OidcServiceProvider.php` — register entity types `oidc_client` (already done — leave), add `oidc_authorization_code`, `oidc_access_token`, `oidc_refresh_token`. Bind `KeyMaterialProviderInterface` → `InMemoryKeyMaterialProvider` placeholder. Add the SP to `composer.json` `extra.waaseyaa.providers` if not present.
- `packages/routing/src/AuthOidcRouteServiceProvider.php` — register:
  - `oidc.authorize` → `GET|POST /oidc/authorize`, controller `'Waaseyaa\\Oidc\\Authorize\\AuthorizeController::__invoke'`, `_session: true`.
  - `oidc.token` → `POST /oidc/token`, controller `'Waaseyaa\\Oidc\\Token\\TokenController::__invoke'`, `_public: true` (client auth is inside the controller).

**T004 — Tests**
- `tests/Unit/Authorize/AuthorizeControllerTest.php` — happy path with PKCE S256; `response_type=token` rejected; `code_challenge_method=plain` rejected; missing-account → redirect to login; valid path persists a code row.
- `tests/Unit/Token/TokenControllerTest.php` — auth-code happy path returns expected JSON shape (access_token, refresh_token, id_token, expires_in, token_type=Bearer, scope); reusing a consumed code → `invalid_grant`; mismatched client_id → `invalid_client`; bad PKCE verifier → `invalid_grant`; unsupported `grant_type` → `unsupported_grant_type`.

## Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/oidc/tests/ packages/routing/tests/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `rg -n 'use Waaseyaa\\(Api|Admin|GraphQL|MCP)' packages/oidc/src` returns **nothing** (C-002).
6. `rg -n "response_type.*token|response_type.*id_token" packages/oidc/src/Authorize/AuthorizeController.php` returns **nothing** (C-004 — only `code` flows through).

## Commit + handoff

- Commits (footer `Mission: oidc-flows-completion-01KSEFTP`):
  - `feat(oidc): authorization code + token schemas`
  - `feat(oidc): authorize controller (auth-code flow, PKCE S256)`
  - `feat(oidc): token controller (authorization_code grant) + RS256 issuers`
  - `feat(routing): /oidc/authorize + /oidc/token routes`
- Then:
  ```
  spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission oidc-flows-completion-01KSEFTP
  spec-kitty agent tasks move-task WP01 --to for_review --mission oidc-flows-completion-01KSEFTP --note "auth-code happy path + PKCE S256 enforced + single-use codes. Refresh + revoke land in WP02; JWKS lands in WP04."
  ```

## Report back with
1. Commit SHAs.
2. Confirmation that `rg 'Waaseyaa\\(Api|Admin|GraphQL|MCP)' packages/oidc/src` is empty (C-002).
3. The exact PKCE rejection messages on `code_challenge_method=plain` and missing `code_challenge`.
4. The full JSON response body for a successful `POST /oidc/token` with the auth-code grant.
5. Confirmation that re-POSTing the same `code` returns `{error: "invalid_grant"}` and not the original token pair.

## Activity Log
- 2026-05-25T05:29:40Z – unknown – subagent shipped code retroactively
- 2026-05-25T05:30:13Z – unknown – code already committed: 221ac1248..a7240b47f
- 2026-05-25T05:30:23Z – unknown – Opus review: all 5 OIDC WPs cleanly committed; gates pass; DIR-004 userinfo field-access wiring confirmed; subagent self-corrected DatabaseInterface::getConnection() per CLAUDE.md gotcha
