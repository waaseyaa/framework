# waaseyaa/messaging

Layer 3: generic messaging primitives and access integration.

The package owns `MessageThread`, `ThreadParticipant`, `ThreadMessage`,
`MessagingServiceProvider` and `MessagingAccessPolicy`. It supplies membership
records, participant-based access decisions, atomic acting-creator membership
and a participant schema transition. It does not supply a complete send service,
read-receipt progression, unread-count service, presence or push delivery.

## Installation and composition

Messaging is opt-in; the default core/cms/full runtime closures do not include it.
Install a release compatible with the rest of the Framework cohort:

```sh
composer require waaseyaa/messaging
```

Composer's `extra.waaseyaa.providers` and `extra.waaseyaa.policies` declare
provider and policy discovery. The canonical kernel supplies the entity manager,
Framework Symfony event-dispatcher adapter and current account context. Registration
adds the three entity types; boot attaches the creator bootstrap subscriber.
Standalone manual composition must supply those same services and install the
normal entity/access read guard. Constructing entities or policies alone does
not qualify provider discovery, HTTP authorization or installed-package behavior.

The supported storage contract is default `sql-blob` on S1 SQLite. Thread and
participant repositories must support atomic SQL writes and share the managed
transaction connection. Memory-only composition is refused at provider boot;
directly registered subscribers also refuse unsupported writes. Missing manager
or Framework dispatcher currently skips subscriber registration; consumers must
not interpret that path as qualified messaging capability.

## Creation and access

Saving a new thread through canonical provider/repository composition captures
the actual authenticated account at PRE_SAVE and writes its owner membership
before transaction commit. Both rows persist, or neither does. Insertion failures
propagate. Submitted `created_by` does not establish the bootstrap actor. Trusted
actorless creation creates no membership. Discard failed in-memory entities and
use fresh objects when retrying; rollback does not rewind assigned identities.

Current policy permits authenticated thread creation and uses membership for
thread/message/participant access, with `administer content` bypass. Generic API
field-edit checks also inspect the target thread. This is current behavior,
not a settled role-specific invitation, sender-edit or deletion contract.
Membership authority, sender binding, last-owner rules, parent deletion,
retention and read-state progression remain explicit convergence decisions.
Application consent and moderation policy belong to consumers.

Protected entity/field factories provide immutable-principal decisions through
the access package's interfaces. Their concrete public adapter classes also
support independent PSR-4 loading. Outsider and missing-repository refusal is
preserved; no compatibility wrapper is needed.

## Schema and upgrades

Entity attributes declare schema transitions and the unique `(thread_id, user_id)`
key. `SqlSchemaHandler` applies `ThreadParticipantSchema` before that key. The
transition materializes identity columns, backfills valid stored identity data
and merges duplicate memberships into the lowest ID, retaining owner role,
earliest join and greatest read position. Repeated application preserves state.
Malformed/scalar blobs are not converted into invented identity data.

The provider does not own an independent backfill engine. Full no-dev split and
HTTP composition qualification remains an audit gap. See
[the enduring contract](../../docs/specs/messaging.md) and
[the assessment](../../docs/audits/packages/messaging.md).
