# Data Model: Revision & Audit Provenance

**Date**: 2026-06-12 | **Plan**: [plan.md](plan.md)

No new entity types. The "data model" of this mission is the acting-account state model, the revision metadata shape, the additive audit columns, and the publish-transition payload.

## Acting account — three states, one resolution order

| State | Value | Meaning | Example contexts |
|---|---|---|---|
| Account N | `int ≥ 1` (also `PHP_INT_MAX` for `DevAdminAccount`) | A real authenticated account acted | Web session, MCP bearer token, agent run initiator |
| Anonymous | `0` (`AnonymousUser` sentinel) | An anonymous web actor acted — a *real* acting context | Unauthenticated HTTP request |
| None | `null` | No acting context exists | CLI batch, system bootstrap, queue job without explicit actor |

`0` and `null` are never interchangeable anywhere in this mission: `0` means "the anonymous account did it", `null` means "nobody was in scope". No surface may coerce `null → 0` (FR-001, FR-004).

**Resolution order** (computed once per save / pointer operation in `EntityRepository`, and per audit row in the listeners):

```
actor(op) = SaveContext.actorUid          if SaveContext.actorOverridden   (explicit, may be null)
          ⊕ AccountContext.current()?.id() (request-scoped holder; null when unset)
          ⊕ null
```

**Holder writers** (each restores/overwrites correctly):

| Writer | Scope | Value |
|---|---|---|
| `SessionMiddleware` | per HTTP request (unconditional overwrite) | the `_account` account (N / 0 / dev fallback) |
| `McpEndpoint::dispatch()` | per MCP request, set/restore in `finally` | bearer-auth-resolved account |
| `AgentExecutor::executeRun()` | per agent run, set/restore in `finally` | `$initiatorAccount` |
| (nothing) | CLI / queue / bootstrap | `null` |

## SaveContext — additive override surface

`SaveContext` gains two readonly members threaded through every existing `with*()` builder:

| Member | Type | Default | Semantics |
|---|---|---|---|
| `actorUid` | `?int` | `null` | The override value (meaningless unless `actorOverridden`) |
| `actorOverridden` | `bool` | `false` | `withActorUid(?int)` sets true; an explicit `withActorUid(null)` forces a null author even inside an authenticated request |

Existing flags (`withoutNewRevision`, `langcode`, `isImport`, `translations`) are unchanged; `SaveContext::default()` yields the not-overridden state (pre-mission behavior).

## Revision metadata (live dialect, per revision row)

Live tables `<entity>_revision` and `<entity>__translation__revision` after this mission:

| Column | Type | Nullability | Written by | Notes |
|---|---|---|---|---|
| `revision_created` | varchar(32) | NOT NULL | driver (existing) | unchanged |
| `revision_log` | text | NULL | driver (existing) | unchanged |
| `revision_author` | int | **NULL, no default** | driver (**NEW**) | soft FK to user uid — no ON DELETE; history survives user deletion. Name/semantics adopted from the dormant dialect (D7) |

Read model — `RevisionMetadata` (existing shape, finally hydrated):

```
RevisionMetadata(
    revisionCreatedAt: \DateTimeImmutable   ← revision_created
    revisionAuthor:    ?int                 ← revision_author (SQL NULL → null)
    revisionLog:       ?string              ← revision_log
)
```

Hydration points (all NEW — `setRevisionMetadata()` currently has zero production callers): `EntityRepository::loadRevision()`, the translation-revision load paths, and therefore `listRevisions()` / `listTranslationRevisions()` transitively. Pre-existing rows (created before the column existed, or seeded/backfilled without context) hydrate `revisionAuthor: null`.

**Revert authorship** (spec edge case): `rollback()` writes a *new* revision whose `revision_author` is the resolved actor at revert time — never the target revision's original author (the original row is untouched).

## Audit event — additive actor fields

`audit_event` table after this mission:

