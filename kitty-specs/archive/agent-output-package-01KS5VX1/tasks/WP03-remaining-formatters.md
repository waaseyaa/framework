---
work_package_id: WP03
title: Remaining 7 formatters
dependencies:
- WP02
requirement_refs:
- FR-004
- FR-008
- FR-011
- NFR-003
- C-001
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main.
subtasks:
- T008
- T009
- T010
- T011
- T012
- T013
- T014
history: []
authoritative_surface: packages/agent-output/src/Formatter/
execution_mode: code_change
owned_files:
- packages/agent-output/src/Formatter/PestFormatter.php
- packages/agent-output/src/Formatter/PhpStanFormatter.php
- packages/agent-output/src/Formatter/PackageLayersFormatter.php
- packages/agent-output/src/Formatter/DeadCodeFormatter.php
- packages/agent-output/src/Formatter/GetQueryBindingsFormatter.php
- packages/agent-output/src/Formatter/ComposerPolicyFormatter.php
- packages/agent-output/src/Formatter/DriftDetectorFormatter.php
- packages/agent-output/tests/Contract/PestFormatterTest.php
- packages/agent-output/tests/Contract/PhpStanFormatterTest.php
- packages/agent-output/tests/Contract/PackageLayersFormatterTest.php
- packages/agent-output/tests/Contract/DeadCodeFormatterTest.php
- packages/agent-output/tests/Contract/GetQueryBindingsFormatterTest.php
- packages/agent-output/tests/Contract/ComposerPolicyFormatterTest.php
- packages/agent-output/tests/Contract/DriftDetectorFormatterTest.php
tags: []
---

## Objective

Ship the remaining seven formatters. Each follows the same shape as `PhpUnitFormatter` (WP02). Each gets a contract test covering pass / fail / empty.

## Subtasks

Each subtask is one formatter + its contract test. Total: 7 subtasks (T008..T014).

### Per-formatter event shape

| Formatter | `result` field source | Failure detail (FR-008) |
|---|---|---|
| `PestFormatter` | `tests_failed > 0` | per-test file/line/message |
| `PhpStanFormatter` | `errors > 0` | per-error file/line/identifier/message |
| `PackageLayersFormatter` | non-empty `violations` | per-violation source/target/edge |
| `DeadCodeFormatter` | new findings beyond baseline | per-finding FQCN/file/line |
| `GetQueryBindingsFormatter` | new offenders beyond baseline | per-offender file/line |
| `ComposerPolicyFormatter` | any rule failure | per-failure file/rule-code/explanation |
| `DriftDetectorFormatter` | non-empty drift list | per-drift spec-file/last-touch |

### Per-formatter contract test pattern

Each `*FormatterTest`:
1. `formatsPassingRun`
2. `formatsFailingRun` with at least one structured failure detail
3. `formatsEmptyRun`
4. `outputIsValidNdjson` — one line, terminates with `\n`, JSON round-trip clean
5. `envelopeUnder500BytesForPass` (NFR-003 passing-envelope size)
6. `failureEnvelopeUnder2KbPerEntry` (NFR-003 failure-size median)

## Definition of Done

- [ ] All 7 formatter classes implement `FormatterInterface`.
- [ ] All 7 contract tests pass (≥ 6 methods each).
- [ ] `composer cs-check`, `phpstan`, layer + dead-code gates clean.
- [ ] Each formatter handles the failure shape (FR-008) — generic "result: fail" without details is insufficient.

## Risks and notes

- **DriftDetectorFormatter** is special: drift-detector is a shell script. The formatter parses post-hoc stdout text via a `parseRawOutput(string): array` method. Contract test feeds fixture stdout strings rather than running the live detector.
- **PestFormatter** shares much with PhpUnitFormatter (Pest builds on PHPUnit). Either extract a small base class or duplicate — both are acceptable. If extracted, the base class lives in `packages/agent-output/src/Formatter/AbstractTestRunnerFormatter.php` and is `@api`-marked.
- **Baseline-aware formatters** (`DeadCode`, `GetQueryBindings`): the event payload must distinguish "baselined finding (silent)" from "new finding (loud)". The check scripts already make this distinction at runtime — the formatter just needs to surface the new-only count.
