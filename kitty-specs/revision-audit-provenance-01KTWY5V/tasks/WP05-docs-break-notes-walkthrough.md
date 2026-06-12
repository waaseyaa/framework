---
work_package_id: WP05
title: Docs, Break Notes & Walkthrough
dependencies:
- WP01
- WP02
- WP03
- WP04
requirement_refs:
- C-003
- FR-009
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T020
- T021
- T022
- T023
history:
- date: '2026-06-12T03:32:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: docs/specs/
execution_mode: code_change
owned_files:
- CHANGELOG.md
- docs/specs/revision-system-unified.md
- docs/specs/ocap-audit-log.md
- docs/specs/mcp-endpoint.md
- docs/specs/access-control.md
tags: []
---

# WP05 — Docs, Break Notes & Walkthrough

**Mission**: revision-audit-provenance-01KTWY5V | **Tracks**: #1644, #1645, #1648
**Requirements**: FR-009 (docs half), C-003 (spec constraints; functional FRs covered by the other WPs) | **Dependencies**: WP01, WP02, WP03, WP04
**Command**: `spec-kitty agent action implement WP05 --agent <name>`

## Objective

Document the release before the tag exists: CHANGELOG entries under `[Unreleased]` covering every consumer-visible change, the four affected subsystem specs brought in line with the new behavior (the drift rule: behavior change and spec change in the same release), the explicit dormant-dialect retirement that completes FR-009, and the quickstart walkthrough executed end-to-end as the mission's final validation. This WP gates the v0.1.0-alpha.205 release cut.

## Context (read first)

