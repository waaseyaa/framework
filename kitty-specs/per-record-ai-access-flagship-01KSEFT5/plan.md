# Implementation Plan: Per-Record AI Access Flagship (M-A5)

**Mission:** `per-record-ai-access-flagship-01KSEFT5` — see `spec.md`.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` (spec/plan/tasks/wps.yaml shape); CodifiedContext three-tier pattern (`docs/specs/codified-context-integration.md`) for any cross-layer adapter binding; charter directive **DIR-004** (OCAP-by-architecture) operational embodiment + **DIR-006** (codified gates) for verification discipline.
**Three WPs, all parallel (no dependency edges):** WP01 (tool boundary), WP02 (MCP serializer), WP03 (per-file toggle). Each ships its own dead-code-in-production guard test so the wiring is provably real per-WP, not just in aggregate.

---

## WP01 — AI tool boundary: AccessChecker injection + governed-data marker (M-A5)

**Layers:** L5 (`ai-tools`, `ai-agent`) + L1 (`access`).
**Owns:** the `AgentToolInterface::execute()` signature change, the `#[Capability(governed_data: false)]` marker, every shipped tool's compliance with the new contract, and the boundary integration test.

### ai-tools (L5) — interface + registry + tool updates
- `src/AgentToolInterface.php` — update `execute()` signature per **D-D1**. Preferred shape (option (b)): introduce `src/AgentToolContext.php` (readonly DTO carrying `AccountInterface $account`, `AccessCheckerInterface $accessChecker`, `?string $agentRunId`) and change `execute(array $arguments, AgentToolContext $context): AgentToolResult`. Alternative shape (option (a)): keep `array $arguments, AccountInterface $account` and add `AccessCheckerInterface $accessChecker` as a third parameter. Implementer documents the choice in the WP01 report. `@api`.
- `src/AgentToolContext.php` (if option (b) is chosen) — readonly value type with named-arg constructor.
- `src/AbstractAgentTool.php` — update `dryRun()` + `argumentsForAudit()` to match the new signature; keep redaction list intact.
- `src/Attribute/Capability.php` — new attribute `#[Capability(governed_data: bool)]` with `governed_data` default `true`. PHP attribute (`#[\Attribute(\Attribute::TARGET_CLASS)]`) + class-level `@api`. Alternatively, add `governedData: bool = true` parameter to the existing `#[AsAgentTool]` attribute (`src/Attribute/AsAgentTool.php`) — implementer documents the choice.
- `src/Catalogue/AttributeToolRegistry.php` — read the new marker per-tool, surface the boolean as `ToolDescriptor::touchesGovernedData()`.
- `src/Entity/{EntityCreateTool,EntityReadTool,EntityUpdateTool,EntitySearchTool,EntityDeleteTool,EntityListTool}.php` — each `execute()` consumes the new `AccessChecker`. For per-record reads: call `EntityAccessHandler::access($entity, 'view', $context->account)` and skip / mark `accessDenied` per FR-002. For field-shaping: apply `filterFields()` consistent with JSON:API. Mutations (`Create`/`Update`/`Delete`) check `'create'`/`'update'`/`'delete'` operation respectively.
- `src/Relationship/RelationshipTraverseTool.php` + `src/Vector/VectorSearchTool.php` — same pattern; vector search results filtered to the access-allowed subset before being returned.

### ai-agent (L5) — runtime wires the context
- `src/AgentExecutor.php` — construct `AgentToolContext` per tool call from the resolved `_account` (read from request attributes or from the agent run record), the kernel `AccessChecker`, and the current `$run->id`. Pass through to `$tool->execute($args, $context)`. The `AgentAuditLog` write site already exists — extend it to also receive per-call `AccessChecker` decisions (`NFR-003`).
- `src/Tool/Bimaaji/{IntrospectGraphTool,IntrospectSectionTool,SearchSpecsTool,ProposeMutationTool}.php` — each carries `#[Capability(governed_data: false)]` (or `#[AsAgentTool(governedData: false)]`) per **D-D3**. Implementer enumerates against the "does this tool's output expose entity-data-shaped content OR mutate governed user data" test and writes the enumeration into the WP01 report.
- `src/AiAgentServiceProvider.php` — wire `AccessCheckerInterface` resolution for the executor's constructor (already resolves `AccountInterface` providers via `InitiatorAccountLoaderInterface`).

