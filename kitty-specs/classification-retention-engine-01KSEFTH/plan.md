# Implementation Plan: Classification + Retention Engine

**Mission:** `classification-retention-engine-01KSEFTH` — see `spec.md`.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` (must be merged first).
**Pattern references (READ FIRST):** `packages/field/src/Form/`, `packages/field/src/Attribute/{FieldTemplate,BundleTemplate}.php`, `packages/field/src/BundleTemplateCompiler.php` (field-type system); `packages/access/src/Policy/*` + `docs/specs/field-access.md` + `docs/specs/access-control.md` (policy registration + open-by-default field-access); `packages/scheduler/src/ScheduleEntriesInterface.php` + `Schedule.php` (scheduled-task contract); `docs/specs/entity-storage-two-axis.md` + `entity-storage-translatable-revisions.md` (DIR-005 substrate); CLAUDE.md §"Adding an entity type" / §"Adding an access policy" / §"Adding a schedule-entries class".

**Four WPs.** WP02 depends on WP01 (field type + label entity in place). WP03 depends on WP01 + WP02 (jobs need both label resolver + policy entity). WP04 depends on WP02 (admin SPA needs policy JSON-schema). WP02 and WP04 can otherwise proceed in parallel once their respective prerequisites land.

## WP01 — Field type, label catalogue entity, inheritance resolver, parent resolvers

### `classification_label` field type (T-A)
- `packages/field/src/Classification/ClassificationLabelFieldType.php` `implements FieldTypeInterface`. Class-level `@api`.
- Schema contribution: when attached to a bundle, contributes three columns to the host entity table: `classification_label` (string, indexed), `classification_inherited_from` (string nullable), `classification_overridden_at` (datetime nullable).
- Field metadata via `#[FieldTemplate(type: 'classification_label', label: 'Classification', cardinality: 1, settings: [...])]`. Verify the exact attribute signature in `packages/field/src/Attribute/FieldTemplate.php` — extend additively if a `pii` parameter or `metadata` slot is needed.
- The field's Form widget (in `packages/field/src/Form/Widget/ClassificationLabelWidget.php`) renders a `<select>` populated from `ClassificationLabelDefinition` entities, ordered by `confidentiality_level ASC`. When the user changes the selection, `classification_overridden_at` is set to `now()` in the controller.

### `ClassificationLabelDefinition` entity (T-B)
- `packages/field/src/Entity/ClassificationLabelDefinition.php extends ContentEntityBase`. Constructor hardcodes `entity_type_id = 'classification_label_definition'` and keys `{id, uuid, label: 'display_name'}`. Class-level `@api`.
- Schema fields per spec.md §In-scope. Migration in `packages/field/migrations/2026_05_25_000003_create_classification_label_definition_table.php`.
- `packages/field/defaults/classification-labels.yaml` — nine canonical labels. Loadable via `bin/waaseyaa config:import`. Idempotent re-import: the importer uses `label_id` as the natural key (verify the existing config-import idempotency mechanism in `packages/config`).

### Label inheritance: resolver + parent-resolver registry (T-C)
- `packages/field/src/Classification/ClassificationDecision.php` — readonly value: `string $labelId`, `?string $inheritedFrom`, `\DateTimeImmutable $resolvedAt`. `@api`.
- `packages/field/src/Classification/LabelInheritanceResolver.php`. Constructor: `(iterable<ClassificationParentResolverInterface> $parentResolvers, EntityTypeManager $etm, ?LoggerInterface $logger = null)`. Method `resolve(EntityInterface $entity): ClassificationDecision`:
  - If `$entity->get('classification_overridden_at')->value !== null` and `$entity->get('classification_label')->value !== null` → return its own.
  - Else: find a parent-resolver whose `supports($entity)` returns true; call `parentOf($entity)`; recurse; return parent's decision with `inheritedFrom = "{parentType}:{parentUuid}"`.
  - If no parent → return a `ClassificationDecision` with `labelId = 'public'` and `inheritedFrom = null` (the safe default per open-by-default — see decisions deferred).
- `packages/field/src/Classification/ClassificationParentResolverInterface.php` — `supports(EntityInterface $entity): bool`, `parentOf(EntityInterface $entity): ?EntityInterface`. `@api`.
- Three stock implementations in `packages/field/src/Classification/ParentResolver/`:
  - `MediaParentResolver` — `supports(): bool` matches `entity_type_id === 'media'`; `parentOf()` returns the entity referenced by `media`'s parent-entity field (verify exact field name in `packages/media/src/Media.php`; if media doesn't currently have a parent-entity reference, the resolver returns null — documented as a known limitation; an enhancement mission can add a parent reference to media later).
  - `NodeParentResolver` — currently returns null (node has no parent today; resolver is in place for forward-compat).
  - `AttachmentParentResolver` — supports `attachment` entities; `parentOf()` returns the host entity (attachments always have a host entity per `packages/attachment`).

### Storage hook: `EntityLifecycleSubscriber` (T-D)
- `packages/field/src/Classification/EntityLifecycleSubscriber.php` subscribes to entity-save events (verify event FQCNs in `packages/entity/src/Event/`).
- Before-save handler: for any entity whose bundle includes the `classification_label` field:
  - If `classification_overridden_at` IS set (user override in the form payload) → respect: write the label as-is, `inherited_from = null`.
  - Else: call `LabelInheritanceResolver::resolve()`; write the resolved label + `inherited_from`. Persist the resolution.
  - If the resolved label differs from the previous persisted value → dispatch a `Waaseyaa\Audit\Contract\AuditEventDescriptor` of kind `AuditEventKind::ClassificationChange` via the injected `AuditWriterInterface` with attributes `{from_label, to_label, source: 'override'|'inheritance', inherited_from}`. (FR-007)
- The subscriber's body is best-effort try-catch — a failing audit write does not block the save (consistent with the audit substrate's best-effort guarantee).

### Tests for WP01 (T-E)
- `packages/field/tests/Unit/Classification/LabelInheritanceResolverTest.php` — table-driven: terminal entity → `public`; entity with override → own label; entity with parent (override) → parent's label + `inheritedFrom` populated; entity with grandparent → recurses correctly.
- `packages/field/tests/Unit/Classification/EntityLifecycleSubscriberTest.php` — fires fake save event; asserts the resolved label is written; asserts an `AuditEventDescriptor` of kind `classification.change` is dispatched on a label change.
- `packages/field/tests/Unit/Entity/ClassificationLabelDefinitionTest.php` — schema sanity + idempotent re-import smoke.

## WP02 — `RetentionPolicy` entity, `ClassificationFieldAccessPolicy`, clearance checker, JSON:API CRUD

### `RetentionPolicy` entity (T-F)
- `packages/field/src/Entity/RetentionPolicy.php extends ContentEntityBase`. Schema per spec.md §In-scope. Migration in `packages/field/migrations/2026_05_25_000004_create_retention_policy_table.php`. `@api`.
- JSON-Schema for the entity is auto-derived by the framework's `SchemaPresenter` (per CLAUDE.md §"Adding an API endpoint"). Verify `SchemaPresenter` exposes the `applies_to` array as an array-of-strings + `action` + `trigger_kind` as enums.

### `ClassificationFieldAccessPolicy` (T-G)
- `packages/field/src/Classification/Policy/ClassificationFieldAccessPolicy.php implements AccessPolicyInterface, FieldAccessPolicyInterface` (intersection-type per `docs/specs/field-access.md`). Class-level `#[PolicyAttribute(entityType: '*')]` (verify the wildcard pattern is supported; if not, fall back to per-entity-type registration in service provider). `@api`.
- `access(EntityInterface $entity, AccountInterface $account, string $op): AccessResult` (entity-level — implements `AccessPolicyInterface`): when `op === 'view'` or `'update'` or `'delete'`, consult the entity's classification label. If `hold-*` and account lacks `legal-hold-bypass` → `forbidden()`. If clearance < confidentiality → `forbidden()`. Else `neutral()` (other policies may grant or remain neutral).
- `fieldAccess(EntityInterface $entity, string $fieldName, AccountInterface $account, string $op): AccessResult` (field-level — implements `FieldAccessPolicyInterface`): same logic per `docs/specs/field-access.md` open-by-default — only return `forbidden()`; else `neutral()`.
- Constructor: `(ClassificationLabelRegistryInterface $labels, ClassificationClearanceCheckerInterface $clearance)`.

### `ClassificationClearanceCheckerInterface` + `RoleBasedClearanceChecker` (T-H)
- `packages/field/src/Classification/ClassificationClearanceCheckerInterface.php`. `clearanceLevelFor(AccountInterface $account): int`. `@api`.
- `packages/field/src/Classification/RoleBasedClearanceChecker.php implements ClassificationClearanceCheckerInterface`. Reads `classification.role_clearance` config (default: `{admin: 10, nation-steward: 9, editor: 5, viewer: 1}`); returns the highest level matched across the account's roles; default 0 for accounts with no matching role.

### `ClassificationLabelRegistryInterface` (T-I)
- `packages/field/src/Classification/ClassificationLabelRegistryInterface.php`. Cached lookup `definition(string $labelId): ?ClassificationLabelDefinition` over the entity repository. `@api`.
- Simple impl `ClassificationLabelRegistry` with a request-scoped in-memory cache (cleared on entity save).

### Permissions + role catalogue update (T-J)
- `packages/field/src/Classification/Permissions.php` — declares the `legal-hold-bypass` permission constant. `@api`.
- The default role catalogue (`packages/user/defaults/role-catalogue.yaml` or wherever the project stores it — verify location) gains a `legal-hold-bypass` permission entry; the bundled `admin` role does NOT grant it by default (only explicit `legal-hold-bypass` role can grant access to held data — Hold's whole point).

### JSON:API CRUD endpoints (T-K)
- No bespoke controllers needed beyond entity registration + route declarations. `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` adds `api.classification.policies.*` routes (index/show/create/update/delete) over the `RetentionPolicy` entity; route options `_role: admin` for write, `_role: governance-viewer` for read.
- Routes use the framework's standard `JsonApiController` patterns (see existing routes for other entities).

### Tests for WP02 (T-L)
- `packages/field/tests/Unit/Classification/Policy/ClassificationFieldAccessPolicyTest.php` — table-driven covering: anonymous reads `confidential` → forbidden; viewer reads `internal` → neutral; admin without bypass reads `hold-legal` → forbidden; admin WITH bypass reads `hold-legal` → neutral; admin reads `public` → neutral. Uses anonymous classes implementing intersection types per CLAUDE.md §Testing gotcha (createMock can't mock intersection types).
- `packages/field/tests/Unit/Classification/RoleBasedClearanceCheckerTest.php` — config-driven mapping verification.
- `packages/field/tests/Unit/Entity/RetentionPolicyTest.php` — schema + JSON-schema generation smoke.

## WP03 — Scheduled jobs (purge, redact, hold-scan)

### `ClassificationRetentionScheduleEntries` (T-M)
Per CLAUDE.md §"Adding a schedule-entries class":
- `packages/field/src/Classification/Schedule/ClassificationRetentionScheduleEntries.php implements ScheduleEntriesInterface`. Class-level `@api`.
- `register(ScheduleInterface $schedule): array` returns three `ScheduledTask`s:
  - `classification.retention.purge` — cron `0 */6 * * *` (6-hourly).
  - `classification.retention.redact` — cron `30 */6 * * *` (6-hourly, offset by 30min so the two don't slam the DB at once).
  - `classification.retention.hold_scan` — cron `0 3 * * *` (daily, 03:00 UTC).

### Purge job (T-N)
- `packages/field/src/Classification/Job/PurgeJob.php`. Constructor: `(EntityRepositoryInterface $policyRepo, EntityTypeManager $etm, AuditWriterInterface $auditWriter, ?LoggerInterface $logger = null)`.
- `run()`:
  - Load all `RetentionPolicy` entities where `action = 'purge'` and `trigger_kind = 'age_based'`.
  - For each policy, wrapped in try-catch (NFR-004):
    - Compute cutoff `now() - $policy->trigger_value` (ISO-8601 duration).
    - For each label glob in `applies_to`, query entities across all entity types that have a `classification_label` field with matching label and `created_at < cutoff` and uuid NOT IN policy.exemptions.
    - For each matched entity: `$repo->delete($entity)` (fires `entity.delete` — the audit substrate's `EntityLifecycleAuditListener` writes the `entity.delete` audit record automatically).
    - Then write `AuditEventDescriptor(eventKind: AuditEventKind::RetentionPurge, entityType: ..., entityUuid: ..., attributes: {policy_id, label_id})`.
  - Log per-policy summary at `info` level: `Purged N entities for policy {id}`.

### Redact job (T-O)
- `packages/field/src/Classification/Job/RedactJob.php`. Constructor mirrors PurgeJob.
- `run()`:
  - Load all `RetentionPolicy` entities where `action = 'redact'`.
  - For each policy (try-catch wrapped):
    - For each matched entity: discover PII fields via the bundle's `#[FieldTemplate(..., pii: true)]` metadata (verify the metadata-read mechanism in `packages/field/src/BundleTemplateCompiler.php`); for each PII field, set the value to `null` via the entity repository's update path; save.
    - Write `AuditEventDescriptor(eventKind: AuditEventKind::RetentionRedact, entityType, entityUuid, attributes: {policy_id, label_id, redacted_fields: [...]})`.

