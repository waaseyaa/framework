---
work_package_id: WP03
title: Docs & CHANGELOG
dependencies:
- WP01
- WP02
requirement_refs:
- C-001
- C-002
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
created_at: '2026-06-12T00:00:00+00:00'
subtasks:
- T012
- T013
- T014
- T015
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "20092"
history:
- date: '2026-06-12T00:00:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: docs/specs/
execution_mode: code_change
owned_files:
- CHANGELOG.md
- docs/specs/api-layer.md
- docs/specs/mcp-endpoint.md
- docs/specs/infrastructure.md
tags: []
---

# WP03 — Docs & CHANGELOG

**Mission**: request-surface-hardening-01KTX7F2 | **Tracks**: #1649, #1650, #1652
**Requirements**: C-001 (the 403→404 break documented), C-002 (release-flow constraints; functional FRs covered by WP01/WP02) | **Dependencies**: WP01, WP02
**Command**: `spec-kitty agent action implement WP03 --agent <name>`

## Objective

Document the release before the tag exists. The consumer-visible 403→404 change on denied singles leads the CHANGELOG (C-001); the discovery default change, the `discoverable` flag, the bearer hardening, and the path-resolution fix follow; the three subsystem specs are updated **from the contracts** (not from memory of the diff); drift detector runs clean; the quickstart walkthrough is executed and recorded as final mission validation.

## Context (read first)

