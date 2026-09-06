# FW-PROJECT-BOARD-SYNC-01 — Framework roadmap synchronization

- Parent: `b701d5ad4693f6f91d3e9c298b706c19ebd77f02`
- Contract: `docs/specs/project-board-synchronization.md`
- Forge mirror: `waaseyaa/framework#2667`
- Authority: repository issue labels and milestones, exact Project snapshot,
  reviewed plan identity, tests, and review evidence

## Outcome

Provide a report-first synchronizer for the live Framework roadmap that can
detect missing open issues and field drift without treating planning fields as
repository authority. Any mutation must come from an exact reviewed plan and a
fresh-state match.

## Decisions

1. Issue labels own readiness and priority; issue milestones own delivery lane.
   Project fields mirror those values. Roadmap Stage remains a distinct
   board-owned delivery-ordering axis.
2. Gate membership, readiness, delivery lane, delivery ordering, and release
   claim are independent. No synchronizer operation writes one from another.
3. The beta safety rule is one-way validation: an open beta blocker outside
   Stage 0 is drift; a Stage 0 non-blocker is correct.
4. Missing open issues are planned for intake. Closed issues remain as board
   history, become Done, and have readiness cleared. Missing closed issues are
   not re-added.
5. Missing readiness maps to Needs Triage. Conflicting readiness or priority
   carriers are reported and receive no guessed field operation.
6. Plans bind Project, field and option identities plus all relevant source
   state. Live collection proves bounded completeness; application re-reads and
   refuses stale plans.
7. Apply requires a new durable receipt, records each successful operation,
   and reports partial failure without permitting blind replay through the same
   receipt path.

## Work packages

1. Record the authority and mapping contract.
2. Add fixture proofs for intake, closure, mappings, independent axes,
   ambiguity, and stale plan/field identity refusal.
3. Implement read-only audit, deterministic plan, verify, and explicit apply.
4. Validate against a live read-only snapshot without applying it.

## Residual operational scope

Automatic scheduling requires an organization-Project read/write credential
whose secret name, custody, and rotation policy are not currently declared in
the repository. This candidate does not invent that authority or reuse a
release credential. Wiring the scheduled audit/apply path remains explicit
follow-up until that credential contract is reviewed.
