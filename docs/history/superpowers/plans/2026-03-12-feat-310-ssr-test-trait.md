# Issue #310 — SSR InteractsWithRenderer Test Trait

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan.

**Goal:** Add a PHPUnit trait that simplifies SSR rendering tests with render(), assertRenderContains(), and assertRenderMatches() helpers.

**Architecture:** Trait in SSR test namespace uses Twig ArrayLoader for in-memory template rendering. No container boot needed — trait creates a lightweight Twig Environment on demand. Filesystem templates supported via optional addTemplatePath().

**Tech Stack:** PHP 8.4, Twig 3.x, PHPUnit 10.5

---

## File Structure

| Action | Path | Responsibility |
|---|---|---|
| Create | `packages/ssr/tests/Support/InteractsWithRenderer.php` | Trait: render(), assertRenderContains(), assertRenderMatches() |
| Create | `packages/ssr/tests/Support/InteractsWithRendererTest.php` | Unit tests for all trait methods |
| Create | `packages/ssr/tests/fixtures/greeting.html.twig` | Minimal fixture for filesystem render tests |

## Steps

- [ ] Step 1: Write failing tests
- [ ] Step 2: Implement trait
- [ ] Step 3: Run tests — all pass
- [ ] Step 4: Full suite + PHPStan
- [ ] Step 5: Commit
