---
work_package_id: WP01
title: API Hardening — Discovery Filtering & Denied-as-404
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- NFR-001
- NFR-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-request-surface-hardening-01KTX7F2
base_commit: 86b1fd3dc22218161d4e68d5e39ab4142d4d84df
created_at: '2026-06-12T06:42:07.394503+00:00'
subtasks:
- T001
- T002
- T003
- T004
- T005
- T006
shell_pid: '27092'
history:
- date: '2026-06-12T00:00:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/api/
execution_mode: code_change
owned_files:
- packages/api/src/ApiDiscoveryController.php
- packages/api/src/Http/Router/DiscoveryRouter.php
- packages/api/src/JsonApiController.php
- packages/api/tests/Unit/ApiDiscoveryControllerTest.php
- packages/api/tests/Unit/Http/Router/DiscoveryRouterTest.php
- packages/api/tests/Unit/JsonApiControllerAccessControlTest.php
- packages/api/tests/Unit/JsonApiControllerDeniedNotFoundTest.php
- packages/entity/src/EntityType.php
- packages/entity/tests/Unit/EntityTypeDiscoverableTest.php
- tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php
tags: []
---

# WP01 — API Hardening: Discovery Filtering & Denied-as-404

**Mission**: request-surface-hardening-01KTX7F2 | **Tracks**: #1649
**Requirements**: FR-001..FR-004, NFR-001, NFR-002 | **Dependencies**: none
**Command**: `spec-kitty agent action implement WP01 --agent <name>`

## Objective

Close both halves of #1649. After this WP: an anonymous `GET /api` reveals **zero** entity-type ids (the response keeps its envelope — `meta` + `links.self` — but lists types only for authenticated accounts); any entity type can opt out of the index entirely via `EntityType(discoverable: false)`; and a single-entity read denied by `view` access returns the exact same not-found response — byte-identical body, equal status — as a nonexistent id, killing the existence oracle. Mutating operations keep their genuine 403s.

## Context (read first)

- `research.md` D1 (the falsified categorical-check assumption and the adopted fallback), D2 (why the flag lives on `EntityType` only, NOT on `EntityTypeInterface`), D3 (the single-factory 404 + the deliberate absence of a debug-mode variant).
- `contracts/discovery-and-404.md` — the authoritative behavior spec; every numbered clause must hold.
- `data-model.md` "Discovery index — visibility decision per type" and "Not-found shape".
- **Dispatch reality**: `ApiDiscoveryController` is NOT container-resolved. `DiscoveryRouter::handle()` constructs it inline per request (`packages/api/src/Http/Router/DiscoveryRouter.php:40-44`) and already builds `$ctx = WaaseyaaContext::fromRequest($request)` (`:37`) whose `->account` is the `_account` request attribute (set by `SessionMiddleware` for every HTTP request — `AnonymousUser` id 0 when unauthenticated). The account is one constructor argument away.
- **Show dispatch reality**: `JsonApiRouter` (foundation) builds `JsonApiController` with `($entityTypeManager, $serializer, $accessHandler, $ctx->account)` and emits `jsonApiResponse($document->statusCode, $document->toArray())` (`packages/foundation/src/Http/Router/JsonApiRouter.php:47-69`). Headers are identical for both 404 cases by construction — your job is document-level byte-identity only. `JsonApiRouter` is NOT in your owned files and needs no change.
- Current `show()` branches (`packages/api/src/JsonApiController.php`): missing → `JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found.")` (`:141-145`); denied → `JsonApiError::forbidden("Access denied for viewing entity '{$id}'.")` (`:147-155`). `notFound()` sets no `code` member; `forbidden()` sets `code: 'FORBIDDEN'` (`packages/api/src/JsonApiError.php:60-80`).
- `EntityType` is `final readonly`, named-param construction; additive-param precedents: `tenancy`, `primaryStorageBackend` (`packages/entity/src/EntityType.php:68-84`). `fromClass()` (`:196-253`) caches per class — non-tenancy overrides are first-call-wins by framework norm.
- `EntityTypeInterface` has seven anonymous-class test implementors in cli/listing/relationship packages — **outside this WP's owned files**. Do not touch the interface.
- Account sentinel gotcha (CLAUDE.md): `AnonymousUser` id 0, `isAuthenticated() === false`; `DevAdminAccount` id `PHP_INT_MAX`, authenticated. The filter keys on `isAuthenticated()`, never on id values.

