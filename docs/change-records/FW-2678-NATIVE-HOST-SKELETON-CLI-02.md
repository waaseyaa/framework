# FW-2678-NATIVE-HOST-SKELETON-CLI-02 — paired installed-consumer CLI on Linux and Windows

- Status: review candidate
- Issue: #2678 (second slice); parent program #2676
- Contract: [`docs/specs/native-host-support.md`](../specs/native-host-support.md)
- Base: `13d818dd353321b6d3447340aca0e4eb26d96c00` (the FW-2678-NATIVE-HOST-CONTRACT-01 squash)
- Integration and review owner: Codex. Implementation: Claude.
- Landing: not authorized by this record; Russell approves each landing.

## Custody record (manual Windows coordinator exception)

| Field | Value |
|---|---|
| Custody type | Manual Windows coordinator exception |
| Custody ID | `3a9776c2-2fd4-4f32-9103-3e1887a691db` |
| Canonical worktree path | `C:/dev/waaseyaa/framework-worktrees/native-host-cli-2678` |
| Branch | `claude/native-host-skeleton-cli-2678` (created as `claude/native-host-cli-2678` for the superseded FW-2678-NATIVE-HOST-CLI-02 lane and renamed in place with native Git when the lane pivoted) |
| Base SHA | `13d818dd353321b6d3447340aca0e4eb26d96c00` (verified as `origin/main` immediately before creation) |
| Owner | Claude |
| Integration/review owner | Codex |
| Created | 2026-09-25T17:01:32Z (worktree directory); recorded 2026-09-25T17:01:37Z |

The custody exception of FW-2678-NATIVE-HOST-CONTRACT-01 applies unchanged:
`bin/worktree-coordinator` cannot lease a native Windows worktree, so no
coordinator registry or lease file was written, fabricated or hand-edited. The
worktree was created with native Windows Git
(`C:\Program Files\Git\cmd\git.exe`, 2.47.0), dependencies were installed only
inside the candidate, and WSL Git never touched this repository: the WSL
diagnostics below cloned a bundle file that native Git wrote.

## Lane pivot and the repository-root discriminator

FW-2678-NATIVE-HOST-CLI-02 proposed running `php vendor/bin/waaseyaa list --raw`
at the repository root of the native-host contract checkout. Its baseline
discriminator, run before any change, showed that the repository root is not
a bootable consumer:

- on native Windows and under WSL, the command exits 1 because no
  application secret is configured outside a development environment;
- with `APP_ENV=local` it exits 1 because no active configuration generation
  exists, and the attempted boot creates `storage/waaseyaa.sqlite` (removed
  afterwards; `storage` is an allowed root entry).

That result is recorded here as a design discriminator only. This slice does
not change repository-root hygiene policy and does not describe `list --raw`
as read-only: a full CLI boot opens the application database. The lane
pivoted to the two existing consumer lanes, which already build a real
consumer from the candidate and complete its lifecycle.

## Outcome

Pull requests cannot pass `merge/platform-runtime-acceptance` unless a fresh
consumer built from the candidate completes `site:init` and `install:init` on
native Linux and native Windows, and then `php vendor/bin/waaseyaa list --raw`
exits 0 in both consumers and lists `list`, `db:init`, `site:init`,
`site:doctor` and `install:init`. The two lanes must also have installed an
equivalent `waaseyaa/*` cohort from the same originating candidate, in the
same workflow run.

## Design

- **Pairing.** `site-reference-consumer` (ubuntu-24.04) is the Linux lane and
  `ci/skeleton-create-project-windows` (windows-2025) the Windows lane.
  `ci/skeleton-create-project` is unchanged: on ordinary runs it proves the
  published release line, not the candidate, so it cannot share a candidate
  subject with the Windows lane.
- **Contract.** The `consumer_cli` section of
  [`tools/native-host-contract.json`](../../tools/native-host-contract.json)
  holds the argv, the required catalogue entries, the lifecycle artifacts
  (`.waaseyaa/generated.json` and `bin/maintenance/site-verify`, which only
  `site:init` publishes) and each lane's job, candidate binding and boot
  environment. It shares the contract digest with the first slice.
- **Lifecycle completion, observed.** Each lifecycle step must succeed with
  exit 0. The collector then observes the result rather than trusting the
  step. The `site:init` publications must exist, and the consumer database
  must hold an activated configuration generation, which only `install:init`
  creates. The database is opened read-only, using the table
  `tests/ReferenceConsumer/prepare.php` inspects. The database file alone
  would prove nothing, because a CLI boot can create an empty one.
- **Job binding.** Each record carries the `GITHUB_JOB` that collected it, and
  the collector rejects a job other than the lane's.
