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
4. Record snapshot hash changes in milestone report under `docs/plans/`.

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
   - `docs/plans/artifacts/v1.3-cross-repo-harness.md`
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
   - a `docs/plans/` report,
   - GitHub issue closure evidence.
5. For external module work, follow:
   - `docs/specs/extension-author-onboarding.md`

## Audit Trail

- Extension release runbook: `docs/specs/extension-release-playbook.md`
- v1.0 verification: `docs/plans/v1.0-verification-report.md`
- v1.1 verification readiness: `docs/plans/v1.1-verification-gate-readiness-report.md`
- v1.2 tooling reports:
  - `docs/plans/v1.2-cli-scaffolding-report.md`
  - `docs/plans/v1.2-fixture-generator-report.md`
  - `docs/plans/v1.2-debug-context-panel-report.md`
  - `docs/plans/v1.2-performance-cli-tooling-report.md`
  - `docs/plans/v1.2-mcp-introspection-diagnostics-report.md`
