# Waaseyaa Auth System Design

**Date:** 2026-03-29
**Status:** Approved
**Scope:** Framework-level authentication UI, theming, override mechanism, and scaffold CLI

---

## 1. Problem Statement

The admin SPA has no working login page. Recent commits removed the login.vue page, the /login proxy rule, and the auth middleware — leaving no path for unauthenticated users to sign in.

Beyond the immediate fix, Waaseyaa needs a framework-grade authentication system that:

- Works out of the box on any Waaseyaa app with zero configuration
- Is brandable via CSS custom properties without forking
- Is fully replaceable when apps need custom auth UX
- Is scaffoldable for teams that want to own and customize the auth files
- Follows modern BFF + session-based SPA security patterns
- Is extensible for future passkeys, SSO, and OAuth flows

This system replaces what Drupal's user.login + theme overrides, Laravel's Breeze/Jetstream (now starter kits), Django's contrib.auth templates, and WordPress's wp-login.php provide in their respective ecosystems.

---

## 2. Architecture Overview

### Override Resolution Order

Auth screens follow a three-tier resolution, highest priority first:

1. **App override** — `app/pages/login.vue` in the consuming Nuxt app
2. **Scaffolded files** — copied by `bin/waaseyaa scaffold:auth` into the app
3. **Framework default** — `packages/admin/app/pages/login.vue` (Nuxt layer)

This uses Nuxt's native layer resolution. No configuration flags or registration needed — placing a file at the same path in the app automatically overrides the framework default.

### Auth Flow (BFF Pattern)

```
Browser (SPA)                    Nuxt Proxy                    PHP Backend
     |                               |                              |
     |  GET /_surface/session         |  GET /admin/surface/session  |
     |------------------------------>|----------------------------->|
     |                               |                              |
     |  401 Unauthorized             |                              |
     |<------------------------------|<-----------------------------|
     |                               |                              |
     |  (navigate to /login)         |                              |
     |                               |                              |
     |  POST /api/auth/login         |  POST /api/auth/login        |
     |  {username, password}         |  {username, password}         |
     |------------------------------>|----------------------------->|
     |                               |                              |
     |  200 + Set-Cookie: PHPSESSID  |  Set session, return user    |
     |<------------------------------|<-----------------------------|
     |                               |                              |
     |  (navigate to returnTo)       |                              |
     |  GET /_surface/session         |                              |
     |------------------------------>|  (now authenticated)         |
```

Tokens never reach the browser. The PHP session cookie (`HttpOnly; Secure; SameSite=Lax`) is the sole auth credential. This follows the Backend-for-Frontend (BFF) pattern recommended by OWASP and Curity for SPAs.

---

## 3. File Layout

### Framework files (packages/admin/)

```
packages/admin/app/
  pages/
    login.vue                    # Default Split Panel login page
  components/
    auth/
      LoginForm.vue              # Form fields + submit logic (reusable)
      BrandPanel.vue             # Left panel with gradient, logo, tagline
  composables/
    useAuth.ts                   # Auth state, login(), logout(), session()
  assets/
    auth.css                     # CSS custom property defaults for auth screens
```

### App override (consuming Nuxt app)

```
app/
  pages/
    login.vue                    # Overrides framework login (auto-detected)
```

### Scaffolded files (after bin/waaseyaa scaffold:auth)

```
app/
  pages/
    login.vue                    # Copied from framework, app now owns it
  components/
    auth/
      LoginForm.vue              # Copied
      BrandPanel.vue             # Copied
  composables/
    useAuth.ts                   # Copied
  assets/
    auth.css                     # Copied
```

---

## 4. Default Login Page (Split Panel)

### Visual Design

Two-column layout on screens >= 768px:

- **Left panel (brand):** Gradient background using CSS custom properties. Displays app name (from `config.public.appName`), optional tagline, optional logo via CSS `background-image`. Fully themeable.
- **Right panel (form):** Username/email field, password field, submit button, error message area. Clean white background.

