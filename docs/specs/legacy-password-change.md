# Required password change after imported-credential proof

Change record: `FW-AUTH-LEGACY-CHANGE-01`.

`auth.legacy_passwords.formats` explicitly enables `phpass` and/or
`wordpress_bcrypt`. `require_change: true` requires accounts with only an
imported hash to choose a different password. A native hash always takes
precedence and never falls back to a legacy value.

Canonical login verifies the old credential, then issues a five-minute,
single-use password-reset capability. This grants neither a session nor a
pending two-factor identity. Its metadata binds the imported credential and
session generation. The normal authentication eligibility policy denies
pending accounts at password/direct login, HTTP session and bearer resolution,
and two-factor promotion. Site HTML presents the canonical JSON response.

Both this proof and emailed forgot-password links use canonical reset. A
different password is required while migration is pending. Reset checks
same-connection participation of canonical SQL User and token repositories,
atomically consumes the token, reloads User, validates proof, saves a native
hash, clears the legacy hash, advances session generation and revokes sibling
tokens. Failure rolls back both writes. No successful-reset login is implicit.
The member signs in with their new password and any configured 2FA still applies.

The opt-in policy changes no native-password login behavior. Canonical reset
now always refuses inactive accounts and provides atomic consumption; the
former direct-construction nontransactional fallback is removed. Consumers
constructing ResetPasswordController must provide LegacyPasswordChange.

WordPress verification follows
[wp_check_password](https://developer.wordpress.org/reference/functions/wp_check_password/).
WordPress bcrypt prehashes with HMAC-SHA384 and uses maintained PHP
`password_verify`; bcrypt cost is bounded to 4..14. Portable phpass retains
the existing bounded verifier. Both cap plaintext at 4,096 bytes. Symfony's
native-password hasher does not implement WordPress's prefixed prehash format;
this small domain-specific verifier introduces no generic hashing framework.
Existing Symfony HTTP responses and the Framework managed DBAL transaction,
token repository, rate limiter and internal-field readers are reused.

This slice covers Framework HTTP authentication. The optional MCP durable-bearer
adapter predates the eligibility policy and does not yet consult it. That
separate boundary is not qualified by this contract. Sheguiandah does not
install `waaseyaa/mcp`; adopting that package requires closing the gap first.

Verification must include both imported formats, wrong credentials, no identity
before completion, different-password refusal, expired/reused proofs, disabled
accounts, credential changes, mismatch stores, after-save rollback, native
accounts, emailed fallback and actual provider/HTML/API wiring.
