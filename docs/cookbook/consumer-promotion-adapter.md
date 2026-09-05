# Adapt consumer production promotions

Use this to turn a GitHub Actions dump into DORA-ready promotion records.
It is read-only JSON normalization. It does not call GitHub, write DevLake,
install Grafana, or change CI.

## Dump

```bash
noglob gh api 'repos/jonesrussell/waaseyaa-infra/actions/workflows/promote.yml/runs?per_page=100' \
  --jq '{repository: "jonesrussell/waaseyaa-infra", runs: .workflow_runs}' \
  > /tmp/consumer-promotions-input.json
```

Do not dump Framework GitHub Deployments or a generic `deploy-*.yml` run list
and treat the result as promotions. The adapter will reject those rows.

## Run

```bash
php bin/adapt-consumer-promotions --self-test
php bin/adapt-consumer-promotions --input=/tmp/consumer-promotions-input.json
```

A successful `promote.yml` `workflow_dispatch` run becomes one
`sample_eligible` promotion with target, `production`, exact 40-character
revision, reason, started/finished UTC, and sample size. Push deploys, PR
merges, other workflow paths, unparseable run names, short SHAs, and
title/head SHA mismatches are rejected with a closed code. A failed promote
is recorded and is not sample-eligible.

The adapter never prints `GH_TOKEN` or `GITHUB_TOKEN` values.
