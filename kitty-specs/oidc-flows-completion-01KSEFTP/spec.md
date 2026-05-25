# OIDC Flows Completion — finish the issuer (auth-code, token, userinfo, JWKS, discovery, client-reg UI)

**Mission:** `oidc-flows-completion-01KSEFTP`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub umbrella issue at filing time — the mission is the source-of-truth for execution. File a tracking issue only if Russell wants public visibility on the unblock signal.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape; M4B `QueueController` / `QueueAdminApiRouter` for the router + `ApiServiceProvider::httpDomainRouters()` shape; `packages/auth/src/Controller/LoginController.php` for session-aware HTTP controllers; `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` for route registration via string-FQCN at L0; `packages/routing/src/AuthOidcRouteServiceProvider.php` for the auth/oidc → L4 route lift.

## Why this mission exists

`packages/oidc` is **scaffold-only** as of alpha.188. Inventory shows it carries: a client schema migration (`2026_04_26_000001_oidc_client_schema.php`), an `OidcClientAccessPolicy`, an `AuthorizationRequestValidator` + `AuthorizeController` skeleton, a `TokenRequestValidator`, and a `ClientRegistry/OidcClientLookup` — but **no end-to-end flow wiring, no userinfo, no JWKS, no discovery, no signing-key storage, no admin client-registration UI**.

The alpha-to-beta plan calls this out as a Wave-2 substrate item: **"Finishing OIDC unblocks every other Tsen'awt component"** — every consumer app in the ecosystem (Giiken, Minoo, OIATC, NorthOps, Anokii) federates to a single Waaseyaa-issued IdP via `waaseyaa/oauth-provider`'s `GenericOidcProvider`. Until the IdP issues real ID tokens against real JWKS, no consumer app can be wired for SSO, and the substrate-hardening cluster cannot ratify cross-app identity (gap-matrix row A2).