- Write the docs FROM the mission artifacts, not from memory of the diff: `contracts/revision-author.md`, `contracts/audit-attribution.md`, `data-model.md` (three-state actor model, per-surface table, dual-column mapping), `research.md` D3 (the `actor_uid`-not-`account_uid` adaptation and its rationale) and D7 (reconcile-or-retire decision).
- CHANGELOG convention: entries go under `[Unreleased]`, NOT a pre-stamped version heading — the release-cut workflow stamps the heading (alpha.202 lesson, C-003). Check existing section formatting: `rg -n "Unreleased" CHANGELOG.md | head` and mirror how alpha.204's entries were structured.
- `docs/specs/revision-system-unified.md` — the LIVE canonical revision spec (read-first per the orchestration table). Its §6 already schedules the vid stack for retirement but keeps `RevisionTableBuilder`/`TranslationSchemaHandler` "where still used by the translation substrate". FR-009's docs half lands here.
- `docs/specs/ocap-audit-log.md` — owns the audit table schema, the event-kind taxonomy (additive-only extension policy), and the listener catalogue.
- `docs/specs/mcp-endpoint.md` — endpoint contract; gains the dispatch-event seam.
- `docs/specs/access-control.md` — access package contract; gains the AccountContext service.
- Implemented reality to restate (verify against the merged WPs, cite nothing that didn't land): `revision_author` nullable column + additive sync on both live revision tables; `RevisionMetadata` hydration on revision loads; `SaveContext::withActorUid()`; `RevisionPointerMovedEvent` + `revision.publish`/`revision.revert` kinds; `actor_uid` + `account_uid = actor ?? 0`; `AuditEventDescriptor` `int → ?int`; `McpDispatchEvent`; the `query()` raw-SQL guard; the new `audit → access` composer edge.
- This WP touches NO PHP source. If the walkthrough surfaces a code defect, record it for orchestrator action — do not fix it here.

## Requirement / constraint map

| Deliverable | Requirement / constraint |
|---|---|
| CHANGELOG under `[Unreleased]` | C-003 (CI-gated release flow; heading stamped at release-cut) |
| revision-system-unified.md dormant-dialect retirement | FR-009 (the "explicitly retired in docs" half; the column convergence half landed with the entity-storage WP) |
| ocap-audit-log.md actor/kind/guard updates | drift rule (CLAUDE.md "Stale specs cause bad code"); C-004 legacy-column documentation |
| mcp-endpoint.md dispatch seam | drift rule; spec assumption "#1635–#1637 are separate" preserved in text |
| access-control.md AccountContext contract | drift rule; FR-002's consumer-facing documentation |
| Quickstart walkthrough | SC-001..SC-005 final verification; mission acceptance evidence |

## Out of scope for this WP (do not touch)

- Any PHP file. Mission 1's docs WP carried one code touch (a pinning test); this one carries none — defects found during the walkthrough are recorded for orchestrator action, not fixed.
- `docs/specs/entity-storage-two-axis.md` — already marked SUPERSEDED; the retirement note goes in revision-system-unified.md §6, not there.
- `docs/specs/entity-system.md`, `docs/specs/infrastructure.md`, `docs/specs/ai-integration.md` — if the drift detector flags them via this mission's source changes, note it in completion notes; fixing them is out of owned files.
- CLAUDE.md — this mission adds no env vars and no new operation checklist entries (attribution has no opt-out switch by design — data-model.md "Configuration surface").
- `kitty-specs/**` mission artifacts — they are planning history, not living specs; do not "update" spec.md to match D3.

## Subtasks

### T020 — CHANGELOG `[Unreleased]`

**File**: `CHANGELOG.md`

Under `[Unreleased]`, following the file's existing Added/Changed conventions:

1. **Added** — revision authorship (#1644): nullable `revision_author` column on `<entity>_revision` and `<entity>__translation__revision`, recorded from the request-scoped acting account on every standard save path (override via `SaveContext::withActorUid()`); readable via `revisionMetadata()->revisionAuthor`; pre-existing revisions read back `null`. State the additive-sync behavior explicitly: existing tables gain the column automatically at kernel boot / `db:init` — no migration step, no row rewrites.
2. **Added** — audit actor attribution (#1645): new nullable `audit_event.actor_uid` column (+ index) is the authoritative actor with three-state semantics — account N / anonymous `0` / `NULL` = no acting context; `account_uid` retained unchanged as the legacy `actor ?? 0` compat column (existing dashboards/filters unaffected). Name the per-surface fixes: entity lifecycle now records the acting account (never the entity's own `uid` field), agent tool rows record the initiator (hardcoded 0 gone), publish/revert pointer moves are audited for the first time (`revision.publish` / `revision.revert` kinds via `RevisionPointerMovedEvent`), MCP dispatch events actually fire.
3. **Changed** — `AuditEventDescriptor::$accountUid` widened `int → ?int` (alpha-phase API change; existing int-passing constructions unaffected; null = no actor). One line on the acting-account context: `Waaseyaa\Access\Context\AccountContextInterface` set per HTTP request / MCP request / agent run; CLI/queue default null.
4. **Fixed/Security** (match the file's convention for guard-strengthening entries) — append-only audit guard now also rejects raw SQL `UPDATE/DELETE/DROP/ALTER/TRUNCATE` against audit tables through `AppendOnlyAuditDatabase::query()` (#1648), same error as the builder-level guard; SELECTs with mutation verbs inside string literals pass.
5. Note the new internal dependency edge `waaseyaa/audit → waaseyaa/access` if the CHANGELOG conventionally records manifest changes (check precedent; omit if not conventional).
6. **NOT under a version heading.** No "alpha.205" string anywhere in your diff.

Drafting skeleton (adapt to the file's real section names; facts from data-model.md):

```markdown
## [Unreleased]

### Added
- Revision authorship (#1644): nullable `revision_author` on `<entity>_revision` /
  `<entity>__translation__revision`, recorded from the acting account on every
  standard save path; readable via `revisionMetadata()->revisionAuthor`; existing
  tables gain the column additively at boot/db:init; pre-existing revisions read null.
  Per-save override: `SaveContext::withActorUid(?int)` (explicit null forces no author).
- Audit actor attribution (#1645): nullable `audit_event.actor_uid` (+ index) as the
  authoritative actor — account N / anonymous 0 / NULL = no acting context. Surfaces:
  entity lifecycle (acting account, never the entity's `uid` field), agent tools
  (initiator, hardcoded 0 removed), publish/revert pointer moves (NEW
  `revision.publish` / `revision.revert` kinds via `RevisionPointerMovedEvent`),
  MCP dispatch (`waaseyaa.mcp.dispatch` now fired by `McpEndpoint`).
- `Waaseyaa\Access\Context\AccountContextInterface` — request-scoped acting-account
  holder set by SessionMiddleware / McpEndpoint / AgentExecutor; CLI/queue read null.

### Changed
- `AuditEventDescriptor::$accountUid` widened `int → ?int` (null = no actor);
  `account_uid` column unchanged, written as `actor ?? 0` (legacy compat).
- `waaseyaa/audit` now requires `waaseyaa/access` (same-layer L1 edge).

### Security
- `AppendOnlyAuditDatabase::query()` now rejects raw UPDATE/DELETE/DROP/ALTER/TRUNCATE
  targeting audit tables (#1648) — same error as the builder-level guard; string
  literals/comments are stripped before matching, so SELECTs over payloads containing
  mutation verbs still pass.
```

### T021 — Spec docs

**Files**: `docs/specs/revision-system-unified.md`, `docs/specs/ocap-audit-log.md`, `docs/specs/mcp-endpoint.md`, `docs/specs/access-control.md`

1. **revision-system-unified.md**:
   - Live-dialect schema section: add `revision_author` (nullable int, no default, soft FK — survives user deletion) to both live revision tables; document the additive sync arm in `ensureRevisionTable()`/`ensureTranslationRevisionTable()` (the early-return is gone); document `RevisionMetadata` hydration on `loadRevision()` and the resolution order (SaveContext override → AccountContext → null) with the null-vs-0 rule and revert authorship (the reverter, not the original author).
   - **Explicit dormant-dialect retirement (FR-009/D7)**: in §6, state that `RevisionTableBuilder`'s `<entity>__revision` vid emission dialect — including its `revision_created_at`/`revision_author` metadata block — is non-live and superseded; its author semantics now live in the real `<entity>_revision` tables; `syncTwoAxis()` has no production callers. The single authoritative author definition is the live dialect's `revision_author`. Deletion of the dormant stack remains conditioned on §6's staged plan (out of this mission).
   - Document `RevisionPointerMovedEvent` (operation publish|revert, from→to, dispatched alongside legacy `REVISION_REVERTED`; `rollback()` excluded).
2. **ocap-audit-log.md**: `actor_uid` column + index + migration behavior (CREATE for new installs, guarded ALTER for existing; pre-upgrade rows null); the dual-column write mapping and which column is authoritative; the two new kinds in the taxonomy table; the listener catalogue updated — per-listener actor source (the data-model.md per-surface table) + the new `PublishPointerAuditListener`; the `query()` raw-SQL guard semantics including the documented fail-closed residual cases and the structural zero-false-positive argument (writer insert-only; prune/query resolve the raw database).
3. **mcp-endpoint.md**: the dispatch-event seam — `McpDispatchEvent` (`NAME`, payload, ?accountUid), fired once per authenticated well-formed JSON-RPC request post-auth/post-parse pre-routing; best-effort; the params-hash privacy property (listener hashes, endpoint passes raw); the optional dispatcher + account-context constructor params; independence from the #1635/#1636 transport bugs.
4. **access-control.md**: the `AccountContextInterface` service contract — three-state model, who sets it (SessionMiddleware unconditionally per request; MCP endpoint and agent executor set/restore in finally; CLI/queue read null), single kernel-shared instance, how to resolve it (services bus / handler container), and the `SaveContext::withActorUid()` override relationship.
5. Spec language describes behavior (contract language), not implementation diff narration. Keep each addition in the host spec's existing voice and section structure.

Per-spec must-cover checklist (tick each before review):

| Spec | Must state |
|---|---|
| revision-system-unified.md | `revision_author` definition (nullable int, no default, soft FK); additive sync arm exists; hydration + resolution order + null-vs-0; revert authorship; `RevisionPointerMovedEvent`; §6 dormant-dialect retirement naming `RevisionTableBuilder` / `<entity>__revision` / `revision_created_at` |
| ocap-audit-log.md | `actor_uid` authoritative vs `account_uid` legacy (`actor ?? 0`); migration behavior; `revision.publish` + `revision.revert` in the kind taxonomy; per-listener actor-source table; `PublishPointerAuditListener`; `query()` guard incl. fail-closed residuals + structural NFR-003 argument |
| mcp-endpoint.md | `McpDispatchEvent` name/payload; fires once post-auth/post-parse pre-routing; best-effort; raw-params-in-event / hash-in-listener privacy split; optional dispatcher + context ctor params; #1635/#1636 independence |
| access-control.md | `AccountContextInterface` contract; three-state actor model; writer table (who sets, scoping discipline); single kernel-shared instance + resolution paths; relationship to `SaveContext::withActorUid()` |

### T022 — Drift detector

1. Run `tools/drift-detector.sh` (use Git Bash on Windows if needed).
2. The four owned specs must come back clean for this mission's source changes. Other specs flagged by this mission's WPs (e.g. `infrastructure.md` via the kernel change, `entity-system.md`, `ai-integration.md`) — evaluate: if the behavior they describe changed materially, note the gap in completion notes for orchestrator action (out of owned files); do not fix here.
3. Record the detector output summary in this WP file under a "Drift Check" heading.

### T023 — Quickstart walkthrough + gates

1. Execute `kitty-specs/revision-audit-provenance-01KTWY5V/quickstart.md` steps 1–7 end-to-end. Record a per-step PASS/FAIL table at the bottom of this WP file (Mission 1 WP04 precedent — see its "Quickstart Walkthrough" section for the expected shape, including how pre-existing Windows-local failures are documented against clean main).
2. Step 7's gates, run individually per the Windows-local convention:
   ```bash
   ./vendor/bin/phpunit tests/Integration/Provenance/ --no-progress
   ./vendor/bin/phpunit packages/entity-storage/tests/Integration/RevisionAuthor/ --no-progress
   ./vendor/bin/phpunit packages/audit/tests/ --no-progress
   ./vendor/bin/phpunit packages/mcp/tests/ --no-progress
   composer phpstan
   composer cs-check
   composer check-composer-policy
   bin/check-dead-code
   bin/check-package-layers
   bin/check-getquery-bindings
   ```
3. CHANGELOG self-check from quickstart §7: `[Unreleased]` covers the column, `actor_uid` + null-vs-0, the descriptor widening, the two kinds + pointer event, the MCP event, the guard.
4. Any failure traceable to another WP's code: record path + failing command + observed/expected in completion notes for orchestrator action. Doc-side failures: fix and re-run.

## Definition of Done

- [ ] CHANGELOG entries under `[Unreleased]` (no version heading) covering all six change groups from quickstart §7's checklist.
- [ ] All four specs updated from the contracts; the dormant-dialect retirement is explicit in revision-system-unified.md §6 (FR-009 closed).
- [ ] Drift detector recorded; the four owned specs clean.
- [ ] Quickstart steps 1–7 recorded as a per-step table, all PASS (or pre-existing-on-clean-main failures documented with evidence).
- [ ] No PHP source changes; no changes outside `owned_files`.

## Reviewer guidance

- **Diff the CHANGELOG against the merged reality, not the spec**: the spec's Key Entities wording implies `account_uid` becomes nullable — the implementation (correctly, per research D3) added `actor_uid` instead. The CHANGELOG and ocap-audit-log.md MUST name `actor_uid` as authoritative and `account_uid` as legacy; reject text written from spec.md.
- FR-009 is closed by documentation — verify the retirement note names the dormant dialect precisely (`RevisionTableBuilder`, `<entity>__revision`, `revision_created_at`) and states where the single authoritative author definition lives. A vague "superseded" line does not satisfy "explicitly retired".
- Null-vs-0 must be stated in BOTH the CHANGELOG and ocap-audit-log.md — it is the semantic consumers will get wrong.
- Verify no "alpha.205" heading appears in the CHANGELOG diff (the release-cut workflow stamps it).
- Check the walkthrough table is empirical (commands actually run, counts recorded), not aspirational.

## Quickstart Walkthrough (T023 — fill during implementation)

Record the actual run here, Mission 1 WP04 shape (environment line + per-step table + per-gate results):

| Step | Acceptance scenario(s) | Command / check | Result |
|---|---|---|---|
| 1. Revision author recorded + readable | 1–2 | `./vendor/bin/phpunit tests/Integration/Provenance/ --no-progress` + `packages/entity-storage/tests/Integration/RevisionAuthor/` | |
| 2. Audit actor = session account, not entity uid | 3–4 | `./vendor/bin/phpunit packages/audit/tests/ --no-progress` | |
| 3. Publish-pointer moves audited | 5 | publish/revert kinds asserted (AuditAttributionTest S3) | |
| 4. MCP dispatch event fires | 6 | `./vendor/bin/phpunit packages/mcp/tests/ --no-progress` | |
| 5. Raw-SQL append-only guard | 7 | `./vendor/bin/phpunit packages/audit/tests/Integration/AuditImmutabilityTest.php --no-progress` | |
| 6. Pre-existing-table upgrade path | 8 | migration-shaped cases in RevisionAuthorTest + AuditAttributionTest | |
| 7. Gates + CHANGELOG check | SC-005 | individual gate commands (see T023) + `[Unreleased]` checklist | |

Pre-existing Windows-local failures (OIDC PEM, CLI snapshots, temp-dir races — see Mission 1 WP02's Triage Log) are documented against clean main, not re-litigated here; the release gate is green Linux CI at the tag commit.

## Drift Check (T022 — fill during implementation)

- Detector output for the four owned specs: ___
- Other flagged specs traceable to this mission (orchestrator action items): ___

## Completion notes template (fill in before requesting review)

- CHANGELOG diff location: under `[Unreleased]` only — confirm no version heading in the diff (`git diff CHANGELOG.md | rg -n "alpha.205"` empty).
- FR-009 retirement sentence: paste the exact added line(s) from revision-system-unified.md §6.
- The four must-cover checklists (T021 table): all ticked / exceptions noted.
- Defects found during the walkthrough referred for orchestrator action: ___

## Activity Log

- 2026-06-12T03:32:00Z – spec-kitty.tasks – created
