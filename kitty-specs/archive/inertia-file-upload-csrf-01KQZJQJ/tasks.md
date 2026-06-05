# Work Packages: CSRF for Inertia File Uploads

**Mission ID**: `01KQZJQJV8XMG9C1PF7TVMKKHE` (mid8: `01KQZJQJ`)
**Mission Slug**: `inertia-file-upload-csrf-01KQZJQJ`
**Spec**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Contract**: [contracts/csrf-token-cookie.md](./contracts/csrf-token-cookie.md)
**Branch contract**: current `main` → planning base `main` → merge target `main` (matches: yes)
**Generated**: 2026-05-06T21:45:49Z

---

## Overview

Four work packages. WP01 is the foundation (framework code + unit tests). WP02 and WP03 can parallelize after WP01 lands. WP04 is the **hard cross-repo acceptance gate** and runs last.

Implementer for WP01–WP04: **Sonnet**. Reviewer: **Opus**.

| WP | Title | Subtasks | Depends on | Lane affinity | Est. prompt size |
|---|---|---|---|---|---|
| WP01 | CSRF middleware: accept X-XSRF-TOKEN + write XSRF-TOKEN cookie | 7 | — | A | ~450 lines |
| WP02 | Integration test through HttpKernel (Inertia multipart) | 6 | WP01 | A or B (post-WP01) | ~350 lines |
| WP03 | Documentation: convention page + security-defaults pointer | 3 | WP01 (logical, not strict) | B | ~200 lines |
| WP04 | Cross-repo Giiken Ingestion smoke (HARD GATE) | 7 | WP01, WP02 | A (post-WP02) | ~400 lines |

**MVP scope**: WP01 alone closes the framework gap; WP02 pins it; WP03 documents it; WP04 verifies it actually reaches the consumer surface. The mission cannot be marked done without WP04.

---

## Subtask Index (reference table — not a tracking surface)

The `[P]` marker indicates the subtask is parallel-safe across files/concerns within its WP, **not** task status.

