# FW-DELIVERY-COVERAGE-01 — make changed-line coverage attribution explicit

- Status: review
- Parent programme: Framework #2527
- Forge mirror: Framework #2904
- Worktree lease: `9076692268b11f1d10025a1694f1cc62` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2904-coverage-attribution`
- Design-start head: `5bac44286a77ec99abb2a2b53a9cf823298c94a7`

## Intent

Operators treating CI shard Clover as “integration ran, therefore lines are
covered” hit friction around #2780 / PR #2885 and the sibling #2886 lane.
Integration suites already execute in the PCOV shards, but PHPUnit metadata and
process boundaries can leave executed behavior out of the changed-line signal.
This change makes the gate report what the number means — covered, uncovered,
metadata-excluded attribution risk, and uninstrumented — without percentage-
driven duplicate tests and without moving the 80% threshold.

## Reproduction (exact local PCOV 1.0.12 on PHP 8.5.8)

Synthetic fixture under `/tmp/fw2904-repro` with identical `Greeter::greet()`
branches:

| Run | Metadata | Clover stmt counts on greet body |
| --- | --- | --- |
| Integration-shaped test | `#[CoversNothing]` | all `count="0"` despite assertions passing |
| Unit companion | `#[CoversClass(Greeter::class)]` | stmts `count="1"` |
| Subprocess child execution | `#[CoversClass]` on parent test | stmts `count="0"` (child process not instrumented) |
| No covers attribute | bare test | attributed normally |

Changed-line gate inflation probe (`bin/check-changed-php-coverage`):

- Diff changes `Greeter.php` (1 covered clover stmt) and `ChildOnly.php`
  (absent from Clover entirely).
- Gate reported `1/1 … 100.00%` and exited 0 — the clover-absent file never
  entered the denominator.

Conclusions established by evidence (not hypothesis):

1. **Metadata-excluded:** `#[CoversNothing]` suppresses attribution even when
   the production path ran in-process.
2. **Uninstrumented / invisible denominator:** changed source absent from Clover
   is silently omitted, which can inflate the percentage.
3. **Subprocess:** parent-process Clover still lists the file (uncovered stmts),
   so the gap presents as uncovered rather than missing unless the file is
   outside the coverage include set.

## Decisions

1. Keep the blocking threshold at **80% of changed executable Clover stmts**
   (`covered / (covered + uncovered)`). Do not change floors or invent a new
   required percentage in this slice.
2. Extend `bin/check-changed-php-coverage` to classify every changed executable
   line (static analysis ∩ diff) as **covered**, **uncovered**, or
 **uninstrumented** (executable + changed but absent from Clover), and print
   that inventory beside the existing percentage.
3. Treat **excluded** as an attribution-risk label: uncovered changed lines
   whose only changed test companions are `#[CoversNothing]` (same signal as
   `bin/check-covers-nothing-companions`). Visible in the gate report; does not
   by itself alter the percentage formula.
4. Retain intentional `#[CoversNothing]` on structural/orchestration tests.
   Behavioral branches still need `#[CoversClass]` / `#[CoversMethod]` Unit or
   Contract companions — documented, not blanket-removed.
5. Prefer reporting on the existing shard-merged Clover over adding another full
   suite run.
6. Shared files (`.github/workflows/ci.yml`, `tools/preflight-gates.json`, shared
   agent guidance) stay with Codex for this wave. This lane owns coverage
   tooling, Architecture tests, `docs/testing/coverage.md`, and the changelog
   fragment; any CI/preflight wiring is supplied as an integration note.

## Work packages

1. Change record + reproduction evidence (this document).
2. Failing Architecture fixtures for classification / inflation / CoversNothing
   exclusion visibility.
3. Implement reporting in `bin/check-changed-php-coverage`.
4. Update `docs/testing/coverage.md` with the four-way classification and
   limitations.
5. Exact-head PCOV verification on the review candidate; Codex landing handoff.

## Verification (candidate `26e60426be3df95c830f918a300b6cdec0372873`)

- Reproduction: PCOV 1.0.12 / PHP 8.5.8 Greeter fixture — CoversNothing stmts
  `count="0"`; CoversClass attributed; subprocess child unattributed.
- Inflation probe: clover-absent changed file → legacy gate `1/1 100%`; new gate
  prints `uninstrumented:` and the denominator warning.
- `./vendor/bin/phpunit --filter 'ChangedPhpCoverageRatchetTest|CoversNothingCompanionDiagnosticTest' --no-coverage` — 11 tests OK.
- PCOV on exact head: `php -d extension=…/pcov.so ./vendor/bin/phpunit --filter ChangedPhpCoverageRatchetTest --coverage-clover build/logs/clover-2904.xml` — 9 tests OK.
- `php bin/check-changed-php-coverage --clover=build/logs/clover-2904.xml --base=origin/main` — `no changed PHP source lines` (candidate touches `bin/` + docs + Architecture tests only).
- `php bin/check-pr-preflight` — green.

## Boundaries

No threshold change, no ruleset edit, no release/deploy, no blanket
`CoversNothing` removal, no superficial line-touching tests, no edits to the
untracked cookbook outside this worktree, and no edits to other agents’
worktrees or to still-coordinated shared CI/preflight files without Codex.
