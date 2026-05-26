---
work_package_id: WP03
title: Scheduled retention jobs (purge, redact, hold-scan), best-effort wrapping, FR-015 integration test with dead-code guard
dependencies:
- WP01
- WP02
requirement_refs:
- FR-009
- FR-010
- FR-011
- FR-012
- FR-015
- NFR-004
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-M
- T-N
- T-O
- T-P
- T-Q
- T-R
history: []
authoritative_surface: packages/field/src/Classification/Job
execution_mode: code_change
owned_files:
- packages/field/src/Classification/Schedule/ClassificationRetentionScheduleEntries.php
- packages/field/src/Classification/Job/PurgeJob.php
- packages/field/src/Classification/Job/RedactJob.php
- packages/field/src/Classification/Job/HoldScanJob.php
- packages/field/tests/Unit/Classification/Schedule/ClassificationRetentionScheduleEntriesTest.php
- packages/field/tests/Unit/Classification/Job/PurgeJobTest.php
- packages/field/tests/Unit/Classification/Job/RedactJobTest.php
- packages/field/tests/Unit/Classification/Job/HoldScanJobTest.php
- packages/field/tests/Unit/Classification/Job/BestEffortTest.php
- tests/Integration/PhaseClassificationRetention/ClassificationRetentionIntegrationTest.php
tags:
- substrate
- classification
- retention
- scheduler
- integration-test
agent: "claude:opus:reviewer2:reviewer"
shell_pid: "506289"
---

# WP03 — Scheduled retention jobs + integration test

**Mission:** `classification-retention-engine-01KSEFTH`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01, WP02.

## Pattern references — READ FIRST

- `packages/scheduler/src/ScheduleEntriesInterface.php` + `Schedule.php` + `ScheduledTask.php` — schedule-entries contract per CLAUDE.md §"Adding a schedule-entries class".
- `bin/waaseyaa schedule:list` — discovery surface (verify after registering).
- `packages/audit/src/Contract/AuditWriterInterface.php` + `AuditEventKind.php` — verify `retention.purge`, `retention.redact`, `classification.change` kinds exist; if any missing, the OCAP audit substrate spec.md explicitly enumerates them in the 14-case enum.
- `packages/field/src/BundleTemplateCompiler.php` — how to read field-template metadata (specifically the `pii: true` marker added in WP01).
- CLAUDE.md §Logging "best-effort side effects" — listener pattern; jobs follow the same pattern.

## Subtasks

### T-M — `ClassificationRetentionScheduleEntries`
- `packages/field/src/Classification/Schedule/ClassificationRetentionScheduleEntries.php implements ScheduleEntriesInterface`. `@api`.
- Three `ScheduledTask`s per plan.md §T-M. Each task references the corresponding job class.
- Registered via service provider per CLAUDE.md §"Adding a schedule-entries class" (mark class `@api`, declare `register()`, verify in `bin/waaseyaa schedule:list`).

### T-N — `PurgeJob`
- Per plan.md §T-N. Wraps each policy iteration in try-catch (NFR-004).
- Writes ONE `retention.purge` `AuditEventDescriptor` per deletion via `AuditWriterInterface`. The entity deletion itself fires `entity.delete` which the audit substrate's `EntityLifecycleAuditListener` writes — so each deletion produces TWO audit records (one structural lifecycle, one retention-policy attribution). Documented in spec.md.
- Honours `policy.exemptions` (entity UUIDs that bypass).

### T-O — `RedactJob`
- Per plan.md §T-O. For each matched entity: discover PII fields via the field-template `pii: true` metadata; null those fields; preserve structural + audit fields. Write `retention.redact` audit event with attributes `{policy_id, label_id, redacted_fields}`.
- IMPORTANT: do NOT null the `classification_label` itself (it stays for audit-trail purposes — the redacted entity remains classified).

### T-P — `HoldScanJob`
- Per plan.md §T-P. Verification-only: identifies hold-vs-purge conflicts; writes `classification.change` audit event with `attributes.conflict = 'hold_vs_purge'`; logs `notice`. Does NOT delete.

### T-Q — Unit + best-effort tests
- Per plan.md §T-Q. `BestEffortTest` is the NFR-004 proof.