| ID | Description | WP | Parallel |
|---|---|---|---|
| T001 | Read `HttpKernel.php` + existing `CsrfMiddleware` response path; pick "extend `CsrfMiddleware`" vs. "new `XsrfCookieMiddleware`" shape; document chosen shape | WP01 | | [D] |
| T002 | Implement the cookie writer per `contracts/csrf-token-cookie.md` §1 (XSRF-TOKEN, urlencoded value, HttpOnly=false, Secure=scheme-tracking, SameSite=Lax, Path=/, no Domain, session lifetime) | WP01 | | [D] |
| T003 | Restrict cookie write to `text/html` primary Content-Type; skip JSON/non-HTML; ensure idempotency | WP01 | [P] with T002 if separate middleware | [D] |
| T004 | Extend `CsrfMiddleware` request-side to accept `X-XSRF-TOKEN` header (URL-decoded before `hash_equals`) alongside existing `_csrf_token` field and `X-CSRF-Token` header | WP01 | [P] with T002–T003 if separate middleware | [D] |
| T005 | Unit tests in `CsrfMiddlewareTest.php` covering full Content-Type × token-source matrix from contract §2 (json exempt, `_csrf_token` field, `X-CSRF-Token` header, `X-XSRF-TOKEN` header URL-decoded, multipart variants, regression cases) | WP01 | [P] with T006 | [D] |
| T006 | Unit tests for cookie writer (new `XsrfCookieMiddlewareTest.php` if separate middleware, else folded into `CsrfMiddlewareTest.php`) pinning every attribute from contract §1; cookie absent on JSON responses; idempotency | WP01 | [P] with T005 | [D] |
| T007 | `composer test` + `composer analyse` green; zero new warnings (NFR-004) | WP01 | | [D] |
| T008 | Create `tests/Integration/Phase13/InertiaMultipartCsrfIntegrationTest.php` following the `SsrHttpKernelIntegrationTest` pattern (boot full `HttpKernel`, dispatch through full middleware stack) | WP02 | | [D] |
| T009 | Integration case 1 — GET to a CSRF-protected route, assert response carries `Set-Cookie: XSRF-TOKEN=...` with the contract's attributes | WP02 | | [D] |
| T010 | Integration case 2 — extract cookie from GET, POST `multipart/form-data` with `X-XSRF-TOKEN: <urldecode(cookie)>`, assert 200/302 | WP02 | | [D] |
| T011 | Integration case 3 (negative) — same multipart POST without `X-XSRF-TOKEN`, assert 403 | WP02 | [P] with T010 | [D] |
| T012 | Integration case 4 (regression) — `application/json` POST with no token, assert exempt path still works | WP02 | [P] with T010, T011 | [D] |
| T013 | Run `vendor/bin/phpunit tests/Integration/Phase13/InertiaMultipartCsrfIntegrationTest.php` green | WP02 | | [D] |
| T014 | Create `docs/conventions/csrf-token-cookie.md` with three audiences (Inertia zero-code, vanilla `fetch` with `document.cookie`, server-rendered Twig with `csrf_token()` helper); include contract summary tables | WP03 | | [D] |
| T015 | Update `docs/specs/security-defaults.md` with a one-line pointer to the new convention page | WP03 | [P] with T014 | [D] |
| T016 | Verify docs build / lint (if applicable per Taskfile); zero new warnings | WP03 | | [D] |
| T017 | In `/home/jones/dev/giiken`: `cp composer.json composer.json.smoke-backup`; add path repository for `/home/jones/dev/waaseyaa/packages/*` (symlink); `composer update 'waaseyaa/*'` | WP04 | | [D] |
| T018 | Verify symlink resolution (`vendor/waaseyaa/user` points to local source); confirm framework SHA in use | WP04 | | [D] |
| T019 | `./vendor/bin/waaseyaa migrate` (idempotent); `./vendor/bin/waaseyaa serve`; log in as seeded admin/staff; navigate to Pilot Nation A Anishnawbek community Ingestion page | WP04 | | [D] |
| T020 | Upload real test file (.md or .csv); capture (a) browser screenshot → `artifacts/giiken-smoke-<utc>.png`, (b) network capture (DevTools "copy as cURL" or `curl -v`) showing `X-XSRF-TOKEN` header and 200/302 → `artifacts/giiken-smoke-<utc>-network.txt`, (c) server log excerpt showing `knowledge_item` creation → `artifacts/giiken-smoke-<utc>-server.log` | WP04 | | [D] |
| T021 | Write `artifacts/giiken-smoke-<utc>.md` summary (framework SHA, giiken SHA, test file used, outcome, links to evidence) | WP04 | | [D] |
| T022 | Revert giiken `composer.json` (`mv composer.json.smoke-backup composer.json && composer update 'waaseyaa/*'`); verify giiken `git status` clean | WP04 | | [D] |
| T023 | Final acceptance check — all four evidence files present; `knowledge_item` row created; `X-XSRF-TOKEN` was forwarded automatically (no giiken JS code touched); giiken tree clean | WP04 | | [D] |

Total: **23 subtasks across 4 WPs.** All within the 3–10 subtasks/WP target.

---

## WP01 — CSRF middleware: accept X-XSRF-TOKEN + write XSRF-TOKEN cookie

**Goal**: Land the framework-side change. Both request-side (accept new header) and response-side (write cookie) implementation, with full unit-test coverage of the contract.

**Priority**: P0 (foundation for everything else).

**Independent test**: `vendor/bin/phpunit packages/user` green; `composer analyse` clean. No regression in existing CSRF tests.

**Implementer must decide first**: Read `packages/foundation/src/Kernel/HttpKernel.php` to confirm middleware contract (onion vs. sequential). Then choose:
- (a) Extend `CsrfMiddleware` to write the cookie on its response path, or
- (b) Introduce a separate `XsrfCookieMiddleware` adjacent to `CsrfMiddleware`.

Document the decision in the WP commit message body. Either shape is acceptable; both yield identical observable behavior. Prefer (a) if `CsrfMiddleware` already has clean response-side hooks; prefer (b) if response-side modification would require restructuring `CsrfMiddleware`.

**Included subtasks**:

