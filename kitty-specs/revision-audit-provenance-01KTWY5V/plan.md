# Implementation Plan: Revision & Audit Provenance

**Branch**: `main` | **Date**: 2026-06-12 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `kitty-specs/revision-audit-provenance-01KTWY5V/spec.md`
**Tracking**: #1644, #1645, #1648 | **Target release**: v0.1.0-alpha.205

## Summary

Make the framework answer "who did this" on both provenance surfaces — revisions and the OCAP audit log — and close the raw-SQL hole in the append-only guard. Verified current state (full evidence in [research.md](research.md)):

1. **Revisions have no author.** The live revision write assembles only `entity_id`/`revision_id`/`revision_created`/`revision_log` ([packages/entity-storage/src/Driver/RevisionableStorageDriver.php:301-330](../../packages/entity-storage/src/Driver/RevisionableStorageDriver.php), public seam `writeRevision()` at `:74-81`); the table spec has no author column ([packages/entity-storage/src/SqlSchemaHandler.php:641-717](../../packages/entity-storage/src/SqlSchemaHandler.php)); and `RevisionableEntityInterface::revisionMetadata()` is never hydrated — `setRevisionMetadata()` has zero production callers, so the accessor returns null on every load ([packages/entity/src/RevisionableEntityTrait.php:97-138](../../packages/entity/src/RevisionableEntityTrait.php), [packages/entity-storage/src/EntityRepository.php:592-630](../../packages/entity-storage/src/EntityRepository.php)).
2. **The acting account does not travel.** No account-context service exists; the account lives only on the `_account` request attribute ([packages/user/src/Middleware/SessionMiddleware.php:61](../../packages/user/src/Middleware/SessionMiddleware.php)), unreachable from storage. Entity events carry no account.
3. **Audit mis-attributes its most important events.** Entity lifecycle rows use the entity's own `uid` field, else 0 ([packages/audit/src/Listener/EntityLifecycleAuditListener.php:128-138](../../packages/audit/src/Listener/EntityLifecycleAuditListener.php)); agent-tool rows hardcode 0 ([packages/audit/src/Listener/AgentToolAuditListener.php:62](../../packages/audit/src/Listener/AgentToolAuditListener.php)); publish-pointer moves dispatch only the actor-less, transition-less `REVISION_REVERTED` misnomer ([packages/entity-storage/src/EntityRepository.php:842-845](../../packages/entity-storage/src/EntityRepository.php)) which no audit listener subscribes; the MCP listener waits on `waaseyaa.mcp.dispatch` ([packages/audit/src/Listener/McpDispatchAuditListener.php:30](../../packages/audit/src/Listener/McpDispatchAuditListener.php)) that nothing fires — `McpEndpoint` has no dispatcher dependency at all ([packages/mcp/src/McpEndpoint.php:29-99](../../packages/mcp/src/McpEndpoint.php)).
4. **`audit_event.account_uid` is `NOT NULL DEFAULT 0`** ([packages/audit/src/Schema/AuditEventSchemaHandler.php:45](../../packages/audit/src/Schema/AuditEventSchemaHandler.php)) — "no actor" and "anonymous" are indistinguishable, and the column cannot be relaxed additively (C-004).
5. **The append-only guard skips raw SQL.** `AppendOnlyAuditDatabase::query()` delegates unguarded ([packages/audit/src/Storage/AppendOnlyAuditDatabase.php:84-87](../../packages/audit/src/Storage/AppendOnlyAuditDatabase.php)) while the class doc claims structural immutability.
6. **Two revision dialects exist**: the live `<entity>_revision` (no author) and the dormant M-004 `<entity>__revision` with a `revision_author` column ([packages/entity-storage/src/Schema/RevisionTableBuilder.php:284-289](../../packages/entity-storage/src/Schema/RevisionTableBuilder.php)), reachable only via `TranslationSchemaHandler::syncTwoAxis()` which has no production callers.

The work: a request-scoped `AccountContext` holder in `packages/access` set by the session middleware / MCP endpoint / agent executor with a `SaveContext` override (D1); a nullable `revision_author` column with additive sync — which must be *added* to `ensureRevisionTable()`, since revision tables have no additive arm today (D2); metadata hydration on every revision load; an additive nullable `actor_uid` audit column with null-vs-0 semantics (D3); a typed `RevisionPointerMovedEvent` + publish/revert audit listener (D4); the MCP dispatch event fired from the endpoint seam (D5); a literal-stripping token guard on raw audit SQL (D6); and dialect reconciliation in docs (D7).

