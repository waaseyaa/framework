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

OIDC secret persistence is rooted in `WAASEYAA_APP_SECRET` through distinct,
versioned HKDF-SHA-256 purposes:

- RSA private keys use sodium secretbox with a fresh nonce and a strict
  `secretbox.hkdf-v1:` envelope. Public-key material remains directly readable.
- Opaque access and refresh tokens use separate secretbox encryption keys and
  separate HMAC-SHA-256 lookup keys. Exact lookup uses the keyed lookup column;
  the bearer value is returned only after its encrypted envelope authenticates.
- Issuer signing uses the encrypted database repository unless file keys are
  explicitly configured. Database key configuration or decryption errors are
  propagated and do not select another provider.

Existing installations run `bin/waaseyaa oidc:migrate-secrets --confirm` in
maintenance mode after taking a trusted backup. The command converts signing
keys, access tokens, and refresh tokens in one transaction. Runtime readers
accept only authenticated envelopes; there is no ongoing plaintext read mode.
See `docs/upgrade-notes/oidc-secrets-at-rest.md` for the bounded procedure.

See [ADR-006](../../docs/adr/006-cross-app-identity-via-oidc.md) for full context.

## Status

**Scaffold only.** Implementation lands in follow-up PRs, TDD order per ADR-006 §7: discovery → JWKS → authorization code flow → token → userinfo → revocation → logout.

## Stack

- [`league/oauth2-server`](https://oauth2.thephpleague.com/) — OAuth 2.0 authorization server
- [`lcobucci/jwt`](https://github.com/lcobucci/jwt) — ID token JWT assembly

## License

GPL-2.0-or-later.
