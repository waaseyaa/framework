# Two-Factor Authentication

Status: shipped (mission `two-factor-end-to-end-01KRW8TN`, 2026-05-18). Layer 1 (auth) + 4 (routing).

## Architectural intent

Waaseyaa ships TOTP (RFC 6238) + recovery-code 2FA as a real, opt-in feature for every User. The framework owns the primitives + the HTTP contract; consumer apps decide when to surface it in their UI.

A user can enable 2FA, receive a one-time set of 8 recovery codes, log in with username+password+TOTP, fall back to a recovery code (consumed on use), and disable 2FA atomically with proof-of-possession. No second-factor token is issued at login until the TOTP/recovery step completes.

## Storage

Two `#[Field]`-annotated public properties on `Waaseyaa\User\User`:

| Field | Type | Semantics |
|---|---|---|
| `two_factor_secret` | `?string` (Base32) | TOTP shared secret. `null` ↔ 2FA disabled. |
| `two_factor_recovery_codes_hash` | `?list<string>` (Argon2id hashes) | One-time recovery codes. Hashed; plaintext is displayed exactly once at setup. `null` ↔ 2FA disabled. |

Both persist through the entity-storage `_data` JSON blob mechanism — **no schema migration**. Existing User rows load cleanly: missing keys default to `null`/`null`.

Encryption-at-rest for the TOTP secret is deferred to a follow-up; v1.0 stores Base32 plaintext. Recovery codes are hashed with Argon2id (matches `User::setRawPassword`).

## Service surface (`Waaseyaa\Auth\TwoFactorService`, `@api`)

```php
final class TwoFactorService
{
    public function setup(User $user): TwoFactorSetupResult;
    public function enable(User $user, string $secret, list<string> $plaintextRecoveryCodes, string $firstCode): bool;
    public function verify(User $user, string $code): bool;
    public function disable(User $user): void;
    public function isEnabled(User $user): bool;
}
```

- `setup` generates secret + 8 recovery codes + QR URI. **Does NOT persist.** Throws `RuntimeException` if 2FA is already enabled.
- `enable` verifies `firstCode` matches `secret` via `TwoFactorManager::verifyCode`. On success, hashes the recovery codes with Argon2id and writes the User to storage.
- `verify` tries TOTP first via `verifyCode`. On miss, iterates the user's stored hashes and calls `password_verify`. A successful recovery match removes that hash from the list and re-persists the User. Returns `false` when 2FA is not enabled or `$code === ''`.
- `disable` sets both fields to `null` and persists.
- `isEnabled` is a getter check (`getTwoFactorSecret() !== null`).

The companion readonly value object:

```php
final readonly class TwoFactorSetupResult
{
    public function __construct(
        public string $secret,        // Base32 TOTP secret
        public string $qrCodeUri,     // otpauth://totp/... for QR generation
        public array $recoveryCodes,  // list<string>; 8 plaintext codes
    ) {}
}
```

## HTTP contract

All four routes register in `Waaseyaa\Routing\AuthOidcRouteServiceProvider` (the L4 host for L1 auth/oidc per AGENTS.md), with `allowAll()` middleware — controllers gate themselves on `_account`.

### POST /api/auth/2fa/setup

Initiates setup. Authenticated session required.

```
Request:  (no body)
Response 200:
{
  "jsonapi": { "version": "1.1" },
  "data": {
    "type": "two-factor-setup",
    "attributes": {
      "secret": "JBSWY3DPEHPK3PXP...",
      "qr_code_uri": "otpauth://totp/Waaseyaa:alice@example.com?secret=...",
      "recovery_codes": ["abcde-fghij", "klmno-pqrst", ...] // 8 codes, plaintext, displayed ONCE
    }
  }
}
Response 409 (already enabled):
{ "jsonapi": {...}, "errors": [{ "status": "409", "title": "Already Enabled" }] }
```

Nothing is persisted by this call. The client must echo the secret + recovery_codes back to /enable.

### POST /api/auth/2fa/enable

Confirms setup with a TOTP proof-of-possession. Authenticated session required.