## Requirement / contract map

| Deliverable | Requirement | Contract anchor |
|---|---|---|
| `discoverable` flag on EntityType | FR-002 | discovery-and-404.md §4–6 |
| Account-aware discover() with authenticated-only default | FR-001 | §1–3, 7–8; research D1 |
| Shared not-found factory in show() | FR-003 | §9–12 |
| Mutation 403s untouched | FR-004 | §14–17 |
| Byte-identity pin test | NFR-002 / SC-002 | §10 + Verification |
| No per-row checks in discovery | NFR-001 | §7 |

## Out of scope for this WP (do not touch)

- `packages/entity/src/EntityTypeInterface.php` — deliberately not widened (research D2).
- `packages/foundation/**` — `JsonApiRouter`, `BuiltinRouteRegistrar`, kernels: nothing there changes. The adjacent enumeration surfaces (`/api/entity-types`, `/api/openapi.json`, `/api/schema/*`) are a documented follow-up, not yours.
- `packages/api/src/JsonApiRouteProvider.php` — the `api.discovery` route stays `allowAll()`; no route changes anywhere.
- Collection `index()` behavior (#1605), translation controller, field auto-save.
- CHANGELOG and `docs/specs/**` — WP03 owns documentation.

## Subtasks

### T001 — `discoverable` flag on EntityType

**Files**: `packages/api`-adjacent none; `packages/entity/src/EntityType.php`, `packages/entity/tests/Unit/EntityTypeDiscoverableTest.php` (NEW)

1. Add a trailing constructor param after `$tenancy` (keep `$_fieldDefinitions` last — it is `@internal`): actually place `private bool $discoverable = true` immediately **before** `$_fieldDefinitions` so the internal slot stays terminal. Named-param construction framework-wide makes the position safe; verify no positional construction passes `_fieldDefinitions` (grep `new EntityType(` for positional 15th args — none expected).
2. Accessor:
   ```php
   /**
    * Whether this entity type appears in the GET /api discovery index.
    *
    * Visibility only — CRUD routes and access enforcement are unaffected.
    * Read duck-typed by ApiDiscoveryController (EntityTypeInterface is
    * deliberately not widened; see mission request-surface-hardening research D2).
    */
   public function isDiscoverable(): bool
   ```
3. `fromClass()`: add `bool $discoverable = true` parameter and pass it through to the `new self(...)` construction. Like other non-tenancy overrides it is first-call-wins under the cache — no fail-loud needed (only tenancy is a security boundary there).
4. Tests (`EntityTypeDiscoverableTest`, namespace matching `packages/entity/tests/Unit/`): default true; explicit false; `fromClass()` passthrough (use an existing attribute-carrying fixture class — see `EntityTypeFromClassTest` for the pattern and remember `EntityType::clearFromClassCache()` in setUp/tearDown).

**Validation**: `./vendor/bin/phpunit packages/entity/tests/ --no-progress`; `composer phpstan`.

### T002 — Discovery filtering

**Files**: `packages/api/src/ApiDiscoveryController.php`, `packages/api/src/Http/Router/DiscoveryRouter.php`

1. `ApiDiscoveryController`: add trailing ctor param `private readonly ?AccountInterface $account = null` (import `Waaseyaa\Access\AccountInterface` — api already requires access via existing edges; verify with `bin/check-package-layers`). Keep `$basePath` semantics untouched.
2. `discover()`:
   ```php
   $links = ['self' => $this->basePath];
   if ($this->account?->isAuthenticated() === true) {
       foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
           if (method_exists($definition, 'isDiscoverable') && !$definition->isDiscoverable()) {
               continue; // non-discoverable: absent for every caller (FR-002)
           }
           $links[$id] = [ /* unchanged shape */ ];
       }
   }
   ```
   Null account or anonymous → zero type links, envelope unchanged (contract §1–2). Update the class docblock: it currently says "Lists all registered entity types" — now account-dependent.
3. `DiscoveryRouter::handle()` (`:40-44`): pass `$ctx->account` into the construction — `new ApiDiscoveryController($this->entityTypeManager, account: $ctx->account)`. Note `WaaseyaaContext::$account` is typed non-nullable `AccountInterface` but hydrated from `attributes->get('_account')` which can be null in unit harnesses — the controller's nullable param absorbs that; do not add defensive logic in the router.
4. NFR-001 sanity: the only added work is `isAuthenticated()` once + `method_exists`/accessor per type. No access handler, no storage, no queries — do not "improve" this with policy checks (research D1 explains why there is no usable categorical check).

**Validation**: T005 test matrix; `composer cs-check`.

### T003 — show(): denied returns the not-found shape

**Files**: `packages/api/src/JsonApiController.php`

1. Extract the existing missing-branch construction verbatim into one private factory:
   ```php
   /**
    * Canonical single-read 404. Used for BOTH a nonexistent id and a
    * view-denied entity — byte-identical on purpose (FR-003 / NFR-002,
    * mission request-surface-hardening-01KTX7F2). Do not fork the message.
    */
   private function notFoundDocument(string $entityTypeId, int|string $id): JsonApiDocument
   {
       return $this->errorDocument(
           JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found."),
       );
   }
   ```
   MOVE the literal — do not retype it (the pin test will catch drift, but don't rely on it).
2. Missing branch (`:141-145`) → `return $this->notFoundDocument($entityTypeId, $id);`.
3. Denied branch (`:147-155`): keep the access check exactly as-is (`accessHandler !== null && account !== null`, `check($entity, 'view', $account)`, `!$access->isAllowed()`), but replace the forbidden return with `return $this->notFoundDocument($entityTypeId, $id);`. The entity must not be serialized; the `AccessResult` reason must not leak into the response.
4. Touch nothing else: the unknown-entity-type 404 (`:133-137`, different message — contract §17), `store`/`update`/`destroy`/field-edit forbidden returns (FR-004), and the allowed-path serialization stay byte-identical.
5. No debug-mode branch of any kind (research D3) — if a reviewer asks for one, the answer is the research doc.

**Validation**: T004 pin test; T005 updated access-control tests.

### T004 — NFR-002 byte-identity pin test

**Files**: `packages/api/tests/Unit/JsonApiControllerDeniedNotFoundTest.php` (NEW)

Follow the fixture style of `JsonApiControllerAccessControlTest` (anonymous-class policy into a real `EntityAccessHandler`, in-memory storage, real serializer — `createMock()` cannot do the intersection types).

1. Arrange one controller with: a registered type, one persisted entity (known id `X`), a policy whose `access()` returns `AccessResult::forbidden(...)` for `view`, and a non-anonymous account.
2. `$denied = $controller->show($type, X); $missing = $controller->show($type, 'does-not-exist-999');`
3. Assert `json_encode($denied->toArray(), JSON_THROW_ON_ERROR) === json_encode($missing->toArray(), ...)` — wait: the detail string interpolates the *probe id*, so byte-equality holds per-probe, not across different ids. Pin it correctly: ALSO request the **same id** in a denied world vs a world where that id was never created (two controller/storage arrangements, same id `X`) and assert byte-equality there — that is the oracle the spec describes (same probe, two worlds). Keep both assertions: same-id-two-worlds byte-equality (the real pin) and equal `statusCode` (404) on both.
4. Assert the denied document contains no `code` member and no trace of "denied"/"forbidden"/the policy reason (string scan of the encoded body).
5. Guard: with an allowing policy, `show()` still returns the entity resource (no over-404ing).

**Validation**: `./vendor/bin/phpunit packages/api/tests/Unit/JsonApiControllerDeniedNotFoundTest.php --no-progress`.

### T005 — Deliberate updates to tests that encode the old behavior

**Files**: `packages/api/tests/Unit/JsonApiControllerAccessControlTest.php`, `packages/api/tests/Unit/ApiDiscoveryControllerTest.php`, `packages/api/tests/Unit/Http/Router/DiscoveryRouterTest.php`, `tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php`

1. `JsonApiControllerAccessControlTest`: the denied-**show** assertions (status `'403'`) encode the #1649 oracle — update them to the not-found shape (status `'404'`, title `Not Found`, no `code`). The denied-**update/delete/create** assertions stay `'403'` UNCHANGED (FR-004); if any of them starts failing you broke FR-004 — stop and fix the source, not the test.
2. `ApiDiscoveryControllerTest`: keep the existing shape tests but make them authenticated (pass an authenticated account stub); add: anonymous account → only `self` in links; null account → only `self`; `discoverable: false` type absent for an authenticated account while a sibling discoverable type is present; envelope `meta` constant across all cases.
3. `DiscoveryRouterTest`: assert the account from `_account` reaches the controller — e.g. a request with an authenticated `_account` lists a registered type, the same request with an anonymous `_account` does not. Mind the existing fixture (`:52` sets `_controller`); `WaaseyaaContext::fromRequest` also reads `_broadcast_storage` — mirror the existing test's attribute setup.
4. `ApiDiscoveryIntegrationTest` (Phase7): route-shape tests (`_public`, path, methods, controller string) must pass UNCHANGED — the route really does stay public. The listing tests (`:97-114` asserting `['article', 'tag']`) become the authenticated case; add the anonymous case asserting zero type links. Follow the file's existing kernel/fixture style.

**Validation**: full owned-test run, plus `./vendor/bin/phpunit packages/api/tests/ --no-progress` (nothing else in the api suite may regress).

### T006 — Gates

```bash
./vendor/bin/phpunit packages/api/tests/ packages/entity/tests/ tests/Integration/Phase7/ --no-progress
composer phpstan
composer cs-check
bin/check-package-layers
bin/check-dead-code
```

No new manifest edges; no PHPStan baseline additions; `isDiscoverable()` is wired (controller reads it) so no `@api` needed — but `fromClass()`'s new param is reflection-invisible to the dead-code gate anyway (parameters aren't symbols).

## Edge cases & risks (from the plan premortem)

- **Anonymous clients that walked `links.*`** lose enumeration by design. Do not add a "but list public types" carve-out — there is no categorical check to decide "public" with (research D1); that is WP03's CHANGELOG line, not your code.
- **`DevAdminAccount`** is authenticated → sees discoverable types. Correct (it is an account N).
- **Same-id-two-worlds** is the only honest byte-identity pin (T004 step 3) — a naive denied-vs-other-id comparison would pass even with a leaky message.
- **Detail-string drift** is the long-term failure mode for NFR-002; the single factory + the moved (not retyped) literal + the pin test are the three locks.
- **`method_exists` on the interface stubs**: definitions in tests may be `EntityTypeInterface` anonymous classes without the accessor — they must be treated as discoverable (open default), which the `method_exists(...) && !...` guard does. Don't invert it.

## Definition of Done

- [ ] All six subtasks complete; owned suites green; full `packages/api/tests/` green.
- [ ] Contract `discovery-and-404.md` clauses 1–17 each verifiably hold (reviewer walks the list).
- [ ] Anonymous discovery response contains no registered type id anywhere in its body (SC-001).
- [ ] Same-id denied vs missing: byte-identical bodies, equal status (SC-002 / NFR-002).
- [ ] Mutation-denial assertions unchanged and green (FR-004).
- [ ] `composer phpstan`, `composer cs-check`, `bin/check-package-layers`, `bin/check-dead-code` clean; no changes outside `owned_files`.

## Reviewer guidance

- Diff `JsonApiController` for exactly TWO behavioral edits: the extracted factory and the denied-branch return swap. Any change to mutation paths, the unknown-type 404, or serialization is scope creep — reject.
- Grep the diff for the 404 detail literal: it must appear exactly once in production code (the factory).
- Verify the discovery filter consults nothing but `isAuthenticated()` and `isDiscoverable()` — any `EntityAccessHandler`/storage usage in `discover()` violates NFR-001 and research D1; reject.
- Confirm `EntityTypeInterface` is untouched and `_fieldDefinitions` remained the terminal ctor param.
- Run the Phase7 integration test yourself — the `_public` route assertion passing is the proof the fix is body-level, not route-level.

## Activity Log

- 2026-06-12T00:00:00Z – spec-kitty.tasks – created
