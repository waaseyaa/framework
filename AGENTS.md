# Waaseyaa Agent Rules

- `CLAUDE.md` is authoritative. Read it, the relevant `docs/specs/` contracts, and `docs/specs/workflow.md`; do not run retired Spec Kitty commands.
- Substantive work is design-first and anchored to its GitHub issue. One PR per work package; titles and bodies must be `#N`-traceable.
- Use TDD: add the regression test first and run it to prove red before implementing.
- Every PR adds an appropriate `CHANGELOG.md` `[Unreleased]` entry.
- Acknowledge reviewed spec drift up front with `spec-reviewed:` commit trailers; the blocking CI spec-drift check reads commit trailers.
- Respect package layers. Symfony imports use the existing checker allowlist convention; any exception belongs in the explicit allowlist with a one-line rationale.
- Run repository Git commands through `bin/git`; it mechanically refuses `git stash` for all agents and subagents. If a rebase needs a clean tree, commit on a temporary branch. Do not touch the dangling `da4d26758` stash.
- Run the split Unit suite with `php -d memory_limit=1G ./vendor/bin/phpunit --testsuite Unit --no-coverage`; do not run the whole suite as one process. The root configuration declares a coverage report, so omitting `--no-coverage` on a machine without a coverage driver stops at a runner warning before any tests execute.
- Run local gates under `set -o pipefail`. Local results are advisory; GitHub CI is authoritative.
- Never merge to `main` while a release split/fan-out is running.
- Merge PRs only via the `auto-merge-when-green` label, which enables native auto-merge after all five required checks pass.
- Keep changes paired with boundary-level tests; update specs only when architecture or enduring contracts change.
