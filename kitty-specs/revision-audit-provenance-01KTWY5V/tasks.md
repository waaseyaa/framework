# Tasks: Revision & Audit Provenance

**Mission**: `revision-audit-provenance-01KTWY5V` | **Branch**: `main` → merges to `main`
**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md) (D1–D7), [data-model.md](data-model.md), [contracts/](contracts/)
**Tracking**: #1644, #1645, #1648 | **Target release**: v0.1.0-alpha.205

## Subtask Index

| ID | Description | WP | Parallel |
|----|-------------|----|----------|
| T001 | AccountContextInterface + RequestAccountContext in packages/access | WP01 | |
| T002 | SessionMiddleware sets the context alongside `_account` (+ HttpKernel pass) | WP01 | |
| T003 | AbstractKernel shared instance, repository-factory seam, services-bus exposure | WP01 | |
| T004 | Unit tests: holder (set/current/clear/null default) + middleware test | WP01 | |
| T005 | SaveContext::withActorUid(?int) + actorOverridden flag + unit tests | WP02 | |
| T006 | EntityRepository actor resolution → all 7 writeRevision sites + RevisionPointerMovedEvent | WP02 | |
| T007 | RevisionableStorageDriver trailing `?int $author` + column write on both paths | WP02 | |
| T008 | SqlSchemaHandler revision_author in both revision-table specs + additive ADD-COLUMN arm | WP02 | |
| T009 | RevisionMetadata hydration on revision loads + docblock fix (D7) | WP02 | |
| T010 | Integration tests: record/readback, revert authorship, pre-existing-table migration, NFR-001 perf | WP02 | |
| T011 | AuditEventSchemaHandler actor_uid (CREATE + guarded ALTER + index); descriptor ?int; writer dual-column; AuditEvent::getActorUid() | WP03 | |
| T012 | Listener actor sources: lifecycle from context, agent-tool event→context→null, MCP null-preserving | WP03 | |
| T013 | PublishPointerAuditListener + 2 additive AuditEventKind cases + provider wiring + composer access edge | WP03 | |
| T014 | AppendOnlyAuditDatabase::query() literal-stripping token guard (D6) | WP03 | |
| T015 | Unit tests: listener actor matrices, guard matrix, writer/descriptor/read model | WP03 | |
| T016 | Integration tests: AuditAttributionTest (4 surfaces, NFR-002) + AuditImmutabilityTest raw-SQL + suite green (NFR-003) | WP03 | |
| T017 | AgentRunToolCallObserved additive `?int $accountId` + AgentExecutor (both dispatch sites, context set/restore) | WP04 | [P] |
| T018 | McpDispatchEvent + McpEndpoint optional dispatcher, fire post-auth/post-parse, context set/restore | WP04 | [P] |
| T019 | Unit tests: ai-agent/ai-observability + McpEndpointDispatchEventTest + event-name pin | WP04 | [P] |
| T020 | CHANGELOG [Unreleased] entries (column, actor semantics, kinds/events, guard) | WP05 | |
| T021 | Spec docs: revision-system-unified (FR-009 retirement), ocap-audit-log, mcp-endpoint, access-control | WP05 | |
| T022 | Drift detector run for touched specs | WP05 | |
| T023 | Quickstart walkthrough + gates | WP05 | |

## WP01 — Acting-Account Context (access + wiring)

**Prompt**: [tasks/WP01-acting-account-context.md](tasks/WP01-acting-account-context.md) | **Priority**: P1 | **Estimated prompt**: ~290 lines
**Goal**: A request-scoped acting-account holder exists in `packages/access` (the package that owns `AccountInterface`), is set by `SessionMiddleware` on every HTTP request, and is constructed once per kernel and exposed on the kernel services bus + repository-factory seam so WP02/WP03/WP04 consumers can reach the same instance.
**Independent test**: `./vendor/bin/phpunit packages/access/tests/ packages/user/tests/` green; `bin/check-package-layers` green (no new manifest edges in this WP).
**Dependencies**: none (lane root)

- [ ] T001 AccountContextInterface + RequestAccountContext in `packages/access/src/Context/` (WP01)
- [ ] T002 SessionMiddleware optional ctor param; context set alongside `_account` on both branches; HttpKernel passes the kernel instance (WP01)
- [ ] T003 AbstractKernel: one shared RequestAccountContext, `attachAccountContext()` forward seam in the repository factory, services-bus + handler-container exposure (WP01)
- [ ] T004 Unit tests: holder semantics (null default, set/current/clear, overwrite) + middleware sets context for authenticated/anonymous/pre-set `_account` (WP01)