### access (L1) — no change required; consumed as-is
- `AccessCheckerInterface` (existing) is the contract `AgentToolContext` references. No edit needed unless the implementer chooses option (b) of **D-D4** (runtime guard), in which case `AccessChecker::lastDecisionWasMade(): bool` may be added — but the preferred mechanism is a per-run audit-log assertion, no L1 change.

### Tests
- `packages/ai-tools/tests/Unit/AgentToolContextTest.php` (if option (b)) — DTO construction + immutability.
- `packages/ai-tools/tests/Unit/Catalogue/AttributeToolRegistryTest.php` — marker discovery (governed_data = false vs default true).
- `packages/ai-tools/tests/Unit/Entity/EntityReadToolTest.php` — per-record gating: allowed account returns entity, denied account returns `accessDenied` shape.
- `packages/ai-agent/tests/Unit/AgentExecutorTest.php` — context propagation, audit-log emission on access checks.
- `tests/Integration/PhasePerRecordAiAccess/AgentToolBoundaryTest.php` (**FR-004**) — kernel-boot test: two accounts, divergent entity access, same tool, divergent results. MUST FAIL when `AgentExecutor` is reverted to passing only `(args, account)`.

### Codified-policy-gate impacts
- `bin/check-package-layers` — no new edges introduced (`ai-tools` already depends on `access`; `ai-agent` already depends on both).
- `bin/check-dead-code` — the new `Capability` attribute is reflection-discovered; the WaaseyaaEntrypointProvider (`tools/phpstan/WaaseyaaEntrypointProvider.php`) MUST be extended to mark classes carrying `#[Capability]` as used (mirrors how `#[PolicyAttribute]` is handled today). Without this, the new attribute classes would trigger fail-on-new dead-code findings.
- `bin/check-getquery-bindings` — every new `getQuery()->execute()` chain inside the updated tools MUST go through `setAccount()` (this is precisely the substrate being proved). No baseline-entry additions expected.
- `composer phpstan` — signature change ripples to every implementer; PHPStan failures point reviewers to any tool the implementer missed.

### Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/ai-tools/tests/ packages/ai-agent/tests/ tests/Integration/PhasePerRecordAiAccess/AgentToolBoundaryTest.php`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`

---

## WP02 — MCP serializer field-access wiring (M-A5)

**Layers:** L6 (`mcp`) + L1 (`access`).
**Owns:** the entity-shaping site under `packages/mcp/src/Tools/EntityTools.php` and any sibling serializer, the redaction-shape contract, and the MCP↔JSON:API parity integration test.

### mcp (L6) — serializer wiring
- `src/Tools/EntityTools.php` — locate the entity-to-MCP-response shaping. After loading the entity, before serialising, run it through `EntityAccessHandler::filterFields($entity, 'view', $account)` (or the `FieldAccessPolicyInterface` consultation method that JSON:API uses — check `packages/api/src/Serializer/ResourceSerializer.php` for the canonical call site to mirror exactly). For every field where the policy returns `AccessResult::forbidden()`, replace the value with the canonical redaction shape `['accessRestricted' => true, 'reason' => 'field_forbidden_for_account']`. Neutral and Allowed → exposed (FR-005 open-by-default).
- `src/Serializer/` (create if absent) — extract the redaction logic into `McpEntityFieldFilter.php` so both `EntityTools` and any future MCP entity-emitting tool can reuse it. `@api`.
- `src/McpServiceProvider.php` — bind the filter; ensure `EntityAccessHandler` is resolved from the kernel container (not constructed locally) so the field-policy collection matches the one JSON:API uses.

### access (L1) — no change required
- `EntityAccessHandler::filterFields()` (existing) is the contract MCP consults. If it's not yet a first-class method (some current call sites inline the per-field loop), extract it from `ResourceSerializer` into `EntityAccessHandler` so both surfaces use one helper. This is the "JSON:API + MCP shared field-exposure helper" referenced in spec.md "Out-of-band" — land it inside this WP if the extraction is cheap, otherwise file the follow-up.

