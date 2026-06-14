# Auth System — Phase 2: Registration, Password Reset, Email Verification

**Date:** 2026-03-30
**Status:** Approved
**Scope:** User registration (configurable modes), password reset with mail policy, email verification with configurable gating
**Depends on:** Auth Phase 1 (login, session, admin plugin 401 handling)

## 1. Overview

Phase 2 adds three auth flows to the Waaseyaa framework: user registration, password reset, and email verification. All three share a unified token infrastructure (`AuthTokenRepository`) and follow the BFF pattern established in Phase 1 — sessions are the sole auth credential, tokens never reach the browser except as single-use URL parameters.

### Design Principles

- **Configurable by default** — registration mode, email verification gating, and mail policy are all configurable with safe defaults.
- **Infrastructure first** — shared token repository, config system, and mail policy are built before any flow, avoiding duplication.
- **Anti-enumeration** — all user-facing responses are generic. The system never reveals whether an account exists.
- **Dev-friendly** — development mode logs token URLs when mail isn't configured, so local dev works without a mailer.

## 2. Shared Infrastructure

### 2.1 AuthTokenRepository

Replaces `PasswordResetTokenRepository` (which uses raw PDO). Implemented against `DatabaseInterface` (DBAL).

**Schema** (`auth_tokens` table):

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK AUTO | Row ID |
| `user_id` | TEXT NULL | NULL for invite tokens (no user yet) |
| `token_hash` | TEXT NOT NULL | HMAC-SHA256 of plain token |
| `type` | TEXT NOT NULL | `password_reset`, `email_verification`, `invite` |
| `created_at` | INTEGER NOT NULL | Unix timestamp |
| `expires_at` | INTEGER NOT NULL | Unix timestamp |
| `consumed_at` | INTEGER NULL | Set on consumption |
| `meta` | TEXT NULL | JSON blob for context (invite role, IP, etc.) |
| `created_by` | TEXT NULL | Admin user ID for invite issuance |

**Indexes**: `(token_hash)`, `(user_id, type)`

**API**:

```php
interface AuthTokenRepositoryInterface
{
    /** Returns plain token string. Stores only the HMAC-SHA256 hash. */
    public function createToken(int|string|null $userId, string $type, int $ttlSeconds, ?array $meta = null): string;

    /** Returns ['id' => int, 'user_id' => string|null, 'meta' => ?array] or null. */
    public function validateToken(string $plainToken, string $type): ?array;

    /** Marks token as consumed. Returns void. */
    public function consumeToken(int $tokenId): void;

    /** Revokes all tokens for a user, optionally filtered by type. */
    public function revokeTokensForUser(int|string $userId, ?string $type = null): void;

    /** Deletes expired tokens. Returns count deleted. */
    public function pruneExpired(): int;
}
```

**Hashing**: HMAC-SHA256 with a server secret (`auth.token_secret` config key). Secret auto-generated on first boot if not set. Plain tokens are 64-char hex strings (`bin2hex(random_bytes(32))`). Raw tokens are never persisted or logged (except dev-mode URL logging).

**Token types and TTLs** (configurable):

| Type | Default TTL | Notes |
|---|---|---|
| `password_reset` | 1 hour | Single-use, revokes previous tokens for same user |
| `email_verification` | 24 hours | Single-use, revokes previous tokens for same user |
| `invite` | 7 days | Single-use, `user_id` is NULL, `meta` contains invited email/role |

### 2.2 Configuration

Added to `config/waaseyaa.php`:

```php
'auth' => [
    'registration' => 'admin',           // 'admin' | 'open' | 'invite'
    'require_verified_email' => false,    // true = block unverified from AdminShell
    'mail_missing_policy' => null,        // null = auto (dev-log in dev, fail in prod)
    'token_secret' => env('AUTH_TOKEN_SECRET', ''),
    'token_ttl' => [
        'password_reset' => 3600,
        'email_verification' => 86400,
        'invite' => 604800,
    ],
],
```

**`mail_missing_policy` resolution**:

| Config value | Behavior |
|---|---|
| `null` (default) | `dev-log` when `APP_ENV` is `local`/`development`; `fail` otherwise |
| `'dev-log'` | Generate token, log URL via `error_log()`, return generic 200 |
| `'fail'` | Return 503 `{"error": "mail_not_configured"}` |
| `'silent'` | Return generic 200, no email, no log (opt-in only) |

**Runtime warning**: When `require_verified_email` is `true` but mail driver is not configured in production, log a warning at boot time.

### 2.3 User Entity Changes

Add `email_verified` field to User entity type in `UserServiceProvider`:

```php
'email_verified' => [
    'type' => 'boolean',
    'label' => 'Email verified',
    'description' => 'Whether the user has verified their email address.',
    'weight' => 6,
],
```

Default: `0` for open registration, `1` for invite-consumed and admin-created users.

