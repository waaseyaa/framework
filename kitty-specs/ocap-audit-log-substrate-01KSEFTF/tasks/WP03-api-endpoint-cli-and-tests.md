---
work_package_id: WP03
title: JSON:API audit endpoint, audit:prune CLI, integration tests (dead-code guard + retention), docs + CHANGELOG
dependencies:
- WP01
requirement_refs:
- FR-009
- FR-010
- FR-011
- FR-013
- FR-014
- FR-015
- NFR-002
- NFR-005
- C-002
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-ocap-audit-log-substrate-01KSEFTF
base_commit: 22d588204807ea3f92e11441086e4acccb7f78b1
created_at: '2026-05-25T05:33:30.581912+00:00'
subtasks:
- T-M
- T-N
- T-O
- T-P
shell_pid: "63322"
history: []
authoritative_surface: packages/api/src/Audit
execution_mode: code_change
owned_files:
- packages/api/src/Audit/AuditQueryReadModelInterface.php
- packages/api/src/Audit/AuditEventResource.php
- packages/api/src/Audit/AuditQueryDto.php
- packages/api/src/Audit/ApiAuditQueryAdapter.php
- packages/api/src/Controller/AuditQueryController.php
- packages/api/src/Http/Router/AuditApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/composer.json
- packages/api/tests/Unit/Controller/AuditQueryControllerTest.php
- packages/api/tests/Unit/Http/Router/AuditApiRouterTest.php
- packages/cli/src/Command/Audit/PruneCommand.php
- packages/cli/tests/Unit/Command/Audit/PruneCommandTest.php
- tests/Integration/PhaseOcapAudit/OcapAuditEndpointTest.php
- tests/Integration/PhaseOcapAudit/AuditRetentionPruneTest.php
- docs/specs/ocap-audit-log.md
- docs/specs/codified-context-integration.md
- CHANGELOG.md
tags:
- substrate
- ocap
- audit
- api
- cli
- json-api
agent: "claude"
---

# WP03 — JSON:API audit endpoint + `audit:prune` CLI + integration tests + docs

**Mission:** `ocap-audit-log-substrate-01KSEFTF`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01. May proceed in parallel with WP02.

## Pattern references — READ FIRST

