# FW-K1-CUTOVER-VERIFY-01 -- read-only K1 cutover verification

- Issue: `#2869`
- Contract: `docs/specs/delivery-telemetry.md`
- Worktree lease: `4b19e7847bfb058220d8690cd48e299b` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2916-codex-integration`
- Authority: repository-tracked verifier; forge-neutral

## Outcome

Give operators a concrete acceptance check that the deployed Panel 8 result,
read-only projection identity, and accepted projector receipt describe the same
cutover. Grafana and the projection database remain rebuildable views rather
than event authorities.

## Design

`php bin/verify-k1-delivery-cutover --receipt=/absolute/path.json` requires a
successful `apply` or `verify` receipt. The receipt pins the expected source
commit, generation, projector version, event count, and every projection
identity hash.

The verifier fetches the deployed dashboard from the loopback Grafana API,
requires Panel 8's SQL and datasource UID to match the tracked definition
exactly, and obtains the actual panel row through `/api/ds/query`. It then
reads the projection tables through a query-only database connection. Each
receipt-backed Grafana field and each database identity field is compared
independently with the receipt. The Panel 8 projected timestamp remains
display-only because the projector receipt has no corresponding timestamp
field. Missing, `unknown`, stale, malformed, or mismatched receipt-backed
evidence fails closed.

Grafana credentials and database credentials remain environment-only. Username
redaction preserves surrounding diagnostic words while still replacing the
complete username token. HTTP requests have a five-second timeout, bounded
response bodies, and no redirect following. The Grafana base URL and single
declared MySQL host must be loopback;
duplicate MySQL host parameters are rejected before connection. A SQLite
database must already exist at an absolute path, preventing a verification typo
from creating a new database. The public arbitrary-dashboard SQL seam is removed.

The Architecture fixture copies the accepted tracked default dashboard and
uses a disposable loopback HTTP server and SQLite database. It covers token
and basic authentication, exact deployed SQL/datasource matching, receipt
staleness, every comparison field separately,
unknown values, row cardinality, redirect refusal, missing SQLite, and
credential redaction. Redirect refusal covers both dashboard GET and panel-query
POST requests. The credential-free self-test keeps a small comparison smoke
with in-memory SQLite and no external HTTP or persistent database, and exercises
the duplicate-host MySQL DSN boundary without opening a connection.

## Scope boundary

This slice owns the verifier, its focused fixtures and tests, this record, the
operator guide, and the changelog fragment. It does not change the dashboard,
projector, validator, CI, or live Grafana/projection state. This candidate is
based on the accepted batch-aware Panel 8 change from `#2915`; the fixture does
not synthesize substitute dashboard SQL.
