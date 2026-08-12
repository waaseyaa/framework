# waaseyaa/scheduler

**Layer 0 — Foundation**

Task scheduling with cron expression support for Waaseyaa.

`Schedule` is a registry of `ScheduledTask`s configured via the `ScheduleBuilder` fluent API. `ScheduleRunner` runs cooperative direct tasks under durable renewable leases and fences. Persistent queued tasks use a deterministic occurrence ledger plus transactional enqueue outbox; the worker acquires its own execution lease and reports completion separately from enqueue. Each invocation produces a `ScheduleRunResult` summarizing enqueued/executed, skipped, and failed task IDs.

Key classes: `Schedule`, `ScheduleInterface`, `ScheduleBuilder`, `ScheduleRunner`, `ScheduleRunResult`, `ScheduledTask`.
