# Convention: CSRF Token Cookie (`XSRF-TOKEN`)

## Overview

Every HTML response from a Waaseyaa-bootstrapped app sets an `XSRF-TOKEN` cookie that JavaScript can read. State-changing routes accept the token from a form field, a header, or the cookie-backed header — so Inertia consumers get CSRF protection for file uploads with no consumer-side code. For the full security model, see [docs/specs/security-defaults.md](../specs/security-defaults.md).

---

## For Inertia + Vue consumers

Inertia's axios adapter reads the `XSRF-TOKEN` cookie automatically and forwards it as the `X-XSRF-TOKEN` request header on every mutation. You do not need to wire anything.

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

const form = useForm({ file: null as File | null })

function submit() {
  form.post('/ingest/upload', {
    forceFormData: true,
  })
  // No CSRF code anywhere. Inertia's axios reads XSRF-TOKEN cookie
  // and forwards it as X-XSRF-TOKEN automatically.
  // → 200/302
}
</script>
```

Zero consumer-side wiring. That is the contract.

---

## For vanilla `fetch` consumers

If you use `fetch` directly (for example, a sprinkle of JS on a Twig page), read the cookie manually and pass it as the `X-XSRF-TOKEN` header.

```html
<script>
function getXsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

const form = new FormData()
form.append('file', fileInput.files[0])

await fetch('/ingest/upload', {
  method: 'POST',
  body: form,
  headers: {
    'X-XSRF-TOKEN': getXsrfToken(),
  },
})
// → 200/302
</script>
```

The same cookie serves both Inertia and vanilla-fetch consumers. No meta tag or shared prop is needed.

---

## For server-rendered Twig consumers

For traditional non-JS forms, keep using the existing `csrf_token()` helper. This path is unchanged by this convention.

```twig
<form action="/ingest/upload" method="post" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="{{ csrf_token() }}">
  <input type="file" name="file">
  <button type="submit">Upload</button>
</form>
```

`csrf_token()` is the existing template helper. No migration is required.

---

## Cookie attributes

The `XSRF-TOKEN` cookie is written on every `text/html` response, and — since the #2177 F1 prerequisite — on any response (JSON included) whose request carries both an authenticated `_account` and the login-session `waaseyaa_uid` marker. This lets an SPA that boots against JSON endpoints (`GET /api/user/me`) receive the token without ever loading a kernel-rendered HTML page, while bearer-only requests do not receive a browser-session token. Both paths use the same attributes; anonymous and bearer-only non-HTML responses set no cookie.

| Attribute | Value | Notes |
|---|---|---|
| Name | `XSRF-TOKEN` | Exact case. Inertia's axios looks for this name. |
| Value | `urlencode($_SESSION['_csrf_token'])` | URL-encoded. Server URL-decodes before comparison. |
| `HttpOnly` | absent / `false` | JavaScript MUST be able to read it. |
| `Secure` | `true` if request is HTTPS, `false` otherwise | Detected via the request's `X-Forwarded-Proto` honored by the existing trusted-proxy logic, falling back to `$_SERVER['HTTPS']`. |
| `SameSite` | `Lax` | Hard-coded. Don't make this configurable in this mission. |
| `Path` | `/` | App-wide. |
| `Domain` | _(unset)_ | Browser defaults to current host. |
| `Max-Age` / `Expires` | _(unset — session cookie)_ | Lives for the browser session, matches session token lifetime. |

> Authoritative source: `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/contracts/csrf-token-cookie.md`

---

## Accepted token sources

For any state-changing method (`POST`, `PUT`, `PATCH`, `DELETE`), the framework accepts the CSRF token from the following sources in order, stopping at the first match.

| Order | Source | Pre-comparison transform |
|---|---|---|
| 1 | `_csrf_token` POST field | _(none — read as-is)_ |
| 2 | `X-CSRF-Token` request header | _(none — read as-is)_ |
| 3 | `X-XSRF-TOKEN` request header | URL-decode (`urldecode()`) before comparison |

If none of the three sources provides a valid token, the framework returns 403 Invalid Security Token.

---

## What this convention does NOT do

- The exempt Content-Types (`application/json`, `application/vnd.api+json`) remain exempt **by default**. A route may override this in either direction via the three-valued `_csrf` route option: `RouteBuilder::csrfExempt()` (`_csrf = false`, never validate) or `RouteBuilder::requireCsrf()` (`_csrf = true`, validate every content type — the #2177 F1 prerequisite for cookie-authenticated JSON endpoints). See `docs/specs/security-defaults.md` "CSRF token cookie".
- No token rotation policy change. The session-scoped CSRF token is rotated only by the existing rotation triggers (e.g., login).
- No session driver change. The token continues to live in `$_SESSION['_csrf_token']` via whatever session driver the app is configured to use.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| 403 on Inertia multipart POST | Cookie not being set on the response | Check that the GET that delivered the page returned `Set-Cookie: XSRF-TOKEN`. Confirm middleware ran. |
| 403 even though cookie is present | Server is comparing the URL-encoded value | Server must `urldecode()` the `X-XSRF-TOKEN` header before `hash_equals`. |
| Cookie not set over HTTPS | `Secure` flag missing or wrong scheme detection | Verify the request scheme detection honors `X-Forwarded-Proto` only via the existing trusted-proxy path. |
| Inertia version mismatch (409) | Unrelated to this convention | Existing `InertiaMiddleware` behavior; out of scope. |
| Cookie set on JSON API response | Response Content-Type detection too loose | Tighten the "is HTML" check to `text/html` primary type only. |