## Technical Context

**Language/Version**: PHP 8.5+ (charter baseline), Symfony 7.x components
**Primary Dependencies**: none new. One new internal manifest edge: `waaseyaa/audit` → `waaseyaa/access` (same-layer L1, constraint `^v0.1.0-alpha.<current>` per CP-NEW; no cycle — access requires only entity/foundation/plugin)
**Storage**: Additive-only DDL — nullable `revision_author` on `<entity>_revision` + `<entity>__translation__revision` (new-table spec + `ADD COLUMN` sync arm), nullable `actor_uid` + index on `audit_event` (CREATE TABLE for new installs + guarded `ALTER TABLE ADD COLUMN` for existing). No row rewrites, no NOT NULL changes (C-001, C-002, C-004)
**Testing**: PHPUnit 10.5 — per-package unit tests, audit integration suite (immutability/chaos/prune must stay green = NFR-003), kernel-booted integration tests in `tests/Integration/` against SQLite
**Target Platform**: Framework monorepo; ships in v0.1.0-alpha.205 under the CI-gated release flow (C-003)
**Project Type**: Monorepo packages — `access` (L1), `user` (L1), `entity` (L1), `entity-storage` (L1), `audit` (L1), `ai-observability` (L5), `ai-agent` (L5), `mcp` (L6), `foundation` kernel (sanctioned cross-layer), docs
**Performance Goals**: NFR-001 — ≤5% median revisionable-save overhead; actor resolution is an in-memory holder read, no query
**Constraints**: Layer discipline (one new same-layer edge, no upward edges); additive-only schema; `AuditEventQuery` compatibility (it selects `fields('ae')`, so additive columns pass through — verified); PHPStan baseline; dead-code gate; composer policy; CHANGELOG under `[Unreleased]`
**Scale/Scope**: 9 packages touched + CHANGELOG + 4 spec docs; 4 new classes, 2 new enum cases, no new packages, no new entity types

## Charter Check

*GATE: evaluated 2026-06-12 against `.kittify/charter/charter.md`.*

- **PHP 8.5 baseline, Symfony components**: PASS — no new runtime deps; the MCP endpoint gains an optional `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` already used throughout.
- **Per-package unit tests**: PASS — new/extended unit tests in `packages/access`, `packages/entity-storage`, `packages/audit`, `packages/mcp`, plus kernel integration tests (see Project Structure).
- **Quality gates (CI matrix, PHPStan baseline, composer policy, dead-code, getQuery)**: PASS — no exemptions requested. All new classes ship wired (no dormant `@api` scaffolding); the new `audit → access` edge satisfies `bin/check-package-layers` (L1→L1) and CP-NEW (exact `^<current-tag>` literal).
- **DIRECTIVE_003 (decision documentation)**: Seven material decisions documented in [research.md](research.md) D1–D7 with rationale and rejected alternatives.
- **DIRECTIVE_010 (spec fidelity)**: One deliberate adaptation — the spec's Key Entities wording implies `account_uid` becomes nullable; source reality (NOT NULL today, SQLite no ALTER-COLUMN, C-004 additive-only) makes that unimplementable, so the nullable-distinct actor lands in a new additive `actor_uid` column with `account_uid` retained as a derived legacy column. Documented in research.md D3; FR-004's normative requirement (absence distinct from 0) is fully met. No other drift.
- **Layer discipline**: PASS, with one explicitly-reviewed point (D1): the acting-account holder lives in `packages/access` (L1, owner of `AccountInterface`). Readers `entity-storage` (existing access edge) and `audit` (NEW same-layer edge) are L1→L1; setters `user` (L1), `ai-agent` (L5→L1), `mcp` (L6→L1) all point downward or sideways on existing edges; the kernel passes the shared instance under its entry-point exemption. `audit`'s new `PublishPointerAuditListener` imports `RevisionPointerMovedEvent` from entity-storage — an edge audit already declares. DIR-004 (OCAP-by-architecture) is *advanced* by this mission (attribution + stronger append-only guarantee); DIR-005 (two-axis substrate) is respected — the single-axis path changes only additively and the translation-revision table gets the same additive author column.

