# FW-PUBLICATION-REPLAY-ERASURE-01

Scope: supported targeted publication replay erasure, based on
`2c072dd09a37f2129fcea5d0ab4a5aed1ba9027b`. Publishing owns retained projections;
entity-storage owns entity/revision deletion, database owns atomic completion,
and access owns current replay authorization. This is a bounded boundary audit,
not a complete package convergence assessment.

## Intended contract and plan

Add `IdempotencyStore::eraseContentResponses(string $uuid): int` for trusted
erasure orchestration. Capture the canonical entity UUID before deletion; invoke
supported repository deletion and this operation within one outer transaction
using the same database object. Do not expose this storage-level operation as a
user endpoint without application erasure authorization. UUIDs must uniquely
identify an aggregate across this store. Matching spans actors, operations and
descriptors because all those projections belong to the same erased aggregate.

Each matching JSON snapshot becomes literal `null`. Clear operation, request
hash and creation time; keep only the existing opaque key as the refusal marker.
The marker must never expire while the store accepts arbitrary old client keys.
Any use of that key refuses without executing or exposing a response. Unaffected
records retain their conflict, TTL and current-authorization behavior.
An erased retry refuses before sweep, so it cannot delete unrelated expired rows.
Ordinary request sweeping now shares its transaction and rolls back on refusal.
Review repair converts expired responses into the same content-free markers,
so a sweep cannot remove protection before deletion. Erasure requires durable
issued-key admission. `enableIssuedKeyAdmission()` is the cutover operation;
`issueKey()` supplies time-bounded client keys afterward. Unknown legacy keys
already swept by older versions are refused after cutover rather than guessed
from absent data.
No schema migration or additional content digest/UUID registry is needed.
Historical rows are matched by exact top-level UUID, not text search or numeric
IDs. Invalid JSON aborts atomically rather than claiming complete erasure.
The content-free guarantee requires already opaque ContentPublisher keys.
Direct calls with empty namespace preserve raw keys, which must contain no
content; content-bearing raw legacy keys require separate remediation.

Record failing tests first, implement the operation, run publishing/access and
real transaction tests, review an immutable candidate independently, then run
the native default preflight and commit evidence. Root owns final integration
and the heavy slot; this lane owns only the publishing delta, spec and record.
Broaden only for transaction, schema or authorization failures beyond this slice.

## Audit and limits

Repository deletion never promised derived replay erasure. Existing response
snapshots include UUID; opaque actor namespaces cannot be reconstructed from
stored keys. Existing mutation-authority tombstones protect aggregate writes,
but cannot correlate a create retry's opaque publication key to an erased UUID.
The 48-hour opportunistic sweep previously removed the only correlation.
Keys already swept by older versions cannot be recovered; the admission
cutover rejects unknown legacy retries. The operation protects all applicable
records still present, including expired-but-unswept rows. A fresh issued key
remains a new mutation and is not duplicate-content prevention.
Consumers must fence publication during the deletion transaction and must not
reuse erased aggregate UUIDs. Erasure failure rolls back that transaction;
completion failure after commit is a committed outcome, not a rollback.

Symfony Cache and Lock were assessed against official documentation: cache
eviction loses replay protection; locks serialize live work but do not preserve
durable refusal after process exit. This change adds publishing domain policy
to its existing transaction/store, not a generic cache or lock mechanism. No
dependency installation impact. Publishing owns this decision; re-evaluate if
the store is extracted into generic middleware or a maintained durable
idempotency component supplies equivalent erasure/transaction semantics.

Read-only issue search (`publishing idempotency`, all states, limit 30) found
actor partition repair #2555, atomic projection repair #2141, and transaction
outcome work #2999/#3035. Their protections remain required. Consumer handoff
distinguishes retained storage from repaired response authorization. No issues
were published. Alpha hold, release and deployment boundaries remain in force.
Read-only bodies of #3008, #2555 and #3035 were reconciled: repository-composition
qualification and CLI/MCP committed-outcome qualification remain separate open
work; this slice preserves the closed actor-partition repair.

## Evidence

Native Windows PHP 8.5.5 / PHPUnit 13.1.14; candidate-local publishing class
reflection resolved under this worktree's `packages/publishing/src`.
`vendor/waaseyaa/publishing` is a junction to this candidate, not a donor.
Unchanged lock SHA-256:
`8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`.
Fresh canonical local main inspection: `bff4b104d29ba484307dc6655796be10837ff85e`.
This candidate preserves the separately qualified authorization repair at its
exact base; main does not yet contain it. No rebase or other-lane edits occurred.

- Failing regression: `php vendor/bin/phpunit packages/publishing/tests/Unit/IdempotencyStoreTest.php --filter 'erasure|failed_and_outer' --no-coverage`:
  two missing-method errors before implementation, 1.879s.
- `php vendor/bin/phpunit packages/publishing/tests/Unit --no-coverage`:
  152 tests, 588 assertions pass, 3.341s after refusal-before-sweep repair.
  Covers five snapshots across two actors/five verbs, deletion/revisions,
  unaffected replay, access revocations/conflicts, repeated erasure, malformed
  JSON, mid-update database failure and completion failure.
- `php vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryTransactionalHooksTest.php packages/entity-storage/tests/Unit/Concurrency/EntityRepositoryAggregateConcurrencyTest.php tests/Integration/Transaction/RepositoryOutermostCompletionTest.php --no-coverage`:
  32 tests, 388 assertions pass, 2.403s on the first implementation candidate.
  Adjacent transaction evidence, not erasure-specific concurrent-writer proof.
