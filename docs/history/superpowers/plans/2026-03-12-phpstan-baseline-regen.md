# PHPStan Baseline Regeneration Plan

> **For agentic workers:** REQUIRED: Use superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Regenerate the PHPStan baseline after v1.0 decomposition (HttpKernel 2266→265L, McpController 1650→265L) to remove stale ignores and ensure accuracy.

**Architecture:** Single-file update (`phpstan-baseline.neon`) on main branch. No code changes.

**Tech Stack:** PHPStan level 5, `phpstan.neon` config

---

### Task 1: Regenerate and verify PHPStan baseline

- [ ] **Step 1: Review current baseline size**
  Run: `wc -l phpstan-baseline.neon`

- [ ] **Step 2: Regenerate baseline**
  Run: `./vendor/bin/phpstan analyse --generate-baseline`
  Expected: New `phpstan-baseline.neon` written

- [ ] **Step 3: Review new baseline size**
  Run: `wc -l phpstan-baseline.neon`
  Expected: Fewer entries than before (stale decomposition ignores removed)

- [ ] **Step 4: Verify no stale ignores remain**
  Run: `./vendor/bin/phpstan analyse`
  Expected: No errors, no "ignored error not matched" warnings

- [ ] **Step 5: Spot-check baseline for old file paths**
  Verify no references to pre-decomposition monolithic methods in HttpKernel.php or McpController.php that no longer exist.

- [ ] **Step 6: Commit**
  ```bash
  git add phpstan-baseline.neon
  git commit -m "chore: regenerate PHPStan baseline after v1.0 decomposition"
  ```
