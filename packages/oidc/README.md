# waaseyaa/oidc

**Layer 1 — Core Data**

OpenID Connect issuer (authorization server) for the Waaseyaa ecosystem.

This package holds the issuer primitives. The HTTP route table lives in Layer 4
(`Waaseyaa\Routing\OidcHttpRoutes`, wired by
`Waaseyaa\Routing\AuthOidcRouteServiceProvider`) so this Layer 1 package carries
no routing types. Operator commands live in `waaseyaa/cli`; the client
administration JSON:API lives in `waaseyaa/api`; the Admin SPA client screens
live in `packages/admin/app/pages/oidc/clients/`.

## Status

The issuer flows are implemented in this repository. The former **Scaffold
only** status line was stale: it described the package as of alpha.188, before
the completion mission landed.

That status is not a claim of production readiness. This repository contains no
OpenID Foundation certification suite and no deployment evidence. What it
contains is executable coverage: package tests under `packages/oidc/tests/` and
kernel HTTP/CLI tests under `tests/Integration/Oidc/`. Passing package tests are
evidence that the implemented behaviour is pinned, not that a given deployment
is a certified or operated identity provider.

## Three surfaces that are frequently collapsed

| Surface | State |
|---|---|
| **1. This package (`waaseyaa/oidc`)** | Implemented issuer. Capability matrix below. |
| **2. [`biindigen-waaseyaa`](https://github.com/waaseyaa/biindigen-waaseyaa)** | Separate application repository for the dedicated IdP (canonical user store, sign-in UI). Its README still reads **Scaffold only**; the repository was created 2026-04-17 and has had no push since its initial scaffold commit. Operating an issuer is that application's job, not this package's. |
| **3. Consumer federation** | **Missing.** ADR-006 planned a `GenericOidcProvider` on `waaseyaa/oauth-provider` plus just-in-time `User` projections from the ID-token `sub`. Neither exists. |

An audit that reads ADR-006 and concludes generic OIDC support is unavailable
has the two halves inverted: the *issuer* is implemented, and the *consumer
adapter* is the part that is missing.

### `GenericOidcProvider` does not exist

`packages/oauth-provider/src/Provider/` contains exactly two classes,
`GoogleOAuthProvider` and `GitHubOAuthProvider`. The package is a consumer-side
"Sign in with X" abstraction (`OAuthProviderInterface`, `ProviderRegistry`,
`OAuthStateManager`, `OAuthToken`, `OAuthUserProfile`); it exposes no generic
OIDC discovery/JWKS client. Every `GenericOidcProvider` string in this
repository is in a planning document — ADR-006 and the archived mission spec —
never in code.

Installing `waaseyaa/oidc` makes an application an issuer, not a relying party.
An application that needs to *consume* this issuer must currently bring its own
OIDC client.

## Capability matrix

| Capability | Status | Implementation |
|---|---|---|
| Discovery `GET /.well-known/openid-configuration` | Implemented | `Discovery\DiscoveryController`, `Discovery\DiscoveryDocumentBuilder`. Advertises authorize, token, userinfo, jwks and revocation endpoints. Does **not** advertise `end_session_endpoint`. |
| JWKS `GET /.well-known/jwks.json` | Implemented | `Jwks\JwksController`, `Jwks\JwksDocumentBuilder`. Public key components only. |
| Authorization code `GET /oidc/authorize` | Implemented | `Authorize\AuthorizeController`, `Authorize\AuthorizationRequestValidator`. `response_type=code` only; scope must contain `openid`. |
| PKCE | **Required, `S256` only** | `code_challenge` is mandatory (`invalid_request` when absent) and `code_challenge_method` must be exactly `S256`; any other value, including `plain`, is rejected. `Token\PkceVerifier` recomputes the challenge and compares with `hash_equals`. Discovery advertises `code_challenge_methods_supported: ["S256"]`. |
| Token `POST /oidc/token` | Implemented | `Token\TokenController` with `Token\TokenRequestValidator`; `refresh_token` is dispatched to `Token\RefreshTokenGrantHandler`. Confidential clients authenticate with HTTP Basic or `client_secret_post`; public clients rely on PKCE alone. |
| Refresh rotation | Implemented | `Token\RefreshTokenIssuer` rotates on use. Replaying a revoked refresh token cascade-revokes the whole chain and logs a warning (RFC 6819 §5.2.2.3). |
| UserInfo `GET`/`POST /oidc/userinfo` | Implemented | `Userinfo\UserinfoController`. Access tokens are opaque, not JWTs: the bearer value is looked up in the persisted `oidc_access_token` row with revocation and expiry enforced. Every claim is gated through `FieldAccessPolicyInterface`; forbidden claims are omitted rather than erroring. |
| Revocation `POST /oidc/revoke` | Implemented | `Revoke\RevocationController` (RFC 7009). Unknown, missing and foreign tokens all return `200` so the endpoint is not an enumeration oracle. Revoking an access token cascades to its refresh token; the reverse does not cascade (RFC 7009 §2.1). |
| Consent `GET`/`POST /oidc/consent` | Implemented | `Consent\ConsentScreenController` and `Consent\ConsentRepository`. The route is not CSRF-exempt and the controller additionally verifies `_csrf_token`. Approval records a row in `oidc_user_consent` and issues the code; denial redirects with `error=access_denied`. The screen is inline HTML, not a Twig template (the class docblock still says Twig and is stale). |
| Client registry | Implemented | `oidc_client` entity, `ClientRegistry\OidcClientLookup`, `ClientRegistry\OidcClientSeeder` for the `oidc.clients` config block, `OidcClientAccessPolicy`. Admin JSON:API CRUD at `/api/oidc-clients` (`Waaseyaa\Api\Controller\OidcClientController`); Admin SPA screens under `packages/admin/app/pages/oidc/clients/`. This is **not** RFC 7591 dynamic registration. |
| Signing-key lifecycle | Implemented | `Key\SigningKeyRepository`, `Key\RealKeyMaterialProvider`, `Key\SigningKeyLifecyclePolicy`, `Key\SigningKeyEmergencyRevocationService`. Stage, propagate, activate, clean up and emergency-revoke are separate audited transitions with their own CLI commands. Issuer signing binds `KeyMaterialProviderInterface` to `Key\RealKeyMaterialProvider` over that repository unconditionally; no configuration redirects issuer signing to files. `Keys\PemFileKeyLoader` is bound separately as `OidcKeyLoaderInterface` (shaped by `oidc.signing_keys` or `OIDC_SIGNING_KEY_DIR`) and is reachable by explicit callers through `Token\InMemoryKeyMaterialProvider`, but nothing in the issuer signing path resolves it. |
| Encrypted key and token custody | Implemented, two envelope formats | Which format a process writes depends on whether an `ApplicationMasterKeyring` is bound: a keyring-backed runtime writes application-master envelopes, and a runtime without a bound keyring writes `secretbox.hkdf-v1:` envelopes. The package binds no keyring itself and resolves it optionally, so neither format is universal. See [Security: secrets at rest](#security-secrets-at-rest). Opaque access and refresh tokens carry separate encryption keys and separate HMAC-SHA-256 lookup keys under either format. `ext-sodium` is a hard requirement. |
| Migrations | Implemented | Eight migrations under `packages/oidc/migrations/`, listed below. |
| RP-initiated logout / `end_session` | **Absent** | No controller, no route, no `end_session` string anywhere in `packages/oidc/src` or `packages/routing/src`, and no `end_session_endpoint` in the discovery document. Explicitly deferred by the completion spec. |
| Token introspection (RFC 7662) | Absent | Declared out of scope by the completion spec; relying parties validate ID tokens against JWKS. |
| Implicit, hybrid, device and client-credentials grants | Absent | `response_types_supported` is `["code"]`; `grant_types_supported` is `["authorization_code", "refresh_token"]`. |
| Consumer federation (`GenericOidcProvider`, JIT `User` projection) | Absent | See above. |

The discovery document also reports `subject_types_supported: ["public"]`,
`id_token_signing_alg_values_supported: ["RS256"]`, `scopes_supported:
["openid", "profile", "email"]` and `token_endpoint_auth_methods_supported:
["client_secret_basic", "client_secret_post", "none"]`.

`Userinfo\UserinfoClaimResolver` maps `address` and `phone` scopes to claims,
but no `User` field backs them, so those claims are omitted and neither scope is
advertised in discovery.

## HTTP routes

Registered by `packages/routing/src/OidcHttpRoutes.php`:

| Route | Methods | Notes |
|---|---|---|
| `/.well-known/openid-configuration` | `GET` | Always registered |
| `/.well-known/jwks.json` | `GET` | Always registered |
| `/oidc/authorize` | `GET` | Registered only when `AuthorizeController` resolves |
| `/oidc/token` | `POST` | CSRF-exempt; registered only when `TokenController` resolves |
| `/oidc/revoke` | `POST` | CSRF-exempt; registered only when `RevocationController` resolves |
| `/oidc/userinfo` | `GET`, `POST` | CSRF-exempt; registered only when `UserinfoController` resolves |
| `/oidc/consent` | `GET`, `POST` | Not CSRF-exempt; registered only when `ConsentScreenController` resolves |

There is no `/end_session` route under any prefix.

## Configuration

| Setting | Resolution order |
|---|---|
| Issuer URL | `oidc.issuer` config, then the `OIDC_ISSUER` environment variable, then `http://localhost:8000` |
| Login redirect | `oidc.login_path` config, then `/login` |
| Static clients | `oidc.clients` config, seeded on boot. Removing an entry from config is non-destructive; it does not delete the client row. |

## Schema

Migrations in `packages/oidc/migrations/`:

- `2026_04_26_000001_oidc_client_schema`
- `2026_05_25_000002_oidc_token_schema`
- `2026_05_25_000003_oidc_signing_key_schema`
- `2026_05_25_000004_oidc_user_consent_schema`
- `2026_07_15_000005_oidc_secret_storage`
- `2026_08_12_000006_oidc_authorization_code_schema`
- `2026_08_15_000007_oidc_signing_key_lifecycle`
- `2026_08_15_000008_oidc_application_master_custody`

They create `oidc_client`, `oidc_access_token`, `oidc_refresh_token`,
`oidc_signing_key`, `oidc_user_consent`, `oidc_authorization_codes`,
`oidc_signing_key_version_sequence` and `oidc_signing_key_revocation`.

## Operator commands

Registered by `Waaseyaa\CLI\Provider\OidcServiceProvider`:

- `oidc:init-signing-key`
- `oidc:stage-signing-key`
- `oidc:record-signing-key-propagation`
- `oidc:activate-signing-key`
- `oidc:cleanup-signing-keys`
- `oidc:emergency-revoke-signing-key`
- `oidc:migrate-secrets`

## Security: secrets at rest

Encrypted OIDC material (signing private keys, opaque access tokens, opaque
refresh tokens) is persisted through `Security\SecretBoxEnvelope`. There are two
envelope formats, and which one a given process **writes** depends on the
custody `OidcServiceProvider::runtimeCustody()` selects. Public-key material
remains directly readable in both.

### Keyring-backed: application-master envelopes

When an `ApplicationMasterKeyring` is bound, `runtimeCustody()` selects it and
derives no application-secret key. `SecretBoxEnvelope::seal()` then writes a
JSON `Foundation\Security\ApplicationMasterEnvelope`
(`waaseyaa.application-master.envelope.v1`) sealed with XChaCha20-Poly1305 IETF
AEAD. Each envelope records its master version, purpose, record identity and
schema version, and those fields are authenticated as associated data. Opening
re-checks purpose, record identity and schema version exactly before returning
plaintext. This format carries **no** `secretbox.hkdf-v1:` prefix.

`OidcServiceProvider` resolves the keyring with
`resolveOptional(ApplicationMasterKeyring::class)` and binds none itself. No
default binding was found anywhere in this repository outside tests, so whether
an installation gets this format depends on how that application wires its
secret resolver.

Master-version rotation is covered by dedicated rekey adapters for signing keys
and both token tables (`Rekey\OidcSigningKeyRekeyAdapter`,
`Rekey\OidcAccessTokenRekeyAdapter`, `Rekey\OidcRefreshTokenRekeyAdapter`),
which implement snapshot, batched transition, verification and rollback against
the application-master rekey coordinator.

### Without a bound keyring: `secretbox.hkdf-v1:` envelopes

When no keyring is bound, custody falls back to a 32-byte key derived from the
`ApplicationSecret` (rooted in `WAASEYAA_APP_SECRET`) through a distinct,
versioned HKDF-SHA-256 purpose per record class. `seal()` then writes
`secretbox.hkdf-v1:` followed by base64url of a fresh nonce and an
`XSalsa20-Poly1305` secretbox ciphertext. This is the older of the two formats,
and it is what a runtime with no bound keyring writes today.

### Compatibility and reading

`open()` dispatches on the `secretbox.hkdf-v1:` prefix: prefixed values take the
legacy path, everything else is decoded as an application-master envelope. A
process therefore needs the legacy key to read legacy rows.

Setting `oidc.accept_legacy_application_secret_material` to `true` keeps that
derived legacy key alongside the keyring, so a keyring-backed process can still
open pre-existing `secretbox.hkdf-v1:` rows. It does not change the write
format: a keyring-backed process still seals new material as an
application-master envelope. Without that flag, a bound keyring means legacy
envelopes cannot be opened. Setting it does re-introduce the
`WAASEYAA_APP_SECRET` dependency that a bound keyring otherwise removes. This
repository states no policy on how long an installation may leave the flag
enabled.

Under either format, opaque access and refresh tokens use separate encryption
keys and separate HMAC-SHA-256 lookup keys. Exact lookup uses the keyed lookup
column; the bearer value is returned only after its encrypted envelope
authenticates. Runtime readers accept only authenticated envelopes; there is no
ongoing plaintext read mode.

### Normalizing stored custody

`bin/waaseyaa oidc:migrate-secrets --confirm` runs
`Security\LegacyOidcSecretMigrator` over `oidc_signing_key`,
`oidc_access_token` and `oidc_refresh_token` inside a single
`oidc_secret_storage_migration` transaction, rolling back on any error and
returning per-table counts. Run it in maintenance mode after taking a trusted
backup.

It normalizes rather than only encrypting. Given custody material able to read
the stored value, each row is treated as follows:

- **Plaintext** signing keys (PEM) and tokens are sealed with the custody the
  runtime selected. Signing-key material that is neither an envelope nor a PEM
  private key is refused, as is an empty token value.
- **`secretbox.hkdf-v1:` envelopes** are opened and resealed under the selected
  custody, which is how a keyring-backed installation converts legacy rows.
- **Application-master envelopes whose master version is not the active
  version** are opened and resealed at the active version. For tokens the keyed
  lookup index is re-derived too, so a row whose ciphertext is already current
  but whose `token_lookup` is not is still migrated.
- **Rows already at the active version** are skipped, and when no keyring is
  bound existing envelopes are skipped rather than rewritten. For the two token
  tables this skip is evaluated only for rows that already carry a
  `token_lookup` value; a row without one is resealed and its lookup index
  written.

Each update is applied with a compare-and-set condition on the previously
stored value, and a concurrent modification aborts the migration rather than
overwriting it. A `token_lookup` that does not correspond to its token is
refused.

[`docs/upgrade-notes/oidc-secrets-at-rest.md`](../../docs/upgrade-notes/oidc-secrets-at-rest.md)
describes the original plaintext-to-secretbox upgrade only. It is written
against the `WAASEYAA_APP_SECRET` model and does not describe application-master
custody or the resealing behaviour above; the behaviour described here comes
from `LegacyOidcSecretMigrator` itself.

## Deferred and out of scope

- RP-initiated logout / `end_session` (deferred by the completion spec to a
  follow-up mission)
- Token introspection (RFC 7662)
- Dynamic client registration (RFC 7591)
- Multi-tenant realms
- SCIM provisioning
- Federation chaining
- PAR (RFC 9126), FAPI profiles, encrypted (JWE) ID tokens

## Distribution

`waaseyaa/oidc` is split-published to Packagist from `packages/oidc` and is
**not** a dependency of the `waaseyaa/core`, `waaseyaa/cms` or `waaseyaa/full`
metapackages. In the root `waaseyaa/framework` manifest it appears under
`require-dev`, so a production `--no-dev` install of the framework metapackage
does not pull it in. An application that wants to run an issuer requires
`waaseyaa/oidc` explicitly.

(ADR-006 §7 instructed registering the package in a root `replace` block. The
root manifest has no `replace` section; `require-dev` plus the split workflow is
what actually ships.)

## Tests

Kernel HTTP and CLI coverage (subprocess kernel, `#[CoversNothing]`):

- `tests/Integration/Oidc/OidcDiscoveryIntegrationTest.php`
- `tests/Integration/Oidc/OidcJwksIntegrationTest.php`
- `tests/Integration/Oidc/OidcAuthorizeIntegrationTest.php`
- `tests/Integration/Oidc/OidcTokenIntegrationTest.php`
- `tests/Integration/Oidc/SigningKeyLifecycleCliTest.php`
- `tests/Integration/Oidc/Fixtures/token_flow_runner.php`

Package coverage under `packages/oidc/tests/Unit/` and
`packages/oidc/tests/Integration/` spans the authorize validator, token
controller and request validator, PKCE verifier, ID-token minter, userinfo,
consent, revocation, JWKS, client registry and seeding, authorization-code
repository, runtime schema authority, secret storage, signing-key lifecycle and
application-master rekeying. No test in these suites is skipped or incomplete.

Route wiring is covered by `packages/routing/tests/Unit/OidcHttpRoutesTest.php`
and `packages/routing/tests/Unit/AuthOidcRouteServiceProviderTest.php`; the CLI
roster by `packages/cli/tests/Unit/Provider/OidcServiceProviderTest.php`.

## Architecture records

[ADR-006](../../docs/adr/006-cross-app-identity-via-oidc.md) is the originating
decision record. Several of its target-shape details are now stale against the
code: it names `GenericOidcProvider`, includes an `end_session` controller in
the planned layout, describes the package as wrapping `league/oauth2-server`,
and directs registration through a root `replace` block. Prefer this README and
the live route table for current capability.

The completion mission is archived at
[`kitty-specs/archive/oidc-flows-completion-01KSEFTP/spec.md`](../../kitty-specs/archive/oidc-flows-completion-01KSEFTP/spec.md).
Its opening inventory describes the package as scaffold-only *as of alpha.188*;
that paragraph is the historical starting point, not a description of current
`main`. The same spec is the authority for the deferrals listed above.

## Stack

- [`lcobucci/jwt`](https://github.com/lcobucci/jwt) — still declared in
  `packages/oidc/composer.json` from ADR-006, but no `Lcobucci\*` type is
  imported anywhere in `packages/oidc/src`. `Token\IdTokenMinter` assembles the
  ID token itself: it base64url-encodes a JSON header and claim set, signs the
  joined input through the active `SigningKeySignerInterface`, and verifies with
  `openssl_verify()` against the published key set.
- [`league/oauth2-server`](https://oauth2.thephpleague.com/) — likewise declared
  from ADR-006, but no `League\OAuth2\*` type is imported anywhere in
  `packages/oidc/src`; the grant, token and userinfo paths are first-party.
- `ext-sodium` — required for both custody formats (XChaCha20-Poly1305 IETF for
  application-master envelopes, secretbox for legacy ones).

## License

GPL-2.0-or-later.