### Tests
- `packages/mcp/tests/Unit/Serializer/McpEntityFieldFilterTest.php` — unit test: filter with forbidden field returns redaction marker, with allowed/neutral returns value.
- `packages/mcp/tests/Unit/Tools/EntityToolsTest.php` — extend existing test with a field-policy fixture (mirroring `PermissionAwareNodeVisibilityPolicy` already in the suite).
- `tests/Integration/PhasePerRecordAiAccess/McpJsonApiFieldParityTest.php` (**FR-007**) — kernel-boot test: register a `FieldAccessPolicy` returning forbidden on `node.body` for account X; hit MCP `entity.read` AND `GET /api/node/{id}` as account X; assert (a) MCP includes redaction marker, (b) JSON:API omits the field, (c) field-set parity (the set of field-keys "visible to caller" is identical between the two surfaces, modulo the marker's presence). MUST FAIL when the MCP wiring is removed.

### Spec updates (in scope, this WP)
- `docs/specs/mcp-endpoint.md` — stamp + "serializer redaction shape" section; document the canonical redaction marker.
- `docs/specs/field-access.md` — stamp + "MCP parity" section explaining the asymmetric shape (JSON:API omits, MCP redacts — both compliant with open-by-default).

### Codified-policy-gate impacts
- `bin/check-package-layers` — `mcp` already depends on `access` + `entity`; no new edges.
- `bin/check-dead-code` — new `McpEntityFieldFilter` class wired via `McpServiceProvider`; no new entrypoint convention required (service-provider-bound classes are reachable through provider scan).
- `composer phpstan` — covers the new filter class.

### Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/mcp/tests/ packages/access/tests/ tests/Integration/PhasePerRecordAiAccess/McpJsonApiFieldParityTest.php`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`

---

## WP03 — Per-file AI-access toggle field + admin UI (M-A5)

**Layers:** L1 (`field`, `access`) + L2 (`media`, `attachment`) + L6 (`admin`).
**Owns:** the new field type, the per-entity field registration, the policy class, the migration, the SPA component, and the toggle integration test.

### field (L1) — new field type
- `src/FieldType/AiAccessibleField.php` — implements `FieldTypeInterface`. Storage column: `VARCHAR(8)` (covers `'yes'`, `'no'`, `'inherit'`). Default `'inherit'`. Validates value ∈ `{'yes', 'no', 'inherit'}`. JSON Schema output for `SchemaPresenter` integration. `@api`.
- `src/FieldServiceProvider.php` — register the new field type via `FieldTypeManager::addFieldType(new FieldType(id: 'ai_accessible', class: AiAccessibleField::class, ...))`.

### media (L2) + attachment (L2) — entity field registration + migration
- `packages/media/src/Entity/Media.php` — add `ai_accessible` to `fieldDefinitions()` array (field type `'ai_accessible'`, label `'AI accessibility'`, default `'inherit'`).
- `packages/media/migrations/<timestamp>_add_ai_accessible_to_media.php` — `SqlSchemaHandler::addColumn('media', 'ai_accessible', ...)`. Mirror existing migration patterns under `packages/media/migrations/`.
- `packages/attachment/src/Entity/Attachment.php` + sibling migration under `packages/attachment/migrations/`.
- `packages/media/src/MediaServiceProvider.php` + `packages/attachment/src/AttachmentServiceProvider.php` — no change required if `fieldDefinitions()` is read at registration time.

### access (L1) — new policy
- `src/Policy/AiAccessibilityPolicy.php` — `final class AiAccessibilityPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface`. Carries `#[PolicyAttribute(entityType: 'media')]` + `#[PolicyAttribute(entityType: 'attachment')]`. `access()` consults the `ai_accessible` field on the entity. When value === `'no'` AND the request is agent-initiated (per **D-D2** — preferred mechanism (b): check request attributes for `_agent_run_id`), return `AccessResult::forbidden()`. When value === `'inherit'`, return `AccessResult::neutral()` (defers to other policies; until M-A4 lands, this is equivalent to `'yes'`). When value === `'yes'`, return `AccessResult::neutral()` (does not affirmatively allow; other policies decide). `fieldAccess()` mirrors with the same rule. `@api`.

### admin (L6) — toggle UI
- `packages/admin/app/components/media/AiAccessibleToggle.vue` — tri-state control (`yes` / `no` / `inherit`), bound via the existing `SchemaField` renderer chain. Read `docs/specs/admin-spa.md` for the canonical field-renderer registration mechanism before inventing a new one.
- `packages/admin/app/i18n/en.json` — keys `media.ai_accessible.{label, help, yes, no, inherit}`.
- `packages/admin/tests/unit/components/media/AiAccessibleToggle.test.ts` — vitest: tri-state render, change-emit, default `'inherit'`.

### Tests
- `packages/field/tests/Unit/FieldType/AiAccessibleFieldTest.php` — value validation, default, JSON Schema output.
- `packages/access/tests/Unit/Policy/AiAccessibilityPolicyTest.php` — `'no'` + agent context → forbidden; `'no'` + non-agent context → neutral; `'yes'` → neutral; `'inherit'` → neutral.
- `packages/media/tests/Unit/Entity/MediaTest.php` — field registration present.
- `tests/Integration/PhasePerRecordAiAccess/AiAccessibleToggleTest.php` (**FR-011**) — kernel-boot test: seed media with `'no'`, exercise `EntityReadTool::execute()` with agent-initiated account, assert forbidden; update to `'yes'`, re-run, assert allowed. Uses WP01's tool-boundary wiring (no inbound WP dependency — the test fails cleanly with a clear message if WP01 is missing).

### Spec updates (in scope, this WP)
- `docs/specs/access-control.md` — stamp + "AI access at the tool boundary" section enumerating the `ai_accessible` field semantics + `AiAccessibilityPolicy` registration.
- `docs/specs/ai-integration.md` — stamp + "Per-record access" subsection.

### Codified-policy-gate impacts
- `bin/check-package-layers` — `media` and `attachment` already depend on `field`; `access` already provides policies via attribute discovery; no new edges.
- `bin/check-dead-code` — `AiAccessibilityPolicy` carries `#[PolicyAttribute]` (already handled by `WaaseyaaEntrypointProvider`). `AiAccessibleField` carries class-level `@api`.
- `composer phpstan` — covers all new classes.

### Verification gate (in lane worktree)
1. `composer install && cd packages/admin && npm install && cd -`
2. `vendor/bin/phpunit packages/field/tests/ packages/access/tests/ packages/media/tests/ packages/attachment/tests/ tests/Integration/PhasePerRecordAiAccess/AiAccessibleToggleTest.php`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `cd packages/admin && npm test && npm run typecheck && npm run lint`

---

## Reviewer focus

- (a) **R1 / FR-004 / FR-007 / FR-011 — dead-code-in-production guards.** For each WP, verify the integration test FAILS when the WP's wiring is reverted. The reviewer runs each test once with the wiring present (green) and once with the wiring removed (red) before approving. This is non-negotiable per DIR-004.
- (b) **D-D3 marker enumeration.** Independently grep the four bimaaji tool classes; confirm `governed_data: false` is present on each. Independently confirm that no tool under `packages/ai-tools/src/Entity/` carries the marker (every entity-touching tool MUST go through `AccessChecker`).
- (c) **C-002 layer integrity.** Grep `packages/access/src/**` + `packages/field/src/**` for any `use Waaseyaa\AI\` import — MUST find zero. `bin/check-package-layers` green.
- (d) **C-003 redaction shape.** Grep MCP serializer output for the literal `accessRestricted` key; confirm the redaction marker is the only forbidden-field representation. Confirm no MCP path 403's an entity for a single forbidden field.
- (e) **NFR-003 audit lineage.** Confirm `AgentAuditLog` receives an entry per `AccessChecker` consultation inside a tool, not only for explicit denials. DIR-004 requires the lineage even on allowed decisions.
- (f) **`getQuery()` baseline discipline.** No new entries added to `tools/getquery-bindings-baseline.txt`; every new chain uses `setAccount()`.
