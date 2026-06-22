# MyClaudia — Product Design

**Date:** 2026-03-08
**Status:** Approved
**Context:** Brainstorming session; supersedes MyMe (abandoned)

---

## Core Identity

MyClaudia is a **cloud-first AI personal operations system** — a cockpit for the knowledge worker's entire day. It replaces the local Claudia daemon as the canonical mind. Local tooling (CLI, future desktop/mobile apps) becomes a thin client and integration bridge.

**The killer feature is continuity of mind** — not any single module, but the AI that knows you, remembers yesterday, and synthesizes rather than aggregates.

**The core daily loop:**
1. **Day Brief** — personalized AI synthesis to start the day (not a dashboard, not a feed)
2. **Unified Inbox** — normalized signals across sources, prioritized by relationship and commitment context
3. **Loose ends** — commitments, follow-ups, drift detection from last session

---

## Architecture

### Three Layers

**Layer 1 — Waaseyaa Backend (engine)**
Multi-tenant PHP 8.3+ / Symfony 7 foundation. Provides: entity system, event ingestion pipeline, AI skill execution, memory graph, API surface, multi-tenancy. No new infrastructure needed — waaseyaa already has ingestion, entities, the AI layer, and the event model.

**Layer 2 — MyClaudia Application (product)**
The eight core entities, ingestion adapters for MVP integrations, Day Brief pipeline, and Commitment extraction skill. This is the cockpit.

**Layer 3 — Claudia Personality (mind)**
The AI reasoning layer that runs on top of the data — synthesizes, surfaces, nudges, and maintains relationship + commitment awareness across sessions. Unified across web, CLI, and eventually native apps.

### Memory Model: Hybrid Event + Graph

- **Events** — raw, immutable, timestamped facts (message arrived, meeting scheduled, PR opened). The stream that feeds the Day Brief.
- **Entities** — stable nodes (Person, Commitment, Project, Integration). Waaseyaa entities.
- **Edges** — relationships between entities (Commitment → Person, Commitment → Event, etc.).

**Day Brief query pattern:** *What events touched my high-priority entities since last session?* The AI synthesizes; it does not aggregate.

### Cloud-First Identity

- Memory graph lives in MyClaudia (Waaseyaa DB)
- Skills execute in MyClaudia (queue-based)
- Personality is unified across all surfaces
- CLI, desktop, mobile are all thin clients
- Local daemon (current Claudia) becomes optional: offline cache + local integration bridge

---

## Core Entities

| Entity | Purpose |
|--------|---------|
| `Account` | Multi-tenant user, preferences, integration credentials |
| `Integration` | Connected source (Gmail, Calendar, GitHub, etc.) |
| `Event` | Immutable ingested fact with source, timestamp, raw payload |
| `Message` | Normalized communication (email, etc.) linked to Event |
| `Person` | Extracted or explicit contact, relationship context |
| `Commitment` | Tracked obligation (see below) |
| `Project` | Work context grouping commitments and people |
| `Memory` | Long-term AI observations, patterns, learnings |

### Commitment Entity (detail)

```
id, title, description
source_event_id     → the event that created it
person_id           → who it's owed to or from
project_id          → optional
status              → pending | active | completed | dropped
confidence          → 0.0–1.0 (implicit only; 1.0 for explicit/system)
due_at              → optional
created_at, updated_at
```

**Edges:** Commitment → Person, Commitment → Project, Commitment → Event, Commitment → Message

**Three commitment types:**
- **Implicit** — AI-inferred from message content ("I'll send that over tomorrow"). Confidence-scored; surfaced as candidates in Day Brief.
- **Explicit** — User-confirmed ("track this"). Full weight in drift detection and relationship intelligence.
- **System** — Deterministic from structured sources (PR assigned, calendar event requiring prep, deadline in metadata).

---

## Data Flow

```
Gmail API
    → Ingestion Adapter
    → Event (immutable, timestamped, source-tagged)
        → Person extraction (sender/recipients → Person entities + edges)
        → Commitment extraction skill (AI, confidence-scored)
            → Commitment entities (pending/active/system)
                → Day Brief pipeline
                    (query: events since last session, high-priority entities)
                        → AI synthesis
                            → Day Brief output (web UI + CLI)
```

**Drift detection** runs on a schedule against active Commitments — surfaces anything with no activity past its expected response window.

---

## Day Brief Structure (v0)

1. **What changed since yesterday** — new messages, new events, new PRs
2. **Commitments touched** — new, updated, drifting (no activity), due soon
3. **Relationships that matter today** — people with open commitments, overdue replies
4. **Attention required** — high-confidence implicit candidates awaiting confirmation, system commitments

**Delivery:** Pull-first for MVP (user opens app, sees brief). Push (email digest, notification) is phase 2 — not until the brief content is proven.

---

## MVP Scope

### In (Phase 1)
- Gmail ingestion → Events → People → Commitments
- Commitment extraction skill (implicit + explicit + system-derived)
- Drift detection
- Day Brief v0 (pull model, web UI)
- CLI read-only: `myclaudia brief`, `myclaudia commitments`
- Waaseyaa multi-tenant foundation (single user initially, architected for many)

### Out (Phase 2)
- Google Calendar ingestion
- GitHub ingestion
- Write-back to email (reply, draft)
- Task extraction from commitments
- Push notifications (email digest, mobile)
- Native desktop + mobile apps
- Multi-tenant onboarding flow / billing

---

## Error Handling

- **Gmail API failures** — log + retry queue; never block Day Brief generation
- **Partial data** — degraded brief surfaced to user, not a broken app
- **Commitment extraction failures** — events still stored, commitment creation skipped (re-runnable)
- **AI skill failures** — fall back to raw event list in Day Brief (always show *something*)

---

## Testing Strategy

Waaseyaa's in-memory backends mean all pipeline logic is unit-testable without live API calls:

- **Unit** — Ingestion adapters, commitment extraction logic, drift detection, Day Brief query
- **Integration** — Full pipeline with fixture email threads (thread → events → commitments → brief)
- **AI skill tests** — Fixture messages with known expected commitments, confidence threshold validation
- **In-memory** — `InMemoryEntityStorage` for all entity operations; `PdoDatabase::createSqlite()` for integration tests

---

## Implementation Sequence (Approach 2: Day Brief-first)

1. Scaffold MyClaudia as a Waaseyaa app (from skeleton)
2. Define core entities in Waaseyaa entity system
3. Gmail ingestion adapter → Event + Person creation
4. Commitment extraction AI skill
5. Day Brief pipeline + web UI (minimal, clean)
6. CLI commands (`myclaudia brief`, `myclaudia commitments`)
7. Drift detection (scheduled job)
8. User confirmation loop (track / ignore / done)

---

## Relationship to Existing Projects

| Project | Role |
|---------|------|
| `waaseyaa` | Framework — engine for MyClaudia; gaps surfaced by building MyClaudia get fixed upstream |
| `claudia` (this repo) | Predecessor — local daemon becomes thin client; personality/skills informed by Claudia's design |
| `myme` | Abandoned — vision absorbed; desktop app was too slow to iterate on |

MyClaudia is **Claudia 2.0**: cloud-first, one memory, one personality, multi-surface.
