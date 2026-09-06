# FW-QUEUE-JOB-CONTRACT-01 — document the real application job type

Status: candidate  
Anchor mirror: waaseyaa/framework#2822  
Parent candidate: `origin/main`

## Intent

Stop telling consumers to implement `Waaseyaa\Queue\JobInterface`, which does
not exist. The supported application job extension point is abstract `Job`.

## Decisions

1. Do not add a `JobInterface`. Application jobs subclass `Job` and implement
   `handle()`, matching `JobHandler`.
2. Classify `Job` public. `HandlerInterface`, `TransportInterface`, and
   `FailedJobRepositoryInterface` stay internal.
3. README key classes name only loadable types. Job middleware is foundation
   `JobMiddlewareInterface`, not a queue-package class.
4. `QueueJobContractSurfaceTest` fails if README names a missing type or if
   `Job` is not public.

## Verification

`packages/queue/tests/Unit/QueueJobContractSurfaceTest.php` plus the existing
`JobTest` / `JobHandlerTest` subclassing proofs.
