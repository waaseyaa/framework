# Nuxt 4 Upgrade — Admin SPA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade `packages/admin` from Nuxt `^3.15.0` to Nuxt `^4`, absorbing and fixing the two issues raised in PR #1133 review.

**Architecture:** Four sequential passes on the `feat/nuxt-surface-proxy` branch: (1) fix the PR issues before the upgrade, (2) version bump via `nuxi upgrade`, (3) fix any Nuxt 4 breaking changes surfaced by the test suite, (4) remove dead server-side code from the async plugin.

**Tech Stack:** Nuxt 4, Vue 3, TypeScript 5.6, Vitest 4, Playwright 1.58, h3/Nitro

---

## File Map

| File | Change |
|---|---|
| `packages/admin/server/routes/admin/_surface/[...path].ts` | **Delete** |
| `packages/admin/nuxt.config.ts` | Add `runtimeConfig.backendUrl`; update `nuxt` version after upgrade |
| `packages/admin/package.json` | `nuxt ^3.15.0` → `^4.x` (set by `nuxi upgrade`) |
| `packages/admin/package-lock.json` | Updated by `nuxi upgrade` |
| `packages/admin/app/plugins/admin.ts` | Remove dead `import.meta.server` block (Pass 4) |

No other files are expected to change. If `npm run build` or `npm test` surface failures in other files, fix them inline in Task 3 and note them in the commit message.

---

## Task 1: Fix PR issues — delete server route, expose `backendUrl` in runtimeConfig

**Files:**
- Delete: `packages/admin/server/routes/admin/_surface/[...path].ts`
- Modify: `packages/admin/nuxt.config.ts`

The server route was added to fix CORS during SSR, but `ssr: false` means there is no SSR. It duplicates the `routeRules` proxy and wins over it in Nitro's resolution order, making `routeRules` dead code. Delete it. Expose `backendUrl` via `runtimeConfig` so runtime handlers have a canonical, typed source for the backend URL.

- [ ] **Delete the server route**

```bash
rm packages/admin/server/routes/admin/_surface/\[...path\].ts
rmdir packages/admin/server/routes/admin/_surface
rmdir packages/admin/server/routes/admin
rmdir packages/admin/server/routes
rmdir packages/admin/server
```

Verify the directory is gone:
```bash
ls packages/admin/server 2>&1
```
Expected: `ls: cannot access 'packages/admin/server': No such file or directory`

- [ ] **Add `backendUrl` to `runtimeConfig` in `nuxt.config.ts`**

Open `packages/admin/nuxt.config.ts`. The current `runtimeConfig` block is:

```ts
runtimeConfig: {
  public: {
    enableRealtime: process.env.NUXT_PUBLIC_ENABLE_REALTIME ?? (process.env.NODE_ENV === 'production' ? '1' : '0'),
    appName: process.env.NUXT_PUBLIC_APP_NAME ?? 'Waaseyaa',
    docsUrl: process.env.NUXT_PUBLIC_DOCS_URL ?? 'https://github.com/jonesrussell/waaseyaa',
    baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '/admin',
    auth: {
      registration: process.env.NUXT_PUBLIC_AUTH_REGISTRATION ?? 'admin',
      requireVerifiedEmail: process.env.NUXT_PUBLIC_AUTH_REQUIRE_VERIFIED_EMAIL === '1',
    },
  },
},
```

Replace it with (add private `backendUrl` before `public`):

```ts
runtimeConfig: {
  backendUrl: process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080',
  public: {
    enableRealtime: process.env.NUXT_PUBLIC_ENABLE_REALTIME ?? (process.env.NODE_ENV === 'production' ? '1' : '0'),
    appName: process.env.NUXT_PUBLIC_APP_NAME ?? 'Waaseyaa',
    docsUrl: process.env.NUXT_PUBLIC_DOCS_URL ?? 'https://github.com/jonesrussell/waaseyaa',
    baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '/admin',
    auth: {
      registration: process.env.NUXT_PUBLIC_AUTH_REGISTRATION ?? 'admin',
      requireVerifiedEmail: process.env.NUXT_PUBLIC_AUTH_REQUIRE_VERIFIED_EMAIL === '1',
    },
  },
},
```

