# FW-CONSUMER-PROMOTION-ADAPTER-01 — read-only consumer promotions

- Issue: `#2869`
- Contract: `docs/specs/delivery-telemetry.md`
- Worktree lease: `7ed5b29e963a22cd34305190aeccc837` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2869-consumer-promotion`
- Authority: repository-tracked adapter; forge-neutral

## Outcome

Normalize dumped GitHub Actions runs into consumer production-promotion
records without treating PR merges, Framework GitHub Deployments, or retired
push `deploy-*.yml` history as deployments.

## Design

`php bin/adapt-consumer-promotions --input=<file>` reads a `{repository, runs}`
JSON document and emits `waaseyaa.consumer-promotion-adapter.v1`. A row is a
promotion only when:

- `path` is `.github/workflows/promote.yml`
- `event` is `workflow_dispatch`
- the run-name matches `Promote {target} to {environment} at {sha} — {reason}`
- `{sha}` is 40 lowercase hex and equals `head_sha`
- `{environment}` is `production`
- `{target}` is one of the closed promote.yml targets

`sample_eligible` is true only for `conclusion=success`. Failed promotions are
kept and counted out of `sample_size`. Rejected rows carry a closed code:
`workflow_path_not_promote`, `event_not_workflow_dispatch`,
`display_title_unparseable`, `revision_not_exact_sha`,
`revision_mismatch_head_sha`, `environment_not_production`, `target_unknown`,
or `incomplete_source`.

The tool does not fetch GitHub, connect to MySQL, or write files. `--self-test`
and Architecture fixtures cover accepted, rejected, and ineligible cases
without credentials.

## Scope boundary

This slice owns the adapter, its tests, this record, the operator guide, the
telemetry contract paragraph, and the changelog fragment. Collection settings,
Grafana JSON, projector, validator, and CI jobs were not changed.
