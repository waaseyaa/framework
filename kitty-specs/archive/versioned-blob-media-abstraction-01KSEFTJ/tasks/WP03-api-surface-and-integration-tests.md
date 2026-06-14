---
work_package_id: WP03
title: "JSON:API surface — read-model + adapter + controller + router + foundation routes + integration tests (dedup + forbidden version)"
dependencies:
  - WP01
  - WP02
requirement_refs:
  - FR-008
  - FR-009
  - FR-010
  - FR-013
  - FR-014
  - NFR-002
  - NFR-004
  - NFR-005
  - C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: "Branches from WP02 merge commit. Completed work merges back into main."
subtasks:
  - T-J
  - T-K
  - T-L
  - T-M
authoritative_surface: "packages/api/src/Media"
execution_mode: code_change
owned_files:
  - packages/api/src/Media/MediaVersionReadModelInterface.php
  - packages/api/src/Media/MediaVersionResource.php
  - packages/api/src/Media/ApiMediaVersionAdapter.php
  - packages/api/src/Controller/MediaVersionController.php
  - packages/api/src/Http/Router/MediaVersionApiRouter.php
  - packages/api/src/ApiServiceProvider.php
  - packages/api/composer.json
  - packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
  - packages/api/tests/Unit/Controller/MediaVersionControllerTest.php
  - packages/api/tests/Unit/Http/Router/MediaVersionApiRouterTest.php
  - tests/Integration/PhaseMediaVersioning/MediaVersioningIntegrationTest.php
  - tests/Integration/PhaseMediaVersioning/ForbiddenVersionIntegrationTest.php
tags: ["substrate", "media", "json-api", "integration-test", "dedup"]
history: []
---

# WP03 — JSON:API surface + integration tests

**Mission:** `versioned-blob-media-abstraction-01KSEFTJ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01, WP02.

## Pattern references — READ FIRST

- `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + `AiObservabilityController.php` + the `ApiServiceProvider::httpDomainRouters()` resolveOptional block (M5A shipped exemplar).
- `packages/api/src/Http/Router/WorkflowGuardsApiRouter.php` + `AiObservabilityApiRouter.php` — router shape.
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — `_authenticated` route option + string FQCN registration pattern.
- `tests/Integration/PhaseAiObservability/AiObservabilityDashboardEndpointTest.php` — kernel-boot integration test pattern.
- `docs/specs/codified-context-integration.md` — the cross-layer L0↔L4 read-contract pattern (extended here to L2↔L4).

## Subtasks

### T-J — Read-model + DTO + adapter

1. `packages/api/src/Media/MediaVersionReadModelInterface.php` — `findForMedia(string $mediaUuid, AccountInterface $account): iterable<MediaVersionResource>`, `findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource`. `@api`. **No `use Waaseyaa\Media\Version\*` types in the interface** (api-local DTOs only).
2. `packages/api/src/Media/MediaVersionResource.php` — readonly DTO per spec.md. `@api`.
3. `packages/api/src/Media/ApiMediaVersionAdapter.php implements MediaVersionReadModelInterface`. Constructor `(Waaseyaa\Media\Version\MediaVersionRepository $repo, AccessChecker $checker)`. Maps `MediaVersion` → `MediaVersionResource`. The api → media import is downward (L4 → L2) and allowed.

### T-K — Controller + router + service provider wiring

1. `packages/api/src/Controller/MediaVersionController.php`. Constructor `(?MediaVersionReadModelInterface $readModel = null)`. 
   - `index(Request $request, string $mediaUuid): array`. Resolves `$account = $request->attributes->get('_account')` (CLAUDE.md gotcha — never `account`). Returns `{data: [...resources], meta: {total: count}}`. Null read-model → `{data: [], meta: {total: 0}}`.
   - `show(Request $request, string $mediaUuid, int $vid): array`. Resolves account; calls `findByVid`; null result → 404 JSON:API error; otherwise `{data: resource}`.
2. `packages/api/src/Http/Router/MediaVersionApiRouter.php` mirrors `AiObservabilityApiRouter`: `supports()` matches `'MediaVersionController::'`; dispatch `index` + `show`; JSON:API error envelope on unknown action.
3. `packages/api/src/ApiServiceProvider.php`:
   - `use Waaseyaa\Api\Media\MediaVersionReadModelInterface;` (api-local).
   - In `register()`: `$this->singleton(MediaVersionReadModelInterface::class, fn($c) => new ApiMediaVersionAdapter($c->get(MediaVersionRepository::class), $c->get(AccessChecker::class)));`.
   - In `httpDomainRouters()`: `$rm = $this->resolveOptional(MediaVersionReadModelInterface::class); if ($rm instanceof MediaVersionReadModelInterface) { $routers[] = new MediaVersionApiRouter(new MediaVersionController($rm)); }`.
