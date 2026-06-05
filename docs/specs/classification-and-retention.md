# Classification & Retention Engine

Mission: `classification-retention-engine-01KSEFTH`. Refs: gap-matrix-A4, DIR-004.

The classification substrate lets a Nation label any entity with a
confidentiality/hold classification, inherit labels down a parent chain,
gate access by clearance, and drive retention (purge / redact / hold) from
those labels. It lives entirely in `packages/field` (L1) plus a thin set of
JSON:API routes in `packages/foundation` (L4 route registrar) and an admin
SPA editor in `packages/admin` (L6).

## Layers & ownership

| Concern | Where |
|---|---|
| Label vocabulary entity | `packages/field/src/Entity/ClassificationLabelDefinition.php` |
| Per-record label (field type) | `packages/field/src/Classification/ClassificationLabelFieldType.php` (columns `classification_label`, `classification_inherited_from`, `classification_overridden_at`) |
| Inheritance | `packages/field/src/Classification/LabelInheritanceResolver.php` + `ParentResolver/*` + `EntityLifecycleSubscriber` (PRE_SAVE) |
| Retention rule entity | `packages/field/src/Entity/RetentionPolicy.php` |
| Access enforcement | `packages/field/src/Classification/Policy/ClassificationFieldAccessPolicy.php` |
| Clearance | `packages/field/src/Classification/{ClassificationClearanceCheckerInterface,RoleBasedClearanceChecker}.php` |
| Label lookup | `packages/field/src/Classification/{ClassificationLabelRegistryInterface,ClassificationLabelRegistry}.php` |
| Permissions / roles | `packages/field/src/Classification/Permissions.php`, `packages/field/defaults/classification.yaml` |
| Scheduled jobs | `packages/field/src/Classification/Schedule/ClassificationRetentionScheduleEntries.php` + `Job/{Purge,Redact,HoldScan}Job.php` |
| JSON:API routes | `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` (`api.classification.policies.*`) |
| Admin editor | `packages/admin/app/pages/classification/policies/*`, `composables/useRetentionPolicies.ts` |

## Labels

Nine bundled seed labels (override/extend via `config:import`) in
`packages/field/defaults/classification-labels.yaml`, keyed by `label_id`
with an ordinal `confidentiality_level` (0 = public … 80 = hold-ethics-review):
`public`, `internal`, `confidential`, `restricted`, `nation-confidential`,
`nation-sacred`, `hold-legal`, `hold-research`, `hold-ethics-review`.

Labels prefixed `hold-` carry hold semantics (see Access). Labels are data,
not code constants — a Nation defines its own.

## Inheritance

On every save, `EntityLifecycleSubscriber` resolves the effective label via
`LabelInheritanceResolver`: an explicit `classification_label` wins; otherwise
the entity's parent (resolved per-type via `ClassificationParentResolverInterface`)
supplies the label and `classification_inherited_from = parent_uuid` is recorded.
Cascade is re-evaluate-on-next-write (no eager downward cascade — bounded scope,
C-003). Every effective-label change writes a `classification.change` audit event.

## Access (`ClassificationFieldAccessPolicy`)

Registered cross-cutting via `#[PolicyAttribute(entityType: '*')]` (the kernel
policy resolver injects the manifest entity types into the constructor; the
policy matches `'*'` in `appliesTo()`). Implements both `AccessPolicyInterface`
and `FieldAccessPolicyInterface`. Two rules, **in order**:

1. **Hold override (C-004 / FR-013).** A `hold-*` label forbids access for any
   account lacking the `legal-hold-bypass` permission — even an admin. Hold
   short-circuits: clearance is not consulted. Held data is **never** deleted or
   redacted; it stays present and is blocked at read time, preserving
   legal/research/ethics traceability.
2. **Clearance gate (FR-005 / FR-008).** Account clearance below the label's
   confidentiality level → forbidden. Otherwise neutral (other policies decide).

Open-by-default on field-level, deny-unless-granted on entity-level — the policy
only ever returns Forbidden or Neutral. An unknown label is Neutral (a
misconfigured policy never silently locks everyone out).

### Clearance

`RoleBasedClearanceChecker` maps roles → level via the `classification.role_clearance`
config (default `admin:10, nation-steward:9, editor:5, viewer:1, anonymous:0`);
the max across an account's roles wins. Nations override via config.

### Roles & permission

No central role-catalogue table exists; roles are plain strings on accounts.
`Permissions::LEGAL_HOLD_BYPASS` (`legal-hold-bypass`) is the sole hold escape
hatch — the bundled `admin` role does **not** carry it. `governance-viewer` is
the read-only role for the policy API. Both documented in
`packages/field/defaults/classification.yaml`.

## Retention rules (`RetentionPolicy`)

Columns: `name`, `applies_to` (JSON array of `label_id` patterns, glob-aware
e.g. `nation-*`), `action` (`purge` | `redact` | `hold-flag`), `trigger_kind`
(`age_based` | `event_based`), `trigger_value` (ISO-8601 duration or event-kind),
`exemptions` (JSON array of `entity_type:uuid`). Sibling of `AuditRetentionPolicy`
(audit-log retention) — split deliberately.

### Scheduled jobs

`ClassificationRetentionScheduleEntries` registers three tasks (auto-discovered):

| Task | Cron | Job | Behaviour |
|---|---|---|---|
| `classification.retention.purge` | `0 */6 * * *` | `PurgeJob` | Deletes age-eligible matching entities via storage (fires `entity.delete` audit) + a `retention.purge` event each. |
| `classification.retention.redact` | `30 */6 * * *` | `RedactJob` | Nulls `#[FieldTemplate(pii: true)]` fields, preserves identity/label/audit; writes `retention.redact`. |
| `classification.retention.hold_scan` | `0 3 * * *` | `HoldScanJob` | Verification-only: flags hold-vs-purge conflicts with a `classification.change` (`conflict: hold_vs_purge`) event + notice log. Never deletes. |

All jobs are best-effort per policy iteration (NFR-004): a single failing policy
never aborts the sweep. System sweeps query with `accessCheck(false)`.

## JSON:API + admin

`/api/classification/policies[/{id}]` — index/show gate `governance-viewer,admin`;
store/update/destroy gate `admin`. Served by the standard `JsonApiController`
(friendly aliases over the auto-generated `/api/retention_policy` routes). The
admin SPA editor (`/classification/policies`) lists policies via
`useRetentionPolicies` and edits them with `SchemaForm` against the
`retention_policy` JSON-Schema. Nav entry under "Governance".

## Tests

- Unit: field package `tests/Unit/Classification/**`, `tests/Unit/Entity/RetentionPolicyTest.php`.
- Integration: `tests/Integration/PhaseClassificationRetention/ClassificationRetentionIntegrationTest.php` (FR-015) — composes inheritance + access end-to-end; the hold-block assertion is the C-004/FR-013 guard (removing the policy registration must fail it).
- Admin: `packages/admin/tests/unit/composables/useRetentionPolicies.test.ts` + `e2e/classification-policies.spec.ts` (deferred run).
