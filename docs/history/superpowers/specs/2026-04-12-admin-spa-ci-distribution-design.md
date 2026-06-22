# Admin SPA CI/CD Build & Distribution

**Date:** 2026-04-12
**Status:** Approved
**Goal:** Every `composer create-project waaseyaa/waaseyaa` install has a working `/admin` surface without requiring Node.js.

## Problem

After `create-project`, `public/admin/index.html` doesn't exist. Users see a fallback page listing API endpoints. Building the SPA requires Node.js — an unacceptable friction for PHP-only consumers.

## Solution

Pre-build the Nuxt 4 admin SPA in CI and ship the built assets inside the `waaseyaa/admin-surface` Composer package at `dist/`. The PHP service provider falls back to vendor-shipped assets when no app-level build exists.

## Architecture

### Asset location

Built SPA output (`index.html`, `_nuxt/` chunks) lives at `packages/admin-surface/dist/` in the monorepo. The existing `split.yml` splitsh-lite pipeline already splits `packages/admin-surface` to its own repo — `dist/` ships automatically via Packagist.

### PHP serving (two-tier lookup)

`AdminSurfaceServiceProvider` SPA catch-all route:

1. **App override:** `$projectRoot/public/admin/index.html` — checked first (existing behavior)
2. **Vendor fallback:** `__DIR__ . '/../dist/index.html'` — new, serves from `vendor/waaseyaa/admin-surface/dist/`

This preserves the ability for apps to override with their own build while providing a working default.

### CI workflow

New workflow `.github/workflows/admin-dist.yml`:

- **Trigger:** Push to `main` when `packages/admin/**` changes, plus `workflow_dispatch`
- **Node:** v22 (matches existing `admin.yml`)
- **Build:** `npm ci && npm run build` in `packages/admin/`
- **Copy:** `.output/public/*` → `packages/admin-surface/dist/`
- **Diff check:** Skip commit if `dist/` unchanged
- **Commit:** Push to branch `admin-dist/update`
- **PR:** Open (or update existing) PR targeting `main`
- **Tagging:** Not handled — the existing release pipeline (`release.yml` + `split.yml`) handles tags. Built assets land on `main` via merged PR and are included in the next tag.

### Permissions

Workflow needs `contents: write` and `pull-requests: write`.

## Files Changed

| File | Change |
|------|--------|
| `.github/workflows/admin-dist.yml` | New — CI build, commit, PR workflow |
| `packages/admin-surface/src/AdminSurfaceServiceProvider.php` | Add vendor `dist/` fallback in SPA catch-all |
| `.gitignore` | Allow `packages/admin-surface/dist/` to be tracked |
| `packages/admin-surface/.gitignore` | New — baseline ignores for the package (dist/ tracked by CI) |

## End-User Experience

```
composer create-project waaseyaa/waaseyaa myapp --stability=dev
cd myapp
composer run dev
# Visit /admin → working SPA, no Node.js required
```

## Properties

- **Deterministic builds:** `npm ci` uses lockfile, pinned Node version
- **Reproducible assets:** Same source commit always produces same `dist/` content
- **Frictionless DX:** Zero Node.js requirement for end users
- **Override path:** Apps can still build their own SPA into `public/admin/` to override vendor assets
- **No skeleton bloat:** Assets live in admin-surface package, not the project template
