# Runtime-Adapter Scoping (audit "runtime adapter" architecture-debt item)

**Status:** SCOPE ONLY — no refactor performed. Awaiting a go/no-go decision.
**Date:** 2026-06-23
**Grounded against:** `main` @ the C-24 completion commit.

This is the deliberate "scope it and report before diving in" output for the
named architecture-debt item *runtime adapter* (FrankenPHP-in-`index.php`). It
describes the current shape, a target design, the risk map, the test strategy,
the BC surface, and a recommendation. It does **not** change any code.

---

## 1. What exists today (grounded)

The "runtime adapter" is the **front controller**, `public/index.php` (104
lines). One file boots the app correctly under three runtimes; only the
request-loop wrapper differs:

1. **FrankenPHP worker mode** (production: local demo, Web Networks, Waaseyaa
   Cloud) — boots the `$handler` once, then loops on
   `frankenphp_handle_request($handler)` so the app stays warm and requests are
   served concurrently across threads (a long-lived `/api/broadcast` SSE stream
   pins one thread). Launched by the **native** `frankenphp run --config
   config/frankenphp/Caddyfile` (the Caddyfile's worker block sets
   `WAASEYAA_FRANKENPHP_WORKER=1` for `public/index.php`).
2. **FrankenPHP / FPM classic** — one request per invocation.
3. **`php -S` (cli-server)** — one request per invocation with static-file
   passthrough; this is what `waaseyaa serve` runs (single-worker dev only).

The runtime-specific logic embedded in the file:

- a `cli-server` static-file `return false` passthrough;
- autoload + `Dotenv::loadEnv(...)` (APP_ENV defaults to `production`);
- the `$handler` closure: **fresh `HttpKernel` per request** (no container/entity
  state bleeds across requests in a long-lived worker) + a **debug-gated**
  boot-failure JSON:API 500 (never leaks `$e->getMessage()` outside `APP_DEBUG`);
- the worker loop: exact `WAASEYAA_FRANKENPHP_WORKER=1` selection,
  fail-closed verification that `frankenphp_handle_request()` exists,
  `ignore_user_abort(true)`, an optional `FRANKENPHP_WORKER_MAX_REQUESTS`
  recycle bound, and `gc_collect_cycles()` each turn. Classic FrankenPHP also
  exposes the worker function and may throw or return `false` outside worker
  mode, so the front controller never calls it without the explicit marker.

### The byte-locked copies (the actual debt)

This file is duplicated **four times** and pinned byte-identical by
`tests/Architecture/FrontControllerRuntimeDispatchTest.php`:

| Copy | Path | Role |
|------|------|------|
| repo dev | `public/index.php` | the framework repo's own front controller |
| make:public stub | `packages/cli/templates/public/index.php.stub` | emitted verbatim by `waaseyaa make:public` (`MakePublicHandler`) |
| skeleton | `skeleton/public/index.php` | the scaffolded app's front controller |
| golden | `skeleton/bin/maintenance/golden-public-index.php` | drift reference the test compares against |

