# Feature Specification: Admin CRUD Correctness (Edit feedback packaging, Delete persistence)

**Mission:** `admin-crud-correctness-01KVGEPD` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-19

## Summary

The admin CRUD demo failed on two of the four operations. Root-causing showed they are **different in nature** and must not be conflated:

- **D6 — Edit busy-state feedback absent (alpha.226 headline feature).** The feedback code (`Opening…`, `aria-busy`, route-level `NuxtLoadingIndicator`, parallelized + deduped fetches) **shipped correctly in SOURCE** (`packages/admin/app/…`, PR #1678) but the demo app was served a **stale prebuilt admin bundle**. Downstream consumers run a prebuilt SPA committed at `packages/admin-surface/dist/` and shipped via Composer; that bundle was last rebuilt **2026-06-05**, 14 days *before* the feature merged, and the release tagged with source-vs-bundle drift. This is a **packaging/release-process gap**, not a regression and not a too-fast flash.
- **D7 — Delete never persists + misleading "Not found".** A single root cause produces both symptoms: `GenericAdminSurfaceHost::handleDelete` resolves the entity with a bare `load($id)` (no UUID fallback) while the SPA passes the entity **UUID** — so the lookup misses, the method returns a 404 "Not found" **before** ever calling `delete()`, and that 404 bubbles to the list view as a banner.

This mission (a) closes the admin-dist packaging gap so the shipped bundle can never silently lag the source, and (b) fixes `handleDelete` to resolve by UUID like its sibling methods, while preserving the delete authorization boundary. It also lands **Wayfinding Phase-1 groundwork** (stable `data-anchor` IDs on the schema-driven admin) — the beacon overlay will ship in this same admin bundle, so the freshness gate and the anchor seeds belong together.

## Resolved Decisions (locked — no longer open)

- **D6:** Keep the committed prebuilt `packages/admin-surface/dist`. Add a **content-signature** (NOT timestamp) freshness gate that fails CI when `packages/admin/app` changes without a corresponding dist rebuild, and assert the served bundle contains the feedback markers (e.g. `Opening`). **Do not touch the alpha.226 feedback feature code.**
- **D7:** `handleDelete` mirrors `get()` — `is_numeric($id) ? $storage->load($id) : $storage->loadByKey('uuid', $id)` (storage `accessCheck(false)`), THEN enforce `EntityAccessHandler::check($entity,'delete',$account)` before `$storage->delete([$entity])`. Harden the SPA error surfacing so a genuine 404 and a failed-delete read differently.
- **Wayfinding Phase-1 groundwork (parent `wayfinding-01KVGH5X`, FR-006):** while in `SchemaView`/`SchemaForm`/`SchemaList`, add stable `data-anchor` IDs derived from schema field identity (entity type + field/operation), so the anchor catalog has real seeds before Wayfinding lands. Anchors are inert attributes only in this mission — no overlay, no delivery.

## Actors

- **Admin user / cold agent** — edits and deletes content in the admin SPA and expects visible busy feedback and a delete that actually removes the row.
- **Framework maintainer / release engineer** — owns the admin-dist build/commit workflow and the release-cut gate.

## Evidence (the failures to eliminate)

### D6 — stale bundle (packaging gap)

1. Feature is in SOURCE only: commit `eacbf42d9` (PR #1678, tag v0.1.0-alpha.226) edits `packages/admin/app/app.vue` (route `NuxtLoadingIndicator`), `components/schema/SchemaList.vue` (Edit link → `aria-busy`/`is-busy`/`Opening…`), `SchemaView.vue` + `SchemaForm.vue` (`role="status"` busy region, `Promise.allSettled` fetches), `adapters/AdminSurfaceTransportAdapter.ts` (`inflightGets` dedup), `i18n/en.json` (`"opening"`).
2. Consumers are served a prebuilt bundle, not source: `.github/workflows/admin-dist.yml` runs `npm run generate` and **commits** the output into `packages/admin-surface/dist/`; that tree ships in the `waaseyaa/admin-surface` Composer package. `AdminSurfaceServiceProvider::routes()` serves `/admin/{path}` with tier-1 `<projectRoot>/public/admin/{path}` then tier-2 vendored `dist/`.
3. The shipped bundle is stale: `git diff v0.1.0-alpha.225 v0.1.0-alpha.226 -- packages/admin-surface/dist` is **empty**. Build manifest at the tag = `{"id":"1d7ffc6c…","timestamp":1780665145674}` (2026-06-05). Last dist rebuild = commit `ec71a9d6a` (2026-06-05), feature merged `eacbf42d9` (2026-06-19); no dist commit landed for the feature.
4. my-app serves that exact stale bundle: `my-app/public/admin/` is empty, so it falls through to `vendor/waaseyaa/admin-surface/dist/`, which is byte-identical to the repo dist (tree sha256 `c5ebf44c…`; entry chunk `3JnrbJ8E.js` sha256 `ca780ccd…`). Token scan of the served bundle: `Opening`=0, `navigatingId`=0, `NuxtLoadingIndicator`=0, `allSettled`=0, `inflightGets`=0; old `Loading...` present. The feedback code is **absent from the running bundle** — conclusively ruling out regression and too-fast.

### D7 — delete UUID mismatch

5. SPA sends the UUID: `AdminSurfaceTransportAdapter.remove()` POSTs `{action:'delete', id:<resourceId>}` to the admin-surface action endpoint; `ResourceSerializer::serialize` sets the JSON:API id to the **UUID** for int-PK content entities ([packages/api/src/ResourceSerializer.php:61-67](packages/api/src/ResourceSerializer.php)).
6. `handleDelete` ignores the UUID path: [packages/admin-surface/src/Host/GenericAdminSurfaceHost.php:448](packages/admin-surface/src/Host/GenericAdminSurfaceHost.php) is a bare `$entity = $storage->load($id);` with **no** `is_numeric ? load : loadByKey('uuid')` fallback — unlike `get()` (lines 245-250) and `resolveSchemaBundle()` (lines 343-346) which **do** have it. Verified by reading the file.
7. Both symptoms from one cause: `load('<uuid>')` queries the INTEGER `id` column → 0 rows → `null` → line 450-451 returns 404 "Not found" and **never reaches `$storage->delete([$entity])`** (line 464). The row survives (JSON:API GET still 200), and the 404 title surfaces in `SchemaList.vue::deleteEntity`'s catch as the list banner.
8. Empirical confirmation on my-app's SQLite: `story.id` INTEGER, `uuid` CLOB; `WHERE id='<uuid>'` → 0 rows, `WHERE uuid='<uuid>'` → the row. Single dev-server process on a single on-disk DB rules out the ephemeral-connection theories (#1611/#1650).

## User Scenarios & Testing

1. **Edit shows feedback.** In a freshly-shipped admin bundle, clicking Edit immediately shows the route `NuxtLoadingIndicator`, the Edit control goes `aria-busy`/disabled with `Opening…`, and a `role="status"` busy region spans the load.
2. **Delete persists.** Deleting a content entity from the SPA (which sends the UUID) removes the row from storage; a subsequent JSON:API GET of that resource returns 404; the SPA returns to the list with the row gone and **no** error banner.
3. **Shipped bundle matches source.** A release cannot be tagged when `packages/admin-surface/dist/` is out of sync with `packages/admin/app/` source.

### Edge cases

- Delete with a numeric id (non-content or int-keyed entity) must still work (preserve the `is_numeric` branch).
- Delete on a genuinely missing entity must still 404 — but only after both id and uuid lookups miss.
- A delete the account is not authorized for must still be denied by `EntityAccessHandler::check($entity,'delete',$account)` (the access check runs *after* resolution).
- An app that ships its own `public/admin/` override bundle must not be forced stale by the framework dist.

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | `GenericAdminSurfaceHost::handleDelete` MUST resolve the entity by id OR uuid (`is_numeric($id) ? load($id) : null`, then `loadByKey('uuid',$id)`), matching `get()` / `resolveSchemaBundle()`, so a SPA delete keyed by UUID resolves the real entity. | **Accepted** |
| FR-002 | After resolution, `handleDelete` MUST still enforce `EntityAccessHandler::check($entity,'delete',$account)` and MUST call `$storage->delete([$entity])` on success, returning `['deleted'=>true]`. | **Accepted** |
| FR-003 | The post-delete admin UX MUST NOT render a backend 404 title as a generic list banner; on a successful delete the SPA stays on the list with the row removed and no error; a genuine 404 and a failed-delete MUST read differently. | **Accepted** |
| FR-004 | A CI gate (`bin/check-admin-dist-fresh`, wired into `composer verify`) MUST fail when `packages/admin/app` source changes without a corresponding dist rebuild, by comparing a committed **content signature** of the admin source against a freshly recomputed one. The signature MUST be line-ending-normalised so it matches across Windows/Linux. | **Accepted** |
| FR-005 | The dist rebuild + signature update MUST be a single reproducible step (`bin/build-admin-dist`), so the committed `dist/` and the committed signature stay in lockstep; the gate makes a stale bundle un-shippable. | **Accepted** |
| FR-006 | A served-bundle content assertion (PHP test over the vendored `dist/` served by the `admin_spa` route) MUST verify the bundle contains the current feedback markers (e.g. `Opening`), so a stale bundle also fails the PHP suite. | **Accepted** |
| FR-007 | **(Wayfinding Phase-1 groundwork)** `SchemaView`/`SchemaForm`/`SchemaList` MUST emit stable `data-anchor` IDs derived from schema field identity (entity type + field name / operation), so the future anchor catalog has real seeds. Anchors are inert attributes in this mission — no overlay/delivery. | **Accepted** |

### Non-Functional / Security (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | The UUID-resolution change MUST NOT weaken the delete authorization boundary; `loadByKey('uuid',…)` (storage-level `accessCheck(false)`) followed by the per-entity `EntityAccessHandler::check(...,'delete',...)` is the **decided** admin-delete pattern (matches `get()`). | **Accepted** |
| NFR-002 | The packaging gate MUST be deterministic across OSes (no reliance on local clock/timestamps that drift) — use a content signature, not the build timestamp. | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | D6's feedback CODE is correct and MUST NOT be modified to "fix" the demo — the remediation is packaging + a freshness gate, not a code change to the feature. | Accepted |
| C-002 | Prefer extracting one shared `resolveEntity(type,id)` helper used by `get`/`handleUpdate`/`handleDelete`/`resolveSchemaBundle` to stop the drift that caused D7 — confirm scope before extracting. | Proposed |
| C-003 | No BC shims (no deployed downstream apps). | Accepted |

## Success Criteria

- SC-001: A unit test drives `action(type,'delete',['id'=>$uuid])` where numeric id ≠ uuid with a delete-allowing access handler and asserts success `['deleted'=>true]` (not 404) and that `delete()` was invoked; an integration test (`DBALDatabase::createSqlite()`) deletes via the action endpoint by UUID and asserts `loadByKey('uuid',$uuid) === null`.
- SC-002: A packaging-freshness gate fails on current `main` if `packages/admin/app` is changed without a dist rebuild, and a served-bundle assertion finds `Opening` in the shipped dist after a rebuild.
- SC-003: Against a freshly-built bundle, the Edit busy feedback is observable; against my-app after a dist rebuild + `composer update`, the busy region appears.
- SC-004: `composer verify` and the admin frontend tests (`SchemaList.test.ts`, `AdminSurfaceTransportAdapter.test.ts`) stay green.

## Key Entities

- `GenericAdminSurfaceHost` (`handleDelete`, `get`, `resolveSchemaBundle`), `AdminSurfaceTransportAdapter`, `SchemaList.vue`, `ResourceSerializer`, `SqlEntityStorage` (`load`, `loadByKey`, `delete`).
- `AdminSurfaceServiceProvider` (admin_spa route, dist tiers), `packages/admin-surface/dist/` build manifest, `.github/workflows/admin-dist.yml`, `release-cut.yml` / `scripts/release.sh`.

## Assumptions

- A-001: The admin-dist commit-the-bundle distribution model is retained for now; the gate makes drift impossible to ship. Whether to move to build-at-install or release-asset distribution is a separate decision (see open questions).
- A-002: **D8 (dev auto-auth) is intended behaviour, not in scope as a fix.** `/admin` opening to the dashboard with no login is `DevAdminAccount` injected by `SessionMiddleware` when `shouldUseDevFallbackAccount()` passes (SAPI ∈ {cli-server, frankenphp} ∧ development mode ∧ `auth.dev_fallback_account`); prod boot aborts on `APP_DEBUG`. To demonstrate real auth on camera, set `WAASEYAA_DEV_FALLBACK_ACCOUNT=false` in the app `.env` (keeps `APP_ENV=local`) and sign in via the real `POST /api/auth/login`. This is a demo-runbook note; the only possible deliverable is documentation.
- A-003: `handleUpdate` already routes through `JsonApiController::update` → `loadByIdOrUuid` (UUID-aware); confirm with an end-to-end UUID update that it is unaffected.

## Scope

**In:** the `handleDelete` UUID-resolution fix + access-preserving delete; the post-delete error-surfacing fix; an admin-dist freshness gate wired into `composer verify`/release-cut; a served-bundle content assertion; an optional shared `resolveEntity` helper; a demo-runbook note for D8.

**Out:** changing the alpha.226 feedback CODE; the open-by-default/missing-AccessPolicy render behaviour (that is D5 / #1605); migrating off the commit-the-bundle distribution model; building a login screen (D8 is dev-mode-only and intended).
