# Classification + Retention Engine — Field-Type Labels, Inheritance, Retention-Policy Entity, Purge/Redact/Hold Jobs

**Mission:** `classification-retention-engine-01KSEFTH`
**Target branch:** `main`
**Tracks:** No GitHub issue (Wave 2 framework substrate). Closes gap-matrix row **A4**; alpha-to-beta-plan §1 substrate item **#4**. Charter directives: **DIR-004** (OCAP-by-architecture), **DIR-005** (two-axis storage preservation), **DIR-006** (codified gates).
**Pattern reference (CANONICAL):** existing `packages/field` field-type system (`FieldTypeInterface`, `Form/`, `BundleTemplate.php`, `FieldTemplate.php`) for the classification-label field type; `packages/scheduler` (`ScheduleEntriesInterface`, `ScheduledTask`) for the purge/redact/hold jobs; existing `packages/access` entity-level + field-level policy interfaces for the access composition (`AccessPolicyInterface`, `FieldAccessPolicyInterface`, the intersection-type pattern documented in `docs/specs/field-access.md`).
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` (this cluster's first mission) — MUST be merged before this mission enters implementation. Purge/redact/hold actions write `retention.purge` / `retention.redact` / `retention.hold` records via `AuditWriterInterface` from the audit substrate; classification changes write `classification.change` records.

## Why this mission exists

Per the inventory in gap-matrix row A4: today policies can be modelled as config entities, but there is **no retention enforcement runtime** (no purge jobs, no redaction, no holds), **no per-record classification labels as a first-class field**, and **no label inheritance** from parents to children. OCAP doctrine (DIR-004) requires that classified data be governed at field granularity — a folder's confidentiality cascades to its files; a community-elder interview's hold flag blocks every export, summary, or AI tool that touches it. Without a first-class classification-label field type, every downstream surface (Drive, Docs, Forms, Data Rooms) reinvents its own ad-hoc tagging — guaranteeing drift between surfaces and procurement-hostile audit gaps.

This mission ships the substrate: a `classification_label` field type extending typed-data, label inheritance rules (parent → child unless child overrides — `inherited_from` field records the propagation chain for audit), a `RetentionPolicy` entity matching labels to actions (purge / redact / hold) and triggers (age-based / event-based), and scheduled jobs that execute the policies. The Admin SPA editor for policies (WP04) is schema-driven on top of the policy entity.

Three Wave 2+ missions compose on this: `per-record-ai-access-flagship-*` reads classification labels in `FieldAccessPolicyInterface::fieldAccess()` to redact for AI tools; `versioned-blob-media-abstraction-01KSEFTJ` inherits labels from parent entities to media versions; `offline-first-sync-substrate-01KSEFTM` filters offline reads by classification (per design-offline-first.md §7 "OCAP Integration / Offline Reads").

## Scope

### In scope

**Layer 1 — `packages/field` extension:**
- New field type `classification_label` (string-backed enum + metadata). Registered via `FieldTypeInterface`. Schema columns added to host entity: `classification_label` (string, indexed), `classification_inherited_from` (string `entity_type:uuid`, nullable), `classification_overridden_at` (datetime, nullable — non-null means an explicit override was made; null means inherited live).
- Field metadata declared via `#[BundleTemplate]` / `#[FieldTemplate]` attributes per CLAUDE.md orchestration row for `packages/field/src/Attribute/`. Class-level `@api`.
- Label catalogue: a separate `ClassificationLabelDefinition` entity (a label IS data, not a code constant — Nations need to define their own labels without a framework release). Fields: `id`, `uuid`, `label_id` (string, machine name), `display_name`, `confidentiality_level` (int, 0..10 — `0`=public, `10`=most restricted), `default_actions` (`purge`|`redact`|`hold`-allowed array; informational — actual actions live on `RetentionPolicy`), `created_at`.
- A small bundled seed of canonical labels in `packages/field/defaults/classification-labels.yaml` (loadable via `bin/waaseyaa config:import`): `public`, `internal`, `confidential`, `restricted`, `nation-confidential`, `nation-sacred`, `hold-legal`, `hold-research`, `hold-ethics-review`. Nations override or extend via their own config import.

