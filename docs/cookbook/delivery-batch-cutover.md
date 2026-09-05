# Activate batch-aware delivery projection

This procedure implements `FW-DELIVERY-BATCH-PROJECTION-01` after the complete
`FW-DELIVERY-EVENT-BATCHES-01` candidate has been accepted. It upgrades the local
analytics projection; it does not publish event evidence or authorize a release.

## Preconditions

- Use a clean checkout of the accepted cutover commit with its locked dependencies.
- Confirm the freeze manifest matches the accepted v1 ledger and the gate refuses
  mutation or deletion of accepted freeze, batch-schema and batch files.
- Retain the last successful projection receipt and its immutable source commit.
  The database is a rebuildable projection, not the source of event authority.
- Supply the existing local projection connection through
  `WAASEYAA_DELIVERY_TELEMETRY_DSN`, `WAASEYAA_DELIVERY_TELEMETRY_DB_USER` and
  `WAASEYAA_DELIVERY_TELEMETRY_DB_PASSWORD`. Do not print or commit their values.
  MySQL must use a loopback host. SQLite is for disposable verification.

## Validate and activate

Run these commands from that checkout in Bash. The source commit is captured
once; every operation below uses that same immutable object. Choose a new receipt
directory for each attempt so earlier evidence remains available.

```bash
set -euo pipefail
source_commit=$(bin/git rev-parse --verify 'HEAD^{commit}')
source_base=$(bin/git rev-parse --verify "$source_commit^1")
receipt_dir=$(mktemp -d "${TMPDIR:-/tmp}/waaseyaa-batch-cutover.XXXXXXXX")
printf '%s\n' "$source_commit" > "$receipt_dir/source.sha"
php bin/check-delivery-agent-events --candidate="$source_commit" --base="$source_base" > "$receipt_dir/source-validation.log" 2>&1
php bin/project-delivery-agent-events install > "$receipt_dir/install.json" 2> "$receipt_dir/install.stderr.log"
php bin/project-delivery-agent-events plan --source-ref="$source_commit" > "$receipt_dir/plan.json" 2> "$receipt_dir/plan.stderr.log"
php bin/project-delivery-agent-events apply --source-ref="$source_commit" > "$receipt_dir/apply.json" 2> "$receipt_dir/apply.stderr.log"
php bin/project-delivery-agent-events verify --source-ref="$source_commit" > "$receipt_dir/verify.json" 2> "$receipt_dir/verify.stderr.log"
php bin/project-delivery-agent-events apply --source-ref="$source_commit" > "$receipt_dir/repeat-apply.json" 2> "$receipt_dir/repeat-apply.stderr.log"
printf 'Receipts: %s\n' "$receipt_dir"
```

`install` explicitly adds the projection identity table and preserves existing
rows. It is idempotent. Ordinary operations refuse a missing identity table;
they do not silently install it. The first plan may report `drift` because the
new identity is not populated yet; plan success means the source was validated,
not that the projection already matches it.

Inspect the receipts before calling activation complete:

- All source-dependent receipts name the captured source commit and projector
  version 2. Event-schema, frozen-ledger, batch-manifest, batch-schema, freeze and
  replay hashes agree across plan, apply, verify and repeat apply.
- Verify succeeds with outcome `no_op`. Event count matches the accepted complete
  source set. Do not hard-code the cutover count into future checks.
- Repeat apply reports `no_op`, with the same generation as the successful apply.
  A later observation time does not justify advancing projection generation.
- Refresh Grafana and inspect its source/freshness display and representative
  accepted PRs. The frozen-ledger hash still names only v1 bytes; the separate
  batch manifest and replay hashes bind the complete set.

Retain receipts and source-validation output as operational evidence. Publish new
agent events only through reviewed immutable batch files after this cutover is
qualified; never resume appending to the frozen v1 JSONL.

## Failure and recovery

Stop when a command fails and retain the partial receipts. A failed apply rolls
back event rows and both projection identities together. Re-run plan, apply and
verify against the same captured source after resolving the reported cause.
An interrupted install can be retried explicitly; it does not clear existing
rows. Do not repair failures by changing the accepted freeze, deleting accepted
batches, editing projection rows manually, or reverting to an older source.
