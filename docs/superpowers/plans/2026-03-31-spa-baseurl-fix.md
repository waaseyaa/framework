# SPA baseURL Fix (#814) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the admin SPA so surface API calls hit `/admin/_surface/*` instead of `/_surface/*` at root.

**Architecture:** Use the existing `runtimeConfig.public.baseUrl` to prefix the surface path. Fix its default from `''` to `'/admin'`. Fix the stale routeRules proxy path.

**Tech Stack:** Nuxt 3, TypeScript

---

### Task 1: Fix `baseUrl` default and routeRules proxy in nuxt.config.ts

**Files:**
- Modify: `packages/admin/nuxt.config.ts:30` (routeRules)
- Modify: `packages/admin/nuxt.config.ts:43` (baseUrl default)

- [ ] **Step 1: Fix routeRules proxy path**

In `packages/admin/nuxt.config.ts`, change the `_surface` routeRule from:

```typescript
'/_surface/**': { proxy: `${backendUrl}/admin/surface/**` },
```

to:

```typescript
'/_surface/**': { proxy: `${backendUrl}/admin/_surface/**` },
```

This aligns the dev proxy with the server routes renamed in #813 (alpha.99).

- [ ] **Step 2: Fix baseUrl default**

In the same file, change the `baseUrl` runtime config from:

```typescript
baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '',
```

to:

```typescript
baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '/admin',
```

- [ ] **Step 3: Commit**

```bash
git add packages/admin/nuxt.config.ts
git commit -m "fix: set baseUrl default to /admin and fix routeRules proxy path (#814)"
```

### Task 2: Build surfacePath from baseUrl in admin plugin

**Files:**
- Modify: `packages/admin/app/plugins/admin.ts:33`

- [ ] **Step 1: Change surfacePath to use baseUrl**

In `packages/admin/app/plugins/admin.ts`, change line 33 from:

```typescript
const surfacePath = '/_surface'
```

to:

```typescript
const surfacePath = `${baseUrl}/_surface`
```

This uses the `baseUrl` already extracted on line 32, producing `/admin/_surface` by default.

- [ ] **Step 2: Verify fetch calls are correct**

Confirm the two `$fetch` calls (lines 56 and 65) now resolve correctly:
- `$fetch('/admin/_surface/session', { baseURL: '/' })` → hits `/admin/_surface/session` ✓
- `$fetch('/admin/_surface/catalog', { baseURL: '/' })` → hits `/admin/_surface/catalog` ✓

The `AdminSurfaceTransportAdapter` on line 108 receives the corrected `surfacePath` as its constructor arg — no changes needed there.

- [ ] **Step 3: Commit**

```bash
git add packages/admin/app/plugins/admin.ts
git commit -m "fix: prefix surfacePath with baseUrl for correct surface API routing (#814)"
```

### Task 3: Build, tag, and update consumer app

**Files:**
- Modify (consumer): `northops-waaseyaa/composer.json` (framework version)

- [ ] **Step 1: Build the admin SPA**

```bash
cd packages/admin && npm run generate
```

Verify no build errors.

- [ ] **Step 2: Run framework tests**

```bash
cd ~/dev/waaseyaa && ./vendor/bin/phpunit
```

All tests should pass (the changes are frontend-only).

- [ ] **Step 3: Commit the built SPA assets and tag**

```bash
git add -A
git commit -m "build: regenerate admin SPA with baseURL fix (#814)"
git tag v0.1.0-alpha.100
git push origin main --tags
```

- [ ] **Step 4: Wait for Packagist index, then update consumer app**

```bash
cd ~/dev/northops-waaseyaa
composer clear-cache && composer update 'waaseyaa/*'
git add composer.lock
git commit -m "chore: update waaseyaa packages to v0.1.0-alpha.100 (SPA baseURL fix)"
```

- [ ] **Step 5: Push and deploy**

```bash
git push origin feat/phase1-sprint
```

Let GitHub Actions handle deployment.

### Task 4: Deploy Caddy config and verify E2E

- [ ] **Step 1: Deploy updated Caddy config via ansible**

```bash
cd ~/dev/northcloud-ansible
ansible-playbook webserver.yml --limit northops -t caddy
```

- [ ] **Step 2: Run E2E tests**

```bash
cd ~/dev/northops-waaseyaa
npx playwright test
```

All E2E tests should pass — surface API calls now hit the correct `/admin/_surface/*` paths.
