# ADR-006: Cross-app identity via OIDC

**Status:** Accepted — 2026-04-17
**Date:** 2026-04-17
**Repos:** waaseyaa/framework, plus one new consumer app repo (dedicated IdP, name TBD, following the `*-waaseyaa` convention)

## 1. Decision

Ecosystem-wide single sign-on is delivered by a dedicated OIDC issuer app and a new framework package.

1. A **new package `waaseyaa/oidc`** provides the OIDC authorization-server primitives (authorization, token, userinfo, discovery, JWKS, revocation endpoints; token issuance; signing-key rotation). It wraps `league/oauth2-server` plus a thin OIDC layer.
2. A **new dedicated IdP app** (own repo, own domain) is the only deployment of `waaseyaa/oidc`. It owns the canonical user store, the sign-up/sign-in UI, and the consent screen.
3. The **existing `waaseyaa/oauth-provider`** package (consumer-side "Sign in with X") gains a **`GenericOidcProvider`** class that federates to any OIDC issuer — our own IdP first, with Google/GitHub continuing to work via the existing concrete providers.
4. Consumer apps (Giiken, Minoo, OIATC, NorthOps, future) drop `waaseyaa/auth`'s local login path in favour of `oauth-provider` → `GenericOidcProvider` pointed at the IdP. Local `User` entities are retained as **JIT-provisioned projections** of the OIDC subject, created/updated on first sign-in from ID-token claims.

## 2. Invariants

1. **There is exactly one canonical user record per person across the ecosystem. It lives in the IdP app.** Consumer-app `User` rows are projections keyed on the OIDC `sub` claim.
2. **Consumer apps never share a database, user table, or session store with each other or with the IdP.** The only cross-app data paths are: (a) OIDC flows via HTTP, (b) events via Redis pub/sub (see forthcoming ADR).
3. **`waaseyaa/oidc` is opt-in.** It is installed by the IdP app only. It is not a dependency of `auth`, `user`, or `access`, and is not bundled into `waaseyaa/framework`'s `replace` block.
4. **`waaseyaa/auth`'s local login path remains available for the IdP app itself** (to avoid recursion), and as an escape hatch for ops/admin bootstrap in any Waaseyaa app. Consumer apps disable it by config once OIDC is wired.

## 3. Motivation

The ecosystem is growing: Giiken, Minoo, OIATC, NorthOps, plus future Waaseyaa apps. Today each app has:

- An independent `users` table (via `waaseyaa/user`).
- Its own login form (via `waaseyaa/auth`).
- `BearerAuthMiddleware` with a single shared HMAC secret — no federation-ready claims, no issuer/audience validation, no refresh-token semantics.

The absence of a shared identity layer would force one of three bad outcomes: (a) every user signs up N times across apps; (b) apps begin reading each other's user tables (the anti-pattern this ADR exists to prevent); (c) ad-hoc "link your accounts" flows accrete per app. Each violates the principle that cross-app integration is identity + events + read-only APIs, nothing else.

OIDC solves this with an industry-standard contract: a single issuer, discoverable via `/.well-known/openid-configuration`, producing signed ID tokens with stable `sub` claims. Every consumer app verifies the same issuer and maps `sub` to a local `User` projection.

## 4. Why OIDC over alternatives

| Alternative | Rejected because |
|---|---|
| **Shared users table across apps** | Violates bounded-context discipline. One app's schema change breaks every other app. This is exactly the failure mode the broader architecture is structured to avoid. |
| **Custom JWT with shared secret (extend `BearerAuthMiddleware`)** | Symmetric-secret JWTs don't scale past two parties. No key rotation story. No discovery. No standard revocation. Would re-invent half of OIDC, worse. |
| **SAML** | Heavier, XML-based, weak mobile/SPA story. OIDC is the de-facto winner for this generation's SSO. |
| **Extend `waaseyaa/auth` with OIDC issuer bits** | `auth` is single-app-scoped (login forms, password reset). Bolting an authorization server on conflates two responsibilities. Consumer apps installing `auth` would pull in issuer code they never use. |
| **Extend `waaseyaa/oauth-provider` with issuer bits** | `oauth-provider` is consumer-side. Making one package both issuer and consumer inverts dependency direction and forces every consumer to ship issuer code. |

