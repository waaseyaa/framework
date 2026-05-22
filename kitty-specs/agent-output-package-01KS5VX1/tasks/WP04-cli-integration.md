---
work_package_id: WP04
title: CLI integration (--output=json + auto-detect)
dependencies:
- WP03
requirement_refs:
- FR-005
- FR-006
- FR-009
- FR-012
- FR-013
- SC-002
- C-002
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main.
subtasks:
- T015
- T016
- T017
- T018
history: []
authoritative_surface: tests/Integration/PhaseN/AgentOutput/
execution_mode: code_change
owned_files:
- phpunit.xml.dist
- phpstan.neon
- bin/check-package-layers
- bin/check-dead-code
- bin/check-getquery-bindings
- bin/check-composer-policy
- tools/drift-detector.sh
- tests/Integration/PhaseN/AgentOutput/PhpUnitJsonOutputTest.php
- tests/Integration/PhaseN/AgentOutput/PackageLayersJsonOutputTest.php
- tests/Integration/PhaseN/AgentOutput/HumanOutputUnchangedTest.php
tags: []
---

## Objective

Wire each affected CLI command to detect agent env / `--output=json` and route output through the right formatter. This is the highest-touch WP — it edits several scripts and config files. Integration tests verify the round-trip.

## Subtasks

### T015 — PHPUnit Printer / Subscriber

PHPUnit 10's event subscriber API is the right hook. Add a Subscriber class under `packages/agent-output/src/Listener/PhpUnitEventSubscriber.php` (or similar) that listens for `TestPassed`, `TestFailed`, `TestSuiteFinished`. Register via `phpunit.xml.dist`'s `<extensions>` block.

The subscriber activates only when `AgentDetector::detect() !== null` OR `WAASEYAA_OUTPUT=json` OR `--output=json` passed. Otherwise it's a no-op (default PHPUnit output preserved — C-002).

### T016 — PHPStan custom error format

`phpstan.neon`: register a custom error formatter class via the `errorFormatters` key. The formatter activates the same way as T015. PHPStan calls into the formatter at the end of the run with the full error set.

### T017 — `bin/check-*` PHP CLI scripts

For each of `check-package-layers`, `check-dead-code`, `check-getquery-bindings`, `check-composer-policy`:

1. Add `--output=json` flag parsing at the start of the script.
2. Activate the relevant formatter when flag is set OR env-detected.
3. At end-of-run, emit the formatter's envelope to stdout instead of (or in addition to) the existing human output.
4. Keep exit codes unchanged.

For `tools/drift-detector.sh` (shell): wrap the existing output with a tiny PHP post-processor invoked when the flag is set. Or pipe to `php bin/agent-output-format drift-detector` (a thin wrapper that reads stdin → calls `DriftDetectorFormatter::parseRawOutput` → writes envelope).

### T018 — Integration tests

`PhpUnitJsonOutputTest` (FR-012):
- Runs `vendor/bin/phpunit --output=json` over `packages/agent-output/tests/Fixture/SuiteWithFailures/`
- Captures stdout, parses NDJSON
- Asserts envelope is well-formed and reflects the suite outcome
- Asserts no envelope on stderr

`PackageLayersJsonOutputTest` (FR-013):
- Runs `bin/check-package-layers --output=json` against the live monorepo
- Asserts `result: pass` (or `fail` with the live violation set)

`HumanOutputUnchangedTest` (SC-002):
- Runs each affected command without agent env + without `--output=json`
- Asserts NO JSON envelope is emitted (negative assertion)
- Snapshots a few key signal strings from each command's expected human output (e.g. PHPUnit's "OK, but there were issues!" footer) to detect accidental regressions

## Definition of Done

- [ ] PHPUnit, PHPStan, and all 4 `bin/check-*` scripts honor `--output=json` + agent-env auto-detect.
- [ ] `tools/drift-detector.sh` either accepts the flag or is documented as opt-in via post-processor.
- [ ] All 3 integration tests pass.
- [ ] Human output is preserved when no agent env / no flag.
- [ ] `composer verify` exit code unchanged from current behavior.

## Risks and notes

- **`composer verify` NDJSON discipline:** Confirm each gate's envelope lands on its own line in the parent's stdout. If composer's script runner interleaves with `> @check-X` progress lines, suppress those under `WAASEYAA_OUTPUT=json` via a small wrapper.
- **`phpunit.xml.dist` registration:** The extension block is loaded by every PHPUnit run, including human runs. Make sure the subscriber is a no-op when no activation signal is present (otherwise it impacts NFR-001's "no overhead" claim for humans).
- **PHPStan formatter discovery:** `phpstan.neon`'s `errorFormatters` key references the formatter class by FQCN — confirm the class is autoloadable in the phpstan binary's classpath (it is if the package is in the root composer.json's `require` or `require-dev`).