Note: `backendUrl` is private (no `public.` prefix) — it is a server-side value and must not be exposed to the browser bundle. The top-level `const backendUrl` at line 1 of the file remains unchanged — it is used for `routeRules` at config evaluation time, which is correct.

- [ ] **Verify TypeScript compiles**

```bash
cd packages/admin && npm run build 2>&1 | tail -20
```

Expected: build succeeds with no TypeScript errors. If there are errors, fix them before committing.

- [ ] **Commit**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/admin/nuxt.config.ts
git commit -m "$(cat <<'EOF'
fix(admin): remove duplicate server route, expose backendUrl via runtimeConfig

Drops the Nitro server route added in feat/nuxt-surface-proxy — it proxied
/_surface/** identically to the existing routeRules entry and shadowed it
in Nitro's resolution order, making the config-level proxy dead code.

Exposes backendUrl as a private runtimeConfig key so runtime handlers have
a canonical, typed source for NUXT_BACKEND_URL.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Bump Nuxt to v4

**Files:**
- Modify: `packages/admin/package.json` (version updated by nuxi)
- Modify: `packages/admin/package-lock.json` (lockfile updated by nuxi)

- [ ] **Run `nuxi upgrade`**

```bash
cd packages/admin && npx nuxi upgrade --force
```

This bumps `nuxt` and all first-party packages (`@nuxt/kit`, `@nuxt/schema`, `@nuxt/vite-builder`, etc.) to their Nuxt 4-compatible versions. It will update `package.json` and regenerate the lockfile. Let it run to completion.

- [ ] **Confirm the version bump**

```bash
node -e "console.log(require('./package.json').dependencies.nuxt)"
```

Expected: a version string starting with `4.` (e.g. `^4.0.0` or `4.x.y`).

- [ ] **Commit the lockfile and package.json before touching source**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/admin/package.json packages/admin/package-lock.json
git commit -m "$(cat <<'EOF'
chore(admin): bump nuxt to v4 via nuxi upgrade

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Fix Nuxt 4 breaking changes

**Files:**
- Modify: whichever files the test suite flags (see below for known candidates)

Run the test suite and build immediately after the version bump to surface any Nuxt 4 breaking changes. Fix inline.

- [ ] **Run TypeScript build**

```bash
cd packages/admin && npm run build 2>&1
```

If errors appear, the most likely causes in this codebase are:

1. **`defineNuxtPlugin` return type tightened** — Nuxt 4 is stricter about the `provide` return type. If `app/plugins/admin.ts` errors on the return type, change the explicit type annotation:

   ```ts
   // Before:
   export default defineNuxtPlugin(async (): Promise<{ provide: { admin: AdminRuntime | null } }> => {
   
   // After (let Nuxt 4 infer):
   export default defineNuxtPlugin(async () => {
   ```

2. **`routeRules` proxy type changed** — if `nuxt.config.ts` errors on the `proxy` key shape, check the Nuxt 4 `NitroRouteConfig` type. The fix is typically adding a `to:` key alongside `proxy`.

3. **`compatibilityDate` value rejected** — unlikely (2025-01-01 is valid), but if Nuxt 4 enforces a minimum date, bump to `'2025-06-01'`.

- [ ] **Run unit tests**

```bash
cd packages/admin && npm test 2>&1
```

Expected: all tests pass. If failures appear, they will be in the `tests/` directory. Fix the specific failure — do not suppress with `vi.mock` or skip.

- [ ] **Run E2E tests** (requires PHP backend running)

If the PHP backend is available:

```bash
cd packages/admin && npm run test:e2e 2>&1
```

Expected: Playwright tests pass. The most likely failure is a proxy regression — if `/_surface/session` returns a network error, check that `routeRules` is still evaluated correctly by printing the resolved config:

```bash
cd packages/admin && npx nuxi info 2>&1
```

- [ ] **Commit fixes (if any)**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/admin/
git commit -m "$(cat <<'EOF'
fix(admin): resolve Nuxt 4 breaking changes

[List specific fixes made — e.g. "Remove explicit return type from defineNuxtPlugin"]

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

If there were no changes needed (build and tests pass immediately), skip this commit.

---

## Task 4: Architectural audit — remove dead server-side code from plugin

**Files:**
- Modify: `packages/admin/app/plugins/admin.ts`

With `ssr: false`, `import.meta.server` is always false. The server-side branch in the admin plugin (lines 31–37) uses `useRoute()` inside an async plugin — a pattern explicitly forbidden by CLAUDE.md ("Nuxt async plugins can't call composables — use `window.location.pathname` on client"). Although this code is tree-shaken away at runtime, it is still compiled and violates the rule. Remove it.

Current plugin structure (lines 25–37):

```ts
// ── Skip auth check on public auth pages (prevents redirect loop) ─────
if (import.meta.client) {
  if (isPublicAuthPath(window.location.pathname, baseUrl)) {
    syncAuthState(null, false)
    return { provide: { admin: null } }
  }
}
if (import.meta.server) {
  const route = useRoute()
  if (isPublicAuthPath(route.path, baseUrl)) {
    syncAuthState(null, false)
    return { provide: { admin: null } }
  }
}
```

- [ ] **Replace both guarded blocks with a single client-only check**

Since `ssr: false` means the plugin only ever runs on the client, remove both guards and the dead server branch. The `import.meta.client` guard is also redundant — remove it for clarity:

```ts
// ── Skip auth check on public auth pages (prevents redirect loop) ─────
if (isPublicAuthPath(window.location.pathname, baseUrl)) {
  syncAuthState(null, false)
  return { provide: { admin: null } }
}
```

Replace lines 24–37 in `app/plugins/admin.ts` with the above (keep the comment line).

- [ ] **Run unit tests**

```bash
cd packages/admin && npm test 2>&1
```

Expected: all pass. The plugin unit tests in `tests/` should not depend on the removed server branch.

- [ ] **Run build**

```bash
cd packages/admin && npm run build 2>&1
```

Expected: zero errors.

- [ ] **Commit**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/admin/app/plugins/admin.ts
git commit -m "$(cat <<'EOF'
refactor(admin): remove dead import.meta.server branch from admin plugin

With ssr: false the plugin only runs on the client. The server-side branch
used useRoute() inside an async plugin — forbidden by CLAUDE.md
("Nuxt async plugins can't call composables"). Remove both conditional
guards and inline the single client-side check directly.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Final validation and PR update

- [ ] **Full test run**

```bash
cd packages/admin
npm run build 2>&1 | tail -5
npm test 2>&1 | tail -10
```

Both must be clean before the PR is marked ready.

- [ ] **Update PR #1133 description**

The PR title and body describe only the server route addition. Update to reflect the full scope:

```bash
gh pr edit 1133 \
  --title "feat(admin): upgrade to Nuxt 4, fix proxy architecture, clean plugin" \
  --body "$(cat <<'EOF'
## Summary

- Drops the Nitro server route (`/_surface` proxy) — duplicated `routeRules` and was dead code with `ssr: false`
- Exposes `backendUrl` as a private `runtimeConfig` key (canonical runtime source for `NUXT_BACKEND_URL`)
- Bumps `nuxt` from `^3.15.0` to `^4` via `nuxi upgrade --force`
- Fixes any Nuxt 4 breaking changes surfaced by the test suite
- Removes dead `import.meta.server` branch from `app/plugins/admin.ts` (violated CLAUDE.md: async plugins must not call composables)

Closes the two issues raised in the code review comment on this PR.

## Test plan

- [ ] `npm run build` — zero TypeScript errors
- [ ] `npm test` — all Vitest tests green
- [ ] `npm run test:e2e` — Playwright E2E green (requires `composer dev`)
- [ ] `/_surface/session` loads correctly in browser after `composer dev`
- [ ] No CORS errors in console on any admin page

🤖 Generated with [Claude Code](https://claude.ai/code)
EOF
)"
```

- [ ] **Push branch**

```bash
git push origin feat/nuxt-surface-proxy
```

---

## Gotchas

- **Do not run `npm install` manually** — `nuxi upgrade --force` manages the lockfile. Running `npm install` separately can produce a mismatched lockfile.
- **`backendUrl` is private** — do not move it under `runtimeConfig.public`. It must never appear in the browser bundle.
- **`routeRules` only applies in dev/preview** — in a static build (`npm run generate`), the web server handles proxying. This is expected and correct.
- **If `npm run test:e2e` fails with "connection refused"** — the PHP backend is not running. Start it with `composer dev` in the repo root, wait for it to be ready, then re-run.
