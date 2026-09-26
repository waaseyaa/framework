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
  library whose tests run on native Windows and Linux, while building and
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
| `bin/check-skeleton-docker-secret-exclusion` (PHP; Linux Docker proof in `ci/skeleton-create-project`, `--self-test` in the contract) | The Dockerfile context-escape parse, the build-context inventory and its dotenv entries, the raw and gzip sentinel scans, the generated-secret reader, the classification of the `docker --version` and `docker info` probes, the exit code and message each classification forces, and the positive-control and subject verdicts | **Extracted** into `bin/lib/skeleton-docker-secret-exclusion.php` without a behavior change; decisions tested on both hosts. The process runner and the Docker, `tar` and filesystem orchestration stay in the gate. |
| `ci.yml` `skeleton-create-project`, step "Production Docker image builds and ships its runtime extensions" (inline Bash) | The release-cut skip rule (the Dockerfile blob must equal the first parent's), the required extension list, the apk layer comparison | **Linux-owned.** Its inputs are Docker and Git output inside a Linux proof; moving the rule would rewrite that proof. Deferred, not required by the issue text. |
| `bin/sync-internal-versions` over `bin/lib/internal-version-sync.php` (PHP; release cut) | All of it | **Already portable.** The library tests and new entrypoint tests run on both hosts. |
| `bin/changelog-fragments`, `bin/check-changelog-shape` (PHP; release cut) | All of it | **Already portable.** Their tests run the entrypoints (`release`, rollback, shape) from scratch roots on both hosts. |
| `bin/resolve-split-main-targets` (PHP; `split-main.yml`) | All of it | **Already portable.** Its tests run the entrypoint on both hosts. |
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
  verdicts.

The code moved with only the changes the split needs: the file read stays in
the gate, `docker info` arrives as a callable, the dotenv filter is a named
function, and the two exit branches became one outcome function. The
messages, exit codes and output are unchanged. The gate stays dependency-free, because the hosted Docker lane
runs it without `composer install`, and loads the library with a plain
`require` before any mode runs. When the library is missing or unreadable,
every mode, `--self-test` and `--allow-missing-docker` included, exits 2 with
a harness error on stderr and nothing on stdout.

The null device, the runner, the launcher probe and the self-test stay in the
gate, so the portable null-device guard's classifications of this file, keyed
by symbol, are unchanged, and the new library spells no `/dev/null`.

### The release helpers

The four release helpers were already plain PHP with tests that exercise their
entrypoints or their library; they needed no extraction. The version sweep's
existing tests called its library in-process, so three new tests run the
tracked `bin/sync-internal-versions` and its library from a scratch root whose
path contains a space:

- a missing, empty, `dev-main`, wildcard or caret version exits 1 with its
  usage or validation message and changes nothing;
- an empty manifest set, and lock dependency-key drift, exit 2 with their
  messages, and a refused lock stays byte-identical;
- a sweep updates every discovered manifest, the skeleton's included, and a
  second run reports `Updated 0 file(s)`.

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

The expected methods grow from 13 to 52 (Integration) and from 50 to 74
(Architecture). Both `ci.yml` leaves run the re-rendered steps, and
`tools/ci-workflow-inventory.json` is regenerated.

## Discriminating evidence

### Mutation testing

A scratch harness applied 50 mutants, each an exact edit that occurs once,
to the library (44) and to the gate's wiring (6) in a disposable WSL bundle
clone. It ran exactly the contract's selection of the two gate test classes
(the decisions class, and `SkeletonDockerSecretExclusionTest` minus its
excluded methods) after an unmutated control, and restored each file with Git.

- The first run, at `32c4c408e`, killed 48 of 50. Both survivors were test
  gaps, repaired in `28e0db620`:
  - the refusal cases caught `RuntimeException` around `self::fail()`, and
    PHPUnit's own assertion failures are `RuntimeException`s, so a wrongly
    accepted 15-character secret still passed;
  - no fixture had a comment ending in a backslash, so treating comments as
    Dockerfile content went unnoticed.
- At code-final `28e0db620` all 50 were killed, and the clone was clean
  afterwards. The mutants cover every decision: dotenv matching, the
  inventory prefix, trailing slash, empty entry and order, the gzip branch
  and its union with raw hits, the overlap window and its de-duplication,
  continuation joins and their line numbers, case-insensitive instructions,
  `--from`, destinations, remote sources, `.env.example`, the secret's
  anchor, carriage return and 16-byte boundary, every probe classification,
  every outcome and exit code, both verdicts, and the gate's library check,
  its exit code in every mode, the flag wiring, the outcome message and a
  private copy of a moved decision.

A second table of 10 mutants against the release helpers was killed by the
contract's selection of their tests: the version sweep's usage exit, its
invalid-version exit, its empty-set exit, its lock-drift exit, its updated
count, its wildcard check and its skeleton discovery; the split-main
resolver's allowlist and empty-selection exit; and the changelog compiler's
refusal exit. The first five target the entrypoint, which no test on either
host exercised before this slice.

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
never native-host acceptance or a hosted substitute), at `ae9467dcf`, whose
gate and library blobs equal code-final `28e0db620`:

- `php bin/check-skeleton-docker-secret-exclusion` exited 0: the positive
  control saw the leak on all three surfaces (60 context entries, 7 dotenv
  files), and the subject passed (40 entries, 8 saved blobs);
- with the four dotenv exclusions removed from `skeleton/.dockerignore`, it
  exited 1 and named the leak on the inventory, the image filesystem and the
  saved layer for every sentinel.

### Native Windows

Windows 11, PHP 8.5.5, at code-final `28e0db620`. Every contract command
except the locked install ran as its argument array, and each PHPUnit
command's logs went through the collector's own `nhe_phpunit_results()` and
`nhe_phpunit_violations()`:

| Command | Result |
|---|---|
| `root-hygiene-before`, `composer-policy`, `portable-paths`, `null-device-self-test`, `portable-null-device`, `root-hygiene-after` | exit 0 |
| `phpunit-unit` | 90 tests, 59 of 59 expected methods, no violation |
| `phpunit-integration` | 66 tests, 52 of 52 expected methods, no violation (18.9 s) |
| `phpunit-architecture` | 162 tests, 74 of 74 expected methods, no violation (22.7 s) |

### WSL Ubuntu 24.04

PHP 8.5.8 in a bundle clone (local Linux evidence only):

- the same replay at `28e0db620` passed with the same method counts;
- the full Architecture suite at `ae9467dcf` passed, 1,686 tests and
  43,623 assertions with no skip, including the real Docker proof test, in
  513 s;
- at `28e0db620`, `SubprocessHarnessContractTest`,
  `RecursiveRemoverContractTest`, `TestQualityInventoryTest`,
  `NativeHostBinInventoryTest` and the whole `SkeletonDockerSecretExclusionTest`
  passed (23 tests).

## Cost

Locally on native Windows, the added Integration tests take about 12 s, most
of it the split-main resolver's PHP launches, and the added Architecture tests
about 4 s. Under WSL the whole Integration and Architecture commands take 3 s
and 7 s. No job or step is added.

## Residual limitations recorded, not fixed

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
