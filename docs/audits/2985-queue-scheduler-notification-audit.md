# #2985 — queue / scheduler / notification audit

Audit lane of **#2985** (framework-wide competing-implementation audit).
Read-only. No runtime change, no class removal, no public-surface
reclassification is proposed or performed here. No test suites were run.

- **Pinned source SHA:** `8747683eaa0c063995a7560e6ef4c480c2ec5b4c` (`origin/main`)
- **Identity:** findings are against that commit on `main`. No unmerged
  candidate was consulted.
- **Leads:** #2818, #2745, #2743, #2741, #2747 taken as starting points and
  re-derived. #2822 was treated as historical evidence to verify, not an open
  deliverable. **All five open leads are confirmed.** Unusually for this
  program, none was stale — but two were confirmed by a different mechanism
  than a casual reading suggests, and the severity of three is bounded by a
  configuration fact the issues do not mention.

## 1. Scope and coverage matrix

| Area | Reviewed | Depth | Notes |
|---|---|---|---|
| `packages/queue/src` (50 files) | ✅ | Dispatch, persistence, claim, settle, retry, worker | |
| `packages/scheduler/src` (39 files) | ⚠️ Partial | Lease/Fence/Occurrence compared against queue | Full scheduler audit not performed |
| `packages/notification/src` (11 files) | ✅ | Full file inventory + recipient traced hop by hop | |
| `packages/api/src/Controller/BroadcastStorage.php` | ✅ | `pushRetained` write path | |
| `packages/mail` boundary | ⚠️ Partial | `Envelope`/`Mailer` recipient handling only | Transports not audited |
| Queue → scheduler handoff | ✅ | Outbox seam identified | |
| Worker isolation (#2818) | ✅ | Filesystem/process/network/resource searches | |
| Failed-job retry claim | ✅ | `claimForRetry` latch | |
| **Mail transports** | ❌ Not reviewed | — | Determines empty-`to` behaviour |
| SSE read/delivery path | ✅ | Channel resolution + authorization | Read scoping verified correct |
| Scheduler execution model | ✅ | Lease TTL, checkpointing, due-detection, backfill | |
| **Scheduler `Schedule/Ai` subtree** | ❌ Not reviewed | — | Out of lane |
| **Symfony Messenger transport** | ❌ Not reviewed | — | `MessageBusQueue` delegates outward |
| **Consumer apps** | ❌ Not reviewed | — | Out of repository |
| **Test suites** | ❌ Not run | — | Static audit per scope |

## 2. Actual call paths and implementation ownership

**Dispatch.** One contract: `QueueInterface::dispatch(object $message): void`
(`packages/queue/src/QueueInterface.php:59`), four implementations —
`SyncQueue` (inline), `InMemoryQueue` (test), `MessageBusQueue` (Symfony),
`DbalQueue` (persistent). `OccurrenceQueueInterface::dispatchOccurrence`
(`OccurrenceQueueInterface.php:72`) adds scheduler identity; only `DbalQueue`
implements it. No divergent second enqueue API.

**Persistence.** `DbalTransport::push` → table `waaseyaa_queue_jobs`
(`Transport/DbalTransport.php:60-72`; schema `Migration/CreateQueueTables.php:16-31`).
Payload is PHP `serialize()`, optionally wrapped in `QueueEnvelopeV1`, then
HMAC-sealed by `SignedQueuePayload::seal` (`Security/SignedQueuePayload.php:42-57`).

**Claim.** `DbalTransport::pop` (`:74-177`) — SELECT then conditional UPDATE in
a bounded retry loop (`MAX_CLAIM_RETRIES = 50`). Fresh claim guards on
`reserved_at IS NULL` (`:128-132`); reclaim guards on `reserved_at = $prior`
and bumps `attempts` (`:139-146`). Visibility timeout default 90s
(`QueueServiceProvider.php:63`), enforced **at read time**, no sweeper.

**Execution.** `Worker::processJob` (`Worker/Worker.php:161-308`), `\Throwable`
caught (`:262-307`) → `handleFailure` (`:323-350`).

**Settlement.** `ack`/`reject`/`release`/`defer` (`DbalTransport.php:179-213`).

**Scheduler handoff.** `ScheduleRunner::run()` routes string-command tasks to
`enqueueTask()` (`:53-54`), which writes a durable outbox row
(`Occurrence/OccurrenceOutboxRepository.php`) then dispatches via
`OccurrenceOutboxDispatcher::dispatchEntry` → `queue->dispatchOccurrence(...)`
(`Occurrence/OccurrenceOutboxDispatcher.php:55`). Closure and
`LeaseAwareCommandInterface` tasks execute **inline**, never touching the
queue. This is a write-ahead-outbox pattern and is a genuine durability
mechanism, not a competing queue.

### Ownership

| Capability | Owner | Entry | Notes |
|---|---|---|---|
| Job contract | `waaseyaa/queue` | abstract `Job` | `@api`, declared `public` |
| Persistent transport | `waaseyaa/queue` | `DbalTransport` | declared `internal` |
| Execution | `waaseyaa/queue` | `Worker` | |
| Scheduled occurrence identity, lease, fence | `waaseyaa/scheduler` | `DatabaseLease`, `DatabaseFenceGuard`, `OccurrenceRepository` | |
| Notification routing | `waaseyaa/notification` | `NotificationDispatcher` | |
| Retained broadcast | `waaseyaa/api` | `BroadcastStorage` | |

## 3. The configuration fact that bounds severity

**The default queue driver is `sync`.**
`QueueServiceProvider::register()`: `$driver = $this->config['queue']['driver'] ?? 'sync'`
(`packages/queue/src/QueueServiceProvider.php:38`), and `SyncQueue` executes
handlers inline with no persistence (`SyncQueue.php:41`).

Every defect in §4 except F5 requires the **database** driver. An operator who
has not opted into persistence is not exposed. This does not reduce the
defects' correctness severity — it changes who is affected, and it is not
stated in any of the five issues. It should be recorded on each.

## 4. Confirmed findings

### F1 — Settlement is unfenced; a stale worker can destroy a live claim
**CONFIRMED. #2741. Verified directly, not only by delegation.**

All four settlement operations key **only on `id`**
(`packages/queue/src/Transport/DbalTransport.php:179-213`):

```
ack()     DELETE ... WHERE id = ?
reject()  DELETE ... WHERE id = ?
release() UPDATE ... SET reserved_at = NULL, available_at = ?, attempts = attempts + 1 WHERE id = ?
defer()   UPDATE ... SET reserved_at = NULL, available_at = ? WHERE id = ?
```

No predicate references `reserved_at` or any per-claim identity, and `pop()`
returns only the row id — the worker never receives a claim receipt it could
present back.

Sequence:
1. Worker A claims job X (`reserved_at = 100`).
2. A stalls beyond the 90s visibility timeout without dying (GC pause, slow
   I/O, swap).
3. Worker B reclaims X legitimately (`:139-146`), `attempts` bumped, and begins
   executing.
4. A wakes and calls `ack(X)` → **`DELETE WHERE id = X`**, destroying B's live
   claim. B's own later settle affects 0 rows, unchecked.
5. If B then fails, there is no row to retry. **The job is silently lost.**

Worse variant: A's stale `release(X)` clears `reserved_at` while B is still
running, so a third worker C can claim X concurrently — genuine double
execution, not merely double settlement.

**Scope of the claim, narrowed (Codex review).** This is a data-loss path
*under stated preconditions*, not a general property of the queue:

1. the **database** driver is configured (the default is `sync`, §3);
2. a worker stalls **without dying** for longer than `visibilityTimeout`
   (default 90s, `QueueServiceProvider.php:63`) — a dead process settles
   nothing and is handled correctly by reclaim;
3. that stalled worker then **wakes and settles**, rather than being killed;
4. a second worker has already reclaimed the row in the interval.

All four must hold. The audit did **not** measure how often a stall of that
shape occurs, and makes no frequency claim. What is established is that the
settle predicates permit the interleaving, not that it is common.

### F2 — The scheduler already solved F1; the queue never received the fix
**CONFIRMED. Competing authority with asymmetric safety.**

`packages/scheduler/src/Occurrence/OccurrenceRepository.php:75-95` predicates
every settlement on `status = 'running' AND execution_fence = ?`, so a stale
owner's settle affects 0 rows and raises rather than mutating another owner's
claim. `Lease/DatabaseLease.php` mints a strictly increasing `fencing_token`
plus `renewal_generation`/`renewal_nonce`; `Fence/DatabaseFenceGuard.php:17-70`
rejects a stale fence with `StaleFenceException` and treats a same-fence replay
as an idempotent no-op.

The queue has no equivalent for generic jobs. `OccurrenceContextInterface::fence()`
exists (`packages/queue/src/Occurrence/OccurrenceContextInterface.php:12`) but
covers only scheduler-dispatched occurrences, and it protects the **business
effect**, not the queue row — the transport settle remains unconditional either
way.

No comment or spec in `packages/queue` justifies the omission, while the
scheduler's code describes closing a "split-brain reclaim window".

**Narrowed (Codex review).** Two things this does *not* establish. First, the
scheduler's fencing is not a drop-in for the queue: it guards *occurrence
settlement and durable effects* keyed by `(resource_key, fence_domain)`, over a
lease the scheduler itself mints, whereas the queue's settle is a transport
operation on a row id with no lease handle in the caller's hands. Adopting it
means designing a claim receipt for the transport, not copying a class.
Second, "divergence rather than intentional difference" is an inference from
the *absence* of a justifying comment — it is not evidence that anyone decided
against fencing the queue. The accurate statement is that the queue's settle
path is unfenced and no document explains why.

### F3 — The failed-job retry claim is a one-way latch
**CONFIRMED. #2743, by the mechanism the issue actually names.**

`DatabaseFailedJobRepository::claimForRetry()`
(`packages/queue/src/Storage/DatabaseFailedJobRepository.php:103-109`) sets
`retried_at = <timestamp>` guarded by `retried_at IS NULL`. The guard makes the
claim atomic, but the claim carries **no owner and no expiry**. If the retrying
process dies before `releaseRetryClaim()` (`:111-118`), the row remains
`retried_at IS NOT NULL` forever, no other process can claim it, and no sweeper
exists. The failed job is **permanently stranded**.

Related and separate: an operator cannot distinguish a running job from an
orphaned one. `applyStatusFilter` derives status purely from
`reserved_at IS NULL` / `IS NOT NULL` (`DbalTransport.php:296-306`), with no
lease-expiry awareness — so a dead worker's row reports `in_progress`
indefinitely. That is precisely #2743's "without a recoverable handoff
outcome".

### F4 — Async notifications substitute the mail recipient
**CONFIRMED. #2745, localized to one expression.**

`SendNotificationJob` carries only `notifiableType`, `notifiableId`,
`notification`, `channels` (`Job/SendNotificationJob.php:28-33`) — **no address
field**. On the worker, `SendNotificationHandler::buildNotifiable()` constructs
an anonymous notifiable whose routing is
(`Job/SendNotificationHandler.php:70-76`):

```php
return match ($channel) {
    'database' => $this->id,
    default    => null,
};
```

Mail routing is unconditionally `null`. The original entity is never reloaded.
A `toMail()` following the framework's own canonical pattern
(`packages/api/src/Controller/NotificationController.php:210-211`) then falls
back to a sentinel address — so the recipient is **silently substituted, not
merely lost**.

The sync path is correct: `send()` passes the caller's real notifiable through
(`NotificationDispatcher.php:53-68`). `sendAsync()` on the default `SyncQueue`
also short-circuits to `send()` (`:75-79`) — which is why tests pass. The
defect appears only on a real queue, and no test exercises
`sendAsync` + real queue + a `toMail()` that calls `routeNotificationFor()`.
That coverage gap is why it shipped.

### F5 — Retained broadcast writes are not atomic; the leak question is UNRESOLVED
**Non-atomicity CONFIRMED. The earlier disproof of #2747's leak claim is WITHDRAWN.**

**Correction (Codex review).** A previous revision of this report asserted that
#2747's "leak live messages" claim was *disproved from the code*, on the
grounds that `retainedFor($channels)` is only ever called with channels
`resolveSubscriberChannels()` has already authorized. **That disproof is
withdrawn.** Establishing a negative — that no interleaving anywhere can expose
a retained message to an unintended subscriber — requires more than showing one
read path filters correctly, and this audit did not do that work. The claim is
returned to **unresolved**, and #2747 should not be re-scoped or de-prioritised
on the strength of the withdrawn analysis.

What remains **CONFIRMED**, verified directly:
`BroadcastStorage::pushRetained()`
(`packages/api/src/Controller/BroadcastStorage.php:69-97`) performs a log
`INSERT`, a retained `DELETE` and a retained `INSERT` as three sequential
unguarded statements with **no transaction** — the file contains no transaction
call, though `transactional()` is used elsewhere in the codebase
(`packages/scheduler/src/Lease/DatabaseLease.php:36`,
`Fence/DatabaseFenceGuard.php:23`). The delete-then-insert is commented as a
portable upsert safe because "the retained set is written by a single presenter
emitting sequentially" — an assumption, not an enforced invariant.

**What the confirmed finding actually is.** The defect is *publication despite a
reported failure*, not cross-channel disclosure. Because the log `INSERT`
(`:79-82`) commits before the retained `DELETE`/`INSERT` (`:89-94`) and nothing
wraps the three in a transaction, a fault after the first statement leaves the
operation reporting failure to its caller while a **pollable row already exists
in `_broadcast_log`**. An authorized subscriber on that channel therefore
receives a message the writer believes was not published — and a caller retry
re-runs the log `INSERT`, so the same subscriber receives it twice.
`EmitBeaconController.php:124` calls `pushRetained` with no try/catch.

The recipient in this finding is entitled to the channel. The harm is
publication of a message whose write was reported as failed, and duplicate
delivery on retry — an integrity and idempotency defect, not a confidentiality
one.

Two further **CONFIRMED** failure modes, none claimed exhaustive:
- DELETE succeeds and INSERT throws → the retained row is gone (loss on replay).
- A concurrent same-key retry would collide on the
  `_broadcast_retained(channel, retain_key)` primary key
  (`packages/api/migrations/2026_08_12_000001_broadcast_schema.php:34`).

**Separate hypothesis, UNRESOLVED:** whether any sequence exposes a retained
message to a subscriber *not entitled to it*. That is a distinct,
confidentiality-shaped claim from the integrity finding above, it is not
established by this audit, and this report's earlier attempt to *disprove* it
was withdrawn. Resolving it needs an adversarial interleaving analysis of
write-failure states against the read path. Neither the confirmed finding nor
the withdrawn disproof should be read as settling it.

Separately documented and not a finding: non-privileged broadcast channels have
no per-channel ACL — `docs/specs/broadcasting.md:315-316` states any
authenticated session may subscribe to any non-privileged channel.

This finding is independent of the queue driver — it is on the API request path.

### F10 — Missed scheduler occurrences are silently skipped
**CONFIRMED. No owning issue found.**

`ScheduledTask::isDue()` (`packages/scheduler/src/ScheduledTask.php:52-60`)
matches the cron expression against the current minute only, and
`ScheduleRunner::run()` (`ScheduleRunner.php:42-91`) keeps no persisted
last-successful-run marker and performs no catch-up. `ScheduleRunHandler`
(`packages/cli/src/Handler/ScheduleRunHandler.php:19-22`) is one-shot against
`new \DateTimeImmutable()`. **If the scheduler is not invoked during a task's
due minute, that occurrence is skipped — not run late, not backfilled.**
Invocation cadence is entirely the operator's responsibility, and nothing
records the gap.

This is a legitimate design for cron-driven schedulers, but it is undocumented
in the package README and invisible at runtime, which makes it a reliability
surprise rather than a stated constraint.

### F11 — An inline scheduled task can outlive its own lease
**CONFIRMED, bounded.**

`ScheduledTask::$lockTtl` defaults to 300s (`ScheduledTask.php:28`).
`runTask()` checkpoints the lease before and after the command
(`ScheduleRunner.php:286-288`) but **not during** it, so a lease-aware inline
command that runs longer than its TTL without calling
`LeaseExecutionContext::checkpoint()` itself can have its lease reclaimed by
another host mid-run.

The blast radius is bounded by design: `DatabaseFenceGuard`
(`Fence/DatabaseFenceGuard.php:17-70`) refuses a stale owner's *durable
effects*, so the damage is wasted work rather than corrupted state — which is
precisely the protection the queue lacks in F1/F2. Constructor validation also
prevents a plain `Closure` from ever declaring `preventOverlap`
(`ScheduledTask.php:37-42`).

### F6 — `#[UniqueJob]`/`#[RateLimited]` are a DOCUMENTED LIMITATION
**Reclassified (Codex review). Not a defect, and no new issue is warranted.**

A previous revision recorded this as an unowned behavioural divergence and
recommended opening an issue. **That was wrong.** `DbalQueue`'s own class
docblock documents the limitation prominently and by name
(`packages/queue/src/DbalQueue.php:22-32`):

> **Important — `#[UniqueJob]` / `#[RateLimited]` are NOT enforced by this
> driver.** Both attributes are handled exclusively by `AttributeGuard`, which
> performs pure in-process / per-PHP-process tracking. They are enforced by
> `SyncQueue` (same process) but NOT by `DbalQueue` … Cross-process enforcement
> would require a distributed dedup/rate-limit store and is currently
> unimplemented.

The behaviour is additionally made **non-silent** by design: a warning is
logged once per job class per process when such a message is dispatched
(`DbalQueue.php:179-221`).

So the facts stand — `AttributeGuard` is called only by `SyncQueue`
(`SyncQueue.php:44`), and `waaseyaa_queue_jobs` carries no unique constraint
(`Migration/CreateQueueTables.php:16-31`) — but the disposition changes: this is
a **stated limitation with a deliberate operator signal**, not an undecided
divergence. The earlier recommendation to open an issue is withdrawn. If
cross-process uniqueness is wanted, that is a feature request against a known
gap, not a correctness repair.

### F7 — Declared job timeouts are never enforced
**CONFIRMED. Contributes to #2818. Verified directly.**

`Job::$timeout` (`Job.php:25`, default 60) and `WorkerOptions::$timeout`
(`Worker/WorkerOptions.php:22`) are declared but consumed nowhere:
`rg -n "set_time_limit|pcntl_alarm|SIGALRM|->timeout" packages/queue/src packages/cli/src/Handler/QueueWorkHandler.php`
returns **no enforcement**. The only live bound is `WorkerOptions::$memoryLimit`,
a cooperative check evaluated **between** jobs (`Worker.php:411-416`) — it
cannot bound a single job's runtime or a runaway allocation within one job.

### F8 — No worker isolation boundary exists
**CONFIRMED. #2818 is accurate and unaddressed.**

No filesystem restriction, no `open_basedir`/chroot, no guard on
`proc_open`/`exec`, no network egress policy, and no per-job wall-clock bound.
The worker resolves database credentials through the same container as the web
request, so a job handler runs with web-equivalent privileges. Signals are
handled only between jobs, and only when `pcntl` is loaded
(`Worker.php:395-429`); without `pcntl` the process dies on default disposition
and the row waits for lease expiry.

### F9 — Job payloads are authenticated, not encrypted
**CONFIRMED. Security-relevant; no issue found.**

`SignedQueuePayload::seal()` (`Security/SignedQueuePayload.php:42-57`) computes
an HMAC and base64-encodes the payload; `packages/queue/src` contains no
encryption. The full serialized job — all constructor arguments and state — is
therefore recoverable by anyone with database read access, and is echoed by
`queue:failed`. Separately, `DatabaseFailedJobRepository::record()` stores
`$e::class . ': ' . $e->getMessage()` verbatim (`:29`), so an exception message
interpolating payload data persists that data in plaintext.

This is a **property statement, not an allegation of a vulnerability**, and it
is narrower than "payloads are exposed": the reader must already hold database
read access or operator access to `queue:failed`, which is the same trust
boundary as every other row the framework stores in plaintext. Signing is what
the design claims — integrity, not confidentiality — and confidentiality at
rest may be a deliberate non-goal. It is recorded only because no document
states the choice, so a consumer placing secrets in a job payload has nothing
to consult.

## 5. Disproved leads and non-findings

- **#2822 — resolved, verified.** No `JobInterface` exists; `packages/queue/src/Job.php:10-11`
  says so explicitly and the contract is abstract `Job`, `@api`, declared
  `public` (`packages/queue/public-surface.php:22`). Fixed by `e9fa7b3ba` with
  a `QueueJobContractSurfaceTest` regression pin. Correctly closed.
- **JSON encode/decode asymmetry — not applicable.** CLAUDE.md's rule was
  checked and does not apply: the queue serializes with PHP `serialize()`, not
  JSON (`rg json_encode packages/queue/src` → no hits on the persistence path).
- **Notification surface "gap" — conformant, not a finding.** An investigator
  flagged `NotificationDispatcher` and `SendNotificationJob` as `@api` but
  absent from `packages/notification/public-surface.php`. Both are
  `final class` (`NotificationDispatcher.php:16`, `Job/SendNotificationJob.php:20`),
  and charter §2 deliberately does not track concrete finals (audit C-16). The
  parity gate is behaving correctly.
- **Corrupt-row handling — sound.** `Worker.php:163-196` wraps both
  `unserialize()` calls in try/catch with `false`/non-object checks and routes
  failures to the failed-job store. No silent null.
- **Claim atomicity — sound.** The conditional-UPDATE claim is correct; F1 is
  about settlement, not acquisition. This distinction matters for any fix.

### Investigator error corrected

One lane's issue-search returned issue numbers from unrelated GitHub
repositories (an unscoped search), producing a list with no bearing on this
repository. That section was discarded; the issue evidence in §6 comes from
direct `gh issue view` on `waaseyaa/framework` and from commit history.

## 6. Existing issues affected — with evidence

| Issue | State | Relationship | Evidence |
|---|---|---|---|
| **#2741** | OPEN p1 | **Confirmed, unfixed.** Add: it is a data-loss path, and the scheduler already has the fix | `DbalTransport.php:179-213` unconditional predicates; `OccurrenceRepository.php:75-95` fenced counterpart |
| **#2743** | OPEN p1 | **Confirmed** by the `claimForRetry` latch, plus the indistinguishable-status defect | `DatabaseFailedJobRepository.php:103-118`; `DbalTransport.php:296-306` |
| **#2745** | OPEN p1 | **Confirmed**, root cause localized to one `match` | `SendNotificationHandler.php:70-76`; `SendNotificationJob.php:28-33` |
| **#2747** | OPEN p1 | **Confirmed: publication to an authorized subscriber despite a failed write, plus duplicate delivery on retry.** A separate unauthorized-disclosure hypothesis is UNRESOLVED; an earlier disproof of it is withdrawn | `BroadcastStorage.php:69-97` (no transaction); log INSERT commits at `:79-82` before the retained pair at `:89-94` |
| **#2818** | OPEN p1 | **Confirmed and understated** — declared timeouts are dead config, not merely absent isolation | `Job.php:25`, `WorkerOptions.php:22`, no enforcement anywhere |
| #2822 | CLOSED | Verified resolved; regression-pinned | `e9fa7b3ba`; `QueueJobContractSurfaceTest` |
| #2740 | — | Sibling A3-QUEUE-01 (messages without handler) | `docs/change-records/FW-2740.md` |
| #2734 | — | #2747's own body says coordinate on transaction behaviour | `docs/change-records/FW-2734.md` |
| #2719 / #2723 / #2727 | — | Parent assessment/synthesis for the A3-QUEUE findings | named in all four issue bodies |

**F6 is a documented limitation** (`DbalQueue.php:22-32`) and needs no issue;
the earlier recommendation to open one is withdrawn. **F9 has no owning issue**
and should be recorded as a documented property decision on #2818, which
already owns the worker trust boundary, rather than as a new ticket.

## 7. Compatibility constraints

- `waaseyaa/queue`, `waaseyaa/scheduler`, `waaseyaa/notification`,
  `waaseyaa/api` are all published and split-mirrored. Nothing here authorizes
  removing a class.
- **F1's fix changes a published contract.** `TransportInterface` is declared
  `internal` (`packages/queue/public-surface.php`), so adding a claim receipt to
  `pop()`/`ack()` is not a public break — but any third-party transport
  implementation would need updating, and #2741's own body asks for an
  inventory of every transport implementation. `InMemoryTransport` and
  `DbalTransport` are the in-repo ones.
- **F4's fix is a behavioural change to delivery.** Snapshotting the address at
  dispatch versus re-resolving on the worker have different privacy and
  correctness properties (a snapshot delivers to a stale address; re-resolution
  requires a field-read scope on a worker that has no request account). #2745
  correctly frames this as a design choice, not a patch.
- **F7 enforcement would newly kill long-running jobs** that currently run past
  their declared timeout because nothing enforces it. That is a behavioural
  change requiring a deprecation note even though it makes the code match its
  own documented contract.
- **F6 convergence changes observable behaviour**: enforcing `#[UniqueJob]` on
  the persistent driver would newly reject dispatches that currently succeed.
- `Job::$retryAfter` defaults to `0`, so `Job` subclasses that never set it
  retry with **zero backoff** (`Worker.php:379-387`). Changing the default is
  observable.

## 8. Focused acceptance criteria

Each fails for one specific defect.

1. **F1:** two connections; A claims, lease expires, B reclaims, A calls
   `ack` — assert B's row survives and B settles successfully.
   *Discriminates:* fails today at the first assertion.
2. **F1:** A's stale `release` must not clear B's `reserved_at`.
   *Discriminates:* catches the concurrent-execution variant that an
   ack-only test would miss.
3. **F3:** claim a failed job for retry, kill the claimer, assert the row
   becomes claimable again within a bounded time.
   *Discriminates:* fails today — the latch never releases.
4. **F3:** `listJobs` distinguishes a live claim from a lease-expired one.
   *Discriminates:* fails today; both report `in_progress`.
5. **F4:** `sendAsync` through a **real** queue, with a `toMail()` that calls
   `routeNotificationFor('mail')`, delivers to the original address.
   *Discriminates:* passes trivially on `SyncQueue`, so the test must pin a
   persistent driver — that is the coverage gap that let this ship.
6. **F5:** inject a fault between the log insert and the retained insert;
   assert no pollable log row remains.
   *Discriminates:* fails at every statement boundary today.
7. **F6:** dispatch the same `#[UniqueJob]` twice on the database driver.
   *Discriminates:* fails today; passes on `sync`, so the test must pin the
   driver.
8. **F7:** a job exceeding its declared `timeout` is terminated.
   *Discriminates:* fails today — nothing reads the property.

None should be implemented under this audit.

## 9. Recommended priorities

1. **F1 / #2741 — highest.** The only confirmed silent-data-loss path, and the
   fix already exists in-repo in the scheduler to copy.
2. **F3 / #2743.** Permanent stranding plus no operator visibility.
3. **F4 / #2745.** Silent misdelivery of mail; substituted, not dropped.
4. **F5 / #2747.** Driver-independent, on the API request path. The
   transaction fix stands on its own merits. **Do not re-scope the issue on
   this report's earlier disproof, which is withdrawn**; the leak question is
   unresolved and needs an adversarial interleaving analysis.
   **F10** should be documented rather than fixed.
5. **F8 / F7 / #2818.** Design-sized; the dead `timeout` fields are a cheap
   honesty fix that can land before the isolation contract.
6. **F9.** Document the decision on #2818.

**F6 is not on this list** — it is a documented limitation, not work.

Record the §3 driver-default fact on #2741, #2743, #2745 and #2818 — it bounds
who is exposed and is absent from all four.

## 10. Next disjoint audit slice — recommendation

**Access control and authorization evaluation** (`packages/access`,
`packages/auth`, `packages/user/src/Middleware`) — specifically the
`AccessChecker` route-option path versus `EntityAccessHandler` policy
evaluation versus field-read levels, and whether the three agree on
deny-vs-neutral semantics.

Rationale: it is disjoint from Codex's generation/search repairs, from #2984,
and from both audits delivered so far; the media lane already surfaced one
asymmetry (entity-level `isAllowed()` versus field-level `!isForbidden()`,
documented as intentional in CLAUDE.md but never audited end to end); and every
finding in this lane that touches authorization — F4's worker-without-account
problem, F8's privilege parity — resolves into that subsystem.

Explicitly **not** next: `packages/cli/src/Site/` or `packages/search` (Codex
active), `packages/ingestion` (checkpointed under #2984), or `packages/media`
(checkpointed, awaiting Codex review).