### Hold-scan job (T-P)
- `packages/field/src/Classification/Job/HoldScanJob.php`. Verification-only.
- `run()`:
  - Load all `RetentionPolicy` entities where `action = 'hold-flag'`. Note their `applies_to` label set.
  - Separately, load all `action = 'purge'` policies; note their `applies_to` set.
  - For each entity matched by ANY hold-flag policy: check whether the same entity is also matched by ANY purge policy. If so → conflict.
  - For each conflict: write `AuditEventDescriptor(eventKind: AuditEventKind::ClassificationChange, attributes: {conflict: 'hold_vs_purge', entity_type, entity_uuid, purge_policy_id, hold_policy_id})` AND log at `notice` level.

### Tests for WP03 (T-Q)
- `packages/field/tests/Unit/Classification/Schedule/ClassificationRetentionScheduleEntriesTest.php` — assert three tasks registered with correct cron expressions.
- `packages/field/tests/Unit/Classification/Job/PurgeJobTest.php` — seed policies + entities; run; assert: only age-eligible entities deleted; exemptions honoured; `retention.purge` audit events written.
- `packages/field/tests/Unit/Classification/Job/RedactJobTest.php` — seed entity with a `pii: true` field + a structural field; run; assert PII field null, structural field preserved, audit event written.
- `packages/field/tests/Unit/Classification/Job/HoldScanJobTest.php` — seed conflicting policies; run; assert conflict audit event + log line.
- `packages/field/tests/Unit/Classification/Job/BestEffortTest.php` — inject a writer that throws on second call; assert first policy completes, second logs warning, third policy still runs.

