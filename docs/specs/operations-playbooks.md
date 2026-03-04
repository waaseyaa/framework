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

## Audit Trail

- v1.0 verification: `docs/plans/v1.0-verification-report.md`
- v1.1 verification readiness: `docs/plans/v1.1-verification-gate-readiness-report.md`
- v1.2 tooling reports:
  - `docs/plans/v1.2-cli-scaffolding-report.md`
  - `docs/plans/v1.2-fixture-generator-report.md`
  - `docs/plans/v1.2-debug-context-panel-report.md`
  - `docs/plans/v1.2-performance-cli-tooling-report.md`
  - `docs/plans/v1.2-mcp-introspection-diagnostics-report.md`
