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
No schema migration or additional content digest/UUID registry is needed.
Historical rows are matched by exact top-level UUID, not text search or numeric
IDs. Invalid JSON aborts atomically rather than claiming complete erasure.

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

Pending implementation, independent review and native verification.
