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

Both Composer scripts start `bin/project-hooks-launcher` with Composer's PHP
rather than a bare `bash`, because Composer runs a script line through the host
shell and on native Windows `cmd.exe` resolves `bash` to
`C:\Windows\System32\bash.exe`, the WSL launcher. The launcher runs the runner
from the repository root with the host's supported Bash
(`repository_bash_command()` in `bin/lib/repository-bash.php`) and returns its
exit status unchanged:

- POSIX hosts use `bash` from `PATH`.
- Native Windows uses `bin/bash.exe` of the Git for Windows installation that
  owns the Windows Git executable (`repository_git_command()`, so
  `WAASEYAA_SYSTEM_GIT` applies), found from `git --exec-path`. That is the
  shell Git runs the installed shims with. The launcher never falls back to
  `PATH` there: without Git for Windows Bash it exits 1 with a repair message.

The runner treats a drive-letter hook directory (`C:/...`), which Git for
Windows reports for a linked worktree's common hook directory, as absolute.
Proof: `tests/Architecture/ProjectHooksLauncherTest.php`; change record
`docs/change-records/FW-2679-HOOKS-WINDOWS-LAUNCHER-01.md`.

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