- `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + `AiObservabilityController.php` + the `ObservabilityServiceProvider::register()` binding + the `ApiServiceProvider::httpDomainRouters()` `resolveOptional` block — M5A's shipped exemplar, controller signature pattern, JSON:API response shape.
- `packages/api/src/Http/Router/WorkflowGuardsApiRouter.php` + `AiObservabilityApiRouter.php` — router shape.
- `packages/cli/src/Command/` — sibling command structure; specifically a sibling using `CommandTester`-friendly construction.
- `tests/Integration/PhaseAiObservability/AiObservabilityDashboardEndpointTest.php` — kernel-boot integration test pattern.
- `CLAUDE.md` §"Adding an API endpoint" + §HTTP / auth — request attribute `_account`, route options for access control.

## The cross-layer wiring decision

`packages/audit` is L0; `packages/api` is L4. The layer rule says lower may not import higher — so `packages/audit/src/Adapter/ApiAuditQueryAdapter.php` cannot import `Waaseyaa\Api\Audit\AuditQueryReadModelInterface`.

**Resolution (per spec.md §Decisions deferred + plan.md §T-E note):** the adapter ships INSIDE `packages/api/src/Audit/ApiAuditQueryAdapter.php`. It depends on `Waaseyaa\Audit\Contract\AuditQueryInterface` (api → audit = downward import = allowed). Translation between `AuditEvent` entities and `AuditEventResource` DTOs happens in the adapter. The `AuditServiceProvider` binding for `AuditQueryReadModelInterface` (deferred from WP01 T-E) is added here in this WP by adding a registration step in `ApiServiceProvider::register()` — `$this->singleton(AuditQueryReadModelInterface::class, fn($c) => new ApiAuditQueryAdapter($c->get(AuditQueryInterface::class)))`. This preserves the M5A pattern (the adapter binds in the side that owns the higher-layer interface) while honouring the layer rule.

## Subtasks

### T-M — JSON:API endpoint

1. `packages/api/src/Audit/AuditQueryReadModelInterface.php` — `findBy(AuditQueryDto $query): iterable<AuditEventResource>`; `count(AuditQueryDto $query): int`. Class-level `@api`. No `use Waaseyaa\Audit\*` (api-local DTOs only).
2. `packages/api/src/Audit/AuditEventResource.php` — readonly DTO mirroring the audit-event schema (camelCase): `id`, `uuid`, `eventKind`, `accountUid`, `entityType`, `entityUuid`, `subjectUri`, `outcome`, `severity`, `attributes`, `createdAt`. `@api`.
3. `packages/api/src/Audit/AuditQueryDto.php` — readonly query value (mirrors the audit-side `AuditQuery` but lives api-local). `@api`.
4. `packages/api/src/Audit/ApiAuditQueryAdapter.php implements AuditQueryReadModelInterface`. Constructor `(Waaseyaa\Audit\Contract\AuditQueryInterface $auditQuery)`. `findBy()` maps `AuditQueryDto` → `Waaseyaa\Audit\Contract\AuditQuery` (validates `kinds` strings against the enum), calls underlying `findBy`, yields each `AuditEvent` mapped to an `AuditEventResource`. `count()` similar.
5. `packages/api/src/Controller/AuditQueryController.php` — `__construct(private readonly ?AuditQueryReadModelInterface $readModel = null)`. `index(Request $request): array`:
   - Parse `page[limit]` (default 50, max 500), `page[offset]` (default 0), `filter[account]` (int), `filter[entity]` (string `type:uuid`), `filter[kind]` (comma-list → array), `filter[from]` / `filter[to]` (ISO-8601 → `\DateTimeImmutable`).
   - Build `AuditQueryDto`. Call `findBy()` + `count()`. Return `{data: [...resources], meta: {total, limit, offset}}` JSON:API.
   - Null read model → `{data: [], meta: {total: 0, limit: 50, offset: 0}}`. Does NOT re-check role.
6. `packages/api/src/Http/Router/AuditApiRouter.php` — mirror `AiObservabilityApiRouter`: `supports()` matches `'AuditQueryController::'`; dispatch `index`; JSON:API error envelope on unknown action.
7. `packages/api/src/ApiServiceProvider.php`:
   - `use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;`
   - In `register()`: bind `AuditQueryReadModelInterface` → factory creating `ApiAuditQueryAdapter` from `AuditQueryInterface`. This is the single place where audit's L0 contract is composed into api's L4 interface.
   - In `httpDomainRouters()`: `$rm = $this->resolveOptional(AuditQueryReadModelInterface::class); if ($rm instanceof AuditQueryReadModelInterface) { $routers[] = new AuditApiRouter(new AuditQueryController($rm)); }`.
8. `packages/api/composer.json` — add `"waaseyaa/audit": "<exact current tag>"` to **require-dev** (NOT `require` — C-002 / NFR-002) + `{"type": "path", "url": "../audit"}` repo entry. `composer update --lock waaseyaa/audit`.
9. Tests:
   - `packages/api/tests/Unit/Controller/AuditQueryControllerTest.php` — fake read model returning canonical resources; assert mapped JSON:API payload. Null read model → empty shape.
   - `packages/api/tests/Unit/Http/Router/AuditApiRouterTest.php` — `supports()` matrix; dispatch `index`; unknown action → 404 JSON:API error.

### T-N — `audit:prune` CLI command

1. `packages/cli/src/Command/Audit/PruneCommand.php extends \Symfony\Component\Console\Command\Command`. Signature `audit:prune --older-than=<duration> [--kind=<glob>] [--dry-run]`. Constructor `(AuditQueryInterface $query, AuditWriterInterface $writer, DatabaseInterface $db, ?LoggerInterface $logger = null)`.
2. Validate `--older-than` via `new \DateInterval($input)`; throw on invalid.
3. Compute cutoff `\DateTimeImmutable::now()->sub($interval)`. Build `AuditQuery` with `to = $cutoff` and (if `--kind`) `kinds = [...]` (resolve glob: `*` → all enum cases; `entity.*` → all cases starting with `entity.`; literal → single).
4. If `--dry-run`: `$count = $query->count($auditQuery); echo "Would prune {$count} events"`; exit 0.
5. Execute: compute `$deletedCount = $query->count($auditQuery)` BEFORE delete; write one self-audit event via `$writer->record(new AuditEventDescriptor(eventKind: AuditEventKind::AuditRetentionPruned, ..., attributes: ['kind_pattern' => $kind, 'older_than' => $input, 'deleted_count' => $deletedCount]))`; then `$db->delete('audit_event')->condition('created_at', $cutoff->format(\DateTimeInterface::ATOM), '<')->condition(...kind...)->execute()`. Print `Pruned {$deletedCount} events`; exit 0.
6. Wire into CLI via package's command-discovery surface (verify pattern in `packages/cli/src/CliKernel.php` / `CommandDefinition.php`).
7. Test `packages/cli/tests/Unit/Command/Audit/PruneCommandTest.php` using `CommandTester` (per CLAUDE.md §Testing).

### T-O — Integration tests

1. `tests/Integration/PhaseOcapAudit/OcapAuditEndpointTest.php` `#[CoversNothing]` — mirror `AiObservabilityDashboardEndpointTest`:
   - Boot full kernel.
   - Seed via the `audit_event` repository: 6+ events across 4+ kinds (`entity.read`, `entity.write`, `access.denied`, `agent.tool_execute`) and 2+ accounts (uid 1 admin + uid 2 author).
   - `GET /api/audit/events?filter[kind]=entity.read&page[limit]=10` as **admin** → assert response shape `{data, meta: {total, limit, offset}}`, only `entity.read` events returned, ordering `created_at DESC`.
   - `GET /api/audit/events` as a **non-admin** account → assert 403.
   - **FR-013 dead-code guard mechanism:** add a code comment block at the top of the test explaining that removing the `AuditQueryReadModelInterface` binding in `ApiServiceProvider::register()` MUST cause this test to fail (the controller's read model becomes null → empty payload → assertion fails). Reviewer verifies this by-hand.
2. `tests/Integration/PhaseOcapAudit/AuditRetentionPruneTest.php` `#[CoversNothing]`:
   - Boot kernel.
   - Seed 10 events with mixed `created_at`: 6 older than 1 hour, 4 newer.
   - Run `audit:prune --older-than=PT1H` via `CommandTester`.
   - Assert: 6 rows deleted from `audit_event` (now 4 remain + 1 new `audit.retention_pruned` self-audit event = 5).
   - Assert: exactly one `audit.retention_pruned` event exists with `attributes.deleted_count = 6`.

### T-P — Docs + CHANGELOG

1. `docs/specs/ocap-audit-log.md` — new spec file. Sections: Overview, Why, Architecture (cross-layer L0↔L4 pattern), Schema, Event-kind taxonomy (the 14 cases + extension policy), Listener catalogue (the 5 listeners + their subscribed events + their best-effort guarantee), Query API (interface + JSON:API endpoint shape), Retention (policy entity + CLI + self-audit semantics), Performance budget (NFR-005), Implementation notes, Cross-references. ~250–400 lines.
2. `docs/specs/codified-context-integration.md` — append a brief cross-reference paragraph noting that the audit substrate uses the same L0↔L4 read-contract pattern documented here.
3. `CHANGELOG.md` `[Unreleased]` → **Added**: `OCAP audit log substrate (packages/audit, renamed from analytics): append-only unified event table spanning entity / API / agent / MCP / broadcast events, JSON:API query endpoint at /api/audit/events (admin), audit:prune CLI command, 5 cross-cutting listeners with best-effort write semantics, AuditRetentionPolicy entity. Closes gap-matrix A3 / alpha-to-beta-plan §1 item #2. (ocap-audit-log-substrate-01KSEFTF)`.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/api/tests/Unit/Audit/ packages/api/tests/Unit/Controller/AuditQueryControllerTest.php packages/api/tests/Unit/Http/Router/AuditApiRouterTest.php packages/cli/tests/Unit/Command/Audit/ tests/Integration/PhaseOcapAudit/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. `rg -nE 'use Waaseyaa\\\\Audit' packages/api/src/` → empty (the `ApiAuditQueryAdapter` imports `Waaseyaa\Audit\Contract\AuditQueryInterface` — but that's the api → audit direction = downward = allowed; verify the grep is scoped correctly).
6. **Dead-code guard verification:** comment out the `singleton(AuditQueryReadModelInterface::class, ...)` line in `ApiServiceProvider::register()`. Re-run `OcapAuditEndpointTest`. Confirm it FAILS. Restore the binding. Document this in the WP report.
7. Performance smoke: seed 1000 synthetic events, run `GET /api/audit/events?filter[account]=2&page[limit]=50` 5 times; measure with `microtime(true)`; assert < 100ms (NFR-005).

## Commit + handoff

- `feat(api): AuditQueryReadModelInterface + AuditEventResource + AuditQueryDto + ApiAuditQueryAdapter`
- `feat(api): AuditQueryController + AuditApiRouter + ApiServiceProvider wiring`
- `feat(cli): audit:prune command with --older-than / --kind / --dry-run + self-audit on execution`
- `test(integration): OCAP audit endpoint dead-code guard + retention prune`
- `docs(specs): ocap-audit-log.md + codified-context-integration.md cross-ref + CHANGELOG`

```
spec-kitty agent tasks mark-status T-M T-N T-O T-P --status done --mission ocap-audit-log-substrate-01KSEFTF
spec-kitty agent tasks move-task WP03 --to for_review --mission ocap-audit-log-substrate-01KSEFTF --note "Endpoint + CLI + integration tests passing; dead-code guard verified"
```

## Report back with

1. Commit SHAs.
2. The JSON:API payload from a seeded `GET /api/audit/events` request (paste sample).
3. The failing assertion from the dead-code guard verification (the test message when the binding is removed).
4. The deleted_count + remaining-row count from `AuditRetentionPruneTest` (must match: 6 deleted, 5 remain).
5. NFR-005 perf-smoke timing for 1000-event seed (must be < 100ms).
6. `rg -nE 'use Waaseyaa\\Audit\\\\(Contract\|Adapter\|Entity\|Enum\|Writer\|Query\|Storage\|Listener\|Schema)' packages/api/src/` — list every match; only the adapter under `packages/api/src/Audit/ApiAuditQueryAdapter.php` should appear, importing `Waaseyaa\Audit\Contract\AuditQueryInterface` only (audit-side contract, api-side adapter).

## Activity Log
- 2026-05-25T05:33:32Z – claude – shell_pid=63322 – Assigned agent via action command
- 2026-05-25T05:52:58Z – claude – shell_pid=63322 – Endpoint + CLI + integration tests complete; all gates pass (phpstan, check-package-layers, check-dead-code, check-getquery-bindings, check-composer-policy, cs-check). Commit d60076d66.
- 2026-05-25T05:53:39Z – claude – shell_pid=63322 – Opus review: lane-a disciplined; single comprehensive commit; API + CLI + tests + 3-spec stamp; gates all clean; OCAP audit substrate now fully operational
- 2026-05-26T18:47:43Z – claude – shell_pid=63322 – Done override: Sprint merge to main
