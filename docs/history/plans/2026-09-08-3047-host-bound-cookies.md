# #3047 — Configurable host-bound session and CSRF cookies

Source anchors (Framework `31515f363a418c8afe257df9f1652bfa0e8c0179`):

- Issue #3047 acceptance (Studio #27 prerequisite)
- Issue #3031: Admin upload must reuse the canonical CSRF cookie decode contract
- Live code: `SessionCookiePolicy`, `SessionMiddleware::applySessionCookieIni`,
  `CsrfMiddleware` (`XSRF-TOKEN` hard-coded), Admin `useApi.ts` /
  `FileUpload.vue` (divergent cookie readers), `NativeSession::start`
- Specs/conventions: `docs/specs/security-defaults.md`,
  `docs/conventions/csrf-token-cookie.md`, `docs/specs/middleware-pipeline.md`,
  `docs/specs/admin-spa.md`

## Contract

Extend `session.cookie` (resolved by `SessionCookiePolicy`) with one canonical
binding surface for **both** the PHP session cookie and the CSRF double-submit
cookie:

| Key | Default (compatible) | Host-bound profile (`host_bound => true`) |
|---|---|---|
| `name` | unset → PHP `session_name()` default | `__Host-waaseyaa_session` (or explicit `__Host-*`) |
| `csrf_name` | `XSRF-TOKEN` | `__Host-XSRF-TOKEN` (or explicit `__Host-*`) |
| `path` | `/` | must be `/` |
| `domain` | unset (omit attribute) | must be unset |
| `secure` | `'auto'` | forced `true` |
| existing `httponly` / `samesite` / `use_strict_mode` | unchanged | session remains HttpOnly; CSRF remains JS-readable |

Reject at policy construction when `host_bound` is combined with incompatible
path/domain/secure/name/csrf_name. When a PHP session is **already active**,
reject if live `session_name()` / `session_get_cookie_params()` disagree with
the resolved policy (inherited PHP settings / prestarted mismatch).

## Owned paths

- `packages/user/src/Session/SessionCookiePolicy.php` (+ new exception type)
- `packages/user/src/Middleware/SessionMiddleware.php`
- `packages/user/src/Middleware/CsrfMiddleware.php`
- `packages/user/src/Session/NativeSession.php` (align `start()` with policy)
- `packages/user/tests/Unit/Session/*`, `packages/user/tests/Unit/Middleware/*`
- `packages/admin/app/utils/csrfCookie.ts` (shared decoder; closes #3031 overlap)
- `packages/admin/app/composables/useApi.ts`, `FileUpload.vue`, Nuxt public
  `csrfCookieName`, AdminConfig wiring
- Specs/convention updates listed above
- `changes/unreleased/3047.host-bound-cookies.added.md`

Out of scope: Studio DNS/TLS/public listener/deployment, donor vendor, copying
Studio security rules, releasing.

## Evidence split

- **Native-header:** PHPUnit asserts `Set-Cookie` name/path/domain/Secure and
  rejection paths; login/logout/CSRF validation regressions.
- **Admin unit:** shared decoder + upload/api consumers with configured names
  and URL-encoded tokens.
- **Real-browser sibling-origin tossing:** run only if an isolated Playwright
  harness is available in-lane; otherwise record as remaining acceptance gap
  (do not claim unexecuted browser enforcement).