On screens < 768px, the layout stacks vertically — brand panel becomes a compact header above the form (degrades to the "Branded Header Card" pattern).

### CSS Custom Properties

All theming is driven by CSS variables. Apps override these in their own stylesheets — no file changes needed.

```css
/* Brand panel */
--waaseyaa-auth-brand-bg: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
--waaseyaa-auth-brand-color: #ffffff;
--waaseyaa-auth-brand-logo: none;              /* url('/logo.svg') */
--waaseyaa-auth-brand-logo-size: 48px;

/* Form panel */
--waaseyaa-auth-form-bg: #ffffff;
--waaseyaa-auth-form-color: #1e293b;
--waaseyaa-auth-form-muted: #64748b;

/* Inputs */
--waaseyaa-auth-input-border: #d1d5db;
--waaseyaa-auth-input-focus: #2563eb;
--waaseyaa-auth-input-focus-ring: rgba(37, 99, 235, 0.15);
--waaseyaa-auth-input-radius: 6px;

/* Button */
--waaseyaa-auth-btn-bg: #2563eb;
--waaseyaa-auth-btn-hover: #1d4ed8;
--waaseyaa-auth-btn-color: #ffffff;
--waaseyaa-auth-btn-radius: 6px;

/* Page */
--waaseyaa-auth-page-bg: #f1f5f9;
--waaseyaa-auth-card-radius: 12px;
--waaseyaa-auth-card-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);

/* Density toggle */
--waaseyaa-auth-hide-brand-panel: 0;           /* Set to 1 for Centered Minimal */
```

### Responsive Behavior

```css
/* Default: split panel */
.auth-page { display: flex; min-height: 100vh; }
.auth-brand { flex: 1; }
.auth-form-panel { flex: 1; }

/* Density toggle: hide brand panel */
/* login.vue reads --waaseyaa-auth-hide-brand-panel at mount and applies .minimal class */
.auth-page.minimal .auth-brand { display: none; }
.auth-page.minimal .auth-form-panel { max-width: 420px; margin: 0 auto; }

/* Mobile: stack vertically, compact brand header */
@media (max-width: 767px) {
  .auth-page { flex-direction: column; }
  .auth-brand { flex: none; padding: 1.5rem; text-align: center; }
}
```

Note: CSS cannot natively compare custom property values with `==`. The density toggle will use a class-based approach: `login.vue` reads the CSS variable at mount time and applies a `.minimal` class when the value is `1`. This keeps the template simple and avoids JavaScript-in-CSS hacks.

---

## 5. useAuth() Composable API

The existing `useAuth()` composable must be updated to actually call the backend API instead of redirecting.

```typescript
export function useAuth(): {
  // Reactive state
  currentUser: Ref<AdminAccount | null>
  isAuthenticated: ComputedRef<boolean>

  // Actions
  login(username: string, password: string): Promise<LoginResult>
  logout(): Promise<void>
  checkAuth(): Promise<void>
}

interface LoginResult {
  success: boolean
  error?: string           // Human-readable error message
  account?: AdminAccount   // Populated on success
}
```

### login() behavior

1. POST to `/api/auth/login` with `{ username, password }` and `credentials: 'include'`
2. On success (200 with `data.id`): update `currentUser` state, return `{ success: true, account }`
3. On 400/401: return `{ success: false, error: 'Invalid username or password.' }`
4. On network error: return `{ success: false, error: 'Unable to reach the server.' }`

### logout() behavior

1. POST to `/api/auth/logout` with `credentials: 'include'`
2. Clear `currentUser` and `authChecked` state
3. Navigate to `/login`

### Extension points (no implementation yet, API reserved)

```typescript
// Phase 3 — WebAuthn / SSO hooks
startWebAuthnRegistration?(): Promise<void>
startWebAuthnLogin?(): Promise<void>
startOidcFlow?(provider: string): Promise<void>
```

These are documented as future API surface but not implemented in Phase 1.

