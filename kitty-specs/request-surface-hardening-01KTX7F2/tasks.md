# Tasks: Request-Surface Hardening

**Mission**: `request-surface-hardening-01KTX7F2` | **Branch**: `main` → merges to `main`
**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md) (D1–D6), [data-model.md](data-model.md), [contracts/](contracts/)
**Tracking**: #1649, #1650, #1652 | **Target release**: v0.1.0-alpha.206

## Subtask Index

| ID | Description | WP | Parallel |
|----|-------------|----|----------|
| T001 | EntityType `discoverable` flag (ctor param + accessor + fromClass passthrough) + unit tests | WP01 | | [D] |
| T002 | Discovery filtering: account into ApiDiscoveryController via DiscoveryRouter; authenticated-only default + duck-typed flag skip | WP01 | | [D] |
| T003 | JsonApiController::show(): denied-view returns the shared not-found document (single private factory) | WP01 | | [D] |
| T004 | NFR-002 byte-identity pin test (denied vs missing: bodies byte-equal, status equal, entity never serialized) | WP01 | | [D] |
| T005 | Deliberate test updates: access-control 403→404 assertions, discovery unit/router tests, Phase7 integration account-aware | WP01 | | [D] |
| T006 | WP01 gates: full api/entity suites, phpstan, cs, layers, dead-code | WP01 | | [D] |
| T007 | BearerTokenAuth constant-time full-scan hash_equals comparison (string-cast keys, no early exit) | WP02 | | [D] |
| T008 | Blocked-account duck-typed isActive() fail-closed rejection + auth unit tests (existing matrix unchanged) | WP02 | | [D] |
| T009 | DatabaseBootstrapper: project-root absolutization (Windows-aware) + optional logger + docroot warning; AbstractKernel passes logger | WP02 | | [D] |
| T010 | CLI parity: DbInitHandler resolution rules; HealthReport/About display resolved path; handler tests | WP02 | | [D] |
| T011 | Tests: DatabaseBootstrapper resolution/warning matrix + tests/Integration/DbPath HTTP-vs-CLI agreement under docroot CWD | WP02 | | [D] |
| T012 | CHANGELOG [Unreleased]: 403→404 break (C-001, prominent), discovery default + flag, bearer hardening, path fix + warning | WP03 | |
| T013 | Spec docs: api-layer.md, mcp-endpoint.md, infrastructure.md updated from the contracts | WP03 | |
| T014 | Drift detector run; resolve flags for the three touched specs | WP03 | |
| T015 | Execute quickstart.md steps 1–6 as final validation; record per-step results | WP03 | |

## WP01 — API Hardening: Discovery Filtering & Denied-as-404 (#1649)

**Prompt**: [tasks/WP01-api-hardening.md](tasks/WP01-api-hardening.md) | **Priority**: P1 | **Estimated prompt**: ~320 lines
**Goal**: Anonymous discovery reveals no entity-type ids (authenticated-only default, research D1); types can opt out of discovery entirely via `EntityType(discoverable: false)` (D2); a denied single read is byte-indistinguishable from a missing one (D3, NFR-002 pinned); mutations keep genuine 403s (FR-004).
**Independent test**: `./vendor/bin/phpunit packages/api/tests/ packages/entity/tests/ tests/Integration/Phase7/` green, including the new byte-identity pin and the account-aware discovery matrix.
**Dependencies**: none (lane root)

- [x] T001 EntityType: additive `discoverable: bool = true` ctor param + `isDiscoverable(): bool` + `fromClass()` passthrough; `EntityTypeInterface` untouched (research D2 — seven external test fixtures); new `EntityTypeDiscoverableTest` (WP01)
- [x] T002 DiscoveryRouter passes `$ctx->account` into the inline construction (:41); ApiDiscoveryController gains optional `?AccountInterface $account = null`; discover() lists type links only for `isAuthenticated()` accounts and skips duck-typed `isDiscoverable() === false` types for every caller; envelope (meta + self) constant (WP01)
- [x] T003 JsonApiController::show(): extract single private `notFoundDocument($entityTypeId, $id)`; missing branch and denied-view branch both return it (identical detail string, no `code` member); access check still runs, entity never serialized; store/update/destroy/field paths untouched (WP01)
- [x] T004 NEW `JsonApiControllerDeniedNotFoundTest`: denying policy vs nonexistent id through the same controller → `json_encode(toArray())` byte-equality + equal statusCode (NFR-002 / SC-002); guard that allowed entities still serialize (WP01)
- [x] T005 Deliberate updates: `JsonApiControllerAccessControlTest` denied-show 403→404 (mutation 403 assertions unchanged); `ApiDiscoveryControllerTest` + `DiscoveryRouterTest` matrix; `tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php` route-shape assertions unchanged (`_public` stays), listing assertions split anonymous/authenticated (WP01)
- [x] T006 Gates: `./vendor/bin/phpunit packages/api/tests/ packages/entity/tests/ tests/Integration/Phase7/`; `composer phpstan`; `composer cs-check`; `bin/check-package-layers`; `bin/check-dead-code` (WP01)

**Implementation sketch**: research D1–D3, contracts/discovery-and-404.md is the authoritative behavior spec. The whole change is account-plumbing inside packages/api plus one additive value-object param in packages/entity; zero new classes, zero manifest edges. Biggest review risk: the 404 detail string must be the *moved* literal, not a retyped one — the pin test is the guard.

## WP02 — Bearer Hardening & DB Path Resolution (#1652 + #1650)

