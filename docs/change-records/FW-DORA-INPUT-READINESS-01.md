# FW-DORA-INPUT-READINESS-01 — DORA source audit

- Issue: `#2869`
- Contracts: `docs/specs/delivery-telemetry.md`, `docs/specs/workflow.md`
- Worktree lease: `1393cd58f5d5c350f20477aaabfd53df`
- Authority: repository-tracked audit; forge-neutral
- Collection settings, dashboards, and CI were not changed

## Boundary

Waaseyaa Framework is a library. PR merges, GitHub Environment records named
`production` on `waaseyaa/framework`, and CI success are not production
deployments. Application staging, promotion, rollback, and incidents belong to
consumer and infrastructure repositories.

## Actual sources

| System | What it is | Query evidence (2026-09-05) |
| --- | --- | --- |
| Framework publication | Tag + split + Packagist | `release-cut.yml` last success 2026-09-02T15:48Z for `v0.1.0-alpha.300`; `packagist-update.yml` success 2026-09-02T16:29Z. `scripts/build-release-candidate.sh` records `deployment_performed: false`. Current `release.yml` is Release Readiness: no `environment:`, no deploy/rollback/incident path (`CiReleaseWorkflowParityTest`). |
| Framework GitHub Deployments | Retired CI noise | 2,283 records, environments `staging`/`production`, latest 2026-08-10T06:21Z. Sample `5827240642` is push-to-main `Release Pipeline` (old `release.yml`) for merge `#2331`; `production_environment` is false. Current workflows contain no `environment:` key; Environments API `total_count` is 0. Do not ingest these as DORA deploys. |
| Consumer production promotion | `jonesrussell/waaseyaa-infra` `promote.yml` | The pinned workflow [at infra commit `0888d56e011483b84aacf8c3fc84de8b231b0b79`](https://github.com/jonesrussell/waaseyaa-infra/blob/0888d56e011483b84aacf8c3fc84de8b231b0b79/.github/workflows/promote.yml) accepts one target, `production`, an exact 40-character `revision`, and a reason through manual `workflow_dispatch`. The [latest observed run `33474562592`](https://github.com/jonesrussell/waaseyaa-infra/actions/runs/33474562592) succeeded for `northway` at infra revision `0888d56e011483b84aacf8c3fc84de8b231b0b79` on 2026-09-01T05:41Z; observation metadata was rechecked 2026-09-05T14:47:55Z. The workflow's `production` environment currently has `protection_rules: []` and `deployment_branch_policy: null` as observed at that time, so `Protected Environment` is workflow wording, not an active protection configuration. The revision is checked against the infra checkout's `main` and passed to `promote-target`; no consumer/framework SHA mapping is proven by this source. Targets include `minoo`, `giiken`, `northway`, `jgs`, `fnpi`, `goformx`, `oiatc`, `rhtcircle`, `sagamokstrong`, `spot-the-ai`. |
| Infra GitHub Deployments | Mixed history | 1,908 records. Recent ones are `promote.yml`. Older pages are push-triggered `deploy-*.yml` (sample page 1900: `Deploy oiatc-app` on push, 2026-04-20). Those push deploys are not the current promotion contract. Only `promote.yml` is a DORA deployment candidate. |
| Minoo application repo | Source pin, not the live deploy | `waaseyaa/minoo` README: minoo.live deploys from `waaseyaa-infra`, not this repo. 541 GitHub Deployments remain; sample `5204248159` has `production_environment: false`. Retired PHP Deployer. |
| Anokii / Sheg | No production promotion feed | `waaseyaa/anokii`: deployer recipe only; Deployments API empty. `jonesrussell/sheguiandah-waaseyaa`: private; Deployments and Environments empty. |
| Tracked Grafana | Delivery flow, not DORA | `ops/observability/grafana/waaseyaa-k1-delivery-flow.json` reads `_tool_github_pull_requests`, `_tool_github_runs`, `_tool_github_pull_request_reviews`, and `waaseyaa_delivery_agent_events_v1` for `waaseyaa/framework` only. No `cicd_deployments` / incident tables. |

Incident sources: Framework has no `incident`/`outage` labels and no incident
workflow. `release.yml` is asserted not to create incidents. Promote reasons are
operator strings, not incident open/close events. Historical production failures
(minoo.live WSOD, SFN SQLite swap) live in specs and comments, not a catalog.

## Four metrics

| Metric | Present | Missing |
| --- | --- | --- |
| Deployment frequency | Consumer: `promote.yml` success time, target, SHA. Framework: publication time (tag/Packagist) if labeled as publication, not deploy. | A DevLake/Grafana binding to `promote.yml` only. Sheg/Anokii production events. |
| Lead time for changes | PR `github_created_at` → `merged_at` (pre-production). Framework merge → tag/Packagist (publication lag). | First commit → consumer production promote. The current `promote.yml` evidence does not prove a consumer/framework pin mapping (`MINOO_REF` or equivalent target source) from the supplied infra revision to the promoted application. |
| Change failure rate | None that is a production failure linked to a promote. | Incident identity, severity, and deploy SHA. CI failures and verifier findings must not substitute. |
| Time to restore | None. | Incident start and restore/rollback completion times. |

## Proposed first dashboard

Do not add a four-box DORA dashboard yet.

1. **Consumer promotions** — one row per successful `promote.yml` run: target,
   environment `production`, exact SHA, started/finished UTC, reason. Filter by
   workflow path, not by GitHub environment name. Show sample size. Keep push
   `deploy-*.yml` history out of the metric.
2. **Framework publication** — successful `release-cut` / tag / Packagist
   verify, labeled publication. Do not join these to K1 merge times as deploys.
3. Leave K1 as pre-production delivery flow. Defer CFR and restore until a
   governed incident source exists (likely an append-only incident ledger, not
   GitHub issue keyword search).

Re-run evidence:

```bash
gh api repos/waaseyaa/framework/environments --jq '.total_count'
gh api repos/jonesrussell/waaseyaa-infra/environments --jq '.environments[].name'
gh run list --repo jonesrussell/waaseyaa-infra --workflow promote.yml --limit 8
gh run list --repo waaseyaa/framework --workflow packagist-update.yml --limit 3
```
