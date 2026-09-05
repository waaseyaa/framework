# FW-DELIVERY-EVENT-BATCHES-01 — immutable agent-event batches

- Status: **design review** (do not implement the batch contract until Codex
  accepts the migration/ordering design below)
- Parent programme: Framework #2527
- Forge mirror: Framework #2902
- Depends on: #2900 / PR #2907 (landed)
- Coordinates with: #2869 projection (`FW-DELIVERY-TELEMETRY-02`)
- Worktree lease: `6f57f3a75066e7c8eaed54cf51681b4c` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2902-event-batches`
- Design-start head: `a0dbd5353dfc3ef6aaf38d61afb6a969f54c0485`
- v1 ledger SHA-256 at design start:
  `0c8be52156201fa1f3c35d0261fdc91446ba8a49e491020bdba106a2f011c38f`

## Intent

Even after merge-base branch validation (#2900), concurrent evidence publishers
still contend on one append-only JSONL tail. Dual appends force textual merges,
rebases, and requalification. Replace the hotspot with **independent immutable
batch files** and a **deterministic complete-set projection**, while keeping
every accepted v1 byte and event ID intact.

## This candidate

| Deliverable | Path |
| --- | --- |
| Change record | this file |
| Migration / ordering design for Codex | `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-design.md` |
| Codex review ask | `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-codex-review.md` |
| #2869 projection coordination | `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-2869-coordination.md` |
| Deferred CI/preflight patch stub | `docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-ci-patch.md` |
| Proposed contract sketch (not LIVE) | `docs/specs/delivery-agent-event-batches.md` |
| Dual-append contention fixture | `tests/Architecture/DeliveryAgentEventBatchContentionFixtureTest.php` |
| Ordering-independent adversarial fixtures | `tests/Architecture/DeliveryAgentEventBatchAdversarialFixtureTest.php` |
| Complete-set helper (no replay order) | `bin/lib/delivery-agent-event-set.php` |

**Still deferred until Codex accepts ordering:** freeze enforcement wired into
`bin/check-delivery-agent-events`, batch schema as a LIVE gate, `ci.yml` /
`preflight-gates.json`, and `bin/project-delivery-agent-events` changes.

## Decisions locked for design review

1. Freeze accepted v1 JSONL bytes; never rewrite, retimestamp, or renumber.
2. New evidence is new uniquely named batch files only — no shared mutable index.
3. Complete-set rules: event-ID uniqueness, causal closure, single adjudication,
   closed event semantics, refuse cycles / missing causes / conflicting duplicates.
4. Deterministic total order over the union without inventing occurrence times;
   do not blindly apply v1 line-order to batches (see design doc).
5. Acceptance of two non-conflicting batches is commutative.
6. Shared CI/preflight wiring is a **separate** Codex integration patch after
   the contract is accepted.
7. Batch-aware projection lands in coordination with #2869 after this contract
   settles — not as a silent rewrite of TELEMETRY-02.

## Verification (design slice)

- Architecture contention fixture green (documents today’s dual-append failure).
- No production gate behaviour change in this PR.
- Codex design review required before implementation PR.
