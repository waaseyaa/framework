# Auth System Phase 1 - Code Review

**Reviewer:** Claude Opus 4.6
**Branch:** auth-system-phase1 (12 commits)
**Issues:** #760, #761, #762, #763, #764, #765, #766

---

## What Was Done Well

- **BFF auth pattern** is correctly implemented: credentials POST to PHP, session cookie is the sole auth token, no JWTs in browser storage.
- **Component decomposition** is clean: BrandPanel, LoginForm, and login.vue page each have a single responsibility.
- **CSS custom property theming** is well-designed with sensible defaults and a `--waaseyaa-auth-hide-brand-panel` density toggle.
- **Test coverage** is thorough: unit tests for useAuth (all branches), component tests for BrandPanel and LoginForm, E2E Playwright tests for the full login flow, PHP unit tests for RateLimiter, ScaffoldAuthCommand, and NativeSession.
- **Scaffold CLI** follows atomic file write pattern (write-to-temp-then-rename for manifest), respects `--force`/`--dry-run` flags, and writes checksums for future drift detection.
- **`credentials: 'include'`** is correctly applied on all `$fetch` calls (per CLAUDE.md gotcha about Nuxt `$fetch` not sending cookies by default).
- **`ignoreResponseError: true`** prevents Nuxt from throwing on 401/429 responses, allowing the composable to parse error bodies.

---

## Critical Issues (Must Fix)

### 1. In-memory RateLimiter is per-process and non-persistent

**File:** `packages/auth/src/RateLimiter.php`
**File:** `packages/foundation/src/Http/ControllerDispatcher.php` (line ~691)

The `RateLimiter` uses a `private array $attempts = []` stored in PHP process memory. With `php -S` (built-in server), each request may spawn a new process or reuse one unpredictably. In production (PHP-FPM), each worker has its own memory space. This means:

- Rate limiting **does not work** across requests in most deployment configurations.
- The `static $rateLimiter` in `ControllerDispatcher::dispatch()` only survives within a single PHP process lifetime.

**Recommendation:** This is acceptable as a Phase 1 placeholder if documented, but should be called out as a known limitation. Phase 2 should use a cache-backed limiter (e.g., `CacheBackendInterface` or SQLite-based). Add a `@todo` comment and note in the design doc.

### 2. Design doc and plan deleted from branch

The `git diff --stat` shows -2066 lines from `docs/superpowers/specs/2026-03-29-auth-system-design.md` and `docs/superpowers/plans/2026-03-29-auth-system-phase1.md`. These files were deleted in this branch. Design docs should be preserved -- they serve as architectural decision records. If they should live elsewhere, move them rather than deleting.

**Recommendation:** Restore both files. They are referenced in the PR description and are valuable for future contributors.

---

## Important Issues (Should Fix)

### 3. `X-Forwarded-Proto` trust without validation

**File:** `packages/user/src/Session/NativeSession.php` (line 125)

```php
return isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
```

The `X-Forwarded-Proto` header is client-settable. Any HTTP client can send `X-Forwarded-Proto: https` to make the server set `Secure` cookies, then the cookies won't be sent back over plain HTTP, causing session loss. More critically, if a reverse proxy is NOT in use, an attacker could trick the app into thinking it's behind TLS when it isn't.

**Recommendation:** Guard this behind a "trusted proxies" configuration, or at minimum a check that the app is running behind a known proxy. A simple `WAASEYAA_TRUST_PROXY=1` env var would suffice for now.

### 4. Login page skip logic in admin.ts uses `window.location.pathname`

**File:** `packages/admin/app/plugins/admin.ts` (line 36)

```typescript
if (import.meta.client && window.location.pathname.endsWith('/login')) {
```

This uses `endsWith('/login')` which would also match paths like `/not-a-login` or `/admin/some-other-login`. It should use an exact match or a route-name check.

**Recommendation:** Use `window.location.pathname === '/login'` or check the Nuxt route name instead.

### 5. `returnTo` query parameter is not validated

