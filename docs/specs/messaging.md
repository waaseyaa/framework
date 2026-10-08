# Messaging package contract

**Package:** `waaseyaa/messaging`  
**Layer:** 3, Services

Messaging owns generic thread, membership and message entity primitives,
participant access integration, transactional creator membership and participant
schema integrity. It does not own a product chat UI, consent/moderation policy,
presence, live push, federated delivery or a complete send/read-state service.

## Entity and authority boundaries

`MessageThread` is conversation metadata, `ThreadParticipant` stores membership
and read-position metadata, and `ThreadMessage` stores message content and sender
metadata. Constructor defaults are not a live lifecycle engine. The package does
not maintain thread activity on every message mutation or automatically advance
read positions. A stored `last_read_at` can be an input to consumer calculations;
there is no package unread-count implementation.

Current access policy uses membership for view/update/delete decisions and
requires authentication for creation, with the administrative bypass. Generic
API field-edit decisions inspect a child entity's target thread. Role-specific
membership grants/revocation, sender attribution, edit/delete authority, parent
lifetime, last-owner behavior and retention are unsettled D2 convergence
contracts; current permissive behavior must not be mistaken for a completed
contract. Consumer consent/moderation is separate.

Protected read factories implement the access package's immutable-principal,
structural-identity and compiled-subject interfaces. Entity and field adapters
serve that current authority boundary and independently autoload at their PSR-4
paths. Ordinary mutable-entity access and Protected decisions have different
refusal semantics; adapter layout changes must not merge these authorities.

## Composition and storage

Messaging is an explicit Composer opt-in and is absent from the default
core/cms/full runtime closures. The manifest declares the provider and policy.
The canonical kernel supplies entity manager, Framework Symfony dispatcher,
account context and read guard. Provider registration declares entity metadata;
boot attaches the creator subscriber. Missing manager/dispatcher currently
returns without wiring: this is an unqualified profile, not successful proof.

Supported storage is default sql-blob on S1 SQLite. Memory compositions are
refused. The intended kernel profile and no-dev split, HTTP and non-HTTP actor
journeys still need explicit D3 qualification. Source tests or synthetic supplied
services do not count as installed profile evidence.

### Participant bootstrap (`ThreadParticipantBootstrapSubscriber`)

Authenticated thread creation through the canonical repository must persist a thread and exactly one acting-creator owner in the same SQL transaction, or persist neither. `ThreadParticipantBootstrapSubscriber` captures the authenticated account at `PRE_SAVE`, keyed by thread object, and inserts its `role: owner` membership at `EntityPersistedEvent`. This storage event runs after identity assignment and entity hooks, before the surrounding single or batch transaction commits. It is not a `POST_SAVE` notification. An insertion failure propagates, rolls back both rows and suppresses commit notifications.

The account comes from `AccountContextInterface`, never submitted `created_by`. Actorless trusted CLI/system creation remains supported with no automatic membership. A failed mutation's in-memory entities must be discarded before a fresh retry; database rollback does not rewind hook calls or assigned identities. Successful repeated saves are updates and do not reseed membership. The database unique pair continues to fence duplicates; fresh requests do not acquire a new cross-request idempotency contract.

Provider boot resolves the thread and membership repositories before creation and explicitly rejects memory-only composition. The supported package profile remains default `sql-blob` on SQLite; thread and participant writes must share the same managed transaction connection. A separate participant connection refuses authenticated creation. FW-MESSAGING-ATOMIC-CREATE-01 / #2753 records the replacement of the earlier post-commit bootstrap.

### Participant uniqueness (`ThreadParticipantSchema`)

After generic repository resolution materializes the sql-blob base table, `ThreadParticipantSchema` additively creates and backfills dedicated `thread_id` and `user_id` columns. Before installing the composite unique key, an upgrade deterministically collapses any legacy duplicate pair into the lowest-`tpid` row while retaining the strongest membership state: `owner` wins over `member`, the earliest `joined_at` survives, and `last_read_at` takes the greatest value. The normal storage driver routes subsequent values to the dedicated columns, so API, subscriber, CLI, and direct repository writes all share the same database-enforced membership invariant.

## Structural repair

The empty deprecated post-save callback is removed in alpha; PRE_SAVE and the
transactional persisted event remain canonical. No compatibility shim is kept.
Participant decoding uses one stored-blob normalization rule. Missing or invalid
identity data remains untouched, and persisted duplicate repair remains necessary.
Schema authority belongs to entity declarations and SqlSchemaHandler, not a
parallel provider backfill implementation.

## Evidence and outstanding decisions

FW-MESSAGING-ATOMIC-CREATE-01 / #2753 records landed creator atomicity.
The package assessment under #3118 records D2 lifecycle/authority and D3
installation decisions, API-owned content-identity intake and remaining gaps.
FW-MESSAGING-PUBLIC-CONTRACT-01 / #3188 owns the bounded structural/public
contract reconciliation. This repair does not settle D2 or qualify every profile.

`bin/check-package-layers` assigns messaging to L3. Required access, database,
entity, entity-storage and foundation dependencies remain lower-layer edges.
