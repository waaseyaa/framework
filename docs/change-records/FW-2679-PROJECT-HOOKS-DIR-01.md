# Fail-closed project-hook directory resolution

Status: implementation candidate, third bounded slice of Framework #2679
(program #2676). Base: `eafd7a3fb25290c9afeb871e7c49b5404629e413`. Ranked
second by the read-only #2679 audit.

## Problem

`bin/project-hooks` takes the hook directory from
`git rev-parse --git-path hooks`. It joins anything that is not absolute to
the checkout root, and it never checked whether Git succeeded or what it
answered. The following was reproduced on the base through the Composer
entrypoint (`php bin/project-hooks-launcher`) in a disposable checkout on
native Windows.

| Git's answer | Controlled by | `hooks:install` | `hooks:doctor` afterwards |
|---|---|---|---|
| failure (`fatal: not a git repository`, exit 128) | `GIT_DIR` pointing at a missing directory | exit 0, `pre-commit` and `pre-push` written into the checkout root | exit 0, "installed and current" |
| nothing, exit 0 | a `git` function loaded through `BASH_ENV` (real Git never answers nothing) | exit 0, shims in the checkout root | exit 0, "installed and current" |
| two lines (`hooks` / `second`) | `core.hooksPath` containing a newline | exit 0, a directory named `hooks⏎second` created in the checkout root, shims inside it | exit 0, "installed and current" |

Each unusable answer therefore resolved into the working tree, and doctor
approved the result.

Building the tests exposed a related gap. Git for Windows echoes
`core.hooksPath` as configured, so a value in native spelling comes back as
`C:\...`. The drive-letter check from #3148 accepted only `C:/...`, so the
runner joined that valid absolute answer to the checkout root as well.

## Contract

`docs/specs/project-hooks.md` ("Installation contract"):

- If `git rev-parse --git-path hooks` fails, answers nothing, or answers more
  than one line (any `\n` or `\r`), `install` and `doctor` exit 1 before
  creating or changing anything. Stderr carries
  `project-hooks: could not resolve the Git hook directory: ...` with the cause
  and a next step, after any message Git printed itself.
- A drive-letter answer (`^[A-Za-z]:[\/]`) is absolute, in either separator
  spelling. `/`-rooted answers are absolute. Relative answers are joined to the
  checkout root as before.
- Both callers propagate the failure explicitly (`|| exit`) rather than
  relying on `set -e`.
- Unchanged: normal-checkout and linked-worktree resolution, shim contents and
  marker, the unknown-hook refusal, doctor's missing/stale reporting, and every
  other action, message and exit code.

## Out of scope

Raw `bin/git` convergence, the skeleton `regen-lock` quoting, other
Bash-backed Composer scripts, the two native-Windows `ProjectHooksTest`
failures, the native-host inventory, and #2678.

## Verification boundary

`tests/Architecture/ProjectHooksDirectoryTest.php` runs the launcher from a
disposable checkout with isolated Git configuration.

- Six cases cover the three unusable answers for both actions. Each must exit 1
  with the named stderr and leave a byte-identical checkout snapshot.
- Four preservation controls cover a relative answer in a normal checkout, the
  common directory from a linked worktree, and an absolute `core.hooksPath` in
  native and forward-slash spelling.

Against the base on native Windows, the six unusable-answer cases and the
native-spelling control fail, and the other three controls pass. After the
change all ten pass. Removing any single guard (failure, empty, multi-line)
fails exactly that guard's two cases.

WSL2 runs are local Linux evidence. Hosted Linux CI qualification of the exact
candidate is still required, and no native Windows CI job runs these scripts
(#2678).
