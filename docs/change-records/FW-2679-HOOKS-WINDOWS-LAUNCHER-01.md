# Composer hook scripts on native Windows

Status: implementation candidate, first bounded slice of Framework #2679
(program #2676).

## Problem

`composer hooks:install` and `composer hooks:doctor` were the script lines
`bash bin/project-hooks install|doctor`. Composer runs a script line through
the host shell. From native PowerShell or cmd, `cmd.exe` resolves `bash` to
`C:\Windows\System32\bash.exe`, the WSL launcher. WSL's Linux Git runs against
the `/mnt/c` view of the checkout, where a linked worktree's
`gitdir: C:/...` pointer does not resolve:

| Invocation (linked worktree) | Observed on the base |
|---|---|
| `composer hooks:doctor` | `fatal: not a git repository: /mnt/c/.../C:/dev/.../.git/worktrees/...`, then both hooks reported missing, exit 1 |
| `composer hooks:install` | Exit 0, `Project hooks installed in /mnt/c/.../linked/`: the shims were written into the worktree root and nothing reached the common hook directory |
| `composer hooks:doctor` after that install | `Project hooks are installed and current.`, exit 0, approving the stray shims |

In a non-linked checkout the WSL path happened to work, but only through WSL's
own Git.

The supported Windows path had a second defect. Under Git for Windows Bash,
`git rev-parse --git-path hooks` reports a linked worktree's common hook
directory as a drive-letter path (`C:/.../.git/hooks`). `hooks_dir` treated
anything not starting with `/` as relative and prefixed the worktree root, so
from a linked worktree `hooks:doctor` reported installed hooks as missing and
`hooks:install` exited 0 after creating a nested `C:/Users/...` directory tree
inside the worktree.

## Contract

`docs/specs/project-hooks.md` ("Installation contract"):

- The Composer scripts are `@php bin/project-hooks-launcher install|doctor`.
  Composer starts the PHP launcher with its own PHP binary, not through a shell
  word the host resolves.
- The launcher starts the unchanged `bin/project-hooks` runner with
  `repository_bash_command()` (`bin/lib/repository-bash.php`): `bash` from
  `PATH` on POSIX hosts, exactly as the old script line did; on native Windows,
  `bin/bash.exe` of the Git for Windows installation that owns the Windows Git
  executable (`repository_git_command()`, so `WAASEYAA_SYSTEM_GIT` applies),
  found from `git --exec-path`. That is the shell Git uses to run the installed
  shims. It never falls back to `PATH` on Windows. Without Git for Windows Bash
  it exits 1 with a repair message.
- The launcher accepts exactly one action, `install` or `doctor`, and rejects
  anything else with a usage message and exit 1. The Git hook shims keep
  starting `bin/project-hooks` directly.
- The launcher runs the runner from the repository root and returns its exit
  status unchanged. The runner writes to the inherited stdout and stderr, so
  the launcher buffers nothing, and its stdin is the null device. After 60
  seconds the launcher stops the runner and exits 1.
- The `git --exec-path` probe starts Git only through `repository_git_command()`.
  It has a 10-second deadline, null stdin and stderr, and a stdout capture file
  instead of a pipe. It reads back at most 4096 bytes, and larger output is
  rejected rather than truncated. A probe that cannot start, fails, overflows,
  or times out selects no Bash.
- Both subprocesses use `repository_wait_for_child()`. At the deadline it sends
  terminate, waits up to two seconds, sends kill, and waits up to two more. It
  signals only the direct child, and a failed cleanup never replaces the
  deadline result with an exit code.
- `hooks_dir` treats a drive-letter path (`^[A-Za-z]:/`) as absolute. POSIX
  paths never match it, so Linux resolution is unchanged.

Unchanged: the shim contents and marker, the pre-commit and pre-push gates
(`bin/check-pr-preflight` stays blocking), the Claude session context, the
refusal to replace unknown hooks, and the native-host classification of the
hook scripts as host-specific internal automation.

## Out of scope

Other Bash-backed Composer scripts and `bin/git` callers (including the
`where git` Bash lookup in `tools/check-surface-parity.php` and
`tools/lib/SurfaceDeclarations.php`), symlink fixtures, Composer colour
handling, extension setup, cross-drive `cd`, and the #2678 native Windows CI
wiring. `hooks_dir` still falls back to the worktree root if Git itself fails,
which only a Bash with a Git that cannot read the checkout (the WSL path this
slice removes) reached. It is recorded for a later slice.

The `bin/` partition in `docs/specs/native-host-support.md` counts 93 entries
"at this contract revision". The base already tracks 102. This slice records
the new launcher on the hooks row and leaves the re-inventory to the #2679
audit.

## Residual #2679 work

- `tests/Architecture/ProjectHooksTest.php` is not green on native Windows.
  `installer_is_idempotent_and_preserves_unknown_hooks` and
  `claude_context_is_bounded_and_works_from_a_subdirectory` start
  `../bin/project-hooks` and `../../bin/project-hooks` through PHP `exec()`,
  which uses `cmd.exe`, and fail with `'..' is not recognized as an internal or
  external command`. This slice does not touch that test or those commands, so
  the failure predates it. Porting the test is separate work.
- A launcher or probe that stops at its deadline signals only its direct
  child. Processes the runner started itself are not tracked.
- `hooks_dir`'s fallback to the worktree root when Git fails, noted above.

## Verification boundary

`tests/Architecture/ProjectHooksLauncherTest.php` fails against the base and
passes after the change on native Windows. Its end-to-end case runs the real
Composer scripts from a disposable linked worktree, with a `PATH` `bash` shadow
standing in for the WSL launcher. The Windows cases of the host rule run on
every host against a simulated installation. That is not native Windows
evidence. Reverting only the `hooks_dir` change fails the end-to-end case on
native Windows.

The exec-path probe case is a discriminator for the Git entrypoint. On native
Windows, a `WAASEYAA_SYSTEM_GIT` pinned to a missing executable must make the
probe and the host rule fail closed, after an unpinned positive control reaches
Git for Windows. On POSIX, the probe must return the answer of a fixture
`bin/git` adapter. Changing the probe to a bare `git` fails the case on native
Windows and under WSL2. The bounded-subprocess cases use PHP children on every
host. Removing termination at the deadline fails the stop case on native
Windows, because the abandoned child survives and writes its marker.

Under WSL2, as local Linux evidence, the end-to-end case shows POSIX hosts still
take `bash` from `PATH`. `ProjectHooksTest` passes all five cases there.
`claude_context_is_bounded_and_works_from_a_subdirectory` needs `GIT_DIR` and
`GIT_WORK_TREE` pointed at the Linux view of this Windows worktree, plus
`GIT_OPTIONAL_LOCKS=0`, because WSL's Git cannot follow its `gitdir: C:/...`
pointer.

No native Windows CI job runs these scripts (#2678). Hosted Linux CI
qualification of the exact candidate is still required.
