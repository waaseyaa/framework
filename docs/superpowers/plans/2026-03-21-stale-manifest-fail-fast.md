# Stale Manifest Fail-Fast Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Waaseyaa fail fast on stale provider manifests and allow `optimize:manifest` to recover package discovery without normal provider boot.

**Architecture:** Add a dedicated stale-manifest exception and validate cached provider classes before provider boot. Refactor the console kernel so a minimal command set, including `optimize:manifest`, can run even when the cached manifest is stale, while normal commands still stop with a targeted recovery error.

**Tech Stack:** PHP 8.4, PHPUnit 10, Symfony Console, Waaseyaa foundation discovery/kernel packages

---

### File Structure

**Files likely to change**
- Create: `packages/foundation/src/Discovery/StaleManifestException.php`
- Modify: `packages/foundation/src/Discovery/PackageManifestCompiler.php`
- Modify: `packages/foundation/src/Kernel/AbstractKernel.php`
- Modify: `packages/foundation/src/Kernel/ConsoleKernel.php`
- Modify: `packages/cli/src/Command/Optimize/OptimizeManifestCommand.php`
- Modify: `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`
- Create or Modify: `packages/foundation/tests/Unit/Discovery/StaleManifestExceptionTest.php`
- Create or Modify: `packages/foundation/tests/Unit/Kernel/ConsoleKernel...Test.php`
- Create or Modify: `packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php`

### Task 1: Lock the stale-manifest exception contract

**Files:**
- Create or Modify: `packages/foundation/tests/Unit/Discovery/StaleManifestExceptionTest.php`
- Create: `packages/foundation/src/Discovery/StaleManifestException.php`

- [ ] **Step 1: Write the failing test**
Create a unit test asserting the exception preserves `missingProviders`, `manifestPath`, `recoveryCommand`, and emits a targeted operator-facing message.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/StaleManifestExceptionTest.php`
Expected: FAIL because the exception class does not exist yet.

- [ ] **Step 3: Write minimal implementation**
Add `StaleManifestException` as a dedicated exception type with stable accessors/message formatting.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/StaleManifestExceptionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Discovery/StaleManifestException.php packages/foundation/tests/Unit/Discovery/StaleManifestExceptionTest.php
git commit -m "feat: add stale manifest exception"
```

### Task 2: Validate cached provider classes when loading the manifest

**Files:**
- Modify: `packages/foundation/src/Discovery/PackageManifestCompiler.php`
- Modify: `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`

- [ ] **Step 1: Write the failing test**
Add a compiler test that writes a cached manifest containing a missing provider class and asserts `load()` throws `StaleManifestException` instead of silently returning the stale manifest.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php --filter stale`
Expected: FAIL because `load()` currently accepts the stale manifest.

- [ ] **Step 3: Write minimal implementation**
Add provider validation in the cache-loading path and throw `StaleManifestException` with the manifest path and recovery command.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php --filter stale`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifestCompiler.php packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php
git commit -m "feat: fail fast on stale provider manifests"
```

### Task 3: Make console recovery commands reachable in degraded mode

**Files:**
- Modify: `packages/foundation/src/Kernel/ConsoleKernel.php`
- Modify: `packages/foundation/src/Kernel/AbstractKernel.php`
- Modify: `packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php`
- Create or Modify: `packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php`

- [ ] **Step 1: Write the failing test**
Add a kernel-level test proving that with a stale cached manifest:
1. `optimize:manifest` remains runnable
2. non-recovery commands surface the stale-manifest error instead of misleading output

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php`
Expected: FAIL because the current kernel boots before command registration can recover.

- [ ] **Step 3: Write minimal implementation**
Refactor console startup so a minimal command set is registered before full boot, and gate full-boot-dependent commands behind successful manifest/provider validation.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Kernel/AbstractKernel.php packages/foundation/src/Kernel/ConsoleKernel.php packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php
git commit -m "feat: allow manifest recovery from degraded console boot"
```

### Task 4: Verify end-to-end console behavior

**Files:**
- Modify: `packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php`
- Modify: `packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php`

- [ ] **Step 1: Write the failing test**
Extend tests so stale manifests produce a targeted error message that names the missing provider and remediation command for non-recovery commands.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php`
Expected: FAIL until the exact message/exit behavior matches the contract.

- [ ] **Step 3: Write minimal implementation**
Adjust console error handling and recovery command output to match the approved operator-facing contract.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/tests/Unit/Kernel/ConsoleKernelStaleManifestTest.php packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php packages/foundation/src/Kernel/ConsoleKernel.php
git commit -m "fix: clarify stale manifest recovery diagnostics"
```

### Task 5: Final verification

**Files:**
- Modify: relevant touched files only

- [ ] **Step 1: Run focused tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery packages/foundation/tests/Unit/Kernel packages/cli/tests/Unit/Command/Optimize`
Expected: PASS

- [ ] **Step 2: Run broader safety check**

Run: `./vendor/bin/phpunit --filter \"ConsoleKernel|PackageManifestCompiler|OptimizeManifestCommand\"`
Expected: PASS

- [ ] **Step 3: Review worktree state**

Run: `git status --short`
Expected: only intended framework changes remain
