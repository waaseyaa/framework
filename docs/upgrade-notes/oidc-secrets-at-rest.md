# OIDC secrets-at-rest migration

Encrypted OIDC material — signing private keys, opaque access tokens, opaque
refresh tokens — is persisted through `Waaseyaa\Oidc\Security\SecretBoxEnvelope`.
There are **two envelope formats**. Which one a given process *writes* depends
on the custody `Waaseyaa\Oidc\OidcServiceProvider::runtimeCustody()` selects at
boot, not on anything in this note. Both are described below; pick the
migration steps that match your installation's wiring.

## Which custody does this installation use?

- **Keyring-backed (application-master envelopes).** `runtimeCustody()`
  resolves `Waaseyaa\Foundation\Security\ApplicationMasterKeyring` with
  `resolveOptional()`. If your application binds that service — the `oidc`
  package does not bind it itself — signing keys and tokens are sealed as
  `waaseyaa.application-master.envelope.v1` JSON, authenticated with
  XChaCha20-Poly1305 IETF AEAD over the master version, purpose, record
  identity and schema version. There is no `secretbox.hkdf-v1:` prefix on this
  format.
- **No bound keyring (legacy `secretbox.hkdf-v1:` envelopes).** If nothing
  binds `ApplicationMasterKeyring`, custody falls back to a key derived from
  `WAASEYAA_APP_SECRET` through a per-record-class HKDF-SHA-256 purpose, and
  `seal()` writes `secretbox.hkdf-v1:` followed by base64url of a nonce and an
  XSalsa20-Poly1305 secretbox ciphertext. This is the older format and remains
  what an installation with no bound keyring writes today.

`open()` dispatches on the `secretbox.hkdf-v1:` prefix, so a process needs
whichever key produced a row to read it back. Setting
`oidc.accept_legacy_application_secret_material` to `true` keeps the derived
legacy key available alongside a bound keyring, so a keyring-backed process can
still open pre-existing legacy rows — it does not change what that process
*writes*; new material is still sealed as an application-master envelope. That
flag re-introduces the `WAASEYAA_APP_SECRET` dependency a bound keyring
otherwise removes, and this repository states no policy for how long it may
stay enabled. Under either format, opaque access and refresh tokens carry
separate encryption keys and separate HMAC-SHA-256 lookup keys.

## Before you upgrade

- If this installation has no bound `ApplicationMasterKeyring`: set the same
  canonical `WAASEYAA_APP_SECRET` on every issuer process. Changing it
  invalidates every persisted legacy envelope and its keyed lookup value, with
  no re-encryption or rotation tooling provided by that path alone.
- If this installation binds an `ApplicationMasterKeyring`: master-version
  rotation for OIDC records is covered by dedicated rekey adapters
  (`Rekey\OidcSigningKeyRekeyAdapter`, `Rekey\OidcAccessTokenRekeyAdapter`,
  `Rekey\OidcRefreshTokenRekeyAdapter`) against the application-master rekey
  coordinator, not by this note.
- Either way: take and verify a trusted database backup, stop issuer traffic
  and background work, and apply pending migrations before running the command
  below.

## Running the migration

```sh
bin/waaseyaa oidc:migrate-secrets --confirm
```

This runs `Waaseyaa\Oidc\Security\LegacyOidcSecretMigrator` over
`oidc_signing_key`, `oidc_access_token` and `oidc_refresh_token` inside a
single `oidc_secret_storage_migration` transaction, rolling back on any error
and reporting per-table counts. It **normalizes** stored custody rather than
only converting plaintext. Given custody material able to read a row, each row
is handled as follows:

- **Plaintext** signing keys (PEM) and plaintext tokens are sealed under the
  custody the runtime currently selects. Signing-key material that is neither
  a recognized envelope nor a PEM private key is refused, as is an empty token
  value.
- **`secretbox.hkdf-v1:` envelopes** are opened and resealed under the
  selected custody — this is how a keyring-backed installation converts
  legacy rows to application-master envelopes.
- **Application-master envelopes whose master version is not the active
  version** are opened and resealed at the active version. For tokens, the
  keyed lookup index is re-derived too, so a row whose ciphertext is already
  current but whose `token_lookup` is not is still migrated.
- **Rows already at the active version are skipped.** When no keyring is
  bound, existing legacy envelopes are likewise skipped rather than rewritten.
  For the two token tables, this skip is evaluated only for rows that already
  carry a `token_lookup` value — a row without one is resealed and its lookup
  index written.

Every update carries a compare-and-set condition on the row's prior stored
value; a concurrent modification aborts the migration instead of overwriting
it. A `token_lookup` that does not correspond to its token is refused. The
command is idempotent for rows already at the active version/format and
refuses unrecognized or inconsistent material.

Resume issuer traffic only after the command succeeds.

## Runtime read requirement

Regardless of custody, runtime reads require a valid authenticated envelope —
there is no ongoing plaintext read mode. This is why the pre-upgrade backup and
maintenance window above are not optional: a row that cannot be opened under
the custody a process has available is unreadable to that process until
`oidc:migrate-secrets` normalizes it (legacy) or a rekey adapter run brings it
to the active master version (application-master).

See `packages/oidc/README.md` under "Security: secrets at rest" for the
authoritative, longer-form description this note is kept in sync with.
