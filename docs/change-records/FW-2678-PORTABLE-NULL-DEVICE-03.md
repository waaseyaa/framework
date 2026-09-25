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
Linux and native Windows alike, and the pre-push preflight runs it too.

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
  `bin/lib/portable-null-device.php` are plain PHP with no autoloader and no
  extension beyond PHP's default build.
  - Files are enumerated through Git (tracked files plus untracked, unignored
    ones; `repositoryFiles()`) and filtered to the governed surface. A PHP
    file is a `.php` file, or one that opens with a PHP open tag in any case
    or with a PHP shebang (`php`, `php8.5`, ...) followed, after any blank
    lines, by the open tag.
  - Each file that spells `/dev/null` is tokenized after line endings are
    normalized, and every string token that spells it is inspected: constant
    strings, interpolated and heredoc fragments, and inline text. Comments are
    documentation.
  - Exit codes: 0 pass, 1 violation, 2 usage error or any failure to scan.
    Every failure is caught; none escapes as an uncaught error.
- **The descriptor rule.** A `/dev/null` literal that is the path element of
  a `['file', …]` or `array('file', …)` descriptor, positional or keyed, is
  always rejected, even when a host choice picks between whole descriptors.
  The message recommends `['null']` rather than asserting that the code
  fails. A classification that names a descriptor is also reported as stale,
  with the reason that a descriptor cannot be classified.
- **The anchor.** A classification names a file, the enclosing symbol and the
  literal (the token's content as written), with the exact occurrence count.
  There are no line numbers.
  - A closure belongs to the symbol that encloses it, and an anonymous class
    is `class@anonymous`, including `new readonly class` and an attributed
    anonymous class.
  - A keyword used as a named argument (`class:`, `function:`) declares
    nothing, and `Foo::class` is not a declaration.
  - A classification covers the occurrences that fit its purpose first, so a
    surplus is reported where it misfits.
- **Purpose shapes.** The three purposes are mutually exclusive syntactic
  shapes of the occurrence and its statement. A statement ends at `;`, at a
  block brace and at a PHP tag; interpolation braces do not end it.
  Relabelling a classification is therefore always caught:
  - platform-derived: the same statement also names `NUL` and a Windows host
    signal. The choice must be one statement, and its direction is not
    checked;
  - semantic-diff-marker: a diff header, or a bare `/dev/null` beside an
    `a/` or `b/` label, with no `NUL` counterpart and no redirection;
  - posix-only-shell: command text, neither a bare path nor a diff header,
    that redirects to `/dev/null` or passes it as a whitespace- or
    `=`-delimited word, with no `NUL` counterpart.
- **Manifest validation.**
  - Exact top-level and entry keys, schema
    `waaseyaa.portable_null_device_classifications` version 1, and a purpose
    vocabulary that must match the gate's.
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
  symbol, literal and, where relevant, the classification index; a literal
  that is not valid UTF-8 is rendered with replacement characters. An
  unclassified literal also gets the manifest change that would classify it,
  on its first occurrence in the symbol:
  - one new entry, as exact JSON, covering every occurrence of that literal
    in that symbol, with the purpose their shape fits;
  - or, for a surplus over an existing entry, "raise
    `classifications[i].occurrences` from N to M".

  Where no purpose fits, the surplus misfits the entry's purpose, or the
  occurrences do not share one shape, it gives the host-derived remedy
  instead.
- **No self-exemption.** The gate's own files are governed like any other.
  The library spells the device by construction (`'/dev/' . 'null'`), because
  it never opens it and holds no literal it would have to classify.
- **Placement.**
  - `portable-null-device` is a `gate` command of
    [`tools/native-host-contract.json`](../../tools/native-host-contract.json)
    after `null-device-self-test`. Both `native-host-contract` leaves run it
    as the same literal `pwsh` step, and the collector requires its
    `success`/`0` result through the existing `NATIVE_HOST_STEP_RESULTS` line.
  - `tests/Architecture/PortableNullDeviceGateTest.php` joins the
    `phpunit-architecture` selection with its 19 methods in
    `expected_methods`.
  - As a fast repo-state gate that CI enforces, it is also in the default
    pre-push preflight (`tools/preflight-gates.json`), as
    `docs/specs/governed-gates.md` §1 and §4 require. Its `enforced_by` is the
    native-host step.
  - `tools/ci-workflow-inventory.json` is regenerated: the leaf has 16 steps
    and `bin/check-portable-null-device` as a local equivalent.
  - No job, required context or `merge/*` name is added, and
    `tools/ci-check-roster.json` and the frozen rollback baseline are
    unchanged.
- **Specifications.**
  - `docs/specs/native-host-support.md` has a new "Portable null-device guard"
    section, the new entry in its verified `bin/` row (105 files) and the
    updated `dev-runtime` row.
  - `docs/specs/ci-test-selection.md` places the gate and its test.
  - `docs/specs/governed-gates.md` lists the gate in the default profile
    (§1) and cross-references it in §8.

## Independent review

An independent exact-diff review of `3ce71a5b8`, a read-only Opus pass, found
no blocking issue. Its three should-fix findings are repaired:

1. The suggestion for a surplus occurrence added a duplicate entry, and two
   new identical literals got two one-count entries. Suggestions are now
   grouped, a surplus raises the existing count, and a test pastes every
   suggestion back and requires a clean result.
2. No case pinned the statement boundaries or several symbol rules. Negative
   controls now pin `;`, `{`, `}` and interpolation braces. Symbol cases
   cover anonymous, readonly and attributed anonymous classes, `Foo::class`,
   `class:` and `function:` named arguments, `function &f()`, enums,
   interfaces, traits, properties and closures.
3. The gate was missing from the default preflight, contrary to
   `governed-gates.md`. It is now listed there.

Nits applied:

- symbol attribution for rare syntax;
- the spec's wording of the platform-derived shape (one statement, direction
  unchecked);