**Implementation sketch**: research D1. The repository-factory pass to `EntityRepository` cannot be a named constructor arg in this WP (the receiving parameter is WP02's file) — WP01 ships a `method_exists`-guarded `setAccountContext()` forward seam in the factory closure (no-op until WP02 merges; precedent: the `method_exists` hydration at `EntityRepository:620-627`). Compile-green standalone.

## WP02 — Revision Author (entity-storage)

**Prompt**: [tasks/WP02-revision-author.md](tasks/WP02-revision-author.md) | **Priority**: P1 | **Estimated prompt**: ~420 lines
**Goal**: Every revision created through the standard save path records the resolved actor (override → context → null), readable back via `revisionMetadata()`; live revision tables gain `revision_author` additively — including on pre-existing tables (the additive arm `ensureRevisionTable()` lacks today); publish-pointer moves dispatch a typed transition event.
**Independent test**: `./vendor/bin/phpunit packages/entity-storage/tests/ tests/Integration/Provenance/` green, including the pre-existing-table migration case and the NFR-001 perf smoke.
**Dependencies**: WP01

- [ ] T005 SaveContext: `withActorUid(?int)` + `actorOverridden` flag threaded through every existing `with*()` builder + unit tests incl. explicit-null override (WP02)
- [ ] T006 EntityRepository: `setAccountContext()` receiver, actor resolution once per operation, threaded to all 7 writeRevision callsites; RevisionPointerMovedEvent (publish|revert, from→to) dispatched from setPublishedRevision()/setCurrentRevision() alongside legacy REVISION_REVERTED (WP02)
- [ ] T007 RevisionableStorageDriver: trailing `?int $author = null` on writeRevision(); `revision_author` written on both private row-assembly paths; updateRevision() exclusion (WP02)
- [ ] T008 SqlSchemaHandler: revision_author in buildRevisionTableSpec() + buildTranslationRevisionTableSpec(); additive fieldExists→addField arm in ensureRevisionTable()/ensureTranslationRevisionTable() (research falsification #1 — both early-return today; mirror ensureBundleSubtable) (WP02)
- [ ] T009 RevisionMetadata hydration in loadRevision() + translation-revision loads; RevisionMetadata docblock corrected to the live dialect (D7) (WP02)
- [ ] T010 Integration tests: record/readback matrix (N/0/null), revert authorship, override precedence, pre-existing-table additive migration + null-author readback, physical-column pin, kernel-booted SC-001 + NFR-001 ≤5% perf smoke (WP02)

**Implementation sketch**: research D2/D4, contracts/revision-author.md is the authoritative behavior spec. Biggest risk: the additive sync arm (the spec's original assumption that sync machinery existed for revision tables was falsified — research "Verified ground truth"). The kernel-booted test exercises WP01's forward seam end-to-end.

## WP03 — Audit Actor Attribution (audit)

**Prompt**: [tasks/WP03-audit-actor-attribution.md](tasks/WP03-audit-actor-attribution.md) | **Priority**: P1 | **Estimated prompt**: ~440 lines
**Goal**: All four #1645 surfaces record the correct three-state actor in the new additive `actor_uid` column (`account_uid` stays legacy `actor ?? 0`); publish-pointer moves become auditable via a new listener + two additive kinds; the raw-SQL hole in the append-only decorator closes (FR-008) with zero false positives (NFR-003).
**Independent test**: `./vendor/bin/phpunit packages/audit/tests/` green including the unchanged immutability/chaos suite; `bin/check-package-layers` + `composer check-composer-policy` green with the new audit → access edge.
**Dependencies**: WP01, WP02, WP04

- [ ] T011 AuditEventSchemaHandler: actor_uid in CREATE TABLE + idempotent guarded ALTER for existing installs + index; AuditEventDescriptor::$accountUid int→?int; AuditEventWriter actor_uid verbatim + account_uid = actor ?? 0; AuditEvent::getActorUid(): ?int (WP03)
- [ ] T012 EntityLifecycleAuditListener actor from AccountContext (drop entity-uid resolution); AgentToolAuditListener event accountId → context → null; McpDispatchAuditListener null-preserving (WP03)
- [ ] T013 PublishPointerAuditListener (RevisionPointerMovedEvent → revision.publish / revision.revert additive kinds); AuditServiceProvider wiring; composer.json same-layer access edge `^0.1.0-alpha.203` per CP-NEW (re-verify tag at implementation time) (WP03)
- [ ] T014 AppendOnlyAuditDatabase::query() literal-stripping token guard sharing assertMutable()'s message factory (D6) (WP03)
- [ ] T015 Unit tests: per-listener actor matrices (N/0/null, entity-uid never consulted), publish listener kinds + payload, guard matrix (verbs × tables × literals × comments), descriptor/writer/read model (WP03)
- [ ] T016 Integration tests: AuditAttributionTest — all four surfaces record correct actor_uid (NFR-002 100%); AuditImmutabilityTest extended with raw UPDATE/DELETE/DROP/ALTER via query() throwing (SC-003); existing immutability + chaos + prune suite green unchanged (NFR-003) (WP03)

**Implementation sketch**: research D3/D4/D6, contracts/audit-attribution.md authoritative. Expect to UPDATE existing attribution assertions that encode the #1645 bug (entity-uid actors) — deliberately and visibly; the immutability/prune/chaos tests must pass UNCHANGED.

## WP04 — Agent & MCP Event Provenance (ai-agent + ai-observability + mcp)

**Prompt**: [tasks/WP04-agent-mcp-event-provenance.md](tasks/WP04-agent-mcp-event-provenance.md) | **Priority**: P1 | **Estimated prompt**: ~300 lines
**Goal**: `AgentRunToolCallObserved` carries the initiator account; `AgentExecutor` populates it at both dispatch sites and scopes the account context around runs (queue-safe); `McpEndpoint` actually fires the `waaseyaa.mcp.dispatch` event the audit listener has been waiting on — best-effort, independent of #1635/#1636.
**Independent test**: `./vendor/bin/phpunit packages/ai-agent/tests/ packages/ai-observability/tests/ packages/mcp/tests/` green; event name pinned to the audit listener's constant by test.
**Dependencies**: WP01

- [ ] T017 AgentRunToolCallObserved: additive `?int $accountId = null`; AgentExecutor passes initiator at both dispatch sites (:337, :367) + set/restore AccountContext around executeRun() in finally (WP04)
- [ ] T018 NEW McpDispatchEvent (NAME 'waaseyaa.mcp.dispatch'); McpEndpoint optional `?EventDispatcherInterface`, fire post-auth/post-parse pre-match, best-effort try/catch; AccountContext set/restore to bearer-auth account in finally (WP04)
- [ ] T019 Unit tests: event accountId carried on success + threw paths; context restored after run (incl. throw); endpoint fires exactly once, 401/parse-error fire nothing, RPC response unchanged on dispatcher failure; `McpDispatchEvent::NAME === McpDispatchAuditListener::EVENT_NAME` pin (WP04)

**Implementation sketch**: research D1 (setters 2–3), D5. The event-name pin test lives in packages/mcp/tests with a require-dev `waaseyaa/audit` edge (L6→L1 downward, legal). Runs lane-parallel after its dependency completes.

## WP05 — Docs, Break Notes & Walkthrough

**Prompt**: [tasks/WP05-docs-break-notes-walkthrough.md](tasks/WP05-docs-break-notes-walkthrough.md) | **Priority**: P2 | **Estimated prompt**: ~270 lines
**Goal**: The release is documented before the tag exists: CHANGELOG under `[Unreleased]` (C-003), the four subsystem specs updated from the contracts (incl. explicit dormant-dialect retirement, FR-009), drift detector clean for touched specs, quickstart walkthrough recorded.
**Independent test**: quickstart.md steps 1–7 pass end-to-end; `composer verify` components green.
**Dependencies**: WP01, WP02, WP03, WP04

- [ ] T020 CHANGELOG [Unreleased]: revision_author column + additive sync; actor_uid + null-vs-0 semantics + account_uid legacy; AuditEventDescriptor int→?int widening; revision.publish/revision.revert kinds + RevisionPointerMovedEvent; McpDispatchEvent; query() raw-SQL guard — NOT under a version heading (WP05)
- [ ] T021 docs/specs updates: revision-system-unified.md (author column on live dialect + explicit dormant-dialect retirement, FR-009), ocap-audit-log.md (actor_uid, kinds, listener catalogue, guard), mcp-endpoint.md (dispatch-event seam), access-control.md (AccountContext service contract) (WP05)
- [ ] T022 tools/drift-detector.sh run; resolve flags for the four touched specs (WP05)
- [ ] T023 Execute quickstart.md steps 1–7 as final validation; record per-step results in the WP file (WP05)

**Implementation sketch**: write docs from the two contracts and data-model.md, not from memory of the diff. CHANGELOG-under-[Unreleased] is the alpha.202 lesson; the release-cut workflow stamps the heading.

## Lane / Parallelization Summary

- **Lane A**: WP01 → WP02 → WP03 → WP05
- **Lane B**: WP04 (starts after WP01, parallel with WP02; WP03 waits for both lanes since its agent-tool listener tests consume WP04's event shape)
- MVP scope: WP01 + WP02 (revision authorship live end-to-end); WP03 + WP04 complete the audit surfaces; WP05 gates the release cut.

## Cross-WP seam (documented adaptation)

The plan's "optional ctor param on EntityRepository passed by the kernel factory" cannot be split cleanly across two sequentially-merging WPs with disjoint file ownership (`AbstractKernel.php` is WP01's; `EntityRepository.php` is WP02's; the named-arg pass would not compile in WP01 before WP02 adds the parameter). Adaptation: WP01 ships a `method_exists('setAccountContext')`-guarded attach call in the repository factory (no-op until WP02), and WP02 adds the `setAccountContext(?AccountContextInterface)` receiver on EntityRepository. Same shared-instance semantics, compile-green at every merge point. Precedent: `EntityRepository::loadRevision()`'s existing `method_exists` hydration seam.
