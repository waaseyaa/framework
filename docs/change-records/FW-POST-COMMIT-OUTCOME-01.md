# FW-POST-COMMIT-OUTCOME-01 — committed mutation caller signals (#2999)

Status: in progress (slice 1 merged; slice 2 API candidate)

Forge mirror: #2999

## Intent

When entity storage commits a mutation but post-commit completion work fails,
callers must receive an explicit committed-but-side-effects-failed signal instead
of a generic uncaught 500 or a fictional rollback. Blind retry must be
discouraged once the durable row exists.

## Slice 1 — publishing idempotency completion signal (merged, PR #3000)

Stop `IdempotencyStore::execute()` from masking `TransactionCompletionException`
after the outer managed transaction has already committed. The mutation and
idempotency replay row remain durable; callers receive the original completion
failure, not a fictional rollback on an inactive transaction frame.

Implemented in `packages/publishing/src/Idempotency/IdempotencyStore.php` with
production-composition tests in
`packages/publishing/tests/Unit/IdempotencyStoreTest.php`. Changelog fragment:
`changes/unreleased/2999.idempotency-completion-signal.fixed.md`.

## Slice 2 — JSON:API committed-outcome envelope (this candidate)

### Decision (accepted API contract)

`UnitOfWork` preserves its public post-commit contract: it throws
`TransactionCompletionException` stamped with that **outer** `transaction()`
call's commitment token (a new token per outer entry; nested in-instance calls
keep the outer token). `EntityRepository` translates **only** a matching-token
completion exception into
`Waaseyaa\EntityStorage\Exception\EntityMutationCommittedSideEffectsFailedException`
for repository callers. Foreign-token or bare completion failures — including
an independent nested repository write during `PRE_SAVE` / `PRE_DELETE`, or a
retained completion exception from an earlier reuse of the same UnitOfWork —
are not translated; the outer mutation rolls back and the bare completion
exception propagates.

`JsonApiController` catches that repository-translated exception **only** at
repository mutation calls:

- `store()` → `EntityRepository::save()`
- `update()` → both plain save and `saveWithExpectation()` save paths
- `destroy()` → `EntityRepository::delete()`

Earlier validation, access, workflow transition, and payload-guard work stay
outside the catch. The response is a sanitized JSON:API error document:

| Surface | Contract |
|---|---|
| HTTP status | `500` |
| Machine code | `COMMITTED_SIDE_EFFECTS_FAILED` |
| Detail | Operator guidance only; no callback messages, classes, traces, or submitted payloads |
| Meta | `committed: true`, `operation: create\|update\|delete`, `resource_type`, `resource_id` |
| Retry | Detail states retry is unsafe; not `2xx`, `409`, or `422`; no recovery claim |

Pre-commit / `PRE_SAVE` / `PRE_DELETE` failures — including a completion-shaped
exception or foreign-unit committed failure from another connection — roll back
and must **not** emit `COMMITTED_SIDE_EFFECTS_FAILED`. `JsonApiRouter` passes
the controller document through unchanged.

Spec: `docs/specs/jsonapi.md` § "Committed mutation, failed post-commit side
effects". Evidence:
`packages/api/tests/Integration/JsonApiCommittedOutcomeFlowTest.php` and
`UnitOfWorkTest` post-commit / unwrap assertions.

### Required evidence (this slice)

Production composition via `DBALDatabase::createSqlite()`, real
`EntityRepository` / `UnitOfWork` completion drain, and injected post-commit
listener failures (not a repository mock that merely throws):

1. POST → typed 500 while row and assigned id are durable.
2. PATCH (plain) → typed 500 while update is durable.
3. PATCH (expectation-stated / `expected_revision_id`) → typed 500 while update is durable.
4. DELETE → typed 500 while row is absent.
5. Hostile callback message absent from wire envelope.
6. Ordinary `PRE_SAVE` control rolls back; no `COMMITTED_SIDE_EFFECTS_FAILED`.
7. Pre-save `TransactionCompletionException` control rolls back; no committed
   envelope (`committed: true` / `COMMITTED_SIDE_EFFECTS_FAILED`).
8. Two-connection `PRE_SAVE` discriminator: inner durable, outer rolled back,
   no `COMMITTED_SIDE_EFFECTS_FAILED`.
9. Two-connection `PRE_DELETE` discriminator: same binding guarantees.
10. At least one `ControllerDispatcher` + `JsonApiRouter` HTTP path preserves
    status/code/meta unchanged.
11. UnitOfWork consumers that catch only `TransactionCompletionException` still
    handle post-commit failures (token present for that outer call).
12. Sequential UnitOfWork reuse: a retained tokenized failure from committed
    transaction A, thrown during rolled-back transaction B on the same instance,
    must not match B's commitment token.

### Residual scope (#2999 still open)

| Surface | Status |
|---|---|
| MCP content tools (`ContentToolSet` → `ContentPublisher`) | Mapping not designed / not implemented |
| CLI governed authoring (`GovernedAuthoringRecipe` / `ContentPublisher`) | Source-backed absence; exit semantics not designed |
| Recovery / reconciliation playbooks (#2763 projection delivery, audit fan-out) | Not owned by this slice |
| Caller-specific retry guidance beyond JSON:API detail | Open for MCP/CLI/idempotency-key semantics |
| Non-UnitOfWork repository composition (no mutation authority) | Residual: POST/AFTER dispatch immediately after local commit as the original throwable; a local `commit()` `TransactionCompletionException` can be masked by inactive-rollback — not the repository-translated EmCSF signal |

Publishing idempotency behavior from slice 1 remains intact. This slice does
**not** close #2999.

## Exclusions (slice 2)

- `ControllerDispatcher` global exception mapping, `DBALTransaction` semantics,
  other callers, MCP/CLI adapters, Composer, CI, rosters, unrelated tests.
  Phase distinction is owned by UnitOfWork token-stamped TCE plus
  EntityRepository matching-token translation, plus the JSON:API catch
  retarget — not by inferring commit from a bare DBAL completion exception
  crossing the whole repository call.

## Work packages

1. **Done:** idempotency completion-signal fix + unit tests + changelog fragment.
2. **This candidate:** entity-storage post-commit phase signal + JSON:API
   committed-outcome envelope + integration/unit tests + spec/changelog updates.
3. **Next:** MCP mapping, CLI exit semantics, recovery/reconciliation ownership,
   focused adapter tests.
