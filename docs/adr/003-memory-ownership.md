# ADR-003: Memory System Ownership Boundary

**Status:** Accepted
**Date:** 2026-03-23
**Repos:** waaseyaa/framework, jonesrussell/claudriel

## 1. Decision

Waaseyaa owns memory infrastructure (storage backends, retrieval semantics, vector indexing, memory lifecycle, privacy boundaries). Claudriel owns domain-specific memory types (what to remember, how to use memories, consolidation rules, and user-facing memory UX).

## 2. Invariant

**Framework defines memory mechanics. Application defines memory meaning.**

No application may implement its own vector store integration, retrieval algorithm, or memory lifecycle manager. Applications define memory entity types, configure retention policies, and build domain-specific recall logic on top of framework primitives.

## 3. Waaseyaa Scope

The framework provides:

- `MemoryStoreInterface` abstracting vector/semantic storage backends
- Embedding generation and indexing pipeline
- Retrieval API (similarity search, filtered recall, temporal decay)
- Memory lifecycle management (creation, consolidation, expiration, deletion)
- Privacy boundary enforcement (per-user, per-workspace isolation)
- Conversation history management (windowing, summarization)
- Episodic memory primitives (session boundaries, episode linking)

**Relevant milestones:**
- Agentic Framework M48 (#619-#623), specifically:
  - #620 ai-memory package (conversation, semantic, episodic memory)
  - #621 ai-guardrails package (privacy boundaries)

## 4. Claudriel Scope

The application provides:

- Domain memory entity types (person memories, commitment context, relationship history)
- Memory consolidation rules (when to merge, when to flag conflicts)
- Memory import pipeline (CSV/JSON ingestion)
- Memory UI (consolidation flow, search, audit trail)
- Agent tools for memory operations (import/status/search)
- Privacy controls specific to Claudriel's trust model
- Access recording (who accessed what memory, when)
- Domain-specific recall strategies (e.g., "recall everything about this person before a meeting")

**Relevant milestones:**
- v2.2 Memory and Graph Improvements (#410-#415)

## 5. Issue Impact

| Issue | Repo | Action |
|---|---|---|
| Claudriel #410 (memory entities + access recording) | Claudriel | Keep: domain entities using framework storage |
| Claudriel #411 (memory consolidation UI) | Claudriel | Keep: app-level UX |
| Claudriel #412 (memory import pipeline) | Claudriel | Keep: app-level ingestion |
| Claudriel #414 (agent tools for memory) | Claudriel | Keep: app-level tools |
| Claudriel #415 (privacy controls) | Claudriel | Keep: app-level policy on top of framework enforcement |
| Waaseyaa #620 (ai-memory package) | Waaseyaa | Keep: this IS the framework primitive |
| Waaseyaa #621 (ai-guardrails) | Waaseyaa | Keep: framework-level privacy enforcement |
| Waaseyaa #622 (ai-observability) | Waaseyaa | Keep: framework-level memory metrics |

No issues need to move. The split is already implicitly correct.

## 6. Rationale

- Memory is a cross-agent, cross-application primitive. Any Waaseyaa app with AI capabilities will need conversation history, semantic recall, and privacy boundaries.
- Vector store integration, embedding pipelines, and retrieval algorithms are infrastructure, not domain logic.
- Claudriel's v2.2 memory work is inherently domain-specific: what constitutes a "person memory" or "commitment context" is Claudriel's business, not the framework's.
- Building parallel memory infrastructure in Claudriel would make Waaseyaa's ai-memory package (#620) redundant and create a migration burden.
- This follows the entity storage pattern: Waaseyaa provides `SqlEntityStorage` (mechanics), Claudriel defines `Person`, `Commitment`, etc. (meaning).

## 7. Consequences

**Positive:**
- Claudriel's memory work focuses on domain semantics, not storage plumbing
- Other Waaseyaa apps inherit memory capabilities
- Privacy enforcement is consistent across all apps
- Single place to manage vector store credentials and embedding models

**Negative:**
- Claudriel's v2.2 is partially blocked until Waaseyaa's ai-memory package (#620) provides retrieval primitives
- Framework memory API must be designed with Claudriel's consolidation and conflict-detection patterns in mind
- Vector store selection becomes a framework-level decision affecting all consumers

**Neutral:**
- Claudriel can build memory entities, import pipelines, and UI now using standard entity storage, then layer semantic retrieval when the framework package ships
- The ai-guardrails package (#621) and Claudriel's privacy controls (#415) are complementary, not competing: framework enforces boundaries, app defines what boundaries exist
