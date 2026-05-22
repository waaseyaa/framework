---
work_package_id: WP04
title: Integration tests
dependencies:
- WP03
requirement_refs:
- FR-011
- FR-012
- SC-001
- SC-002
- SC-003
- SC-004
- SC-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T011
- T012
- T013
- T014
- T015
history: []
authoritative_surface: tests/Integration/PhaseN/BimaajiInstall/
execution_mode: code_change
owned_files:
- tests/Integration/PhaseN/BimaajiInstall/InstallCommandTest.php
- tests/Integration/PhaseN/BimaajiInstall/InstallCommandDryRunTest.php
- tests/Integration/PhaseN/BimaajiInstall/InstallCommandPreservesHandEditsTest.php
- tests/Integration/PhaseN/BimaajiInstall/InstallCommandSandboxTest.php
- tests/Integration/PhaseN/BimaajiInstall/InstallCommandUnknownClientTest.php
tags: []
---

## Objective

Five integration tests covering the full command surface. Each test runs the command against a sandbox temp directory and asserts behavior — no test touches the consumer's real project root.

## Subtasks

### T011 — `InstallCommandTest` (positive path, FR-011)

Parameterized via `#[DataProvider]` over the 7 launch clients. For each client:

1. Create a temp sandbox: `sys_get_temp_dir() . '/bimaaji-install-' . uniqid()`.
2. Run `bimaaji:install --client=<id> --force` against the sandbox.
3. Assert: target files exist at the documented paths; each file is non-empty; sha1 matches transformer output.
4. Re-run with `--force`; assert: same byte content (`sha1` equal); summary reports "unchanged" not "written" (SC-003).

### T012 — `InstallCommandDryRunTest` (FR-012, SC-004)

1. Create sandbox; snapshot tree (recursive file list + sha1s).
2. Run `bimaaji:install --client=claude --dry-run`.
3. Assert: stdout lists target files; stderr is empty.
4. Assert: re-snapshot tree is byte-identical to before-snapshot.
5. Run again without `--dry-run` and `--force`; assert: the actual writes match the dry-run-listed paths exactly.

### T013 — `InstallCommandPreservesHandEditsTest` (FR-006, SC-005)

1. Sandbox + run `bimaaji:install --client=claude --force`. Capture written target.
2. Hand-edit one target file (append a unique marker string).
3. Re-run `bimaaji:install --client=claude` (no `--force`). Provide `s` (skip) to the interactive prompt.
4. Assert: edited file still contains the unique marker. Summary reports "skipped".
5. Re-run with `--force`. Assert: edited file no longer contains the marker (overwritten). Summary reports "written".

Use a stdin fixture for the non-`--force` prompt — the framework's CLI testing harness should support feeding interactive responses (Symfony's `CommandTester::setInputs()` pattern or equivalent).

### T014 — `InstallCommandSandboxTest` (NFR-002)

1. Sandbox. Inside the sandbox, create a `forbidden/` directory.
2. Pre-create `$projectRoot/../forbidden-outside-sandbox/` (parent dir of sandbox).
3. Run `bimaaji:install --client=claude --force`.
4. Assert: writes only occur inside `$projectRoot`. Use `find $tempParent -newer $sentinel` to check.
5. Bonus: artificially modify a target path in the test to escape the sandbox (e.g. inject `../../etc/foo`). Assert the command refuses + exits non-zero.

### T015 — `InstallCommandUnknownClientTest` (FR-008, NFR-004)

1. Sandbox. Run `bimaaji:install --client=clade --force` (typo).
2. Assert: exit code ≠ 0.
3. Assert: stderr contains "Did you mean 'claude'?" (Levenshtein suggestion).
4. Repeat with `--client=zzzzzzzzzz` (no close match). Assert: stderr lists all supported clients, no `Did you mean` (distance too far).

## Definition of Done

- [ ] All 5 tests pass.
- [ ] Tests are sandboxed via `sys_get_temp_dir()` — never touch the consumer or worktree root.
- [ ] Test fixtures use small inline skill content, not the live `skills/waaseyaa/*`.
- [ ] All local gates clean (cs-check, phpstan, layer, composer-policy, dead-code, getQuery).

## Risks and notes

- **Test sandbox cleanup:** Use `tearDown()` to recursively remove the temp dir. If a test fails mid-write, the dir is left for debugging — that's acceptable, just note it.
- **stdin fixtures for interactive prompts:** Symfony's `CommandTester::setInputs()` writes to the input stream. The framework's CLI testing harness uses a similar pattern via `CliTester::executeMap()` or equivalent — check the existing pattern in `packages/cli/tests/`.
- **`InstallCommandSandboxTest`'s injection path:** The "escape the sandbox" assertion may require a special transformer test double that emits an out-of-sandbox target path. Document the test mechanism clearly so reviewers don't think this is a real attack vector.
