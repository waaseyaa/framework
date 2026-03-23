# ADR-001: Scheduling Ownership Boundary

**Status:** Accepted
**Date:** 2026-03-23
**Repos:** waaseyaa/framework, jonesrussell/claudriel

## 1. Decision

Waaseyaa owns task scheduling execution (cron parsing, job persistence, retries, failure handling, worker lifecycle). Claudriel owns task definitions (what runs, when, and why).

## 2. Invariant

**Framework owns execution. Application owns intent.**

No application may implement its own scheduler runtime, job queue, retry logic, or failure recording. Applications define task classes and schedule expressions; the framework executes them.

## 3. Waaseyaa Scope

The framework provides:

- Cron expression parsing and schedule resolution
- Durable job persistence and failure recording
- Retry policies with configurable backoff
- Worker process lifecycle (start, stop, health)
- A `SchedulableTaskInterface` that applications implement
- CLI command for executing due tasks (`waaseyaa:schedule:run`)

**Relevant milestones:**
- v1.9 Production Queue Backend (#516, #517, #519, #525)
- Feature Parity Roadmap #591 (task scheduling / cron system)

## 4. Claudriel Scope

The application provides:

- Concrete task classes implementing `SchedulableTaskInterface`
- Task type definitions (weekly doc refresh, PR summary, daily brief)
- Schedule expressions per task (e.g., "every weekday at 7am")
- Admin UI for task management (enable/disable, view history)
- Agent tools for task CRUD (create/list/pause)
- Observability hooks specific to Claudriel's domain

**Relevant milestones:**
- v2.9 Scheduled Tasks & Autonomous Ops (#394-#400)

## 5. Issue Impact

| Issue | Repo | Action |
|---|---|---|
| Claudriel #394 (ScheduledTask entity) | Claudriel | Keep: app-level entity wrapping framework primitives |
| Claudriel #395 (scheduler CLI) | Claudriel | Narrow: delegates to Waaseyaa's scheduler, adds app-specific task discovery |
| Claudriel #396 (task types) | Claudriel | Keep: these are app-level task definitions |
| Claudriel #397 (agent tools) | Claudriel | Keep: app-level UX |
| Claudriel #398 (admin UI) | Claudriel | Keep: app-level UX |
| Claudriel #399 (observability hooks) | Claudriel | Keep: consumes framework telemetry |
| Claudriel #400 (tests) | Claudriel | Keep: app-level test coverage |
| Waaseyaa #591 (task scheduling) | Waaseyaa | Keep: this IS the framework primitive |
| Waaseyaa #516-#519, #525 (queue backend) | Waaseyaa | Keep: foundation for scheduler |

No issues need to move between repos. The boundary is already implicitly correct in the issue descriptions; this ADR makes it explicit and prevents future drift.

## 6. Rationale

- Scheduling semantics (cron parsing, retries, backoff, job persistence) are cross-application concerns that every Waaseyaa app will eventually need.
- Claudriel's v2.9 scheduler work depends on a durable queue backend, which Waaseyaa already plans in v1.9.
- Building a Claudriel-specific scheduler would make Waaseyaa's queue backend redundant or force a painful migration later.
- This follows the same pattern as entity storage: Waaseyaa provides `SqlEntityStorage`, Claudriel defines entity types.

## 7. Consequences

**Positive:**
- Single scheduler implementation, tested and maintained in one place
- Claudriel's v2.9 work becomes simpler (task definitions only, no runtime)
- Other Waaseyaa apps get scheduling for free

**Negative:**
- Claudriel's v2.9 is blocked until Waaseyaa v1.9 ships the queue backend
- Framework changes require the release-tag-update cycle

**Neutral:**
- Claudriel can prototype task definitions now using a simple cron-in-CLI approach, then swap to framework primitives when ready