- the POSIX-only shape widened to command-text arguments, which the brief's
  "POSIX-only shell fragments" include;
- the descriptor message;
- versioned shebangs and blank lines before the open tag;
- invalid UTF-8 and the exit-code catch-all, with no `mbstring`;
- the measurement wording.

## Discriminating evidence

Native Windows 11, PHP 8.5.5, Composer 2.9.5, Windows PowerShell 5.1 (no
`pwsh`); WSL Ubuntu 24.04, PHP 8.5.9, Composer 2.7.1. This is local evidence,
not the reference hosts.

- **The mutants the brief names**, each a case of
  `PortableNullDeviceGateTest` built from the tracked sources and manifest,
  changed in memory:
  - direct hard-coded descriptor: the base `qcRunGit()` shape is rejected,
    classified or not, under every purpose. Every descriptor spelling is
    recognised, including a host choice between whole descriptors, and
    `['null']` and a host-derived path are not;
  - new unclassified literal: `fopen('/dev/null', 'w')` in package code (no
    purpose fits), a host-shell redirection (the diagnostic proposes the
    `posix-only-shell` entry), and a surplus occurrence in a classified
    symbol. Every suggestion, pasted back, classifies exactly what it
    reported;
  - deleted classified occurrence: a removed `ConfigDiffer` label and one
    fewer self-test oracle entry make their classifications stale;
  - altered purpose or file: every relabelling of every tracked
    classification is a purpose mismatch (30 relabellings), and a moved file
    leaves a stale entry and an unclassified occurrence;
  - duplicate classification: adjacent, with another purpose, and far apart;
  - platform-aware implementation accepted: the five tracked host-derived
    symbols and four more host-choice forms, but not two devices without a
    host signal, nor a choice split across statements;
  - semantic diff marker accepted: the three tracked markers and two more
    diff forms, but not a bare path without a label.

  The class, 19 cases, also covers:
  - malformed and overly broad classifications, each rejected for its own
    reason;
  - statement boundaries and symbol attribution;
  - the command-text POSIX shape;
  - the governed surface and PHP detection;
  - comments;
  - invalid UTF-8;
  - line-ending and unrelated-edit stability;
  - the entrypoint's exit codes 0, 1 and 2, and a harness error for a root
    Git cannot read.
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
- **Mutation testing of the gate.** Sixty-four injected mutants were each
  killed by `PortableNullDeviceGateTest` at `38ea9933e`: 61 of the library
  and 3 of the entrypoint. The later commits of this record leave the
  library and tests unchanged. The run used the disposable Linux clone and
  restored every file; the unmutated control passed 19/19. The mutants
  removed or weakened:
  - the descriptor rule and its `array()` and keyed spellings;
  - the stale, fewer, extra and unmatched occurrence checks;
  - the purpose check and each purpose shape, including command words, the
    diff-header exclusion and the bare-path exclusion;
  - the duplicate, sort, pattern, directory-symbol and surface checks, and
    each path-spelling check;
  - the count type, symbol, rationale, schema and vocabulary checks;
  - comment, inline-text and fragment inspection, and line-ending
    normalization;
  - each statement boundary and the interpolation exception;
  - the closure, anonymous-class, `::class`, by-reference and named-argument
    symbol rules;
  - the Windows-named-variable signal, case-insensitive `NUL`, spaced and
    unspaced input redirection, the escaped-newline header and the `a/`
    label;
  - unreadable-manifest handling and its finding filter;
  - the descriptor-classification message;
  - purpose suggestions, their grouped count, the raised count, the
    once-per-group rule, and fitting occurrences first;
  - the scan's exit-code catch-all and UTF-8 substitution;
  - case-insensitive open tags, versioned shebangs and blank lines after the
    shebang;
  - the entrypoint's exit code, `--manifest` option and argument refusal.

  Two earlier runs left one survivor each, and both became tests:
  - a file outside the governed surface was rejected only through the sort
    order its name broke, so every malformed case now asserts its own
    diagnostic;
  - once command words were accepted, a spaced input redirection no longer
    needed the redirection rule, so an unspaced `</dev/null` case was added.
