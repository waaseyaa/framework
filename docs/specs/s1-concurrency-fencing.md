# S1 entity concurrency and scheduler fencing

Status: implementation contract for `S1-FW-DB-03`.

Parent candidate: `S1-FW-DB-02` commit
`4f0eaeaa69733f7cf6fb3c91d5a0a98c354ff72d`, tree
`d0564935a578238ddd23ef73250867a64540137d`.

This is a forge-neutral change record. Git objects, executable tests, exact
installed artifacts, and signed evidence are the authorities. No forge,
registry, issue, pull request, hosted artifact, or hosted approval is required
to reproduce or verify this package.

## Outcome

Persisted entities reject stale updates, deletes, pointer moves, translations,
and batch operations by default. Overlap-protected scheduled work uses durable,
renewable leases and fences every durable effect; expiry can never turn an old
worker into an authorized writer.

This package closes the framework implementation boundary for `F-DB-003`,
`F-DB-004`, and `F-DB-005`. Exact Sheg compatibility remains required before
those findings or S1 can close.

## Aggregate mutation authority

Every persisted entity identity owns one DB-02-managed authority record keyed
by storage authority, tenant/community, entity type, and canonical entity ID.
It contains a monotonic aggregate version, an unguessable opaque tag, active or
tombstone state, and the last accepted scheduler fence per declared fence
domain. A revision ID is never the aggregate version: in-place saves,
translations, lifecycle pointers, and disciplined forward drafts can change
without mapping one-to-one to a revision head.

Repository loads hydrate an opaque `EntityMutationToken` from that authority.
Creation states an absent-row condition. Existing update, delete, translation,
history, pointer, and batch commands require the token; omission fails before
events or writes. Blind maintenance is a distinct privileged, audited command
with an explicit reason, never a nullable token or environment switch.

After pure validation, the first authoritative mutation is one compare-and-swap
on the authority record inside the same transaction as base, bundle, revision,
translation, pointer, fence, outbox, and audit writes. Transactional guards and
write-capable pre-events run only after the claim. A losing command produces no
row, revision, pointer, event, outbox entry, or external effect. A no-op retains
the token and emits no mutation event; every effective success advances exactly
one aggregate version and returns the successor token.

Deletes retain a minimal tombstone through the stale-client and retained-backup
horizon. Re-creation advances the authority rather than resetting it, preventing
ABA acceptance. Purge is separately authorized and dependency-aware.

Batch commands sort canonical identities before claims, reject duplicate
identities, and commit all items or none. History operations state the exact
expected aggregate token and relevant current, working, or published heads.
Backfills use bounded, resumable batches rather than an unbounded transaction.

Workflow transitions use the internal `AggregateMutationRepositoryInterface`
command boundary. The transition mutation runs only after the aggregate claim;
revision creation, status finalization, pointer guards, publication pointer
movement, and the configured audit write share that transaction. Pointer or
required-audit failure rolls everything back, and one successful workflow
command advances the aggregate version exactly once.

## Protocol surfaces

HTTP reads return one canonical quoted strong ETag. Existing-entity mutation
requires exactly one strong `If-Match`; absence returns 428, stale state returns
412, and weak, wildcard, or list validators are rejected. JSON:API If-Match
surfaces share `Waaseyaa\Api\Http\EntityMutationPrecondition` for that envelope.
Conflict responses do not disclose the current token or mutable entity data.
Admin surface mutations keep the body `mutation_token` transport
(`fromOpaqueString()`); page-builder keeps revision/fingerprint fencing and
does not carry `EntityMutationToken`.

Admin, JSON:API, GraphQL, MCP/AI, CLI, workflow, publishing, migration, worker,
translation, and batch surfaces carry the same opaque expectation. Arbitrary
automatic retries are forbidden. Only registered deterministic commutative
operations may re-read, recompute, and retry within a bounded policy.

Direct coordinator and driver mutation paths are internal or enforce the same
authority. Public upsert is not a mutation command: create, update, and delete
have distinct intent and failure semantics.

## Lease and fence authority

Production overlap prevention requires the authoritative database. It never
falls back to process memory. A stable lease-domain row retains owner,
expiration, renewal generation and nonce, and fence history after release. One
global signed-64-bit sequence supplies comparable fences to domains that share
a durable resource.

Acquire and renew use integer-millisecond database time and exact
owner/fence/generation compare-and-swap. The database returns its time and
expiry; local monotonic elapsed time, measured round-trip, and a safety margin
schedule heartbeats. Same-tick renewal must extend ownership. Clock rollback,
invalid TTL/precision, ambiguity, and counter overflow fail readiness.
When a response is lost after commit, the authority accepts success only when
read-back proves the exact owner, fence, successor generation, attempt nonce,
expiry, and a remaining horizon larger than the measured round-trip margin.
An old but still-live expiry is not evidence that renewal succeeded.

