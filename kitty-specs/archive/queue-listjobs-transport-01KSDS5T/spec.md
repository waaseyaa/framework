# Queue Transport `listJobs()` + Queued/In-Flight Dashboard Columns

**Mission:** `queue-listjobs-transport-01KSDS5T`
**Target branch:** `main`
**Tracks:** GitHub issue #1576 (filed by M4B WP01 as the explicit follow-up deferral).
**Pattern reference:** M4B WP01 (`QueueController` + `useQueueJobs.ts`, squash `f0317b429`).

## Why

M4B intentionally shipped failed-jobs only because `TransportInterface` had no listing surface. This mission adds it, extends the controller and frontend, closes the deferral.

## Scope

### In scope (single WP)

- **`packages/queue/src/Transport/TransportInterface.php`:** add `public function listJobs(int $limit, int $offset = 0, ?string $status = null): array;` returning `{data: list<JobRow>, total: int}`. `$status` ∈ `{'queued', 'in_progress', null}`. `null` returns both.
- **`packages/queue/src/Transport/DbalTransport.php`:** implement. SQL selects from `waaseyaa_queue_jobs`. Status filter via `reserved_at IS NULL` (queued) / `reserved_at IS NOT NULL` (in_progress). Order by id ASC. Total via separate COUNT(*) with the same filter.
- **`packages/queue/src/Transport/InMemoryTransport.php`:** implement. Merge `$this->queues` (queued) + `$this->reserved` (in_progress) into a sorted list, apply filter + slice.
- **`packages/queue/tests/Contract/TransportContractTest.php`:** new (or extended) abstract contract test. Concrete tests for both backends. Cover empty, all-queued, mixed, status filter, limit/offset pagination.
- **`packages/api/src/Controller/QueueController.php`:** extend `index()` to read `?status=queued|in_progress|failed|all`. Default `failed` (M4B backward compat). For non-failed statuses, delegate to `TransportInterface::listJobs()`. Inject `TransportInterface` via constructor.
- **`packages/api/src/ApiServiceProvider.php`:** wire `TransportInterface` into `QueueController` construction via the existing `resolveOptional()` block. Skip cleanly if absent.
- **Frontend:** `useQueueJobs.ts` accepts an optional `status` arg. `pages/queue/index.vue` adds Failed/Queued/In progress/All filter chips at the top. Conditional column rendering: failed rows keep the M4B detail; live-job rows show `(id, queue, status chip, attempts, age)`. Retry/discard buttons remain failed-only.
- **i18n:** `queue_status_failed`, `queue_status_queued`, `queue_status_in_progress`, `queue_status_all`, `queue_age_seconds` in `app/i18n/en.json`.
- **Spec stamp** on `docs/specs/admin-spa.md`. **CHANGELOG** entry under `[Unreleased]` → Added.

### Out of scope

- Retry/discard on live jobs (only `failed` rows act).
- Kill-stuck-job action.
- Per-queue filter beyond default name.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `TransportInterface::listJobs(int $limit, int $offset = 0, ?string $status = null): array` returns `{data: list<JobRow>, total: int}` where each row has `{id, queue, payload, attempts, available_at, reserved_at, status}` and `status` is derived (`reserved_at IS NULL` → `queued`, else `in_progress`). |
| FR-002 | Mandatory | `DbalTransport::listJobs()` implements the contract against `waaseyaa_queue_jobs` with the documented filter + pagination semantics. |
| FR-003 | Mandatory | `InMemoryTransport::listJobs()` implements the contract by merging `$queues` + `$reserved`. |
| FR-004 | Mandatory | Contract test covers: empty, all-queued, queued+in_progress mix, status filter, limit/offset pagination. Runs against both backends. |
| FR-005 | Mandatory | `GET /api/queue/jobs?status=queued` returns transport-listed queued jobs. |
| FR-006 | Mandatory | `GET /api/queue/jobs?status=in_progress` returns reserved jobs. |
| FR-007 | Mandatory | `GET /api/queue/jobs?status=failed` (default if absent) returns failed jobs — unchanged from M4B. |
| FR-008 | Mandatory | `GET /api/queue/jobs?status=all` returns failed + queued + in_progress merged with status fields populated. |
| FR-009 | Mandatory | `/queue` page renders chips for the four statuses; default selection `failed`. |
| FR-010 | Mandatory | Retry / discard buttons render ONLY on `status: failed` rows. |
| FR-011 | Mandatory | `docs/specs/admin-spa.md` stamped; `CHANGELOG.md` `[Unreleased]` updated. |
| NFR-001 | Mandatory | Backward-compatible: existing `GET /api/queue/jobs` callers (no `?status`) still get the M4B response shape. The M4B integration test (`PhaseQueueAdmin`) must pass unchanged. |
| NFR-002 | Mandatory | New `TransportInterface::listJobs()` carries `@api` PHPDoc (the interface itself is `@api` already — confirm new method inherits or stamp). |
| C-001 | Constraint | No retry/discard buttons on live-job rows. |
| C-002 | Constraint | No per-queue filter UI. |

## Acceptance

- All FRs met.
- `composer cs-check`, `composer phpstan`, `bin/check-{package-layers,dead-code,getquery-bindings,composer-policy}` all green.
- `vendor/bin/phpunit` (packages/queue, packages/api, tests/Integration/PhaseQueueAdmin) green.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Commit footers `Refs #1576`; final merge commit uses `Closes #1576`.

## Risks

- **Backward compat regression.** Existing M4B callers must keep working. Use the existing `PhaseQueueAdmin` integration test as the regression gate.
- **Contract test placement.** If `packages/queue/tests/Contract/` doesn't exist, create it as a sibling to `tests/Unit/`. Mirror whatever contract pattern exists in `packages/cache` or `packages/state`.
- **Default queue name.** If the install has more than one queue, MVP shows all of them merged. Per-queue filter is a follow-up.
