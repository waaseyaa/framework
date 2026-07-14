# Messaging — L3 Chat Substrate

<!-- Spec reviewed 2026-07-14 - R21 #2010: thread_participant identity is now a persistence invariant. MessagingServiceProvider materializes/backfills dedicated thread_id and user_id columns after the generic sql-blob table exists, then installs a composite UNIQUE key on (thread_id, user_id). Duplicate membership is rejected by the database on every write surface. -->
<!-- Spec reviewed 2026-07-06 - #1915 R16 (audit L3-messaging.md): closed the participant-bootstrap deadlock. Nothing previously seeded the first thread_participant row, so MessagingAccessPolicy::fieldAccess()'s "must already be a participant to create the first row" gate made messaging unusable for non-admins. Fix: ThreadParticipantBootstrapSubscriber (packages/messaging/src/EventSubscriber/) subscribes to EntityEvents::PRE_SAVE/POST_SAVE for MessageThread and, on genuine creation, seeds the acting account (from AccountContextInterface, never the entity's own created_by field) as a thread_participant with role 'owner'. Wired in MessagingServiceProvider::boot(). Acceptance: ThreadParticipantBootstrapSubscriberTest (real EntityRepository + real EventDispatcher, no mocked persistence). -->
<!-- Spec reviewed 2026-06-22 - WP14 (alpha245 security, audit #31): the participant-only access guarantee (only participants can read or post) is now BACKED BY CODE. MessagingAccessPolicy (implements AccessPolicyInterface + FieldAccessPolicyInterface, #[PolicyAttribute(['message_thread','thread_message','thread_participant'])], EntityTypeManager injected by the policy dependency resolver) enforces: read via access('view') participant-only; post/modify via fieldAccess('edit') Forbidden-unless-participant (store() runs the field-edit check on the constructed message, which carries thread_id — the only create-time hook that sees the target thread; createAccess() does not); thread creation via createAccess() for any authenticated account. Admins (administer content) bypass. Participation is checked with an accessCheck(false) system query against thread_participant. Spec text was already accurate; this records that the enforcing code now exists. Acceptance: MessagingAccessPolicyTest. -->
<!-- Spec reviewed 2026-05-25 - l2-content-types-consolidation-01KSEFTX - WP03 - messaging L3 graduation -->

**Package:** `waaseyaa/messaging`  
**Layer:** 3 — Services  
**Spec status:** Initial (L3 graduation baseline)

---

## Why L3

`waaseyaa/messaging` provides a direct-messaging substrate (threads, messages, participants) that is the foundation for the **Anokii Chat** surface (gap-matrix capability C-1). Chat is a service abstraction, not a content type:

- Per-thread access policies (only participants can read or post).
- Read-receipt semantics derived from per-participant `last_read_at`.
- Future: real-time broadcast via the broadcasting infrastructure, presence, federated delivery.

Placing messaging at L2 (Content Types) was an initial approximation. The L3 graduation aligns the package with its service role, unblocks the future Anokii Chat surface mission, and keeps the content-type layer (L2) focused on entity shapes that admin SPA pages list and edit directly.

The graduation was introduced in mission `l2-content-types-consolidation-01KSEFTX` WP03 (2026-05-25).

---

## Data Model

| Class | Role |
|---|---|
| `MessageThread` | Conversation container. Holds metadata: subject (optional), created\_at, participant set. |
| `ThreadParticipant` | Per-account membership record. Stores `last_read_at` for unread-count derivation. |
| `ThreadMessage` | Individual message. References `MessageThread` as parent; stores sender, body, created\_at. |

All three are entity types registered via `MessagingServiceProvider` and discoverable through `EntityTypeManager`.

---

## Access Policy Model

Access is enforced at the entity level by an access policy (registered via `#[PolicyAttribute]`):

- Only participants in a `MessageThread` can read messages in that thread.
- Only participants can post new `ThreadMessage` entities.
- Thread creation is open to any authenticated account.
- Unread counts are derived from `ThreadParticipant::last_read_at` — no separate read-status table exists.

Field-level access follows the open-by-default rule (`FieldAccessPolicyInterface`: Neutral = accessible, only Forbidden restricts).

---

## Service Provider

`MessagingServiceProvider` is auto-discovered via `extra.waaseyaa.providers` in `composer.json`. It registers the three entity types with `EntityTypeManager` (`register()`) and, in `boot()`, subscribes `ThreadParticipantBootstrapSubscriber` to the entity event dispatcher.

### Participant bootstrap (`ThreadParticipantBootstrapSubscriber`)

`createAccess()` allows any authenticated account to create a `message_thread`, but `fieldAccess()` requires the acting account to already be a `thread_participant` before it may create a `thread_participant`/`thread_message` row for that thread — a deliberate chicken-and-egg gate with no bypass baked into the policy itself. `ThreadParticipantBootstrapSubscriber` closes the gap from outside the policy: it listens for `EntityEvents::PRE_SAVE`/`POST_SAVE` on `MessageThread`, and on genuine creation (captured via `isNew()` at PRE_SAVE, the same two-phase pattern `Waaseyaa\Audit\Listener\EntityLifecycleAuditListener` uses) inserts a `thread_participant` row for the creator with `role: 'owner'`. The seeded account is read from `AccountContextInterface::current()`, never the entity's own `created_by` field, so a spoofed `created_by` value cannot seed membership for a different account (the same discipline #1645 established for audit actor attribution). If no acting account exists (CLI/system context), the thread is created with no participants — consistent with the existing admin-bypass-only creation path.

### Participant uniqueness (`ThreadParticipantSchema`)

After generic repository resolution materializes the sql-blob base table, `ThreadParticipantSchema` additively creates and backfills dedicated `thread_id` and `user_id` columns and installs a composite unique key on `(thread_id, user_id)`. The normal storage driver routes subsequent values to those columns, so API, subscriber, CLI, and direct repository writes all share the same database-enforced membership invariant.

---

## Out of Scope (follow-up missions)

The following capabilities are **not** in scope for this package in its current state. Each is a separate follow-up mission:

- **Real-time presence** — tracking online/typing status per thread.
- **Read-receipt UI** — surfacing `last_read_at` in the Anokii Chat SPA.
- **Federated XMPP/Matrix bridge** — cross-protocol delivery.
- **Push notifications for new messages** — integration with `waaseyaa/notification`.
- **Admin SPA chat management pages** — thread moderation, participant management UI. (Tracked by `l2-harden-messaging-01KSEW82`.)
- **Anokii Chat surface** — the full real-time chat UI for the Anokii distribution. (Separate post-WP03 mission.)

---

## Layer Gate

`bin/check-package-layers` assigns `"messaging": 3`. Its access, database, entity, and foundation dependencies are all lower-layer edges. No L2 package may require `waaseyaa/messaging`.
