# waaseyaa/queue

**Layer 0 — Foundation**

Async job queue for Waaseyaa applications.

`SyncQueue` is intentionally inline: handler exceptions propagate to the
dispatching caller and are not copied to the failed-job repository. Persistent
drivers execute through `Worker`, which records exhausted jobs and logs a
throwing `Job::failed()` hook without allowing that secondary failure to stop
the worker.

Provides a `JobInterface`, `JobMiddlewareInterface`, and queue backend abstraction for dispatching and processing background jobs. Uses Symfony Messenger conventions. Workers consume jobs outside the HTTP request lifecycle.

Key classes: `JobInterface`, `JobMiddlewareInterface`, `QueueInterface`.

Persistent scheduler delivery is an explicit extension rather than an implied
`QueueInterface::dispatch()` guarantee. `OccurrenceQueueInterface` attaches
the immutable `QueueOccurrenceV1` identity to the signed authority envelope,
and `Worker` delegates lease acquisition, fencing, completion, and
dead-letter transitions to the scheduler-supplied `OccurrenceRuntimeInterface`.
The queue transport reservation remains delivery mechanics, never execution
ownership.

## Persistent payload integrity

`DbalQueue` signs every serialized message with HMAC-SHA-256 under the
`waaseyaa.queue.payload-hmac.v1` application-derived key. `Worker` verifies the
strict versioned envelope before deserialization. Failed-job rows retain the
same signed envelope, and `queue:retry` verifies it before re-dispatch.

Existing pending and failed rows do not have this envelope. Before deploying
this change, either drain them with the previous release or intentionally clear
`waaseyaa_queue_jobs` and `waaseyaa_failed_jobs`. Persistent readers do not keep
an unsigned compatibility mode.

Hot-path note: HKDF runs once when the queue services are constructed. Each
dispatch performs one HMAC-SHA-256 plus URL-safe base64 encoding; each worker or
retry read performs one base64 decode, one HMAC-SHA-256, and one constant-time
comparison before the existing serialization work.

Local PHP 8.5.8 microbenchmark (2026-07-15, 100,000 in-memory seal+open
round trips over a serialized empty object): 396.58 ms total, 3.966 µs per
round trip. This isolates envelope work from database and job execution time.
