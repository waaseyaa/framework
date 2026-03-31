# Fix SPA baseURL for Surface API Calls (#814)

## Problem

The admin SPA sends `/_surface/*` requests to the site root instead of `/admin/_surface/*`. Three locations contribute:

1. **`admin.ts`**: `$fetch('/_surface/session', { baseURL: '/' })` resolves to `/_surface/session` (root)
2. **`nuxt.config.ts`**: routeRules proxy maps `/_surface/**` to `/admin/surface/**` (missing underscore after alpha.99 route rename)
3. **`surfacePath`** is `'/_surface'` — passed to `AdminSurfaceTransportAdapter` which uses native `fetch()`, also hitting root

## Fix

Use the existing `runtimeConfig.public.baseUrl` to build the correct surface path.

### Changes

**`nuxt.config.ts`**:
- Change `baseUrl` default from `''` to `'/admin'`
- Fix routeRules proxy: `/_surface/**` → `${backendUrl}/admin/_surface/**`

**`admin.ts`**:
- Change `surfacePath` from `'/_surface'` to `` `${baseUrl}/_surface` ``
- Result: `/admin/_surface` (default) or `${NUXT_PUBLIC_BASE_URL}/_surface` (custom)

### No changes needed

- **`useApi.ts`**: `baseURL: '/'` is correct — API routes (`/api/*`) live at root
- **`AdminSurfaceTransportAdapter`**: receives `surfacePath` as constructor arg, automatically correct

### Verification

- Surface session fetch hits `/admin/_surface/session`
- Surface catalog fetch hits `/admin/_surface/catalog`
- Transport adapter CRUD calls hit `/admin/_surface/{type}/*`
- API auth calls still hit `/api/auth/*` at root
- Dev proxy routes `/_surface/**` to correct backend path