- [x] T001 Read HttpKernel.php + existing CsrfMiddleware response path; decide middleware shape; document choice (WP01)
- [x] T002 Implement cookie writer per contract §1 attributes (WP01)
- [x] T003 Restrict cookie write to text/html responses; idempotency (WP01)
- [x] T004 Extend CsrfMiddleware request-side to accept X-XSRF-TOKEN header (URL-decoded) (WP01)
- [x] T005 Unit tests for full Content-Type × token-source matrix (WP01)
- [x] T006 Unit tests for cookie writer pinning every contract §1 attribute (WP01)
- [x] T007 composer test + composer analyse green; NFR-004 zero new warnings (WP01)

**Implementation sketch**:

1. **Read pass** (T001): `packages/foundation/src/Kernel/HttpKernel.php` (handle method + middleware pipeline construction), `packages/user/src/Middleware/CsrfMiddleware.php` (full file). Look for: how does middleware return a response, how can a response be modified after `$next($request)` returns. Pick shape; write decision into WP commit body (1 paragraph).
2. **Cookie writer** (T002, T003): Use Symfony's `Response->headers->setCookie(Cookie::create(...))`. Cookie name `XSRF-TOKEN`, value `urlencode($_SESSION['_csrf_token'])`, attributes from contract. Content-Type check: parse the response Content-Type primary type case-insensitively, only write cookie if `text/html`.
3. **Request-side header** (T004): In `CsrfMiddleware`, where the existing `X-CSRF-Token` header is read, add a sibling read for `X-XSRF-TOKEN` with `urldecode()` before `hash_equals` comparison. Order: `_csrf_token` field → `X-CSRF-Token` header → `X-XSRF-TOKEN` header (URL-decoded). First match wins via `hash_equals`.
4. **Tests** (T005, T006): The CsrfMiddlewareTest matrix MUST cover all 9 contract §2 cases. Cookie writer tests MUST pin every attribute in contract §1 separately (one test per row of the attributes table) plus idempotency and no-cookie-on-JSON.
5. **Validation** (T007): `composer test`, `composer analyse`. If either reports new warnings or failures, fix before marking WP done.

**Owned files** (no overlap with other WPs):
- `packages/user/src/Middleware/CsrfMiddleware.php`
- `packages/user/src/Middleware/XsrfCookieMiddleware.php` (only if shape (b) chosen)
- `packages/user/tests/Unit/Middleware/CsrfMiddlewareTest.php`
- `packages/user/tests/Unit/Middleware/XsrfCookieMiddlewareTest.php` (only if shape (b) chosen)

**Authoritative surface**: `packages/user/src/Middleware/`

**Requirement refs**: FR-001, FR-002, FR-003, FR-004, FR-005, FR-006, FR-008, NFR-001, NFR-002, NFR-004, C-001, C-002, C-003, C-004

**Risks**:
- Wrong middleware-contract assumption (onion vs. sequential) → caught at T001 read pass.
- URL-encoding double-decode on the request side → pinned by T005 test asserting URL-encoded cookie + URL-decoded header comparison.
- Cookie set on JSON responses → pinned by T006.

**Reviewer guidance** (Opus):
- Verify `hash_equals` is used for the new comparison path (timing attack).
- Verify `urldecode` happens exactly once on the header value before comparison.
- Verify cookie writer writes nothing on non-HTML responses.
- Verify zero changes to the `application/json` exemption logic.
- Verify zero new composer dependencies.

---

## WP02 — Integration test through HttpKernel (Inertia multipart)

**Goal**: End-to-end test that boots the full `HttpKernel` and proves the contract holds across the full middleware stack with a real multipart submission.

**Priority**: P1 (required for NFR-003).

**Independent test**: `vendor/bin/phpunit tests/Integration/Phase13/InertiaMultipartCsrfIntegrationTest.php` green.

**Depends on**: WP01.

**Included subtasks**:

- [x] T008 Create InertiaMultipartCsrfIntegrationTest.php scaffolded from SsrHttpKernelIntegrationTest pattern (WP02)
- [x] T009 Integration case 1 — GET asserts Set-Cookie: XSRF-TOKEN=... (WP02)
- [x] T010 Integration case 2 — multipart POST with X-XSRF-TOKEN asserts 200/302 (WP02)
- [x] T011 Integration case 3 (negative) — multipart POST without header asserts 403 (WP02)
- [x] T012 Integration case 4 (regression) — application/json POST without token asserts exempt path still works (WP02)
- [x] T013 Run integration suite green (WP02)

