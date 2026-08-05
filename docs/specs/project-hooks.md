# Project Hooks

## Purpose

Project hooks provide fast local feedback without duplicating the complete CI
pipeline or silently depending on an undeclared executable. The tracked
`bin/project-hooks` command is the single source of truth.

## Installation contract

`composer hooks:install` writes small `pre-commit` and `pre-push` shims into the
Git hook directory. Each shim resolves the current worktree at invocation time,
then delegates to that worktree's tracked runner. Installation is idempotent.
It may replace this project's marked shims and generated Lefthook shims, but it
must refuse to overwrite an unknown user-owned hook. The obsolete generated
`prepare-commit-msg` hook is removed only when it identifies itself as a
Lefthook shim.

Installation stays manual because linked worktrees share a common hook
directory. `composer hooks:doctor` reports missing, stale, or obsolete shims
with a repair command.

## Gate contract

- Pre-commit runs the code-style check only when PHP files are staged.
- Pre-push runs Composer policy, Symfony import, and package-layer checks in
  sequence. It also reports specification drift, advisory locally and blocking
  in CI.
- Missing required commands fail explicitly with an actionable message.
- The full publication gate remains `composer verify`, followed by CI on the
  exact pushed revision.

## Agent context contract

Claude `SessionStart` runs only for a new startup. It receives at most a short
branch, base, committed-diff, and working-tree summary. Resume, compaction,
clear, and fork events do not rerun specification drift or inject file lists.
Specification review is an explicit task using
`tools/drift-detector.sh origin/main`.
