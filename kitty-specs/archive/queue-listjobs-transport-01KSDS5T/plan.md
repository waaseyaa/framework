# Implementation Plan: Queue Transport listJobs

**Mission:** `queue-listjobs-transport-01KSDS5T` — see `spec.md` for full FR list.
**Pattern reference:** M4B WP01 (squash `f0317b429`).
**Single WP, single PR.**

## WP01 — listJobs across the stack

### Backend: `packages/queue`
- `Transport/TransportInterface.php` — add `listJobs(int $limit, int $offset = 0, ?string $status = null): array`.
- `Transport/DbalTransport.php` — implement via `SELECT FROM waaseyaa_queue_jobs WHERE reserved_at IS NULL` (queued) or `WHERE reserved_at IS NOT NULL` (in_progress). `LIMIT/OFFSET`. Total via separate `COUNT(*)`.
- `Transport/InMemoryTransport.php` — merge `$this->queues` (queued) + `$this->reserved` (in_progress) into a single sorted-by-id list, apply filter + slice.
- `tests/Contract/TransportContractTest.php` — abstract contract test + concrete subclasses for both backends. Cover empty, all-queued, mixed, status filter, pagination.

### Backend: `packages/api`
- `Controller/QueueController.php` — read `?status` query param; route `failed` to `FailedJobRepository` (unchanged), `queued|in_progress|all` to `TransportInterface::listJobs`. Default `failed` for backward compat. Inject `TransportInterface`.
- `ApiServiceProvider.php` — extend the queue `resolveOptional()` block to also resolve `TransportInterface`. Pass into `QueueController` constructor. Skip queue routes if transport absent.
- `tests/Unit/Controller/QueueControllerTest.php` — extend with status-routing cases.
- `tests/Integration/PhaseQueueAdmin/QueueAdminEndpointsTest.php` — extend to seed transport rows + assert each `?status` value.

### Frontend: `packages/admin`
- `app/composables/useQueueJobs.ts` — `fetchJobs(page, perPage, status?: 'failed' | 'queued' | 'in_progress' | 'all')`. Default `'failed'`.
- `app/pages/queue/index.vue` — status filter chips above the table. Switch between detail (failed) and lean (queued/in_progress) column sets based on selected chip.
- `app/i18n/en.json` — `queue_status_failed`, `queue_status_queued`, `queue_status_in_progress`, `queue_status_all`, `queue_age_seconds`.
- `tests/unit/composables/useQueueJobs.test.ts` — extend with status-arg cases.
- `e2e/queue.spec.ts` — extend with chip-switching smoke.

### Spec stamp + CHANGELOG
- `docs/specs/admin-spa.md` — stamp.
- `CHANGELOG.md` — `[Unreleased]` → Added: queue dashboard now shows queued + in-flight alongside failed. (#1576)

## Verification gate

In lane worktree:
1. `composer install`
2. `vendor/bin/phpunit packages/queue/ packages/api/tests/Unit/Controller/QueueControllerTest.php tests/Integration/PhaseQueueAdmin/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`
6. Playwright deferred to CI (needs `nuxt dev`).

## Reviewer focus

- (a) `TransportInterface::listJobs()` contract test runs cleanly on BOTH backends.
- (b) `GET /api/queue/jobs` without `?status` still returns failed jobs (NFR-001 backward compat).
- (c) Retry/discard buttons render only on `failed` rows (C-001).
- (d) Final merge closes #1576 via commit footer.
