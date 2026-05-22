---
work_package_id: WP01
title: Package scaffold + AgentDetector
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-010
- NFR-001
- C-001
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main.
subtasks:
- T001
- T002
- T003
history: []
authoritative_surface: packages/agent-output/
execution_mode: code_change
owned_files:
- packages/agent-output/composer.json
- packages/agent-output/README.md
- packages/agent-output/src/AgentDetector.php
- packages/agent-output/tests/Unit/AgentDetectorTest.php
- packages/agent-output/tests/Unit/AgentDetectorTimingTest.php
tags: []
---

## Objective

Stand up the new `packages/agent-output/` Layer-0 package with the canonical Waaseyaa package shape, plus the `AgentDetector` env-var lookup that gates every later formatter activation. This WP is the foundation for every other M4 WP — it must land cleanly with full layer + composer-policy compliance before any formatter work begins.

## Subtasks

### T001 — `composer.json` and package shape

Create `packages/agent-output/composer.json`:

- name `waaseyaa/agent-output`, type `library`, license matches the framework's standard.
- `require: { "php": ">=8.5" }` — no `waaseyaa/*` deps (C-001 Layer-0 invariant).
- `require-dev: { "phpunit/phpunit": "^10.5" }`.
- `autoload.psr-4: { "Waaseyaa\\AgentOutput\\": "src/" }`.
- `autoload-dev.psr-4: { "Waaseyaa\\AgentOutput\\Tests\\": "tests/" }`.
- `extra.branch-alias: { "dev-main": "0.1.x-dev" }`.
- `config.sort-packages: true`.

Add a one-section `README.md` so the package is discoverable.

### T002 — `AgentDetector::detect()`

Create `packages/agent-output/src/AgentDetector.php`. Constructor takes no args. Single public method `detect(): ?string` reads from a class-constant map (8 env vars per spec FR-002) and returns the first match's identifier. Use `getenv()` (not `$_ENV` — agent runtimes may not populate the superglobal). NFR-001 budget: ≤ 1 ms — this is trivially achieved since the method does only `count(self::CLIENTS)` env lookups.

Mark the class `@api` so the dead-code gate doesn't flag it before consumers wire up.

### T003 — Unit tests

`AgentDetectorTest` (FR-010):
- One method per documented env var asserting the right client identifier returns.
- `returnsNullWhenNoEnvVarsSet`.
- `returnsNullForUnknownClient` (sets `CURSOR_NEXT=1`, asserts `null`).

`AgentDetectorTimingTest` (NFR-001):
- Microbenchmark — 100 invocations, assert median ≤ 1 ms. Soft warning at 0.5 ms; hard fail at 5 ms. Same shape as M1's `generateCompletesWithinNfrBudget`.

## Definition of Done

- [ ] `packages/agent-output/composer.json` passes `bin/check-composer-policy` (sort-packages, no @dev, no wildcards).
- [ ] `bin/check-package-layers` passes (no `waaseyaa/*` deps — package is Layer 0).
- [ ] `composer cs-check`, `composer phpstan` clean on touched files.
- [ ] All `AgentDetectorTest` methods pass; timing test asserts median ≤ 1 ms.
- [ ] Dead-code gate clean (no new entries beyond baseline).

## Risks and notes

- **First-time package on the layer scale:** Layer 0 packages are tightly scoped. Confirm no accidental import of `Waaseyaa\Foundation\*` — `agent-output` predates foundation in the layer table and must remain so.
- **`getenv()` vs `$_ENV` vs `$_SERVER`:** Different runtimes populate different sources. `getenv()` reads the process environment directly and is reliable across the documented clients.
- **Branch-alias dev key:** Match the existing pattern across other Waaseyaa packages (`packages/foundation/composer.json` is the canonical reference).
