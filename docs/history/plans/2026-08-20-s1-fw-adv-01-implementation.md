# S1-FW-ADV-01 implementation plan

Contract: `docs/specs/save-advisories.md`

Stable record: `docs/change-records/S1-FW-ADV-01.md`

## Slice 1 — storage contract

Status: complete.

Retain red tests for advisory construction/canonical binding, malformed data,
`SaveContext` immutability and builder preservation, exception ordering,
original entity exposure, and no-write event abort. Implement the values in
`waaseyaa/entity-storage` and thread the stored original into
`BeforeSaveEvent` from the singular repository save path.

Focused proof: entity-storage Unit and Integration suites, API surface check,
PHPStan for touched packages.

## Slice 2 — HTTP and Admin

Status: complete.

Retain red controller tests for create, plain update, and expected-revision
update; malformed/oversized acknowledgement input; changed-candidate refusal;
and validation non-bypass. Implement strict resource-meta parsing and a 428
error mapper. Preserve error code/meta through Generic Admin, extend transport
contracts with optional tokens, then add the accessible review/resubmit panel
with immutable captured candidate data.

Focused proof: api and admin-surface PHP Unit/Integration suites; admin Vitest,
typecheck, and targeted browser/component coverage.

## Slice 3 — publishing and MCP

Status: complete.

Retain red tests proving create/update no-write failure and successful exact
retry, idempotency fingerprint inclusion, structured publishing exception, MCP
schema exposure, and structured tool error propagation. Add trailing optional
parameters only.

Focused proof: publishing and ai-tools Unit suites plus MCP bridge regression
tests.

## Slice 4 — import

Status: complete.

Retain red migration create/update tests for undeclared refusal, declared
one-retry success, candidate-change invalidation, validation independence,
deterministic advisory evidence, and no evidence on hash-match skip. Add the
bounded migration declaration, destination immutable clone, and warning records
in `RunReport`.

Focused proof: migration Unit/Integration and CLI import rendering suites.

## Slice 5 — convergence

Status: complete. Exact evidence is retained in
`docs/change-records/S1-FW-ADV-01.md`; the review branch remains unmerged and
does not authorize a tag, release, split-package fan-out, or deployment.

Run formatting, Composer policy, API/architecture gates, PHPStan, full Unit and
Integration suites, and `php bin/check-pr-preflight`. Record exact parent,
candidate, commands, counts, skips, and failures in the stable change record.
Push only the review branch and open a draft pull request linked to #2467.
