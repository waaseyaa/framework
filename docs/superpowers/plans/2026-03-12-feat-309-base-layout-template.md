# Issue #309 — Base Layout Template Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan.

**Goal:** Add a base layout template with named blocks so all SSR page templates share consistent HTML scaffolding.

**Architecture:** Single base.html.twig in `packages/ssr/templates/layouts/` with named Twig blocks. Existing page-level templates (page.html.twig, 404.html.twig) updated to extend it. No new PHP classes — Twig's template inheritance is the mechanism.

**Tech Stack:** PHP 8.4, Twig 3.x, PHPUnit 10.5

---

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Create | `packages/ssr/templates/layouts/base.html.twig` | Base HTML layout with named blocks |
| Modify | `packages/ssr/templates/page.html.twig` | Extend base layout |
| Modify | `packages/ssr/templates/404.html.twig` | Extend base layout |
| Create | `packages/ssr/tests/Unit/Layout/BaseLayoutTest.php` | Verify layout blocks, extension, asset() integration |

## Steps

- [ ] Step 1: Write failing tests
- [ ] Step 2: Create base layout template
- [ ] Step 3: Update page.html.twig and 404.html.twig to extend base
- [ ] Step 4: Run tests — all pass
- [ ] Step 5: Run full suite + PHPStan
- [ ] Step 6: Commit