```
Request: { "secret": "...", "recovery_codes": ["...", ...], "first_code": "123456" }
Response 200: { ..., "data": { "type": "two-factor", "attributes": { "enabled": true } } }
Response 401 (wrong first_code): { ..., "errors": [{ "status": "401", "title": "Invalid Code" }] }
Response 400 (missing fields):   { ..., "errors": [{ "status": "400", "title": "Bad Request" }] }
```

### POST /api/auth/2fa/verify

Two dispatch modes — distinguished by request state:

1. **Pending login completion.** `_account` is NOT set; `$_SESSION['waaseyaa_pending_2fa_uid']` carries the user's UID (placed there by `LoginController` after password verification when 2FA is enabled). The controller loads the user, verifies the submitted code, and on success promotes the session to a full login (`waaseyaa_uid` set, pending key cleared, `session_regenerate_id(true)` called).

2. **Authenticated re-verify.** `_account` is a `User` instance. Used to re-confirm 2FA before sensitive operations (e.g. disable).

```
Request: { "code": "123456" }                   // TOTP, OR
Request: { "code": "abcde-fghij" }              // recovery code
Response 200: { ..., "data": { "type": "two-factor", "attributes": { "verified": true } } }
Response 401 (wrong code):    rate-limit budget consumed.
Response 429:                  Retry-After: 60 (5 failed attempts per IP per 60s under `2fa-verify:` namespace)
Response 400 (no 2FA enabled): error envelope.
```

Successful verifications do NOT consume the rate-limit budget.

### POST /api/auth/2fa/disable

Wipes credentials atomically; requires a valid TOTP or unused recovery code as proof-of-possession.

```
Request:  { "code": "123456" }
Response 200: { ..., "data": { "type": "two-factor", "attributes": { "enabled": false } } }
Response 401 (no/wrong code):  credentials NOT wiped.
Response 400 (no 2FA enabled): error envelope.
```

## Login flow

`LoginController` after password verifies:

```
if (TwoFactorService::isEnabled($user)) {
    $_SESSION['waaseyaa_pending_2fa_uid'] = $user->id();
    // No session_regenerate_id, no waaseyaa_uid set.
    return 200 { data: { type: 'auth', attributes: { state: '2fa_required', pending_user_id: <uid> } } };
}
// existing happy path: waaseyaa_uid set, session regenerated, full credentials returned.
```

Backward-compat: users without 2FA see no behavior change.

## Rate limiting

`VerifyTwoFactorController` uses the existing `RateLimiterInterface` (the same backend as `LoginController`) under a distinct key prefix `2fa-verify:` so verify failures do NOT count against login attempts and vice versa. Limit: 5 failed attempts per IP per 60 seconds. On the 6th attempt the controller returns `429 Too Many Requests` with `Retry-After: 60`.

## Invariants

- A User has at most one TOTP secret at a time.
- Recovery codes are hashed; plaintext is visible exactly once.
- `enable` is atomic: secret + recovery codes commit in a single save.
- `disable` wipes both fields atomically.
- A recovery code is consumed on first successful match (removed from the user's stored list and re-persisted).
- TOTP verification window: ±1 30-second step (RFC 6238 tolerance), implemented in `TwoFactorManager::verifyCode`.
- TOTP and recovery comparisons use `hash_equals` (TOTP) and `password_verify` (recovery) — constant-time.

## Out of scope (deferred)

- Encryption-at-rest for the TOTP secret.
- WebAuthn / FIDO2 / passkeys.
- SMS / push 2FA.
- Admin-side "force 2FA for all users" enforcement policy.
- UI in `packages/admin/`.

## References

- Mission: `kitty-specs/two-factor-end-to-end-01KRW8TN/`
- Primitives: `packages/auth/src/TwoFactorManager.php`
- Service: `packages/auth/src/TwoFactorService.php`
- Controllers: `packages/auth/src/Controller/{Setup,Enable,Verify,Disable}TwoFactorController.php`
- Routes: `packages/routing/src/AuthOidcRouteServiceProvider.php`
- DI bindings: `packages/auth/src/AuthServiceProvider.php`
- E2E: `tests/Integration/PhaseTwoFactor/TwoFactorE2ETest.php`
- Closes: GitHub #1499