| Column | Status | Type | Semantics |
|---|---|---|---|
| `account_uid` | unchanged (legacy) | INTEGER NOT NULL DEFAULT 0 | `actor ?? 0` — keeps every existing query/dashboard working (C-004). 0 still conflates anonymous/none here, by design |
| `actor_uid` | **NEW** | INTEGER NULL | authoritative actor: N / 0 / NULL per the three-state model. Index `audit_event_actor_uid` |

New installs get `actor_uid` in `CREATE TABLE`; existing installs get a guarded `ALTER TABLE audit_event ADD COLUMN actor_uid INTEGER` (idempotent, metadata-only DDL). Rows written before the upgrade have `actor_uid` NULL — correctly read as "actor unknown/not recorded".

Write DTO — `AuditEventDescriptor::$accountUid` widens `int → ?int` (null = no acting context). Writer mapping:

```
INSERT audit_event:
    actor_uid   = descriptor.accountUid            (null preserved)
    account_uid = descriptor.accountUid ?? 0       (legacy sentinel)
```

Read model — `AuditEvent` gains `getActorUid(): ?int` (missing column / NULL → null); `getAccountUid(): int` unchanged.

### Audit event kinds (additive — extension policy of ocap-audit-log.md)

| Case | Value | Emitted by | Attributes payload |
|---|---|---|---|
| `RevisionPublish` | `revision.publish` | `PublishPointerAuditListener` ← `RevisionPointerMovedEvent(operation: publish)` | `{entity_id, from_revision_id: ?int, to_revision_id: int, operation: "publish"}` |
| `RevisionRevert` | `revision.revert` | same listener ← `operation: revert` | `{entity_id, from_revision_id: ?int, to_revision_id: int, operation: "revert"}` |

### Per-surface actor source (the four #1645 surfaces)

| Surface | Listener | Actor source after this mission | Before |
|---|---|---|---|
| Entity lifecycle (write/delete) | `EntityLifecycleAuditListener` | `AccountContext` (N/0/null) | entity's own `uid` field, else 0 |
| Agent tool execute | `AgentToolAuditListener` | `AgentRunToolCallObserved::$accountId` (NEW, set by executor) → context → null | hardcoded 0 |
| Publish pointer | `PublishPointerAuditListener` (NEW) | `AccountContext` | no row at all |
| MCP dispatch | `McpDispatchAuditListener` | `McpDispatchEvent::$accountUid` (?int, null preserved) | event never fired; 0-coercion in listener |

## Publish-pointer transition event

`Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent` (dispatched by FQCN, alongside legacy `EntityEvents::REVISION_REVERTED`):

| Field | Type | Notes |
|---|---|---|
| `entityTypeId` | string | |
| `entityId` | string | |
| `operation` | `'publish'` \| `'revert'` | publish = `setPublishedRevision()`; revert = `setCurrentRevision()`. `rollback()` dispatches no pointer event (it creates a revision; authorship covered by `revision_author`) |
| `fromRevisionId` | `?int` | prior pointer value; null when previously unpublished (publish) or unknown |
| `toRevisionId` | int | target revision |

## MCP dispatch event

`Waaseyaa\Mcp\Event\McpDispatchEvent` — `NAME = 'waaseyaa.mcp.dispatch'` (string-pinned to `McpDispatchAuditListener::EVENT_NAME` by test):

| Field | Type | Notes |
|---|---|---|
| `method` | string | JSON-RPC method (`tools/call`, `tools/list`, …) |
| `params` | array | raw params — the audit listener stores only a SHA-256 hash, never the payload |
| `accountUid` | `?int` | bearer-auth account id |

## Configuration surface

| Name | Kind | Default | Effect |
|---|---|---|---|
| *(none new)* | — | — | Attribution has no opt-out switch: recording a nullable author/actor is non-breaking by construction (nullable columns, additive events). The per-save `SaveContext::withActorUid()` is the only knob, and it is an override, not a disable. |

## State transitions

None beyond the publish/revert pointer transition captured by `RevisionPointerMovedEvent` above. Attribution is a pure annotation on existing writes: every save/publish/audit path completes exactly as before, with author/actor recorded alongside.