OIDC via a dedicated package is the industry standard. GitLab, Atlassian, Shopify, Google Workspace — all do this. The library choice (`league/oauth2-server` + OIDC layer) is proven PHP-ecosystem practice.

## 5. Target shape

### 5.1 `waaseyaa/oidc` package

```
packages/oidc/
├── composer.json       # requires: league/oauth2-server, lcobucci/jwt; path-repo deps on foundation, access, user
├── src/
│   ├── Server/         # AuthorizationServer wiring, grant types, scope/claim mapping
│   ├── Http/           # Route controllers for authorize, token, userinfo, jwks, discovery, revocation, end_session
│   ├── KeyStore/       # Signing-key storage + rotation; DatabaseInterface-backed default
│   ├── Client/         # RegisteredClient entity, client repository
│   ├── Token/          # AccessToken, RefreshToken, AuthCode entities + repositories
│   ├── Scope/          # Scope registry (openid, profile, email, offline_access, per-app scopes)
│   └── Discovery/      # /.well-known/openid-configuration assembly
└── tests/
```

The package exposes a single `OidcServiceProvider` that wires everything. An IdP app's bootstrap is:

```php
// config/packages.php in the IdP app
return [
    \Waaseyaa\Oidc\OidcServiceProvider::class,
];
```

Config (`config/oidc.php`) declares the issuer URL, registered clients, and scope mappings. Secrets come from env per ADR-005.

### 5.2 IdP app