`ScheduleRunner` resolves `LeaseAuthorityInterface`, never the legacy lock
adapter. A database-less composition receives `UnavailableLeaseAuthority` and
an overlap-protected run is a structured failure, not an overlap skip. Only a
live competing owner produces `skipped: overlap`. An overlap task must have an
explicit stable name and implement `LeaseAwareCommandInterface`; arbitrary
closures and job-class strings are rejected because they cannot renew or carry
a fence. Direct commands receive `LeaseExecutionContext` and renew before and
after execution and before each declared durable effect. Lease loss is fatal and
must escape best-effort domain catches.

Database-local effects pass through `DatabaseFenceGuard`. Its row is keyed by
resource and lease domain and is updated in the same database transaction as
the supplied effect. A lower fence is rejected, the same deterministic effect
is a no-op even when occurrence recovery owns a higher fence, and a distinct
effect at the same fence is rejected unless a
future domain-specific ordering contract explicitly provides stronger
semantics. A failed effect rolls back its fence claim. The retention purge,
redaction, and hold-conflict writers use this boundary around repository and
audit writes. A composition that places those writes in another database must
fail readiness until it supplies an equivalent sink-local guard; transaction
nesting does not imply cross-database atomicity.

Every effect carries lease domain, global fence, deterministic occurrence ID,
and effect ID. Entity writes check and advance the accepted resource fence in
the same transaction as the entity CAS. Equal delivery of the same effect is a
no-op; distinct equal-fence effects require declared ordering. Duplicate-safe
additive external effects may use occurrence/effect idempotency. Ordered
replacement effects require destination fence/CAS or a serialized transactional
outbox that discards stale fences. An unfenceable task cannot claim
`preventOverlap`.

## Occurrence and queue ownership

A scheduled occurrence is uniquely identified by stable task ID, schedule
generation, and canonical due instant. Manual runs require an idempotency key.
The producer transaction records the occurrence and enqueue outbox. A durable
worker separately acquires the execution lease; it never informally resumes a
producer lease. Recorded-before-enqueue, enqueue-before-ack,
effect-before-completion, retry, and dead-letter states reconcile idempotently.

The direct-command path now records the scheduled occurrence before acquiring
execution ownership. `ScheduledTask::scheduleGeneration()` binds the stable
name, cron expression, timezone, command type, overlap policy, and TTL; the due
instant is the canonical UTC minute. `OccurrenceRepository::begin()` attaches
the winning global fence. A completed occurrence cannot run again, while a
failed or abandoned occurrence can be recovered only by a higher fence. The
occurrence ID scopes every sink effect ID, so retries are exact replays rather
than unrelated writes. Manual direct runs require an `Idempotency-Key`; its
digest becomes the stable trigger key, and repeating it returns the completed
occurrence without another effect. The admin SPA creates one UUID per operator
confirmation and the HTTP boundary returns 428 when it is absent. A task
without durable occurrence and fence protection is refused with 409 rather
than accepting an idempotency key it cannot honor.
Transactional enqueue outbox, worker-side acquisition, retry, and dead-letter
state are now explicit for persistent queued commands. The scheduler records
the occurrence and outbox in one database transaction, then a dispatcher sends
a signed `QueueOccurrenceV1` identity. An ambiguous transport result retains
the outbox row for same-occurrence delivery retry. The worker acquires a new
execution lease, restores the occurrence context, checkpoints around the body,
and completes only after its fenced effects finish. Duplicate delivery is
acknowledged without execution; live contention is deferred without consuming
the attempt budget. Transport exhaustion or repeated dispatch failure moves
both the delivery authority and occurrence to a durable dead-letter state.

Queue dispatch means enqueued, not completed. A void dispatch return,
serialization, and `UniqueJob` marker are not cross-process ownership. Generated
closure names are not stable identities. The three classification-retention and
two agent-maintenance overlap closures must become stable lease-aware commands.

Only a proven live owner is an overlap skip. Busy/locked, connection, timeout,
permission, schema, and unknown database failures remain failures. Ambiguous
acquire or renewal runs nothing unless exact owner, fence, generation, nonce,
and safety horizon are proven by read-back.

## Verification boundary

The exact predecessor keeps all 48 critical DB-03 anchors byte-identical to the
reviewed design baseline. The complete mutation-call vocabulary still resolves
to 89 first-party candidates; DB-02 changed ten excluded schema/storage matches
only by removing runtime DDL. The successor inventory is regenerated and
hash-bound before implementation evidence is sealed.

Retained reds cover two-reader/one-winner updates across entity shapes, stale
delete races, all-or-nothing batches, translation and pointer races, every
supported protocol surface, lease overrun/renewal ambiguity, stale fenced
effects, deterministic occurrences, queue crash points, database fault
classification, and all five unstable overlap closures.

The package is complete only when split Unit, Integration, Architecture,
static-analysis, package-layer, Composer-policy, secret, formatting, roster,
and isolated installed-artifact gates pass at one exact candidate and an
independent read-only review is reconciled. This authorizes no release,
deployment, production, backup, restore, or recovery operation.
