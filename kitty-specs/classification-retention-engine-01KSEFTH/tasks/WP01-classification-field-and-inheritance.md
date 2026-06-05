---
work_package_id: WP01
title: Classification field type, ClassificationLabelDefinition entity, LabelInheritanceResolver + parent resolvers, EntityLifecycleSubscriber, label-seed YAML
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-007
- NFR-001
- NFR-005
- C-001
- C-002
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-A
- T-B
- T-C
- T-D
- T-E
agent: claude
history: []
authoritative_surface: packages/field/src/Classification
execution_mode: code_change
owned_files:
- packages/field/src/Classification/ClassificationLabelFieldType.php
- packages/field/src/Classification/ClassificationDecision.php
- packages/field/src/Classification/LabelInheritanceResolver.php
- packages/field/src/Classification/ClassificationParentResolverInterface.php
- packages/field/src/Classification/ParentResolver/MediaParentResolver.php
- packages/field/src/Classification/ParentResolver/NodeParentResolver.php
- packages/field/src/Classification/ParentResolver/AttachmentParentResolver.php
- packages/field/src/Classification/EntityLifecycleSubscriber.php
- packages/field/src/Form/Widget/ClassificationLabelWidget.php
- packages/field/src/Entity/ClassificationLabelDefinition.php
- packages/field/migrations/2026_05_25_000003_create_classification_label_definition_table.php
- packages/field/defaults/classification-labels.yaml
- packages/field/src/Attribute/FieldTemplate.php
- packages/field/src/FieldServiceProvider.php
- packages/field/tests/Unit/Classification/LabelInheritanceResolverTest.php
- packages/field/tests/Unit/Classification/EntityLifecycleSubscriberTest.php
- packages/field/tests/Unit/Entity/ClassificationLabelDefinitionTest.php
tags:
- substrate
- classification
- field-type
- inheritance
- layer-1
---

# WP01 — Classification field type + label inheritance

**Mission:** `classification-retention-engine-01KSEFTH` (gap-matrix A4; alpha-to-beta-plan §1 item #4)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` MUST be merged.

## Pattern references — READ FIRST

- `packages/field/src/Attribute/FieldTemplate.php` + `BundleTemplate.php` — current attribute shape. T-A may extend `FieldTemplate` with a `pii` parameter (additive, default `false`).
- `packages/field/src/Form/Widget/` — existing widget shapes.
- `packages/field/src/BundleTemplateCompiler.php` — how field metadata is consumed.
- `packages/entity-storage/src/Schema/SqlSchemaHandler.php` — schema contribution mechanism.
- `docs/specs/entity-storage-two-axis.md` — DIR-005 substrate; classification lives on the non-translatable axis (NFR-001 / C-005).
- `packages/audit/src/Contract/AuditWriterInterface.php` + `AuditEventDescriptor.php` — from the prior cluster mission; this WP dispatches `classification.change` events via the writer.
- `packages/config/src/Sync/` — config-import idempotency mechanism (NFR-005).

## Subtasks

### T-A — `classification_label` field type
- `packages/field/src/Classification/ClassificationLabelFieldType.php implements FieldTypeInterface`. `@api`. Contributes three schema columns to host entity table (`classification_label`, `classification_inherited_from`, `classification_overridden_at`). Indexed on `classification_label`.
- If `FieldTemplate` lacks `pii: bool`, extend it (additive). Document the change in the WP report.
- `Widget/ClassificationLabelWidget.php` renders a `<select>` from `ClassificationLabelDefinition` entities (ordered by `confidentiality_level ASC`).

### T-B — `ClassificationLabelDefinition` entity + seed YAML
- Entity per CLAUDE.md §"Adding an entity type". `@api`. Migration creates the table.
- `packages/field/defaults/classification-labels.yaml` — seeds the nine labels listed in spec.md §In-scope (`public`, `internal`, `confidential`, `restricted`, `nation-confidential`, `nation-sacred`, `hold-legal`, `hold-research`, `hold-ethics-review`). Each row carries `label_id`, `display_name`, `confidentiality_level`.
- Verify the existing config-import is idempotent by `label_id` as natural key (NFR-005). If it isn't, document the gap and propose a one-line fix.

### T-C — `LabelInheritanceResolver` + parent-resolver registry
- `ClassificationDecision.php` (readonly) + `LabelInheritanceResolver.php` per plan.md §T-C.
- `ClassificationParentResolverInterface.php` `@api`.
- Three stock implementations in `ParentResolver/` for media, node, attachment. Each `supports()` matches by `entity_type_id`. Verify each entity-type's parent reference field in its package (`packages/media/`, `packages/attachment/`).
- Service-provider registration: bind the resolver with an iterable of parent resolvers. Use whatever tagged-collection pattern exists (CLAUDE.md notes future tagged-collection support — for now bind the iterable explicitly in `FieldServiceProvider::register()`).

### T-D — `EntityLifecycleSubscriber`
- Subscribes to entity-save events (verify event FQCNs in `packages/entity/src/Event/`).
- Before-save: compute effective label via the resolver; persist label + `inherited_from`. Detect changes vs prior persisted value; dispatch `AuditEventDescriptor(eventKind: AuditEventKind::ClassificationChange, ...)` via injected `AuditWriterInterface`.
- Whole body try-catch wrapped per CLAUDE.md §Logging "best-effort side effects" pattern (do not crash the save on audit failure — `AuditWriterInterface::record()` is itself best-effort, but defence in depth).

### T-E — Unit tests
- Per plan.md §T-E. Use anonymous classes for any intersection-typed mock (CLAUDE.md gotcha).

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/field/tests/Unit/Classification/ packages/field/tests/Unit/Entity/ClassificationLabelDefinitionTest.php`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. `bin/waaseyaa optimize:manifest`.
6. NFR-005: run `config:import packages/field/defaults/classification-labels.yaml` twice; assert second run is a no-op (no duplicate definitions). Use `bin/waaseyaa entity:count classification_label_definition` between runs.
7. Layer check: `bin/check-package-layers` must report no new upward edges from `packages/field`.

## Commit + handoff

- `feat(field): classification_label field type + widget + schema contribution`
- `feat(field): ClassificationLabelDefinition entity + 9-label seed YAML`
- `feat(field): LabelInheritanceResolver + ClassificationParentResolverInterface + 3 stock resolvers`
- `feat(field): EntityLifecycleSubscriber dispatches classification.change audit events`
- `test(field): label inheritance + lifecycle subscriber + label-definition tests`

```
spec-kitty agent tasks mark-status T-A T-B T-C T-D T-E --status done --mission classification-retention-engine-01KSEFTH
spec-kitty agent tasks move-task WP01 --to for_review --mission classification-retention-engine-01KSEFTH --note "Field type + inheritance substrate in place; audit-event dispatch verified"
```

## Report back with

1. Commit SHAs.
2. The exact `FieldTemplate` signature change (if extended) — paste old + new constructor signatures.
3. Idempotent-import proof: counts before / after two `config:import` runs (must be the same).
4. The audit-event payload dispatched for a sample classification-change scenario (paste from `EntityLifecycleSubscriberTest`).
5. Output of `bin/check-package-layers` (clean — no new upward edges from `packages/field`).

## Activity Log
- 2026-05-25T06:11:05Z – claude – Moved to in_progress
- 2026-05-26T11:17:38Z – claude – Done override: Feature squash-merged to main (b170e0a44)
