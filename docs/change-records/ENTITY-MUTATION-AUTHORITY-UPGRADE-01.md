# ENTITY-MUTATION-AUTHORITY-UPGRADE-01 — reachable legacy authority repair

- Issue: [#2460](https://github.com/waaseyaa/framework/issues/2460)
- Contract: `docs/specs/entity-system.md`, `docs/specs/cli-kernel.md`
- Authority: implementation, local verification, draft pull request, and issue evidence only

## Decision

Persisted pre-DB-03 aggregates remain fail-closed during ordinary hydration.
The supported escape hatch is the explicit restricted command
`entity:backfill-mutation-authorities --reason=...`, dispatched before provider
boot. No ordinary boot, read, migration, schema synchronization, or fresh-install
path invokes the repair.

The command validates its audit reason, repairs repositories implementing the
framework boundary with a database-backed authority, and explicitly reports
other repository types it skips. Repository construction and repair failures do
not strand later types. A repository-owned transaction repairs each type
atomically across every declared community. Legacy empty community owners bind
to the same `_global` tenant used by hydration, while NUL-bearing entity,
community, or language identities fail before composite keys are formed.
Translatable types derive authority only from the canonical language row, not
from translation peers. A post-commit event-delivery failure reports the exact
committed count, while an unclassified foreign failure makes both its per-type
count and the aggregate total unknown rather than claiming zero. The retained
invocation reason plus exit/count report is the
durable audit evidence: output includes the reason's SHA-256 digest for binding
without echoing the raw reason. Per-row events are post-commit notifications,
not the sole durable audit record. Existing authorities are preserved, output
reveals no token material, and a completed retry is idempotent.

## Proof

The packaged-form gate starts from a released pre-DB-03 consumer, persists its
ordinary `workflow:editorial` row, resolves and installs the exact candidate
package cohort over that durable database, and runs the supported upgrade
sequence. It proves ordinary boot is initially refused, the
restricted command repairs all tenant bindings without changing an existing
authority, ordinary boot then succeeds, and a completed retry creates zero
rows.

No merge, release, tag, deployment, or production operation is authorized by
this change record.