A minimal Waaseyaa app. One job: host the OIDC issuer plus sign-up/sign-in/consent/account-settings UI. Uses `waaseyaa/user` for the canonical user entity, `waaseyaa/auth` for the actual sign-in form (local login, since it can't federate to itself), and `waaseyaa/oidc` for the issuer surface.

### 5.3 `GenericOidcProvider` in `waaseyaa/oauth-provider`

A new class alongside `GoogleOAuthProvider` / `GitHubOAuthProvider`. Constructor takes an issuer URL; it performs OIDC Discovery at boot and implements `OAuthProviderInterface` by translating OIDC flows into the existing interface. ID-token validation (`iss`, `aud`, `exp`, signature against JWKS) happens here.

### 5.4 Consumer adoption pattern

Each app:

1. Installs `waaseyaa/oauth-provider` (already installed for some).
2. Configures a `GenericOidcProvider` instance pointed at the IdP issuer URL.
3. Disables `waaseyaa/auth`'s local login routes in config.
4. On OIDC callback, maps `sub` → local `User` projection (create on first sight, update name/email claims on every sign-in).

`BearerAuthMiddleware` is retained for now for service-to-service API calls. A follow-up ADR replaces it with OIDC-issued access tokens validated against JWKS.

## 6. Out of scope for v1

Explicit non-goals, to prevent scope creep:

- **Multi-tenant realms / organizations.** v1 has a flat user space. A future `waaseyaa/tenant` package and paired ADR will introduce realms when a concrete multi-tenant requirement surfaces (e.g., OIATC hosting multiple Nations with distinct IdP policies).
- **Dynamic client registration (RFC 7591).** Clients are statically configured in the IdP.
- **Federation chaining** (IdP federating upstream to Google/Microsoft). If needed later, the IdP uses `GenericOidcProvider` internally — same primitive.
- **SCIM provisioning.** JIT provisioning from ID-token claims only. SCIM is a v2 concern.
- **M2M service-to-service auth upgrade.** `BearerAuthMiddleware` stays for now. Replacement is a follow-up ADR.

## 7. Migration plan

1. **Scaffold `packages/oidc/`** with `composer.json` (deps: `league/oauth2-server`, `lcobucci/jwt`, path-repo references to `foundation`, `access`, `user`), empty `src/`, empty `tests/`, README. Register in root `composer.json`'s `replace` and `autoload.psr-4` per ADR-004 conventions.
2. **Implement issuer endpoints** in TDD order: discovery → JWKS → authorization code flow → token endpoint → userinfo → revocation → RP-initiated logout. Client repo + token repo backed by `DatabaseInterface`.
3. **Scaffold the IdP app repo** (new GitHub repo, `*-waaseyaa` name). Minimal: `waaseyaa/framework` + `waaseyaa/oidc` + `waaseyaa/auth` (for its own local sign-in) + a sign-up/consent UI.
4. **Add `GenericOidcProvider`** to `packages/oauth-provider/`. Tests against the IdP's issuer in an integration test.
5. **Adopt in Minoo first** (live but no user activity → zero migration cost). Validate end-to-end.
6. **Adopt in Giiken**, then OIATC, NorthOps, etc.
7. **Cut `waaseyaa/oidc` v0.1 release** from the monorepo split once the IdP has been running in prod for a stability window.

## 8. Breaking changes

None for existing users (there are none yet at the cross-app layer). For the framework:

- New package surface (`waaseyaa/oidc`) enters the `replace` block at v0.1; consumers of the framework bundle get it transparently but only the IdP app wires the service provider.
- No changes to `waaseyaa/auth`, `waaseyaa/user`, or `waaseyaa/access` in this ADR.

## 9. Follow-up work enabled

- **ADR-007 (future): `BearerAuthMiddleware` → OIDC access tokens.** Replace shared-HMAC JWTs with JWKS-verified access tokens minted by the IdP, for service-to-service calls.
- **ADR-008 (future): `waaseyaa/events` package.** Lift Redis pub/sub from North Cloud into a reusable framework package with documented topic conventions.
- **ADR-009 (future): `waaseyaa/tenant` package.** Realms/organizations for multi-tenant OIDC, if/when a concrete requirement emerges.
- **Per-app `User` projection contract.** Minor spec documenting the `sub` → local `User` mapping rules that consumer apps implement on OIDC callback.

## 10. Implementation status

This ADR is preserved above as the historical decision record — the text in
§1–§9 is not rewritten to match current code. The `waaseyaa/oidc` issuer
package has since shipped (discovery, JWKS, authorization code with mandatory
PKCE, token and refresh, userinfo, revocation, a consent screen, the signing-
key lifecycle, and encrypted key/token custody), but four target-shape claims
above no longer describe the implementation. Each was verified against the
tree at commit `c674ee734`:

| ADR claim | Section | Current code |
|---|---|---|
| `waaseyaa/oauth-provider` gains a `GenericOidcProvider` class (§5.3, and the consumer adoption pattern in §5.4/§7 step 4) | §5.3, §7 | Does not exist. `packages/oauth-provider/src/Provider/` contains only `GoogleOAuthProvider` and `GitHubOAuthProvider`. Consumer federation and JIT `User` projection remain unbuilt. |
| The package tree includes `Http/` route controllers for, among others, `end_session` (RP-initiated logout) | §5.1 | No `end_session` controller exists. There is no route for it in `packages/routing/src/OidcHttpRoutes.php`, no `end_session` string anywhere in `packages/oidc/src`, and no `end_session_endpoint` in the discovery document. RP-initiated logout is explicitly deferred by the completion spec. |
| `waaseyaa/oidc` "wraps `league/oauth2-server` plus a thin OIDC layer" (§1 item 1, §4 comparison table, §5.1) | §1, §4, §5.1 | No `League\OAuth2\*` type is imported anywhere in `packages/oidc/src`. The dependency is declared in `packages/oidc/composer.json` but unused; the grant, token, and userinfo paths are first-party. (`lcobucci/jwt`, named alongside it in §5.1, is likewise declared but unused — `Token\IdTokenMinter` assembles, signs, and verifies the ID token itself.) |
| The package "enters the `replace` block at v0.1" of the root manifest (§7 step 1, §8) | §7, §8 | The root `composer.json` has no `replace` section. `waaseyaa/oidc` is declared under `require-dev` and is split-published to Packagist from `packages/oidc`, so a production `--no-dev` install of the framework metapackage does not pull it in. |

None of these are protocol-behaviour changes; they are corrections to this
record's target-shape description. For current, code-verified capability —
including the capability matrix, HTTP route table, configuration keys, and
secrets-at-rest custody model — see
[`packages/oidc/README.md`](../../packages/oidc/README.md), which is the
authority this ADR now defers to. `docs/upgrade-notes/oidc-secrets-at-rest.md`
covers the `oidc:migrate-secrets` upgrade path.
