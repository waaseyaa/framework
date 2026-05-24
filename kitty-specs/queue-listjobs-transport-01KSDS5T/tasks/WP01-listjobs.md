---
work_package_id: WP01
title: TransportInterface::listJobs() + queue dashboard filter chips
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- FR-008
- FR-009
- FR-010
- FR-011
- NFR-001
- NFR-002
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-queue-listjobs-transport-01KSDS5T
base_commit: b5969e1da40b85d162e5da55500e2a19b1870bcb
created_at: '2026-05-24T20:07:19.907320+00:00'
subtasks: []
shell_pid: "399835"
history: []
authoritative_surface: packages/queue/src/Transport/TransportInterface.php
execution_mode: code_change
owned_files:
- packages/queue/src/Transport/TransportInterface.php
- packages/queue/src/Transport/DbalTransport.php
- packages/queue/src/Transport/InMemoryTransport.php
- packages/queue/tests/Contract/TransportContractTest.php
- packages/queue/tests/Contract/DbalTransportContractTest.php
- packages/queue/tests/Contract/InMemoryTransportContractTest.php
- packages/api/src/Controller/QueueController.php
- packages/api/src/ApiServiceProvider.php
- packages/api/tests/Unit/Controller/QueueControllerTest.php
- tests/Integration/PhaseQueueAdmin/QueueAdminEndpointsTest.php
- packages/admin/app/composables/useQueueJobs.ts
- packages/admin/app/pages/queue/index.vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useQueueJobs.test.ts
- packages/admin/e2e/queue.spec.ts
- docs/specs/admin-spa.md
- CHANGELOG.md
tags: []
agent: "claude:opus:reviewer:reviewer"
---

# WP01 — TransportInterface::listJobs() + queue dashboard filter chips

