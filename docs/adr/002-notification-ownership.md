# ADR-002: Notification System Ownership Boundary

**Status:** Accepted
**Date:** 2026-03-23
**Repos:** waaseyaa/framework, jonesrussell/claudriel

## 1. Decision

Waaseyaa owns notification delivery primitives (transport abstraction, retries, rate limiting, provider failover). Claudriel owns notification definitions, channel configuration, templates, and user-facing delivery flows.

## 2. Invariant

**Framework delivers messages. Application decides what messages exist.**

No application may implement its own delivery transport, retry logic, or rate limiting. Applications define notification types, select channels, compose content, and configure recipients.

## 3. Waaseyaa Scope

The framework provides:

- A `NotificationInterface` with channel routing
- Transport drivers (SMTP, webhook, SMS gateway, push)
- Delivery retry with configurable backoff
- Rate limiting per channel and recipient
- Provider failover (e.g., primary SMTP fails, fall back to secondary)
- A `ChannelInterface` that applications can extend
- Delivery event dispatching for observability

**Relevant milestones:**
- Feature Parity Roadmap #592 (notification system, multi-channel)

## 4. Claudriel Scope

The application provides:

- Notification type definitions (commitment reminder, brief delivery, follow-up nudge)
- Channel configuration per user/workspace (which channels are active, preferences)
- Message templates and content composition
- Outbound delivery service orchestrating notification-to-channel mapping
- Admin UI for channel management
- Agent tools for channel operations (list/send/last_message)
- Inbound channel listeners (Slack webhook receiver, email reply parser)

**Relevant milestones:**
- v2.3 Onboarding and Export (#389-#393)
- v2.8 Multi-channel Ingestion (#366-#368, #384)

## 5. Issue Impact

| Issue | Repo | Action |
|---|---|---|
| Claudriel #389 (inbound channel listeners) | Claudriel | Keep: app-level ingestion |
| Claudriel #390 (outbound delivery service) | Claudriel | Keep: app-level orchestration using framework transports |
| Claudriel #391 (agent tools for channels) | Claudriel | Keep: app-level UX |
| Claudriel #392 (admin UI for channels) | Claudriel | Keep: app-level UX |
| Claudriel #393 (channel integration tests) | Claudriel | Keep: app-level tests |
| Claudriel #366 (Slack ingestion) | Claudriel | Keep: app-level channel |
| Claudriel #367 (unified commitment view) | Claudriel | Keep: app-level aggregation |
| Claudriel #368 (mobile daily brief) | Claudriel | Keep: app-level push notification |
| Claudriel #384 (auto-ingestion) | Claudriel | Keep: app-level automation |
| Waaseyaa #592 (notification system) | Waaseyaa | Keep: this IS the framework primitive |

No issues need to move. Claudriel's notification issues are correctly scoped as application-level consumers.

## 6. Rationale

- Delivery reliability (retries, rate limits, provider failover) is infrastructure that every app needs identically.
- Channel configuration, templates, and user preferences are inherently app-specific.
- Claudriel's v2.3 and v2.8 milestones would duplicate Waaseyaa #592's work if both build delivery engines.
- This mirrors how web frameworks handle notifications: Laravel provides `Notification` + channels, apps define `toMail()`, `toSlack()`, etc.

## 7. Consequences

**Positive:**
- Claudriel's channel work focuses on UX and domain logic, not transport plumbing
- Other Waaseyaa apps inherit reliable multi-channel delivery
- Single place to manage provider credentials and failover

**Negative:**
- Claudriel's v2.3 outbound delivery (#390) is partially blocked until Waaseyaa #592 ships
- Framework notification API design must accommodate Claudriel's inbound+outbound pattern

**Neutral:**
- Claudriel can build channel UX and agent tools now, wiring to framework transports later
- Inbound channel listeners (Slack webhooks, email parsing) remain entirely app-level regardless
