# Verify K1 Grafana identity after projection cutover

Use this after the batch-aware projector and Panel 8 are both deployed. It is
read-only acceptance evidence. It does not apply a projection, import Grafana,
or mutate live state.

## Preconditions

- A checkout that contains the batch-aware Panel 8 definition (`#2915`).
- A local projection that already has `waaseyaa_delivery_projection_state` and
  `waaseyaa_delivery_projection_identity_v2`.
- The existing local connection in `WAASEYAA_DELIVERY_TELEMETRY_DSN`,
  `WAASEYAA_DELIVERY_TELEMETRY_DB_USER`, and
  `WAASEYAA_DELIVERY_TELEMETRY_DB_PASSWORD`. Do not print or commit those values.
  MySQL must use a loopback host. SQLite is for disposable fixtures.

## Run

```bash
php bin/verify-k1-delivery-cutover --self-test
php bin/verify-k1-delivery-cutover
```

The live command executes Panel 8's tracked SQL against that connection and
compares the row with the projection identity. It reports mismatches in source
SHA, projector version, generation, event count, frozen v1 ledger hash,
complete replay hash, and batch-manifest hash. Missing provenance, including
Grafana `unknown` values, fails verification.

Optional `--dashboard=/absolute/path.json` points at a disposable definition.
The default is `ops/observability/grafana/waaseyaa-k1-delivery-flow.json`.