### FR-015 integration test (T-R)
- `tests/Integration/PhaseClassificationRetention/ClassificationRetentionIntegrationTest.php` `#[CoversNothing]`. Boots full kernel; seeds the FR-015 scenario; asserts all five sub-assertions. Includes a code-block comment block documenting that removing the `ClassificationFieldAccessPolicy` registration MUST cause the hold-block assertion to fail (reviewer verifies this by-hand).

## WP04 — Admin SPA policy editor

### Composable + pages + nav (T-S)
- `packages/admin/app/composables/useRetentionPolicies.ts` — `{policies, loading, error, fetchPolicies(), savePolicy(policy), deletePolicy(id)}`. Mirror `useQueueJobs.ts` shape; use `useApi`.
- `packages/admin/app/pages/classification/policies/index.vue` — list page; summary cards (total policies, by action breakdown); table of policies with edit/delete buttons.
- `packages/admin/app/pages/classification/policies/[id].vue` — detail/edit page; uses `SchemaForm` (per CLAUDE.md `packages/admin/*` orchestration row) against the `RetentionPolicy` JSON-schema fetched from `/api/schemas/retention_policy`.
- Nav entry under "Governance" group → `/classification/policies` (mirror how `/queue` registers per M5A WP02).
- i18n keys in `packages/admin/app/i18n/en.json`: `classification_policies_title`, `classification_policy_action_purge`, `..._redact`, `..._hold_flag`, `classification_policy_label_applies_to`, etc.