**Layer 1 — `packages/field` label inheritance:**
- New `Waaseyaa\Field\Classification\LabelInheritanceResolver` (`@api`). Given an entity, resolves: (1) the entity's own `classification_label` if `classification_overridden_at IS NOT NULL`, else (2) traverse to the entity's parent (the parent relationship is configurable per entity type via a `ClassificationParentResolverInterface`), recurse, return the parent's label + record the source `entity_type:uuid` in `inherited_from`.
- `ClassificationParentResolverInterface` (`@api`) — implementations register per entity type. Stock implementations: `MediaParentResolver` (media's parent entity), `NodeParentResolver` (node's parent — currently usually none, but composes for future hierarchical content), `AttachmentParentResolver` (attachment's parent entity). Registered via service provider tags.
- Storage hook: on save, `EntityLifecycleSubscriber` (in `packages/field`) computes the effective label via `LabelInheritanceResolver`, writes it to `classification_label` + `classification_inherited_from`. If the user explicitly set the label (form submit set `classification_overridden_at`), the resolver respects the override and propagates downward (children of this entity re-evaluate their labels on their next save — async on next-write, NOT recursively today; this is bounded scope per Risks).

**Layer 1 — `packages/field` field-level access composition (classification → access):**
- `Waaseyaa\Field\Classification\ClassificationFieldAccessPolicy` (`@api`) `implements FieldAccessPolicyInterface`. Honours `docs/specs/field-access.md` semantics (open-by-default, `Forbidden` only restricts). Reads the entity's effective classification label; consults the requesting account's clearance via `ClassificationClearanceCheckerInterface`. If clearance is below confidentiality level → `AccessResult::forbidden()`. Otherwise `neutral()`.
- `ClassificationClearanceCheckerInterface` (`@api`) — accept account → return `int` clearance level. Stock impl: `RoleBasedClearanceChecker` mapping roles to levels (e.g. `admin` → 10, `nation-steward` → 9, `editor` → 5, `viewer` → 1). Configurable via `classification.role_clearance` config key.
- Hold semantics: if the label has `hold-*` semantics (any label whose `label_id` starts with `hold-`), the policy returns `forbidden()` regardless of clearance UNLESS the requesting account has the `legal-hold-bypass` permission. Hold overrides every other policy — even an admin without explicit bypass cannot read held data.

**Layer 1 — `packages/field` new entity: `RetentionPolicy`:**
- `RetentionPolicy` entity. Fields: `id`, `uuid`, `name`, `applies_to` (array of `label_id` matched — supports glob, e.g. `nation-*`), `action` (`purge` | `redact` | `hold-flag`), `trigger_kind` (`age_based` | `event_based`), `trigger_value` (ISO-8601 duration for age-based; event-kind string for event-based, e.g. `audit:access.denied` to redact records that triggered a denial — implementation hook only, NOT a primary case at this layer), `exemptions` (array of `entity_type:uuid` pairs that bypass this policy), `created_at`. `@api`.
- The policy entity composes on the `AuditRetentionPolicy` from `ocap-audit-log-substrate-01KSEFTF` (which governs only audit-log retention, kind-pattern + age). The classification `RetentionPolicy` extends that semantic to non-audit entities by adding `applies_to: label_id` matching and the `redact` + `hold-flag` actions. Documented as a deliberate split in spec.md §"Decisions pre-resolved".

**Layer 0 — `packages/scheduler` jobs:**
- `Waaseyaa\Field\Schedule\ClassificationRetentionScheduleEntries` `implements ScheduleEntriesInterface` (`@api`). `register()` declares:
  - `classification.retention.purge` — every 6 hours. Reads all `RetentionPolicy` entities with `action=purge` and `trigger_kind=age_based`; for each, find matching entities (by label) older than the trigger; mark for deletion via the framework's entity deletion path (which fires `entity.delete` events that the audit substrate's `EntityLifecycleAuditListener` catches → `entity.delete` audit record). Then write a `retention.purge` audit record per entity deleted (separate event kind documenting the policy that triggered the deletion).
  - `classification.retention.redact` — every 6 hours. For each `redact` policy: load entities; for each, blank/null user-data fields (specifically: every field marked `pii: true` in its field-template metadata) but PRESERVE the entity, the `_data` blob structure, and the audit trail. Write a `retention.redact` audit record.
  - `classification.retention.hold_scan` — daily. For each `hold-flag` policy: query entities matching the label; ensure each carries the `hold` semantic via the access policy. Mostly a verification job — hold-flagged data is blocked at access-time by `ClassificationFieldAccessPolicy`, not at storage-time. Reports any hold-policy targets that ALSO carry a `purge` policy (which would be a misconfiguration — `hold` MUST win over `purge`; the scan emits a `notice` log + writes a `classification.change` audit event flagging the conflict for admin review).