---

## 6. Admin Plugin (admin.ts) Changes

The plugin's 401 handling must use Nuxt navigation instead of `window.location.href`:

**Current (broken):**
```typescript
if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
  if (import.meta.client) {
    window.location.href = loginUrl  // loginUrl points to /login which doesn't exist
  }
  return { provide: { admin: null } }
}
```

**Fixed:**
```typescript
if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
  if (import.meta.client) {
    navigateTo('/login', { replace: true })
  }
  return { provide: { admin: null } }
}
```

The login page uses `definePageMeta({ layout: false })` so it renders outside the AdminShell layout (which requires an authenticated session).

---

## 7. Nuxt Config Changes

Restore the `/api/auth/**` proxy coverage (already covered by `/api/**` rule). No new proxy rules needed — the existing `'/api/**'` route rule handles `/api/auth/login` and `/api/auth/logout`.

The `/_surface/**` proxy rule (mapping to `/admin/surface/**`) already handles session checks.

No changes to `nuxt.config.ts` proxy rules are needed.

---

## 8. Scaffold CLI

### Command

```bash
bin/waaseyaa scaffold:auth
```

### Behavior

1. Copies framework auth files into the app directory:
   - `packages/admin/app/pages/login.vue` → `app/pages/login.vue`
   - `packages/admin/app/components/auth/LoginForm.vue` → `app/components/auth/LoginForm.vue`
   - `packages/admin/app/components/auth/BrandPanel.vue` → `app/components/auth/BrandPanel.vue`
   - `packages/admin/app/composables/useAuth.ts` → `app/composables/useAuth.ts`
   - `packages/admin/app/assets/auth.css` → `app/assets/auth.css`

2. Idempotent — skips files that already exist (prints warning). Use `--force` to overwrite.

3. Prints a summary of copied files and a note: "You now own these files. Framework updates will no longer flow to them."

4. `--dry-run` mode: prints what would be copied without writing files. Useful for reviewing before committing.

### Rebase guidance

When the framework updates auth files (e.g., security fixes), apps with scaffolded files must manually merge changes. The recommended workflow:

```bash
# 1. See what changed upstream
bin/waaseyaa scaffold:auth --dry-run

# 2. Diff your scaffolded files against the current framework versions
diff app/pages/login.vue packages/admin/app/pages/login.vue

# 3. Apply upstream changes selectively
# (manual merge — scaffolded files are owned by the app)
```

Document this workflow in the developer docs with concrete examples.

### Upstream security notices

When scaffolded auth files are detected and the framework version has changed since scaffolding, `bin/waaseyaa` CLI commands should print a one-line notice:

```
[notice] Auth scaffold may be outdated — run `bin/waaseyaa scaffold:auth --dry-run` to review upstream changes.
```

This is non-blocking and only shown when the framework's auth file checksums differ from the scaffolded versions. Checksums are stored in `app/.waaseyaa/scaffold-manifest.json` at scaffold time.

### Implementation

This is a PHP CLI command added to `packages/cli/src/Command/`. It reads a manifest of scaffold-able file sets and copies them. The auth set is the first; future sets (e.g., `scaffold:admin`, `scaffold:api`) follow the same pattern.

Phase 1 scope: the scaffold command is designed and the auth file set is defined. The CLI command implementation is a Phase 1 deliverable but can be deferred to the end of the phase if the login page and useAuth fixes are prioritized first.

---

## 9. Security Requirements

### Cookie attributes

The PHP backend must set session cookies with:
- `HttpOnly` — not accessible from JavaScript
- `Secure` — only sent over HTTPS (relaxed for localhost in dev)
- `SameSite=Lax` — prevents CSRF from cross-origin form posts

### Proxy and TLS termination

When deployed behind a reverse proxy that terminates TLS (common in production), the PHP backend must trust `X-Forwarded-Proto` to determine the effective scheme. Without this, `Secure` cookies won't be set on HTTP-behind-proxy connections, causing silent login failures.