### Tests for WP04 (T-T)
- `packages/admin/tests/unit/composables/useRetentionPolicies.test.ts` — vitest: fetch success, save, delete, error path.
- `packages/admin/e2e/classification-policies.spec.ts` — Playwright smoke (deferred run per lane-worktree limitation).
- `docs/specs/classification-and-retention.md` — created in WP04 (or split: schema-level docs in WP02, admin docs in WP04).
- `CLAUDE.md` orchestration table — add `packages/field/src/Classification/*` row → `docs/specs/classification-and-retention.md`.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

## Verification gate (each WP, in lane worktree)

1. `composer install`; (for WP04) `cd packages/admin && npm install`.
2. WP01-WP03: `vendor/bin/phpunit packages/field/tests/Unit/Classification/ tests/Integration/PhaseClassificationRetention/`. WP04: vitest + typecheck + lint.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. `bin/waaseyaa schedule:list` (WP03) — three classification.retention tasks present.
6. `bin/waaseyaa config:import packages/field/defaults/classification-labels.yaml && bin/waaseyaa config:import packages/field/defaults/classification-labels.yaml` — second run does NOT create duplicates (NFR-005).
7. Reviewer: dead-code-guard verification on FR-015 (remove `ClassificationFieldAccessPolicy` policy registration; rerun integration test; confirm hold-block assertion fails; restore).

