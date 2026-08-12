# S1-FW-DB-02 — schema mutation authority

- Parent: `17bb866242c63885b6d6cc5373098d0ddb7f4e22`
- Parent tree: `2512f6abe4ca1f39378fc972e8202e90a8857c63`
- Contract: `docs/specs/s1-schema-authority.md`
- Findings: `F-DB-006` through `F-DB-010`
- Authority: local source changes only; forge-neutral

## Sequence

1. Commit this contract and stable change record.
2. Commit executable retained-red failures without rewriting history.
3. Implement the coordinator, unique ledger, strict verification, and
   read-only/runtime refusal boundary in reviewable slices.
4. Mechanically classify the complete DDL surface.
5. Prove exact installed form and reconcile independent review.

## External interlock

No issue, pull request, hosted check, forge API, merge, split, publication,
release, deployment, production operation, backup, restore, or recovery action
is authorized by this record.