- `contracts/discovery-and-404.md` and `contracts/bearer-and-dbpath.md` — the source of truth for every doc sentence you write.
- `data-model.md` — the decision tables to transcribe (per-operation denial table, bearer decision table, path-resolution matrix).
- `research.md` D1–D6 — for the "why" notes the specs carry (especially D1's falsified categorical-check assumption and D3's no-debug-variant decision).
- **CHANGELOG state**: `[Unreleased]` currently still holds the alpha.205 provenance entries (latest tag `v0.1.0-alpha.204`). Append this mission's entries under the SAME `[Unreleased]` heading — never a pre-stamped `alpha.206` heading (the alpha.202 lesson; the release-cut workflow stamps headings). If alpha.205 has been cut by the time you run, `[Unreleased]` is simply empty — same instruction.
- Spec-doc convention: the three files carry dated `<!-- Spec reviewed … -->` HTML comment lines near the top recording what changed and why — follow that exact pattern (see `mcp-endpoint.md:3` for the freshest example, written by the previous mission's docs WP).
- Doc anchors verified at plan time: `api-layer.md` documents the discovery response contract (review note `:40`, JsonApiRouteProvider section `:765`) and the JSON:API controller CRUD semantics (`:239-307`); `mcp-endpoint.md` lists `BearerTokenAuth` (`:43`) and the authenticate step (`:87`); `infrastructure.md` has the `WAASEYAA_DB` env row (`:1637`) and the `DatabaseBootstrapper` paragraph (`:1652`).

## Requirement / contract map

| Deliverable | Requirement | Contract anchor |
|---|---|---|
| CHANGELOG 403→404 entry, prominent | C-001 | discovery-and-404.md §13 |
| CHANGELOG discovery/flag/bearer/path entries | SC-005 | both contracts, all sections |
| api-layer.md update | SC-005 / drift rule | discovery-and-404.md §1–17 |
| mcp-endpoint.md update | SC-005 / drift rule | bearer-and-dbpath.md §1–8 |
| infrastructure.md update | SC-005 / drift rule | bearer-and-dbpath.md §9–19 |
| Quickstart walkthrough executed | mission DoD | quickstart.md steps 1–6 |

## Out of scope for this WP (do not touch)

- Any production or test code — if a quickstart step fails, the finding goes back to the owning WP, not into a hotfix here.
- `docs/specs/access-control.md`, `entity-system.md` — the access API did not change (no categorical check was added; research D1 chose the fallback precisely to avoid touching access).
- README files, UPGRADING.md (alpha-phase breaks live in the CHANGELOG per house convention).

## Subtasks

### T012 — CHANGELOG `[Unreleased]` entries

**Files**: `CHANGELOG.md`

Append under `[Unreleased]`, alongside whatever alpha.205 content remains. Required entries (Keep-a-Changelog sections):

1. **Changed — leading entry (C-001)**: denied single reads return 404. State plainly: `GET /api/{type}/{id}` on an entity the caller is denied `view` on now returns the *same not-found response* as a nonexistent id — byte-identical body (status 404, no `FORBIDDEN` code), closing the existence oracle (#1649). **Clients keying on 403 for denied reads must adapt.** Mutating operations (POST/PATCH/DELETE) keep genuine 403s. No debug-mode variant exists. Reference the same-probe indistinguishability (SC-002).
2. **Changed**: anonymous API discovery lists no entity types. `GET /api` keeps its envelope (`meta` + `links.self`) for all callers, but per-type links are emitted only for authenticated accounts (no categorical per-type view check exists in the access API — the authenticated-only default is the documented fallback). Anonymous clients that enumerated `links.*` must use concrete endpoints. (#1649)
3. **Changed**: relative database paths resolve against the project root. `WAASEYAA_DB` (and `config['database']`) relative values now resolve against the kernel project root in every runtime — HTTP, dev server, CLI, queue — instead of the process CWD; the dev server can no longer silently create a second database under the docroot. Absolute paths, `:memory:`, and the unset default are unchanged. Deployments that relied on CWD-relative resolution change deliberately. `db:init`, `health:report`, and `about` report the same resolved path. (#1650)
4. **Added**: `EntityType` `discoverable: bool = true` constructor flag + `isDiscoverable()` — per-type opt-out from the discovery index, for every caller; visibility only (CRUD routes and access enforcement unaffected). (#1649)
5. **Added**: boot-time warning when the resolved database path is inside the public docroot (`{projectRoot}/public`). (#1650)
6. **Security**: MCP bearer-token hardening — token comparison is constant-time over all configured tokens (`hash_equals` full scan), and a token resolving to a blocked/inactive account (duck-typed `isActive()`) is rejected at request time, fail closed, indistinguishable from an unknown token; zero added queries. (#1652)

House style: bold lead sentence naming the affected packages, prose detail after; issue refs in parentheses; never a version heading.

### T013 — Spec doc updates

**Files**: `docs/specs/api-layer.md`, `docs/specs/mcp-endpoint.md`, `docs/specs/infrastructure.md`

Write from the contracts. Each file gets a dated `<!-- Spec reviewed 2026-06-XX - mission request-surface-hardening-01KTX7F2 WP03: … -->` line plus body updates:

1. `api-layer.md`:
   - Discovery contract (the `:765` JsonApiRouteProvider section + wherever the response shape is described): account-dependent links (authenticated-only default), constant envelope, `discoverable` flag semantics (duck-typed read; `EntityTypeInterface` not widened), route stays `_public`.
   - JSON:API controller CRUD section (`:239-307`): `show()` denied-view → canonical not-found document, single factory, byte-identity pinned by test (NFR-002); the per-operation denial table from data-model.md; FR-004 boundary (mutations keep 403; residual authenticated-only existence signal on mutations, accepted).
   - A short "adjacent enumeration surfaces" note: `/api/entity-types`, `/api/openapi.json`, `/api/schema/{entity_type}` (BuiltinRouteRegistrar) remain option-less/anonymous and still enumerate type ids — out of #1649's scope, follow-up issue recommended (plan Risks).
2. `mcp-endpoint.md`:
   - `BearerTokenAuth` row/section (`:43`) + the authenticate step (`:87`): constant-time full-scan comparison; blocked-account fail-closed rejection via duck-typed `isActive()`; rejection indistinguishable from an unknown token (same 401 envelope); custom `McpAuthInterface` implementations own the liveness semantics of their own account objects; `getTokens()` fingerprinting contract unchanged.
3. `infrastructure.md`:
   - The `WAASEYAA_DB` env row (`:1637`) and `DatabaseBootstrapper` paragraph (`:1652`): the resolution matrix (precedence unchanged; relative → project-root; absolute/`:memory:`/drive-letter/UNC passthrough; climbing `../` relative-to-root), the CWD-independence invariant, the docroot warning predicate and its best-effort nature, and the CLI parity (`db:init` + display surfaces).

### T014 — Drift detector

```bash
tools/drift-detector.sh
```

Resolve any flags for the three touched specs; if the detector flags other specs touched by WP01/WP02 source changes (e.g. entity-system.md for the EntityType param), assess: the flag semantics are API-layer behavior — add a one-line cross-reference only where the detector demands it, otherwise record the rationale in the completion notes.

### T015 — Quickstart walkthrough

Execute `quickstart.md` steps 1–6 end-to-end against merged WP01+WP02 and record per-step results (pass/fail + the command output essentials) in this WP file's Activity Log / completion notes. Step 6 includes `composer verify` and `bin/check-package-layers`. Any failure → file it back to the owning WP; this WP does not patch code.

## Edge cases & risks

- **`[Unreleased]` collision**: alpha.205's entries may still sit there — append, never restructure or re-section the existing block; the release-cut workflow owns heading manipulation.
- **Doc drift from the diff instead of the contract**: the contracts are the reviewed behavior; if implemented code disagrees with a contract clause, that is a WP01/WP02 review failure to escalate, not a doc to silently bend.
- **Over-documenting**: do not add an UPGRADING.md section or migration guide — alpha-phase breaks live in the CHANGELOG (house convention; see the alpha.204 entries' style).

## Definition of Done

- [ ] CHANGELOG `[Unreleased]` covers all six entries above; the 403→404 break is the first Changed entry (C-001); no pre-stamped version heading anywhere in the diff.
- [ ] All three specs updated with dated review lines and contract-faithful body text; drift detector clean for the touched specs.
- [ ] Quickstart steps 1–6 executed and recorded; `composer verify` green at walkthrough time.
- [ ] No changes outside `owned_files`; no code changes.

## Reviewer guidance

- Read the CHANGELOG entries against the two contracts clause-by-clause — the C-001 entry must state byte-identity and the "clients keying on 403" sentence explicitly (the spec's words).
- Verify the api-layer.md "adjacent surfaces" note exists — it is the honesty boundary that keeps SC-001 from overclaiming.
- Confirm the specs document the *fallback rationale* (no categorical check exists) rather than implying per-account type filtering — future readers must not assume granularity the code doesn't have.
- Spot-check the walkthrough record: step 4 (SC-004) must show the no-stray-database evidence, step 3 the blocked-token 401.

## Activity Log

- 2026-06-12T00:00:00Z – spec-kitty.tasks – created
- 2026-06-12T07:23:48Z – claude:fable-5:implementer:implementer – shell_pid=21672 – Started implementation via action command
- 2026-06-12T07:34:20Z – claude:fable-5:implementer:implementer – shell_pid=21672 – Ready for review
- 2026-06-12T07:35:04Z – claude:fable-5:reviewer:reviewer – shell_pid=20092 – Started review via action command
- 2026-06-12T07:37:49Z – claude:fable-5:reviewer:reviewer – shell_pid=20092 – Review passed: diff scope exactly the 4 owned files (CHANGELOG +15/-0, prior entries byte-untouched); C-001 leads Changed with explicit clients-keying-on-403-must-adapt language, no version heading, #1649/#1650/#1652 present, adjacent-routes boundary stated; all 5 docs-vs-code spot checks verified against landed source (show() denied path/notFoundDocument factory, discover() visibility formula, BearerTokenAuth full-scan+(string) cast+duck-typed isActive, absolutize() matrix, BuiltinRouteRegistrar routes confirmed option-less); gates re-run green (phpstan, cs-check, check-dead-code) and api+mcp suites 626 tests/1889 assertions OK
- 2026-06-12T07:39:41Z – claude:fable-5:reviewer:reviewer – shell_pid=20092 – Done override: Mission squash-merged to main as 0f0a7bf2a

## Completion notes — T015 quickstart walkthrough + gates (2026-06-12, lane commit ca44e1b2f)

Executed in the lane worktree against merged WP01+WP02 (`79562e180` + `b8bdfee16`) with the WP03 docs commit on top. All PHPUnit runs via `php -d memory_limit=2G`; the only PHPUnit "warning" in every run is the benign no-code-coverage-driver notice.

| Quickstart step | Command(s) | Result |
|---|---|---|
| 1 — anonymous discovery reveals no type ids (SC-001) | `ApiDiscoveryControllerTest` (6 tests, 18 assertions) + `tests/Integration/Phase7/ApiDiscoveryIntegrationTest` (8 tests, 36 assertions) | **PASS** — anonymous→zero type links, authenticated→all discoverable types, `discoverable: false` absent for both, envelope constant, route shape `_public` unchanged |
| 2 — denied single read byte-identical to missing (SC-002, NFR-002) | `JsonApiControllerDeniedNotFoundTest` (3 tests, 14 assertions) | **PASS** — `json_encode` byte-equality of denied vs missing documents, equal 404 status, denied entity never serialized |
| 3 — bearer hardening (SC-003) | `packages/mcp/tests/Unit/Auth/` (13 tests, 16 assertions: 7-test pre-existing `BearerTokenAuthTest` matrix unchanged + `BearerTokenAuthHardeningTest`) | **PASS** — hash_equals full scan (match-first/match-last/numeric token), blocked `isActive(): false` → null indistinguishable from unknown token (the blocked-token 401 path), account without `isActive()` passes |
| 4 — relative WAASEYAA_DB resolves against project root (SC-004) | `DatabaseBootstrapperTest` (25 tests, 44 assertions) + `tests/Integration/DbPath/DbPathResolutionTest` (2 tests, 8 assertions) | **PASS** — HTTP-shaped boot (docroot CWD) and CLI-shaped boot resolve the same file, write-through-one-read-through-other, **no stray file materializes under the docroot** (the no-stray-database evidence); docroot-warning emission/non-emission via spy logger |
| 5 — unchanged-behavior pins | step-4 matrix (absolute/drive-letter/UNC/`:memory:`/default byte-identical, climbing `../` → project root) + `DbInitHandlerTest`/`HealthReportHandlerTest`/`AboutHandlerTest` (16 tests, 50 assertions) | **PASS** — `db:init` parity with kernel resolution; `health:report`/`about` display the resolved path |
| 6 — gates | see below | **PASS** (all) |

Targeted suites beyond the quickstart commands: `packages/api/tests/` 498 tests / 1501 assertions PASS; `packages/entity/tests/` 517 / 1112 PASS; `packages/mcp/tests/` 128 / 388 PASS; `packages/foundation/tests/Unit/Kernel/Bootstrap/` 38 / 66 PASS; `packages/validation/tests/` 52 / 100 PASS; `tests/Integration/Phase7/` 92 / 428 PASS.

Gates (step 6, run individually in place of the monolithic `composer verify` per Windows-advisory practice — Linux CI remains the authority):

- `composer phpstan` — OK, no errors
- `composer cs-check` — OK, 0 offending files
- `bin/check-dead-code` — OK, no new unused members beyond baseline
- `composer check-composer-policy` — OK
- `bin/check-package-layers` — OK, no new manifest edges (mission constraint held)

### T014 — drift detector record

`tools/drift-detector.sh` after the WP03 docs commit:

- **OK**: `docs/specs/api-layer.md`, `docs/specs/infrastructure.md`, `docs/specs/mcp-endpoint.md` — all three owned specs cleared.
- **STALE (remaining, not owned by WP03)**: `docs/specs/entity-system.md`, triggered solely by WP01's additive `packages/entity/src/EntityType.php` change (the `discoverable` ctor param + `isDiscoverable()`). Rationale for not editing it here: the flag's semantics are API-layer discovery behavior, fully documented in `api-layer.md` (visibility decision, duck-typed read, interface-not-widened note) and the CHANGELOG; `entity-system.md` is outside WP03's `owned_files`. The detector is timestamp-based, so a one-line cross-reference in `entity-system.md` ("EntityType `discoverable` flag — semantics owned by docs/specs/api-layer.md, #1649") committed on the lane or at merge would clear it; recommended as a reviewer/merge follow-up.

### Deviations

- CHANGELOG sectioning follows this WP file's six-entry layout (Changed ×3 / Added ×2 / Security ×1) — the db-path change is under **Changed** (it deliberately changes behavior for CWD-reliant deployments) and the bearer hardening under **Security**, not under "Fixed".
- New entries were inserted immediately after the `## [Unreleased]` heading, above the still-uncut alpha.205 provenance block, which was left byte-untouched (merge-cleanliness anchoring; the release-cut workflow owns heading manipulation). This yields temporarily duplicated Keep-a-Changelog section names under `[Unreleased]` until the next cut stamps them.
- `infrastructure.md` did not receive a Mission-2 `AbstractKernel::accountContext()` one-liner: the drift detector is timestamp-based and cleared without it; adding Mission-2 content was outside this mission's touch-only-what-you-own rule.
- Step 1–5 "by hand" dev-server probes were satisfied by the equivalent unit/integration pins named in the quickstart itself (each step's listed test commands), not by a live `php -S` session.

- 2026-06-12 – claude:fable-5:implementer:implementer – T012–T015 complete; lane commit ca44e1b2f; ready for review
