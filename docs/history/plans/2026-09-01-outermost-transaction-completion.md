# Outermost transaction completion implementation plan

Stable change record: `FW-2734`

1. Add retained-red tests for separate-owner outer commit/rollback and
   per-event failure isolation.
2. Add a completion-aware transaction contract and a per-connection DBAL frame
   coordinator without widening `DatabaseInterface`.
3. Make `UnitOfWork` register token and event drains with the transaction and
   fail before mutation when the contract is unavailable.
4. Replace first-party raw scheduler and schema-coordination outer transactions
   with the managed boundary, and make multi-step publishing reload the
   transaction-visible successor token between claims.
5. Add real fence/repository/cache composition coverage for both SQL layouts,
   update enduring contracts, and add the changelog fragment.
6. Run focused tests, all suites, full preflight, changed-line coverage, and an
   independent outer-rollback/re-entrancy review before publication.