**Implementation sketch**:

1. **Scaffold** (T008): Read `tests/Integration/Phase13/SsrHttpKernelIntegrationTest.php` for the pattern (temp filesystem mount, kernel boot, request dispatch). Create the new test file alongside it.
2. **Cases 1–4** (T009–T012): Use one shared kernel boot for all four cases. Extract the cookie value from case 1's response and reuse it (URL-decoded) in case 2's `X-XSRF-TOKEN` header.
3. **Run** (T013): Single phpunit invocation; ensure deterministic ordering.

**Owned files**:
- `tests/Integration/Phase13/InertiaMultipartCsrfIntegrationTest.php`

**Authoritative surface**: `tests/Integration/Phase13/`

**Requirement refs**: FR-002, FR-005, FR-008, NFR-003

**Risks**:
- Test relies on a CSRF-protected route in the kernel boot fixture. If none exists, define one minimal one inside the test setup.
- URL-encoding of the cookie value in the test assertion. Pin both forms (encoded in cookie, decoded in header).

**Reviewer guidance** (Opus):
- Verify the four cases are independent (no order-dependent state).
- Verify the test does NOT mock `CsrfMiddleware` or the cookie writer (must run them for real).
- Verify the negative case asserts 403 specifically, not just "non-2xx".

---

## WP03 — Documentation: convention page + security-defaults pointer

**Goal**: Explain the convention to consumer-app developers (FR-007, SC-3). Three audiences, one page.

**Priority**: P1 (required for SC-3, FR-007).

**Independent test**: Manual read-through of the new page; existing docs lint clean.

**Depends on**: WP01 (logically — content reflects the implementation), but does not need WP02. Can run in parallel with WP02 once WP01 lands.

**Included subtasks**:

- [x] T014 Create docs/conventions/csrf-token-cookie.md (Inertia, fetch, Twig examples) (WP03)
- [x] T015 Add one-line pointer to convention page from docs/specs/security-defaults.md (WP03)
- [x] T016 Verify docs build / lint (if applicable) (WP03)

**Implementation sketch**:

1. **Create page** (T014): Mirror the structure in `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/quickstart.md` §2–§4. Three runnable code blocks (Vue/Inertia, vanilla fetch, Twig) plus the contract summary tables (cookie attributes, accepted-source list).
2. **Pointer** (T015): Add a section or one-line reference to `docs/specs/security-defaults.md` linking to the new page from the CSRF discussion.
3. **Verify** (T016): Run any docs-related task from `Taskfile.yml` (e.g., `task docs:lint` if present); if no docs CI exists, manual proofread is sufficient.

**Owned files**:
- `docs/conventions/csrf-token-cookie.md`
- `docs/specs/security-defaults.md`

**Authoritative surface**: `docs/`

**Requirement refs**: FR-007

**Risks**:
- Documentation drift from contract. Mitigation: copy the contract tables directly; reference the contract file by path in the convention page footer.

**Reviewer guidance** (Opus):
- Verify the three examples are runnable (not pseudocode).
- Verify the convention page links back to the spec/contract for authoritative behavior.
- Verify no implementation-detail leaks that would rot fast.

---

## WP04 — Cross-repo Giiken Ingestion smoke (HARD ACCEPTANCE GATE)

**Goal**: Prove the framework change actually reaches the consumer surface — a real multipart upload through the giiken Ingestion UI succeeds with zero giiken-side code changes (other than the verification-only path-repo composer config that gets reverted).

**Priority**: P0-gate. **The mission cannot be marked done until this WP completes with all four evidence files in `artifacts/`.**

**Independent test**: Successful real upload + four evidence files captured + giiken tree clean after revert.

**Depends on**: WP01 (mandatory — the framework change must exist), WP02 (recommended — integration test should pass before running the real smoke). Can technically run after WP01 alone, but reviewer expects WP02 green first.

**Included subtasks**:

