# FW-WORKFLOW-PROJECTION-AUDIT-01 — legacy serving-projection audit and repair

Status: implementing  
Anchor mirror: waaseyaa/framework#2570  
Parent: waaseyaa/framework#2435  
Parent candidate: `384a267be67d2805771206e33e29437958f5ea33`

## Intent

Provide a report-first operator command for workflow-bound legacy records whose
materialized serving projection cannot be derived from their workflow and
published pointer. Repair is deliberately one finding at a time and requires
the exact fingerprint emitted by a current report.

## Decisions

1. The published-pointer revision is authoritative when present. Its declared
   workflow state's `published` flag determines materialized `status`, and its
   revision id determines the served base-row revision selector.
2. A pointerless record derives the audit expectation from its working-copy
   state, but automatic repair is refused because there is no authoritative
   serving snapshot to rebuild from.
3. Repair republishes the already-selected published revision through
   `EntityRepositoryInterface::setPublishedRevision()`. It never copies the
   working copy or translates a state id into a blind base-row update.
4. Each invocation repairs at most one aggregate. The report fingerprint binds
   the visible selectors and aggregate version; a fresh mutation token supplies
   the repository CAS. A changed aggregate, missing workflow/pointer, unknown
   state, unsupported storage shape, or incomplete read fails closed.
5. Findings contain identifiers, binding and revision/state selectors, current
   and proposed projection metadata, and the confirmation fingerprint. Content
   fields are never serialized.
6. The command is operator-only. It is not called during boot, migration, reads,
   saves, or request handling, and it does not change visibility predicates.

## Invariants

- A draft or review working-copy tip over a live published pointer is valid and
  is not a finding.
- Unbound bundles retain direct status authority and are not audited or repaired.
- Report-only mode performs zero writes.
- A successful repair emits before/after projection evidence and a repeated
  report is clean.
- Recovery begins from a verified database backup; an interrupted or
  post-verification-failed attempt is investigated before any retry.

## Verification evidence

- Real SQLite repository tests cover report, confirmed repair, idempotence,
  missing-pointer refusal, stale/concurrent confirmation refusal, an unbound
  negative control, and a forward-draft negative control.
- Full preflight, exact candidate SHA, packaged-form evidence, split suites, and
  hosted checks will be recorded on the pull request.
