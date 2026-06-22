# Implementation Plan: Request-Surface Hardening

**Branch**: `main` | **Date**: 2026-06-12 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `kitty-specs/request-surface-hardening-01KTX7F2/spec.md`
**Tracking**: #1649, #1650, #1652 | **Target release**: v0.1.0-alpha.206

## Summary

Close three independent request-surface gaps. Verified current state (full evidence in [research.md](research.md)):

1. **Discovery enumerates everything for everyone.** `ApiDiscoveryController::discover()` lists every registered entity type unconditionally ([packages/api/src/ApiDiscoveryController.php:30-35](../../packages/api/src/ApiDiscoveryController.php)); the `api.discovery` route is `allowAll()` ([packages/api/src/JsonApiRouteProvider.php:37-44](../../packages/api/src/JsonApiRouteProvider.php)). The controller is built inline per request by `DiscoveryRouter::handle()` ([packages/api/src/Http/Router/DiscoveryRouter.php:40-44](../../packages/api/src/Http/Router/DiscoveryRouter.php)) which already holds the request account via `WaaseyaaContext::fromRequest()` (`_account` attribute, [packages/foundation/src/Http/Router/WaaseyaaContext.php:31-39](../../packages/foundation/src/Http/Router/WaaseyaaContext.php)) — the account just never reaches the controller.
2. **No categorical per-type view check exists** — the spec's primary assumption is falsified. `AccessPolicyInterface::access()` requires a concrete `EntityInterface` ([packages/access/src/AccessPolicyInterface.php:24](../../packages/access/src/AccessPolicyInterface.php)); the only type-level seam is `createAccess()` (create-only, `:33`); `EntityAccessHandler` exposes no policy list and no type-view aggregate ([packages/access/src/EntityAccessHandler.php:82-129](../../packages/access/src/EntityAccessHandler.php)). The spec's pre-approved fallback applies: discoverable flag + authenticated-only default (research D1).
3. **Denied single reads are an existence oracle.** `JsonApiController::show()` returns the not-found shape for a missing entity ([packages/api/src/JsonApiController.php:141-145](../../packages/api/src/JsonApiController.php)) but a 403 `FORBIDDEN` shape for a real-but-denied one (`:147-155`). Both responses exit through the same emitter (`JsonApiRouter::handle()` → `jsonApiResponse($document->statusCode, $document->toArray())`, [packages/foundation/src/Http/Router/JsonApiRouter.php:47-69](../../packages/foundation/src/Http/Router/JsonApiRouter.php)), so byte-identity is achievable purely inside the controller. Mutations keep their genuine 403s (`update` `:360-366`, `destroy` `:432-438`, FR-004).
4. **Bearer auth is a plain hash lookup with no liveness check.** `BearerTokenAuth::authenticate()` resolves `$this->tokens[$token] ?? null` ([packages/mcp/src/Auth/BearerTokenAuth.php:26-28](../../packages/mcp/src/Auth/BearerTokenAuth.php)) — non-constant-time, and a blocked account's token keeps working. `AccountInterface` carries no status member ([packages/access/src/AccountInterface.php](../../packages/access/src/AccountInterface.php)); `User implements AccountInterface` and exposes `isActive(): bool` ([packages/user/src/User.php:29,340-343](../../packages/user/src/User.php)) — the duck-typed seam (research D4).
5. **A relative `WAASEYAA_DB` resolves against process CWD.** `DatabaseBootstrapper::resolvePath()` passes the env value verbatim into `DBALDatabase::createSqlite()` ([packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php:25-43](../../packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php), env read `:29`); SQLite then resolves relative paths against CWD. Every kernel runtime funnels through this one seam (`AbstractKernel::bootDatabase()` at [packages/foundation/src/Kernel/AbstractKernel.php:197](../../packages/foundation/src/Kernel/AbstractKernel.php)) — HTTP (`public/index.php` computes `$projectRoot = dirname(__DIR__)` regardless of CWD, `:23`), CLI/queue (`packages/cli/bin/waaseyaa`, `projectRoot = getcwd()` validated against `composer.json`). The CLI's `db:init` has its own divergent resolution that absolutizes env values but passes `config['database']` through verbatim and only recognizes `/`-prefixed absolutes ([packages/cli/src/Handler/DbInitHandler.php:160-181](../../packages/cli/src/Handler/DbInitHandler.php)). The unset default is already project-root-absolute (`:29`) — unchanged (acceptance scenario 5).