## Reviewer focus

- (a) **DIR-005 honoured:** classification field columns added to host entity table on the **non-translatable axis** (verify in two-axis-storage spec by inspecting which axis stores entity-level metadata); no two-axis substrate dropped.
- (b) **DIR-004 honoured:** classification.change + retention.purge + retention.redact audit events all written via `AuditWriterInterface`. Hold-vs-purge conflict surfaces via `classification.change` event for admin review.
- (c) **C-004 hold-override:** `ClassificationFieldAccessPolicy` returns `forbidden()` for `hold-*` labels regardless of clearance, only `legal-hold-bypass` permits. Integration test seeds an admin WITHOUT bypass and asserts the block.
- (d) **NFR-004 best-effort:** retention jobs wrap each policy iteration in try-catch; a failing policy doesn't block other policies. `BestEffortTest` proves this.
- (e) **Cross-mission seam:** the listener seam with `ocap-audit-log-substrate-01KSEFTF` is clean — this mission `use`s `Waaseyaa\Audit\Contract\AuditWriterInterface` only (not concrete writer); audit kinds `classification.change`, `retention.purge`, `retention.redact` exist in the `AuditEventKind` enum.
- (f) **Idempotent label import** (NFR-005): re-running `config:import` doesn't duplicate.
- (g) **Layer cleanliness** (C-002): `packages/field` does not gain a higher-layer dependency. `bin/check-package-layers` green.
- (h) **PII-marker convention:** if `#[FieldTemplate]` is extended with `pii: true`, the change is additive + backward-compatible. Reviewer confirms no existing field templates break.
