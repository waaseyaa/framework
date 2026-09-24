# FW-2678-NATIVE-HOST-CONTRACT-01 — required native-host contract on Linux and Windows

- Status: review candidate
- Issue: #2678 (first slice); parent program #2676
- Contract: [`docs/specs/native-host-support.md`](../specs/native-host-support.md)
- Base: `6359a442829d9bfdc6dfe7dc49b41872e88b4eb6`
- Integration and review owner: Codex. Implementation: Claude.
- Landing: not authorized by this record; Russell approves each landing.

## Custody record (manual Windows coordinator exception)

| Field | Value |
|---|---|
| Custody type | Manual Windows coordinator exception |
| Custody ID | `38e4b653-6edf-4ce0-9b8d-7f41251e655c` |
| Canonical worktree path | `C:/dev/waaseyaa/framework-worktrees/native-host-contract-2678` |
| Branch | `claude/native-host-contract-2678` |
| Base SHA | `6359a442829d9bfdc6dfe7dc49b41872e88b4eb6` (verified as `origin/main` immediately before creation) |
| Owner | Claude |
| Integration/review owner | Codex |
| Created | 2026-09-24T22:06:57Z |

The mandatory `bin/worktree-coordinator` lease could not be acquired on the
native Windows host. The coordinator accepts only paths that begin with `/`
and starts the Bash `bin/git` adapter, which Windows CreateProcess cannot run.
Under WSL, Git reports every Windows-created worktree as a `C:/...` path marked
prunable, so `lease acquire` would reject the new worktree as unregistered. No
coordinator registry or lease file was written, fabricated or hand-edited:
`.git/waaseyaa-worktree-leases.json` does not exist in this clone. The
worktree was created detached at the exact base with native Windows Git
(`C:\Program Files\Git\cmd\git.exe`, 2.47.0), the branch was created inside
it, and dependencies were installed only inside the candidate. No other
worktree, branch or recovery ref was changed, and WSL Git was never used to
change this repository. Windows support for the coordinator is separate debt.

## Outcome

Pull requests cannot pass `merge/platform-runtime-acceptance` unless the
supported native-host contract ran successfully on native Linux and native
Windows and produced a validated evidence record for each host.

## Design

- **One contract.** [`tools/native-host-contract.json`](../../tools/native-host-contract.json)
  lists every command as a complete argument array, the host tuples, the
  runtime ranges, the PHP extension set and, for each PHPUnit command, the
  exact test methods it must execute.
- **One matrix.** `ci.yml#native-host-contract` runs every contract command in
  order, one step each, on `ubuntu-24.04` and `windows-2025`. Both leaves run
  the same step text under `pwsh`. Composer is pinned to the 2.10 line
  through setup-php. Each step records its native exit code immediately after
  the command.
- **Strict PHPUnit.** Every PHPUnit command passes `--fail-on-skipped`,
  `--fail-on-incomplete` and `--fail-on-empty-test-suite`, and keeps JUnit and
  OTR logs. JUnit folds incomplete tests into `skipped`; OTR reports them as
  ABORTED, so the collector reads both and requires them to agree.
- **Evidence, not orchestration.** `bin/native-host-evidence collect` runs no
  contract command. It receives only explicit `<id> <outcome> <exit code>`
  lines for the governed steps and rejects missing, duplicate, unknown,
  skipped or non-success steps. It then records and validates the evidence
  listed below. `verify-set` accepts exactly one passing record per contract
  host, bound to the verifier's checkout, the contract digest and the workflow
  run. On Windows the Composer shim is resolved on `PATH`/`PATHEXT` to an
  absolute path and started through `cmd.exe`: an argument-array start cannot
  reach `composer.bat`, and a bare name makes the shim's `%~dp0` the working
  directory.
- **Lineage.** `ci/native-host-contract` needs the matrix, requires its result
  to be `success` and runs `verify-set`. `merge/platform-runtime-acceptance`
  now needs `frankenphp-worker`, `skeleton-create-project-windows` and
  `native-host-contract-evidence`. The nine required names and the live
  ruleset are unchanged; no ruleset API was called.
- **Projector.** `crp_verify_evidence()` previously threw for any aggregate
  prerequisite outside the frozen 22-context legacy baseline. Forward evidence
  now covers three sets, and each context must be a completed success on the
  evidence SHA from its bound app:
  - the legacy contexts with their tracked bindings (`ci/mutation-pilot`
    stays unbound);
  - the nine stable decisions, bound to the Actions app;
  - every other aggregate prerequisite, bound to the Actions app.

  Rollback needs no evidence and restores the byte-identical 22-context
  payload. `tools/ci-ruleset-main-protection-baseline.json` is unchanged, so a
  rollback would no longer require `ci/native-host-contract`. That consequence
  is accepted: the baseline is the frozen emergency payload.
- **Replaced lane.** `ci/local-operator-windows` is removed. Its tests are
  part of the contract on both hosts and remain a narrow local-operator proof,
  not #2680's AI-plane parity.

### What `collect` records and validates

- The subject: checked-out HEAD, `GITHUB_SHA`, the dispatched SHA,
  pull-request head, run ID and attempt, and contract digest. The subject
  profile depends on the event:

  | Event | Profile | Requires |
  |---|---|---|
  | `pull_request` | merge-ref | HEAD = `GITHUB_SHA`; the PR head is recorded, never substituted |
  | `workflow_dispatch` with a SHA | dispatched SHA | HEAD = that SHA |
  | `workflow_dispatch` without a SHA | dispatched ref | HEAD = `GITHUB_SHA` |
  | `push` | main | HEAD = `GITHUB_SHA` |