**Layer 6 — `packages/admin` SPA policy editor (WP04):**
- New page `app/pages/classification/policies/index.vue` (list) + `[id].vue` (detail/edit). Mirrors the schema-driven `SchemaForm` approach (per CLAUDE.md orchestration row for `packages/admin` and the existing workflow-definition editor pattern).
- New composable `app/composables/useRetentionPolicies.ts` mirroring `useQueueJobs.ts` shape — `{policies, loading, error, fetchPolicies(), savePolicy(), deletePolicy()}`.
- Nav entry under "Governance" group (mirror `/queue`).
- i18n keys in `app/i18n/en.json`.
- Vitest unit test + Playwright smoke (deferred-run per lane-worktree).

**Layer 4 — `packages/api`:**
- `GET /api/classification/policies` (admin) — list. `GET /api/classification/policies/{id}` — single. `POST /api/classification/policies` — create. `PATCH /api/classification/policies/{id}` — update. `DELETE /api/classification/policies/{id}` — delete.
- Implemented via the framework's standard JSON:API entity controllers (`JsonApiController`) — no bespoke controller needed beyond the entity-type registration (per CLAUDE.md §"Adding an API endpoint").
- Route options: `_role: admin` for write actions, `_role: governance-viewer` (new role) for read.

**Docs:**
- `docs/specs/classification-and-retention.md` — new spec file.
- `CLAUDE.md` orchestration table — add row for `packages/field/src/Classification/*` → `docs/specs/classification-and-retention.md`.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

### Out of scope (→ separate missions)

