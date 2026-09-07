# FW-POST-COMMIT-OUTCOME-01 — publishing idempotency completion signal (slice 1)

Status: implementation candidate (bounded repair slice for #2999 T-9)

Forge mirror: #2999

## Intent

Stop `IdempotencyStore::execute()` from masking `TransactionCompletionException`
after the outer managed transaction has already committed. The mutation and
idempotency replay row must remain durable; callers must receive the original
completion failure, not a fictional rollback on an inactive transaction frame.

This slice owns only the publishing idempotency boundary. It does **not**
define the HTTP/CLI/MCP caller envelope for committed-but-side-effects-failed
outcomes (#2999 T-8).

## Confirmed defect

`DBALTransaction::commit()` commits the Doctrine connection, sets
`active = false`, then drains completion callbacks via
`TransactionCompletionCoordinator`. A failing callback surfaces as
`TransactionCompletionException`.

`IdempotencyStore::execute()` wrapped all failures in a generic
`catch (\Throwable)` that always called `rollBack()`. After commit,
`rollBack()` throws `RuntimeException: Transaction is no longer active`,
replacing the original completion exception even though both the mutation and
the idempotency row were already durable.

## Decision (this slice)

Mirror `DBALDatabase::transactional()` and `UnitOfWork`:

- All operation-phase failures, including a completion-shaped exception raised before the outer commit, roll back.
- Only `TransactionCompletionException` raised by the outer `commit()` is rethrown without rollback.
- Other commit failures roll back and rethrow.

No change to `DBALTransaction`, nested completion semantics, repository
transaction authority, API/HTTP mapping, CLI behavior, or generic exception
surfaces.

## Required evidence (this slice)

Production composition via `DBALDatabase::createSqlite()` and a nested
`afterCommit` callback registered inside the idempotency operation closure:

1. First execution persists mutation effect + idempotency row, then surfaces
   `TransactionCompletionException` with the original nested failure (not an
   inactive-transaction rollback).
2. Retry with the same key replays the stored response without re-executing
   the operation.
3. Pre-commit operation failure controls, including an operation-raised
   `TransactionCompletionException`: mutation and idempotency row roll back;
   retry executes successfully.

Implemented in `packages/publishing/tests/Unit/IdempotencyStoreTest.php`.

## Open design decision — caller result for committed-but-side-effects-failed

**Still open; not invented in this slice.**

### Problem

When entity save + idempotency commit succeed but a post-commit listener or
completion callback fails, the durable database state and the caller-visible
outcome diverge. Callers today see either:

- an uncaught `TransactionCompletionException` bubbling to a generic HTTP 500
  (`ControllerDispatcher::handleException()` maps every `Throwable` to status
  `500` / title `Internal Server Error`), or
- a masked `RuntimeException` from the pre-fix idempotency rollback path.

Neither signals "mutation committed; reconcile side effects" nor discourages
blind retry.

### Candidate directions (for a follow-up slice)

| Surface | Candidate signal | Rationale |
|---|---|---|
| JSON:API (`JsonApiController` entity `store`/`update`/`delete` paths) | A transport status designed specifically against the committed outcome, with a stable machine code such as `committed_side_effects_failed`, `meta.committed: true`, and explicit reconciliation guidance | Existing conflict and validation statuses describe refused writes, so status, code, metadata, and client behavior must be designed together before this outcome is exposed. |
| MCP content tools (`ContentToolSet` → `ContentPublisher`) | Tool error envelope with `isError: true`, explicit `committed: true`, and the same machine code; **no** implicit tool retry | Agents must not re-issue the same idempotency key expecting a fresh mutation when the replay row exists. |
| CLI governed authoring (`GovernedAuthoringRecipe` / `ContentPublisher` wiring) | Non-zero exit with stderr code + human message distinguishing committed completion failure from refused pre-commit mutation | Operators need a grep-friendly signal without encouraging duplicate writes. |

### Why blind retry must be discouraged

Once the idempotency row is stored, repeating the same key is mutation-safe:
it replays the committed response without executing the mutation again. That
replay does **not** re-run failed post-commit listeners and therefore is not a
reconciliation mechanism. Retrying with a new key can duplicate a committed
write. A caller must distinguish safe same-key replay, unsafe new-key retry,
and separately owned side-effect repair (#2763 projection delivery, audit
fan-out, etc.).

### Focused tests needed in follow-up slices

- `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php` or
  JSON:API integration: entity mutation with injected completion failure →
  assert status/code/envelope (once designed).
- `packages/api/src/JsonApiController.php` mutation paths that flow through
  `EntityRepository` / `UnitOfWork` (not `ContentPublisher` directly).
- `packages/ai-tools/tests/Unit/Content/ContentToolSetTest.php` (MCP adapter).
- CLI recipe smoke around `GovernedAuthoringRecipe` content mutations.

Publishing idempotency behavior is covered by this slice; API/CLI adapters
remain unchanged until the envelope contract is designed.

## Exclusions

- `DBALTransaction`, `TransactionCompletionCoordinator`, `EntityRepository`,
  `UnitOfWork`, `JsonApiController`, `ControllerDispatcher`, `ContentToolSet`,
  CLI commands, Composer manifests, CI gates, other test suites.
- Cross-package exception taxonomy and reconciliation playbooks (#2763, #2819).

## Work packages

1. **This slice:** idempotency completion-signal fix + production-composition
   unit tests + changelog fragment.
2. **Next:** design and implement explicit committed-but-side-effects-failed
   caller result across JSON:API, MCP, and CLI adapters with focused tests.
