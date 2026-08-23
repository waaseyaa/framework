# Auth token HMAC key derivation

Reset, email-verification, and invite tokens are HMAC-SHA256 of a
single-use plaintext. The HMAC key is no longer allowed to be the raw
application-master secret.

## What operators must know

- A valid explicit `AUTH_TOKEN_SECRET` / `auth.token_secret` (trimmed, at
  least 32 characters, not a published placeholder such as `change-me`)
  remains an independent override.
- If that key is omitted or empty, Waaseyaa derives
  `waaseyaa.auth.token-hmac.v1` from `WAASEYAA_APP_SECRET` through
  `ApplicationSecret`. Stock skeletons do not need a second secret.
- Explicit invalid values fail closed in every environment. They never
  become an ephemeral random key.
- Downstream apps that previously signed tokens with raw `app_secret`
  bytes will not validate those outstanding tokens after upgrade.
  Plaintext is not stored, so the hashes cannot be rehashed. The longest
  default TTL is seven days (invites). Users must request a new reset,
  verification, or invite after deploy.
- Parser/environment-access consolidation is tracked on #2479, not this
  change.

## Rotation

Rotating `WAASEYAA_APP_SECRET` invalidates derived auth-token HMACs the
same way. Rotating only `AUTH_TOKEN_SECRET` invalidates tokens that used
the explicit override. Outstanding rows expire or can be pruned; they
cannot be migrated.

Which mode you are in decides whether outstanding tokens can block an
application-master rotation at all:

- **Derived custody** (no explicit key). Outstanding tokens are signed with
  material owned by the application master, so `waaseyaa/auth` contributes a
  drain adapter and a rotation waits for them to drain or expire. The longest
  wait is the invite TTL, seven days.
- **Explicit independent custody** (a valid `AUTH_TOKEN_SECRET`). The signing
  key has no relationship to the application master, so rotating the master
  cannot invalidate a single outstanding token. `waaseyaa/auth` contributes
  nothing to the rotation and outstanding tokens never block it. If you
  previously saw a rotation held up by outstanding tokens while running an
  independent secret, that was the defect fixed here, not a real dependency.

An explicit value that is rejected as invalid is not treated as absent. It
fails closed, so a mistyped secret can neither quietly fall back to derived
custody nor quietly remove the rotation guard.
