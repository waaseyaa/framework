# Quickstart: CSRF for Inertia File Uploads

**Mission**: `inertia-file-upload-csrf-01KQZJQJ`
**Phase**: 1 (design)
**Audience**: framework developer implementing the change; consumer-app developer using the result; reviewer running the cross-repo smoke.

---

## 1. What this convention gives you

After this mission lands in `waaseyaa/user`, every HTML response from a Waaseyaa-bootstrapped app sets an `XSRF-TOKEN` cookie that JavaScript can read. State-changing routes accept the token from `_csrf_token` (form field), `X-CSRF-Token` (header), **or** `X-XSRF-TOKEN` (header, URL-decoded). For Inertia consumers, this means file uploads work with no consumer-side code.

---

## 2. Inertia consumer (Vue, the dominant case)

### Before this mission

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

const form = useForm({ file: null as File | null })

function submit() {
  form.post('/ingest/upload', {
    forceFormData: true,        // multipart for files
  })
  // → 403 Invalid Security Token  ❌
}
</script>
```

### After this mission

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

const form = useForm({ file: null as File | null })

function submit() {
  form.post('/ingest/upload', {
    forceFormData: true,
  })
  // → 200/302  ✅
  // No CSRF code anywhere. Inertia's axios reads XSRF-TOKEN cookie
  // and forwards it as X-XSRF-TOKEN automatically.
}
</script>
```

**Zero consumer-side wiring.** That is the contract.

---

## 3. Vanilla `fetch` consumer (e.g., a sprinkle of JS on a Twig page)

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

The same cookie serves both audiences. No meta tag. No shared prop.

---

## 4. Server-rendered Twig template (unchanged)

For traditional non-JS forms, keep using the existing helper:

```twig
<form action="/ingest/upload" method="post" enctype="multipart/form-data">
  <input type="hidden" name="_csrf_token" value="{{ csrf_token() }}">
  <input type="file" name="file">
  <button type="submit">Upload</button>
</form>
```

This path is unchanged by this mission. `csrf_token()` is the existing template helper that exposes `CsrfMiddleware::token()`.

---

## 5. Cross-repo smoke procedure (HARD ACCEPTANCE GATE)

The mission cannot be marked done until this smoke succeeds and evidence lands in `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/`.

### 5.1. Wire Giiken to the local framework build

In `/home/jones/dev/giiken`:

```bash
cp composer.json composer.json.smoke-backup
```

Edit `composer.json` to add a path repository at the **top** of the `repositories` array (or create one if absent):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/home/jones/dev/waaseyaa/packages/*",
      "options": { "symlink": true }
    }
  ]
}
```

Then:

```bash
composer update 'waaseyaa/*' --no-progress --no-interaction
```

Verify the symlink resolved by checking that `vendor/waaseyaa/user` points to the local source.

### 5.2. Run Giiken with the local framework

```bash
cd /home/jones/dev/giiken
./vendor/bin/waaseyaa migrate
./vendor/bin/waaseyaa serve
# Or: php -S 127.0.0.1:8080 -t public public/index.php
```

### 5.3. Perform the real upload

1. Open `http://127.0.0.1:8080/` in a browser, log in as the seeded admin/staff user.
2. Navigate to the Pilot Nation A Anishnawbek community management page.
3. Open the Ingestion page.
4. Upload a real `.md` or `.csv` test file via the Inertia form.

### 5.4. Capture evidence (all three required)

Save into `/home/jones/dev/waaseyaa/kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/`:

| File | Content |
|---|---|
| `giiken-smoke-<utc>.png` | Browser screenshot of the success state after upload. |
| `giiken-smoke-<utc>-network.txt` | Network capture (DevTools → copy as cURL, or `curl -v` if reproducing CLI-side) of the multipart POST showing `X-XSRF-TOKEN` request header and `200`/`302` response. |
| `giiken-smoke-<utc>-server.log` | Server log excerpt showing the request handled and a `knowledge_item` row created. |
| `giiken-smoke-<utc>.md` | Summary: framework SHA used, Giiken SHA, test file used, observed outcome, links to the three artifacts above. |

### 5.5. Revert Giiken to the released version

```bash
cd /home/jones/dev/giiken
mv composer.json.smoke-backup composer.json
composer update 'waaseyaa/*' --no-progress --no-interaction
git status        # Should show no Giiken-side changes.
```

### 5.6. Acceptance check

The smoke is acceptable if **all** of:

- The Inertia upload returned 200/302 (not 403).
- A `knowledge_item` row was created with the uploaded file's content.
- The DevTools network capture shows `X-XSRF-TOKEN` was forwarded automatically (i.e., no Giiken code change was needed).
- Giiken's working tree is clean after the revert step.
- All four evidence files are in `artifacts/`.

If any of those fail, the smoke fails and the mission cannot be marked done.

---

## 6. Re-running the unit + integration tests locally

```bash
cd /home/jones/dev/waaseyaa
./vendor/bin/phpunit packages/user
./vendor/bin/phpunit tests/Integration/Phase13/InertiaMultipartCsrfIntegrationTest.php
./vendor/bin/phpstan analyse packages/user
```

Expected: all green. Zero new warnings (NFR-004).

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| 403 on Inertia multipart POST | Cookie not being set on the response | Check that the GET that delivered the page returned `Set-Cookie: XSRF-TOKEN`. Confirm middleware ran. |
| 403 even though cookie is present | Server is comparing the URL-encoded value | Server must `urldecode()` the `X-XSRF-TOKEN` header before `hash_equals`. |
| Cookie not set over HTTPS | `Secure` flag missing or wrong scheme detection | Verify the request scheme detection honors `X-Forwarded-Proto` only via the existing trusted-proxy path. |
| Inertia version mismatch (409) | Unrelated to this mission | Existing `InertiaMiddleware` behavior; out of scope. |
| Cookie set on JSON API response | Response Content-Type detection too loose | Tighten the "is HTML" check to `text/html` primary type only. |