The only intended divergence: the repo-dev + stub copies **inline** the
APP_DEBUG boot-failure gate (no `App\` namespace), while skeleton/golden delegate
to `App\Http\BootFailureResponder`. `FrontControllerRuntimeDispatchTest` enforces
both the byte-identity and the "no copy leaks the raw boot message" invariant
(the latter was a real audit-Medium residual, fixed in #1755).

**The debt:** runtime-serving logic lives in application space, copied four ways,
so every change to the worker loop / boot-failure handling / runtime detection
must be made in lockstep across four files and re-pinned by an architecture test.

### What `waaseyaa/frankenphp` is today

`packages/frankenphp` is **console commands + binary management only**
(`DevCommand`, `InstallCommand`, `BinaryResolver`, `Installer`). Its README is
explicit: *"It adds two console commands and nothing else; the framework core
stays runtime-agnostic (no FrankenPHP coupling)."* It contains **no**
request-serving / worker-loop code. It is **optional** — not part of
`waaseyaa/core` / `cms` / `full`; apps opt in with `composer require
waaseyaa/frankenphp`. The runtime-agnostic `waaseyaa serve` (`php -S`) is the
zero-dependency fallback in core.

---

## 2. The central design tension (why this is not a trivial move)

The obvious target — *"move the worker loop into `waaseyaa/frankenphp`"* —
collides with two existing, deliberate constraints:

1. **The package is optional.** The php-S / FPM fallback **must** keep working
   for apps that never install `waaseyaa/frankenphp`. So the serving entry point
   cannot hard-depend on a class from that package.
2. **Core is runtime-agnostic by charter.** Coupling the front controller to a
   FrankenPHP-specific class reverses the README's stated invariant.

So any adapter must keep a **runtime-agnostic core serving path** that works with
zero optional packages, and treat FrankenPHP worker mode as an **opt-in
enhancement** that activates only when the symbol/package is present. The
current single-file design already achieves runtime-agnosticism — its cost is
the four byte-locked copies, not a coupling.

---

## 3. Target design (proposed, not built)

**Goal:** reduce each `public/index.php` to a thin, rarely-changing bootstrap and
move the runtime-specific serving logic into one framework-owned, unit-testable
class — without coupling the bootstrap to the optional FrankenPHP package and
without breaking the php-S/FPM fallback.

Proposed shape (Symfony-Runtime / Laravel-Octane-style, adapted):

- A **core** `RuntimeServer` (in `waaseyaa/foundation`, runtime-agnostic) that
  owns: the per-request fresh-kernel `$handler`, the debug-gated boot-failure
  responder, and a `serveOnce()` path for classic FPM / `php -S`. This is the
  zero-dependency default — no optional package required.
- A **runtime selector** that uses the worker-process marker to select the
  worker-loop strategy (including `ignore_user_abort` and the recycle bound),
  then fails closed if `frankenphp_handle_request` is absent; every unmarked
  runtime uses `serveOnce()`. The worker strategy is the *only* FrankenPHP-aware
  code; it can live in foundation guarded purely by `function_exists()` (no
  dependency on `waaseyaa/frankenphp`), OR — cleaner separation — in
  `waaseyaa/frankenphp` as a strategy the detector loads **only if class_exists**,
  preserving the optional-package contract.
- `public/index.php` shrinks to roughly: `require autoload; (new RuntimeServer(
  $projectRoot))->run();` — a handful of lines that essentially never change, so
  the byte-locked duplication stops mattering (the stub/skeleton/golden become
  trivial and stable).
- The `App\Http\BootFailureResponder` seam stays (apps can still override the
  500 body), injected into `RuntimeServer`.

**Recommended variant:** keep the worker-loop strategy in **foundation**, gated
by the exact worker-process marker and guarded by
`function_exists('frankenphp_handle_request')`. Rationale: it is ~25 lines, has
no FrankenPHP *code* dependency (only the runtime-injected global
function), and keeping it in core avoids a foundation→optional-package indirection
while still leaving `waaseyaa/frankenphp` as binary-management-only. This holds
the runtime-agnostic invariant (the code is inert when the symbol is absent) and
collapses the four-copy duplication to a one-line bootstrap.

---

## 4. Risk map

| Risk | Severity | Notes |
|------|----------|-------|
| Worker-loop semantics regressions | **High** | Explicit marker custody, `ignore_user_abort`, fresh-kernel-per-request, and the recycle bound are load-bearing for production concurrency + the SSE stream. A missing marker strands the worker; a marker in classic mode suppresses the synchronous path. The native hosted lane covers both modes. |
| Boot-failure leak re-introduction | **High** | The debug-gate is a fixed audit-Medium (#1755). Any refactor must preserve "never emit `$e->getMessage()` outside APP_DEBUG" across **every** entry path, and keep `FrontControllerRuntimeDispatchTest`'s no-leak invariant green. |
| Breaking the php-S / FPM fallback | **Med-High** | The zero-dependency path must keep working for apps without `waaseyaa/frankenphp`. Easy to regress if the bootstrap accidentally references an optional symbol. |
| Scaffolding drift (`make:public`, skeleton, golden) | **Med** | Four copies + the architecture test must move together; the stub generator (`MakePublicHandler`) emits the new thin bootstrap; the golden/byte-identity test must be re-pinned to the new content. |
| `Dotenv` / APP_ENV default behavior | **Med** | The "default APP_ENV to production, not Symfony dev" subtlety must survive the move. |
| Existing-app upgrade churn | **Med** | Apps already scaffolded carry the old fat `index.php`; they keep working (no forced change), but they won't benefit until they re-scaffold or hand-edit. Document as opt-in. |

---

## 5. Test strategy

- **Unit:** `RuntimeServerTest` — classic `serveOnce()` returns the handler's
  response; boot-failure produces a debug-gated 500 (production hides the
  message, debug shows it); the recycle bound stops after N; the worker-detection
  branch is selected when the global function is present (inject a fake).
- **Runtime selection:** contract-test that every front controller requires the
  exact worker marker, fails closed when a selected worker lacks its API, and
  retains the synchronous path for every unmarked runtime.
- **Architecture:** keep `FrontControllerRuntimeDispatchTest` — re-pin the
  byte-identity to the new thin bootstrap, keep the no-raw-boot-leak invariant
  across all four copies.
- **Hosted worker lane (#2494):** ordinary CI job `ci/frankenphp-worker` now
  installs pinned FrankenPHP and runs `scripts/acceptance-frankenphp-worker.sh`
  against `public/index.php` in real worker mode (sequential + concurrent
  requests, SSE, shutdown). PHPUnit still owns the worker-loop heuristic and
  four-copy architecture pin; this audit's earlier "CI has no FrankenPHP worker"
  statement is obsolete.

---

## 6. BC surface

- **Public API:** `RuntimeServer` (+ any strategy interface) would be **new
  public/`@api`** surface in `waaseyaa/foundation` → surface-map + CHANGELOG
  `### Added`. `App\Http\BootFailureResponder` seam unchanged.
- **Scaffolding:** the `make:public` stub, skeleton, and golden change to the
  thin bootstrap (a generated-output change, not a runtime BC break). Already-
  scaffolded apps are unaffected until they re-scaffold.
- **Drift gate:** `packages/frankenphp/` maps to `operations-playbooks.md`;
  `packages/foundation/` maps to `infrastructure.md` — both specs would need
  the runtime-adapter section + `spec-reviewed:` notes.
- **No runtime BC break** for running apps (the fat `index.php` keeps working).

---

## 7. Recommendation

**Defer — low urgency, real (but bounded) risk; not a blocker.**

- The **four-copy duplication** remains architectural debt, not a reason to
  extract the adapter immediately. The classic empty-response defect is fixed
  independently by explicit runtime selection; extraction would now be a
  structural simplification rather than remediation.
- The highest-risk surface (worker-loop semantics, boot-leak gate) is **load-
  bearing for production**. #2494 added a real-worker HTTP lane; a refactor
  still trades a tidier seam for production-regression risk around explicit
  marker custody and the worker loop, both covered by Architecture and native
  worker/classic acceptance.
- If undertaken, do it as a **single, self-contained mission** with the test
  strategy above landed *first*, the recommended "worker strategy stays in
  foundation, explicit-marker-selected and API-guarded" variant, and a real
  out-of-CI FrankenPHP smoke check before merge.

**Suggested trigger to revisit:** when the worker loop next needs a *behavioral*
change anyway (e.g. a new recycle policy, graceful-drain on SSE, or a second
concurrent runtime), fold this refactor into that work so the duplication cost
and the change land together rather than paying the regression risk for a
pure-tidy pass.
