# Contract: Audit Actor Attribution & Append-Only Raw-SQL Guard

**Mission**: revision-audit-provenance-01KTWY5V | **Requirements**: FR-004..FR-008, NFR-002, NFR-003, C-002, C-004

## Actor encoding (all surfaces)

1. **Authoritative column**: `audit_event.actor_uid` (nullable int) carries the three-state actor: account id N, anonymous `0`, or SQL NULL for "no acting context". `account_uid` remains the legacy compat column (`actor ?? 0`, NOT NULL semantics unchanged) — existing `AuditEventQuery` filters and dashboards keep working byte-for-byte (C-004).
2. **Null-vs-0 invariant**: no listener, writer, or schema default may coerce a missing actor to `0`. `0` appears in `actor_uid` only when the resolved actor IS `AnonymousUser`.
3. **Descriptor**: `AuditEventDescriptor::$accountUid` is `?int`; null means "no actor". Existing constructions (passing int) compile and behave unchanged.
4. **Read model**: `AuditEvent::getActorUid(): ?int` (NULL/absent column → null); `getAccountUid(): int` unchanged.
5. **Schema**: `actor_uid` ships in `CREATE TABLE` for new installs and via an idempotent guarded `ALTER TABLE audit_event ADD COLUMN actor_uid INTEGER` for existing installs, plus index `audit_event_actor_uid`. Additive only — no existing column or row changes (C-002/C-004). Pre-upgrade rows read `actorUid: null`.

## The four #1645 surfaces (NFR-002: each has a pinning test)

### S1 — Entity lifecycle (`entity.write`, `entity.delete`)

6. The actor is the **acting account from `AccountContextInterface`** — never the saved entity's own `uid` field value. A user-A session saving a node whose `uid` field is user B records actor A.
7. With no context (CLI/bootstrap), actor is null — not 0, not the entity's `uid`.
8. The recursion guard (#1587, AuditEvent self-audit) and best-effort try/catch semantics are unchanged.

### S2 — Agent tool execute (`agent.tool_execute`)

9. `AgentRunToolCallObserved` gains additive `?int $accountId = null`; `AgentExecutor` populates it from `$initiatorAccount` at both dispatch sites (success and threw paths).
10. The listener resolves: event `accountId` → `AccountContextInterface` → null. Hardcoded `0` is gone; `0` appears only for an anonymous initiator.
11. Duck-typed (string-FQCN) subscription is preserved — events lacking the property still record (actor falls through to context/null).

### S3 — Publish pointer (`revision.publish`, `revision.revert`) — previously invisible

12. `EntityRepository::setPublishedRevision()` dispatches `RevisionPointerMovedEvent(operation: 'publish', fromRevisionId: prior published pointer or null, toRevisionId)`; `setCurrentRevision()` dispatches `operation: 'revert'` with the prior base `revision_id` pointer. Legacy `EntityEvents::REVISION_REVERTED` dispatches continue unchanged (no consumer break).
13. `PublishPointerAuditListener` (packages/audit) records kind `revision.publish` / `revision.revert` with: actor from `AccountContextInterface` (null-distinct), `entityTypeId`/`entityUuid` subject fields where resolvable, and attributes `{entity_id, operation, from_revision_id, to_revision_id}`.
14. The event fires only after the transaction commits successfully (no audit row for a rolled-back pointer move). Best-effort: listener failures never disrupt the publish.
15. `rollback()` emits no pointer event — it creates a new revision (covered by `revision_author` + existing `entity.write`/`REVISION_CREATED` flow).

### S4 — MCP dispatch (`mcp.dispatch`) — previously never fired

16. `McpEndpoint::dispatch()` fires `McpDispatchEvent` exactly once per authenticated, well-formed JSON-RPC request (after `authenticate()` succeeds and the envelope parses with a `method`), before method routing — covering `tools/call` (FR-007) and all other methods per the listener's documented contract. Unauthenticated (401) and parse-error requests fire nothing.
17. Event payload: `method` (string), `params` (array), `accountUid` (?int from the bearer-auth account). The listener stores only the SHA-256 params hash — raw params never reach the audit row (privacy constraint preserved).
18. `McpDispatchEvent::NAME === McpDispatchAuditListener::EVENT_NAME === 'waaseyaa.mcp.dispatch'` — pinned by a cross-package test (mcp must not require audit; the string is intentionally duplicated).
19. The listener preserves a null `accountUid` (no 0-coercion). Dispatch is best-effort (try/catch): an audit/dispatcher failure never alters the JSON-RPC response.
20. **Independence**: the event fires from the dispatch seam as it exists today; it must not depend on, nor attempt to fix, the #1635/#1636 transport bugs or #1640 OAuth.

## Raw-SQL append-only guard (FR-008)

21. `AppendOnlyAuditDatabase::query($sql, $args)` rejects, with the **same `\LogicException`** (shared message factory with `assertMutable()`), any SQL where — after stripping single-quoted and double-quoted string literals and SQL comments (`--`, `/* */`) — a word-boundary mutation verb (`UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`) co-occurs with a word-boundary append-only table name (`audit_event`).
22. Pass-through (must NOT throw): `SELECT` over `audit_event` (including ones whose *string literals* contain mutation verbs, e.g. `WHERE attributes LIKE '%delete%'`); `INSERT INTO audit_event …`; any mutation of non-audit tables.
23. Fail-closed: residual ambiguous statements (e.g. a CTE `WITH x AS (...) DELETE FROM audit_event`) throw; a pathological SELECT joining `audit_event` with an identifier literally named `delete` also throws — documented and accepted for an append-only guarantee.
24. **Zero false positives on the audit package's own operations (NFR-003), structurally**: the decorator is held only by `AuditEventWriter` (insert-only, never calls `query()`); `audit:prune` and `AuditEventQuery` resolve the **raw** `DatabaseInterface` and never pass through the decorator. Proof: the existing audit suite — `AuditImmutabilityTest`, chaos, prune (including `audit.retention_pruned` self-audit) — passes unchanged.
25. Builder-level guard behavior (`update()`/`delete()` throwing) is unchanged; the class docblock is updated to state the now-true claim that raw SQL is also guarded.

## Verification

- Unit: per-listener actor matrices (account N / anonymous 0 / none null; entity-uid never consulted); `PublishPointerAuditListenerTest` (both kinds, transition payload, null actor); `AppendOnlyAuditDatabaseTest` guard matrix (verbs × tables × literals × comments, throw/pass per §21-23); descriptor widening; writer dual-column mapping; `AuditEvent::getActorUid()`.
- Integration: `AuditAttributionTest` — all four surfaces through real dispatch paths with a context account, asserting `actor_uid` (NFR-002 at 100%); `AuditImmutabilityTest` extended with raw `UPDATE/DELETE/DROP/ALTER audit_event` via the decorator's `query()` throwing (SC-003) while the full pre-existing suite stays green.
- Cross-package: MCP event-name pin test; `McpEndpointDispatchEventTest` (fired once, account carried, 401/parse-error fire nothing, RPC response unchanged on dispatcher failure).
