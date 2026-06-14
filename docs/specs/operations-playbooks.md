# Operations Playbooks

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
| `composer dev:php` | `bin/waaseyaa serve` | PHP built-in server with `PHP_CLI_SERVER_WORKERS=4`. Single foreground process, no shell forking. |
| `composer dev:admin` | `bin/waaseyaa admin:dev` | Nuxt admin SPA dev server. Reads `NUXT_BACKEND_URL` (defaults to `http://127.0.0.1:${APP_PORT:-8080}`). |
| `composer dev` | delegates to `dev:php` | Convenience alias for the most common case (PHP-only). |

For full-stack local development, run `composer dev:php` in one terminal and `composer dev:admin` in another. Each process owns its own lifecycle; killing one does not orphan the other. CI and Docker compose files invoke the typed entries directly rather than the legacy shell pipeline.

### Verification Entry Point

`composer verify` is the canonical repo-wide verification command. It chains every gate that protects merge: `cs-check`, `phpstan`, `check-composer-policy`, `check-package-layers`, `check-no-secrets`, `check-ingestion-defaults`, and `test` (the PHPUnit suite). Each gate is also exposed as its own composer script so contributors can run them in isolation during development:

| Gate | Purpose |
|------|---------|
| `composer cs-check` | PHP-CS-Fixer dry-run — reports style violations |
| `composer phpstan` | PHPStan max-level static analysis (1053 files, zero baseline tolerance) |
| `composer check-composer-policy` | Composer manifest invariants — sort-packages, `@dev` forbidden in published manifests, `self.version` scoped to root metapackage, no wildcard internal versions, tight pre-release floor in non-root manifests |
| `composer check-package-layers` | Seven-layer architecture enforcement at composer.json edges and PHP file imports; kernel-adjacent exemptions are in `KERNEL_EXEMPT_FILES` in the script itself |
| `composer check-no-secrets` | Repo-wide secret scan for committed credentials |
| `composer check-ingestion-defaults` | Ingestion default fixtures match contract |
| `composer test` | PHPUnit Unit + Integration suites, no coverage |
| `composer verify` | Run all of the above sequentially; first failure aborts |

CI must invoke `composer verify` rather than re-implement these checks individually, so a new gate added to the script propagates automatically without a workflow edit. Locally, run `composer verify` before requesting review to catch the same regressions CI would report.

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

## CLI Command Reference

### Queue Operations

| Command | Description | Key Options |
|---------|-------------|-------------|
| `queue:work` | Process jobs from the queue | `queue` (arg), `--sleep`, `--tries`, `--timeout`, `--max-jobs`, `--max-time`, `--memory` |
| `queue:failed` | List all failed queue jobs | — |
| `queue:retry` | Retry a failed job | `id` (arg: job ID or `all`) |
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
| `serve` | Start the PHP development server | `--host` (default: 127.0.0.1), `--port` / `-p` (default: 8080) |
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
php bin/waaseyaa queue:flush           # discard all failures
```

### Scheduling in production

Run `schedule:run` via system cron every minute:

```cron
* * * * * cd /path/to/project && php bin/waaseyaa schedule:run >> /dev/null 2>&1
```

Use `schedule:list` to verify registered tasks.

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
