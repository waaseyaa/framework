# FW-K1-DASHBOARD-FILTERS-01 — dashboard time and repository filters

- Issue: `#2869`
- Contract: `docs/specs/delivery-telemetry.md`
- Authority: repository-tracked Grafana definition; forge-neutral

## Outcome

Make the delivery-flow dashboard's recent PR, review, and pipeline panels obey
the selected Grafana time range and restrict their DevLake inputs to
`waaseyaa/framework` without changing the configured datasource identity.

## Design

Panels 2 and 3 filter the native pull-request creation timestamp, panel 4
filters the native pipeline creation timestamp, and panel 5 treats the selected
range as the pull-request creation cohort. Repository scope joins DevLake's
repository table by both connection identity and repository ID. Agent events in
panel 5 additionally match the repository's full name and PR number.

Panel 5 excludes review events without a source occurrence time. It does not
apply the dashboard range to review occurrences, so the earliest substantive
review for an in-range PR remains the earliest known review. Panels 6–8 remain
explicit current/latest-state views outside the dashboard time filter.

## Scope boundary

This repair does not change ingestion, projection, ledger, CI, release policy,
the datasource connection, or the live Grafana instance.
