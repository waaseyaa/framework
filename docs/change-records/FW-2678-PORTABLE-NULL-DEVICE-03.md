# FW-2678-PORTABLE-NULL-DEVICE-03 — static guard against a hard-coded /dev/null in governed PHP

- Status: review candidate
- Issue: #2678 (third slice); parent program #2676
- Contract: [`docs/specs/native-host-support.md`](../specs/native-host-support.md)
- Base: `a4e88e88afb1d2806b12fbc7947d32a1484e8798` (the FW-2678-NATIVE-HOST-SKELETON-CLI-02 squash)
- Integration and review owner: Codex. Implementation: Claude.
- Landing: not authorized by this record; Russell approves each landing.

## Custody record (manual Windows coordinator exception)

| Field | Value |
|---|---|
| Custody type | Manual Windows coordinator exception |
| Custody ID | `1ce1760d-e97e-4fdd-973f-d544415913b4` |
| Canonical worktree path | `C:/dev/waaseyaa/framework-worktrees/portable-null-device-2678` |
| Branch | `claude/portable-null-device-2678` |
| Base SHA | `a4e88e88afb1d2806b12fbc7947d32a1484e8798` (verified as `origin/main` immediately before creation) |
| Owner | Claude |
| Integration/review owner | Codex |
| Created | 2026-09-25T21:52:50Z |

The custody exception of FW-2678-NATIVE-HOST-CONTRACT-01 applies unchanged:
`bin/worktree-coordinator` cannot lease a native Windows worktree, so no
coordinator registry or lease file was written, fabricated or hand-edited
(`.git/waaseyaa-worktree-leases.json` does not exist). The worktree and its
branch were created in one step at the exact base with native Windows Git
(`C:\Program Files\Git\cmd\git.exe`, 2.47.0). The 23 existing worktrees were
left unchanged. Dependencies were installed only inside the candidate. WSL Git
never touched this repository: the Linux evidence below cloned a bundle file
that native Git wrote.

## Outcome

Pull requests cannot pass `merge/platform-runtime-acceptance` if governed
production PHP hard-codes a `/dev/null` `proc_open()` descriptor, or adds any
other `/dev/null` literal that is not classified as platform-derived, a
semantic diff marker or a POSIX-only shell fragment. The check runs on native
Linux and native Windows alike.

## Audit

The audit covered every governed production PHP file at the base, not only
the seven files the brief listed as known. Governed means all repository PHP
except tests and their support code, benchmarks, `docs/`, `kitty-specs/` and
vendor trees, and it includes the extensionless `bin/` entrypoints (found by
their PHP shebang), `tools/` and `scripts/`. Sixteen files spell `/dev/null`:

- Three mention it only in comments, each documenting an earlier fix:
  `bin/admin-dist-acceptance`, `bin/check-package-layers-pl008-self-test` and
  `bin/check-pr-preflight`. Comments are not inspected.
- Thirteen hold 24 string literals, classified with the brief's five
  categories below.

