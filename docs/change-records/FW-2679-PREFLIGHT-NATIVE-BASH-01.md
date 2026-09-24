# Preflight Bash gates from a native Windows shell

Status: implementation candidate, second bounded slice of Framework #2679
(program #2676). Base: `4512c0d9afdd2a30c6bb2fbb1e4dec67c98d84ba`. Chosen by
the read-only #2679 audit of `main` at `ec6e47d2e`, which ranked it the
highest-impact remaining gap.

## Problem

`php bin/check-pr-preflight` is the documented publication check ("run this
before claiming gates green"). It runs each manifest command with
`exec("cd <root> && (<command>) 2>&1")`, so on native Windows every gate goes
through `cmd.exe`. From PowerShell or cmd, `cmd.exe` resolves `bash` to
`C:\Windows\System32\bash.exe`, the WSL launcher. 13 of the 45 default gates
begin with `bash`. `docs/specs/governed-gates.md` §8 covered only the pre-push
hook, which starts the runner from Git's `sh`, where `bash` is Git for Windows
Bash.

Audit run from native PowerShell on `ec6e47d2e`, a tree that passed 45/45 from
Git Bash at pre-push: 42 ok and 3 FAIL in 327.1s.

- `check-access-hardening`, `spec-drift` and `changelog-discipline` failed
  because WSL's Git cannot follow the linked worktree's `gitdir: C:/...`
  pointer (`not a git repository`, `base ref 'origin/main' does not resolve`).
- Each printed a repository repair hint ("update the affected spec", "add a
  validated fragment") for what was a host fault.
- The other 10 Bash gates passed only because they ran under WSL Linux, not the
  Git for Windows environment §8 governs.

## Contract

`docs/specs/governed-gates.md` §8:

- `--list` mode is unchanged and still loads no helper and needs no `vendor/`.
- After list mode the runner loads `bin/lib/repository-bash.php`, which owns
  the governed Windows Bash selection (`repository_bash_command()`: Git for
  Windows `bin/bash.exe`, found from the Windows Git executable, which
  `WAASEYAA_SYSTEM_GIT` can pin).
- On native Windows only, and only when a selected gate's command begins with
  exactly `bash` followed by whitespace, the runner resolves that Bash once per
  run. It replaces that leading token with the `cmd.exe`-quoted absolute path
  (for example `"C:/Program Files/Git/bin/bash.exe"`) and leaves the rest of
  the command unchanged.
- If the Bash cannot be resolved, or its path cannot be quoted for `cmd.exe`,
  no gate runs. The runner prints an environmental, actionable message and
  exits with the shared precondition code (3), with no repair hints.
- POSIX command strings and behaviour are unchanged. Gate exit codes, captured
  output, the accumulator, `{base}` substitution, the vendor-freshness
  precondition and the pre-push path are unchanged. A Windows run whose
  selected gates include no Bash gate never resolves a Bash.

## Out of scope

The `hooks_dir` Git-failure fallback, the skeleton `regen-lock` quoting, the
individual Bash-backed Composer scripts, the native-host inventory
reconciliation, raw `bin/git` callers, and the #2678 native Windows CI wiring.
These stay in the #2679 backlog ranked by the audit.

## Verification boundary

`PreflightParityTest` gains two synthetic-manifest cases whose gate arguments
are paths containing spaces.

- `preflight_bash_gates_start_the_host_bash_not_a_path_bash` puts a `bash`
  shadow first on PATH. On native Windows the shadow must never start and the
  gate must report a `MINGW`/`MSYS` `uname`. On POSIX the shadow must still run
  the gate.
- `preflight_fails_closed_before_any_gate_when_windows_bash_is_unavailable`
  pins `WAASEYAA_SYSTEM_GIT` to a missing executable. On native Windows the run
  must exit 3 before its first gate, a PHP gate, runs, with no `repair:` output.
  On POSIX the pin is irrelevant and both gates run.

Both cases fail against the base on native Windows: the shadow starts
(exit 97), and a pinned missing Git still runs every gate. On this host the
resolved executable is `C:/Program Files/Git/bin/bash.exe`, whose path also
contains a space.

`VendorFreshnessPreconditionTest::seedPreflight()` now copies
`repository-bash.php`. Without it, both runnable preflight fixture cases fatal
on the new `require`; `--list` still passes.

WSL2 runs are local Linux evidence, not native Windows evidence. No native
Windows CI job runs the preflight (#2678). Hosted Linux CI qualification of the
exact candidate is still required.
