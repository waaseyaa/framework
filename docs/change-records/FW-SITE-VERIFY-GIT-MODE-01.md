# Portable generated verifier across Windows Git and Linux

Base: `028ca8b3e5c1e650d685f532c63667d551c4fba1` (alpha.306).

## Problem and intended contract

A fresh Windows tutorial project passes local verification, but Git records
the generated `bin/maintenance/site-verify` as `100644`. Its ownership metadata
requires `0755`, so Linux verification rejects an otherwise identical checkout.
The reproduced failure and isolated correction are in
`jonesrussell/waaseyaa-guide-test-alpha306`, Actions run `39680462646`.

All supported verification entrypoints already interpret the script through
PHP. Generate it as `0644` on every host, and retain exact permission checking
on hosts with POSIX permission bits. The generated acceptance test must prove
both the declared regular-file mode and execution through `PHP_BINARY`.
Do not mutate a consumer's Git index, weaken the doctor, introduce a second
runner, or add a Git dependency to the generator. Existing alpha.306 projects
retain their recorded `0755` contract and can preserve it with Git's explicit
`update-index --chmod=+x` command until a reviewed dependency/regeneration update.

## Implementation and verification plan

1. Add a real Git index/checkout regression with `core.filemode=false`, the
   Windows behavior, and execute the generated acceptance test after checkout.
   Observe failure against the original generator before implementation.
2. Change only the verifier's generated mode and its owning acceptance contract;
   preserve the artifact path, PHP execution, strict doctor, and transaction engine.
3. Update focused renderer tests, replace the obsolete executable/noexec contract
   tests with the real Git checkout and mode-substitution boundary, and update expected generated metadata, canonical
   spec and starter guidance. The website guide remains on alpha.306 and states
   its Windows Git workaround plus free-port selection.
4. Run focused tests and required preflight; use hosted Linux qualification for
   permission enforcement. The Git round trip supplies independent boundary
   evidence, while a mutation to an executable checkout must still be rejected.
5. Obtain independent review of the immutable Framework candidate before landing
   because generated verification is a mandatory safeguard. Site prose uses
   recorded self-review. No new Framework release is included in this work.

Generic test subprocesses use installed Symfony Process with argument arrays
and a 30-second timeout; temporary directories use Symfony Filesystem. No new
runtime infrastructure or dependency is introduced. Native Git is
`C:/Program Files/Git/cmd/git.exe`; experiments stay outside the checkout.

## Evidence

Local evidence on native Windows, PHP 8.5.5, Composer 2.9.5, with candidate-local
dependencies installed from the unchanged committed lock:

- The real Git regression failed against the original renderer: expected `0644`,
  actual `0755`. It passed after the implementation (1 test, 3 assertions).
- Focused renderer and Git regression after formatting: 10 tests, 73 assertions.
- Site-contract unit tests excluding the two cache-isolation cases that require
  Windows symlink privileges: 377 tests, 1390 assertions passed. The excluded
  class fails identically on the unchanged base because symlink creation is denied.
- The directly affected CLI initialization/evaluation classes ran 45 tests and
  179 assertions. Five native-host cases produced one error and four failures
  involving POSIX directory synchronization, slash normalization, hard links and
  symlink privileges. All five reproduce on unchanged base source; these remain
  hosted Linux checks, not local passes.
- `php bin/check-pr-preflight` passed 43 gates with 3 not applicable, no failures
  and no hosted-required results in the default profile (303.3 seconds).
  The report is retained at
  `C:/dev/waaseyaa/windows-guide-delivery/preflight.json`.
- Project hooks are installed and current. Production code in strict doctor and
  transactional initialization is unchanged. No dependency or artifact-path change.

Independent review and hosted qualification remain acceptance steps for the
immutable candidate. Windows local checks do not substitute for hosted POSIX
permission enforcement, including rejection of a substituted `0755` mode.
Final candidate, review and hosted identities are recorded in the pull request
and delivery record so that evidence updates do not rewrite the reviewed code.