- **One step text.** Both lanes run the argv as the same PowerShell step,
  immediately after the lifecycle, with the first slice's literal rendering
  (`& 'php' 'vendor/bin/waaseyaa' 'list' '--raw'`) and exit capture. stdout is
  kept as `list-raw.stdout` in the evidence artifact. Each lifecycle step
  records its own exit code: the Linux wrapper records the harness's exit
  status, and the Windows script records 0 only after every checked command
  exited 0.
- **Catalogue subset, source-checked.** `list` is the console application
  built-in. `site:init` and `site:doctor` (`SiteServiceProvider`),
  `install:init` (`MigrateServiceProvider`) and `db:init`
  (`ConfigCacheDbAuditServiceProvider`) are yielded unconditionally by
  providers that `waaseyaa/cli` declares for discovery, and none of those
  providers is gated on an optional package.
  `NativeHostConsumerEvidenceTest` re-derives this from source. The scratch
  Windows consumer listed 126 commands, all five included. No entry was
  removed or replaced.
- **Subject: the originating checked-out candidate.** Each record reuses the
  first slice's subject (repository, event, checked-out SHA, pull-request head,
  run and attempt).
  - Linux binds the harness's existing `candidate_revision`, the revision it
    archived. The harness hands it over only after its final PASS, and it
    must equal the checked-out HEAD.
  - Windows binds its checkout.
