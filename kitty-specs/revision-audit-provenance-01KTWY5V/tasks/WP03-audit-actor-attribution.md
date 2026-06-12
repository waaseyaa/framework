---
work_package_id: WP03
title: Audit Actor Attribution (audit)
dependencies:
- WP01
- WP02
- WP04
requirement_refs:
- C-002
- C-004
- FR-004
- FR-005
- FR-006
- FR-008
- NFR-002
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T011
- T012
- T013
- T014
- T015
- T016
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "6036"
history:
- date: '2026-06-12T03:32:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/audit/
execution_mode: code_change
owned_files:
- packages/audit/**
tags: []
---

# WP03 — Audit Actor Attribution (audit)

**Mission**: revision-audit-provenance-01KTWY5V | **Tracks**: #1645, #1648
**Requirements**: FR-004, FR-005, FR-006, FR-008, NFR-002, NFR-003, C-002, C-004 | **Dependencies**: WP01, WP02, WP04
**Command**: `spec-kitty agent action implement WP03 --agent <name>`

## Objective

Fix all four #1645 mis-attribution surfaces in one package: the additive nullable `actor_uid` column becomes the authoritative three-state actor (N / 0 / NULL) while `account_uid` stays the legacy `actor ?? 0` compat column; the entity-lifecycle listener stops reading the saved entity's own `uid` field; the agent-tool listener stops hardcoding 0; publish-pointer moves get their first-ever audit rows via a new listener over WP02's `RevisionPointerMovedEvent`; the MCP listener preserves null. And close #1648: `AppendOnlyAuditDatabase::query()` gets the literal-stripping token guard so raw SQL can no longer mutate audit rows.

The authoritative behavior spec is `kitty-specs/revision-audit-provenance-01KTWY5V/contracts/audit-attribution.md` (clauses 1–25) — implement to it.

## Context (read first)

- `research.md` ground truth ("Audit attribution surfaces", "Audit schema & append-only guard") + D3/D4/D6; `data-model.md` "Audit event — additive actor fields" + per-surface table.
- **Why a new column**: `audit_event.account_uid` is `INTEGER NOT NULL DEFAULT 0` (`packages/audit/src/Schema/AuditEventSchemaHandler.php:45`); SQLite cannot relax NOT NULL via ALTER, and C-004 forbids a table rebuild. The nullable-distinct actor lands in NEW `actor_uid`; `account_uid` is retained as derived legacy. This is the documented DIRECTIVE_010 adaptation (research D3 spec-fidelity note) — do not attempt to make `account_uid` nullable.
- Current mis-attributions (all verified at alpha.203):
  - `EntityLifecycleAuditListener::resolveAccountUid()` (`packages/audit/src/Listener/EntityLifecycleAuditListener.php:128-138`) reads the entity's own `uid` field, else 0. Subscribes PRE_SAVE/POST_SAVE/POST_DELETE (`:37-44`). The recursion guard (#1587) and best-effort try/catch must survive unchanged (contract clause 8).
  - `AgentToolAuditListener` hardcodes `accountUid: 0` (`packages/audit/src/Listener/AgentToolAuditListener.php:62`). Its subscribed event `AgentRunToolCallObserved` now carries `?int $accountId` (added by the agent/MCP provenance WP — a dependency of this one). Duck-typed string-FQCN subscription is preserved (clause 11).
  - `McpDispatchAuditListener` (`packages/audit/src/Listener/McpDispatchAuditListener.php`) duck-reads `method`/`params`/`accountUid` (`:50-52`) and coerces absent → 0. The event now actually fires (dependency WP); this WP only fixes the null-preservation.
  - Publish-pointer moves: no listener at all. WP02's `RevisionPointerMovedEvent` (namespace `Waaseyaa\EntityStorage\Event`) is the typed seam — audit already requires entity-storage, so a typed import is layer-clean.
  - The correct model to converge on: `ApiRequestAuditListener` (`packages/audit/src/Listener/ApiRequestAuditListener.php:50-53`) resolves from `_account` — generalize off the Request via `AccountContextInterface`.
- Context resolution: `AuditServiceProvider::boot()` (`packages/audit/src/AuditServiceProvider.php:73-101`) constructs the listeners. `ServiceProvider::resolveOptional()` falls back to the kernel services bus, which serves `AccountContextInterface` since the context WP — `$this->resolveOptional(\Waaseyaa\Access\Context\AccountContextInterface::class)` returns the kernel's shared instance (null in bare-provider tests — listeners must accept `?AccountContextInterface`).
- Append-only wiring facts (NFR-003 is structural): the decorator is held ONLY by `AuditEventWriter` (wrapped at `AuditServiceProvider.php:60`; writer only calls `insert()`, `Writer/AuditEventWriter.php:39-56`); retention prune (`packages/cli/src/Command/Audit/PruneCommand.php:42`, `:123`) and `AuditEventQuery` resolve the RAW `DatabaseInterface`. A `query()` guard has zero legitimate traffic to false-positive on — the existing suite passing unchanged is the proof.
- `AuditEventQuery` does `fields('ae')` (`packages/audit/src/Query/AuditEventQuery.php:33`) — additive columns flow through untouched (C-004 by construction).
- CHANGELOG/docs are another WP's; this WP's docblock updates stay inside `packages/audit`.

## Requirement / contract map

| Deliverable | Requirement | audit-attribution.md clause(s) |
|---|---|---|
| `actor_uid` schema (CREATE + ALTER + index) | FR-004 enabler, C-002/C-004 | 1, 5 |
| Descriptor `?int` + writer dual-column + `getActorUid()` | FR-004 | 1–4 |
| Lifecycle listener from context | FR-004 | 6–8 |
| Agent-tool listener event→context→null | FR-005 | 9–11 |
| MCP listener null-preserving | FR-007 (consumer half) | 19 |
| `PublishPointerAuditListener` + 2 kinds | FR-006 | 13–15 |
| `query()` guard | FR-008 | 21–25 |
| Four-surface integration pins | NFR-002 (100%) | Verification section |
| Existing suite green unchanged | NFR-003 | 24 |
| `audit → access` edge | layer discipline (plan Charter Check) | research D1 layer answer |

## Out of scope for this WP (do not touch)

- `packages/entity-storage/**` — `RevisionPointerMovedEvent` is produced there (a dependency of this WP); you only consume it.
- `packages/ai-observability/**`, `packages/ai-agent/**`, `packages/mcp/**` — the event producers (also a dependency); you only read the new `accountId`/event payloads.
- `packages/access/**`, kernel files — the context and its bus arm exist already; you only `resolveOptional()` it.
- `packages/cli/src/Command/Audit/PruneCommand.php` — the prune path resolves the raw database BY DESIGN (C-002); the guard must not require changes there. If you think it does, re-read D6.
- CHANGELOG / docs/specs — the docs WP. In-package docblocks ARE yours.

## Subtasks

### T011 — Schema, descriptor, writer, read model

**Files**: `packages/audit/src/Schema/AuditEventSchemaHandler.php`, `packages/audit/src/Contract/AuditEventDescriptor.php`, `packages/audit/src/Writer/AuditEventWriter.php`, `packages/audit/src/Entity/AuditEvent.php`

1. **Schema** (contract clause 5): add `actor_uid INTEGER NULL` (no default) to the `CREATE TABLE` DDL for new installs; in `ensureSchema()`, add an idempotent guarded `ALTER TABLE audit_event ADD COLUMN actor_uid INTEGER` for existing installs (probe column existence first — follow the handler's existing introspection style); add index `audit_event_actor_uid` (guarded `CREATE INDEX IF NOT EXISTS` or probe, matching the handler's existing index handling). Additive only: no existing column/row changes (C-002/C-004).
2. **Descriptor**: `AuditEventDescriptor::$accountUid` widens `int → ?int` (`packages/audit/src/Contract/AuditEventDescriptor.php:21`); docblock: null = "no acting context", 0 = anonymous actor. Every existing construction passing int keeps compiling (clause 3). Grep all constructions (`rg -n "new AuditEventDescriptor" packages/`) and confirm none breaks.
3. **Writer** (clause 1, data-model.md mapping): the INSERT writes `actor_uid = $descriptor->accountUid` (null preserved as SQL NULL — verify the builder writes real NULL) and `account_uid = $descriptor->accountUid ?? 0` (legacy sentinel unchanged). Insert-only posture and best-effort semantics untouched.
4. **Read model** (clause 4): `AuditEvent::getActorUid(): ?int` — missing column / SQL NULL / `''` → null (mirror the `getEntityTypeId2` empty-sentinel precedent in the same class). `getAccountUid(): int` unchanged.

### T012 — Existing listener actor sources

**Files**: `packages/audit/src/Listener/EntityLifecycleAuditListener.php`, `packages/audit/src/Listener/AgentToolAuditListener.php`, `packages/audit/src/Listener/McpDispatchAuditListener.php`

1. **EntityLifecycleAuditListener** (FR-004, clauses 6–8): add optional ctor param `?AccountContextInterface $accountContext = null`; DELETE `resolveAccountUid()` (the entity-`uid` misattribution) and every consultation of the entity's `uid` field for actor purposes; actor = `$this->accountContext?->current()?->id()` (null when no context — never 0, never the entity's uid). Recursion guard + try/catch untouched.
2. **AgentToolAuditListener** (FR-005, clauses 9–11): resolution order event `accountId` → context → null. The event property is additive `?int $accountId = null` on `AgentRunToolCallObserved` (`packages/ai-observability/src/Event/AgentRunToolCallObserved.php`) — read it duck-typed-safely (`property_exists` or null-coalescing on the typed event, matching the listener's current duck-read style) so events lacking the property still record (clause 11). Delete the hardcoded `accountUid: 0` at `:62`. Add the same optional `?AccountContextInterface` ctor param.
3. **McpDispatchAuditListener** (clause 19): preserve a null `accountUid` from the event — remove the 0-coercion in the duck-read (`:50-52`). No context param needed here (the event carries the bearer account explicitly; absent = null).
4. Update each listener's class docblock to state its actor source. Target state (data-model.md per-surface table, inlined):

   | Listener | Actor source after this WP | Before |
   |---|---|---|
   | `EntityLifecycleAuditListener` | `AccountContext` (N / 0 / null) | entity's own `uid` field, else 0 |
   | `AgentToolAuditListener` | event `accountId` → context → null | hardcoded 0 |
   | `PublishPointerAuditListener` (NEW, T013) | `AccountContext` | no row at all |
   | `McpDispatchAuditListener` | event `accountUid` (?int, null preserved) | event never fired; 0-coercion |
   | `ApiRequestAuditListener` | unchanged — already correct (`_account`) | — |
   | `BroadcastAuditListener` | unchanged — out of #1645 scope | — |

### T013 — Publish listener, kinds, wiring, composer edge

**Files**: `packages/audit/src/Listener/PublishPointerAuditListener.php` (NEW), `packages/audit/src/Enum/AuditEventKind.php`, `packages/audit/src/AuditServiceProvider.php`, `packages/audit/composer.json`

1. **Enum**: two additive cases — `RevisionPublish = 'revision.publish'`, `RevisionRevert = 'revision.revert'` (dotted-verb taxonomy; additive-only extension policy of ocap-audit-log.md).
2. **`PublishPointerAuditListener`** (FR-006, clauses 13–14): `final class`, subscribes `RevisionPointerMovedEvent::class` (typed import from entity-storage — an edge audit already declares). Records kind `revision.publish` for operation `publish`, `revision.revert` for `revert`; actor from `?AccountContextInterface` (null-distinct, D3); subject `entityTypeId` (+ `entityUuid` where resolvable — keep it cheap, no extra queries); attributes `{entity_id, operation, from_revision_id, to_revision_id}`. Best-effort try/catch + optional logger, mirroring the sibling listeners' structure exactly.
3. **Provider wiring**: in `AuditServiceProvider::boot()`, resolve the context once — `$accountContext = $this->resolveOptional(AccountContextInterface::class)` (instanceof-check it like the dispatcher/writer resolutions) — pass it into `EntityLifecycleAuditListener`, `AgentToolAuditListener`, and the new `PublishPointerAuditListener`; subscribe the new listener alongside the existing four.
4. **Composer edge** (research D1 layer answer): add to `packages/audit/composer.json` — path repository entry for `../access` and `"waaseyaa/access": "^0.1.0-alpha.203"` in `require`, keeping `sort-packages` order. **CP-NEW**: the literal MUST equal `^<current tag>` from `git describe --tags --abbrev=0 --match='v*.*.*'` — it was `v0.1.0-alpha.203` at planning time; RE-RUN the command at implementation time and use what it prints (an alpha.204 tag may exist by then). Same-layer L1→L1 edge, no cycle (access requires only entity/foundation/plugin).

### T014 — Raw-SQL guard on `query()`

**File**: `packages/audit/src/Storage/AppendOnlyAuditDatabase.php`

1. Guard `query()` (`:84-87`) before delegating (D6, contract clauses 21–23):
   - Strip single-quoted and double-quoted string literals and SQL comments (`-- …\n`, `/* … */`) from the SQL — straightforward regex passes; no SQL parser dependency.
   - If the remainder contains a word-boundary match (case-insensitive) for any of `UPDATE | DELETE | DROP | ALTER | TRUNCATE` **AND** a word-boundary match for an append-only table name (`audit_event` — use the class's existing table-name source), throw.
2. Throw the **same `\LogicException`** as `assertMutable()`: refactor so both paths share one private message factory, interpolating the detected operation (clause 21). The builder-level `update()`/`delete()` behavior is byte-identical (clause 25).
3. Pass-through unchanged: SELECTs over audit_event (including literals containing mutation verbs — `WHERE attributes LIKE '%delete%'` is realistic, the attributes column is JSON TEXT); `INSERT INTO audit_event`; mutations of non-audit tables (clause 22). Fail-closed on residual ambiguity (CTE `WITH x AS (...) DELETE FROM audit_event`; pathological identifier named `delete` joined to audit_event) — accepted and documented in the class docblock (clause 23).
4. Update the class docblock: the structural-immutability claim is now true for raw SQL too (clause 25).

### T015 — Unit tests

**Files**: under `packages/audit/tests/Unit/` — extend `Listener/` and `Storage/AppendOnlyAuditDatabaseTest.php`, `Writer/`, add `Listener/PublishPointerAuditListenerTest.php` (NEW)

1. **EntityLifecycleAuditListener matrix**: context account N → actor_uid N; anonymous 0 → 0; no context → null; an entity whose own `uid` field is account B saved under context A → actor A (the #1645 pin — entity uid NEVER consulted). Expect to rewrite existing assertions that encoded the bug; do so visibly (the immutability/chaos/prune tests are a different matter — see T016).
2. **AgentToolAuditListener**: event with `accountId: 7` → 7; event accountId null + context N → N; both absent → null; legacy event object WITHOUT the property (anonymous class) → falls through to context/null, no error (clause 11); hardcoded-0 gone.
3. **McpDispatchAuditListener**: event accountUid null stays null (no 0-coercion); params hash unchanged behavior.
4. **PublishPointerAuditListenerTest** (NEW): publish operation → kind `revision.publish` with `{entity_id, operation, from_revision_id, to_revision_id}` attributes; revert → `revision.revert`; null from-revision carried as null; actor from context (N/0/null matrix); writer failure swallowed (best-effort).
5. **Guard matrix** (`AppendOnlyAuditDatabaseTest`) — enumerate as data-provider rows; same exception message as the builder guard (assert message equality against an `update()` throw):

   | SQL (representative) | Expected | Why |
   |---|---|---|
   | `UPDATE audit_event SET outcome = ?` | throw | verb + table |
   | `delete from audit_event where id = 1` | throw | case-insensitive |
   | `DROP TABLE audit_event` / `ALTER TABLE audit_event ADD x INT` / `TRUNCATE audit_event` | throw | all five verbs |
   | `WITH x AS (SELECT 1) DELETE FROM audit_event` | throw | not first-keyword-fooled (D6 alt c) |
   | `UPDATE /* harmless */ audit_event SET x=1` | throw | comment stripping doesn't hide the verb+table |
   | `SELECT * FROM audit_event WHERE attributes LIKE '%delete%'` | pass | literal stripped — the realistic false-positive class |
   | `SELECT 'UPDATE audit_event'` | pass | whole statement is a literal |
   | `/* delete audit_event */ SELECT 1 FROM audit_event` | pass | comment stripped |
   | `INSERT INTO audit_event (...) VALUES (...)` | pass | insert is append |
   | `UPDATE other_table SET x = 1` / `DELETE FROM cache_items` | pass | conjunctive rule — non-audit tables mutate freely |
   | `SELECT 1 FROM audit_event JOIN delete ON ...` | throw (documented fail-closed) | clause 23 residual |
6. **Writer/descriptor/read model**: descriptor null → `actor_uid` NULL + `account_uid` 0; descriptor 0 → both 0; descriptor 7 → both 7; `AuditEvent::getActorUid()` null/int round-trips; existing int-passing descriptor constructions compile (type test).

### T016 — Integration tests

**Files**: `packages/audit/tests/Integration/AuditAttributionTest.php` (NEW), `packages/audit/tests/Integration/AuditImmutabilityTest.php` (extend)

1. **AuditAttributionTest** (NFR-002 at 100% — all four surfaces through real dispatch paths against SQLite, with a real `RequestAccountContext` holding a known account):
   - S1 entity lifecycle: dispatch a real save through a repository with the context set → `entity.write` row has `actor_uid` = context account, NOT the entity's `uid` field value (construct the entity with a different `uid` to prove it).
   - S2 agent tool: dispatch a real `AgentRunToolCallObserved(accountId: N)` through the dispatcher → `agent.tool_execute` row `actor_uid = N`.
   - S3 publish pointer: dispatch `RevisionPointerMovedEvent` (publish and revert) → `revision.publish` / `revision.revert` rows with actor + transition attributes.
   - S4 MCP dispatch: dispatch an event named `waaseyaa.mcp.dispatch` carrying method/params/accountUid → `mcp.dispatch` row with `actor_uid` carried; null accountUid → NULL actor_uid + `account_uid = 0` (the null-vs-0 distinctness witness, queried straight off the table).
   - Migration shape: create a pre-mission `audit_event` table (no `actor_uid`), run `ensureSchema()`, assert column + index added, old rows read `getActorUid() === null`.
2. **AuditImmutabilityTest** (SC-003): extend with raw `UPDATE` / `DELETE` / `DROP` / `ALTER` against `audit_event` via the decorator's `query()` → each throws `\LogicException`; a `SELECT` via `query()` still works. Do NOT modify the existing assertions.
3. **NFR-003 proof**: the pre-existing audit suite — `AuditImmutabilityTest` (original assertions), `AuditChaosTest`, prune coverage (including the `audit.retention_pruned` self-audit) — passes UNCHANGED. Attribution assertions that encoded entity-uid actors may change (deliberately, visibly); immutability/chaos/prune may not.

**Validation**:

```bash
./vendor/bin/phpunit packages/audit/tests/ --no-progress
composer phpstan
composer cs-check
composer check-composer-policy      # CP-NEW literal on the new access edge
bin/check-package-layers            # audit → access is L1→L1, must pass
bin/check-dead-code                 # PublishPointerAuditListener is wired — no @api needed
```

## Edge cases & risks (from the plan premortem)

- **Existing attribution assertions encode the bug**: audit tests asserting `account_uid` values produced by the old entity-`uid` resolution will fail — updating them is correct and REQUIRED (they pin #1645's broken behavior). The bright line: attribution assertions change deliberately and visibly; immutability/chaos/prune assertions change not at all. List every modified existing test in completion notes with one line of why.
- **Descriptor widening ripples**: `int → ?int` is parameter-compatible for every existing construction; only READERS of `->accountUid` see the new nullability. The single production reader is the writer (you update it). If PHPStan surfaces another reader, handle the nullability there and flag it in completion notes.
- **ALTER on large audit tables**: `ADD COLUMN` nullable-no-default is metadata-only DDL on SQLite/MySQL 8/PostgreSQL — no rewrite, safe on append-only tables that never shrink. Do not add a backfill.
- **Guard fail-closed posture**: the residual ambiguous statements (CTE-wrapped mutations, an identifier literally named `delete` joined to audit_event) THROW — that is the documented, accepted posture for an append-only guarantee (D6). Do not add an escape hatch or config switch.
- **Legacy `0` stays conflated in `account_uid`**: consumers reading the legacy column see no improvement until they adopt `getActorUid()` — accepted, C-004 compatibility is the point. Do not "fix" old rows or change the legacy column's semantics.
- **Recursion guard (#1587)**: the lifecycle listener ignores AuditEvent's own entity type to avoid self-auditing loops — verify your context change leaves that path untouched (a context read happens AFTER the recursion check, or at least never before it can loop).
- **Provider-test isolation**: bare-provider unit tests have no kernel bus → `resolveOptional()` returns null → listeners run with a null context and record null actors. That is the correct degraded behavior, not a test-setup failure.

## Definition of Done

- [ ] All six subtasks complete; `packages/audit/tests/` green, with immutability/chaos/prune tests passing byte-unchanged (NFR-003).
- [ ] All four #1645 surfaces pinned by integration tests asserting `actor_uid` (NFR-002 100%); null-vs-0 distinctness asserted at the SQL level at least once.
- [ ] Raw `UPDATE/DELETE/DROP/ALTER audit_event` via `query()` throws the same `\LogicException` as the builder guard (SC-003); the false-positive pass-through matrix green.
- [ ] `audit_event` migration shape green: guarded ALTER + index, idempotent, old rows null actor.
- [ ] New `waaseyaa/access` require with the exact CP-NEW literal verified against `git describe` at implementation time; `composer check-composer-policy` + `bin/check-package-layers` green.
- [ ] No changes outside `owned_files` (`packages/audit/**` only — the event/dispatch producers live in other packages and are NOT yours).

## Reviewer guidance

- **Null-vs-0 distinctness is the highest-value assertion**: find the integration case that writes a no-context row and asserts, from a direct table query, `actor_uid IS NULL` AND `account_uid = 0` in the SAME row. If every test goes through the read model only, demand the SQL-level one.
- **Guard false-positive proof**: run the full pre-existing audit suite yourself and diff the test files — immutability/chaos/prune must be additions-only (new methods OK, existing assertions untouched). Then check the pass-through matrix includes a literal containing a mutation verb (`LIKE '%delete%'`) — that is the realistic false-positive class the literal-stripping exists for.
- The entity-uid pin (S1) must construct an entity whose `uid` FIELD differs from the context account — a test where they coincide proves nothing.
- Verify `resolveAccountUid()` is deleted, not bypassed — `rg -n "get\('uid'\)|->uid" packages/audit/src/Listener/EntityLifecycleAuditListener.php` should come back empty of actor-resolution hits.
- Descriptor widening: confirm no existing construction site broke and no reader other than the writer consumes `->accountUid` (`rg -n "accountUid" packages/`).
- Check the CP-NEW literal against `git describe --tags --abbrev=0 --match='v*.*.*'` output at review time — a stale planning-time literal is the known new-app gotcha.

## Completion notes template (fill in before requesting review)

- NFR-003 evidence: pre-existing immutability/chaos/prune test files — confirm additions-only (paste `git diff --stat packages/audit/tests/Integration/` annotated).
- Modified attribution assertions: file + old expectation + new expectation + the #1645 surface it encoded.
- CP-NEW literal used: `^___` (output of `git describe --tags --abbrev=0 --match='v*.*.*'` at implementation time).
- Null-vs-0 SQL-level witness: test name + the direct-query assertion line.
- Guard message-equality proof: test name comparing `query()` throw message to `update()` throw message.
- `rg -n "accountUid" packages/` reader audit result (any reader beyond the writer, and how its nullability was handled).

## Activity Log

- 2026-06-12T03:32:00Z – spec-kitty.tasks – created
- 2026-06-12T05:22:31Z – claude:fable-5:implementer:implementer – shell_pid=21032 – Started implementation via action command
- 2026-06-12T05:44:01Z – claude:fable-5:implementer:implementer – shell_pid=21032 – Ready for review
- 2026-06-12T05:44:40Z – claude:fable-5:reviewer:reviewer – shell_pid=6036 – Started review via action command