Include `email_verified` in the `/_surface/session` response payload.

### 2.4 Rate Limiting

All auth endpoints use the existing `RateLimiter` (to be cache-backed in future per #768):

| Endpoint | Limit |
|---|---|
| `POST /api/auth/register` | 5 per IP per 15 min |
| `POST /api/auth/forgot-password` | 3 per email per 15 min, 10 per IP per hour |
| `POST /api/auth/reset-password` | 10 per IP per hour |
| `POST /api/auth/verify-email` | 10 per IP per hour |
| `POST /api/auth/resend-verification` | 3 per user per hour |
| `POST /api/auth/invite` | 10 per admin per hour |

## 3. Registration Flow

### 3.1 Backend

**Endpoint**: `POST /api/auth/register`

**Request**: `{"name": "...", "email": "...", "password": "...", "invite_token": "..."}`

**Behavior by mode**:

| Mode | Behavior |
|---|---|
| `admin` | Returns 403. Admins create users via entity CRUD. |
| `open` | Creates user with `status: 1`. Sends verification email if mail configured. Sets `email_verified: 0`. |
| `invite` | Requires valid `invite_token`. Consumes token. Creates user with `status: 1`, `email_verified: 1` (invite proves email). |

**Validation**:
- Name: required, 2-255 chars
- Email: required, valid format, unique (query via `EntityRepository::findBy`)
- Password: required, min 8 chars
- Response never reveals "email already exists" — returns generic 422 shape

**On success**: Returns `201 {"user": {"id": ..., "name": ..., "email": ...}}`. Auto-logs the user in (sets session cookie). Sends welcome email if mail configured.

### 3.2 Admin SPA — Registration Page

**`/register` page** — only rendered when `auth.registration` is `open` or `invite`:

- Split Panel layout matching Phase 1's login page, CSS variable theming
- Fields: name, email, password, confirm password
- Invite mode: token extracted from `?token=` query param, submitted as hidden field
- On success: redirect to dashboard (or `/verify-email` if `require_verified_email` is true and mode is `open`)
- Footer link: "Already have an account? Sign in"

**Admin invite management** (when `auth.registration` is `invite`):

- "Invite User" button on Users list page
- Modal: email field, role selector
- Calls `POST /api/auth/invite` → creates `invite` token, emails link
- Invite list visible to admins with status (pending/consumed/expired)

### 3.3 Nuxt Routing

- `login.vue` shows conditional "Create account" link when registration is `open` or `invite`
- `register.vue` — new page, guarded by registration mode from runtime config
- Auth middleware skips `/register` (public page)

## 4. Password Reset Flow

### 4.1 Backend

**`POST /api/auth/forgot-password`**:

- **Request**: `{"email": "..."}`
- **Flow**:
  1. Validate input (trim, max length)
  2. Look up user by email
  3. If user found and mail configured: create `password_reset` token (revokes existing), send email
  4. If user NOT found: do nothing but still return generic 200 (anti-enumeration). Use constant-time comparison to avoid timing side-channels.
  5. If mail not configured: apply `mail_missing_policy` regardless of whether user exists
  6. Return generic 200: `{"ok": true, "message": "If an account exists for that email, a password reset link has been sent."}`
  7. Exception: return 503 when policy is `fail` and mail not configured

**`POST /api/auth/reset-password`**:

- **Request**: `{"token": "...", "password": "...", "password_confirmation": "..."}`
- **Flow**:
  1. Validate token via `AuthTokenRepository::validateToken(token, 'password_reset')`
  2. If valid: hash new password, update user entity, consume token, revoke all other `password_reset` tokens for user
  3. Destroy existing sessions for user (force re-login)
  4. Return 200 on success
  5. Return 422 for invalid/expired/consumed token (distinct error codes: `token_invalid`, `token_expired`, `token_consumed` — but no account info leaked)

### 4.2 Admin SPA

**`/forgot-password` page**:
- Split Panel layout, same theming as login
- Single email field + submit
- On submit: shows success message regardless (anti-enumeration)
- Link: "Back to sign in"

**`/reset-password` page**:
- Reads `?token=...` from URL
- Fields: new password, confirm password
- On success: redirect to `/login` with flash message "Password updated. Please sign in."
- On invalid/expired token: shows error with link to request a new reset
- On consumed token: shows "already used" message with link to request a new reset

### 4.3 Nuxt Routing

- Auth middleware skips `/forgot-password` and `/reset-password` (public pages)
- Both use Split Panel layout

## 5. Email Verification Flow

### 5.1 Backend

**`POST /api/auth/verify-email`**:

- **Request**: `{"token": "..."}`
- **Flow**: Validate token → set `email_verified = 1` on user → consume token → revoke other `email_verification` tokens for user
- **Response**: 200 on success. 422 for invalid/expired. 410 for already-consumed (distinct from expired, allows "already verified" UX).

**`POST /api/auth/resend-verification`**:

- **Requires**: authenticated session (returns 401 if not logged in)
- **Flow**: Revoke existing `email_verification` tokens for user → create new token → send email (or dev-log per `mail_missing_policy`)
- **Response**: 200 with generic success. 429 if rate limited (include `Retry-After` header).

### 5.2 Admin SPA — Gating Mode (`require_verified_email: true`)

**`/verify-email` page** (minimal layout, no AdminShell):
- "Check your email" instructions
- "Resend verification email" button with cooldown display (reflects `Retry-After` header)
- "Back to login" link (logs out and redirects)
- If `?token=...` present: auto-submits verification with transient state (verifying → success → redirect to dashboard)

**`ensureVerifiedEmail` middleware** (Nuxt global middleware):
- Checks `currentUser.email_verified` and `auth.requireVerifiedEmail` from runtime config
- If unverified + required: `navigateTo('/verify-email')`
- Skips: `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`

### 5.3 Admin SPA — Banner Mode (`require_verified_email: false`)

**`VerificationBanner.vue`** component in AdminShell:
- Persistent but dismissible
- Dismissal stored in `localStorage` keyed by user ID (prevents cross-account leakage on shared machines)
- Shows "Verify your email" with inline resend button
- Disappears reactively when `email_verified` becomes true (via `useAuth()`)

### 5.4 Admin Users List

- `email_verified` column visible in user list table
- Bulk actions: "Resend verification", "Mark as verified" (admin override, audit logged)

## 6. Shared SPA Patterns

All new pages follow Phase 1 conventions:
- **Split Panel layout** with CSS variable theming (`--color-primary` deep teal palette)
- **`useAuth()` composable** extended with `register()`, `forgotPassword()`, `resetPassword()`, `verifyEmail()`, `resendVerification()` methods
- **Nuxt proxy** — all `/api/**` routes already proxy to PHP backend
- **`credentials: 'include'`** on all `$fetch` calls for session cookies
- **Accessible**: form labels, aria attributes, focus management on errors

## 7. Migration and Cleanup

- **Delete** `PasswordResetTokenRepository` and its test after `AuthTokenRepository` is complete
- **Update** `UserServiceProvider` to register `AuthTokenRepository` instead of `PasswordResetTokenRepository`
- **Update** `PasswordResetManager` and `EmailVerifier` to use `AuthTokenRepository`
- **No data migration** needed — this is pre-production

## 8. Security Summary

| Concern | Mitigation |
|---|---|
| Account enumeration | Generic responses on all endpoints |
| Token theft | HMAC-SHA256 hashing, single-use, time-limited |
| Brute force | Rate limiting on all auth endpoints |
| Session fixation | New session on login, destroy sessions on password reset |
| Mail misconfiguration | 503 in production, dev-log in development |
| XSS in tokens | Tokens are hex strings, no user content in URLs |
| CSRF | Session-based CSRF protection on stateful endpoints |

## 9. Testing Strategy

### Unit Tests
- `AuthTokenRepository`: create/validate/consume/revoke/prune lifecycle
- Token hashing: plain token validates, wrong token doesn't, expired/consumed tokens rejected
- Config resolution: `mail_missing_policy` auto-detection
- Registration mode enforcement

### Integration Tests
- Full registration → verification → login flow
- Full forgot-password → reset → login flow
- Invite creation → invite consumption → user created
- Rate limiting enforcement

### Playwright E2E
- Registration page renders only when mode is `open`/`invite`
- Login with unverified account + `require_verified_email: true` → redirected to `/verify-email`
- Login with unverified account + `require_verified_email: false` → banner visible
- `/verify-email?token=valid` → token consumed, redirect to dashboard
- Resend verification rate limit enforced, cooldown shown
- Consumed token → distinct "already used" message
- Forgot password → success message shown regardless
- Reset password with valid token → password updated, redirected to login
- Reset password with expired token → error with link to request new reset

## 10. Implementation Order (Approach C)

### Phase I: Infrastructure
1. `AuthTokenRepository` with `auth_tokens` schema and HMAC-SHA256 hashing
2. Auth config system (`registration`, `require_verified_email`, `mail_missing_policy`, `token_secret`, `token_ttl`)
3. Mail missing policy implementation
4. `email_verified` field on User entity + session payload
5. Delete `PasswordResetTokenRepository`, update callers
6. Unit + integration tests for all infrastructure

### Phase II: Vertical Slices
7. Registration flow (backend + SPA + tests)
8. Password reset flow (backend + SPA + tests)
9. Email verification flow (backend + SPA + middleware + banner + tests)

### Phase III: Hardening
10. Admin invite management UI
11. Admin users list: `email_verified` column + bulk actions
12. `scaffold:auth` CLI updates for new pages
13. Playwright E2E test matrix
14. Developer documentation
