# S1-FW-ADV-01 — candidate-bound cross-surface save advisories

- Parent: `cf4bd663ae5fa96b11683e48fdd487b6214ee192`
- Parent tree: `6b31570ffc4c854166099a39da28fda8349b8cfc`
- Contract: `docs/specs/save-advisories.md`
- Issue mirror: `waaseyaa/framework#2467`
- Consumer blocker: `jonesrussell/sheguiandah-waaseyaa#111`
- Authority: source, tests, documentation, branch, commits, push, and draft
  review candidate only

## Outcome

Applications can declare one bundle-aware `BeforeSaveEvent` policy and return
a typed field advisory consistently through JSON:API, Generic Admin,
publishing/MCP create/update, and canonical migration create/update. The first
attempt performs no write; exact candidate-bound acknowledgement allows the
reviewed candidate while validation and all other save guards remain intact.

## Sequence

1. Commit this contract, implementation plan, and stable change record.
2. Commit retained-red primitive/event/repository tests without rewriting
   history.
3. Implement the entity-storage DTO, token, gate, exception, `SaveContext`
   acknowledgement set, and original-entity event seam.
4. Commit retained-red JSON:API and Generic Admin propagation tests, then the
   adapters and accessible confirmation flow.
5. Commit retained-red publishing/MCP tests, then structured propagation and
   input schemas.
6. Commit retained-red migration tests, then explicit declaration, one-retry
   acknowledgement, and bounded run evidence.
7. Prove one fixture policy across every surface, run split test/static gates,
   preflight the exact candidate, and update this record with evidence.

## Compatibility decision

Ten populated Sheguiandah representative/rehearsal databases contain the same
six intentional route-backed page slugs: `services`, `employment`, `news`,
`events`, `login`, and `members`. Blanket rejection would make those records
unsaveable. This change provides accept-with-explicit-acknowledgement and lets
the app distinguish unchanged legacy state from a new collision.

## Interlocks

This record authorizes no merge, tag, release, split-package fan-out,
publication, deployment, repository-setting change, production operation,
backup, restore, or recovery action. GitHub is only a tracking/review adapter;
the versioned contract, exact Git objects, and local verification are the
portable authorities.

## Verification evidence

- `composer verify` at implementation commit `31c84163a` completed successfully:
  formatting, PHPStan (2,402 files), repository architecture/policy/security
  gates, OpenAPI (0 errors; 9 existing warnings), generated Admin distribution
  freshness, and PHPUnit (14,033 tests, 265,183 assertions, 1 skip).
- Governed Admin production build completed with 263 artifacts and distribution
  signature
  `60dffea4f60fb51934c650edad2958374ae27afbcb81ceec81ba8300c89ca4a8`.
- Admin PHP regression coverage completed with 1,053 tests and 3,817
  assertions; focused Admin frontend advisory coverage completed with 33 tests.
- Publishing and MCP coverage completed with 238 tests and 904 assertions;
  migration coverage completed with 439 tests and 202,223 assertions.
- One intermediate full-suite attempt exposed a stale test-only call to the
  renamed advisory payload accessor; commit `31c84163a` corrected it. A later
  attempt hit an unrelated wall-clock transport assertion once; the exact test
  passed five consecutive focused runs and the final uncontended full run above
  was green.
