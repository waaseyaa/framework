---
work_package_id: WP02
title: Revision Author (entity-storage)
dependencies:
- WP01
requirement_refs:
- C-001
- FR-001
- FR-002
- FR-003
- FR-006
- FR-009
- NFR-001
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T005
- T006
- T007
- T008
- T009
- T010
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "9324"
history:
- date: '2026-06-12T03:32:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/entity-storage/src/
execution_mode: code_change
owned_files:
- packages/entity-storage/src/SaveContext.php
- packages/entity-storage/src/EntityRepository.php
- packages/entity-storage/src/Event/RevisionPointerMovedEvent.php
- packages/entity-storage/src/Driver/RevisionableStorageDriver.php
- packages/entity-storage/src/SqlSchemaHandler.php
- packages/entity-storage/tests/**
- packages/entity/src/RevisionMetadata.php
- tests/Integration/Provenance/**
tags: []
---

# WP02 — Revision Author (entity-storage)

**Mission**: revision-audit-provenance-01KTWY5V | **Tracks**: #1644
**Requirements**: FR-001, FR-002, FR-003, FR-006 (event half), FR-009 (docblock half), NFR-001, C-001 | **Dependencies**: WP01
**Command**: `spec-kitty agent action implement WP02 --agent <name>`

## Objective

Make every revision created through the standard save path record the acting account, and make that author readable back: a nullable `revision_author` column on both live revision tables (new tables AND pre-existing ones — the additive sync arm does not exist today and must be built), actor threading through all seven `writeRevision()` callsites, the first-ever production hydration of `RevisionMetadata`, and the typed `RevisionPointerMovedEvent` that another WP's audit listener consumes.

The authoritative behavior spec is `kitty-specs/revision-audit-provenance-01KTWY5V/contracts/revision-author.md` — implement to its 16 clauses.

## Context (read first)

- `research.md` "Verified ground truth" + D2/D4; `data-model.md` (resolution order, metadata shape, pointer event payload); `contracts/revision-author.md` (clauses cited below).
- **Falsification #1 — the most dangerous assumption in this mission**: revision tables have NO additive column sync today. `ensureRevisionTable()` (`packages/entity-storage/src/SqlSchemaHandler.php:248-259`) and `ensureTranslationRevisionTable()` (`:289-302`) early-return when the table exists. The pattern to mirror is `ensureBundleSubtable()` (`:155-163`): table exists → `fieldExists` → `addField`. Without this arm, every existing install silently misses the column and `foldData()` (`:577-613`) folds the author into the `_data` blob — readable but off-contract (clause 14 pins this).
- Live write path: `RevisionableStorageDriver::writeRevision(string $entityId, array $values, ?string $log, ?string $langcode = null): int` (`packages/entity-storage/src/Driver/RevisionableStorageDriver.php:74-81`); single-axis row assembly in private `writeDefaultRevision()` (`:301-330`); per-language in `writePerLangcodeRevision()` (`:345-387`). Columns today: `entity_id`, `revision_id`, `revision_created`, `revision_log` (+ folded fields).
- All seven `writeRevision()` callers live in `EntityRepository` (`packages/entity-storage/src/EntityRepository.php`): `doSave()` immediate (`:435`) and deferred-id (`:480`), `rollback()` (`:653`), `saveTranslationRevision()` (`:870`), `saveTranslationRevisions()` (`:898`), `saveTranslation()` (`:1009`), `backfillInitialRevisions()` (`:1299`). Line numbers verified at alpha.203; re-locate with `rg -n "writeRevision\(" packages/entity-storage/src/EntityRepository.php`.
- `SaveContext` (`packages/entity-storage/src/SaveContext.php`) — final immutable value object, private constructor, designed for additive flags ("Future flags … extend this object without changing call sites").
- Pointer moves: `setPublishedRevision()` (`:795-848`, legacy `REVISION_REVERTED` dispatch at `:842-845`), `setCurrentRevision()` (`:752-755`). `rollback()` is NOT a pointer-only move — it creates a revision and gets NO pointer event (D4, contract clause 15).
- Readback: `RevisionMetadata` (`packages/entity/src/RevisionMetadata.php`) already has the right shape `(\DateTimeImmutable $revisionCreatedAt, ?int $revisionAuthor = null, ?string $revisionLog = null)`; its docblock describes the dormant M-004 dialect (`<entity>__revision`, `revision_created_at`) — wrong, fix it (D7). `RevisionableEntityTrait::setRevisionMetadata()` has ZERO production callers; `EntityRepository::loadRevision()` (`:592-630`) hydrates `revisionId`/`isCurrentRevision` via `method_exists` (`:620-627`) but never builds metadata.
- **WP01 hand-off**: the kernel repository factory already calls `$repository->setAccountContext($accountContext)` behind a `method_exists` guard (find the `revision-audit-provenance-01KTWY5V WP01: forward seam` comment in `AbstractKernel`). This WP adds the receiver — do NOT edit `AbstractKernel` (WP01's file); adding the public method activates the seam automatically.
- Column name/semantics are adopted verbatim from the dormant dialect (`RevisionTableBuilder.php:284-289`): `revision_author`, nullable int, soft FK (no constraint — history survives user deletion). This is FR-009's "single authoritative author definition" converging (D7).

## Requirement / contract map

| Deliverable | Requirement | Contract clause(s) |
|---|---|---|
| Actor resolution + threading to all 7 callsites | FR-001, FR-002 | revision-author.md 1–5 |
| `SaveContext::withActorUid()` override | FR-002 | 5 |
| `updateRevision()` immutability | C-001 spirit | 6 |
| Metadata hydration + readback | FR-001 | 7–10 |
| Column in both new-table specs | FR-003 | 11 |
| Additive ADD-COLUMN arm | FR-003, SC-004 | 12–14 |
| Dialect convergence (column def + docblock) | FR-009 (this WP's half) | 15 |
| Perf smoke | NFR-001 | 16 |
| `RevisionPointerMovedEvent` dispatches | FR-006 (event half; the audit listener is another WP's) | audit-attribution.md 12, 14–15 |

## Out of scope for this WP (do not touch)

- `packages/foundation/src/Kernel/AbstractKernel.php` — the seam comment and attach call are already there from the context WP; your receiver activates them.
- `packages/audit/**` — the publish listener and audit rows.
- `packages/access/**`, `packages/user/**` — the holder and its HTTP writer.
- `packages/entity-storage/src/Schema/RevisionTableBuilder.php`, `Schema/TranslationSchemaHandler.php` — the dormant dialect is retired in DOCS (D7), not deleted or modified here. Resist "cleaning it up".
- Audit/docs/CHANGELOG — later WPs.

## Subtasks

### T005 — SaveContext override surface

**File**: `packages/entity-storage/src/SaveContext.php`; tests in `packages/entity-storage/tests/Unit/SaveContextTest.php` (extend; locate with `rg -l "SaveContext" packages/entity-storage/tests/Unit/`)

1. Two new private readonly members per data-model.md: `?int $actorUid` (default null) and `bool $actorOverridden` (default false), threaded through the private constructor and EVERY existing `with*()` builder (`withoutNewRevision`, `langcode`, `isImport`, `translations` — each re-construction must carry the new members or an override silently drops on chained builders).
2. New builder + accessors:
   ```php
   public function withActorUid(?int $uid): self   // sets actorOverridden = true, even for null
   public function actorUid(): ?int
   public function actorOverridden(): bool
   ```
   Docblock: explicit `withActorUid(null)` forces a NULL author inside an authenticated request (system-attributed maintenance writes — contract clause 5); `actorUid` is meaningless unless `actorOverridden`.
3. `SaveContext::default()` yields the not-overridden state (pre-mission behavior, byte-identical).
4. Unit tests: default state not-overridden; `withActorUid(7)` → overridden, uid 7; `withActorUid(null)` → overridden, uid null (the three-state pin); chaining `->withActorUid(7)->withoutNewRevision()` preserves the override (and the reverse order too); existing flags unaffected.

### T006 — EntityRepository: resolution, threading, pointer event

**Files**: `packages/entity-storage/src/EntityRepository.php`, `packages/entity-storage/src/Event/RevisionPointerMovedEvent.php` (NEW)

1. **Receiver (activates WP01's seam)**:
   ```php
   private ?AccountContextInterface $accountContext = null;

   public function setAccountContext(?AccountContextInterface $accountContext): void
   ```
   Import `Waaseyaa\Access\Context\AccountContextInterface` — entity-storage already requires `waaseyaa/access` (no composer change). Docblock: set by the kernel repository factory; direct constructions (tests, consumers) may call it or rely on the null default (= no ambient context).
2. **Resolution — once per operation** (contract clause 2, data-model.md):
   ```php
   private function resolveActor(?SaveContext $context): ?int
   {
       if ($context !== null && $context->actorOverridden()) {
           return $context->actorUid();
       }
       return $this->accountContext?->current()?->id();   // cast to ?int if id() is not int-typed
   }
   ```
   Check `AccountInterface::id()`'s return type and cast explicitly if needed. Never coerce null to 0 (clause 3).
3. **Thread to all seven callsites** — resolve ONCE per operation, pass as the new trailing `$author` argument (T007). No revision-creation path may bypass resolution (clause 1). Checklist (line numbers at alpha.203 — re-locate before editing):

   | # | Callsite | Where resolved | Expected actor |
   |---|---|---|---|
   | 1 | `doSave()` immediate write (`:435`) | top of `doSave()`, from the passed `$context` | override → context → null |
   | 2 | `doSave()` deferred-id write (`:480`) | same single resolution as #1 | identical to #1 |
   | 3 | `rollback()` (`:653`) | top of `rollback()` (no SaveContext today — context-only resolution unless you thread an optional one; do NOT change its public signature beyond optional additions) | the reverter (clause 4) |
   | 4 | `saveTranslationRevision()` (`:870`) | per operation entry | override → context → null |
   | 5 | `saveTranslationRevisions()` (`:898`) | once, not per langcode | same value for every language row |
   | 6 | `saveTranslation()` (`:1009`) | per operation entry | override → context → null |
   | 7 | `backfillInitialRevisions()` (`:1299`) | per invocation | null in CLI (no special-casing) |
4. **`RevisionPointerMovedEvent`** (NEW, namespace `Waaseyaa\EntityStorage\Event`, sibling of `BeforeSaveEvent` — mirror its style; extends the same Symfony contracts `Event` base if `BeforeSaveEvent` does):
   public readonly `string $entityTypeId`, `string|int $entityId` (match the repository's id handling), `string $operation` (`'publish'`|`'revert'`), `?int $fromRevisionId`, `int $toRevisionId`. Dispatched by FQCN.
5. **Dispatch sites** (D4, contract clauses 12–14):
   - `setPublishedRevision()`: operation `'publish'`; `from` = prior `published_revision_id` read from the base row inside the existing transaction (null when previously unpublished). Dispatch AFTER the transaction commits successfully, alongside — not replacing — the legacy `REVISION_REVERTED` dispatch.
   - `setCurrentRevision()`: operation `'revert'`; `from` = prior base `revision_id` pointer; same after-commit + legacy-preserved rules.
   - `rollback()`: NO pointer event (it creates a revision; flows through `REVISION_CREATED`).
   - Wrap dispatch best-effort only if the legacy dispatches are; otherwise match the surrounding error posture exactly.

### T007 — RevisionableStorageDriver: author parameter + column write

**File**: `packages/entity-storage/src/Driver/RevisionableStorageDriver.php`

1. `writeRevision()` gains a trailing `?int $author = null` (after `$langcode`; existing callers compile unchanged).
2. Thread into both private row assemblies — `writeDefaultRevision()` and `writePerLangcodeRevision()` — adding `'revision_author' => $author` alongside `revision_created`/`revision_log`. SQL NULL when `$author === null` (verify the insert builder writes real NULL, not 0 or '').
3. `updateRevision()` (the `withoutNewRevision` in-place path): `revision_author` joins the existing immutable-metadata exclusion alongside `revision_created`/`revision_log` — in-place updates never touch it (contract clause 6).
4. Driver unit tests (extend the existing driver test class): author written on the single-axis path; author written on the per-langcode path; null author → SQL NULL readback; `updateRevision()` leaves a previously-written author untouched.

### T008 — SqlSchemaHandler: column spec + the additive arm

**File**: `packages/entity-storage/src/SqlSchemaHandler.php`

1. **New-table specs**: add `revision_author` — int, `not null => false`, NO default, NO FK constraint — to `buildRevisionTableSpec()` (`:641-717`) and `buildTranslationRevisionTableSpec()` (`:731-751`). Match the exact field-spec array shape of neighboring nullable columns.
2. **Additive arm** (FR-003, contract clause 12 — THE critical piece): rework `ensureRevisionTable()` and `ensureTranslationRevisionTable()` so the table-exists path, instead of pure early-return, probes `fieldExists($table, 'revision_author')` and calls `addField()` when missing. Mirror `ensureBundleSubtable()` (`:155-163`) for the probe/add idiom and the schema-API calls. Idempotent: second run is a no-op; no other column touched; no row rewritten (C-001).
3. This single change covers both production callsites — the kernel repository factory and `EntitySchemaSync` (`packages/entity-storage/src/EntitySchemaSync.php:109`, `:113`) — both go through `SqlSchemaHandler`. Verify by reading both callers; do not edit them.
4. Schema unit tests (extend the existing spec tests under `packages/entity-storage/tests/Unit/` — locate with `rg -l "buildRevisionTableSpec|ensureRevisionTable" packages/entity-storage/tests/`): column present in both specs; additive arm adds it to a pre-existing table; idempotency (run twice, one column); the translation-revision sibling gets the same treatment.

### T009 — Metadata hydration + docblock fix

**Files**: `packages/entity-storage/src/EntityRepository.php`, `packages/entity/src/RevisionMetadata.php`

1. In `loadRevision()` (and the translation-revision load paths — trace from `listTranslationRevisions()`/translation loads to wherever revision rows hydrate entities), construct and attach metadata for entities implementing `RevisionableEntityInterface`:
   ```php
   $entity->setRevisionMetadata(new RevisionMetadata(
       revisionCreatedAt: new \DateTimeImmutable((string) $row['revision_created']),
       revisionAuthor: isset($row['revision_author']) ? (int) $row['revision_author'] : null,
       revisionLog: ($row['revision_log'] ?? null) !== null ? (string) $row['revision_log'] : null,
   ));
   ```
   Guard with `instanceof RevisionableEntityInterface` (matching the existing `setRevisionId` pattern, clause 10). SQL NULL author → `null`, including every pre-mission row (clause 9). `0` round-trips as `0` (anonymous — clause 8). `listRevisions()` inherits via `loadRevision()` — verify, don't duplicate.
2. This is the FIRST production caller of `setRevisionMetadata()` — confirm with `rg -n "setRevisionMetadata" packages/` before and after.
3. `RevisionMetadata` docblock (D7/FR-009 half): replace the dormant-dialect claims (`<entity>__revision`, `revision_created_at`) with the live ones — stored in `<entity>_revision` / `<entity>__translation__revision` as `revision_created`, `revision_author`, `revision_log`. Docblock-only change; constructor shape untouched.

### T010 — Integration tests

**Files**: `packages/entity-storage/tests/Integration/RevisionAuthor/RevisionAuthorTest.php` (NEW), `tests/Integration/Provenance/KernelRevisionAuthorTest.php` (NEW, `#[CoversNothing]`, namespace `Waaseyaa\Tests\Integration\Provenance`)

`RevisionAuthorTest` (direct repository over `DBALDatabase::createSqlite()`, mirroring existing entity-storage integration tests):

1. **Record/readback matrix**: context account N → author N; anonymous (account id 0) → author 0; no context → author null; each read back via `loadRevision(...)->revisionMetadata()->revisionAuthor` (clauses 3, 8).
2. **Override precedence**: ambient account N + `SaveContext::withActorUid(99)` → 99; ambient N + `withActorUid(null)` → null (clause 5); no override → ambient wins.
3. **Revert authorship**: revision created by A, `rollback()` performed with B in context → the NEW revision's author is B; the target revision row untouched (clause 4).
4. **Pre-existing-table additive migration** (SC-004, clauses 12–13): hand-create a pre-mission-shaped revision table (no `revision_author`) with rows, run `ensureRevisionTable()` via the schema handler, assert: column physically present, old rows read back null author, a new save records an author.
5. **Physical-column pin** (clause 14): after sync, assert `revision_author` is a real column (schema introspection) AND the value is NOT inside the `_data` blob.
6. **Pointer events**: `setPublishedRevision()` dispatches `RevisionPointerMovedEvent(operation 'publish', correct from→to)`; `setCurrentRevision()` dispatches `'revert'`; legacy `REVISION_REVERTED` still observed; `rollback()` dispatches no pointer event.
7. **Translation paths**: a translation revision records the author in `<entity>__translation__revision`.

`KernelRevisionAuthorTest` (SC-001 + NFR-001):

8. Boot a real kernel (mirror `tests/Integration/Validation/KernelValidationWiringTest.php` bootstrap), set an account on the kernel's `accountContext()`, save a revisionable entity through a kernel-built repository, read the author back via `revisionMetadata()`. This proves WP01's `method_exists` seam went live with this WP's receiver.
9. Perf smoke (NFR-001): 200 saves with an account in context vs 200 without, median-over-median ratio ≤ 1.05; follow the retry-once jitter-guard pattern from `tests/Integration/Validation/ValidationOverheadTest.php`. If flaky in CI, loosen with a comment linking NFR-001 — do not delete.

**Validation**:

```bash
./vendor/bin/phpunit packages/entity-storage/tests/ --no-progress
./vendor/bin/phpunit tests/Integration/Provenance/ --no-progress
composer phpstan
composer cs-check
bin/check-getquery-bindings   # no new unbound chains
bin/check-dead-code
```

## Edge cases & risks (from the plan premortem + spec edge cases)

- **The sync-arm surprise is already de-risked but still the diff's center of gravity**: the spec originally assumed additive sync existed for revision tables; research falsified that (`ensureRevisionTable` early-returns). The new arm runs at kernel boot / `db:init` — one `fieldExists` probe per boot per revisionable type, never per save. If you find yourself adding probes to the save path, stop and re-read D2.
- **`foldData()` shadow failure mode**: if the column is in the spec but the sync arm regresses (or a test database predates it), `foldData()` silently folds `revision_author` into `_data` — every readback test still passes because hydration could read the blob. That is why clause 14's physical-column pin exists; treat it as load-bearing, not paranoia.
- **Queue/job writes** (spec edge case): jobs run without a session → resolution yields null unless the job carries an explicit `withActorUid()` override or an upstream executor scoped the context. Nothing in this WP needs queue awareness — document the behavior in `setAccountContext()`'s docblock and move on.
- **Existing consumer attribution fields** (spec edge case): apps with `editor_uid`-style snapshot fields keep working — framework attribution is purely additive alongside them. No test needed; just do not touch field handling.
- **`backfillInitialRevisions()`**: passes the same resolution; a CLI backfill with no context records null — correct, and deliberately NOT an override site (D2). Do not special-case it.
- **Deferred-id saves**: the `:480` callsite exists because new entities get their id after the base insert; the actor resolved at the top of `doSave()` must reach BOTH the immediate and deferred writes — resolve once, pass twice.
- **Two-axis types**: the translation-revision table gets the same additive column (DIR-005 respected per the Charter Check); the per-langcode write path is a separate row assembly — T007 step 2 covers it, T010 case 7 proves it.
- **Pointer-event transactionality** (audit-attribution.md clause 14): the event fires only after the pointer transaction commits — a rolled-back move must produce no event (and therefore no audit row downstream). Check where the existing legacy dispatch sits relative to the transaction and match-or-correct: if the legacy dispatch fires pre-commit today, dispatch the NEW event post-commit anyway and note the divergence in completion notes.
- **NFR-001 envelope**: actor resolution is one in-memory holder read + optional `id()` call; the INSERT grows by one column. If the perf smoke exceeds 1.05x, look for accidental per-row resolution (resolving inside a loop over translations, for example) before loosening the bound.

## Definition of Done

- [ ] All six subtasks complete; `packages/entity-storage/tests/` + `tests/Integration/Provenance/` green; existing entity-storage suite green (revision pruning, two-axis, translation tests unmodified unless an assertion encoded the no-author state — explain any such change in completion notes).
- [ ] All seven `writeRevision()` callsites pass the resolved actor; `rg -n "writeRevision\(" packages/entity-storage/src/EntityRepository.php` shows no unthreaded site.
- [ ] Pre-existing-table migration test green: additive arm, idempotent, old rows null (SC-004).
- [ ] Kernel-booted readback green (SC-001) — the WP01 seam is live.
- [ ] NFR-001 perf smoke green with the bound recorded in completion notes.
- [ ] `composer phpstan`, `composer cs-check`, dead-code and getQuery gates clean; no changes outside `owned_files` (in particular: NOT `AbstractKernel.php`, NOT `packages/audit/**`).

## Reviewer guidance

- **The additive migration on a pre-existing table is the highest-value assertion** (T010 case 4): verify the test creates the OLD shape table first (no `revision_author`), runs the production sync path (not a hand-rolled ALTER), and asserts both the physical column and null readback for old rows. Without it, the mission ships a feature only fresh installs get.
- Check the physical-column pin (case 5) really inspects schema + `_data` — a regression in the sync arm would make `foldData()` silently absorb the author and the readback tests would STILL pass.
- Hunt for null→0 coercion: any `(int)` cast on a possibly-null actor, any `?? 0`, any NOT NULL/default on the column spec is a contract violation (clauses 3, 11).
- Revert authorship (case 3) must assert the actor at revert time AND the original row's untouched author — both halves.
- `withActorUid(null)` ≠ "not overridden": confirm the flag pattern survived every `with*()` builder (chain-order tests).
- Pointer events: after-commit dispatch (no row for rolled-back moves), legacy `REVISION_REVERTED` preserved, `rollback()` excluded.

## Completion notes template (fill in before requesting review)

- Seven-callsite verification: paste the `rg -n "writeRevision\(" packages/entity-storage/src/EntityRepository.php` output with each site annotated resolved-actor-source.
- NFR-001 measured ratio: ___ (bound asserted: ___).
- Existing tests modified (if any): file + assertion + why it encoded the pre-author state.
- Pointer-event dispatch position relative to the transaction: ___ (and whether the legacy dispatch position diverges).
- PHPStan note from the WP01 seam (if the `method_exists` guard needed an ignore on the kernel side, confirm it now resolves cleanly with the receiver present — if the kernel used an ignore comment, flag it for removal in completion notes; the file is not yours to edit).

## Activity Log

- 2026-06-12T03:32:00Z – spec-kitty.tasks – created
- 2026-06-12T04:35:10Z – claude:fable-5:implementer:implementer – shell_pid=10660 – Started implementation via action command
- 2026-06-12T04:55:24Z – claude:fable-5:implementer:implementer – shell_pid=10660 – Ready for review: revision_author on both revision tables (new specs + additive sync arm), actor threaded to all 7 writeRevision callsites, setAccountContext receiver activates WP01 seam, RevisionMetadata hydration + docblock fix, RevisionPointerMovedEvent on publish/revert. Gates green; NFR-001 measured 1.004x (bound 1.05x).
- 2026-06-12T04:56:03Z – claude:fable-5:reviewer:reviewer – shell_pid=9324 – Started review via action command