- Immutable first implementation: `b679ce15d684c20c636aed51d87da6f2aea5e2fc`.
  Precommit portable paths and formatting passed; formatting took 52.88s.
- Default preflight on that commit: 43 executed, 0 reused, 226.2s; four failures
  from two mechanical causes: missing test-only CREATE/DROP trigger roster
  entries and invalid fragment naming. Both were repaired without weakening
  gates. The documented upgrade-guide alternative supplies release-note
  discipline without inventing or publishing a forge issue number.
  Native report: `%TEMP%/publication-replay-erasure-preflight.json`.
- Independent Sol 6.1 low reviewer requested the raw-key contract clarification.
  Its independent synthetic file-backed SQLite probe forcibly terminated a
  worker before and after outer commit, then reopened from another process:
  rollback before commit and durable marker refusal after commit both passed
  (~2.0s). It used a synthetic effect table, not repository deletion. Native
  scratch evidence: `%TEMP%/erasure-independent-probe.php` and
  `%TEMP%/erasure-review-07980a92-e05d-4252-bae6-504a541ad682`.
  Probe SHA-256:
  `a54502f6d883bc6ccf04f29f5c358cd0b63656f47f806bfe39065e82c4a025b7`.
- Final implementation candidate:
  `ca47087aedb202df2672183fe8969bedd2aca028`.
  Independent reviewer **APPROVE**, no blocking code findings. The proportional
  review independently tested refusal-before-sweep using a DELETE-aborting
  SQLite trigger; failed ordinary operations restored swept rows, successful
  ordinary operations removed expired responses while preserving markers
  (~0.55s). Probe `%TEMP%/erasure-ttl-delta-probe.php`, SHA-256
  `d518fbcaa2f6f1992a7f8fd5e38c675039357ad34cd9b721827233be631f7a28`.
  Reviewer-requested prose correction distinguishes erasure writes from
  write-free retries. Prior crash evidence applies to the unchanged erasure
  implementation. The adjacent suites do not call publishing/idempotency, so
  their original evidence remains applicable to the unchanged repository,
  transaction code, dependencies, configuration and runtime.
- `php bin/check-pr-preflight --base=2c072dd09a37f2129fcea5d0ab4a5aed1ba9027b --report-json=<scratch>/publication-replay-erasure-final-preflight.json`:
  **PASS** on the clean final implementation candidate, 42 executed, 0 reused,
  119.3s, 0 failed, 0 hosted-required, 4 not-applicable. This was the default
  native profile, not full static or hosted qualification. The final evidence
  commit changes only this record and the reviewer-requested prose correction;
  code, tests, roster, dependencies and configuration remain identical.
  Evidence is carried forward as equivalent inputs, not a claim of execution
  on the subsequent documentation commit. Document links and diff hygiene were
  checked on that final delta; no unchanged broad checks were repeated.

Hosted full qualification remains unrun, owned by `ci/full-qualification`.
No actual concurrent erasure/publication process test, cross-database operation
or consumer integration qualification is claimed. Consumer fencing is an
explicit precondition. This local-only candidate is not release-qualified and
does not alter the alpha hold. Application qualification is a separate consumer-owned
step, not Framework publication authorization.

## Blocking review repair (2026-10-11)

Review of candidate `44ee0af703cf9d1a325b05e88407a752cbf866eb`
reproduced a swept create key recreating an erased entity with one new audit
effect. The supplied `swept-retry.php` produced `erased_rows=0` and
`retry_created_new_entity=true` before repair. A new real-publisher regression
failed with `Swept retry recreated erased content` before implementation.
The repaired regression creates after admission cutover, ages and sweeps its
response, deletes the entity and erases in one transaction, then proves the
original issued key refuses without another audit effect. The store regression
covers an already-swept legacy key whose syntax resembles the new format.

The independent reviewer identified and then verified repair of an initial
syntax-only admission gap. New issued keys carry an HMAC under the durable
random cutover secret. Their independent no-configuration store and publisher
tests passed (91 tests, 382 assertions), with no remaining blocking finding.
The original reproduction now stops safely at the cutover precondition because
its script does not enable admission. The post-cutover publisher regression is
the repaired end-to-end discriminator.

On the dirty repair diff, native PHP 8.5.5 publishing Unit passed (155 tests,
596 assertions), adjacent repository/transaction tests passed (32 tests,
388 assertions), and default `check-pr-preflight` passed 42 executed gates,
0 failed, 0 hosted-required and 4 not-applicable in 158.6 seconds. Final
commit identity and clean-tree gate equivalence must be recorded after commit.

## Admission-state review repair (2026-10-11)

Review of `da8a30760760aa477eb73300cf59d361c95b3e24` found that an
existing admission row with a malformed marker or secret was treated as
never-enabled admission. The supplied `admission-failure.php` showed one
legacy callback execution after synthetic secret corruption. Four
data-provider regressions cover the marker, secret, operation and creation
sentinels. The marker and secret cases failed before implementation. The
repair validates persisted state before replay and sweep and raises a stable
non-secret error on corruption; rollback prevents cleanup writes from
committing. Valid absence still permits pre-cutover operation. No secret is
regenerated and the cutover row is retained. Recovery requires restoring the
original row from trusted backup or a separately reviewed migration while
ingress remains closed.
