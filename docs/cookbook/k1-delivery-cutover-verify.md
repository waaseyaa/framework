# Verify K1 Grafana identity after projection cutover

Use this after the batch-aware projector and Panel 8 are deployed. The command
is read-only acceptance evidence: it does not apply a projection or import a
Grafana dashboard.

## Preconditions

- Use a checkout containing the accepted batch-aware Panel 8 definition from
  `#2915`.
- Retain the successful JSON receipt from
  `bin/project-delivery-agent-events apply` or `verify`.
- Configure an existing projection database with
  `WAASEYAA_DELIVERY_TELEMETRY_DSN`,
  `WAASEYAA_DELIVERY_TELEMETRY_DB_USER`, and
  `WAASEYAA_DELIVERY_TELEMETRY_DB_PASSWORD`. Use a database account limited to
  read access. MySQL must use a loopback host. SQLite is only for an existing
  disposable fixture at an absolute path.
- Set `WAASEYAA_GRAFANA_URL` to the loopback Grafana base URL, including its
  path prefix when present, such as `http://localhost:4000/grafana`.
- Authenticate with either `WAASEYAA_GRAFANA_TOKEN` or the complete
  `WAASEYAA_GRAFANA_USER` and `WAASEYAA_GRAFANA_PASSWORD` pair. Do not put
  credentials in command arguments or tracked files.

## Run

```bash
php bin/verify-k1-delivery-cutover --self-test
php bin/verify-k1-delivery-cutover \
  --receipt=/absolute/path/to/accepted-projector-receipt.json
```

The live command fetches the deployed dashboard and Panel 8 result through the
Grafana API. It first requires the deployed Panel 8 SQL and datasource UID to
match the tracked definition exactly. It independently reads the projection
identity through a query-only connection, then compares both observations with
the accepted receipt's source SHA, projector version, generation, event count,
and identity hashes.

A missing or `unknown` value, stale receipt relationship, field mismatch,
unexpected row count, redirect, non-loopback endpoint, or failed API request
fails verification. Output names mismatched fields without printing values or
credentials.
