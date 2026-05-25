# Messaging — L3 Chat Substrate

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

`MessagingServiceProvider` is auto-discovered via `extra.waaseyaa.providers` in `composer.json`. It registers the three entity types with `EntityTypeManager`.

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

`bin/check-package-layers` assigns `"messaging": 3`. The package requires only `waaseyaa/entity` (L1) and `waaseyaa/foundation` (L0) — both downward edges, gate-clean. No L2 package may require `waaseyaa/messaging`.
