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
Keys already swept before erasure cannot be recovered; this operation protects
all applicable records still present, including expired-but-unswept rows.
Do not claim protection for already-swept keys or fresh keys recreating content.
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
  Refusal-before-sweep source delta and final contract await proportional review.

Final native preflight and exact immutable review are pending. Hosted full
qualification remains unrun, owned by `ci/full-qualification`. This local-only
candidate is not release-qualified and does not alter the alpha hold.
