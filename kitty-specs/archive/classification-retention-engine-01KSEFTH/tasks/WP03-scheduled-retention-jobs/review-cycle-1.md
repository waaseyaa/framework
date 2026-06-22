---
affected_files: []
cycle_number: 1
mission_slug: classification-retention-engine-01KSEFTH
reproduction_command:
reviewed_at: '2026-05-26T10:48:35Z'
reviewer_agent: unknown
verdict: rejected
wp_id: WP03
---

VERDICT: REJECT

WP03 = PurgeJob / RedactJob / HoldScanJob + ClassificationRetentionScheduleEntries
(cron ~6h / 6h+30m / daily) + best-effort per-policy execution (NFR-004) + unit/best-effort
tests + FR-015 kernel-composition integration test with a dead-code guard. The jobs, schedule
entries, and unit/best-effort tests are well-built and pass on the spec criteria. The
**FR-015 integration test (T-R)** does not meet three explicit, hard acceptance criteria —
those are blocking.

## Acceptance criteria

- **T-M ClassificationRetentionScheduleEntries**: MET — `packages/field/src/Classification/Schedule/ClassificationRetentionScheduleEntries.php:37` implements `ScheduleEntriesInterface`, `@api` at :35, `register(ScheduleInterface): array<string,ScheduledTask>`, three tasks with correct crons (`CRON_PURGE='0 */6 * * *'`, `CRON_REDACT='30 */6 * * *'`, `CRON_HOLD_SCAN='0 3 * * *'`). Auto-discovered by `PackageManifestCompiler` interface scan (foundation `PackageManifestCompiler.php:33,193,792`) → no explicit SP registration required. Nullable job deps keep it discoverable when deps unbound (inert no-op).

- **T-N PurgeJob (FR-009)**: MET — `packages/field/src/Classification/Job/PurgeJob.php`. Per-policy try/catch (NFR-004), `LoggerInterface`+`NullLogger`, `accessCheck(false)` on all getQuery chains (lines ~150-158, justification comment present), deletes via repository (fires `entity.delete`), writes one `retention.purge` `AuditEventDescriptor`, honours `policy.isExempt()`, `@api`. SELF_TYPES guard prevents purging classification machinery.

- **T-O RedactJob (FR-011)**: MET — `RedactJob.php`. Discovers `#[FieldTemplate(pii: true)]` via reflection, nulls those fields, **preserves `classification_label`** (verified by unit test RedactJobTest:61), writes `retention.redact` with `{policy_id,label_id,redacted_fields}`. Best-effort wrapped, `accessCheck(false)`, `@api`.

- **T-P HoldScanJob (FR-012)**: MET — `HoldScanJob.php`. Verification-only (no delete), writes `classification.change` with `attributes.conflict='hold_vs_purge'`, logs `notice`, severity `warning`, `@api`.

- **T-Q Unit + best-effort tests**: MET — PurgeJobTest (age/match/exempt/glob/non-age, 1 `RetentionPurge` event), RedactJobTest (pii null, structural+label preserved, exempt, audit), HoldScanJobTest (conflict/no-conflict), ScheduleEntriesTest (names/crons/UTC/preventOverlap/inert-null). BestEffortTest is a genuine NFR-004 proof: 3 purge policies, audit writer throws on the 2nd call, asserts all 3 entities deleted + writer called 3× + 2 recorded → one failing policy does not abort the batch.

- **NFR-004 best-effort**: MET — try/catch per policy in all three jobs; BestEffortTest proves isolation.

- **getQuery() bindings**: MET — every new `getQuery()->...->execute()` in the jobs uses `accessCheck(false)` with an inline CLAUDE.md justification comment.

- **SqlEntityQuery::exists baseline removal**: MET — baseline entry removed (`phpstan-dead-code-baseline.neon` diff) and the method is genuinely now called: `PurgeJob.php:158`, `RedactJob.php:150`, `HoldScanJob.php:156`. Removal is safe; CI dead-code gate will not regress on this.

- **Dead-code reachability of jobs/schedule**: MET — schedule class auto-discovered via interface scan; all four classes carry `@api` (PurgeJob:38, RedactJob:35, HoldScanJob:35, ScheduleEntries:35), so the dead-code detector treats them as used.