### T-R — FR-015 integration test with dead-code guard
- `tests/Integration/PhaseClassificationRetention/ClassificationRetentionIntegrationTest.php` `#[CoversNothing]`.
- Boot full kernel. Seed labels (use the standard YAML import). Seed a parent entity labelled `confidential` + two child entities (one inherits, one overrides to `public`). Seed an old `public`-labelled entity (created_at > 7 days ago). Seed a `hold-legal`-labelled entity. Seed an admin account + a non-admin account + a `legal-hold-bypass` admin.
- Assert (a) inheritance: child without override carries `confidential` + `inherited_from = entity_type:parent_uuid`.
- Assert (b) override: child with override carries `public` + `inherited_from = null` + `classification_overridden_at IS NOT NULL`.
- Assert (c) `ClassificationFieldAccessPolicy::access()` on the `confidential` entity for anonymous → forbidden; for `admin` → neutral.
- Assert (d) on the `hold-legal` entity: `legal-hold-bypass` admin → neutral; non-bypass admin → forbidden.
- Seed a 7-day purge policy for `public`; run `PurgeJob::run()` directly; assert the old `public` entity is deleted; assert one `retention.purge` audit event exists with `attributes.policy_id` matching.
- Run `HoldScanJob` with a conflicting purge-+hold policy pair; assert a `classification.change` audit event with `attributes.conflict = 'hold_vs_purge'` is written.
- **Dead-code guard mechanism (FR-015 final clause):** add a code-block comment at the top of the test naming the exact line in `FieldServiceProvider` (or `Permissions.php` / policy attribute) that, when commented out, MUST cause this test's hold-block assertion to fail. Reviewer verifies by-hand.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/field/tests/Unit/Classification/Schedule/ packages/field/tests/Unit/Classification/Job/ tests/Integration/PhaseClassificationRetention/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. `bin/waaseyaa schedule:list | grep classification.retention` → three tasks listed with correct cron expressions.
6. Reviewer dead-code-guard: comment out the `ClassificationFieldAccessPolicy` registration; rerun `ClassificationRetentionIntegrationTest`; confirm the hold-block assertion FAILS; restore.

## Commit + handoff

- `feat(field): ClassificationRetentionScheduleEntries — purge / redact / hold-scan tasks`
- `feat(field): PurgeJob writes retention.purge audit events; honours exemptions`
- `feat(field): RedactJob nulls pii-marked fields, preserves audit trail`
- `feat(field): HoldScanJob surfaces hold-vs-purge conflicts via classification.change events`
- `test(field): unit + best-effort tests for purge / redact / hold-scan`
- `test(integration): ClassificationRetentionIntegrationTest with FR-015 dead-code guard`

```
spec-kitty agent tasks mark-status T-M T-N T-O T-P T-Q T-R --status done --mission classification-retention-engine-01KSEFTH
spec-kitty agent tasks move-task WP03 --to for_review --mission classification-retention-engine-01KSEFTH --note "Scheduled jobs + integration test passing; FR-015 dead-code guard verified"
```

## Report back with

1. Commit SHAs.
2. `bin/waaseyaa schedule:list | grep classification` output.
3. The deleted entity-count + audit-event counts from the integration test scenario (purge ran: how many `retention.purge` events written + how many entities deleted).
4. The exact failing assertion when the policy registration is commented out (dead-code guard proof).
5. The `BestEffortTest` output proving a thrown exception in policy A's iteration doesn't block policy B (NFR-004).
6. `bin/check-package-layers` + `bin/check-dead-code` + `bin/check-getquery-bindings` green.

## Activity Log
- 2026-05-25T21:52:48Z – unknown – Scheduled retention jobs (purge/redact/hold-scan) + ClassificationRetentionScheduleEntries + FR-015 integration test in place. 4 commits (e6fd6a92b..275b73bd3). Gate green: phpunit 477/477 (812 assertions incl FR-015 integration), composer phpstan no errors, cs-check 0/1786, check-dead-code clean (stale SqlEntityQuery::exists baseline entry removed), package-layers/getquery/composer-policy all OK. Best-effort isolation (NFR-004) and hold-vs-purge conflict detection (FR-012) covered.
- 2026-05-26T10:43:41Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Started review via action command
- 2026-05-26T10:48:35Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Moved to planned
- 2026-05-26T10:50:14Z – claude:opus:implementer:implementer – shell_pid=492458 – Started implementation via action command
- 2026-05-26T11:08:04Z – claude:opus:implementer:implementer – shell_pid=492458 – FR-015 integration test fixed: PurgeJob/HoldScanJob invoked via booted-kernel real composition, retention.purge + hold_vs_purge audit events asserted, load-bearing dead-code guard (FieldServiceProvider clearance binding) verified by-hand
- 2026-05-26T11:08:52Z – claude:opus:reviewer2:reviewer – shell_pid=506289 – Started review via action command
- 2026-05-26T11:12:24Z – claude:opus:reviewer2:reviewer – shell_pid=506289 – Re-review (claude:reviewer2) cycle 2 PASSED: all 3 prior blockers fixed — jobs invoked via PurgeJob/HoldScanJob.run() with retention.purge + hold_vs_purge audit-event assertions; real kernel/FieldServiceProvider composition; load-bearing dead-code guard (removing the binding breaks boot); no env hacks in commit 6406513d1; NFR-004/schedule/exists-baseline intact.
- 2026-05-26T11:17:41Z – claude:opus:reviewer2:reviewer – shell_pid=506289 – Done override: Feature squash-merged to main (b170e0a44)
