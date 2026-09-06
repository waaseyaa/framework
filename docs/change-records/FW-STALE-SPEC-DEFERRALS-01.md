# FW-STALE-SPEC-DEFERRALS-01 — live spec deferrals to closed issues

- Parent: `origin/main` at lease (`e5015e92421783f128609186e79507199d9a81e9`)
- Contract: `docs/specs/workflow.md`, `docs/specs/ci-test-selection.md` §7.2, `docs/specs/governed-gates.md`
- Forge mirror: Framework #2641
- Authority: nightly warn-only scan of live `docs/specs/` prose only

## Finding

`tools/drift-detector.sh` is a PR-diff coupling check. A spec whose stated
dependency closed in another change never appears in a later diff, so live
prose can defer current capability to a closed issue indefinitely. Naive
"every `#NNNN` must be open" is ~88% closed-and-correct provenance. The
useful residue is present/future-tense deferral phrasing in body prose.

## Decision

1. Scan `docs/specs/**/*.md` body prose only. Blank `<!-- Spec reviewed -->`
   blocks and CommonMark backtick or tilde fenced examples so append-only
   review logs and samples stay dated-wrong on purpose. Fence tracking honors
   opener length and treats an unclosed fence as extending to end of file.
2. Match a narrow deferral vocabulary. Strong phrases (`blocked on`,
   `waits for`, `tracked by`, `tracked in`) may have up to 60 characters
   before `#NNNN` without crossing a newline or sentence terminator.
   `deferred to` / `pending` / `until` / `after` / `once` must be adjacent to
   the hash so "deferred to a follow-up PR (mission #1257)" and positional
   "after routers (#1129)" do not fire. `after #N` used as provenance ("the
   listener uses after #1856") is dropped.
3. Drop a match when its local subclause contains a past-tense verb. Historical
   wording before a comma or other subclause boundary does not suppress a live
   present-tense deferral. Query-string `?page=` is not a sentence boundary.
4. Flag `ISSUE-CLOSED` only. `PR-MERGED` is always fine.
5. Warn-only with `tools/stale-spec-deferrals-baseline.txt` (path:line plus
   mandatory reason). Incomplete entries fail closed. Promote to
   `--fail-on-findings` only once that allowlist stops growing.
6. Host the scan on `.github/workflows/nightly.yml` as
   `nightly/stale-spec-deferrals`. Do not add it to
   `tools/preflight-gates.json` or `bin/check-pr-preflight`. Architecture
   tests inject an issue-state snapshot so they stay offline.
7. Never scan `docs/history/plans/` or `kitty-specs/`.

## Sequence

1. Fixture Architecture tests for the true-positive / false-positive split.
2. Implement `bin/check-stale-spec-deferrals` and the empty allowlist.
3. Wire the warn-only nightly job; keep `nightly/random-order-full` intact.
4. Stamp the affected specs and record this change.

## Boundaries

No merge-gate promotion, no Packagist/tag/ruleset mutation, no scan of
session-history trees, and no change to the unsharded random-order proof.
