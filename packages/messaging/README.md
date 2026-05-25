# waaseyaa/messaging

**Layer 3 — Services**

Direct messaging infrastructure for Waaseyaa: threads, messages, participants.

**Why L3:** Messaging is the substrate for the chat surface (gap-matrix capability C-1 — Anokii Chat). Chat is a service abstraction, not a content type: it has per-thread access policies, real-time broadcast semantics, read-receipt tracking, and presence semantics that belong at the service layer. Graduating messaging to L3 unblocks the future Anokii Chat surface (real-time broadcast via the broadcasting infrastructure, presence, read receipts) as a separate mission. The graduation was introduced in mission `l2-content-types-consolidation-01KSEFTX` WP03.

`MessageThread` is the conversation container; `ThreadParticipant` records membership and read state per account; `ThreadMessage` is the individual message. Access policies enforce that only participants can read or post, and unread counts are derived from per-participant `last_read_at` rather than a separate read-status table.

Key classes: `MessageThread`, `ThreadMessage`, `ThreadParticipant`, `MessagingServiceProvider`.