- The host and runner identity, including architecture.
- The PHP, Composer and SQLite versions, each within the contract's range.
  Node is recorded as not required.
- The hosted shell (`pwsh`, marked hosted-harness-only).
- Per-command outcome and exit code.
- JUnit and OTR counts, and that exactly the expected methods ran.
- A replay rendering per host, round-tripped through the real shell. The
  PowerShell rendering goes through `pwsh` on both leaves; the POSIX `sh`
  rendering also goes through `sh` on Linux. Every element of either
  rendering, the program included, is a single-quoted literal: PowerShell's
  runs through the `&` call operator with each single quotation mark doubled,
  and POSIX `sh`'s uses `'\''`. A fixed regression argument set
  (`NHE_RENDERING_REGRESSION_ARGV`: `-Dfoo.bar`, `-Dfoo.bar=value`, spaces, an
  embedded quote, an empty argument, a quoted Windows path ending in a
  backslash, a UNC path, backslashes, `$HOME`, `a,b`) is round-tripped the
  same way. The contract rejects PowerShell's stop-parsing token `--%`, which
  Windows PowerShell drops even when quoted.

Missing identity exits 3 (incomplete, never a pass). A violation exits 1.

## Selection (discriminating, not broad)

| Command | Targets | Discriminates |
|---|---|---|
| `phpunit-unit` | `ProcOpenProjectInitProcessRunnerTest` (all but the four `…OnLinux` methods), `packages/ai-agent/tests/Unit/LocalOperator` | temp working directories, start failures, exit recovery, timeouts, output caps, Windows file capture, `NUL` stdin; local-operator trust boundary |
| `phpunit-integration` | `ProjectInitProcessTest`, `tests/Integration/LocalOperator` | real child processes and exit propagation through the installed CLI; argv/env child starts, `cli-server` refusal |
| `phpunit-architecture` | `RepositoryGitEntrypointTest`, `ProjectHooksLauncherTest` (7 of 9), `SkeletonDockerSecretExclusionTest` (4 of 7), `PhpunitTempDirGuardTest`, `NativeHostEvidenceTest` | host-aware Git and Bash resolution, bounded output, deadlines, exit pass-through; Windows null device and launcher faults; absolute-path and temp-variable semantics; the collector itself |

The Docker proof, the workflow-wiring assertion and both Composer
hook-script cases (which run Bash hooks) are excluded by name.
`NativeHostContractWorkflowTest` derives the selection from source and
requires it to equal the contract's expected methods.

## Discriminating red-to-green evidence

The base is `6359a4428`, run on native Windows 11 with PHP 8.5.5 and
Composer 2.9.5. This is local native evidence, not the reference host.

- The contract's Unit command on the base had 7 failures in
  `ProcOpenProjectInitProcessRunnerTest`. Those tests ran children in the temp
  directory itself, which the Windows file-capture transport refuses. One
  missing-binary case passed for that same reason, not the one it tests.
  Repaired test-only; 16/16 green, with a new case pinning the refusal.
- The Architecture command on the base had 2 failures in
  `PhpunitTempDirGuardTest`, both predicted by reading the code:
  - a Windows checkout path was fed to the POSIX path rules;
  - the test set `TMPDIR`, which Windows PHP ignores.

  Repaired; 30/30 green. The guard's guidance now names `TMP`/`TEMP` on
  Windows.
- `CiRulesetProjectorTest` against the base projector had 10 failing cases,
  all "Stable aggregate prerequisite ci/native-host-contract is absent from
  the legacy projection". With the candidate projector: 15/15 green.
- `NativeHostEvidenceTest` killed two injected mutants:
  - the skip and incomplete checks disabled (2 failures);
  - the round-trip comparison disabled (1 failure).
- The `bin/check-skeleton-docker-secret-exclusion --self-test` null-device
  control passes natively (exit 0).
- Review found that the first renderer left switch-shaped tokens such as
  `-Dfoo.bar` bare, which PowerShell splits at the dot. The renderer now emits
  every element as a literal. A real child-process round trip on this host
  through Windows PowerShell 5.1 (no `pwsh` installed) preserved every contract
  command and 8 of the 10 regression arguments, including both `-Dfoo.bar`
  forms. 5.1's legacy argument passing lost the empty argument and the quoted
  path ending in a backslash, and it also drops a quoted `--%`. Under WSL, the
  real `sh` round trip preserved every regression argument and contract
  command. The hosted `pwsh` round trip is the authoritative check.

## Residual limitations recorded, not fixed

- Windows resolves a relative `TMP`/`TEMP` against the working directory.
  `TMP=relative\dir` therefore yields an absolute path inside the checkout,
  which the temp-directory guard accepts because only the exact root is
  refused. `root-hygiene-after` is the backstop in the contract.
- `bin/worktree-coordinator` has no native Windows support (see the custody
  record).
- The hosted `pwsh` round trip is exercised only in the hosted leaves. The
  PHPUnit tests model the shells lexically, so no contributor needs `pwsh`.

## Residual #2678 acceptance

- Broader portable PHP gates, beyond the three above and the self-test, need
  classification decisions.
- General CLI commands are not yet in the contract.
- There is no per-host evidence for the skeleton lanes.
- Docker and release helpers still need extraction for Windows regression
  tests.
- A static guard against hard-coded `/dev/null` in portable PHP is still to
  come.
- The nightly Windows surfaces stay policy-only.
- Out of scope for this issue: #3085 (host-aware qualification), #2680
  (AI-plane parity), #2681 (packaged-consumer parity), full Windows PHPUnit,
  and Windows serving, browser, Docker and release proofs.