- **Native Windows contract replay.** Each contract command ran in order
  through its contract PowerShell rendering.
  - On the final code, `38ea9933e`, every step exited 0; `phpunit-architecture`
    took 19.1 s.
  - The hosted collector's own functions found exactly the expected methods
    for the three PHPUnit commands (59, 13 and 50), with no violation.
  - An earlier replay, at `b10b97e7a`, had one load-induced miss:
    `ProjectHooksLauncherTest::a_child_past_its_deadline_is_stopped_and_an_exit_code_passes_through`
    exceeded its 1.8 s wall-clock bound once (2.22 s). It passed 3/3 in
    isolation and on the rerun, and neither that test nor its subject is
    changed here.
- **Linux replay (WSL, not Linux acceptance), at `38ea9933e`.**
  - Every contract command exited 0 from its argument array, with the same
    exact method sets and no violation.
  - 243/243 passed across the affected architecture classes, including:
    - `QualifyCandidateRunnerTest`, which drives the changed runner's real
      children;
    - the dev-runtime tests;
    - `PreflightParityTest` and `RefreshGovernanceArtifactsTest`;
    - the repository-wide `SubprocessHarnessContractTest`,
      `RecursiveRemoverContractTest` and `TestQualityInventoryTest`.
  - A read-only `bin/dev-runtime doctor` run recorded the candidate commit
    through the changed `runProcess()`. It exited 1 only because no managed
    cache exists, and it created none.
- **Both hosts, one answer.** The gate reported 2,978 governed PHP files and
  21 classified literals (11, 3 and 7) on Windows and on Linux.

## Cost

| Measurement | Native Windows 11 workstation | WSL Ubuntu 24.04 |
|---|---|---|
| `php bin/check-portable-null-device` | 1.5–1.6 s | 0.13–0.15 s |
| `PortableNullDeviceGateTest` (19 cases) | about 8 s; the entrypoint case, three real gate runs, takes 5 s | about 3 s wall |
| `phpunit-architecture` contract command | 8–11 s before, 19–20 s after | 7 s after |

On Windows the scan's time goes mostly to Git enumeration with a stat per
path (0.7 s) and reading the PHP files (0.5 s). The default preflight gains
the same one scan. The hosted step and PHPUnit durations are reported with
the pull request. Both leaves finish inside the Linux PHPUnit shards that
pace every run, so the critical path should not change. As in the earlier
slices, this is a job-wall cost proxy only, and no billed cost is claimed.

## Residual limitations recorded, not fixed

- The guard does not decide which PHP is portable. `platform-derived` and
  `posix-only-shell` are reviewed assertions about the surrounding code; the
  guard checks their shape only.
  - The platform-derived shape does not check a choice's direction, and a
    choice spread over several statements does not fit it.
  - The command-text shape cannot tell a shell command from prose, so the
    rationale must say where the command runs.
- It matches the spelling `/dev/null` in string tokens. A path assembled at
  run time (by concatenation, escape sequences or `sprintf`) is outside a
  static guard, and so is a hard-coded `NUL` on POSIX, which Linux CI would
  fail at once.
- `BinaryResolver::lookupOnPath()` is public and trusts its `$isWindows`
  argument to describe the host shell. A caller that passed another value
  would get the other shell's syntax. Its only caller derives it from the
  host.
- The first slice's follow-up debts still apply:
  - Composer replay covers no `cmd.exe` metacharacters;
  - a round-trip shell that starts but exits non-zero is not told apart from
    one that cannot start;
  - the projector does not type-check `prerequisite_contexts`;
  - `bin/worktree-coordinator` has no Windows support.
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
