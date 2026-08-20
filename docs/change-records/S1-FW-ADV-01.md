# S1-FW-ADV-01 — candidate-bound cross-surface save advisories

- Parent: `256cf702400a8d31b188eaa94cff886b42cf99e9`
- Parent tree: `f6fa8cef35bb9d1c85920e2ee390193106cda7d4`
- Historical parent (pre-rebase): `cf4bd663ae5fa96b11683e48fdd487b6214ee192`, tree `6b31570ffc4c854166099a39da28fda8349b8cfc`
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

## Exception and wire-contract decisions

- `AbortOperationException` remains `final`. The advisory exception is a
  sibling `RuntimeException`, not a subclass, so existing abort catches keep
  their prior semantics.
- Generic Admin owns an allowlisted projection of JSON:API errors. Ordinary
  errors stay status/title/detail; only
  `SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` emits `code` and
  `meta.save_advisories`.
- Framework still does not ship a first-party `SaveAdvisory` producer. The
  accepted use case remains one application `BeforeSaveEvent` listener.

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

### Rebase onto `256cf702`

The evidence above is **historical**: it was produced against the pre-rebase
parent `cf4bd663`, before #2462 (PR #2463) and #2446 reached `main`. It is
retained for provenance and is not the acceptance evidence for this candidate.

Post-rebase state:

- the seventeen #2467 commits replay onto `256cf702` with conflicts only in
  `CHANGELOG.md` and the generated Admin bundle; no source conflict occurred;
- `CHANGELOG.md` orders #2467 above #2462 above #2446;
- at `ec75f8cc9` and `a9c3fb91b` the generated bundle retains the current-`main`
  artifact rather than a merge of hashed chunk files;
- the final bundle conflict is resolved by rebuilding the **combined** bundle
  from the merged `packages/admin/app` source, never by merging hashed files.

Deterministic bundle evidence, rebuilt twice under Node 24.19.0:

- admin dist source signature
  `7386e922e53789b048a58d3b858be48b1897e077e861bd069226f54333907cb3`
  (supersedes the historical
  `60dffea4f60fb51934c650edad2958374ae27afbcb81ceec81ba8300c89ca4a8`);
- committed bundle digest — sha256 over the sorted per-file sha256 of all 97
  files under `packages/admin-surface/dist` —
  `e5ac590bc1ac96b5ef3cf0f8f61d4c7f1381fba4ecc78a1d7c2ffa0ec3b679a1`,
  byte-identical across both builds.

The hermetic pipeline's artifact-scan `evidence` hash is **not** a determinism
signal for the shipped bundle: it inventories the build environment and differs
between otherwise identical runs. The committed-bundle digest above is the
reproducibility claim.

### Source compatibility remediation

The candidate carries one further commit. `ContentDraftMutationInterface` is
restored to its original five-parameter `updateDraft()`; acknowledgement
support moves to `AdvisoryAwareContentDraftMutationInterface`. See §
"Compatibility decision" and `docs/specs/save-advisories.md` §6.
- Admin PHP regression coverage completed with 1,053 tests and 3,817
  assertions; focused Admin frontend advisory coverage completed with 33 tests.
- Publishing and MCP coverage completed with 238 tests and 904 assertions;
  migration coverage completed with 439 tests and 202,223 assertions.
- One intermediate full-suite attempt exposed a stale test-only call to the
  renamed advisory payload accessor; commit `31c84163a` corrected it. A later
  attempt hit an unrelated wall-clock transport assertion once; the exact test
  passed five consecutive focused runs and the final uncontended full run above
  was green.
