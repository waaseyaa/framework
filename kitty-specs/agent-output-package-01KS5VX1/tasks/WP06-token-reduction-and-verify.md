---
work_package_id: WP06
title: Token-reduction verification + composer verify
dependencies:
- WP05
requirement_refs:
- NFR-004
- SC-001
- SC-006
- C-006
- C-007
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main.
subtasks:
- T022
- T023
history: []
authoritative_surface: kitty-specs/agent-output-package-01KS5VX1/
execution_mode: planning_artifact
owned_files:
- kitty-specs/agent-output-package-01KS5VX1/verification.md
- tests/Integration/PhaseN/AgentOutput/TokenReductionSmokeTest.php
tags: []
---

## Objective

Verify the headline ≥90% character-reduction claim (NFR-004 / SC-001) with a real measurement, then run the full local gate sweep and document the result in `verification.md`. This is the closing WP — it makes the mission's value proposition empirical, not aspirational.

## Subtasks

### T022 — `TokenReductionSmokeTest`

`tests/Integration/PhaseN/AgentOutput/TokenReductionSmokeTest.php`:

```php
public function jsonOutputIsAtLeast90PercentSmallerThanStandard(): void
{
    $standard = $this->runCommand('vendor/bin/phpunit packages/bimaaji/ --no-coverage', env: []);
    $jsonOut  = $this->runCommand('vendor/bin/phpunit packages/bimaaji/ --no-coverage --output=json', env: []);

    $standardBytes = strlen($standard);
    $jsonBytes     = strlen($jsonOut);

    $reduction = 1.0 - ($jsonBytes / $standardBytes);
    self::assertGreaterThanOrEqual(
        0.90,
        $reduction,
        sprintf('SC-001: expected ≥ 90%% reduction, got %.2f%% (standard=%d bytes, json=%d bytes)', $reduction * 100, $standardBytes, $jsonBytes),
    );
}
```

Helper `runCommand()` executes via `proc_open` and captures stdout. The fixture is intentionally the bimaaji test suite (large enough that the reduction ratio is meaningful, small enough to be quick).

### T023 — Verification log

Create `kitty-specs/agent-output-package-01KS5VX1/verification.md` documenting:

- Local gate sweep results (cs-check, phpstan, layer, composer-policy, dead-code, getQuery, full `composer verify` exit code).
- Test surface summary: unit (`AgentDetectorTest` + timing), contract (8 formatters × ~6 methods), integration (4 tests including TokenReductionSmokeTest).
- Empirical NFR-004 measurement: standard bytes, json bytes, reduction ratio.
- SC-005 third-party-formatter smoke test result.
- Mission PR provenance (WP01..WP06 PR numbers).
- New-package release checklist completion status: split.yml entry ✅, GitHub repo provisioning (manual), Packagist registration (manual, post-tag).

## Definition of Done

- [ ] `TokenReductionSmokeTest::jsonOutputIsAtLeast90PercentSmallerThanStandard` passes.
- [ ] `composer verify` exits 0 on the mission branch tip.
- [ ] `verification.md` records all gate exit codes + the empirical NFR-004 measurement.
- [ ] No commit in the mission branch used `--no-verify` (C-007).

## Risks and notes

- **Empirical 90% threshold:** PHPUnit verbose output on bimaaji is typically multi-KB; a 200-byte envelope is trivially ≥90% smaller. If the threshold fails, the formatter is leaking too much per-test detail in pass-path envelopes — fix the formatter, don't lower the threshold.
- **proc_open vs `shell_exec`:** Use `proc_open` so stdout and stderr stay separated — the test must assert the envelope is on stdout only.
- **CI vs local:** The reduction ratio can differ between CI (no color) and local (color escape codes inflate standard output). Both should still hit 90%; if one doesn't, document the difference in verification.md.
