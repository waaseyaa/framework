# FW-2678-PORTABLE-DOCKER-RELEASE-04: portable Docker and release helpers on native Windows and Linux

- Status: review candidate
- Issue: #2678 (fourth slice); parent program #2676
- Contract: [`docs/specs/native-host-support.md`](../specs/native-host-support.md)
- Base: `8844e5dd3e67520245846782f3514a29f675eb12` (the FW-2678-PORTABLE-NULL-DEVICE-03 squash)
- Integration and review owner: Codex. Implementation: Claude.
- Landing: not authorized by this record; Russell approves each landing.

## Custody record (manual Windows coordinator exception)

| Field | Value |
|---|---|
| Custody type | Manual Windows coordinator exception |
| Custody ID | `7279c482-1663-4fe3-9c47-28e777bfda82` |
| Canonical worktree path | `C:/dev/waaseyaa/framework-worktrees/portable-docker-release-2678` |
| Branch | `claude/portable-docker-release-2678` |
| Base SHA | `8844e5dd3e67520245846782f3514a29f675eb12` (verified as `origin/main` immediately before creation) |
| Owner | Claude |
| Integration/review owner | Codex |
| Created | 2026-09-26T21:10:12Z |

`bin/worktree-coordinator` still cannot lease a native Windows worktree (see
FW-2678-NATIVE-HOST-CONTRACT-01), so no lease file was written, fabricated or
hand-edited. The worktree was created detached at the exact base with native
Windows Git (`C:\Program Files\Git\cmd\git.exe`, 2.47.0), the branch was
created inside it, and `composer install --no-scripts` ran only inside the
candidate. No other worktree, branch or recovery ref was changed. Local Linux
evidence came from disposable clones of a Git bundle of this branch inside
WSL `/tmp`; WSL Git never touched this repository.

## Outcome

#2678's third acceptance item reads: "Keep Linux-specific Docker/release
proofs where appropriate, but extract portable helpers so they receive
Windows regression tests without requiring Docker Desktop." With this slice:

- the Docker secret gate's host-neutral decisions are a dependency-free
  library whose tests run on native Windows and Linux, and its pass and fail
  report runs there too with its Docker inspection stubbed, while building and
  inspecting images stays in `ci/skeleton-create-project` on Linux;
- the release cut's PHP helpers and the split-main target resolver run their
  tests, through their real entrypoints, on both hosts;
- a missing or broken helper cannot be reported as a pass on either host;
- no step, job, required context or `merge/*` name is added.

## Audit

Every Docker and release entrypoint the support contract's inventory names,
with what is host-neutral about it and what this slice does with it:

