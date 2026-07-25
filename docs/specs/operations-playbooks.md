# Operations Playbooks

<!-- Spec reviewed 2026-06-21 - issue #1707 `waaseyaa dev` port preflight (packages/frankenphp): before printing "Serving …" and exec'ing FrankenPHP, the `dev` command now connect-probes the resolved listen address (DevCommand.php). A connect probe — Windows SO_REUSEADDR-safe, unlike a test bind — detects an already-bound address (e.g. an orphaned prior dev server) and the command fails fast with one actionable line plus a port-release hint, instead of printing "Serving" then exiting silently and leaving the browser at ERR_CONNECTION_REFUSED. The probe is injectable; covered by DevCommandPortPreflightTest. No other dev/install behavior changed. -->
<!-- Spec reviewed 2026-07-13 - CW-v1 option-1 PR-7 (#1920, design §8): Playbook H step 5 rewritten from
     "Do NOT bind in production" to the real production binding procedure — precondition (steps 1-4 unchanged),
     grant transition permissions (incl. `revise`, plus the option-1 same-state-republish permission rules for
     published/archived content edits), add the binding, a post-bind HTTP verification probe (real, confirmed
     JSON:API/workflow-transition routes), and a rollback procedure (verified against loadWorkingCopy()'s actual
     binding-agnostic implementation). The "Evaluation-binding caveat" is removed, replaced by a pointer at the
     PR-4 write-side allowlist that structurally closed it. Failure mode 1 gains an option-1 amendment (discipline
     only engages once bound AND pointered); failure mode 2 gains the two-axis (translatable+revisionable)
     hard-throw case. Scope note and title updated to reflect that production binding is no longer deferred. -->
## Purpose

This document consolidates operational workflows introduced across v1.0-v1.2:

- stable MCP/SSR/semantic/workflow contracts (v1.0),
- performance and cache hardening operations (v1.1),
- developer tooling and diagnostics workflows (v1.2).

Use this as the default runbook for upgrades, baseline refreshes, and verification gates.

## Contract Surface Reference

### MCP

- `tools/call` payload meta remains stable with:
  - `contract_version`
  - `contract_stability`
  - `tool`
  - `tool_invoked`
- `search_teachings` remains a supported legacy alias of `search_entities`.
- `tools/introspect` provides deterministic diagnostics for:
  - contract metadata,
  - cache context and scope,
  - visibility policy hints,
  - permission boundaries,
  - execution path and failure-mode hints.

### Workflow and Visibility

- Editorial lifecycle states remain: `draft`, `review`, `published`, `archived`.
- Public read paths must enforce workflow visibility semantics.
- Relationship traversal surfaces must remain source-visibility aware.

### Performance Baselines

- Versioned baseline artifacts are generated with `perf:baseline`.
- Drift detection is performed with `perf:compare`.
- Regression snapshots are tracked under `tests/Baselines/`.

### Local Development Process Model

`composer dev`, `composer dev:php`, and `composer dev:admin` are separate, typed entry points — each runs exactly one long-lived process in the foreground. The legacy single-script `composer dev` couples the PHP server and the admin SPA via a backgrounded shell fork-and-kill one-liner; that pattern is brittle (orphaned PHP processes when the SPA dies, no clean shutdown, shell-expansion variability across `bash`/`zsh`) and has been replaced by the typed split:

| Script | Process | Purpose |
|--------|---------|---------|
| `composer dev:php` | `bin/waaseyaa serve` | PHP built-in server. `bin/waaseyaa serve` now defaults `PHP_CLI_SERVER_WORKERS` to `4` itself (set the env var to override) — no reliance on the composer/shell wrapper. Single foreground process, no shell forking. |
| `composer dev:admin` | `bin/waaseyaa admin:dev` | Nuxt admin SPA dev server. Reads `NUXT_BACKEND_URL` (defaults to `http://127.0.0.1:${APP_PORT:-8080}`). |
| `composer dev` | delegates to `dev:php` | Convenience alias for the most common case (PHP-only). |

For full-stack local development, run `composer dev:php` in one terminal and `composer dev:admin` in another. Each process owns its own lifecycle; killing one does not orphan the other. CI and Docker compose files invoke the typed entries directly rather than the legacy shell pipeline.

**The admin SPA's realtime SSE and worker usage.** The admin SPA holds a long-lived Server-Sent-Events connection to `/api/broadcast` for live updates (`packages/admin/app/composables/useRealtime.ts` → `packages/foundation/src/Http/Router/BroadcastRouter.php`). The `BroadcastRouter` loop is **bounded** (see `docs/specs/broadcasting.md`): it returns on client disconnect or after a per-connection time budget (`DEFAULT_MAX_DURATION_SEC`, 30s), so a worker is never pinned indefinitely and the client's `EventSource` reconnects automatically. A short keepalive cadence (2s) makes disconnect detection prompt so the worker is released soon after a tab navigates away. Even so, each *concurrently open* admin tab uses one worker for the duration of its stream, so the server still needs **>1 worker**. PHP's built-in server is single-worker by default, so `bin/waaseyaa serve` defaults `PHP_CLI_SERVER_WORKERS=4`.

### Two runtimes, two launchers

Waaseyaa is runtime-agnostic and **never wraps a runtime binary in a framework subcommand** — the Symfony Runtime / Laravel Octane / Drupal-DDEV convention. There are exactly two supported ways to serve the app, and the choice mirrors that convention:

| Runtime | How it is launched | Use for |
|---|---|---|
| `php -S` (single worker) | `vendor/bin/waaseyaa serve` | Zero-config local dev convenience. **Not** for production; **not** for the admin SPA's concurrent SSE. |
| FrankenPHP (worker mode, concurrent) | the **native** `frankenphp` command + a committed config file | Admin-SPA-heavy local dev and production. |

`waaseyaa serve` is the plain single-worker `php -S` dev server. PHP's built-in server is single-worker by default, so a long-lived `/api/broadcast` SSE stream would pin the sole worker; `serve` defaults `PHP_CLI_SERVER_WORKERS` to `4` to soften that on POSIX, but the knob is `fork()`-gated and **ignored on Windows**. It is a convenience, not a concurrent runtime.

**Concurrent runtime: launch FrankenPHP natively.** The runtime adapter lives in the front controller (`public/index.php`): when FrankenPHP runs it in worker mode, it boots once and loops on `frankenphp_handle_request()`, serving requests concurrently across threads — a `/api/broadcast` SSE stream occupies one thread while the rest stay responsive (and with the bounded broadcast loop, `BroadcastRouter::DEFAULT_MAX_DURATION_SEC`, the worker is always released). `public/index.php` is the single source of runtime awareness; there is no `serve` flag for it.

The ergonomic dev front door for a downstream app is the skeleton's **`composer run dev`**, wired to `@php vendor/bin/waaseyaa dev` so Composer's own (system) PHP runs the CLI — identically in Git Bash, PowerShell, cmd, and POSIX. The `dev` command (and `frankenphp:install`) ship in the **optional `waaseyaa/frankenphp` package** (the Laravel Octane model), which the skeleton installs by default; the framework core stays runtime-agnostic and the runtime-agnostic `waaseyaa serve` (plain `php -S`) remains the zero-dependency fallback in core. First run, `frankenphp:install` downloads the correct FrankenPHP binary for the OS/arch into a managed path (`vendor/bin/`; on Windows the full SDK zip is extracted into `vendor/bin/frankenphp-dist/` so `frankenphp.exe` finds its DLLs), so the binary's location is never the operator's problem. `dev` resolves the binary to an **absolute path** (`FRANKENPHP_BIN` → managed install → known per-OS locations → `frankenphp` on PATH → else: print + offer `frankenphp:install`) via `Waaseyaa\FrankenPhp\Binary\BinaryResolver`, and execs it shell-free in classic per-request mode on `127.0.0.1:8080` (override with `WAASEYAA_DEV_LISTEN`). It **never adds the FrankenPHP directory to `PATH`** and never invokes the bundled `php.exe`, so the official Windows release's OpenSSL-disabled `php.exe` cannot shadow system PHP and break Composer. (The legacy `skeleton/bin/dev` launcher and the `Foundation\Runtime\FrankenPhpLocator` it used are removed — superseded by the package.) For the warm, concurrent worker runtime, run frankenphp directly against the committed `config/frankenphp/Caddyfile` (worker mode → `public/index.php`):

```bash
# Worker mode (recommended) — concurrent, app stays warm:
PHP_INI_SCAN_DIR="$PWD/config/frankenphp" frankenphp run --config config/frankenphp/Caddyfile

# Zero-config classic alternative (still concurrent across threads, no Caddyfile):
PHP_INI_SCAN_DIR="$PWD/config/frankenphp" frankenphp php-server --root public
```

Both **merge** the skeleton `config/frankenphp/php.ini` (SSE / error settings) on top of the runtime's own ini via `PHP_INI_SCAN_DIR`. Requirements and notes:

- **`frankenphp` must be installed** (install: <https://frankenphp.dev>) and run directly — the framework does not install or wrap it. **Never add the FrankenPHP install directory to `PATH`**: the official Windows release is a full PHP SDK whose bundled `php.exe` (OpenSSL disabled) then shadows system PHP and breaks Composer's TLS. `composer dev` calls the binary by absolute path; for the native worker invocation, call `frankenphp` by absolute path too, or set `FRANKENPHP_BIN`.
- The `Caddyfile` and `php.ini` are committed under `config/frankenphp/` (shipped in the skeleton, so a stock app works out of the box with no hand-edited ini).
- **Use `PHP_INI_SCAN_DIR`, not `PHPRC`.** `PHPRC` *replaces* the runtime's bundled `php.ini` wholesale, and on shared-extension builds (e.g. the official Windows release) that bundled ini is what loads `pdo_sqlite`/`sqlite3` — replacing it strands the SQLite driver and every request 500s with `could not find driver`. `PHP_INI_SCAN_DIR` is additive and avoids that.
- The committed `php.ini` deliberately leaves its `extension=pdo_sqlite`/`extension=sqlite3` lines **commented out** — every mainstream build already provides those (compiled in, or via its own bundled ini), and force-loading them from this ini (which has no `extension_dir`) re-breaks driver registration. Uncomment them (and set `extension_dir`) only for a custom build that genuinely lacks SQLite.
- **Required PHP extensions for any runtime:** `pdo_sqlite` and `sqlite3` (the SQLite default), plus `sodium`. These are declared in `composer.json` `require` (`ext-pdo_sqlite`, `ext-sqlite3`, `ext-sodium`) so `composer install` flags a runtime missing them.
- **Classic `php-server` re-boots the kernel on every request, concurrently — boot-path bootstrap must be idempotent and race-safe.** Unlike worker mode (boot once, loop), classic `php-server` (the mode `composer run dev` uses) boots a fresh kernel per request and serves them across many worker threads. So *any* schema provisioning that runs during boot or route registration executes on every request and can run in several threads at once on a cold DB. Such bootstrap **must** create-if-not-exists (and tolerate a concurrent "already exists"), never a bare `CREATE TABLE` behind a non-atomic existence check — a TOCTOU race there 500s the request (this is exactly how the live `/api/broadcast` SSE path used to fail when `auth_tokens` was provisioned on the auth route-registration path; alpha.238). Better still, provision schema in `db:init`/`migrate` rather than on the request path (cleanup-backlog CL-11). The framework's boot-path bootstraps (`auth`, `audit`, `ai-vector`, `search`) all satisfy this.

### Verification Entry Point

`composer verify` is the canonical repo-wide verification command. It chains every gate that protects merge: `cs-check`, `phpstan`, `check-composer-policy`, `check-package-layers`, `check-no-secrets`, `check-ingestion-defaults`, and `test` (the PHPUnit suite). Each gate is also exposed as its own composer script so contributors can run them in isolation during development:

| Gate | Purpose |
|------|---------|
| `composer cs-check` | PHP-CS-Fixer dry-run — reports style violations |
| `composer phpstan` | PHPStan max-level static analysis (1053 files, zero baseline tolerance) |
| `composer check-composer-policy` | Composer manifest invariants — sort-packages, `@dev` forbidden in published manifests, `self.version` scoped to root metapackage, no wildcard internal versions, tight pre-release floor in non-root manifests |
| `composer check-package-layers` | Seven-layer architecture enforcement at composer.json edges and PHP file imports; kernel-adjacent exemptions are in `KERNEL_EXEMPT_FILES` in the script itself |
| `composer check-contract-suite-coverage` | Asserts `phpunit.xml.dist`'s `Unit` testsuite globs `packages/*/tests/Contract` (not a hand-enumerated subset) and that no abstract contract base class under those directories is named with the `*Test` suffix — see "Contract-suite coverage" below |
| `composer check-no-secrets` | Repo-wide secret scan for committed credentials |
| `composer check-ingestion-defaults` | Ingestion default fixtures match contract |
| `composer test` | PHPUnit Unit + Integration suites, no coverage |
| `composer verify` | Run all of the above sequentially; first failure aborts |

CI must invoke `composer verify` rather than re-implement these checks individually, so a new gate added to the script propagates automatically without a workflow edit. Locally, run `composer verify` before requesting review to catch the same regressions CI would report.

### Contract-suite coverage

`phpunit.xml.dist`'s `Unit` testsuite covers every `packages/*/tests/Contract` directory by construction, via a single `<directory>packages/*/tests/Contract</directory>` glob — it does not hand-enumerate individual packages. (Before WP2 of the 2026-07-02 audit-remediation batch, the list named exactly 7 packages; the other 12 — including `note` and `taxonomy`'s access-policy contract tests — silently never ran in CI. A missing `<directory>` line produces no error, just fewer tests, which is why the glob and the gate below both exist now.)

The glob is safe only because of one naming convention: `phpunit.xml.dist` sets `failOnWarning="true"`, and PHPUnit's default directory collection matches any `*Test.php` file and reflects every class it declares — including abstract ones. Scanning an abstract `FooContractTest` class emits a fatal "Class Foo is abstract" warning under that setting. **Abstract contract base classes must therefore be named `Abstract<X>Contract` (no `Test` suffix)**, so the default `Test.php` collection skips the file entirely; concrete subclasses keep the ordinary `*Test` suffix. `packages/queue/tests/Contract/AbstractTransportContract.php` is the canonical exemplar to model a new abstract contract base on.

`composer check-contract-suite-coverage` (`bin/check-contract-suite-coverage`) guards both halves of this invariant so neither can regress silently: it fails if `phpunit.xml.dist` stops globbing `packages/*/tests/Contract` in the `Unit` testsuite (e.g. a future refactor reintroducing a hand-enumerated list), and it fails if any abstract class under `packages/*/tests/Contract/*.php` is named with the `*Test` suffix.

## Upgrade Playbooks

### Playbook A: Contract-safe Framework Upgrade

1. Pull latest changes and install dependencies:
   - `composer install --no-interaction`
2. Rebuild optimized discovery artifacts:
   - `composer dump-autoload --optimize`
   - `php bin/waaseyaa optimize:manifest`
3. Verify command catalog and MCP routes are available:
   - `php bin/waaseyaa list --no-ansi`
4. Run contract-focused tests:
   - `./vendor/bin/phpunit --configuration phpunit.xml.dist packages/mcp/tests/Unit/McpControllerTest.php`
5. Confirm no stable contract regressions in MCP meta fields.

### Playbook B: Semantic Baseline Refresh

1. Warm semantic index:
   - `php bin/waaseyaa semantic:warm --type node --json`
2. Run semantic baseline suite:
   - `./vendor/bin/phpunit --configuration phpunit.xml.dist --filter SemanticWarmBaselineIntegrationTest`
3. If intended baseline updates are required, refresh snapshots in a dedicated commit using the existing update workflow.
4. Record snapshot hash changes in milestone report under `docs/history/plans/`.

### Playbook C: Performance Baseline Refresh and Drift Checks

1. Generate baseline artifact:
   - `php bin/waaseyaa perf:baseline --snapshot-hash <hash> --threshold semantic_search:120 --threshold warm:500 --output tests/Baselines/perf_baseline.json`
2. Generate current measurement artifact from test/profiling pipeline.
3. Compare:
   - `php bin/waaseyaa perf:compare --baseline tests/Baselines/perf_baseline.json --current <current.json> --json`
4. Treat non-zero status as drift requiring either:
   - optimization changes, or
   - explicit baseline refresh approval.

### Playbook D: MCP Tool Failure Triage

1. Inspect tool contract and execution boundaries:
   - call MCP `tools/introspect` with target tool name.
2. Validate:
   - cache scope (`anonymous` vs `authenticated`),
   - permission boundaries (view/update/workflow),
   - visibility policy hints.
3. Re-run failing tool via `tools/call` using same argument payload.
4. Resolve by category:
   - `-32602`: invalid arguments or unknown tool/state/type.
   - `-32000`: runtime visibility/authorization/dependency failure.

### Playbook E: Cross-Repo Extension Integration Harness (v1.3)

1. Execute harness:
   - `tools/integration/run-v1.3-cross-repo-harness.sh`
2. Review artifact:
   - `docs/history/plans/artifacts/v1.3-cross-repo-harness.md`
3. Treat non-zero harness exit as a cross-repo regression gate failure.

### Playbook F: Structured/Unstructured Ingestion Pipeline (v1.4)

1. Run ingestion on structured JSON:
   - `php bin/waaseyaa ingest:run --input <input.json> --format structured --source ingest://<source> --output <mapped.json> --diagnostics-output <diag.json>`
2. Run ingestion on unstructured notes/transcripts:
   - `php bin/waaseyaa ingest:run --input <input.txt> --format unstructured --source ingest://<source> --output <mapped.json> --diagnostics-output <diag.json>`
3. Validate deterministic mapping output:
   - node keys are normalized and sorted,
   - workflow state maps to publish status (`published => status=1`, otherwise `0`),
   - relationship keys are deterministic (`from_to_type`) and sorted.
4. Treat non-zero exit as ingest gate failure; inspect diagnostics:
   - `diagnostics.errors` for hard mapping/validation failures,
   - `diagnostics.warnings` for skipped/partial rows requiring review.
5. Commit ingest artifacts and issue report for auditability.

### Editorial Dashboard Review
1. Build editorial dashboard from one or more ingest artifacts:
   - `php bin/waaseyaa ingest:dashboard --input <mapped-a.json> --input <mapped-b.json>`
2. Build dashboard from fixture/output glob and emit JSON:
   - `php bin/waaseyaa ingest:dashboard --glob 'artifacts/ingest/*.json' --json --output artifacts/ingest/dashboard.json`
3. Review queue and diagnostics surfaces:
   - blocked/review/ready counts
   - workflow mismatch totals
   - inference review pending totals
   - refresh-required categories

### Ingestion Fixture Pack Regression
1. Replay versioned ingestion fixtures through ingest command tests:
   - `./vendor/bin/phpunit --configuration phpunit.xml.dist packages/cli/tests/Unit/Command/IngestionFixturePackRegressionTest.php`
2. Refresh deterministic scenario aggregate:
   - `php bin/waaseyaa fixture:pack:refresh --input-dir tests/fixtures/scenarios --output tests/fixtures/scenarios/fixture-pack.aggregate.json`
3. Verify repeated refresh runs keep the same aggregate hash.

## Schedule Entry Auto-Discovery

Waaseyaa automatically discovers and registers all classes implementing
`ScheduleEntriesInterface` at kernel boot. No manual service-provider wiring is needed.

### Built-in schedule entries

| Class | Tasks | Cron |
|---|---|---|
| `Waaseyaa\Scheduler\Schedule\Ai\AgentScheduleEntries` | `ai:purge-runs` | Daily (`0 0 * * *`) |
| `Waaseyaa\Scheduler\Schedule\Ai\AgentScheduleEntries` | `ai:reap-stalled-runs` | Every 5 min (`*/5 * * * *`) |
| `Waaseyaa\Api\Schedule\BroadcastStorageScheduleEntries` | `broadcast_log_prune` | Nightly (`0 2 * * *`) |

Verify exact cron expressions with `bin/waaseyaa schedule:list`.

### Disabling a built-in schedule entry

Set `schedule.disabled_entries` to a list of class-string FQCNs:

```yaml
schedule:
  disabled_entries:
    - Waaseyaa\Api\Schedule\BroadcastStorageScheduleEntries
```

**Effect**:
- The entry is not instantiated at boot
- `bin/waaseyaa schedule:list` shows the entry as `[disabled]`
- The underlying task (e.g. `broadcast_log_prune`) never runs

**When to disable**:
- You manage pruning externally (database maintenance job, custom cron script)
- You want to replace a built-in entry with your own implementation
- You are testing and want to suppress background tasks

**Warning**: Disabling `AgentScheduleEntries` stops the AI runtime's retention sweep
(`ai:purge-runs`) and crash-recovery reaper (`ai:reap-stalled-runs`). The agent run
table will grow without bound and stalled runs will never be reaped. Disable only if
you handle these operations externally.

### Playbook G: Fresh App Bootstrap And Site Bring-Up

1. Scaffold the app:
   - `composer create-project waaseyaa/waaseyaa my-site --stability=dev`
2. Verify the clean scaffold before customization:
   - `cd my-site`
   - `./vendor/bin/phpunit`
   - `php bin/waaseyaa optimize:manifest`
3. Add a failing public-site test first:
   - route registration,
   - shared layout rendering,
   - key page headings / links.
4. Add app-level provider registration in `composer.json`:
   - `extra.waaseyaa.providers`
5. Implement only the app-specific surface:
   - `src/Controller/PageController.php`
   - `src/Provider/SiteServiceProvider.php`
   - `templates/*.html.twig`
   - `public/css/site.css`
6. Re-run verification:
   - `./vendor/bin/phpunit`
   - `php bin/waaseyaa optimize:manifest`
7. Only after green verification, add repo-local deploy files:
   - `deploy.php`
   - `.github/workflows/ci.yml`
   - `.github/workflows/deploy.yml`

### Playbook H: Content-Workflow (CW-v1) Activation — Node Revisions, Backfill, and Production Binding

Existing deployments upgrading onto WP-2 (node revisionable storage +
backfilled workflow state/published pointers, docs/specs/content-workflow.md)
MUST run this exact sequence. The order is load-bearing — each step exists
because the step before it is deliberately incomplete, not because of
process ceremony.

**Scope note (CW-v1 option-1 production binding, #1920).** Steps 1–4
activate the revisionable-storage SUBSTRATE: they make `node` revision-aware
and backfill legacy rows' `workflow_state` and published pointer. Step 5
documents the full production binding procedure — granting transition
permissions, adding the `workflows.assignments` binding, a post-bind
verification probe, and the rollback path. Binding `node` to the `editorial`
workflow (or any workflow carrying a published → draft edge) is now
sanctioned in production as of the option-1 release (CW-v1 option-1 PRs 1–6,
#1920 — see `[Unreleased]` in `CHANGELOG.md` until this release is tagged;
treat "this release or later" as step 5's precondition). Forward drafts (a
published → draft edge on the shipped `editorial` workflow, e.g. `revise`)
used to be deferred here: the original WP-2 review found no read path was
pointer-aware, so a forward draft's tip content was served by `find()`-based
readers while status/pointer reflected the published revision. Option-1
closes that by construction — the base row holds the published revision, not
the tip, once an entity is bound and pointered (docs/specs/content-workflow.md,
"Default-revision discipline") — so binding no longer exposes unreviewed
draft content on the public read path. See docs/specs/content-workflow.md,
"Deferred: forward drafts on the shipped workflow," for the historical record
of the original finding and how option-1 closed it.

1. **Deploy WP-2 code.** Nothing below is safe to run against the old code.
2. **`bin/waaseyaa migrate`** — applies the node revision-schema migration
   (`packages/node/migrations/2026_07_06_000001_node_revision_schema.php`):
   creates the `node_revision` table and the base `revision_id` pointer
   column. Idempotent and half-applied-state safe — safe to re-run if a prior
   deploy died partway through.

   **Verification gate — run before step 3.** Confirm the migration actually
   added the pointer columns; a stale migration-discovery cache can make
   `migrate` report success while the migration silently never ran (see
   Failure mode 3 below):
   ```sql
   SELECT revision_id, published_revision_id FROM node LIMIT 1;
   ```
   This errors loudly ("no such column") when the migration did not apply.
   If it does, rebuild the migration-discovery cache
   (`bin/waaseyaa optimize:manifest`) and re-run `migrate` before
   proceeding — do NOT run step 3 against a table missing these columns
   (see Failure mode 3).
3. **`bin/waaseyaa revisions:enable node`** — REQUIRED, not conditional. Step
   2's migration is SCHEMA-ONLY (docs/specs/content-workflow.md, "Backfill is
   binding-scoped, not framework-scoped"): it creates the table but backfills
   no data. This step seeds an initial revision (revision 1) for every
   existing node row and points the base row at it — without it, every
   existing node has revision schema but zero revision history, and every
   later revision-reading path (`loadPublishedRevision()`, `listRevisions()`,
   the pointer-move guard) has nothing to read.
4. **`bin/waaseyaa workflows:backfill-state node editorial`** — stamps a
   `workflow_state` onto every existing node row that does not yet carry one,
   derived from each row's own `status` column (published rows → the
   workflow's published `default_revision: true` state; everything else →
   `initial_state`). Run with `--dry-run` first to see the counts and sample
   ids with zero writes, then re-run for real:
   ```
   bin/waaseyaa workflows:backfill-state node editorial --dry-run
   bin/waaseyaa workflows:backfill-state node editorial
   ```
   Repeat per bundle with `--bundle=<type>` if different content types need
   different workflows. This step is what step 5 depends on — see the first
   failure mode below.

   *What gets written:* the command stamps `workflow_state` on the node's
   **base row and its current/tip revision row** (the non-revision-creating
   save path updates both in one write) — it creates no new revisions, and
   it does not retroactively touch older revision rows. Right after step 3
   the tip IS the only revision, so everything is coherent; in the general
   case an older, unstamped revision row is harmless because
   `WorkflowStateGuard::pointerStatus()` treats a pointer revision whose
   `workflow_state` is unknown to the workflow by copying its own stored
   `status` column — an unstamped revision never reports a wrong derived
   status.

   *Pointer establishment (WP-2 rework task 3):* after the state-stamp
   phase, the command also establishes `published_revision_id` on every
   EXAMINED row — whether newly stamped in this run or already carrying the
   workflow's published `default_revision: true` state from a prior run
   (the WP-1 tail) — whose published pointer is still unset
   (`loadPublishedRevision()` returns null), pointing it at that row's
   current revision id via `EntityRepository::setPublishedRevision()`.
   Without this phase, every legacy published row would read `status = 1,
   workflow_state = 'published', published_revision_id = NULL` forever, with
   no sanctioned code path able to set the pointer after the fact —
   pointer-dependent semantics (`loadPublishedRevision()`, the pointer-move
   guard) would silently treat every such row as never-published. The phase
   runs only when the entity type is revisionable AND a published
   `default_revision: true` state was resolved; a row that is revisionable
   at the type level but has no revision history yet (`revisions:enable`
   was skipped or failed for it) cannot have a pointer established — it is
   counted separately and reported (both `--dry-run` and real output)
   rather than failing the whole command. `--dry-run` reports the
   would-establish count with zero writes; the real run reports the
   established count, and any per-row pointer-establishment failure feeds
   the same failure accounting (and nonzero exit) as the state-stamp phase.

   *Revision-restore paths preserve the pointer (WP-2 rework fix, review
   finding I-1):* once this phase has established `published_revision_id`,
   the two sanctioned CONTENT-restore paths —
   `EntityRepository::rollback()` and `EntityRepository::setCurrentRevision()`,
   also exposed via the MCP/AI tools `entity.rollback` and
   `entity.set_current_revision` — never move the published pointer or flip
   `status` as a side effect of restoring old content. They restore the
   target revision's field values only; the live base row's
   `published_revision_id`/`status` ride through unchanged (or the new
   revision they create records the live pointer/status, not a stale frozen
   one). Moving the published pointer or flipping status remains exclusively
   `TransitionService`'s job (CW-v1 decision 2).

   *Custom workflows:* the command fails fast (nonzero exit, zero writes —
   dry-run included) if the named workflow defines no state that is BOTH
   `published: true` AND `default_revision: true` while published (`status =
   1`) rows still need backfilling — on such a workflow shape it has no
   published target and refuses to silently stamp published content with
   `initial_state`. Fix the workflow definition (flag its live state
   `default_revision: true`) or pre-stamp those rows manually, then re-run.
   When no published rows need backfilling it proceeds with an explicit
   notice instead. The shipped `editorial` workflow is unaffected
   (`published` carries both flags).

   **Warning — previously-archived content collapses into `draft`.** The
   backfill can only read `status`, and `status = 0` covers BOTH
   never-published drafts AND content that was deliberately retired
   (unpublished/archived) under the old model. Both backfill to
   `initial_state` (`draft` on `editorial`) — after the backfill, retired
   content is indistinguishable from new never-reviewed content: it shows up
   in the same editorial queues, and an editor can republish it through the
   normal review path without ever learning it was retired. If your site
   used unpublishing as archiving, identify the retired rows BEFORE running
   the backfill (the criteria are site-specific — an "archived" taxonomy
   term, a path pattern, a cutoff date, an editorial log) and pre-stamp them
   `archived` yourself. The backfill skips every row that already carries a
   non-empty `workflow_state`, so pre-stamping is safe, idempotent-friendly,
   and survives re-runs. Template SQL for node (`workflow_state` is a
   `FieldStorage::Data` field — it lives in the `_data` JSON blob of the
   `node` base table, NOT in a dedicated column; same `json_set` technique
   as the `NodeType` fix below). Stamp the base row and, for revision-axis
   coherence, the current revision row it points at:
   ```sql
   -- Base rows (adjust the WHERE to your site's retirement criteria):
   UPDATE node SET _data = json_set(_data, '$.workflow_state', 'archived')
   WHERE status = 0 AND nid IN (/* retired nids */)
     AND (json_extract(_data, '$.workflow_state') IS NULL
          OR json_extract(_data, '$.workflow_state') = '');
   -- Matching current revision rows (node_revision._data mirrors the base row):
   UPDATE node_revision SET _data = json_set(_data, '$.workflow_state', 'archived')
   WHERE entity_id IN (/* retired nids */)
     AND revision_id IN (SELECT revision_id FROM node WHERE nid = node_revision.entity_id);
   ```
   Skipping the revision-row UPDATE is tolerable (the guard's stored-status
   fallback keeps derived `status` truthful, per "What gets written" above)
   but stamping both keeps the revision axis coherent from day one.
5. **Add the production binding — the option-1 procedure.** Binding is now
   safe to expose to production traffic; the read-side gap that used to gate
   this step is closed by construction (see "Scope note" above and
   docs/specs/content-workflow.md, "Default-revision discipline"). Binding
   before steps 1–4 complete is still unsafe — see the failure modes below
   (amended where option-1 changed the semantics).

   1. **Precondition.** The option-1 release (this release or later) is
      deployed. Steps 1–4 above are unchanged and still required, in order,
      on the target entity type/bundle before you bind it: `migrate`
      (schema) → the verification gate → `revisions:enable` (revision
      history) → `workflows:backfill-state` (state stamp + pointer
      establishment).

   2. **Grant transition permissions to roles.** Grant the full editorial
      permission set to the roles that should hold each transition —
      `use editorial transition submit_for_review`, `...publish`,
      `...reject`, `...archive`, `...restore`, `...restore_to_published`,
      and `...revise` (CW-v1 option-1 PR-6, #1920 — "Create new draft", the
      forward-draft entry edge; not implied by any other permission, grant
      it explicitly). Two option-1-specific rules operators MUST plan
      permission grants around — both are real behavior changes from
      pre-option-1 (docs/specs/content-workflow.md, "Default-revision
      discipline," "same-state republish"):
      - **Same-state edits of PUBLISHED content now require a
        transition-into-published permission.** Once a node is bound and
        pointered, an ordinary content `PATCH` that does not itself change
        `workflow_state` — the common case of an editor fixing a typo on a
        live node directly, without going through `revise` first — is a
        same-state edit of a `default_revision: true` state. Under
        option-1, changing what serves publicly IS publishing: the save is
        authorized only when the acting account holds the permission of at
        least one transition INTO `published` (`publish` or
        `restore_to_published` on the shipped workflow), not merely entity
        `update` access. An account holding only `update` access is denied
        at PRE_SAVE (`REASON_PERMISSION`) with nothing written. Grant
        `publish` (and, for the archived-recovery path, also
        `restore_to_published`) to every role that is expected to edit live
        published content directly.
      - **Archived content follows the identical rule for the `archive`
        permission (design finding A6).** An in-place edit of the served
        archived revision requires the any-of into `archived` — on the
        shipped workflow, `archive` is the only transition targeting it.
        Editors without `archive` cannot edit archived content in place;
        they must go through `restore` (→ `draft`) first, edit the draft,
        and republish through the normal review path.

   3. **Add the binding.** `workflows.assignments`, e.g.
      `node.article => editorial`, per bundle.

      **Group-constrained (department-routing) workflows: assign legacy
      content to departments BEFORE binding.** `GroupConstraintChecker`
      fails closed on content carrying no `group_content` relationship row
      (design invariant 5) — backfilled legacy nodes have no department
      assignment, so on a workflow whose transitions carry
      `group_constraint: content_groups`, every group-gated transition is
      denied for everyone until the content is assigned
      (`bin/waaseyaa groups:content-assign`). The shipped `editorial`
      workflow carries no group constraints and is unaffected; this applies
      only to custom department-routing workflows.

   4. **Post-bind verification probe.** A concrete HTTP sequence proving the
      public read path is byte-stable during a draft window, against the
      real, confirmed JSON:API routes
      (`packages/api/src/JsonApiRouteProvider.php`,
      `packages/api/src/Http/Router/WorkflowTransitionApiRouter.php`) and the
      real login route
      (`packages/routing/src/AuthOidcRouteServiceProvider.php`). Substitute
      `$NID` for a real, already-published article id and `$COOKIES` for a
      cookie-jar file; the editor account must hold at least
      `use editorial transition revise` and `use editorial transition
      publish` **plus ordinary entity `update` access for the bundle** — the
      draft-tip `PATCH` (step 4 below) and the `?workingCopy=1` GET (step 6)
      go through the JSON:API entity/field access gates, not the transition
      permissions; an account holding only the two transition permissions
      403s at those steps.

      ```bash
      # 1. Authenticate as the editor (sets the session cookie).
      curl -s -c "$COOKIES" -X POST http://localhost:8080/api/auth/login \
        -H 'Content-Type: application/json' \
        -d '{"username":"editor","password":"<password>"}'

      # 2. Capture the anonymous baseline BEFORE any draft exists.
      curl -s http://localhost:8080/api/node/$NID | tee /tmp/before.json

      # 3. Open a forward draft: published -> draft (creates a revision-only
      #    tip; content is still the published content at this point).
      curl -s -b "$COOKIES" -X POST \
        http://localhost:8080/api/node/$NID/workflow/transition \
        -H 'Content-Type: application/json' \
        -d '{"transition":"revise"}'

      # 4. Edit the draft tip. Because the working copy is now in a
      #    non-default-revision state ('draft'), this PATCH stays
      #    revision-only — it does NOT auto-republish.
      curl -s -b "$COOKIES" -X PATCH http://localhost:8080/api/node/$NID \
        -H 'Content-Type: application/json' \
        -d '{"data":{"type":"node","attributes":{"title":"Edited draft title"}}}'

      # 5. Anonymous GET MUST be byte-identical to step 2 — old title,
      #    status=1, workflow_state="published".
      curl -s http://localhost:8080/api/node/$NID | tee /tmp/after-patch.json
      diff /tmp/before.json /tmp/after-patch.json   # expect: no diff

      # 6. The working copy carries the new title (editor session; requires
      #    entity UPDATE access; 403 otherwise — not an existence oracle).
      curl -s -b "$COOKIES" \
        "http://localhost:8080/api/node/$NID?workingCopy=1"
      #   -> data.attributes.title == "Edited draft title"
      #   -> data.attributes.workflow_state == "draft"

      # 7. Publish the draft: promotes the tip to the base row.
      curl -s -b "$COOKIES" -X POST \
        http://localhost:8080/api/node/$NID/workflow/transition \
        -H 'Content-Type: application/json' \
        -d '{"transition":"publish"}'

      # 8. Anonymous GET now serves the new content.
      curl -s http://localhost:8080/api/node/$NID
      #   -> data.attributes.title == "Edited draft title"
      #   -> data.attributes.status == 1
      ```

      This is the same sequence the PR-3 HTTP-level oracle
      (`packages/api/tests/Integration/WorkingCopyPointerAwarenessFlowTest.php`)
      pins in-process against real `NodeServiceProvider`/`WorkflowServiceProvider`
      wiring — run it against a live deployment after binding to confirm the
      same guarantee holds end-to-end.

   5. **Rollback procedure.** Removing the `workflows.assignments` entry
      restores unbound behavior immediately: `WorkflowStateGuard::onPreSave()`
      resolves no workflow for the type/bundle and returns before it would
      ever set the default-revision discipline flag
      (`packages/workflows/src/Listener/WorkflowStateGuard.php`), so every
      subsequent save on that type/bundle is undisciplined — ordinary saves
      advance the base row again, exactly as before binding. Un-binding does
      not rewrite `workflow_state`/`published_revision_id`/`status` already
      stored on the base row; the base row keeps serving whatever content was
      last promoted.

      What happens to an in-flight forward draft (an unpromoted `revise`
      tip) — verified against the actual implementation, not assumed:
      - The draft's revision row is **never deleted** by removing the
        binding. `EntityRepositoryInterface::loadWorkingCopy()`
        (`packages/entity-storage/src/EntityRepository.php`) is a pure
        storage-level comparison (the latest revision id vs. the base row's
        own `revision_id`) with no binding awareness at all, so it still
        resolves the draft immediately after un-binding, as long as no
        further save has superseded it as "latest".
      - If nobody saves the entity again before you act, the draft stays
        recoverable exactly as before: `loadWorkingCopy($id)` (or
        `loadRevision($id, $revisionId)` given the specific id) returns it,
        and `EntityRepository::rollback($id, $revisionId)` (also exposed via
        the `entity.rollback` MCP/AI tool) restores its content into a fresh
        tip — content only, never the pointer or `status`.
      - If an ordinary (now undisciplined) save touches the entity BEFORE
        the draft is recovered or promoted, that save creates its own new
        revision and writes it straight to the base row (unbound behavior),
        advancing the base pointer past the draft tip. The draft's revision
        row still is not deleted — nothing in the framework prunes revisions
        automatically (see "Revision-pruning stance for WP-2" below) — but
        it is no longer "latest", so `loadWorkingCopy()` no longer surfaces
        it automatically; recover it by its specific revision id instead.

      Practical guidance: if you are removing a binding specifically to
      abandon in-flight drafts, do so deliberately — audit `node_revision`
      for rows past the published pointer and decide per row — rather than
      relying on un-binding itself to converge them. Un-binding changes only
      whether FUTURE saves are workflow-gated and disciplined; it never
      touches existing revision rows.

   **Write-side field allowlist — closed structurally (CW-v1 option-1 PR-4,
   #1920).** This runbook used to carry an "evaluation-binding caveat"
   scoping binding to non-production, trusted-account environments only,
   because the JSON:API write path applied every attribute in a `PATCH` body
   with no allowlist on the pointer columns — an account holding only entity
   `update` permission could `PATCH {"published_revision_id": N}` directly,
   bypassing `WorkflowStateGuard`/`TransitionService` entirely. The shared
   `Waaseyaa\Entity\Write\EntityWritePayloadGuard` now enforces this
   structurally on every field-map write surface (JSON:API, admin surface,
   GraphQL) — see `docs/specs/api-layer.md`, "Write-side field allowlist
   (CW-v1 option-1 PR-4)" — so the caveat no longer applies; this is exactly
   what un-gates step 5 for production.

**Failure mode 1 — binding before backfill.** `TransitionService::currentState()`
and `WorkflowStateGuard::stateOf()` both fall back to the workflow's
`initial_state` when `workflow_state` is empty. Bind the workflow before
backfilling and every legacy published node reads as `draft` the moment the
binding takes effect — editors can no longer transition it along the real
published path without first fighting the fallback. `WorkflowStateGuard::
applyState()`'s pointer-derived `status` fallback (a pointer revision whose
`workflow_state` is unknown to the workflow keeps its stored `status` rather
than deriving from state) limits the *visible* damage — a legacy published
node does not suddenly un-publish — but it does not remove the need for the
backfill: the node is still misreported as `draft` to every workflow-aware
reader (dashboards, transition permission checks) until `workflows:backfill-
state` runs. Running the backfill after the fact fixes it going forward, but
any transitions attempted in between were evaluated against the wrong
`fromState` — always run the backfill first.

**Option-1 amendment (still accurate, one addition).** The fallback
discussion above is unchanged by option-1: `WorkflowStateGuard::applyState()`'s
status derivation still falls back to copying the pointer revision's stored
`status` whenever that revision's `workflow_state` is unknown to the workflow
(docs/specs/content-workflow.md, "Default-revision discipline" — "status
rides the pointer, uniformly" now applies this same fallback to every
target, not only `default_revision: true` ones, but the fallback rule itself
did not change). What option-1 adds: default-revision discipline itself only
ever engages once BOTH the entity is bound AND its base row carries a live
`published_revision_id` (`WorkflowStateGuard::setDiscipline()`). Binding
before backfill means `published_revision_id` is still unset, so discipline
never engages during the window this failure mode describes — behavior stays
byte-identical to pre-option-1 until the backfill establishes the pointer, at
which point future saves begin engaging discipline (revision-only saves,
same-state republish gating) for the first time. This is one more reason to
always run the backfill first: bind-before-backfill does not merely
misreport state, it also delays when discipline (and its permission
requirements, step 5.2 above) starts applying.

**Failure mode 2 — binding a non-revisionable type, or a two-axis
(translatable + revisionable) type.** `WorkflowBindingResolver::resolve()`
hard-throws (`\RuntimeException`) when a `workflows.assignments` entry names
an entity type that is not revisionable. This fails LOUDLY, the moment a
binding is added and read — not silently, and not at step 4.
Mis-ordering step 5 before step 2/3 (binding a type before its revision
schema/history exist) is therefore self-correcting for this specific error
class: the throw is the signal to go back and run steps 2–4 first. It is
*not* a substitute for running steps 2–4 in order on a type that already
happens to be revisionable — that combination (revisionable type, no
history, no backfill) fails silently per failure mode 1, not loudly.

**Option-1 amendment: two-axis types cannot be bound at all.**
`WorkflowBindingResolver::resolve()` hard-throws the identical way for a
revisionable **and translatable** entity type (design §1,
docs/specs/content-workflow.md "Default-revision discipline") — per-revision
`workflow_state` on a type that is also per-language would be ambiguous
under default-revision discipline, and per-translation workflow state is a
documented post-v1 stage. `node` ships single-axis (revisionable, not
translatable), so this does not affect the procedure above; it matters only
if you bind a different, translatable content type to a workflow.

**Failure mode 3 — running `revisions:enable` before (or without a
successful) `migrate`.** Step 2's migration is what adds the base-row
`revision_id`/`published_revision_id` columns to a pre-existing `node`
table — per the migration file's own docblock
(`packages/node/migrations/2026_07_06_000001_node_revision_schema.php`),
`SqlSchemaHandler::ensureTable()` (the schema-ensure primitive
`revisions:enable` itself calls) has no additive-column path for an
already-existing sql-blob base table; it only emits those columns at CREATE
TABLE time. If the migration never actually ran — e.g. a stale
migration-discovery cache after deploy, so `migrate` silently found
nothing new to apply — running `revisions:enable node` against a `node`
table that still lacks the columns does not error. Instead
`SqlStorageDriver::write()`'s column-routing folds `revision_id` (and, once
`workflows:backfill-state` runs, `published_revision_id`) into the `_data`
JSON blob instead of a real column, corrupting rather than merely omitting
the pointer semantics every later revision-reading path depends on — and
every command in the chain reports success. This is exactly what the
**verification gate after step 2** above exists to catch before step 3 ever
runs; run it, do not skip it, especially on a deploy pipeline where
`migrate`'s success is not independently confirmed against the schema.

**Legacy `NodeType` rows — the `new_revision` opt-out ambiguity.** Before
CW-v1 WP-2 task 2.3, `NodeType`'s constructor default for the `new_revision`
property was `false`; only after task 2.3 did the default flip to `true`
(Drupal parity — see `packages/node/src/NodeType.php`). Any `node_type` row
saved under the OLD code has `new_revision => false` *materialized* into its
persisted value bag — indistinguishable, once loaded, from a bundle that
deliberately opted out via an explicit `new_revision: false`. New `NodeType`
rows created after the WP-2 deploy get the new `true` default correctly;
existing rows do not retroactively pick it up. Operators MUST review
existing `node_type` rows and explicitly set `new_revision: true` on any
bundle that should participate in per-save revisioning.

Check (default `sql-blob` storage backend — `NodeType` values live in the
`_data` JSON column, same as any config entity with no `sql-column` override):
```sql
SELECT type, json_extract(_data, '$.new_revision') AS new_revision FROM node_type;
```
Fix (repeat per bundle that should revision; there is currently no
`entity:update`-style CLI command for this, so the sanctioned system-level
edit is a direct `_data` patch, mirroring the same `json_extract`/`json_set`
technique the query engine itself uses for `Data`-stored fields):
```sql
UPDATE node_type SET _data = json_set(_data, '$.new_revision', json('true'))
WHERE type IN ('article', 'page');
```
Bundles deliberately opted out of per-save revisioning need no change — this
review is only to separate "inherited stale default" from "chosen opt-out"
for bundles where the answer actually matters to the operator.

**Revision-pruning stance for WP-2 (explicit, by user requirement).** With
`revisionDefault: true`, every ordinary node save creates a revision — the
`node_revision` table grows unbounded by design once WP-2 ships; nothing in
the framework prunes it automatically. What ships today:
- `EntityRepository::pruneRevisions(string $entityId, RevisionPruningPolicy $policy)`
  ([packages/entity-storage/src/EntityRepository.php](../../packages/entity-storage/src/EntityRepository.php))
  with `Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy`
  ([packages/entity-storage/src/Revision/RevisionPruningPolicy.php](../../packages/entity-storage/src/Revision/RevisionPruningPolicy.php))
  is the real, callable pruning entry point. `RevisionPruningPolicy::default()`
  is a no-op (FR-039 — retains every revision of every entity forever);
  operators opt in explicitly per entity via `RevisionPruningPolicy::
  keepLastUniform(int $n)` or a per-langcode map, then call `pruneRevisions()`
  themselves (a one-off script, a custom `ScheduleEntriesInterface` class per
  the "Adding a schedule-entries class" checklist in `CLAUDE.md`, or a bespoke
  CLI command in the consuming application). The current revision is always
  excluded from deletion (`RevisionPruningPolicy::candidateExcluded()`,
  FR-038) regardless of policy — and, since WP-2 rework task 5 (review
  finding #6), so is the **published** revision (`published_revision_id`):
  `EntityRepository::pruneRevisions()` reads the base row's published
  pointer and excludes any candidate equal to it (checked inline in the
  executor loop, alongside the current-revision check, without changing
  `candidateExcluded()`'s `@api` signature), and
  `RevisionableStorageDriver::deleteRevision()` carries the same guard, so a
  direct call cannot delete the published revision either. Base tables that
  predate the `published_revision_id` column behave exactly as before —
  only the current-revision guard applies, no SQL error.
- **What is NOT shipped in WP-2**: there is no `revisions:prune` (or
  equivalent) framework CLI command, and no scheduled/automatic pruning task
  runs by default. An operator who does nothing gets unbounded revision
  growth — this is the documented default, not an oversight. Driving
  `pruneRevisions()` from CLI/scheduler is deferred past WP-2; track it
  against the `docs/specs/revision-system-unified.md` follow-up work before
  relying on retention limits in a high-write-volume deployment.

### Playbook I: Maintenance Mode (quiesce for deploys / DB swaps)

Framework-owned quiesce primitive (#2122). Replaces host-side improvisation
(the `.htaccess` 503 hack used during the SFN staging live-SQLite-swap on
2026-07-24), which is a silent no-op under the built-in server and FrankenPHP.
Behaviour is identical under `php -S`, FrankenPHP worker mode, and PHP-FPM
because the gate lives in `HttpKernel::handle()`, not the host server.

**Mechanism.** A single JSON state file — `storage/maintenance.flag` by
default — is the canonical flag. It is read **before** `boot()` (see
`docs/specs/middleware-pipeline.md` "Pre-boot maintenance gate"), so a
maintenance 503 is served without opening or querying the database. This is
what lets maintenance mode survive a database that is mid-swap.

**Semantics.**
- When active, every non-exempt HTTP request gets `503 Service Unavailable`
  with a `Retry-After` header and a branded page (HTML for browsers, JSON:API
  for `Accept: application/json`), carrying the standard security headers.
- **Fail-closed:** if the flag exists but is unreadable, non-JSON, or missing
  its `active` key, the app is treated as IN maintenance (503) with a safe
  default `Retry-After`. Only a present, valid `active:false`, or an absent
  flag, reads as "up". `maintenance:off` clears the flag entirely.
- **Exemptions:** loopback clients (`127.0.0.1`/`::1`) and the configured
  health path(s) bypass the gate so operators and orchestrators can still probe
  during a swap.

**Environment knobs** (resolved by `MaintenanceSettings::fromEnvironment()`):

| Variable | Default | Purpose |
|---|---|---|
| `WAASEYAA_MAINTENANCE_FLAG` | `storage/maintenance.flag` | Flag file path. |
| `WAASEYAA_MAINTENANCE_HEALTH_PATH` | `/health` | Comma-separated exempt paths; empty disables the health exemption. |
| `WAASEYAA_MAINTENANCE_TRUST_LOCALHOST` | `true` | Exempt loopback clients. **Set to `false` behind a same-host reverse proxy.** |
| `WAASEYAA_MAINTENANCE_PAGE` | — | Path to a consumer-supplied branded HTML page. |

> ⚠️ **Same-host reverse-proxy hazard.** The localhost exemption keys on
> `REMOTE_ADDR` only (no `X-Forwarded-For` parsing — that is spoofable if done
> casually). A deployment that fronts the app with a proxy on the same host
> (nginx/Apache `ProxyPass` to `127.0.0.1`, a documented FrankenPHP topology
> and our parked staging vhost) makes **every** external request arrive with
> `REMOTE_ADDR` = 127.0.0.1 — so the whole internet would be exempt and
> maintenance mode would silently never engage. Such deployments MUST set
> `WAASEYAA_MAINTENANCE_TRUST_LOCALHOST=false`.

**Deploy recipe (DB swap).** Commands are idempotent and return script-friendly
exit codes (`maintenance:on`/`off` → 0 on desired state reached, non-zero only
on I/O failure; `maintenance:status` → 0 serving, 1 in maintenance):

```bash
bin/waaseyaa maintenance:on --retry-after=120 --message="Database maintenance" \
  && swap_the_database \
  && bin/waaseyaa maintenance:off
# assert each step's exit code; `maintenance:status` gates verification.
```

The SFN Deployer recipe replaces its `.htaccess` quiesce with exactly this:
`maintenance:on` → atomic SQLite file swap → `maintenance:off`, each step
asserted via exit code, portable across every supported runtime.

## CLI Command Reference

### Queue Operations

| Command | Description | Key Options |
|---------|-------------|-------------|
| `queue:work` | Process jobs from the queue | `queue` (arg), `--sleep`, `--tries`, `--timeout`, `--max-jobs`, `--max-time`, `--memory` |
| `queue:failed` | List all failed queue jobs | — |
| `queue:retry` | Retry a failed job | `id` (arg: job ID or `all`) |
| `queue:forget` | Remove one failed job | `id` (arg: job ID) |
| `queue:flush` | Remove all failed queue jobs | — |

### Scheduling

| Command | Description | Key Options |
|---------|-------------|-------------|
| `schedule:run` | Run due scheduled tasks | — |
| `schedule:list` | List all registered scheduled tasks | — |

### Search

| Command | Description | Key Options |
|---------|-------------|-------------|
| `search:reindex` | Rebuild search index from all indexable entities | `--batch-size` / `-b` (default: 100) |

### Development

| Command | Description | Key Options |
|---------|-------------|-------------|
| `serve` | Start the single-worker `php -S` dev server (not for production or the admin SPA's concurrent SSE — for those, launch FrankenPHP natively against `config/frankenphp/Caddyfile`) | `--host` (default: 0.0.0.0), `--port` / `-p` (default: 8080) |

### Maintenance (quiesce — Playbook I)

| Command | Description | Key Options |
|---------|-------------|-------------|
| `maintenance:on` | Enable maintenance mode (branded 503 + `Retry-After`). Idempotent. | `--retry-after` (default 120), `--message` |
| `maintenance:off` | Clear the flag and restore service. Idempotent. | — |
| `maintenance:status` | Report state. Exit 0 = serving, 1 = in maintenance (incl. fail-closed). | `--json` |
| `sync-rules` | Sync framework rules from Waaseyaa to app | `--force` / `-f`, `--dry-run` |

## Queue Operations Playbook

### Starting a queue worker

```bash
php bin/waaseyaa queue:work --max-jobs=100 --memory=128 --timeout=60
```

For production, run the worker as a systemd service or Supervisor process. Restart on failure.

### Monitoring failed jobs

```bash
php bin/waaseyaa queue:failed          # list all failures
php bin/waaseyaa queue:retry <id>      # retry specific job
php bin/waaseyaa queue:retry all       # retry all failures
php bin/waaseyaa queue:forget <id>     # discard one failure
php bin/waaseyaa queue:flush           # discard all failures
```

### Scheduling in production

Run `schedule:run` via system cron every minute:

```cron
* * * * * cd /path/to/project && php bin/waaseyaa schedule:run >> storage/logs/scheduler.log 2>&1 || { status=$?; logger -t waaseyaa-scheduler "schedule:run failed (exit $status)"; exit $status; }
```

The success path retains scheduler output in the application log. On failure, the
original non-zero status is also emitted to syslog and returned to cron instead of
being hidden by a `/dev/null` redirect. Monitor `waaseyaa-scheduler` syslog entries
with the host's normal alerting. Use `schedule:list` to verify registered tasks.

### Search reindex

Full FTS5 index rebuild (safe to run on a live system):

```bash
php bin/waaseyaa search:reindex --batch-size=200
```

## Onboarding Path (Contributor Quick Path)

1. Read `CLAUDE.md` for architecture and gotchas.
2. Read subsystem spec(s) in `docs/specs/` for the package being changed.
3. Use v1.2 tooling for deterministic setup:
   - `scaffold:bundle`, `scaffold:relationship`, `scaffold:workflow`
   - `scaffold:extension`
   - `fixture:generate`
   - `debug:context`
   - `perf:baseline`, `perf:compare`
4. Keep every implementation issue paired with:
   - focused tests,
   - a `docs/history/plans/` report,
   - GitHub issue closure evidence.
5. For external module work, follow:
   - `docs/specs/extension-author-onboarding.md`

## Audit Trail

- Extension release runbook: `docs/specs/extension-release-playbook.md`
- v1.0 verification: `docs/history/plans/v1.0-verification-report.md`
- v1.1 verification readiness: `docs/history/plans/v1.1-verification-gate-readiness-report.md`
- v1.2 tooling reports:
  - `docs/history/plans/v1.2-cli-scaffolding-report.md`
  - `docs/history/plans/v1.2-fixture-generator-report.md`
  - `docs/history/plans/v1.2-debug-context-panel-report.md`
  - `docs/history/plans/v1.2-performance-cli-tooling-report.md`
  - `docs/history/plans/v1.2-mcp-introspection-diagnostics-report.md`