| File | Symbol | Literal | Count | Category | Disposition |
|---|---|---|---|---|---|
| `bin/lib/vendor-freshness.php` | `vendor_freshness_static_class_data` | `/dev/null` | 1 | platform-derived | accepted |
| `packages/cli/src/ProjectInit/ProcOpenProjectInitProcessRunner.php` | `…::nullInputDevice` | `/dev/null` | 1 | platform-derived | accepted |
| `bin/check-skeleton-docker-secret-exclusion` | `nullDevice` | `/dev/null` | 1 | platform-derived | accepted |
| `bin/check-skeleton-docker-secret-exclusion` | `selfTest` | `/dev/null` | 6 | platform-derived: the OS-family oracle and the foreign-device negative control | accepted |
| `bin/check-skeleton-docker-secret-exclusion` | `selfTest` | the pass message naming both devices | 1 | platform-derived | accepted |
| `packages/frankenphp/src/Binary/BinaryResolver.php` | `…::lookupOnPath` | `command -v frankenphp 2>/dev/null` | 1 | uncertain, then platform-derived | discriminator: the public method trusts its `$isWindows` argument; its only caller, `DevCommand::execute()`, passes `\PHP_OS_FAMILY === 'Windows'` |
| `packages/bimaaji/src/Patch/PatchGenerator.php` | `…::generateAddField` | `--- /dev/null\n+++ b/` | 1 | semantic diff marker | accepted |
| `packages/config/src/Sync/ConfigDiffer.php` | `…::buildSyncOnlyResult`, `…::buildActiveOnlyResult` | `/dev/null` | 1 each | semantic diff marker | accepted |
| `packages/deployer/recipe/waaseyaa.php` | `{main}` | two `2>/dev/null` fragments of one command | 1 each | POSIX-only deployment: Deployer runs it on the remote Linux host over SSH | accepted |
| `bin/build-phpunit-shards` | `{main}` | ` --version 2>/dev/null` | 1 | POSIX-only shell: a Linux CI shard helper | accepted |
| `bin/check-stale-spec-deferrals` | `staleSpecFetchIssue` | ` 2>/dev/null` | 1 | POSIX-only shell: a nightly Linux governance helper with an HTTP fallback | accepted |
| `scripts/layer0_audit/dead_code_candidates.php` | `{main}` | `cd %s && rg … 2>/dev/null \| wc -l` | 2 | POSIX-only shell: a forensic audit pipeline | accepted |
| `tools/audit/GenerateLayerAudit.php` | `runHygieneScan` | `command -v rg 2>/dev/null \|\| true` | 1 | POSIX-only shell: a probe with a PHP fallback | accepted |
| `bin/qualify-candidate` | `qcRunGit`, `qcStartComponent` | `['file', '/dev/null', 'r']` | 1 each | unsafe portable PHP | fixed with `['null']` |
| `bin/dev-runtime` | `runProcess` | `['file', '/dev/null', 'r']` | 1 | a direct descriptor in an explicitly POSIX-only entry | converted to `['null']` |

That is 11 platform-derived literals (one of them the resolved uncertain
case), 3 semantic diff markers, 7 POSIX-only shell or deployment fragments, and
3 direct descriptors. `bin/qualify-candidate` is PHP written to run anywhere
(argument arrays, optional `pcntl`); only its two hard-coded descriptors made
`proc_open()` fail on native Windows, and the runner then reported a spawn
error. `bin/dev-runtime` is WSL2-only by contract, but the descriptor rule has
no exemption, so its stdin changed too; on POSIX, PHP's `['null']` opens the
same `/dev/null`. The other 21 literals are classified in
[`tools/portable-null-device-classifications.json`](../../tools/portable-null-device-classifications.json).
No semantic diff marker and no POSIX-only fragment was treated as a defect.

## Design

- **The gate.** `bin/check-portable-null-device` and
  `bin/lib/portable-null-device.php` are plain PHP with no autoloader.
  - Files are enumerated through Git (tracked files plus untracked, unignored
    ones; `repositoryFiles()`) and filtered to the governed surface.
  - Each file that spells `/dev/null` is tokenized after line endings are
    normalized, and every string token that spells it is inspected: constant
    strings, interpolated and heredoc fragments, and inline text. Comments are
    documentation.
  - Exit codes: 0 pass, 1 violation, 2 usage error or unreadable repository.
- **The descriptor rule.** A `/dev/null` literal that is the path element of
  a `['file', …]` or `array('file', …)` descriptor, positional or keyed, is
  always rejected. A classification that names one is also reported as
  stale, with the reason that a descriptor cannot be classified.
