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
framework boundary, and explicitly reports custom repository types it skips.
It delegates creation to the repository-owned transaction and audit event,
preserves existing authorities, reports only deterministic per-type counts and
skipped type names, and reveals no token material. A retry is idempotent.

## Proof

The packaged-form gate installs the exact candidate tree, creates ordinary
authorities, removes only the persisted `workflow:editorial` authority to model
the legacy state, and proves ordinary boot fails. It then runs the supported
command, verifies an unrelated token is byte-identical, proves ordinary boot
succeeds, and proves a retry creates zero rows.

No merge, release, tag, deployment, or production operation is authorized by
this change record.
