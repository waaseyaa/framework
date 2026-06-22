# waaseyaa/oidc

OpenID Connect issuer for the Waaseyaa ecosystem.

This package provides the authorization-server primitives used by a dedicated IdP app to act as the single sign-on provider for every Waaseyaa app (Giiken, Minoo, OIATC, NorthOps, etc.). Consumer apps do **not** install this package — they federate to the IdP via [`waaseyaa/oauth-provider`](../oauth-provider/)'s `GenericOidcProvider`.

## Scope

- Authorization endpoint (`/authorize`)
- Token endpoint (`/token`)
- UserInfo endpoint (`/userinfo`)
- Discovery (`/.well-known/openid-configuration`)
- JWKS (`/.well-known/jwks.json`)
- Revocation (`/revoke`)
- RP-initiated logout (`/end_session`)
- Signing-key storage + rotation

## Non-goals (v1)

- Multi-tenant realms
- Dynamic client registration (RFC 7591)
- SCIM provisioning
- Federation chaining

## Security: secrets at rest

Two categories of long-lived secrets are persisted by this package, and both are
stored **unencrypted** at rest by design in v1:

- **Opaque access tokens** (`oidc_access_token.token`, `AccessTokenIssuer`). The
  value is a 256-bit random string and is looked up by exact value
  (`findByOpaqueToken`), so the at-rest column must match the bearer string.
  Tokens are short-lived (1h) and revocable.
- **RSA signing private keys** (`oidc_signing_key.private_key_pem`,
  `SigningKeyRepository`). The PEM must be available in plaintext at signing
  time (`IdTokenMinter`), and keys rotate (current + one previous).

**Threat model:** confidentiality of these secrets relies on confidentiality of
the database file/connection (filesystem permissions, disk encryption, network
TLS, and DB access control) — the same trust boundary as the rest of the entity
store. A read of the DB discloses live bearer tokens and signing keys.

Hashing tokens at rest and KMS/app-key-encrypting the signing keys are tracked
hardening (audit D-13); they are deferred because token hashing must be applied
consistently across the access- and refresh-token stores and key encryption
requires app-key/KMS bootstrap and encryption-key rotation — out of scope for a
single-IdP v1. Do not weaken the DB trust boundary in the meantime.

See [ADR-006](../../docs/adr/006-cross-app-identity-via-oidc.md) for full context, invariants, and migration plan.

## Status

**Scaffold only.** Implementation lands in follow-up PRs, TDD order per ADR-006 §7: discovery → JWKS → authorization code flow → token → userinfo → revocation → logout.

## Stack

- [`league/oauth2-server`](https://oauth2.thephpleague.com/) — OAuth 2.0 authorization server
- [`lcobucci/jwt`](https://github.com/lcobucci/jwt) — ID token JWT assembly

## License

GPL-2.0-or-later.