- **The anchor.** A classification names a file, the enclosing symbol and the
  literal (the token's content as written), with the exact occurrence count.
  There are no line numbers. A closure belongs to the symbol that encloses
  it.
- **Purpose shapes.** The three purposes are mutually exclusive shapes of the
  occurrence's statement, so relabelling a classification is always caught:
  - platform-derived: the statement also names `NUL` and a Windows host
    signal;
  - semantic-diff-marker: a diff header, or a bare `/dev/null` beside an
    `a/` or `b/` label, with no `NUL` counterpart and no redirection;
  - posix-only-shell: a redirection to `/dev/null` with no `NUL` counterpart.
- **Manifest validation.**
  - Exact top-level and entry keys, schema `waaseyaa.portable_null_device_classifications`
    version 1, and a purpose vocabulary that must match the gate's.
  - Entries sorted by file, symbol and literal in byte order.
  - Rejected entries: repository-relative paths only, never an absolute
    path, drive letter, backslash or dot segment; a governed PHP file only,
    never a pattern, directory or wildcard symbol; a positive integer count;
    a one-line rationale of at most 500 characters.
  - Duplicates, including ones that are not adjacent, and stale entries,
    where the occurrence is gone or fewer remain.
  - A manifest that cannot be read or parsed fails with only the
    manifest-independent descriptor findings listed beside it.
- **Diagnostics.** Each violation names the file, line (for navigation only),
  symbol, literal and, where relevant, the classification index. An
  unclassified literal also gets the purpose its shape fits and the exact
  JSON entry it would need. If no purpose fits, the diagnostic shows the
  host-derived remedy instead.
- **No self-exemption.** The gate's own files are governed like any other.
  The library spells the device by construction (`'/dev/' . 'null'`), because
  it never opens it and holds no literal it would have to classify.
- **Placement.** `portable-null-device` is a `gate` command of
  [`tools/native-host-contract.json`](../../tools/native-host-contract.json)
  after `null-device-self-test`. Both `native-host-contract` leaves run it as
  the same literal `pwsh` step, and the collector requires its
  `success`/`0` result through the existing `NATIVE_HOST_STEP_RESULTS` line.
  `tests/Architecture/PortableNullDeviceGateTest.php` joins the
  `phpunit-architecture` selection with its 15 methods in `expected_methods`.
  `tools/ci-workflow-inventory.json` is regenerated: the leaf has 16 steps
  and `bin/check-portable-null-device` as a local equivalent. No job,
  required context or `merge/*` name is added, and
  `tools/ci-check-roster.json` and the frozen rollback baseline are
  unchanged.
- **Specifications.** `docs/specs/native-host-support.md` has a new "Portable
  null-device guard" section, the new entry in its verified `bin/` row (105
  files) and the updated `dev-runtime` row. `docs/specs/ci-test-selection.md`
  places the gate and its test, and `docs/specs/governed-gates.md` §8
  cross-references the guard.

## Discriminating evidence

Native Windows 11, PHP 8.5.5, Composer 2.9.5, Windows PowerShell 5.1 (no
`pwsh`); WSL Ubuntu 24.04, PHP 8.5.9, Composer 2.7.1. This is local evidence,
not the reference hosts.

- **The mutants the brief names**, each a case of
  `PortableNullDeviceGateTest` built from the tracked sources and manifest,
  changed in memory:
  - direct hard-coded descriptor: the base `qcRunGit()` shape is rejected,
    classified or not, under every purpose; every descriptor spelling is
    recognised, and `['null']` and a host-derived descriptor are not;
  - new unclassified literal: `fopen('/dev/null', 'w')` in package code (no
    purpose fits), a host-shell redirection (the diagnostic proposes the
    `posix-only-shell` entry), and one more occurrence in a classified
    symbol;
  - deleted classified occurrence: a removed `ConfigDiffer` label and one
    fewer self-test oracle entry make their classifications stale;
  - altered purpose or file: every relabelling of every tracked
    classification is a purpose mismatch (30 relabellings), and a moved file
    leaves a stale entry and an unclassified occurrence;
  - duplicate classification: adjacent, with another purpose, and far apart;
  - platform-aware implementation accepted: the five tracked host-derived
    symbols and four more host-choice forms, but not two devices without a
    host signal;
  - semantic diff marker accepted: the three tracked markers and two more
    diff forms, but not a bare path without a label.
  The class also covers malformed and overly broad classifications (each
  rejected for its own reason), the governed surface, comments, line-ending
  and unrelated-edit stability, and the entrypoint's exit codes 0, 1 and 2.
- **Base discriminator.** In the Linux clone, restoring only the base
  `bin/qualify-candidate` and `bin/dev-runtime` made the gate exit 1 with
  exactly three violations. All three were `direct-descriptor`: at
  `bin/dev-runtime:91` in `runProcess`, `bin/qualify-candidate:118` in
  `qcRunGit` and `:283` in `qcStartComponent`. With the candidate files
  restored, the gate exited 0.
- **The Windows failure itself.** On the native host, starting `git
  --version` with stdin `['file', '/dev/null', 'r']` made `proc_open()`
  return `false`. `['null']` and the host-derived descriptor both started it
  (exit 0).
