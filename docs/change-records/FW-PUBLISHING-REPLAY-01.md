# FW-PUBLISHING-REPLAY-01: current authorization for stored publication responses

9 October 2026. Local repair and candidate verification authorized; no publication,
issue mutation, merge, release or deployment. Owner: this repair lane. Base:
`98189412dbd4055c22c9583a01b3adca736b5906`, verified clean canonical main and
remote main. Isolated branch: `fix/publication-replay-authorization`.
Native Git executable: `C:/Program Files/Git/cmd/git.exe`. The repository
worktree coordinator rejects native absolute Windows paths before acquisition;
no lease was acquired or bypass attempted. This lane exclusively owns the
isolated worktree, which is retained for consumer qualification.

## Contract and bounded plan

A stored idempotent response is content released in the current request. With
an entity access handler composed, replay must satisfy current operation
authority and current entity/read-revision authority. Cached identity, not a
possibly reassigned slug, identifies the result. Existing-entity update denial
retains UNAUTHORIZED; hidden, absent or unreadable content retains NOT_FOUND.
Create authority and publish capability remain checked before lookup. The
explicit capability-only composition without an entity handler remains intact.

The store validates the original request fingerprint first, then invokes a
replay-only authorization callback inside its existing transaction. ContentPublisher
supplies policy; the store does not acquire entity knowledge. All five mutation
entrypoints participate: create/update/publish/unpublish/rollback. Rollback also
rechecks its source revision. Exact returned revision authority prevents current
view access from releasing a now-restricted historical payload.

Do not repeat concurrency checks, validation, transitions, persistence, audit or
completion work. Authorized replay returns original bytes after later revisions;
conflicting reuse still conflicts, and actor partitions are unchanged. This is
domain authorization integration in the existing store, not a new generic
idempotency implementation or a Symfony replacement project.

Read-only independent boundary investigation completed before editing. Root owns
the source/test/spec delta; a fresh independent reviewer owns patch review.
Regression-first tests cover revoked update, view, returned revision and rollback
source authority plus replay restoration without side effects. Direct callers
delegate through ContentPublisher; no separate adapter repair is planned.

Focused plan: publishing package tests, directly affected publishing gateways,
syntax/style, scoped static analysis and governed default preflight against the
verified main base. Broaden only for a demonstrated cross-boundary failure.
Native Windows does not establish full hosted/release qualification.

Release-note disposition: use the upgrade-guide alternative explicitly allowed
by `changes/README.md`, rather than inventing a positive forge issue number.
No public vulnerability issue is authorized. Root CHANGELOG remains untouched.

Verification, review, candidate identity and residuals will be recorded below.

## Investigation and focused evidence

Confirmed affected release: alpha.305, publishing split source
`d703164f3a64cf81a11a72cb1673e21eb17fa314`, entity-storage
`9352c2457ba90d6f9f877dbaebf1aa7021e5e064`, access
`319b6f4aaeef7768d5d1392a73fc0cf620142df5`. A disposable archived consumer,
fresh supported locked install and unchanged synthetic probe reproduced the
stored-response disclosure with exit 255. Its 42-package installed-lock check
passed. No original consumer file or installation was changed. The two affected
publishing implementations are identical between alpha.305 and the verified
main base; earlier release trains were not evaluated. This is not the existing
repository-composition issue #3008.

Capability checks precede lookup, but entity update and rollback revision checks
were inside the skipped mutation callback. Create checked create access but did
not authorize its now-existing result. Direct policy denial refused reads and
fresh publication; only cached content was disclosed. No duplicate write was
observed. Direct tool, advisory, layout and revision gateways delegate to the
same publisher, so no adapter-specific bypass remains in these entrypoints.

Native PHP 8.5.5, PHPUnit 13.1.14. Candidate-local root locked install completed
212 installs, zero updates/removals. Representative ContentPublisher reflection
resolves into this worktree's packages/publishing/src. Lock SHA256:
`8719A36FB5792012CE6F08DB9DF52351D9339345606B882E9D07ED2BFE3461BC`.

- Regression-only baseline: 16 tests / 31 assertions / 16 expected failures
  against unchanged main. The new store authorization test also failed before
  implementation. Initial test-authoring mistakes (wrong revision-history API
  and wrong scratch revision-table suffix) were corrected; they were not
  repository defects.
- `php -l packages/publishing/src/ContentPublisher.php` and
  `php -l packages/publishing/src/Idempotency/IdempotencyStore.php`: pass.
- `php vendor/bin/phpunit packages/publishing/tests packages/ai-tools/tests/Unit/Content/ContentToolSetTest.php packages/ai-tools/tests/Unit/Content/ContentToolSetReadAccessTest.php --no-coverage`:
  pass, 161 tests / 728 assertions, 3.391 seconds. Includes identity reassignment,
  pruned result revision, per-operation revocation, authority restoration,
  original response after later edits, unchanged audit/revisions, actor
  partitions, conflicts, completion failures and direct tool consumers.
- `php vendor/bin/phpstan analyse packages/publishing/src --no-progress --memory-limit=1G`:
  pass. Package syntax/style checks pass with repository CS Fixer configuration.
  An initial multi-path style invocation omitted the required config argument;
  the supported corrected invocation passed.
- `composer hooks:doctor`: installed hooks current. `git diff --check`: pass.
  `php bin/changelog-fragments validate`: 11 existing fragments valid; this
  candidate uses the documented upgrade-guide alternative.

Default preflight and independent immutable-candidate review remain in progress
at this source checkpoint. This checkpoint is not release qualification.