**Post-design re-check**: PASS — no new violations introduced by the Phase 1 design; Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```
kitty-specs/revision-audit-provenance-01KTWY5V/
├── plan.md              # This file
├── research.md          # Phase 0 — verified ground truth + decisions D1–D7
├── data-model.md        # Phase 1 — actor states, metadata shape, audit fields, config surface
├── quickstart.md        # Phase 1 — verify-by-hand script for reviewers
├── contracts/
│   ├── revision-author.md       # Recording + readback + additive schema contract
│   └── audit-attribution.md     # Four-surface actor contract, publish event, MCP event, raw-SQL guard
└── tasks.md             # Phase 2 (/spec-kitty.tasks — not created here)
```

### Source Code (repository root)

```
packages/access/src/Context/
├── AccountContextInterface.php        # NEW — current(): ?AccountInterface / set(?AccountInterface): void (D1)
└── RequestAccountContext.php          # NEW — mutable request-scoped holder implementation

packages/user/src/Middleware/
└── SessionMiddleware.php              # MODIFY — set AccountContext alongside `_account` (:61); optional ctor param

packages/entity/src/
└── RevisionMetadata.php               # MODIFY — docblock only: live table/columns, not the dormant dialect (D7/FR-009)

packages/entity-storage/src/
├── SaveContext.php                    # MODIFY — withActorUid(?int) override + actorOverridden flag (D1/FR-002)
├── EntityRepository.php               # MODIFY — resolve actor once per op; thread to all 7 writeRevision sites;
│                                      #          hydrate RevisionMetadata in loadRevision()/translation loads (FR-001);
│                                      #          dispatch RevisionPointerMovedEvent from setPublishedRevision()/
│                                      #          setCurrentRevision() with from→to transition (D4/FR-006)
├── Event/RevisionPointerMovedEvent.php  # NEW — operation publish|revert, fromRevisionId, toRevisionId
├── Driver/RevisionableStorageDriver.php # MODIFY — trailing `?int $author = null` on writeRevision(); column write (D2)
└── SqlSchemaHandler.php               # MODIFY — revision_author in both revision-table specs + additive
                                       #          ADD-COLUMN arm in ensureRevisionTable()/ensureTranslationRevisionTable() (FR-003)

packages/foundation/src/Kernel/
└── AbstractKernel.php                 # MODIFY — construct one RequestAccountContext; pass into repository factory
                                       #          (validator precedent :203-206); bind on kernel services bus

packages/audit/
├── composer.json                      # MODIFY — add waaseyaa/access (same-layer L1 edge, CP-NEW literal)
└── src/
    ├── Schema/AuditEventSchemaHandler.php       # MODIFY — actor_uid in CREATE TABLE + guarded ALTER + index (D3)
    ├── Contract/AuditEventDescriptor.php        # MODIFY — accountUid widened int → ?int (null = no actor)
    ├── Writer/AuditEventWriter.php              # MODIFY — write actor_uid (null preserved) + account_uid = actor ?? 0
    ├── Entity/AuditEvent.php                    # MODIFY — getActorUid(): ?int accessor
    ├── Enum/AuditEventKind.php                  # MODIFY — additive cases RevisionPublish, RevisionRevert
    ├── Listener/EntityLifecycleAuditListener.php # MODIFY — actor from AccountContext; remove entity-uid resolution (FR-004)
    ├── Listener/AgentToolAuditListener.php      # MODIFY — read event accountId → context → null (FR-005)
    ├── Listener/McpDispatchAuditListener.php    # MODIFY — preserve null actor (no 0-coercion)
    ├── Listener/PublishPointerAuditListener.php # NEW — RevisionPointerMovedEvent → revision.publish / revision.revert (FR-006)
    ├── Storage/AppendOnlyAuditDatabase.php      # MODIFY — query() literal-stripping token guard (D6/FR-008)
    └── AuditServiceProvider.php                 # MODIFY — resolve AccountContext; subscribe PublishPointerAuditListener

packages/ai-observability/src/Event/
└── AgentRunToolCallObserved.php       # MODIFY — additive `?int $accountId = null` constructor param

packages/ai-agent/src/
└── AgentExecutor.php                  # MODIFY — pass accountId at both dispatch sites (:337, :367);
                                       #          set/restore AccountContext around the run (queue-safe) (D1)

packages/mcp/src/
├── Event/McpDispatchEvent.php         # NEW — NAME='waaseyaa.mcp.dispatch'; method, params, accountUid (D5/FR-007)
└── McpEndpoint.php                    # MODIFY — optional ?EventDispatcherInterface; fire event post-auth/post-parse;
                                       #          set/restore AccountContext to the bearer-auth account

CHANGELOG.md                           # MODIFY — [Unreleased]: revision_author column, actor_uid + null-vs-0,
                                       #          descriptor widening, new kinds/events, raw-SQL guard
docs/specs/revision-system-unified.md  # MODIFY — author column on live dialect; explicit dormant-dialect retirement (FR-009)
docs/specs/ocap-audit-log.md           # MODIFY — actor_uid column, new kinds, listener catalogue + publish listener, query() guard
docs/specs/mcp-endpoint.md             # MODIFY — dispatch-event seam
docs/specs/access-control.md           # MODIFY — AccountContext service contract

tests:
packages/access/tests/Unit/Context/RequestAccountContextTest.php             # NEW — set/current/clear, null default
packages/entity-storage/tests/Unit/SaveContextTest.php                       # extend — withActorUid states incl. explicit-null
packages/entity-storage/tests/Unit/Schema/ (revision spec tests)             # extend — revision_author in specs + additive arm
packages/entity-storage/tests/Integration/RevisionAuthor/RevisionAuthorTest.php  # NEW — record + readback via revisionMetadata();
                                                                              #       revert authorship; override precedence;
                                                                              #       pre-existing-table ADD COLUMN, old rows null (SC-004)
packages/audit/tests/Unit/Listener/ (3 listeners)                            # extend — context actor, null-vs-0, event accountId
packages/audit/tests/Unit/Listener/PublishPointerAuditListenerTest.php       # NEW — publish + revert kinds, transition payload
packages/audit/tests/Unit/Storage/AppendOnlyAuditDatabaseTest.php            # extend — query() guard matrix incl. literal-stripping
packages/audit/tests/Integration/AuditImmutabilityTest.php                   # extend — raw UPDATE/DELETE/DROP/ALTER via query() throws (SC-003)
packages/audit/tests/Integration/AuditAttributionTest.php                    # NEW — all four #1645 surfaces record correct actor (NFR-002)
packages/mcp/tests/Unit/McpEndpointDispatchEventTest.php                     # NEW — event fired with method/account; name pinned
                                                                              #       to McpDispatchAuditListener::EVENT_NAME
tests/Integration/Provenance/KernelRevisionAuthorTest.php                    # NEW — kernel-booted save → author readback (SC-001);
                                                                              #       perf smoke for NFR-001
```

