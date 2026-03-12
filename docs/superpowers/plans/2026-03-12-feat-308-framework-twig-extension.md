# Issue #308 — Framework Twig Extension Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `WaaseyaaExtension` Twig extension providing `asset()`, `config()`, and `env()` template functions.

**Architecture:** Single extension class with constructor-injected dependencies (`ConfigFactoryInterface`, asset base path, env whitelist). Registered in `ThemeServiceProvider::createTwigEnvironment()`. Config dependency added to SSR package (layer 6 → layer 1, valid).

**Tech Stack:** PHP 8.4, Twig 3.x, PHPUnit 10.5

---

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Create | `packages/ssr/src/Twig/WaaseyaaExtension.php` | Twig extension: `asset()`, `config()`, `env()` |
| Create | `packages/ssr/tests/Unit/Twig/WaaseyaaExtensionTest.php` | Unit tests for all 3 functions + error cases |
| Modify | `packages/ssr/src/ThemeServiceProvider.php:48-61` | Register WaaseyaaExtension on the Twig Environment |
| Modify | `packages/ssr/composer.json:17-23` | Add `waaseyaa/config` to require |

## Task 1: Write Tests → Implement → Wire

- [ ] Step 1: Write failing tests for all 3 functions
- [ ] Step 2: Run tests — verify they fail (class not found)
- [ ] Step 3: Implement WaaseyaaExtension
- [ ] Step 4: Run tests — verify they pass
- [ ] Step 5: Wire into ThemeServiceProvider + add config dependency
- [ ] Step 6: Run full test suite — no regressions
- [ ] Step 7: Run PHPStan
- [ ] Step 8: Commit