- SessionMiddleware must check `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'` when setting cookie params
- CI must include a test job that simulates TLS termination (proxy on HTTP, `X-Forwarded-Proto: https`)
- Document trusted proxy configuration in developer docs

### SameSite=None for OAuth/SSO (Phase 3)

When OAuth or SSO flows redirect through an identity provider, `SameSite=Lax` may block the session cookie on the return redirect in some browsers. Phase 3 must:
- Switch to `SameSite=None; Secure` for OAuth callback routes only
- Keep `SameSite=Lax` as the default for all other routes
- Document the security tradeoffs in the OAuth integration guide

### Rate limiting

The existing `RateLimiter` in `packages/auth/` must be applied to `POST /api/auth/login`. Limit: 5 attempts per IP per minute (configurable). Return 429 with `Retry-After` header.

### CSRF

Session-based auth with `SameSite=Lax` cookies provides baseline CSRF protection. The `CsrfMiddleware` in `packages/user/` provides additional protection for state-changing requests. Login is exempt from CSRF (the form creates the session, it doesn't use one).

### Input validation

- Username: trimmed, non-empty string, max 255 characters
- Password: non-empty string, max 4096 characters (prevent hash DoS)
- Both validated server-side in `ControllerDispatcher`; client-side `required` attributes for UX

---

## 10. Phase Structure

### Phase 1: Login (this milestone)

| Deliverable | Description |
|-------------|-------------|
| `login.vue` (Split Panel) | Framework default login page with CSS variable theming |
| `LoginForm.vue` | Extracted form component (reusable) |
| `BrandPanel.vue` | Extracted brand panel component (themeable) |
| `auth.css` | CSS custom property defaults |
| `useAuth()` rewrite | login() calls API, returns LoginResult |
| `admin.ts` fix | 401 navigates to /login via Nuxt router |
| `login.vue` layout: false | Renders outside AdminShell |
| Rate limiting | Apply RateLimiter to POST /api/auth/login |
| Scaffold CLI | `bin/waaseyaa scaffold:auth` command |
| Playwright tests | Login roundtrip, cookie assertions, redirect, a11y |
| Developer docs | Override resolution, CSS tokens, scaffold usage |

### Phase 2: Registration + Password Reset (after mail/queue packages)

| Deliverable | Description |
|-------------|-------------|
| `register.vue` | Registration page (Split Panel variant) |
| `forgot-password.vue` | Password reset request page |
| `reset-password.vue` | Password reset form (token-based) |
| Email templates | Verification and reset emails |
| useAuth() extensions | register(), requestPasswordReset(), resetPassword() |
| Scaffold updates | scaffold:auth includes new files |

### Phase 3: Identity Providers (after OAuth package)

| Deliverable | Description |
|-------------|-------------|
| Social login buttons | OAuth provider buttons on login page |
| WebAuthn / passkeys | Passwordless login option |
| SSO / OIDC | Enterprise SSO support |
| useAuth() extensions | startWebAuthnLogin(), startOidcFlow() |
| Provider plugin system | Apps register OAuth providers via config |

---

## 11. Test Matrix (Phase 1)

### Playwright E2E tests

| Test | Description |
|------|-------------|
| Login success | Submit valid credentials → redirected to dashboard, session cookie set |
| Login failure | Submit invalid credentials → error message displayed, no redirect |
| Login redirect | Access protected page while unauthenticated → redirected to /login with returnTo |
| Return after login | Login with returnTo param → redirected to original page |
| Logout | Click logout → session cleared, redirected to /login |
| Cookie attributes | Verify HttpOnly, SameSite=Lax on session cookie |
| Rate limiting | 6 rapid login attempts → 429 response on 6th |
| Accessibility | axe-core scan passes on login page |
| Keyboard navigation | Tab through all form elements, Enter submits |
| Mobile layout | Viewport 375px → brand panel stacks above form |
| CSS theming | Override `--waaseyaa-auth-brand-bg` → brand panel color changes |
| Override resolution | App-level login.vue takes precedence over framework default |
| Proxied TLS termination | CI job: PHP behind HTTP proxy with `X-Forwarded-Proto: https` → cookie set correctly |

### Manual accessibility checklist

In addition to automated axe-core scans, the following must be verified manually before Phase 1 ships:

- [ ] Screen reader (NVDA or VoiceOver): form fields announced with labels, error messages announced on submit
- [ ] Keyboard-only path: Tab to username → Tab to password → Enter submits → focus moves to error or redirects
- [ ] High contrast mode (Windows): form fields and button remain visible and operable
- [ ] Zoom 200%: layout remains usable, no horizontal scroll

### Unit tests (Vitest)

| Test | Description |
|------|-------------|
| useAuth().login() success | Mock $fetch → returns { success: true, account } |
| useAuth().login() failure | Mock $fetch 401 → returns { success: false, error } |
| useAuth().login() network error | Mock $fetch throw → returns { success: false, error } |
| useAuth().logout() | Calls API, clears state |
| LoginForm emits | Submit event includes username and password |
| BrandPanel renders | Displays appName from runtime config |

---

## 12. Competitive Comparison

| Feature | Waaseyaa (proposed) | Laravel 12 Kits | Drupal 11 | Django |
|---------|-------------------|----------------|-----------|--------|
| Zero-config login | Yes (Nuxt layer) | No (must scaffold) | Yes (core route) | Yes (contrib.auth) |
| CSS variable theming | Yes | No (Tailwind classes) | Via Gin Login module | Via template override |
| Full page override | Yes (Nuxt file resolution) | Yes (you own all files) | Yes (Twig override) | Yes (template override) |
| Scaffold/eject CLI | Yes (scaffold:auth) | Yes (laravel new --kit) | No | No (startapp is different) |
| BFF/session-based | Yes | Yes (Sanctum) | Yes | Yes |
| Passkey-ready | Phase 3 | Via WorkOS variant | Via contrib | Via django-passkeys |
| Mobile responsive | Yes (stacked layout) | Yes | Varies by theme | Varies by template |

---

## 13. Resolved Decisions

1. **Logo slot:** BrandPanel supports both mechanisms:
   - CSS `background-image` via `--waaseyaa-auth-brand-logo` for background treatment (watermarks, patterns)
   - `config.public.logoUrl` runtime config for an `<img>` element with proper `alt` text
   - When both are set, the `<img>` renders on top of the CSS background

2. **Dark mode:** Not in Phase 1. Add dark mode CSS variable overrides in a later iteration.

3. **"Remember me" checkbox:** Not in Phase 1. Session duration is controlled server-side. Add when session management is more configurable.

4. **Error message i18n:** Phase 1 uses the backend error message as-is. Phase 2 maps error codes to i18n keys via `useLanguage()`.

## 14. Open Questions

None — all items resolved during design review.

---

## References

- [Laravel 12 Starter Kits](https://laravel.com/docs/12.x/starter-kits) — new scaffold-based approach replacing Breeze/Jetstream
- [Laravel Starter Kits Blog Post](https://laravel.com/blog/laravel-starter-kits-a-new-beginning-for-your-next-project) — rationale for the new model
- [Curity SPA Best Practices](https://curity.io/resources/learn/spa-best-practices/) — BFF pattern, token handler
- [Auth.js v5 Guide](https://dev.to/huangyongshan46a11y/authjs-v5-with-nextjs-16-the-complete-authentication-guide-2026-2lg) — modern auth patterns
- [Drupal Gin Login Module](https://www.rollin.ca/resources/how-to-customize-login-and-registration-pages-in-drupal-with-the-gin-login-module/) — themeable login for Drupal 11
- [OWASP SPA Security](https://dev.indooroutdoor.io/authentication-patterns-and-best-practices-for-spas) — authentication patterns for SPAs