**Mission:** `queue-listjobs-transport-01KSDS5T` (#1576)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Pattern reference (CANONICAL):** the SHIPPED M4B queue admin — `packages/api/src/Controller/QueueController.php`, `packages/admin/app/pages/queue/index.vue`, `tests/Integration/PhaseQueueAdmin/QueueAdminEndpointsTest.php`. You're EXTENDING these.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/queue-listjobs-transport-01KSDS5T-lane-a
```

(Path will be reported by `spec-kitty agent action implement WP01`.)

All edits and commits go there. NOT main repo.

## Critical context

- Lane worktree has no `vendor/` and no `packages/admin/node_modules/`. Run `composer install` and `cd packages/admin && npm install` first.
- `TransportInterface` carries `@api`. The new method is mandatory for implementors. Third-party transports outside the framework will need to add it — that's the trade-off this mission accepts.
- The M4B `PhaseQueueAdmin` integration test MUST still pass unchanged after your changes — backward compat is FR-007 / NFR-001.
- Retry / discard buttons remain failed-only (C-001). Do not wire them onto live-job rows.

## Subtasks

**T001 — Transport contract**
- Add `public function listJobs(int $limit, int $offset = 0, ?string $status = null): array;` to `TransportInterface.php`. PHPDoc declares the return shape `{data: list<JobRow>, total: int}` where `JobRow` is `{id, queue, payload, attempts, available_at, reserved_at, status}` and `status` is the derived `'queued' | 'in_progress'`.
- Implement on `DbalTransport`: SQL queries against `waaseyaa_queue_jobs` with the documented filter semantics. Two queries: one COUNT(*) for `total`, one paginated SELECT for `data`. Apply `WHERE reserved_at IS NULL` for queued, `WHERE reserved_at IS NOT NULL` for in_progress, no filter for both.
- Implement on `InMemoryTransport`: merge `$this->queues` (build queued rows) + `$this->reserved` (build in_progress rows). Sort by id ASC. Apply filter, then slice for limit/offset.

**T002 — Contract test**
- Look at `packages/cache/tests/Contract/` or `packages/state/tests/Contract/` to find the established contract-test pattern in this monorepo. Mirror it.
- New abstract class `TransportContractTest` with the same methods, returning a `TransportInterface` from `protected function makeTransport(): TransportInterface`. Concrete subclasses: `DbalTransportContractTest` (with SQLite in-memory) + `InMemoryTransportContractTest`. Cover at minimum: empty list, all-queued list (N pushes), queued+in_progress split (push N, pop 1, assert split), status filter (queued/in_progress/null), pagination (limit 2 offset 0 vs limit 2 offset 2).
- Use `#[CoversNothing]` on the contract test (per project convention).

**T003 — Controller + service-provider wiring**
- `QueueController::index(Request $request)` — read `?status` (default `failed`). Branch:
  - `failed` → existing `FailedJobRepository::all()` path (unchanged).
  - `queued` → `$this->transport->listJobs($limit, $offset, 'queued')`.
  - `in_progress` → `$this->transport->listJobs($limit, $offset, 'in_progress')`.
  - `all` → merge failed + transport(null).
- Inject `TransportInterface` via constructor. Update existing tests' constructor calls.
- `ApiServiceProvider::httpDomainRouters()` — extend the queue resolveOptional block to also resolve `TransportInterface`. If absent, skip the queue router entirely. The graceful-degradation behaviour M4B established.

**T004 — Backend tests**
- Extend `packages/api/tests/Unit/Controller/QueueControllerTest.php` with cases for each `?status` value. Anonymous-class `TransportInterface` fake with a stub `listJobs()`.
- Extend `tests/Integration/PhaseQueueAdmin/QueueAdminEndpointsTest.php` — seed real transport rows via `push()`, then assert each `?status` value returns the right shape. KEEP the existing M4B-era assertions (the `?status=failed` default case must still pass).

**T005 — Frontend + spec stamp + CHANGELOG**
- `useQueueJobs.ts` — `fetchJobs(page = 1, perPage = 20, status: 'failed' | 'queued' | 'in_progress' | 'all' = 'failed')`. Pass `?status` query param. Backward compat preserved by default.
- `pages/queue/index.vue` — add four chip buttons above the table. Active chip drives the composable. Conditional column rendering: failed → full detail; queued/in_progress → lean (id, queue, status chip, attempts, age in seconds). Retry/discard buttons render only when current chip is `failed`.
- i18n: `queue_status_failed`, `queue_status_queued`, `queue_status_in_progress`, `queue_status_all`, `queue_age_seconds`.
- Update vitest + Playwright accordingly.
- `docs/specs/admin-spa.md` — stamp at top, document the new `?status` query param and the chip UI.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: /queue now lists queued and in-flight jobs alongside failed (TransportInterface::listJobs). (#1576)`

## Verification gate

In the lane worktree:
1. `composer install`
2. `cd packages/admin && npm install && cd -`
3. `vendor/bin/phpunit packages/queue/`
4. `vendor/bin/phpunit packages/api/tests/Unit/Controller/QueueControllerTest.php`
5. `vendor/bin/phpunit tests/Integration/PhaseQueueAdmin/`
6. `composer cs-check` (cs-fix + cache clear if needed)
7. `composer phpstan`
8. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
9. `cd packages/admin && npm test && npm run typecheck && npm run lint`
10. Playwright deferred (needs `nuxt dev`).

## Commit + handoff

- Commit messages, in order:
  - `feat(queue): TransportInterface::listJobs() + DBAL + in-memory impls (#1576)`
  - `feat(queue): contract test for TransportInterface::listJobs() (#1576)`
  - `feat(api): /queue now serves status=queued|in_progress|failed|all (#1576)`
  - `feat(admin): /queue status filter chips for queued/in_progress (#1576)`
  - `docs(specs): admin-spa.md stamp + CHANGELOG (#1576)`
- Every commit footer: `Refs #1576`. Final merge will use `Closes #1576`.
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 T005 --status done --mission queue-listjobs-transport-01KSDS5T
  spec-kitty agent tasks move-task WP01 --to for_review --mission queue-listjobs-transport-01KSDS5T --note "Closes #1576; M4B backward compat verified"
  ```

## Report back with

1. Commit SHAs.
2. Whether `packages/queue/tests/Contract/` already existed or was created.
3. Whether the `?status=all` shape needed any special handling for the mixed failed+transport rows.
4. Backward-compat verification: did M4B's `PhaseQueueAdmin` integration test pass unchanged?

## Activity Log

- 2026-05-24T20:07:22Z – claude:sonnet:implementer:implementer – shell_pid=379607 – Assigned agent via action command
- 2026-05-24T20:28:59Z – claude:sonnet:implementer:implementer – shell_pid=379607 – Closes #1576; M4B backward compat verified
- 2026-05-24T20:29:53Z – claude:opus:reviewer:reviewer – shell_pid=399835 – Started review via action command
- 2026-05-24T20:31:43Z – claude:opus:reviewer:reviewer – shell_pid=399835 – Review passed (opus). All gates green: phpunit 202/202 tests + 543 assertions (16 contract + 7 controller + 4 integration new), phpstan/cs-check/layers/dead-code/getquery/composer-policy clean. TransportInterface::listJobs() contract well-typed with explicit InvalidArgumentException requirement for bad status. DBAL impl uses separate COUNT+SELECT for total/data. InMemory merges queues+reserved. Production binding verified - QueueServiceProvider::register() line 24 already singletons TransportInterface. ApiServiceProvider gracefully degrades to M4B failed-only when TransportInterface absent. M4B PhaseQueueAdmin integration test confirmed passes unchanged (NFR-001 backward compat). status=all merge: failedTotal+transportTotal with shape disambiguation by field presence (exception_class vs reserved_at). phpunit.xml.dist update for new Contract dir noted as out-of-scope warning but correct per documented contract-test placement convention.
- 2026-05-24T20:34:27Z – claude:opus:reviewer:reviewer – shell_pid=399835 – Done override: Mission squash-merged to main (064dfe9c7) - manual conflict resolution on CHANGELOG + admin-spa.md vs M4C
