# CLI bin Package Relocation — Design Spec

**Date:** 2026-04-05
**Status:** Approved for implementation planning
**Author:** Russell Jones / Claudia
**Issue:** waaseyaa/cli#1

---

## Problem

Apps depending on `waaseyaa/cli` cannot run `vendor/bin/waaseyaa` after `composer install`. The bin script lives at the monorepo root (`/bin/waaseyaa`) instead of inside the `packages/cli/` package, and `packages/cli/composer.json` doesn't declare a `bin` field. Result: when `waaseyaa/cli` is split-published, the executable is missing.

## Goal

A fresh Waaseyaa app should work like:
```bash
composer create-project waaseyaa/waaseyaa my-app --stability=dev
cd my-app
vendor/bin/waaseyaa serve
```

Zero config. Match Laravel's `php artisan serve` baseline.

## Changes

### 1. Move bin script into the cli package

**From:** `/bin/waaseyaa` (monorepo root)
**To:** `packages/cli/bin/waaseyaa`

The script content stays the same except for the autoloader path lookup. Currently it walks up two parent directories looking for `vendor/autoload.php`. After the move, it needs to handle two cases:

- **Installed via Composer** (consumer app): `__DIR__/../../../autoload.php` (vendor/waaseyaa/cli/bin → vendor/autoload.php)
- **Monorepo dev**: `__DIR__/../../../vendor/autoload.php` (packages/cli/bin → packages/cli → packages → root → vendor)

The existing script already has multi-path autoloader detection. We just need to add the new monorepo dev path.

### 2. Declare bin in packages/cli/composer.json

Add to `packages/cli/composer.json`:
```json
"bin": ["bin/waaseyaa"]
```

This tells Composer to symlink `packages/cli/bin/waaseyaa` to `vendor/bin/waaseyaa` in any consuming project.

### 3. Replace monorepo root bin/waaseyaa with shim

Keep `/bin/waaseyaa` at the monorepo root as a one-line wrapper that delegates to `packages/cli/bin/waaseyaa`. This preserves existing monorepo dev workflows that call `bin/waaseyaa` from the root.

```bash
#!/usr/bin/env bash
exec "$(dirname "$0")/../packages/cli/bin/waaseyaa" "$@"
```

### 4. Update split workflow (if needed)

If the split workflow uses git filter-branch or git subtree, the relocation should be picked up automatically since the file lives within `packages/cli/`. No workflow changes required (assuming standard split-read).

If the split workflow has explicit file lists or excludes, we'll need to verify it picks up `packages/cli/bin/waaseyaa`.

## Backwards Compatibility

- Monorepo root `bin/waaseyaa` still works (shim delegates)
- Existing scripts/CI calling `bin/waaseyaa` from monorepo root: unaffected
- Consumer apps with `vendor/bin/waaseyaa`: now works (previously broken)
- No PHP API changes

## Testing

Manual verification:
1. From monorepo root: `bin/waaseyaa --version` (or `serve`) — should work via shim
2. From package dir: `packages/cli/bin/waaseyaa --version` — should work directly
3. In a consumer project (Giiken): after `composer update`, `vendor/bin/waaseyaa serve` — should work

No new automated tests for this change. The script is shell glue; if it can't find the autoloader it fails loudly.

## Files Changed

| File | Change |
|------|--------|
| `packages/cli/bin/waaseyaa` | New file (moved from monorepo root) |
| `packages/cli/composer.json` | Add `"bin": ["bin/waaseyaa"]` |
| `bin/waaseyaa` | Replace PHP script with bash shim delegating to packages/cli |