**Prompt**: [tasks/WP02-bearer-auth-db-path.md](tasks/WP02-bearer-auth-db-path.md) | **Priority**: P1 | **Estimated prompt**: ~330 lines
**Goal**: Bearer-token comparison is constant-time over all candidates and a blocked account's still-configured token stops authenticating immediately, fail closed, with zero added queries (D4, SC-003); a relative database path resolves against the kernel project root in every runtime — HTTP, dev server, CLI, queue — with a boot warning when the resolved path lands inside the docroot (D5/D6, SC-004); absolute paths and the unset default are byte-identical (scenario 5).
**Independent test**: `./vendor/bin/phpunit packages/mcp/tests/Unit/Auth/ packages/foundation/tests/Unit/Kernel/Bootstrap/ packages/cli/tests/Unit/Handler/ tests/Integration/DbPath/` green, including the existing 7-test bearer matrix unchanged.
**Dependencies**: none (parallel-safe with WP01; disjoint files)

- [x] T007 BearerTokenAuth: full-scan `hash_equals((string) $candidate, $token)` with no early exit, single return; numeric-string-key cast pinned; `getTokens()` untouched (WP02)
- [x] T008 Blocked check: matched account with `method_exists('isActive')` and `isActive() === false` → null (fail closed, indistinguishable from unknown token); NEW `BearerTokenAuthHardeningTest` (blocked/active/no-method/numeric-token/match-position cases); existing `BearerTokenAuthTest` green unchanged (NFR-003: zero queries) (WP02)
- [x] T009 DatabaseBootstrapper: absolutize relative env/config paths against `$projectRoot` (`:memory:`, `/`, drive-letter, UNC pass through; `./` stripped; `../` climbs from project root); optional `?LoggerInterface $logger = null`; lexical docroot containment check → `warning`; AbstractKernel:197 passes `$this->logger` (WP02)
- [x] T010 CLI parity: DbInitHandler `resolveDatabasePath()`/`absolutize()` adopt identical rules (config values absolutized — verbatim today; Windows absolutes recognized — `/`-only today); HealthReportHandler:115 + AboutHandler:44 display the resolved path; handler unit tests (WP02)
- [x] T011 Tests: DatabaseBootstrapperTest resolution + docroot-warning matrix (spy logger; no-logger silence; `:memory:` never warns); NEW `tests/Integration/DbPath/DbPathResolutionTest.php` — HTTP-shaped vs CLI-shaped resolution agree under a docroot CWD, write-through-one/read-through-other, nothing materializes under the docroot (SC-004) (WP02)

**Implementation sketch**: research D4–D6, contracts/bearer-and-dbpath.md authoritative. The path fix is one method (`resolvePath()`) because every runtime funnels through `AbstractKernel:197` — verified in research; the CLI's divergent `db:init` resolution and the two display surfaces are the parity tail. The integration test manipulates CWD — restore it in `finally` and use per-test temp project dirs (scratch-hygiene rule).

## WP03 — Docs & CHANGELOG

**Prompt**: [tasks/WP03-docs-changelog.md](tasks/WP03-docs-changelog.md) | **Priority**: P2 | **Estimated prompt**: ~250 lines
**Goal**: The release is documented before the tag exists: CHANGELOG under `[Unreleased]` leading with the consumer-visible 403→404 break (C-001), the three subsystem specs updated from the contracts, drift detector clean, quickstart walkthrough recorded.
**Independent test**: quickstart.md steps 1–6 pass end-to-end; `composer verify` components green.
**Dependencies**: WP01, WP02

- [ ] T012 CHANGELOG `[Unreleased]` (appending alongside any still-uncut alpha.205 entries — never a pre-stamped heading): **Changed** — denied single reads 403→404 byte-identical to missing (C-001, clients keying on 403 must adapt) + anonymous discovery lists no types (authenticated-only default; anonymous clients walking `links.*` see only `self`) + relative WAASEYAA_DB/config paths now resolve against the project root (CWD-relative setups change deliberately); **Added** — `EntityType` `discoverable` flag, docroot boot warning; **Security** — bearer constant-time comparison + blocked-account fail-closed rejection (WP03)
- [ ] T013 docs/specs updates from the contracts (not from the diff): `api-layer.md` — discovery response contract (account-dependent links, flag semantics, route stays `_public`) + show() denied-as-404 + the adjacent-enumeration boundary note (`/api/entity-types`, `/api/openapi.json`, `/api/schema/*` follow-up); `mcp-endpoint.md` — BearerTokenAuth comparison + blocked-account contract + custom-auth liveness ownership note; `infrastructure.md` — WAASEYAA_DB resolution table + docroot warning (WP03)
- [ ] T014 `tools/drift-detector.sh` run; resolve flags for the three touched specs (WP03)
- [ ] T015 Execute quickstart.md steps 1–6 as final validation; record per-step results in the WP file (WP03)

**Implementation sketch**: write docs from the two contracts and data-model.md. CHANGELOG-under-[Unreleased] is the alpha.202 lesson; the release-cut workflow stamps the heading. Note `[Unreleased]` may still contain the alpha.205 provenance entries — append, don't restructure.

## Lane / Parallelization Summary

- **Lane A**: WP01 → WP03
- **Lane B**: WP02 (parallel with WP01 — fully disjoint owned files; WP03 waits for both)
- MVP scope: WP01 + WP02 (all eight FRs live); WP03 gates the release cut.