- **T-R FR-015 integration test**: **NOT MET (blocking)** — see below.
  - (a) inheritance cascade: MET (test:65-66).
  - (b) override + `inherited_from=null`: PARTIAL — asserts label kept + `inherited_from` null (test:83-84) but does NOT assert `classification_overridden_at IS NOT NULL` as T-R requires.
  - (c) confidential anon→forbidden / admin→neutral: PARTIAL — anon forbidden (test:98) and a cleared/blocked admin path exist, but the "admin → neutral" case is reframed as "admin clearance 10 < confidential 20 → still forbidden" (test:108); the spec's neutral-for-admin assertion is not made.
  - (d) hold-legal: bypass→neutral (test:122), non-bypass→forbidden (test:133): MET.
  - **Purge scenario: NOT MET** — T-R requires seeding a 7-day purge policy, calling `PurgeJob::run()` directly, asserting the old entity deleted + one `retention.purge` event. The integration test never references `PurgeJob` (grep for `PurgeJob|->run(` returned nothing).
  - **Hold-scan scenario: NOT MET** — T-R requires running `HoldScanJob` with a conflicting purge+hold pair and asserting a `classification.change` `hold_vs_purge` event. Never invoked in the integration test.
  - **"Boot full kernel": NOT MET** — the test composes objects by hand (`InMemoryEntityStorage`, anonymous `AccountInterface`/resolver classes, manual `EntityTypeManager`), no kernel boot.

- **FR-015 dead-code guard**: **NOT MET (blocking)** — T-R requires the guard to name the exact production wiring line in `FieldServiceProvider` (or `Permissions.php` / policy attribute) that, when commented out, MUST fail the hold-block assertion. The test's guard instead instructs replacing the test's own `$this->policy()` helper with a no-op. Because the test directly `new ClassificationFieldAccessPolicy(...)` in its helper (test:143-150) rather than resolving the policy through the service provider / kernel, commenting out the real `FieldServiceProvider` policy binding (`ClassificationFieldAccessPolicy.php:47 #[PolicyAttribute]`, or the SP binds at FieldServiceProvider.php:117-137) would NOT fail the test. The guard does not bind to production wiring and cannot be verified as specified.

## Blocking issues (if REJECT)

1. **FR-015 integration test omits the retention-job scenarios (T-R).**
   `tests/Integration/PhaseClassificationRetention/ClassificationRetentionIntegrationTest.php` never invokes `PurgeJob::run()` or `HoldScanJob::run()` (grep `PurgeJob|HoldScanJob|RedactJob|->run(` → no matches). T-R explicitly mandates: seed a 7-day purge policy → run `PurgeJob::run()` → assert old `public` entity deleted + one `retention.purge` audit event with matching `policy_id`; and run `HoldScanJob` over a conflicting purge+hold pair → assert a `classification.change` `hold_vs_purge` event. Both are absent. The report-back items #3 (deleted-count + audit-event counts from the integration scenario) cannot be produced from this test. Fix: add the purge-run and hold-scan-run scenarios with their audit assertions to the integration test.

2. **FR-015 dead-code guard is decorative, not load-bearing.**
   The guard comment (test:38-46) says to swap `$this->policy()` for a no-op. But `policy()` (test:143-150) directly constructs `ClassificationFieldAccessPolicy`, so the guard never exercises production wiring. T-R's final clause requires the guard to name a real `FieldServiceProvider` / `Permissions.php` / policy-attribute line whose removal fails the hold-block assertion. Reviewer-by-hand verification (gate step 6: comment out the `ClassificationFieldAccessPolicy` registration, rerun, confirm hold-block FAILS) is impossible as written — the test would still pass. Fix: resolve the policy through the kernel/service provider (or otherwise bind the assertion to the production registration line named in the comment) so commenting out the real registration fails the test.

3. **"Boot full kernel" not satisfied (T-R).** The test uses hand-rolled in-memory composition instead of a booted kernel, so it does not exercise the service-provider wiring, `PolicyDependencyResolver`, or schedule-entry discovery that FR-015 is meant to integration-test. This is the root cause enabling issues #1 and #2. Fix: boot the kernel (or at minimum resolve the access policy and run the jobs through the real container-wired objects) so the integration test proves end-to-end composition.

Note: criteria (b) `classification_overridden_at` and (c) admin→neutral are partial deviations from T-R; fold these into the integration-test fixes.

## Non-blocking notes

- Could not execute the test suite in this environment: PHP is 8.4.20 but the project requires >= 8.5 (`vendor/composer/platform_check.php` fatal). Unit-test source inspection is strong, and the WP activity log claims 477/477 green; the blockers above are determined by static inspection of the committed test source, independent of runtime.
- `RedactJob` discovers PII fields via fresh `ReflectionClass` per entity each iteration — fine for correctness; consider memoizing per entity-type class if redaction volume grows.
- Job-level `findLabelled()`/`findAgeEligible()` swallow all `\Throwable` to skip entity types lacking the classification columns — pragmatic, but a malformed-schema error on a real participant type would be silently skipped. Acceptable for a best-effort sweep; the per-policy logging covers the policy layer.
- Unit tests are genuinely good (exemptions, glob matching, non-age policy skip, classification-label preservation, NFR-004 isolation) — the rejection is narrowly about the FR-015 integration test (T-R), not the jobs or unit suite.