| Entrypoint | Host-neutral logic | Disposition |
|---|---|---|
| `bin/check-skeleton-docker-secret-exclusion` (PHP; Linux Docker proof in `ci/skeleton-create-project`, `--self-test` in the contract) | The Dockerfile context-escape parse, the build-context inventory and its dotenv entries, the raw and gzip sentinel scans, the generated-secret reader, the classification of the `docker --version` and `docker info` probes, the exit code and message each classification forces, the positive-control and subject failures, and the report | **Extracted** into `bin/lib/skeleton-docker-secret-exclusion.php` without a behavior change and tested on both hosts. The report (FAIL and exit 1, or PASS and 0), the process runner and the Docker, `tar` and filesystem orchestration stay in the gate; the report is tested on both hosts with the inspection stubbed. |
| `ci.yml` `skeleton-create-project`, step "Production Docker image builds and ships its runtime extensions" (inline Bash) | The release-cut skip rule (the Dockerfile blob must equal the first parent's), the required extension list, the apk layer comparison | **Linux-owned.** Its inputs are Docker and Git output inside a Linux proof; moving the rule would rewrite that proof. Deferred, not required by the issue text. |
| `bin/check-distribution-exclusion` over `tools/lib/DistributionExclusionPolicy.php` (PHP; preflight and `ci.yml`) | Rendering and validating the Docker, deploy-rsync and archive exclusion surfaces, `skeleton/.dockerignore` included | **Linux-owned for its self-test.** The default policy check passes on native Windows (observed). The `--self-test` archive proofs start `composer` by argument array, which cannot reach `composer.bat`, and cannot delete Git's read-only objects afterwards: the run fails and leaves 26 read-only files in `%TEMP%` each time (observed). Recorded debt, not fixed here. |
| `bin/sync-internal-versions` over `bin/lib/internal-version-sync.php` (PHP; release cut) | All of it | **Already portable.** The library tests, and new entrypoint tests from a scratch root, run on both hosts. |
| `bin/changelog-fragments`, `bin/check-changelog-shape` (PHP; release cut, preflight) | All of it | **Already portable.** Their tests run the entrypoints in place against scratch inputs on both hosts: `validate`, `render` (to a file and to stdout), `release` with its refusal and rollback, argument errors, and the shape guard. |
| `bin/resolve-split-main-targets` (PHP; `split-main.yml`) | All of it | **Already portable.** Its tests run the entrypoint in place on both hosts. |
| `bin/generate-surface-map` (PHP; release cut, `--write`) | Composing the public-surface maps | **No Windows claim.** It regenerates committed documentation rather than deciding a release; its parity is checked by the preflight's `surface-parity` gate. |
| `bin/test-random-order` (PHP; release cut) | Seed handling | **No Windows claim.** It orchestrates the Linux random-order PHPUnit proof, which the support contract already classifies as host-specific CI automation. |
| `bin/check-release-require-parity` (Bash with `python3`, `composer`, `git ls-remote`) | Split-target and require parity over manifests | **Linux-owned.** Its remote mode probes split repositories over the network. |
| `bin/check-release-tag-parity` (Bash with `python3`) | The tag-name check (RP001, RP002) | **Linux-owned.** Its substance is `git ls-remote` per split repository; the two-line tag check is not worth a port. |
| `bin/check-monorepo-release-shape` (Bash) | A revision's tree shape | **Linux-owned.** About ten lines of Git plumbing; a port would rewrite it. |
| `bin/check-release-publish-shape` (Bash; preflight and `split.yml`) | Static checks over workflow files | **Linux-owned.** A port would rewrite, not extract. |
| `bin/build-exact-source-artifact`, `verify-exact-source-artifact`, `materialize-exact-source-artifact`, `promote-exact-source-artifact` (Bash) | Archive naming and digests | **Linux-owned.** `git archive`, `tar`, `sha256sum`, `gh` and the repository `bin/git`. |
| `bin/build-split-contribution-boundary`, `configure-split-tag-protection`, `enable-governed-auto-merge`, `wait-for-green-ci` (Bash) | None worth extracting | **Linux-owned** split and GitHub operations. |
| `bin/generate-release-evidence` (Node) | Release evidence JSON | **Linux-owned.** No PHP to extract. |
| `scripts/build-release-candidate.sh` (Bash) | None worth extracting | **Linux-owned.** Builds and records a release candidate. |

## Design

### The Docker gate's decisions

`bin/lib/skeleton-docker-secret-exclusion.php` holds plain `sdse_*` functions
over strings, arrays and files. None starts a process, needs Docker or
branches on the host:

- `sdse_dockerfile_context_escapes()` parses Dockerfile text (the gate's
  `dockerfileContextEscapes()` keeps only the file read);
- `sdse_context_inventory()` and `sdse_is_dotenv()` read the `docker export`
  entry list;
- `sdse_scan_file()` and `sdse_stream_contains()` scan raw and gzip bytes
  with the 512-byte overlap window;
- `sdse_generated_value()` reads a generated secret from `.env`;
- `sdse_classify_docker()` classifies the two probe results; the gate's
  `classifyDocker()` runs the probes and passes `docker info` as a callable,
  so it still starts only after `docker --version` answered;
- `sdse_docker_state_outcome()` maps a classification and
  `--allow-missing-docker` to an exit code and its message, or to null when
  the inspection may proceed. Only an unavailable daemon with the flag exits
  3; an unknown kind fails closed like a harness fault;
- `sdse_control_failures()` and `sdse_subject_failures()` assemble the
  failures;
- `SDSE_EXIT_PASS`, `SDSE_EXIT_LEAK`, `SDSE_EXIT_HARNESS` and
  `SDSE_EXIT_NO_DOCKER` are the gate's exit codes; the gate keeps no copy.

The code moved with only the changes the split needs: the file read stays in
the gate, `docker info` arrives as a callable, the dotenv filter is a named
function, and the two exit branches became one outcome function. The
messages, exit codes and output are unchanged. The gate keeps the report:
any failure prints FAIL with the list and exits 1, otherwise both PASS lines
and 0.

The gate stays dependency-free, because the hosted Docker lane runs it
without `composer install`, and loads the library with a plain `require`
before any mode runs. A load that fails with a `Throwable` (a parse error,
for one) is caught, and the gate then checks that the functions and
constants it needs exist. A missing, unreadable, unparsable or incomplete
library makes every mode, `--self-test` and `--allow-missing-docker`
included, exit 2 with a harness error on stderr and nothing on stdout.

The null device, the runner, the launcher probe and the self-test stay in the
gate, so the portable null-device guard's classifications of this file, keyed
by symbol, are unchanged, and the new library spells no `/dev/null`.

### The release helpers

The four release helpers were already plain PHP; they needed no extraction.
Two gaps in their tests were closed:

- The version sweep's tests called its library in-process. Three new tests
  run the tracked `bin/sync-internal-versions` and its library from a scratch
  root whose path contains a space, because the entrypoint syncs the root it
  sits in:
  - a missing, empty, `dev-main`, wildcard or caret version exits 1 with its
    usage or validation message and changes nothing;
  - an empty manifest set, and lock dependency-key drift, exit 2 with their
    messages, and a refused lock stays byte-identical;
  - a sweep updates every discovered manifest, the skeleton's included, and a
    second run reports `Updated 0 file(s)`.
- Only the changelog compiler's `release` mode ran through its entrypoint,
  though the release cut also runs `validate` and `render --output=` and the
  preflight runs `validate`. A new test runs `validate`, `render` to a
  spaced output path and to stdout, an unknown command, a malformed option
  and an invalid fragment through the entrypoint.

### Contract placement

`tools/native-host-contract.json` gains no command. Its Architecture command
adds `SkeletonDockerSecretExclusionDecisionsTest`, `ChangelogFragmentsTest` and
`ChangelogShapeGuardTest`; its Integration command adds
`ResolveSplitMainTargetsTest` and `SyncInternalVersionsTest`. Two methods are
excluded by name:

- `SyncInternalVersionsTest::resolve_current_version_returns_tag_minus_v_prefix`
  resolves the checkout's newest tag and skips without one. Hosted checkouts
  fetch no tags, `--fail-on-skipped` would fail it, and the release cut passes
  an explicit version rather than reading tags.
- `ChangelogShapeGuardTest::ci_and_release_cut_both_run_the_shape_guard`
  reads workflow files, like the Docker gate's wiring assertion the contract
  already excludes.

The expected methods grow from 13 to 52 (Integration) and from 50 to 76
(Architecture). Both `ci.yml` leaves run the re-rendered steps, and
`tools/ci-workflow-inventory.json` is regenerated.

## Independent review

A fresh-context, read-only review of the exact delta at `041052229` found no
blocking issue. It compared the base and candidate gates in 42 simulated runs
on native Windows (6 Docker states by 7 flag sets) and in real Docker runs
under WSL (clean, seeded leak with escaping `COPY` and `ADD`, and
`--self-test`); every output was byte-identical apart from the random
workspace name. Its findings, all repaired in `89f167bac`:

- **Should-fix: the report path had no test.** Four mutants survived both
  gate test classes: a leak exiting 0, the positive-control failures dropped,
  the subject failures dropped, and the Dockerfile check replaced by an empty
  list, which the delegation test missed because the call still existed in
  an unused wrapper. The gap predates this slice, but the docs overclaimed.
  `the_gate_turns_its_findings_into_its_exit_code_and_output` now runs the
  real gate with its classification pinned to an available daemon and its
  inspection stubbed with canned reports: a clean run exits 0 with both PASS
  lines, a leak, a blind control and an escaping Dockerfile each exit 1 and
  name themselves, and all three together are listed in the gate's order.
  The docs now say what is proven.
- **Should-fix: the audit missed three entrypoints**, now rows above:
  `check-distribution-exclusion`, `generate-surface-map` and
  `test-random-order`.
- **Should-fix: the release-helper row overstated coverage.** Only the
  compiler's `release` mode ran through its entrypoint, and "from scratch
  roots" was wrong for the in-place tests. The new changelog entrypoint test
  covers the other modes, and the row names what runs and how.
- **Nits.** A simulation tree could leak when copying the library failed; each
  tree is now registered on creation and removed in `tearDown()`. Two sets of
  exit constants existed; the gate now uses the library's. A present but empty
  library let `--self-test` pass and other modes die with exit 255; see the
  library check above. The hosted cost is not yet measured.

## Discriminating evidence

### Mutation testing

A scratch harness applies mutants, each an exact edit that occurs once, in a
disposable WSL bundle clone. It runs exactly the contract's selection of the
affected test classes after an unmutated control, and restores each file with
Git; the clone was clean after every table.

- **Docker gate, 58 mutants, all killed at `89f167bac`:** 44 in the library,
  8 in the gate's library check and Docker-state wiring, and 6 in its
  report. They cover dotenv matching, the inventory prefix, trailing slash,
  empty entry and order, the gzip branch and its union with raw hits, the
  overlap window and its de-duplication, continuation joins and their line
  numbers, comments, case-insensitive instructions, `--from`, destinations,
  remote sources, `.env.example`, the secret's anchor, carriage return and
  16-byte boundary, every probe classification, every outcome and exit code,
  both failure lists, the library check (missing, parse error, incomplete,
  exit code), the flag wiring, the outcome message, a private copy of a moved
  decision, and the report: a leak exiting 0, dropped control, subject and
  Dockerfile failures, and the FAIL and PASS lines.
- **History.** The first table, at `32c4c408e`, killed 48 of 50; its two
  survivors were test gaps repaired in `28e0db620` (a refusal loop caught
  `RuntimeException` around `self::fail()`, whose assertion errors are
  `RuntimeException`s too, and no fixture had a comment ending in a
  backslash). The review's four report mutants are in the table above.
- **Release helpers, 14 mutants, all killed at `89f167bac`:** the version
  sweep's usage, invalid-version, empty-set and lock-drift exits, its updated
  count, wildcard check and skeleton discovery; the split-main resolver's
  allowlist and empty-selection exit; and the changelog compiler's refusal,
  `validate` success, `render --output` write, malformed-option and
  unknown-command exits. Nine of them target entrypoint behavior that no test
  on either host exercised before this slice.

### Base discriminator

At the base, the contract ran only `SkeletonDockerSecretExclusionTest` minus
its excluded methods for this gate. Eleven representative decision mutants
applied to the base gate (dotenv matching, the inventory prefix, the gzip
branch, the overlap window, case-insensitive instructions, destinations, the
secret's carriage return, a failed `docker info` read as available, an empty
control inventory accepted, a lost `.env.example` accepted, and an
unavailable daemon skipping without the flag) all **survived** that
selection. Their equivalents are killed on the candidate. Before this slice,
native Windows could therefore report a pass for a broken decision.

### The Linux Docker proof, unchanged in behavior

Under WSL Ubuntu 24.04 with a Docker 29.5.2 daemon (local Linux evidence,
never native-host acceptance or a hosted substitute), at `89f167bac`:

- `php bin/check-skeleton-docker-secret-exclusion` exited 0: the positive
  control saw the leak on all three surfaces (60 context entries, 7 dotenv
  files), and the subject passed (40 entries, 8 saved blobs);
- with the four dotenv exclusions removed from `skeleton/.dockerignore`, it
  exited 1 and listed 20 failures across the inventory, the image filesystem
  and the saved layers.

### Native Windows

Windows 11, PHP 8.5.5, at `89f167bac`. Every contract command except the
locked install ran as its argument array, and each PHPUnit command's logs
went through the collector's own `nhe_phpunit_results()` and
`nhe_phpunit_violations()`:

| Command | Result |
|---|---|
| `root-hygiene-before`, `composer-policy`, `portable-paths`, `null-device-self-test`, `portable-null-device`, `root-hygiene-after` | exit 0 |
| `phpunit-unit` | 90 tests, 59 of 59 expected methods, no violation |
| `phpunit-integration` | 66 tests, 52 of 52 expected methods, no violation (18.3 s) |
| `phpunit-architecture` | 164 tests, 76 of 76 expected methods, no violation (28.2 s) |

The pre-push preflight passed 46 of 46 gates at `041052229`.

### WSL Ubuntu 24.04

PHP 8.5.8 in bundle clones (local Linux evidence only):

- the same replay at `89f167bac` passed with the same method counts;
- `SubprocessHarnessContractTest`, `RecursiveRemoverContractTest`,
  `TestQualityInventoryTest`, `NativeHostBinInventoryTest` and the whole
  `SkeletonDockerSecretExclusionTest`, real Docker proof included, passed at
  `89f167bac` (23 tests);
- the full Architecture suite passed at `89f167bac`: 1,689 tests and 43,717
  assertions, no skip, the real Docker proof test included, in 540 s.

## Cost

Locally on native Windows, the added Integration tests take about 12 s, most
of it the split-main resolver's PHP launches, and the added Architecture tests
about 9 s, most of it the gate runs of the report and broken-library tests.
Under WSL the whole Integration and Architecture commands take 3 s and 8 s. No
job or step is added.

## Residual limitations recorded, not fixed

- `bin/check-distribution-exclusion --self-test` fails on native Windows and
  leaves read-only Git objects in `%TEMP%` (see the audit).
- A comment line inside a Dockerfile continuation is treated as content by
  the context-escape parse, as it was before the move; Docker removes such
  comments first. The parse is a secondary guard beside the build-context
  inventory, and this slice changes no behavior.
- The contract still excludes `the_skeleton_dockerignore_excludes_dotenv_while_keeping_the_example`,
  a text companion to the Docker proof.
- `bin/worktree-coordinator` has no native Windows support (custody record).

## Residual #2678 acceptance

This slice closes the Docker and release-helper gap. The acceptance audit and
the decision to close #2678 belong to the landing review, which also weighs
the items the earlier slices recorded: broader portable PHP gates, general CLI
commands beyond `list --raw`, and the nightly Windows surface, whose cost
split is documented as policy. Out of scope here, as before: #3085, #2680,
#2681, full Windows PHPUnit, and Windows serving, browser, Docker and release
proofs.