- [x] T017 Backup giiken composer.json; add path repository for waaseyaa monorepo (symlink); composer update waaseyaa/* (WP04)
- [x] T018 Verify symlink resolution; confirm framework SHA in use (WP04)
- [x] T019 waaseyaa migrate + serve giiken; log in; navigate to Ingestion page (WP04)
- [x] T020 Upload real test file; capture screenshot + network trace + server log (WP04)
- [x] T021 Write artifacts/giiken-smoke-<utc>.md summary (WP04)
- [x] T022 Revert giiken composer.json; verify clean tree (WP04)
- [x] T023 Final acceptance check (WP04)

**Implementation sketch**: See `quickstart.md §5` for the full procedure. Implementer follows it step-by-step. Evidence file naming is `<utc>` formatted as `YYYYMMDDTHHMMSSZ` for sortability.

**Owned files** (in waaseyaa monorepo only — giiken-side modifications are temporary and reverted):
- `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/giiken-smoke-<utc>.png`
- `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/giiken-smoke-<utc>-network.txt`
- `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/giiken-smoke-<utc>-server.log`
- `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/giiken-smoke-<utc>.md`

**Authoritative surface**: `kitty-specs/inertia-file-upload-csrf-01KQZJQJ/artifacts/`

**Requirement refs**: NFR-005, C-001, C-005

**Risks**:
- Path-repo symlink doesn't pick up local changes. Mitigation: T018 verifies the symlink resolves and shows the local SHA.
- Forgetting to revert giiken composer.json. Mitigation: T022 has explicit `git status` clean check.
- Smoke succeeds but evidence files are missing. Mitigation: T023 final acceptance enumerates all four required artifacts.
- Token rotation race during smoke (login rotates the token mid-session). Mitigation: smoke flow logs in first, then performs the upload — token is stable for the upload step.

**Reviewer guidance** (Opus):
- Verify all four evidence files exist in `artifacts/` and are non-empty.
- Verify the network trace shows `X-XSRF-TOKEN` request header (not the legacy `X-CSRF-Token`) — this proves the new path is what made it work.
- Verify the summary doc lists the framework SHA and confirms it includes the WP01 commit.
- Verify giiken's tree is clean after revert (no leftover `composer.json.smoke-backup`, no path-repo entries).

---

## Requirement coverage

| Requirement | WPs | Covered? |
|---|---|---|
| FR-001 | WP01 | ✅ |
| FR-002 | WP01, WP02 | ✅ |
| FR-003 | WP01 | ✅ |
| FR-004 | WP01 | ✅ |
| FR-005 | WP01, WP02 | ✅ |
| FR-006 | WP01 | ✅ |
| FR-007 | WP03 | ✅ |
| FR-008 | WP01, WP02 | ✅ |
| NFR-001 | WP01 | ✅ |
| NFR-002 | WP01 | ✅ |
| NFR-003 | WP02 | ✅ |
| NFR-004 | WP01 | ✅ |
| NFR-005 | WP04 | ✅ |
| C-001 | WP01, WP04 | ✅ |
| C-002 | WP01 | ✅ |
| C-003 | WP01 | ✅ |
| C-004 | WP01 | ✅ |
| C-005 | WP04 | ✅ |
| C-006 | (branch contract — implicit in all WPs) | ✅ |
| C-007 | (mechanism deferral resolved at plan time) | ✅ |

All FRs, NFRs, and constraints have at least one WP carrying them.

---

## Lane planning hint (for finalize-tasks)

- **Lane A**: WP01 → WP02 → WP04 (sequential, framework code → integration test → cross-repo smoke).
- **Lane B**: WP03 (docs, can run in parallel with WP02 once WP01 is green).

`finalize-tasks` computes the canonical `lanes.json`. The above is intent, not prescriptive.

---

## Branch contract (final restatement)

- Current branch at tasks generation: `main`
- Planning/base branch: `main`
- Final merge target: `main`
- `branch_matches_target`: **true**

Worktrees are created per lane during `/spec-kitty.implement` (one worktree per lane, per spec-kitty 0.11+ contract).

---

## Stopping point

This file is the WP outline + manifest. Per user instruction, the next-phase commands are:

1. `/spec-kitty.tasks-packages` — materialize per-WP prompt files into `tasks/WPxx-slug.md` (flat directory).
2. `/spec-kitty.tasks-finalize` — parse dependencies, register requirement refs, validate, commit, generate `lanes.json`.

Then `/spec-kitty.implement` to dispatch the implementer (Sonnet) and reviewer (Opus) per WP.