4. `packages/api/composer.json` — add `"waaseyaa/media"` to **require-dev** + `"../media"` path repo. `composer update --lock waaseyaa/media`.

### T-L — Foundation routes

1. `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`:
   - `api.media.versions.index` → `GET /api/media/{uuid}/versions`, `->requireAuthenticated()`, `->methods('GET')`, controller string FQCN `'Waaseyaa\\Api\\Controller\\MediaVersionController::index'`.
   - `api.media.versions.show` → `GET /api/media/{uuid}/versions/{vid}`, `->requireAuthenticated()`, `->methods('GET')`, controller string FQCN `'Waaseyaa\\Api\\Controller\\MediaVersionController::show'`.

### T-M — Unit + integration tests

- Unit tests for controller + router per plan.md §T-M.
- `tests/Integration/PhaseMediaVersioning/MediaVersioningIntegrationTest.php` (`#[CoversNothing]`):
  - Boot kernel.
  - Create a Media entity. Upload 3 distinct payloads (A, B, C). Upload a 4th payload identical to A.
  - Assert: 4 `MediaVersion` rows, vids 1..4. v1 and v4 share `blob_uri` + `sha256`. 4 `media.version.created` audit events; v4's event has `attributes.dedup_hit = true`; v1-v3 have `dedup_hit = false`.
  - `GET /api/media/{uuid}/versions` returns 4 entries ordered `vid DESC` (v4, v3, v2, v1).
  - `GET /api/media/{uuid}/versions/2` returns the v2 resource.
  - `GET /api/media/{uuid}/versions/99` returns 404.
- `tests/Integration/PhaseMediaVersioning/ForbiddenVersionIntegrationTest.php` (`#[CoversNothing]`):
  - Seed media + 3 versions.
  - Install a test-only `MediaVersionAccessPolicy` extension that forbids vid=1 for the test non-admin account.
  - `GET /api/media/{uuid}/versions` as admin → 3 entries.
  - Same endpoint as the non-admin → 2 entries (v3, v2 only — v1 NOT enumerable).
  - `GET /api/media/{uuid}/versions/1` as non-admin → 403.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/api/tests/Unit/Controller/MediaVersionControllerTest.php packages/api/tests/Unit/Http/Router/MediaVersionApiRouterTest.php tests/Integration/PhaseMediaVersioning/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. `rg -nE 'use Waaseyaa\\\\Media\\\\Version' packages/api/src/` → only ApiMediaVersionAdapter should match (importing `MediaVersionRepository`). MediaVersionReadModelInterface + MediaVersionResource + MediaVersionController + MediaVersionApiRouter must be Waaseyaa\Api\Media-only.
6. Dead-code-guard verification: comment out the `singleton(MediaVersionReadModelInterface::class, ...)` line in ApiServiceProvider; rerun `MediaVersioningIntegrationTest`; confirm the listing assertion FAILS (controller's readModel becomes null → empty list). Restore. Document.
7. NFR-005 perf smoke: time the dedup-hit path with a 1MB payload. Should be < 5ms additional vs a non-dedup save.

## Commit + handoff

- `feat(api): MediaVersionReadModelInterface + MediaVersionResource + ApiMediaVersionAdapter`
- `feat(api): MediaVersionController + MediaVersionApiRouter + ApiServiceProvider wiring`
- `feat(foundation): /api/media/{uuid}/versions[/{vid}] routes`
- `test(integration): MediaVersioningIntegrationTest (dedup + audit-events) + ForbiddenVersionIntegrationTest (per-version access)`

```
spec-kitty agent tasks mark-status T-J T-K T-L T-M --status done --mission versioned-blob-media-abstraction-01KSEFTJ
spec-kitty agent tasks move-task WP03 --to for_review --mission versioned-blob-media-abstraction-01KSEFTJ --note "API surface + integration tests passing; dedup + forbidden-version verified"
```

## Report back with

1. Commit SHAs.
2. The JSON:API payload from `GET /api/media/{uuid}/versions` after the 4-upload integration scenario (paste).
3. The dedup-hit audit event for v4 (paste — must show `dedup_hit: true`).
4. The forbidden-version count differential (admin sees 3, restricted sees 2 — paste both responses).
5. The dead-code-guard failing assertion (when readModel binding removed — paste).
6. NFR-005 perf-smoke timing.
7. `rg -nE 'use Waaseyaa\\Media' packages/api/src/` output — confirm only ApiMediaVersionAdapter imports media types.

## Activity Log
- 2026-05-25T05:36:35Z – unknown – Moved to in_progress
- 2026-05-25T06:00:42Z – unknown – Moved to for_review
- 2026-05-25T06:01:15Z – unknown – Opus review: lane-a disciplined; clean commit per WP; specs stamped including CLAUDE.md orchestration table row; tests pass
- 2026-05-26T18:55:58Z – unknown – Done override: Sprint merge to main