- **Installed cohort, by content.** Composer path-repository references hash a
  package manifest and the repository options, not its code, so they are
  recorded but never compared.
  - Every installed `waaseyaa/*` file must equal the candidate revision's blob
    at the same path (`git ls-tree -r`).
  - On Windows only, a CRLF checkout of an LF blob (`core.autocrlf`) matches
    after normalization and is counted. Linux compares bytes.
  - Paths match without case on Windows. The candidate tracks both
    `packages/ssr/tests/Fixtures/` and `packages/ssr/tests/fixtures/`, which
    share one directory on NTFS.
  - A candidate file Composer did not install must be export-ignored by the
    candidate; otherwise it is a violation. The export-ignore set is what
    `git archive` leaves out: a file with the attribute set, or any file
    under a directory with it set. It is read with
    `git check-attr --source <candidate>`, including `<dir>/` queries.
  - The distribution export-ignore policy (#2648) withholds `.agents/`,
    `.claude/` and `.mcp.json`: 20 of the tracked files, the same set on both
    hosts.
  - Export-ignored files are left out of every digest, whether or not they
    were installed. `git archive` applies the root `.gitattributes` to package
    paths, while Composer's Windows path mirror reads only the mirrored
    directory's own. Without this, a future export-ignored package file would
    be installed on one host and not the other, a false red.
  - Each package digest covers the candidate paths and blobs of its
    non-export-ignored files. The cohort digest covers name, version and
    package digest. A metapackage (`waaseyaa/ai-development`) is identified
    by its candidate manifest.
- **The Linux scratch commit.** The harness recommits only the skeleton as a
  scratch project and creates its consumer from that commit, so the Linux
  project's source revision is the scratch commit, not the candidate. The
  record carries it as `project_source` (from the harness handover) and
  proves its tree equals the candidate's `skeleton/` tree. The verifier
  rejects a record that claims the scratch commit is the candidate. The
  Windows project is created from the checkout's `skeleton` directory itself.
  Composer keeps no root-package reference through the consumer's later
  `composer update` on either host; both scratch consumers reported `null`.
  The observed root package is recorded as-is and never compared.
- **Boot environment.** The Linux consumer has no `.env`: its harness runs the
  whole lifecycle under an exported `APP_ENV=testing` and a fixed test
  secret, and hands both to the CLI step through `GITHUB_ENV`. The Windows
  consumer boots from its own post-create `.env` (`APP_ENV=local`, generated
  secret). The collector reads `.env` then `.env.local`, and the last
  assignment wins, as in Symfony Dotenv. Both are development environments.
  The record names the environment, its source and whether a secret was
  present, never its value. The harness keeps its consumer only when it
  hands it over, after PASS; a failed harness still cleans up.
- **Gate.** `ci/native-host-consumer-cli` needs both lanes, requires both
  results to be `success`, and downloads each lane's artifact by its single
  name. `consumer-verify-set` then fails closed unless it receives exactly
  one Linux and one Windows record from this run with:
  - the same subject and candidate, and the skeleton tree that the verifier
    resolves from its own checkout;
  - an equivalent installed cohort;
  - successful lifecycle and CLI steps, with exact argv and exit 0;
  - every required catalogue entry, every lifecycle artifact and an
    activated generation;
  - the contract's boot environment;
  - recorded PHP, Composer and SQLite versions in range;
  - the expected OS, runner and hosted shell;
  - no violation or incomplete entry.

  It re-derives each check from the record's fields and does not trust the
  record's `result`.
- **Required checks.** `merge/platform-runtime-acceptance` needs the gate, so
  `site-reference-consumer` becomes merge-blocking; this is intentional. The
  nine `merge/*` names and the frozen 22-context rollback baseline are
  unchanged. The projector proves the gate on the evidence SHA like
  `ci/native-host-contract` and never projects it, so the proved union count
  becomes 33.
- **Roster.** The lanes and the gate are producers
  (`native-host-consumer-linux`, `-windows`, `-aggregate`) with lineage on
  both lanes. There are two single-name artifact contracts
  (`native-host-consumer-evidence-linux` and `-windows`), because the
  one-producer, one-family contract shape cannot express one family from two
  jobs.

### Evidence record (`waaseyaa.native_host_consumer_cli_evidence`, version 1)

Envelope compatible with the first slice (`schema`, `schema_version`,
`result`, `host`, `contract`, `subject`, `runner`, `hosted_shell`, `runtime`,
`violations`, `incomplete`), plus:

- `lane` {`job` (the collecting `GITHUB_JOB`), `candidate_binding`};
- `candidate` {`revision`, `binding`, `skeleton_tree`};
- `root_package` {`name`, `pretty_version`, `reference`}, as observed;
- `project_source` {`relation`, `revision`, `tree`}: the Linux scratch
  commit and its tree, or the Windows checkout skeleton;
- `cohort` {`digest`, `package_count`, `path_comparison`, `packages`}. Each
  package records name, version, type, dist type and reference, candidate
  path, files, withheld, export-ignored count, content digest and
  EOL-normalized count;
- `lifecycle` {`artifacts`, `activated_generations`};
- `cli` {`argv`, `powershell`, `outcome`, `exit_code`, `required_commands`,
  `missing_commands`, `catalogue`};
- `boot_environment` {`app_env`, `source`, `app_secret`: present or absent};
- `steps` (`lifecycle`, `consumer-cli`).

Exit codes match the first slice: 0 pass, 1 violation, 2 harness, 3
incomplete.

## Cost

In the last main run before the change (36073541055),
`site-reference-consumer` took 27 s and `ci/skeleton-create-project-windows`
68 s. The change adds the following to each lane: a PowerShell identity step,
the CLI step, the collector (which hashes about fifteen thousand installed
files and resolves the candidate's export-ignore set) and one upload. It also
adds the Linux gate job. Both lanes and the
gate finish inside the Linux PHPUnit shards that pace every run. The measured
hosted durations are reported with the pull request. This is a job-wall cost
proxy only, and no billed cost is claimed.
`site-reference-consumer` was previously not required; its network-permitted
`composer update` can now block a merge until the lane is re-run.

## Discriminating evidence

Native Windows 11, PHP 8.5.5, Composer 2.9.5; local evidence, not the
reference hosts.

- `NativeHostConsumerEvidenceTest` (82 cases) and
  `NativeHostConsumerCliWorkflowTest` (10 cases) pass. The collector cases
  cover:
  - an archive of another revision and a missing revision handover;
  - changed and unexpected files, and an uninstalled manifest;
  - a candidate file neither installed nor export-ignored;
  - CRLF, normalized only on Windows;
  - an export-ignored file installed on one host but not the other, which
    must not change the digest;
  - export-ignore resolution through directory rules and batches, failing
    closed;
  - a non-candidate package and a dirty checkout manifest;
  - missing installed metadata;
  - a missing `site:init` publication, no database, and a database without
    an activated generation;
  - another or a missing collecting job;
  - unreadable export-ignore attributes;
  - skipped lifecycle or CLI steps and a non-zero CLI exit;
  - an uncaptured catalogue and a missing catalogue entry;
  - a scratch tree that is not the skeleton tree, and a scratch commit
    claimed to be the candidate;
  - a process `APP_ENV` over the consumer `.env`, a `.env.local` that
    overrides it, and no secret;
  - missing handover, repository or tree.

  The verifier cases cover:
  - missing, duplicated, misfiled, extra and non-JSON records, and a
    malformed cohort;
  - another run, attempt, checkout, candidate, pull-request head or
    repository;
  - another cohort digest or package version;
  - a non-zero CLI exit, a missing catalogue entry, another argv, and a
    skipped lifecycle or CLI;
  - a missing lifecycle artifact or activated generation, missing Composer
    or shell version, and another runner, boot environment or lane;
  - a skeleton tree other than the one the verifier resolves;
  - a Linux scratch commit claimed equal to the candidate, a Linux scratch
    tree that is not the skeleton tree, and a Windows record that claims a
    scratch commit.
- Fifteen injected mutants were each killed by the new tests. The run used a
  scratch copy of the library, and the worktree was not modified. The
  unmutated control passed 81/81. The mutants removed:
  - the harness-revision binding;
  - the installed-content comparison;
  - the cross-lane cohort comparison;
  - the scratch-commit check;
  - the required-catalogue check;
  - the CLI exit check;
  - the lifecycle-step check;
  - the duplicate-lane rejection;
  - the run binding;
  - the rule that a withheld file must be export-ignored;
  - the activated-generation check;
  - the collecting-job binding;
  - the verifier's skeleton-tree recomputation;
  - the exclusion of export-ignored files from the digest;
  - the Windows-only CRLF normalization.
- An exploratory Windows consumer built from the working tree completed the
  lifecycle; `list --raw` exited 0 and listed all five required commands
  among 126. The probe exposed three facts the design now handles:
  - the metapackage without an install path;
  - the export-ignore-withheld files;
  - the case-only directory pair in `waaseyaa/ssr`.
- **Native Windows qualification.** The candidate was `1269e97a`, taken from
  a clean native clone of a bundle with `core.autocrlf=true`, as on the
  hosted runner.
  - The Windows lane's lifecycle (create-project from the checkout's
    `skeleton`, `prepare.php configure`, update, post-create, bind-lock,
    `site:init`, strict `site:doctor`, `install:init`) completed.
  - The CI step text ran under Windows PowerShell 5.1 and exited 0.
  - `consumer-collect --host=windows` recorded two expected local-only
    violations, and nothing else: the shell is `powershell` 5.1, not the
    hosted `pwsh`, and Composer is 2.9.5, not 2.10.
  - It installed 68 `waaseyaa/*` packages and 8,972 framework files. It
    withheld 20 files, all 20 export-ignored.
  - It matched paths without case, normalized line endings on 440 files, and
    found one activated generation.
  - The collector took 144 to 196 s on this workstation, hashing about
    fifteen thousand installed files under `%TEMP%`. Resolving export-ignore
    with real Git took 55 batched calls, about 5 s, and reproduced exactly
    the 20 files `git archive` leaves out. The hosted duration is measured in
    CI.
- **WSL diagnostic (not Linux acceptance).** PHP 8.5.8, from the same bundle;
  WSL Git read only the bundle file.
  - The unmodified `site-reference-consumer` harness passed with the handover
    enabled, and `list --raw` exited 0 with 126 commands.
  - The scratch commit's tree was the candidate's `skeleton/` tree
    (`7d2a2e3a…`).
  - `consumer-collect --host=linux` recorded only the diagnostic
    environment's violations: `bash` instead of `pwsh`, and WSL's `PATH`
    Composer 2.7.1.
- **Cross-host pairing of the two diagnostic records.** `consumer-verify-set`
  rejected the set only for those environment facts (the result, shell and
  runtime checks). The two records agreed on:
  - the subject, candidate `1269e97a` and skeleton tree;
  - the cohort digest (`0ae212136ccd8371…`), from the Linux archive with
    exact paths and LF endings and from the Windows CRLF checkout with
    case-insensitive paths.

  Their Composer references for `waaseyaa/framework` differed, which is why
  references are not the identity: the Linux archive gave the
  manifest-and-options hash `9ec6754f…`, and the Windows checkout gave the Git
  commit `1269e97a…`.
- Both scratch consumers reported no root-package reference after the
  update. That corrected an audit reading that had taken the
  `waaseyaa/framework` reference for the root reference; hence
  `project_source`.
- An independent exact-diff review found nothing blocking. Its three
  should-fix findings are fixed here:
  - withheld files not checked against export-ignore;
  - a latent cross-host false red from package-level export-ignore;
  - a lifecycle check that a CLI boot could satisfy.

  So are its applicable nits: job binding, Windows-only CRLF, dotenv
  precedence, keeping the consumer only on handover, a newline guard, the
  failed-record cohort shape, and verifier-side skeleton-tree
  recomputation. Both qualifications were re-collected with the fixed
  collector, with the same cohort digest and only the environment
  violations.

## Residual limitations recorded, not fixed

- The recorded follow-up debts of the first slice still apply:
  - Composer replay covers no `cmd.exe` metacharacters;
  - a round-trip shell that starts but exits non-zero is not told apart from
    one that cannot start;
  - the projector does not type-check `prerequisite_contexts`;
  - `bin/worktree-coordinator` has no Windows support.
- The collector checks the collect step's environment as the proxy for the
  CLI step's; both inherit the same job environment and `GITHUB_ENV`.
- The CLI argv in a record is the contract's; the workflow-shape test, not
  the collector, binds the step text to it.

## Residual #2678 acceptance

- Broader portable PHP gates still need classification decisions.
- Beyond `list --raw` in an installed consumer, general CLI commands are not
  in the contract.
- Docker and release helpers still need extraction for Windows regression
  tests.
- A static guard against hard-coded `/dev/null` in portable PHP is still to
  come.
- The nightly Windows surfaces stay policy-only.
- Out of scope for this issue: #3085, #2680, #2681, full Windows PHPUnit, and
  Windows serving, browser, Docker and release proofs.
