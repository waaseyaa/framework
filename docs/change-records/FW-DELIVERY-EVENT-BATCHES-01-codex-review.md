# Codex review ask — FW-DELIVERY-EVENT-BATCHES-01 / #2902

> Historical design-review request. The accepted design and current integration
> are recorded in `FW-DELIVERY-EVENT-BATCHES-01.md` and
> `FW-DELIVERY-BATCH-PROJECTION-01.md`. The original request below is retained
> as history; it does not describe the current implementation state.

## Original request before implementation

Cursor leased worktree
`/home/fsd42/dev/waaseyaa-worktrees/fw-2902-event-batches`
(lease `6f57f3a75066e7c8eaed54cf51681b4c`, branch `feat/2902-event-batches`).

This candidate is **design-only**. Do **not** treat it as authority to implement
or to edit shared CI/preflight files.

### Read

1. `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01.md`
2. `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-design.md` ← **migration + ordering**
3. `docs/specs/delivery-agent-event-batches.md` (proposed, not LIVE)
4. `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-2869-coordination.md`
5. `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-ci-patch.md` (deferred)

### Verdict needed

- Accept / request changes on the total-order key and freeze semantics.
- Confirm complete-set uniqueness / causality / adjudication rules.
- Confirm commutativity proof shape for the later implementation PR.
- Confirm CI/preflight stays a **separate** patch after contract settle.
- Confirm #2869 projection identity changes wait on this contract.

Reply on the PR or issue with an explicit accept or change list. Implementation
starts only after that.