- **Mutation testing of the gate.** Forty-three injected mutants of the
  library (40) and the entrypoint (3) were each killed by
  `PortableNullDeviceGateTest`. The run used the disposable Linux clone and
  restored every file; the unmutated control passed 15/15. The mutants
  removed or weakened: the descriptor rule and its `array()` and keyed
  spellings; stale, fewer, extra and unmatched occurrence checks; the
  purpose check; each purpose shape; duplicate, sort, pattern,
  directory-symbol and surface checks; each path-spelling check; the count
  type, symbol, rationale, schema and vocabulary checks; comment, inline-text
  and fragment inspection; line-ending normalization; the closure scope
  rule; the Windows-named-variable signal; case-insensitive `NUL`; input
  redirection; the escaped-newline header; the `a/` label; unreadable
  manifest handling; the descriptor-classification message; purpose
  suggestions; the entrypoint's exit code, `--manifest` option and argument
  refusal. The first run left one survivor: a file outside the governed
  surface was still rejected, but only through the sort order its name
  broke. Every malformed case now asserts its own diagnostic, and the rerun
  killed all 43.
- **Native Windows contract replay.** Each contract command ran in order
  through its contract PowerShell rendering. Every step exited 0, except
  that `ProjectHooksLauncherTest::a_child_past_its_deadline_is_stopped_and_an_exit_code_passes_through`
  exceeded its 1.8 s wall-clock bound once (2.22 s) under load. It passed
  3/3 in isolation, and the command's rerun exited 0; neither that test nor
  its subject is changed here. The hosted collector's own functions then
  found exactly the expected methods for all three PHPUnit commands (59, 13
  and 46) and no violation.
- **Linux replay (WSL, not Linux acceptance).** Every contract command
  exited 0 from its argument array, with the same exact method sets and no
  violation. The affected architecture classes passed 220/220, including
  `QualifyCandidateRunnerTest`, which drives the changed runner's real
  children; the dev-runtime tests; and the repository-wide
  `SubprocessHarnessContractTest`, `RecursiveRemoverContractTest` and
  `TestQualityInventoryTest`. A read-only `bin/dev-runtime doctor` run
  recorded the candidate commit through the changed `runProcess()`; it
  exited 1 only because no managed cache exists, and it created none.
- **Both hosts, one answer.** The gate reported 2,978 governed PHP files and
  21 classified literals (11, 3 and 7) on Windows and on Linux.

## Cost

| Measurement | Native Windows 11 workstation | WSL Ubuntu 24.04 |
|---|---|---|
| `php bin/check-portable-null-device` | 1.5–1.6 s | 0.13–0.15 s |
| `PortableNullDeviceGateTest` (15 cases) | about 8 s; the entrypoint case, three real gate runs, takes 5 s | about 3 s wall |
| `phpunit-architecture` contract command | 8–11 s before, 19–20 s after | 7 s after |

On Windows the scan's time goes mostly to Git enumeration with a stat per
path (0.7 s) and reading the PHP files (0.5 s). The hosted step and
PHPUnit durations are reported with the pull request. Both leaves finish
inside the Linux PHPUnit shards that pace every run, so the critical path
should not change. As in the earlier slices, this is a job-wall cost proxy
only, and no billed cost is claimed.

## Residual limitations recorded, not fixed

- The guard does not decide which PHP is portable. `platform-derived` and
  `posix-only-shell` are reviewed assertions about the surrounding code; the
  guard checks their shape only.
- It matches the spelling `/dev/null` in string tokens. A path assembled at
  run time (by concatenation, escape sequences or `sprintf`) is outside a
  static guard, and so is a hard-coded `NUL` on POSIX, which Linux CI would
  fail at once.
- `BinaryResolver::lookupOnPath()` is public and trusts its `$isWindows`
  argument to describe the host shell. A caller that passed another value
  would get the other shell's syntax. Its only caller derives it from the
  host.
- The gate is not in the pre-push preflight roster (`tools/preflight-gates.json`).
  Locally it runs directly or through the Architecture suite. Hosted CI
  enforces it through both native-host leaves and the Linux Architecture
  shards.
- The first slice's follow-up debts still apply: Composer replay covers no
  `cmd.exe` metacharacters; a round-trip shell that starts but exits
  non-zero is not told apart from one that cannot start; the projector does
  not type-check `prerequisite_contexts`; and `bin/worktree-coordinator` has
  no Windows support.
- Outside this slice: the audit found a tracked root-level file named
  `label`, a stray unified diff from #2137. It is not PHP and is left as it
  is.

## Residual #2678 acceptance

- Broader portable PHP gates still need classification decisions.
- Beyond `list --raw` in an installed consumer, general CLI commands are not
  in the contract.
- Docker and release helpers still need extraction for Windows regression
  tests.
- The nightly Windows surfaces stay policy-only.
- Out of scope for this issue: #3085, #2680, #2681, full Windows PHPUnit, and
  Windows serving, browser, Docker and release proofs.