The work: account-aware discovery with an authenticated-only default plus a `discoverable` flag on `EntityType` (D1/D2); a shared not-found document factory in `show()` pinned byte-identical by test (D3); `hash_equals` full-scan token comparison plus a duck-typed `isActive()` fail-closed check (D4); project-root absolutization in `DatabaseBootstrapper` mirrored by the CLI handlers, with a boot-time docroot warning (D5/D6).

## Technical Context

**Language/Version**: PHP 8.5+ (charter baseline), Symfony 7.x components
**Primary Dependencies**: none new. No new internal manifest edges (the mcp blocked-state check is duck-typed precisely to avoid an `mcp → user` edge; api already requires entity/access; foundation/cli edges unchanged)
**Storage**: no schema changes. One behavioral path change in database *connection* resolution (relative → project-root-absolute); absolute paths and the unset default are byte-identical before/after (FR-007, scenario 5)
**Testing**: PHPUnit 10.5 — per-package unit tests (api, entity, mcp, foundation, cli), the NFR-002 byte-identity pin test, and a new kernel-level `tests/Integration/DbPath/` suite proving HTTP and CLI resolve the same file under a docroot CWD
**Target Platform**: Framework monorepo; ships in v0.1.0-alpha.206 under the CI-gated release flow (C-002)
**Project Type**: Monorepo packages — `api` (L4), `entity` (L1), `mcp` (L6), `foundation` kernel (sanctioned cross-layer), `cli` (L6), docs
**Performance Goals**: NFR-001 — discovery filtering is one `isAuthenticated()` call + one boolean accessor per registered type, zero queries, zero row-level checks; NFR-003 — bearer changes add zero database queries (in-memory `hash_equals` scan + in-memory status read)
**Constraints**: C-001 (403→404 documented under `[Unreleased]`), C-003 (SecurityHeadersMiddleware #1651 untouched), C-004 (no new configuration vocabulary beyond the `discoverable` flag — the authenticated-only discovery default is behavior, not config), layer discipline, PHPStan baseline, dead-code gate, composer policy
**Scale/Scope**: 9 production files + CHANGELOG + 3 spec docs; zero new classes (one new ctor param, one accessor, one private factory method, two hardened methods, one resolution helper), no new packages, no new entity types

## Charter Check

*GATE: evaluated 2026-06-12 against `.kittify/charter/charter.md`.*

- **PHP 8.5 baseline, Symfony components**: PASS — no new runtime deps; `hash_equals` is core PHP.
- **Per-package unit tests**: PASS — new/extended unit tests in `packages/api`, `packages/entity`, `packages/mcp`, `packages/foundation`, `packages/cli`, plus the kernel integration suite (see Project Structure).
- **Quality gates (CI matrix, PHPStan baseline, composer policy, dead-code, getQuery)**: PASS — no exemptions requested; everything ships wired (no dormant scaffolding); no manifest edits at all.
- **DIRECTIVE_003 (decision documentation)**: Six material decisions documented in [research.md](research.md) D1–D6 with rationale and rejected alternatives.
- **DIRECTIVE_010 (spec fidelity)**: One deliberate adaptation, pre-authorized by the spec's own Assumptions block — FR-001's "viewable-in-principle per account" is not decidable with the current access API (no categorical type-level view check exists; verified in research "Verified ground truth"), so discovery uses the spec's named fallback: the `discoverable` flag plus an authenticated-only default (anonymous callers receive the index envelope with zero type links). Documented in research D1. A second minor adaptation: scenario 2's *optional* debug-mode distinction is deliberately not implemented (research D3) — the 404 is uniform in all environments, keeping the NFR-002 pin unconditional.
- **Layer discipline**: PASS — `api` (L4) reads `entity` (L1) and `access` (L1) on existing edges; `mcp` (L6) gains no edge (duck-typed status read); `foundation` kernel files ride the entry-point exemption; `cli` (L6) already requires foundation. No upward imports anywhere.

**Post-design re-check**: PASS — no new violations introduced by the design; Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```
kitty-specs/request-surface-hardening-01KTX7F2/
├── plan.md              # This file
├── research.md          # Phase 0 — verified ground truth + decisions D1–D6
├── data-model.md        # Phase 1 — flag semantics, response shapes, path-resolution table
├── quickstart.md        # Phase 1 — verify-by-hand script for reviewers
├── contracts/
│   ├── discovery-and-404.md     # FR-001..004 — discovery filtering + denied-as-404 contract
│   └── bearer-and-dbpath.md     # FR-005..008 — bearer hardening + path resolution contract
└── tasks.md             # Phase 2 — WP01..WP03 breakdown (this mission: produced in the same pass)
```

### Source Code (repository root)

```
packages/entity/src/
└── EntityType.php                     # MODIFY — additive ctor param `discoverable: bool = true`,
                                       #          accessor isDiscoverable(), fromClass() passthrough (D2/FR-002).
                                       #          EntityTypeInterface deliberately untouched (research D2).

packages/api/src/
├── ApiDiscoveryController.php         # MODIFY — optional ?AccountInterface ctor param; discover() lists
│                                      #          type links only for authenticated accounts; duck-typed
│                                      #          isDiscoverable() skip for every caller (D1/D2, FR-001/FR-002)
├── Http/Router/DiscoveryRouter.php    # MODIFY — pass $ctx->account into the inline construction (:41)
└── JsonApiController.php              # MODIFY — show(): denied-view returns the same not-found document
                                       #          as the missing branch via one shared private factory (D3,
                                       #          FR-003); update/destroy/store/field paths untouched (FR-004)

packages/mcp/src/Auth/
└── BearerTokenAuth.php                # MODIFY — full-scan hash_equals comparison (no early exit, FR-005);
                                       #          duck-typed isActive() fail-closed rejection (D4, FR-006)

packages/foundation/src/Kernel/
├── Bootstrap/DatabaseBootstrapper.php # MODIFY — absolutize relative env/config paths against $projectRoot
│                                      #          (Windows-aware); optional ?LoggerInterface; docroot warning
│                                      #          when the resolved path is inside {projectRoot}/public
│                                      #          (D5/D6, FR-007/FR-008)
└── AbstractKernel.php                 # MODIFY — pass $this->logger into the bootstrapper (:197)

packages/cli/src/Handler/
├── DbInitHandler.php                  # MODIFY — resolveDatabasePath()/absolutize() aligned with the
│                                      #          bootstrapper rules (config values absolutized too;
│                                      #          Windows-aware absolutes) (D5, FR-007)
├── HealthReportHandler.php            # MODIFY — display the resolved path, not the raw env value (:115)
└── AboutHandler.php                   # MODIFY — same (:44)

CHANGELOG.md                           # MODIFY — [Unreleased]: 403→404 consumer break (C-001, prominent),
                                       #          discoverable flag + authenticated-only discovery,
                                       #          bearer hardening, path-resolution fix + docroot warning
docs/specs/api-layer.md                # MODIFY — discovery response contract + denied-as-404 semantics
docs/specs/mcp-endpoint.md             # MODIFY — BearerTokenAuth comparison + blocked-account contract
docs/specs/infrastructure.md           # MODIFY — WAASEYAA_DB resolution rules + docroot warning

tests:
packages/entity/tests/Unit/EntityTypeDiscoverableTest.php        # NEW — flag default/explicit/fromClass passthrough
packages/api/tests/Unit/ApiDiscoveryControllerTest.php           # extend — anonymous/authenticated/flag matrix
packages/api/tests/Unit/Http/Router/DiscoveryRouterTest.php      # extend — account reaches the controller
packages/api/tests/Unit/JsonApiControllerAccessControlTest.php   # UPDATE — denied-show assertions move 403→404
                                                                  #   (deliberate: they encode the #1649 oracle)
packages/api/tests/Unit/JsonApiControllerDeniedNotFoundTest.php  # NEW — NFR-002 byte-identity pin
tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php         # UPDATE — discovery contract becomes
                                                                  #   account-aware (asserts both caller classes)
packages/mcp/tests/Unit/Auth/BearerTokenAuthTest.php             # extend — existing matrix stays green
packages/mcp/tests/Unit/Auth/BearerTokenAuthHardeningTest.php    # NEW — hash_equals scan + blocked rejection (SC-003)
packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php  # extend — relative/absolute/
                                                                  #   climbing/Windows matrix + docroot warning
packages/cli/tests/Unit/Handler/DbInitHandlerTest.php            # extend — config absolutization parity
tests/Integration/DbPath/DbPathResolutionTest.php                # NEW — HTTP-shaped and CLI-shaped resolution
                                                                  #   agree under a docroot CWD (SC-004)
```

**Structure Decision**: Zero new classes. Every change lands inside an existing seam: the per-request controller construction in `DiscoveryRouter`, the two response branches in `show()`, the single auth method, the single path-resolution method every kernel runtime already funnels through. The flag is an additive named ctor param on a `final readonly` value object — the established `EntityType` extension pattern (tenancy, primaryStorageBackend precedents).

## Design Outline

1. **Discoverable flag (FR-002 — D2)** — `EntityType` gains `discoverable: bool = true` (additive named param, after `tenancy`) + `isDiscoverable(): bool` accessor + a `fromClass()` passthrough param. `EntityTypeInterface` is NOT widened (it would break seven anonymous-class fixtures in cli/listing/relationship tests outside this mission's surface); the discovery controller duck-types via `method_exists` — the framework's established forward-seam pattern. Non-discoverable types are absent from the index for *every* caller, admin included; their CRUD routes keep working (discoverability ≠ access).
2. **Discovery filtering (FR-001 — D1)** — `DiscoveryRouter::handle()` passes `$ctx->account` into the inline `new ApiDiscoveryController(...)`. `discover()` emits entity-type links only when the account is authenticated (`isAuthenticated() === true`); anonymous callers get the envelope (`meta` + `links.self`) with zero type links. The route stays `allowAll()` — the *index* responds to everyone; its *contents* are account-dependent. Cost: one boolean call per request plus one accessor per type (NFR-001).
3. **Denied-as-404 (FR-003/FR-004 — D3)** — `show()` extracts one private `notFoundDocument(string $entityTypeId, int|string $id)` used by both the missing branch and the denied branch — identical detail string (`"Entity of type '{type}' with ID '{id}' not found."`), identical `JsonApiError::notFound()` construction, no `code` member. Headers are identical structurally (single emitter in `JsonApiRouter`). The NFR-002 pin test asserts `json_encode()` byte-equality of the two documents plus equal status codes. `update`/`destroy`/`store`/field-edit keep `JsonApiError::forbidden()` (FR-004). No debug-mode bypass (research D3): the 404 is uniform in every environment.
4. **Constant-time bearer comparison (FR-005 — D4)** — `authenticate()` replaces the array lookup with a full scan: `foreach ($this->tokens as $candidate => $account) { if (hash_equals((string) $candidate, $token)) { $matched = $account; } }` — string-cast (PHP coerces numeric-string keys to int), no early break, single return. `getTokens()` (admin fingerprinting) unchanged.
5. **Blocked-account rejection (FR-006 — D4)** — after a match: if the account exposes `isActive()` (duck-typed `method_exists`) and it returns `false`, return `null` (fail closed, indistinguishable from a wrong token). Grounded: production token maps hold `User` entities (which implement `AccountInterface` and `isActive()`); kernels are per-request in every runtime (`public/index.php` builds a fresh kernel per request even in worker mode), so the map's account objects — and thus the status read — are request-fresh. Zero added queries (NFR-003). Accounts without a status method pass unchanged (open by construction; documented in the contract).
6. **Project-root path resolution (FR-007 — D5)** — `DatabaseBootstrapper::resolvePath()` absolutizes any relative path — whether from `config['database']` or `WAASEYAA_DB` — against `$projectRoot` before directory creation and `createSqlite()`. "Absolute" is Windows-aware: `:memory:`, leading `/`, drive-letter (`X:` + separator), and UNC (`\\`) pass through. `../`-climbing relatives concatenate onto the project root (spec edge case). The unset default is already absolute — byte-identical behavior (scenario 5). One fix site covers HTTP, dev server, CLI, and queue because all four boot through `AbstractKernel:197`. The CLI's parallel resolution in `DbInitHandler` adopts the same rules (it currently passes config values through verbatim and treats only `/`-prefixed paths as absolute); the two display surfaces (`health:report`, `about`) show the resolved path.
7. **Docroot warning (FR-008 — D6)** — `DatabaseBootstrapper::boot()` gains `?LoggerInterface $logger = null` (the framework's standard optional-logger pattern); `AbstractKernel` passes `$this->logger`. After resolution, if the normalized resolved path sits under `{projectRoot}/public` (the framework docroot — `public/index.php`), log a `warning` naming both paths. Boot proceeds (warn, don't refuse).
8. **Docs & CHANGELOG (C-001)** — `[Unreleased]` entries with the 403→404 consumer-visible break leading; `api-layer.md` (discovery contract + show semantics), `mcp-endpoint.md` (auth contract), `infrastructure.md` (resolution table) updated in the same release. Note: `[Unreleased]` currently also carries the alpha.205 provenance entries (tag is v0.1.0-alpha.204); this mission's entries append alongside whatever is still uncut.

## Risks (premortem)

- **Anonymous consumers lose discovery links** — any anonymous client that walked `links.*` to find endpoints now sees only `self`. Accepted and intended (the leak *is* the listing); concrete endpoints keep working; SC-001 is the point. The CHANGELOG entry states it explicitly so app authors aren't surprised.
- **Adjacent enumeration surfaces remain** — `/api/entity-types`, `/api/openapi.json`, and `/api/schema/{entity_type}` (BuiltinRouteRegistrar `:31-53`) are option-less routes (AccessChecker neutral) that also enumerate type ids. Out of this spec's scope (its Key Entity is the `GET /api` discovery index); flagged for a follow-up issue so SC-001's spirit isn't quietly defeated. WP03 documents the boundary in `api-layer.md`.
- **Existence oracle residue on mutation routes** — an authenticated caller PATCHing a view-denied entity still gets 403 (FR-004 keeps genuine authz errors). This leaks existence to *authenticated* probers only (mutation routes require authentication). Accepted per FR-003's deliberate single-read scope; documented in the contract.
- **404 bytes drift apart later** — someone edits one branch's message and the oracle silently returns. Mitigation: a single shared private factory (one construction site) + the NFR-002 byte-equality pin test that fails on any divergence.
- **Tests that encode the old behavior** — `JsonApiControllerAccessControlTest` asserts `'403'` on denied show; `ApiDiscoveryIntegrationTest` asserts all types listed (and `_public` on the route — which stays true). These assertions encode the #1649 bug; updating them is deliberate and visible. The route-shape assertions must keep passing unchanged.
- **Numeric-string tokens become int array keys** — PHP key coercion means `hash_equals($candidate, $token)` would throw on an int candidate. Mitigation: explicit `(string)` cast in the scan; pinned by a test with a numeric token.
- **A custom `AccountInterface` implementation without `isActive()`** — the duck-typed check cannot reject it. Accepted: the framework's own account objects (`User`) expose it; the contract documents that custom auth implementations owning their account class also own its liveness semantics. An `AccountInterface` widening is deliberately out of scope (too large for this mission).
- **Path absolutization changes a working setup** — a deployment that *relied* on CWD-relative resolution (CWD ≠ project root, intentionally) would start pointing at the project-root file. This is precisely the silently-forked-database bug class the fix exists to kill; C-001-style CHANGELOG entry calls it out. Absolute paths and the default are untouched (scenario 5).
- **Windows path detection** — drive-letter and UNC forms must not be "absolutized" into garbage. The resolution matrix in data-model.md is pinned by unit tests on both separators; CI runs Linux but the unit tests exercise the literal string forms.
- **Docroot warning false negatives via symlinks/normalization** — the containment check normalizes separators and resolves `..` segments lexically (realpath only when the file exists); a symlinked docroot may evade it. Accepted: the warning is best-effort advisory, not a security boundary.

## Complexity Tracking

*No charter violations to justify.*
