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