**File:** `packages/admin/app/pages/login.vue` (line 9)

```typescript
const returnTo = (route.query.returnTo as string) || '/'
```

After successful login, the user is redirected to whatever `returnTo` says. This is an open redirect vulnerability -- an attacker could craft `/login?returnTo=https://evil.com` to redirect users after login.

**Recommendation:** Validate that `returnTo` starts with `/` and does not contain `//` (to prevent protocol-relative URLs like `//evil.com`):

```typescript
function sanitizeReturnTo(value: string | undefined): string {
  if (!value || !value.startsWith('/') || value.startsWith('//')) return '/'
  return value
}
```

### 6. LoginForm does not validate empty submission

**File:** `packages/admin/app/components/auth/LoginForm.vue` (line 56-58)

The form emits `submit` even when both fields are empty. While the server would reject it, this creates unnecessary network requests and a poor UX (server error message instead of client-side validation).

**Recommendation:** Add a guard in `handleSubmit()`:

```typescript
function handleSubmit() {
  if (!username.value.trim() || !password.value) return
  emit('submit', { username: username.value, password: password.value })
}
```

---

## Suggestions (Nice to Have)

### 7. Error message styling uses hardcoded colors

**File:** `packages/admin/app/components/auth/LoginForm.vue` (lines 103-108)

The `.auth-form-error` block uses hardcoded `#fef2f2`, `#fecaca`, `#dc2626` while every other color in the auth system uses CSS custom properties. This breaks theming for dark mode or custom brand colors.

**Recommendation:** Add `--waaseyaa-auth-error-bg`, `--waaseyaa-auth-error-border`, `--waaseyaa-auth-error-color` tokens to `auth.css`.

### 8. BrandPanel `aria-hidden="true"` hides content from screen readers

**File:** `packages/admin/app/components/auth/BrandPanel.vue` (line 12)

The brand panel is decorative, so `aria-hidden="true"` makes sense. However, it contains an `<h1>` which is the page heading. Screen reader users will see no heading on the login page.

**Recommendation:** Move the `<h1>` (or add a visually-hidden heading) outside the `aria-hidden` region so screen readers can identify the page.

### 9. RateLimiterTest does not test expiry

The `RateLimiter` has `pruneExpired()` logic but no test verifies that attempts expire after the decay window. This is the most important behavior to verify.

**Recommendation:** Add a test using `ClockMock` or by extracting a `time()` provider to make expiry testable without `sleep()`.

### 10. `auth.css` defines `--waaseyaa-auth-brand-logo` token but nothing uses it

**File:** `packages/admin/app/assets/auth.css` (line 6)

```css
--waaseyaa-auth-brand-logo: none;
```

This token is defined but BrandPanel uses the `logoUrl` prop instead. Either remove the unused token or wire it up.

---

## Plan Alignment

The implementation aligns well with the design spec's architecture:

- **Three-tier override resolution** (app > scaffold > framework) -- implemented via Nuxt layer resolution.
- **BFF auth flow** -- matches the spec's sequence diagram exactly.
- **Split Panel layout** -- implemented as described.
- **CSS custom property theming** -- all tokens from the spec are present.
- **scaffold:auth CLI** -- implemented with `--force`, `--dry-run`, and manifest tracking.
- **Rate limiting** -- implemented but with the in-memory limitation noted above.
- **Session cookie hardening** -- `HttpOnly`, `Secure`, `SameSite=Lax` all present.

**Missing from Phase 1 (expected for later phases per plan):**
- CSRF token on POST /api/auth/login
- Session fixation protection (session regeneration on login)
- Account lockout (rate limiting is IP-based only, not per-account)
- Logout endpoint wiring in the admin UI (composable has it, no UI trigger seen)

---

## Summary

The implementation is solid and well-structured. The two critical items are (1) documenting the in-memory rate limiter limitation and (2) restoring the deleted design docs. The open redirect in `returnTo` and the `X-Forwarded-Proto` trust issue should be addressed before merge. Everything else is polish that can be tracked as follow-up issues.
