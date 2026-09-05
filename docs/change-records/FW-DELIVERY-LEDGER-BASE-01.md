# FW-DELIVERY-LEDGER-BASE-01 — Branch and acceptance ledger custody

Status: IMPLEMENTED / QUALIFYING
Forge mirror: #2900; programme #2527; coordination owner #2525.
Initial source: 5bac44286a77ec99abb2a2b53a9cf823298c94a7.

## Problem and authority

Comparing a branch ledger to current origin/main rejects unchanged branch history after another lane appends evidence. Local branch validity and accepted combined-state custody are different boundaries. The immutable v1 schema, original ledger bytes, causality and custody-time rules remain authoritative.

## Design

1. Keep --base=REF as the exact prior-custody comparison, including existing fixture interfaces.
2. Add --branch-base=REF for local worktree validation. Resolve REF and HEAD once to exact commits; require one unambiguous merge-base. Use its schema/ledger as the immutable branch prefix and validate current worktree bytes. Reject malformed options, incompatible modes, unreadable refs and disconnected/ambiguous histories. Do not alter other gates' diff-base policy.
3. Add an immutable acceptance mode to the same checker: candidate commit plus expected base and optional expected PR head. A PR candidate must have exactly the pinned base and head as its two parents, in that order. Validate tracked candidate bytes against tracked expected-base bytes. A push candidate must descend from its declared event.before custody base, including multi-commit pushes; explicit dispatch without event.before validates the candidate transition from its first parent. Dirty worktree bytes cannot replace candidate evidence. Bind expected refs to full SHAs in hosted input.
4. Local preflight roster and Composer alias select branch mode. ci/verify-gates remains required, selects immutable acceptance mode, and supplies the actual checked-out candidate with pinned event base/head. Push retains the exact event.before; explicit commit dispatch uses the checked-out commit and its first parent only where event data is absent. Initial/root or ambiguous history fails closed.
5. Governed native auto-merge pins the intended PR head and requires an enforced strict required-check rule containing ci/verify-gates. GitHub's strict rule at merge time, not an earlier GET, is the atomic protection against base movement. No admin/bypass or generic unpinned fallback. Do not change repository settings. Refuse missing strict protection rather than claim local checking can provide an atomic hosted guarantee.

## Boundaries

This does not eliminate dual-append textual conflicts (#2902), change the v1 ledger/schema, bypass up-to-date branch requirements, or make a changed SHA inherit prior verification. Parent topology identifies the proposed tree; the existing exact-prefix and semantic checks prove that tree's ledger custody. Other source-code merge correctness remains governed by existing checks.

## Proof plan

Real disposable Git histories demonstrate: unrelated main append with source-only branch (old command red, new branch mode green); immutable prefix/schema corruption; dual appends; malicious merged resolution dropping a main row; exact parent/head mismatch; moved input refs; missing/disconnected/ambiguous ancestry; immutable candidate validation despite dirty local files. Preserve existing checker adversarial self-tests. Exercise the actual workflow adapter inputs and pinned merge invocation, then full preflight and split suites on the final candidate.

## Independent design review

The independent reviewer accepted separate branch and custody boundaries. It required attribution of moved-base safety to strict native merge enforcement and removal of the old unpinned catch-all merge fallback. These constraints are included above.

## Delivery evidence

Implementation, exact-head results and independent review evidence will be recorded here before publication; hosted identities are supplemental locators. No release or deployment is part of this work.

## Implementation evidence

The regression uses real Git histories: legacy exact-main validation rejects an
unchanged older branch, while the new merge-base mode accepts it. The original
checker rejects the new option (recorded RED); implementation then passes the
branch, immutable candidate, dual-append, corrupted-prefix/schema, causal/ID,
ancestry/topology, missing-authority, and dirty-worktree controls. Focused old
and new ledger suites: 21 tests, 319 assertions. Executable workflow/merge
adapter fixtures: 2 tests, 38 assertions. Initial full preflight: 41 gates pass;
final committed-candidate qualification follows after the last fixture additions.

Independent review verified the actual gh behavior: --auto on an already-clean
PR preserves expectedHeadOid and merges without a shell fallback. The effective
rules endpoint is available with metadata-read access. Bootstrap limitation:
this change lands using the currently accepted trusted-main workflow; the new
helper is available to governed workflow executions after it lands. No claim is
made that this PR's bootstrap executes the new trusted-main helper.

Independent hostile review found a shallow-history bypass: hiding one arm of a
criss-cross made two merge-bases appear unique. Both new history modes therefore
refuse shallow repositories. The original candidate's full-suite runs are
diagnostic only; final qualification binds the corrected committed candidate.

Full Architecture qualification also caught a fixture cleanup-policy violation.
The fixture now uses Symfony Filesystem instead of a custom recursive remover;
the cleanup contract and history fixtures pass together (19 tests, 372 assertions).
The production checker is unchanged by this test-only correction.
