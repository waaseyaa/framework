# Codex integration note — FW-DELIVERY-COVERAGE-01 / #2904

## Lane ownership

Cursor owns this candidate in leased worktree
`/home/fsd42/dev/waaseyaa-worktrees/fw-2904-coverage-attribution`
(lease `9076692268b11f1d10025a1694f1cc62`, branch `feat/2904-coverage-attribution`).

## This lane already lands (no shared-file edit required)

- `bin/check-changed-php-coverage` — classification report beside the existing 80% ratchet
- `tests/Architecture/ChangedPhpCoverageRatchetTest.php` — regressions for uncovered listing, uninstrumented inflation visibility, CoversNothing excluded-attribution
- `docs/testing/coverage.md` — attribution classes + reproduction summary
- `docs/change-records/FW-DELIVERY-COVERAGE-01.md`
- `changes/unreleased/2904.changed-line-attribution.fixed.md`

`ci/coverage` already invokes `php bin/check-changed-php-coverage …`; the new
stdout/stderr is picked up automatically. **No `.github/workflows/ci.yml` change
is required for the gate text to appear.**

## Optional Codex follow-ups (not blocking this PR)

1. If preflight should surface the same classification without a Clover artifact,
   add a note in shared agent guidance pointing authors at
   `docs/testing/coverage.md` §Attribution classes — Cursor did not edit
   `AGENTS.md` / `CLAUDE.md` / `tools/preflight-gates.json` per wave ownership.
2. Do **not** raise or lower the 80% threshold or php-coverage baseline floors in
   this wave; that needs a separate evidenced policy decision.

## Landing ask

Independent review, then ordinary governed merge when checks are green. Coordinate
with any concurrent #2900/#2901 shared-file landings so this branch only carries
coverage-owned paths.