**Structure Decision**: Everything lands in existing packages along the existing persistence/audit pipelines. Four new classes only: the holder pair in `access` (the package that owns `AccountInterface` — the established anti-circularity rule), one typed pointer event in `entity-storage` (sibling of `BeforeSaveEvent`), one audit listener, and one MCP event DTO.

## Design Outline

1. **Actor context (FR-002 — D1)** — `AccountContextInterface` + `RequestAccountContext` in `packages/access/src/Context/`. One instance built in `AbstractKernel::bootEntityTypeManager()`, captured by the repository factory closure (validator precedent) and bound on the services bus. Set by `SessionMiddleware` (every HTTP request, mirrors `_account`), `McpEndpoint` (bearer-auth account, set/restore in `finally`), `AgentExecutor` (initiator account around the run, queue-safe, set/restore in `finally`). CLI/queue/bootstrap default: `null`. Explicit override: `SaveContext::withActorUid(?int)` with an `actorOverridden` flag so an explicit null is distinguishable from "not overridden".
2. **Revision author recording (FR-001 — D2)** — `EntityRepository` resolves the actor once per operation (override → context → null) and passes it through the new trailing `?int $author` parameter of `RevisionableStorageDriver::writeRevision()` at all seven callsites, including `rollback()` (revert authorship = the reverter, spec edge case) and `backfillInitialRevisions()` (null in CLI). Both private write paths add `revision_author` to the row.
3. **Schema (FR-003 — D2)** — `revision_author` (nullable int, no default, soft FK) added to `buildRevisionTableSpec()` + `buildTranslationRevisionTableSpec()`; `ensureRevisionTable()`/`ensureTranslationRevisionTable()` gain the additive arm (`fieldExists` → `addField`) they currently lack — this covers both production callsites (kernel factory, `EntitySchemaSync`). Pre-existing rows read back NULL → null author.
4. **Metadata readback (FR-001)** — `loadRevision()` (and the translation-revision load paths) construct `RevisionMetadata(revision_created, revision_author, revision_log)` from the row and call `setRevisionMetadata()` — the first production caller of that hydration seam. `listRevisions()` inherits via `loadRevision()`.
5. **Audit actor (FR-004/FR-005 — D3)** — additive nullable `actor_uid` column + index; `AuditEventDescriptor::$accountUid` widens to `?int`; writer writes `actor_uid` verbatim and `account_uid = actor ?? 0` (legacy compatibility). Lifecycle listener reads the context (never the entity's `uid` field); agent-tool listener reads the new `accountId` on `AgentRunToolCallObserved` (populated by `AgentExecutor` from `$initiatorAccount`), falling back to context, else null.
6. **Publish audit (FR-006 — D4)** — `RevisionPointerMovedEvent(entityTypeId, entityId, operation, fromRevisionId, toRevisionId)` dispatched from `setPublishedRevision()` (publish) and `setCurrentRevision()` (revert) alongside the legacy `REVISION_REVERTED`; `PublishPointerAuditListener` records `revision.publish` / `revision.revert` (two additive enum cases) with the context actor and the transition in attributes.
7. **MCP dispatch (FR-007 — D5)** — `McpDispatchEvent` fired from `McpEndpoint::dispatch()` after auth + parse, before method routing; optional dispatcher dependency; best-effort try/catch; event name string pinned to the audit listener's constant by test. No dependency on #1635/#1636.
8. **Raw-SQL guard (FR-008 — D6)** — `query()` strips string literals/comments, then throws the `assertMutable()` error when a mutation verb (UPDATE/DELETE/DROP/ALTER/TRUNCATE) and an append-only table name co-occur. Prune and the read query path resolve the raw database and are untouched (NFR-003 structural, existing suite pins it).
9. **Dialect reconciliation (FR-009 — D7)** — live dialect adopts `revision_author` (single authoritative definition); `docs/specs/revision-system-unified.md` explicitly marks the dormant `RevisionTableBuilder` emission dialect as superseded/non-live; `RevisionMetadata` docblock corrected to the live columns.
10. **Docs & CHANGELOG (C-003)** — `[Unreleased]` entries for the new column, the actor semantics (null vs 0), the descriptor widening, the new kinds/events, and the guard; the four spec docs updated in the same PR (drift rule).

## Risks (premortem)

- **Stale actor in long-lived processes** — a worker that saves after a request finishes could inherit a stale context. Mitigation: the two non-HTTP setters (MCP, AgentExecutor) restore the previous value in `finally`; `SessionMiddleware` overwrites unconditionally per request; the contract documents that queue jobs see null unless they set the context or use the `SaveContext` override (spec edge case "queue/job writes").
- **`audit_event` nullability dead-end discovered late** — already de-risked: verified NOT NULL at source; D3's additive `actor_uid` path is the plan of record, not a fallback. ALTER ADD COLUMN (nullable, no default) is metadata-only on SQLite/MySQL 8/PostgreSQL — safe on large audit tables.
- **Revision-table additive sync surprises** — the spec assumed sync machinery existed; it does not for revision tables (early return at `SqlSchemaHandler:253-255`). The new arm runs at kernel boot / db:init, not per save; one `fieldExists` probe per boot per revisionable type. A pinning test asserts the column lands physically (otherwise `foldData()` would silently fold the author into `_data` — readable but off-contract).
- **Descriptor widening (`int → ?int`) ripples** — every existing `AuditEventDescriptor` construction keeps compiling (int accepted); only readers of `->accountUid` see `?int`. The single production reader is the writer (updated). CHANGELOG documents the alpha-phase API change (DIR-003).
- **Double-defined event name** (`'waaseyaa.mcp.dispatch'` in both mcp and audit, since mcp must not require audit) — pinned by a cross-package test comparing `McpDispatchEvent::NAME` to `McpDispatchAuditListener::EVENT_NAME`.
- **Old dashboards keep conflating 0** — `account_uid` retains its 0-sentinel; consumers reading the legacy column see no improvement until they adopt `getActorUid()`. Accepted: C-004 compatibility is the point; docs call out the authoritative column.
- **Audit suite breaks under attribution change** — existing audit tests may assert `account_uid` values produced by the old entity-`uid` resolution. Expected and correct to update: those assertions encode the bug (#1645). The immutability/prune/chaos tests must pass *unchanged* (NFR-003); attribution assertions change deliberately and visibly.
- **MCP endpoint constructor resolution** — `McpEndpoint` is container-resolved from a controller string; the optional `?EventDispatcherInterface` default-null keeps it constructible even if the container cannot supply a dispatcher (event silently not fired, matching best-effort audit semantics). WP verifies the resolver actually injects it in a booted kernel.

## Complexity Tracking

*No charter violations to justify.*