- **Indigenous-language label translation pipeline** — covered by the future `f6-indigenous-language-translation-*` mission (alpha-to-beta-plan §1 item #25). This mission ships English-only label display strings; `display_name` becomes a translatable field once the translation pipeline lands.
- **AI-aware classification** (the `per-record-ai-access-flagship-*` flagship mission consumes labels via `FieldAccessPolicyInterface::fieldAccess()` — that wiring is part of THAT mission, not this one). This mission ships the substrate; the flagship mission ships the AI-tool integration.
- **Recursive downward label cascade on parent change.** When a parent entity's label changes, child entities re-evaluate their effective label on their NEXT save, not eagerly. A future "cascade-now" admin action is deferred. Documented in Risks.
- **Event-based retention triggers beyond a stub.** `trigger_kind=event_based` is reserved in the schema but only `trigger_kind=age_based` is implemented in WP03 scheduled jobs. Event-based triggers compose later.
- **External signed export of retention-policy state.** Internal-only.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | A `classification_label` field type is implemented in `packages/field` per CLAUDE.md §"Adding an entity type" / field-type conventions. It extends typed-data with metadata: confidentiality level, hold-flag, retention-target. Field is auto-discovered via the existing field-type registration mechanism. |
| FR-002 | functional | A `ClassificationLabelDefinition` entity is registered. The bundled seed YAML (`packages/field/defaults/classification-labels.yaml`) loads via `config:import` and seeds the nine canonical labels enumerated in spec.md §In-scope. |
| FR-003 | functional | `LabelInheritanceResolver::resolve(EntityInterface $entity): ClassificationDecision` returns the effective label + the `inherited_from` provenance. If the entity has `classification_overridden_at IS NOT NULL` → its own label. Else, traverse up via `ClassificationParentResolverInterface` registered for the entity type; recurse to a terminal (no parent) or a labelled ancestor; return that label + provenance. |
| FR-004 | functional | On entity save (via `EntityLifecycleSubscriber`), the effective label + `inherited_from` are computed and persisted. If the user explicitly set `classification_label` in the request, `classification_overridden_at` is set to `now()` and `inherited_from` is set to `null`. If the user did NOT set it, the resolver computes from the parent and stores `inherited_from = parent_type:parent_uuid`. |
| FR-005 | functional | `ClassificationFieldAccessPolicy implements FieldAccessPolicyInterface` is auto-discovered via `#[PolicyAttribute(entityType: '*')]` (or the framework's policy-registration mechanism — verify exact mechanism in `packages/access`). Honours `docs/specs/field-access.md` semantics: open-by-default, Forbidden only. Returns `forbidden()` when account clearance < label confidentiality level, or when label has `hold-*` semantics and account lacks `legal-hold-bypass` permission. |
| FR-006 | functional | `ClassificationClearanceCheckerInterface` ships with a `RoleBasedClearanceChecker` default. Mapping is config-driven via `classification.role_clearance`. Default mapping documented in spec.md / docs/specs file: admin→10, nation-steward→9, editor→5, viewer→1, anonymous→0. |
| FR-007 | functional | A `classification.change` audit event is recorded (via `AuditWriterInterface` from `ocap-audit-log-substrate-01KSEFTF`) whenever an entity's effective classification label changes — on initial assignment, on explicit override, or on inheritance recompute that results in a different label than the prior persisted value. |
| FR-008 | functional | `RetentionPolicy` entity is registered with the field schema documented in spec.md §In-scope. JSON:API CRUD is available via the framework's standard entity controllers; route options enforce `_role: admin` for write and `_role: governance-viewer` for read. |
| FR-009 | functional | `ClassificationRetentionScheduleEntries implements ScheduleEntriesInterface` declares three scheduled tasks: `classification.retention.purge` (6-hourly), `classification.retention.redact` (6-hourly), `classification.retention.hold_scan` (daily). Each task is discoverable via `bin/waaseyaa schedule:list`. |
| FR-010 | functional | The purge job: for each `action=purge, trigger_kind=age_based` policy, finds entities with matching labels older than the trigger duration, deletes them via the entity repository (fires `entity.delete`), and writes one `retention.purge` audit event per deletion with attributes `{policy_id, label_id, entity_type, entity_uuid}`. Exemptions matrix is honoured. |
| FR-011 | functional | The redact job: for each `action=redact` policy, finds entities matching the label, nulls every field marked `pii: true` in field-template metadata (preserves entity ID, audit trail, label, structural fields). Writes one `retention.redact` audit event per entity redacted with attributes `{policy_id, label_id, redacted_fields}`. |
| FR-012 | functional | The hold-scan job: emits a `notice` log + writes a `classification.change` audit event with attributes `{conflict: 'hold_vs_purge', held_entity}` whenever an entity carries both a `hold-*` label AND a matching `purge` policy. Does NOT delete. |
| FR-013 | functional | Hold semantics override every other access policy. An entity carrying a `hold-*` label MUST return `AccessResult::forbidden()` from `ClassificationFieldAccessPolicy::fieldAccess()` for any account lacking `legal-hold-bypass`, even an admin. Verified by an integration test seeding a `hold-legal` entity + an admin account WITHOUT the bypass permission. |
| FR-014 | functional | Admin SPA: `/admin/classification/policies` page lists policies via `useRetentionPolicies`. `/admin/classification/policies/{id}` detail/edit page uses `SchemaForm` against the policy entity's JSON-schema. Nav entry under "Governance". Vitest unit test for the composable. Playwright smoke spec for the list page (deferred run per lane-worktree). |
| FR-015 | functional | A **kernel-boot integration test** seeds three labels (`public`, `confidential`, `hold-legal`), one parent + two children (one child overrides, one inherits), one purge policy + one hold policy. Asserts: (a) inheritance correctly cascades on first save; (b) override correctly persists; (c) `ClassificationFieldAccessPolicy` blocks anonymous reads of `confidential`; (d) `legal-hold-bypass` admin can read `hold-legal` but a non-bypass admin cannot; (e) running the purge job removes age-eligible `public`-labelled entities and writes the matching `retention.purge` audit events. Dead-code guard: removing the `ClassificationFieldAccessPolicy` registration MUST cause this test to fail (the hold-block assertion fails closed). |
| FR-016 | functional | `docs/specs/classification-and-retention.md` is created and cross-referenced from CLAUDE.md orchestration table. `CHANGELOG.md` `[Unreleased]` → **Added** records the new substrate. |
| NFR-001 | non-functional | DIR-005 honoured: classification fields compose on the existing two-axis storage substrate (`docs/specs/entity-storage-two-axis.md`). For translatable entities, the classification label lives on the non-translatable axis (a label is metadata about the entity, not about a specific language version). `bin/check-package-layers` stays green. |
| NFR-002 | non-functional | DIR-004 honoured: every classification change is audit-logged (FR-007); every retention action is audit-logged (FR-010, FR-011). Hold overrides every other policy at access time (FR-013). |
| NFR-003 | non-functional | DIR-006 honoured: codified gates stay green. Specifically `bin/check-dead-code` (every public field-type / interface / policy carries `@api`), `bin/check-getquery-bindings` (no new unbound `getQuery()` chains), `bin/check-composer-policy` (internal dependencies pinned to the current tag literal). |
| NFR-004 | non-functional | The purge / redact / hold-scan jobs are best-effort (wrap each policy's iteration body in try-catch + log via `LoggerInterface`); a failing policy MUST NOT prevent other policies from executing in the same scheduled run. |
| NFR-005 | non-functional | A first-time configuration import of `classification-labels.yaml` is idempotent — re-running `config:import` doesn't duplicate label definitions. |
| C-001 | constraint | This mission depends on `ocap-audit-log-substrate-01KSEFTF` — that mission MUST be merged before WP01 begins. WP01 imports `Waaseyaa\Audit\Contract\AuditWriterInterface` for the classification-change + retention audit events. |
| C-002 | constraint | The classification-label field type and the retention-policy entity ship in `packages/field` (Layer 1). They MUST NOT introduce Layer-1 dependencies on higher layers (`bin/check-package-layers` green). |
| C-003 | constraint | Recursive downward cascade on parent label change is OUT OF SCOPE (see Out of scope and Risks). Child entities re-evaluate their effective label on their NEXT save, not eagerly. |
| C-004 | constraint | Hold semantics (`hold-*` labels) override every other access policy at access time. Even an admin without `legal-hold-bypass` MUST be blocked. This is non-negotiable and is the FR-013 verification. |
| C-005 | constraint | Translatable entities: the classification label lives on the non-translatable axis (NFR-001). DIR-005 forbids dropping the two-axis substrate; the label is metadata about the entity, not the language version. |

## Acceptance

- All 16 FRs and 5 NFRs met; all 5 constraints honoured.
- Gates green: `vendor/bin/phpunit`, `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `bin/waaseyaa schedule:list` lists the three classification-retention tasks.
- `bin/waaseyaa config:import packages/field/defaults/classification-labels.yaml` is idempotent (NFR-005).
- Integration test `ClassificationRetentionIntegrationTest` (FR-015) passes; reviewer verifies the hold-block assertion (FR-013) fails when the `ClassificationFieldAccessPolicy` registration is removed.
- Admin SPA: `cd packages/admin && npm test && npm run typecheck && npm run lint` green; the policy editor renders the policy schema from `useRetentionPolicies` data.
- Reviewer (Opus) confirms: (a) label inheritance is correctly recorded with `inherited_from` provenance; (b) hold overrides every other policy at access time; (c) retention jobs are best-effort (a thrown exception in one policy iteration doesn't prevent the next).

## Risks

- **Recursive downward cascade explosion.** If a parent's label change eagerly cascaded to thousands of descendants, the save would block. Mitigation: C-003 deferred eager cascade; documented as a future admin "cascade-now" action. Children re-evaluate on next write.
- **Clearance-checker config drift.** Roles change over a Nation's deployment life. Mitigation: `classification.role_clearance` is config-driven (FR-006); changes go through `config:export` → `config:import` and are audit-logged (via the entity-lifecycle listener from the audit substrate).
- **Hold-vs-purge misconfiguration.** A policy author could attach both a `hold-legal` label AND match the entity in a `purge` policy. Mitigation: FR-012 daily hold-scan job detects this and surfaces it for admin attention; FR-013 ensures hold wins at access-time even if purge runs (although the integration test in FR-015 also seeds this conflict to verify hold-scan emits the conflict event).
- **Performance of label-resolution on save.** Every save now traverses the parent chain. Mitigation: cache the resolved label per-entity (the `classification_label` + `classification_inherited_from` columns ARE the cache). Re-resolution only happens on save; loaded entities use the persisted column directly.
- **PII-field discovery.** The redact job must know which fields are PII. Mitigation: rely on a `pii: true` metadata flag in the existing `#[FieldTemplate]` attribute; document the convention; do NOT attempt to redact fields without explicit marking. (A separate audit mission can sweep the codebase later to mark fields exhaustively.)

## Decisions pre-resolved

- **Classification labels are field-type extensions of typed-data, NOT a separate entity type.** Per "Pre-resolved decisions" in the cluster context block. Reasons: cleaner integration with existing `FieldAccessPolicyInterface`, no new entity-type registration burden per host entity, label is metadata-about-data not data-about-the-Nation. The `ClassificationLabelDefinition` IS an entity (because labels are nation-configurable data) but the per-record assignment uses the field-type slot.
- **Retention-policy entity is separate from `AuditRetentionPolicy` (which lives in `packages/audit`).** Two policy entities, two distinct semantics: audit-side governs audit-log retention by kind + age; classification-side governs entity retention by label + action (purge/redact/hold). Documented split avoids overloading one entity with mismatched semantics.
- **Hold overrides everything at access time (C-004).** Not at storage time. Hold-flagged data is NOT deleted, NOT redacted — it remains physically present and the access policy forbids reads. This preserves legal/research/ethics-review traceability.
- **Bundled label seed YAML, not hardcoded enum.** Nations configure their own labels post-install. The nine bundled labels are seed values via `config:import`, not constitutional defaults.
- **Schedule cadence: 6-hourly purge/redact, daily hold-scan.** Configurable in WP03 via the standard `schedule.entries.<task>.cron` config key per `packages/scheduler` conventions. Hold-scan is daily because it's verification-only (no destructive action).

## Decisions deferred to implementer

- **The exact entity-type-to-parent-resolver registration mechanism.** WP01 ships `ClassificationParentResolverInterface` + three stock impls. The registration mechanism (tagged services? service-provider `register()` block? attribute-based?) follows whatever pattern is canonical in `packages/access` for `PolicyAttribute` registration. Implementer reads `packages/access` first and mirrors.
- **The exact "PII field marking" convention.** WP01 documents `#[FieldTemplate(..., pii: true)]` as the canonical marker. If the existing `FieldTemplate` attribute does not have a `pii` parameter, implementer extends it (additive — backward compatible since `pii` defaults to `false`).
- **Whether `ClassificationFieldAccessPolicy` registers as `entityType: '*'` (matches every entity) or per-entity-type.** Prefer `entityType: '*'` (the policy is universal — every entity that has a classification label gets the policy; entities without one are unaffected per open-by-default semantics).
- **Whether to ship the `legal-hold-bypass` permission as a built-in or as a config-imported role-permission mapping.** Prefer built-in: define the permission constant in `packages/field/src/Classification/Permissions.php`; ship in default role catalogue via `config:import`.

Decision preference order per DIR-006: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- This mission MUST merge before `per-record-ai-access-flagship-*` enters implementation: that mission's `FieldAccessPolicyInterface` integration in `AgentToolInterface::execute()` consumes the labels this mission ships.
- This mission SHOULD merge before `versioned-blob-media-abstraction-01KSEFTJ` enters its WP02 (label inheritance is consumed by media-version storage to inherit labels from the parent entity).
- This mission MUST merge before `offline-first-sync-substrate-01KSEFTM` enters WP03 (classification-aware conflict resolution for governed data uses these labels).
- If a future mission needs eager downward cascade, it should be filed as a separate `classification-cascade-now-*` mission with its own job-queue infrastructure (likely composes on `packages/queue`).