This mission ships the missing endpoints, the userinfo claim resolver that respects field-access policies (per DIR-004), the JWKS + discovery surface, and an admin SPA page to register OIDC clients (replaces today's CLI-only / direct-DB workflow). It does NOT change the IdP deployment topology (single dedicated IdP app), does NOT modify `waaseyaa/oauth-provider` (consumer side), and does NOT introduce dynamic client registration (RFC 7591) — clients are still administered through the SPA.

## The cross-package constraint (read before designing)

`packages/oidc` is **Layer 1 (Core Data)**. Routes live in **Layer 4** (`packages/api` / `packages/routing`). Route registration for `waaseyaa/auth` and `waaseyaa/oidc` already lifts up to L4 via `Waaseyaa\Routing\AuthOidcRouteServiceProvider` — that is the lane this mission extends. The L1 oidc package keeps:

- Controllers (act on L1-resolved services; the HTTP entry crosses layers in the route registrar, not the controller import).
- Validators, request/response value types, signing-key storage, JWKS publisher, discovery document builder.
- Userinfo claim assembler, parameterised by an injected `AccountInterface` source (from session/bearer) and an `EntityAccessHandler` resolved against the `User` entity (downward to access — L1 → L1, allowed).

The admin SPA page (WP05) lives in `packages/admin/` (L6, Nuxt) and consumes a JSON:API CRUD endpoint exposed via `packages/api` (L4). The CRUD endpoint reads/writes the `oidc_client` entity registered by the oidc service provider — same pattern as every other admin entity surface.

## Scope

### In scope

**WP01 — Authorization-code flow (auth-code grant):**
- Wire `AuthorizeController::__invoke()` end-to-end: validate request via existing `AuthorizationRequestValidator`, persist a one-shot `oidc_authorization_code` row (UUID code, client_id, redirect_uri, scope, account_id, code_challenge, code_challenge_method, expires_at — PKCE S256 mandatory for public clients), redirect to `redirect_uri` with `code` + `state`.
- `POST /oidc/token` with `grant_type=authorization_code`: validate against existing `TokenRequestValidator`, exchange code → ID token (RS256) + access token + refresh token; mark code consumed (`used_at` set, single-use enforced); return RFC 6749 JSON.
- Reuse the existing access-token substrate (oauth-provider tokens table or oidc-local equivalent — decision: oidc owns its own token storage to keep the issuer self-contained, mirroring `oidc_client` schema lineage).

**WP02 — Refresh + revocation (token endpoint variants):**
- `POST /oidc/token` with `grant_type=refresh_token`: rotate refresh token (issue new pair, mark prior pair revoked), re-issue ID token with new `iat`/`exp`, preserve original `auth_time` claim.
- `POST /oidc/revoke` per RFC 7009: accept `token` + `token_type_hint` (`access_token` | `refresh_token`), revoke matching token + cascade to its refresh pair on access-token revocation, return 200 even for unknown tokens (per spec to prevent enumeration).
- Token introspection (`POST /oidc/introspect`, RFC 7662) is **out of scope** — consumer apps validate JWTs locally via JWKS.

**WP03 — Userinfo endpoint (DIR-004: response respects field-access):**
- `GET /oidc/userinfo` (and `POST` per spec): authenticate the bearer access token, load the `User` entity for the token's subject, build the response claim set scoped to the token's granted scopes (`openid` → `sub`; `profile` → `name`, `preferred_username`, `updated_at`; `email` → `email`, `email_verified`; `address` → `address`; `phone` → `phone_number`, `phone_number_verified`).
- **Each claim is filtered through `FieldAccessPolicyInterface` against the requesting account** (= the token-bound account, not the IdP service account). A field that returns Forbidden is omitted from the response — never serialised as `null` or `""`. This is the DIR-004 binding point: the OCAP-by-architecture commitment applies to OIDC claims, not just framework-internal serialisers.
- Response is `application/json` per spec; framework's JSON:API envelope does NOT wrap this — OIDC consumers expect bare claim objects.

**WP04 — JWKS + discovery + signing-key storage:**
- Signing-key storage: `oidc_signing_key` entity with `kid`, `algorithm` (RS256), `private_key_pem` (encrypted at rest via `Waaseyaa\Foundation\Crypto` if present; otherwise raw with a documented follow-up), `public_key_pem`, `created_at`, `rotated_out_at` nullable. Rotation policy: keep current + previous (for in-flight token verification); a CLI command `waaseyaa oidc:rotate-signing-key` adds a new current and rotates the previous out.
- `GET /.well-known/jwks.json` returns the JWKS document (current + previous, public-key components only). `Cache-Control: public, max-age=86400`.
- `GET /.well-known/openid-configuration` returns the discovery document with `issuer`, `authorization_endpoint`, `token_endpoint`, `userinfo_endpoint`, `jwks_uri`, `revocation_endpoint`, `end_session_endpoint`, `scopes_supported`, `response_types_supported` (`code` only — implicit + hybrid out), `grant_types_supported` (`authorization_code`, `refresh_token`), `subject_types_supported` (`public`), `id_token_signing_alg_values_supported` (`RS256`), `token_endpoint_auth_methods_supported` (`client_secret_basic`, `client_secret_post`, `none` for PKCE), `code_challenge_methods_supported` (`S256`).

**WP05 — Admin SPA: OIDC client registration UI:**
- `/admin/oidc/clients` Nuxt page: list, create, edit, delete OIDC clients. Fields: `name`, `client_id` (read-only after creation), `client_secret` (shown once on creation, then `[hidden — regenerate to reveal]`), `redirect_uris` (multi-value), `allowed_scopes` (multi-select from supported scopes), `confidential` boolean, `consent_screen_text` (markdown text shown on the consent screen).
- CRUD endpoint at `/api/oidc-clients` registered via `packages/api` JSON:API controllers (one new controller + router per the M4B/M4A-5 pattern), `_role: admin`.
- Consent screen template: when a logged-in user hits `/oidc/authorize` for a client they have not yet consented to, render a Twig template (`packages/oidc/templates/consent.html.twig`) showing client name + requested scopes + consent_screen_text + Approve/Deny buttons. Approval records a `oidc_user_consent` row keyed by `(account_id, client_id, scope_set_hash)`; subsequent authorize requests for the same scope set skip the screen. Deny redirects with `error=access_denied`.

**Docs:**
- `docs/specs/access-control.md` — stamp + new "OIDC userinfo + field-access" section (cross-references DIR-004).
- `packages/oidc/README.md` — replace the "Non-goals (v1)" line with a "Status (alpha.NEXT)" section listing the now-complete endpoint set + remaining out-of-band items.
- `CHANGELOG.md` `[Unreleased]` → **Added**: five entries (one per WP), each footer `Refs <issue-if-filed>` or mission slug.

### Out of scope

- Dynamic client registration (RFC 7591). Clients are administered via the SPA only.
- Token introspection (RFC 7662) — consumers verify JWTs locally via JWKS.
- Hybrid + implicit flow response types — only `code` is supported. PAR (RFC 9126) and FAPI conformance are deferred.
- RP-initiated logout end-session endpoint (`/end_session`) — listed in README but defers to a follow-up; the consent screen suffices for v1 SSO.
- Multi-tenant IdP partitioning — single issuer per deployment.
- Encrypted ID tokens (JWE). JWS RS256 only.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | `AuthorizeController` validates auth requests via `AuthorizationRequestValidator`, persists an `oidc_authorization_code` row with PKCE `code_challenge` + `S256` method, and redirects to `redirect_uri` with `code` + `state` parameters preserved verbatim. Single-use enforced via `used_at`. |
| FR-002 | functional | `POST /oidc/token` with `grant_type=authorization_code` exchanges a valid, unconsumed, unexpired code for `{access_token, token_type=Bearer, expires_in, refresh_token, id_token, scope}`. ID token is signed RS256 using the current signing key, with claims `iss, sub, aud, exp, iat, auth_time, nonce` (nonce echoed if present in the auth request). |
| FR-003 | functional | `POST /oidc/token` with `grant_type=refresh_token` issues a new token pair, rotates the refresh token (old marked revoked), and re-issues an ID token preserving the original `auth_time`. Re-use of a revoked refresh token returns `invalid_grant` and revokes the entire token chain (theft mitigation). |
| FR-004 | functional | `POST /oidc/revoke` accepts `token` + optional `token_type_hint`, revokes the matching token, cascades access→refresh revocation when an access token is revoked, returns 200 with empty body for both known and unknown tokens (RFC 7009 §2.2). |
| FR-005 | functional | `GET /oidc/userinfo` authenticates the bearer access token, loads the subject `User` entity, and returns scope-filtered claims as bare JSON (`Content-Type: application/json`, NOT `application/vnd.api+json`). Each claim is gated by `FieldAccessPolicyInterface::fieldAccess()` against the token-bound `AccountInterface` — Forbidden claims are omitted entirely from the response (DIR-004 binding). |
| FR-006 | functional | `GET /.well-known/jwks.json` returns a JWKS document containing the current + previous signing keys (public components only: `kty`, `kid`, `use=sig`, `alg=RS256`, `n`, `e`). `Cache-Control: public, max-age=86400`. |
| FR-007 | functional | `GET /.well-known/openid-configuration` returns a discovery document with exactly the metadata fields enumerated in spec.md "Scope → WP04". The `issuer` value matches the application's configured public URL. No metadata field is hard-coded that should be derived from configuration. |
| FR-008 | functional | `bin/waaseyaa oidc:rotate-signing-key` rotates the active signing key: generates a new RS256 keypair, sets it current, marks the previous current as `rotated_out_at = now()`. Older keys (rotated_out before current-current) are deleted. JWKS endpoint reflects the change on the next request (no warm-up required). |
| FR-009 | functional | `/admin/oidc/clients` Nuxt page lists clients, supports create (with one-time client_secret reveal), edit (`name`, `redirect_uris`, `allowed_scopes`, `consent_screen_text`, `confidential`), regenerate-secret, and delete. CRUD endpoint at `/api/oidc-clients` is `_role: admin`. |
| FR-010 | functional | Consent screen renders for a logged-in user hitting `/oidc/authorize` for a `(client_id, scope_set)` combination not already in `oidc_user_consent`. Approve writes the consent row and proceeds with the redirect; Deny redirects with `error=access_denied&error_description=user_denied_consent&state=<verbatim>`. |
| FR-011 | functional | Integration test: end-to-end auth-code flow with a real `User`, real client (created via the CRUD endpoint), real consent grant, real JWKS, real userinfo call. Verifies: (a) the id_token verifies against JWKS public key, (b) userinfo returns only allowed claims for a `User` with a `FieldAccessPolicyInterface` that forbids `email`, (c) revoke + re-use of refresh token fails with `invalid_grant`. |
| NFR-001 | non-functional | All HTTP error responses follow the OAuth/OIDC `application/json` shape `{error: "...", error_description: "..."}` — NOT the JSON:API error envelope. Authorize-redirect errors append `error` + `error_description` + `state` to `redirect_uri`. |
| NFR-002 | non-functional | Bearer-token validation rejects expired, revoked, malformed, or non-JWT-signed tokens with `WWW-Authenticate: Bearer error="invalid_token"` and HTTP 401 — never silently 200. |
| NFR-003 | non-functional | Signing-key private material is never logged, never serialised in any admin SPA response, never returned by any read endpoint. JWKS exposes public components only. |
| NFR-004 | non-functional | The userinfo field-access gate runs against the token-bound account, not the IdP service account. Reviewer verifies by reading the controller and confirming no `AccountInterface` is constructed from a system identity. |
| C-001 | constraint | Endpoints respond in `application/json` for non-redirect responses (auth flow). No JSON:API envelope on OIDC-spec endpoints. The admin CRUD endpoint at `/api/oidc-clients` follows the framework JSON:API pattern and IS wrapped — it is a separate surface. |
| C-002 | constraint | Layer-clean: `packages/oidc` (L1) does not import from L2+ packages at runtime. Route registration uses string FQCN through `AuthOidcRouteServiceProvider`. No `use Waaseyaa\Api\…` in oidc source. |
| C-003 | constraint | PKCE S256 is mandatory for public clients (confidential=false). Confidential clients MAY use PKCE; the validator accepts both. `plain` code_challenge_method is rejected. |
| C-004 | constraint | Only `response_type=code` is supported. Implicit (`token`, `id_token`), hybrid (`code token`, `code id_token`, etc.) return `unsupported_response_type`. |
| C-005 | constraint | The mission does NOT modify `waaseyaa/oauth-provider`. Consumer-side integration is verified by reading its `GenericOidcProvider` against this issuer's discovery document — not by editing it. |

## Acceptance

- All 11 FRs met.
- All NFRs met; C-002 verified by `bin/check-package-layers` green and `rg -n 'use Waaseyaa\\(Api|Admin|GraphQL|MCP)' packages/oidc/src` empty.
- All gates green: `vendor/bin/phpunit` (mission scope), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green; new vitest coverage for the OIDC clients composable + page.
- FR-011 integration test demonstrably exercises every endpoint added by WP01–WP04 with a single realistic flow. The test is the regression anchor for downstream consumer-app SSO work.
- `docs/specs/access-control.md` carries the DIR-004 userinfo binding section + a fresh `<!-- Spec reviewed <date> - oidc-flows-completion-01KSEFTP - ... -->` stamp.

## Risks

- **Field-access bypass on userinfo (primary).** If the userinfo controller resolves an `AccountInterface` from an IdP service identity instead of the token-bound account, every userinfo response leaks every claim regardless of `FieldAccessPolicyInterface`. NFR-004 + FR-011 are the explicit guard; reviewer MUST read the controller line that builds the account context and confirm it is the token subject.
- **PKCE downgrade.** Accepting `code_challenge_method=plain` (or missing) for any client class would re-introduce a known auth-code interception vector. C-003 + a unit test on `AuthorizationRequestValidator` enforcing rejection are non-negotiable.
- **Refresh-token replay.** If a revoked refresh token's re-use only fails the new exchange without revoking the chain, a leaked refresh token remains useful. FR-003's chain-revocation is the standard mitigation; integration test FR-011 covers this path.
- **Signing-key leakage.** Private key material in logs / SPA payloads / error pages is a credential disclosure. NFR-003 is the rule; reviewer greps for `private_key_pem` references in controllers, serialisers, and admin payload builders.
- **Discovery document drift.** Hard-coding `issuer` / endpoint URLs in code instead of deriving from configuration means re-deploys to different domains silently break consumers. FR-007's "no hard-coding" clause is the guard; the discovery integration test asserts that changing the app URL config changes the returned `issuer`.
- **Layer creep via admin CRUD.** The `/api/oidc-clients` controller lives in L4 (`packages/api`) and reads/writes the `oidc_client` entity registered by L1 oidc. That direction (L4 → L1) is layer-clean. The risk is the reverse: any new oidc → api `use` violates C-002.

## Decisions pre-resolved

- **OIDC owns its own token storage.** Even though `waaseyaa/oauth-provider` exists for the consumer side, the IdP keeps a self-contained `oidc_access_token` / `oidc_refresh_token` schema to avoid bidirectional coupling with the consumer-side provider. Minimises vendor lock-in (any external IdP could replace this layer without touching consumer code).
- **GraphQL is not used for OIDC client CRUD.** Per the parallel `api-surface-consolidation-jsonapi-primary-01KSEFTV` mission, JSON:API is the primary admin API surface. OIDC clients are administered via JSON:API.
- **Inertia is not used for the OIDC client UI.** Per DIR-007 and the parallel `inertia-demotion-nuxt-standardisation-01KSEFTS` mission, the Nuxt SPA is the workspace UI.
- **Consent is per-(account, client, scope-set), not per-(account, client).** A client requesting a previously-unconsented scope re-prompts. This is the OIDC-spec behaviour and prevents scope-expansion attacks against pre-consented clients.
- **RP-initiated logout (`/end_session`) deferred.** Out-of-band follow-up. The consent screen does not block SSO completion in v1.

## Decisions deferred to implementer

- The exact JWT library (firebase/php-jwt vs lcobucci/jwt vs framework-vendored). Pick the smallest-surface option already in the dependency graph; document the choice in WP04 commit message.
- Encryption-at-rest for signing-key private material: if `Waaseyaa\Foundation\Crypto` (or equivalent) exists, use it; if not, store raw with an inline `// TODO(security-defaults): encrypt private_key_pem once Crypto service lands` and file an out-of-band issue.
- Whether to surface a "Revoke all tokens" admin action in the SPA — defer to a follow-up if it would add UI scope beyond WP05's CRUD list.

Decision preference order (per charter): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- RP-initiated logout (`/end_session`) — follow-up mission to add the endpoint + admin SPA "Sign out everywhere" action.
- Token introspection (`/introspect`, RFC 7662) — file only if a consumer cannot validate JWTs locally.
- Encrypted ID tokens (JWE) — file when a consumer requires it.
- Multi-tenant IdP partitioning — open question, defer until a deployment requires it.
- Encryption-at-rest for signing-key private material if the Crypto service is not present at implementation time.
