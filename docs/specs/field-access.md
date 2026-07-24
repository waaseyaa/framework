# Field-Level Access

<!-- Spec reviewed 2026-07-24 - #2064 framework-owned field defaults: one shared default-classification source is consumed by both sealed runtime layout compilation and activation preflight. It covers universal structural selectors plus the exact first-party config labels, relationship infrastructure, parked media-version internals, and legacy User account-infrastructure fields listed below. Explicit metadata that disagrees with a framework default is a hard conflict. Application bundle fields and directory-exposure policy remain consumer-owned. -->
<!-- Spec reviewed 2026-07-21 - #2064 media hotfix: Media Protected fields now compose with the complete hydrated entity-view decision. Application contextual grants can release bundle-defined Protected media metadata, application Forbidden results still win, and a missing/mismatched hydrated entity fails closed. This does not change legacy open-by-default FieldAccessPolicyInterface filtering or Internal-field sealing. -->
<!-- Spec reviewed 2026-07-19 - #2064 alpha.270 boolean-field hotfix: resolved boolean/bool field definitions now canonicalize values to native PHP bool while the private entity value container is sealed and on every write. Closed validation, persistence extraction, guarded reads, and public projections observe that same type. Protected/Internal sealing and missing-context denial are unchanged. -->

<!-- Spec reviewed 2026-07-17 - #2064 WP1 adds dormant entity-boundary field-read contracts. The existing FieldAccessPolicyInterface remains unchanged and open-by-default for surface/edit filtering. ProtectedFieldReadPolicyInterface is a separate future fail-closed read seam over an immutable principal and structural subject view. No accessor or output behavior changes in WP1. Canonical contract: entity-field-read-boundary.md. -->

<!-- Spec reviewed 2026-07-04 - audit-remediation batch R7 WP1 (entity label/title field-access channel): the entity LABEL/TITLE (`EntityInterface::label()`) is not part of the `fields` bag `filterFields()`/`checkFieldAccess()` gate here — SSR's `<title>`, the schema.org JSON-LD `name`, and the Markdown H1 all read `label()` directly, bypassing this mechanism entirely. A viewable entity (entity-level access Allowed) whose label-key field was Forbidden still leaked the real label through all three. New `EntityAccessHandler::viewableLabel(EntityInterface, AccountInterface, EntityTypeManagerInterface): ?string` resolves the entity type's `label` entity-key field name and runs it through the SAME `checkFieldAccess()` this doc describes — open-by-default, `null` on Forbidden — so callers gate the label identically to any other field. `SsrPageHandler::handleRenderPage()` resolves it once and threads the result into the Twig `title` context var and `EntitySchemaOrgMapper::map()`'s `$labelOverride`; `EntityMarkdownPresenter::present()` calls it directly for the H1. All three fail closed to the entity type id (never the raw label) when Forbidden or when no access handler is wired. `RenderCache::SCHEMA_VERSION` bumped v3->v4 to invalidate pre-fix cached HTML. See CHANGELOG "Security". -->
<!-- Spec reviewed 2026-07-02 - audit-remediation batch R2 WP3 (api M3, schema field-access fails open on exception): GET /api/schema/{entity_type} builds a value-less prototype entity so SchemaPresenter can run field-access checks against it; SchemaPresenter skips its entire field-filtering block when the entity is null, so a prototype-construction exception (caught and swallowed) previously emitted a 200 with an UNFILTERED schema, over-disclosing restricted field definitions. Fixed in SchemaController::show(): the prototype is now seeded with a non-null placeholder for every declared field and entity key so constructor-strict types (UserBlock, engagement Comment/Reaction/Follow, messaging threads) construct and are filtered normally; and if construction STILL throws after seeding, it fails CLOSED with a 500 (no schema body) rather than emitting an unfiltered one. The open-by-default field-access mechanism documented here is unchanged; this only closes the schema surface's null-entity fail-open. Substantive contract in api-layer.md. -->
<!-- Spec reviewed 2026-06-23 - schema-surface auth (audit): no field-access semantics change. The REST schema surface that RENDERS field-access decisions — GET /api/schema/{entity_type} (and /api/openapi.json) — now requires authentication (BuiltinRouteRegistrar requireAuthentication()), because it computed field visibility against a value-less prototype entity and over-disclosed instance-state-gated field DEFINITIONS to anonymous (no row values; the JSON:API serializer still enforces per-record field access). The open-by-default field-access mechanism documented here is unchanged. Substantive contract: docs/specs/api-layer.md "Schema self-description surface requires authentication". -->

Field-level access control allows policies to restrict which fields a user can view or edit on entities. It is a companion to entity-level access, sharing the same handler and discovery infrastructure but with intentionally different semantics.

## Overview

- **Interface:** `FieldAccessPolicyInterface` in `packages/access/src/`
- **Handler:** `EntityAccessHandler` in `packages/access/src/` (same class that handles entity access)
- **Companion:** Classes implement both `AccessPolicyInterface` AND `FieldAccessPolicyInterface`
- **Discovery:** Same `#[AccessPolicy]` attribute; no separate discovery pipeline
- **Default:** Open-by-default. No field policies = all fields accessible.

## Asymmetric Semantics

Access result interpretation differs between entity-level and field-level checks. This asymmetry is intentional.

| Level | Check | Default | Meaning |
|-------|-------|---------|---------|
| Entity | `$result->isAllowed()` | Deny unless granted | Neutral = no policy granted = denied |
| Field | `!$result->isForbidden()` | Allow unless denied | Neutral = no policy denied = accessible |

Entity-level is deny-by-default: a policy must explicitly return `Allowed` for access to be granted. Field-level is open-by-default: access is granted unless a policy explicitly returns `Forbidden`.

```php
// Entity access check (deny-by-default):
$result = $handler->check($entity, 'view', $account);
if ($result->isAllowed()) { /* grant */ }

// Field access check (open-by-default):
$result = $handler->checkFieldAccess($entity, 'title', 'view', $account);
if (!$result->isForbidden()) { /* grant */ }
```

## FieldAccessPolicyInterface

**File:** `packages/access/src/FieldAccessPolicyInterface.php`
**Namespace:** `Waaseyaa\Access`

```php
interface FieldAccessPolicyInterface
{
    /**
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $fieldName The field name being checked.
     * @param string           $operation 'view' or 'edit'
     * @param AccountInterface $account   The account requesting access.
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation, // 'view' or 'edit'
        AccountInterface $account,
    ): AccessResult;
}
```

### Operations

| Operation | Meaning | Denial Effect |
|-----------|---------|---------------|
| `'view'` | Can the account see this field value? | Field omitted from JSON:API response |
| `'edit'` | Can the account modify this field? | 403 error if submitted in POST/PATCH; shown as disabled in admin form |

## Open-by-Default Design

When `EntityAccessHandler::checkFieldAccess()` runs:

1. Starts with `AccessResult::neutral('No field access policy provided an opinion.')`.
2. Iterates policies. Skips those where `appliesTo()` returns false.
3. Skips policies that do not implement `FieldAccessPolicyInterface` (uses `instanceof`).
4. Calls `$policy->fieldAccess(...)` on qualifying policies.
5. Combines with `orIf()`. Forbidden short-circuits.
6. Returns result.

When no policy implements `FieldAccessPolicyInterface` for the entity type, the result is Neutral. Neutral is not Forbidden, so all fields pass through. This ensures zero behavioral change when no field policies exist.

This legacy presentation/edit filtering is distinct from accessor-level Protected reads. A first-party `ProtectedFieldReadPolicyInterface` may implement the internal `EntityViewProtectedFieldReadPolicyInterface` marker. At that point the handler requires the exact hydrated entity and composes the field opinion with the complete entity-level `view` decision. Field Forbidden and any entity Forbidden remain deny-overrides-allow; Neutral is released only by an Allowed entity view. Without the matching entity the read is denied. Media uses this mechanism for core and application-defined Protected fields, allowing a contextual consumer media policy to govern serialized API/admin metadata consistently with downloads and other entity-level views.

## Framework-owned default classifications

Applications classify fields they add. They do not repeat classifications for
storage and account infrastructure defined by first-party framework packages.
`FrameworkFieldReadDefaults` is the single source consumed by both
`EntityReadRuntime` and `FieldAccessPreflightScanner`; a green preflight can
therefore never describe a different level than sealed runtime compilation.
An explicit definition or application artifact may restate a default only at
the same level. A disagreement is a hard conflict.

All registered entity types receive Public `bundle`, `langcode`, and
`default_langcode` structural defaults, matching the runtime's existing
structural treatment. The remaining exact defaults are:

| Entity fields | Default | Newly readable channel and role | Safety basis |
|---|---|---|---|
| Config labels: `classification_label_definition.display_name`, `group_type.label`, `group.name`, `media_type.label`, `menu.label` | Public | Ordinary entity/API presentation after entity-level view succeeds | These are the framework-defined human labels used to identify already-viewable configuration or groups; they contain no credential, membership, or storage authority. |
| `retention_policy.name` | Protected | Only a principal admitted by the classification-retention governance policy | Even its operator-facing label identifies governance configuration, so the default preserves the field's existing Protected level instead of broadening it. |
| `relationship.{confidence,directionality,end_date,from_entity_id,from_entity_type,notes,source_ref,start_date,status,to_entity_id,to_entity_type,weight}` | Protected | Only an account allowed by the relationship Protected-read policy and entity view | These values describe relationship topology and lifecycle. They are never anonymous-by-default and remain subject to endpoint/entity visibility. |
| `media_version.{blob_uri,created_at,created_by,label,media_uuid,mime,sha256,size,vid}` | Internal | No account-facing role or channel | Parked content-addressed storage metadata is available only to typed, audited system/admin infrastructure; ordinary and administrator API reads cannot release it. |
| `user.password_hash`, `user.role` | Internal | No account-facing role or channel | Credential and authorization material never enters an outward projection. `password_hash` is also covered by serializer/schema deny floors, including administrator requests. |
| `user.{consent_date,consent_on_file,must_reset_password,disabled}` | Protected | Authenticated principals with `administer users`, after User entity view | These are administrative account-state facts. The exact User policy rejects every other role and any unexpected policy-subject input. |

User `display_name`, `first_name`, `last_name`, and
`member_directory_visible` intentionally have no framework default. Directory
exposure is application policy. The canonical framework login identifier
`name` retains its existing Protected profile policy; it is not a consumer
directory-field default and is not widened by this table.

```php
// EntityAccessHandler::checkFieldAccess() excerpt:
$result = AccessResult::neutral('No field access policy provided an opinion.');
foreach ($this->policies as $policy) {
    if (!$policy->appliesTo($entityTypeId)) { continue; }
    if (!$policy instanceof FieldAccessPolicyInterface) { continue; }
    $policyResult = $policy->fieldAccess($entity, $fieldName, $operation, $account);
    $result = $result->orIf($policyResult);
    if ($result->isForbidden()) { return $result; }
}
return $result;
```

### Bulk Filtering

`EntityAccessHandler::filterFields()` is a convenience method:

```php
public function filterFields(
    EntityInterface $entity,
    array $fieldNames,   // string[]
    string $operation,   // 'view' or 'edit'
    AccountInterface $account,
): array // string[] -- fields not forbidden
```

Implementation: filters via `!$this->checkFieldAccess(...)->isForbidden()`.

## Intersection Types for Policies

Policy classes must implement both interfaces to participate in field access checks. A class that only implements `AccessPolicyInterface` is skipped for field checks. A class that only implements `FieldAccessPolicyInterface` would never be registered (policies are typed as `AccessPolicyInterface[]`).

```php
#[AccessPolicy(id: 'node_access', entityTypes: ['node'])]
final class NodeAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // entity-level logic
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // create-level logic
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        // field-level logic
        if ($fieldName === 'status' && !$account->hasPermission('administer nodes')) {
            return AccessResult::forbidden('Only administrators can edit the status field.');
        }
        return AccessResult::neutral();
    }
}
```

The `appliesTo()` method from `AccessPolicyInterface` scopes both entity-level and field-level access to the same entity types. For multi-bundle entity types, policies may additionally narrow scope to specific bundles via the `#[AccessPolicy(bundles: [...])]` attribute parameter; see [`bundle-scoped-fields.md`](./bundle-scoped-fields.md#access).

### Real-world example: ownership-field locks

`NodeAccessPolicy` (`packages/node/src/NodeAccessPolicy.php`) forbids edit of `uid`/`type`/`created`/`changed` on an *existing* node for non-admins, closing a mass-assignment path where an author with `edit own {type} content` could reassign authorship, change the bundle, or forge timestamps via `PATCH`. Those fields stay settable at create time (`uid`/`created` are part of authoring a new node).

`EngagementAccessPolicy` (`packages/engagement/src/EngagementAccessPolicy.php`) applies the same pattern to `user_id` on `reaction`/`comment`/`follow` entities, but stricter: `user_id` is server-authoritative and never client-reassignable. On an *existing* entity it is edit-Forbidden outright — ownership is immutable after creation, not just admin-only-editable. On *create* it is edit-Forbidden unless the submitted `user_id` equals the caller's own account id. This closes an anonymous-ownership hole: `EngagementAccessPolicy` had no field policy at all, so any authenticated account could `POST` a comment with `user_id: 0` (or another account's id), minting a row "owned" by the anonymous account — which, combined with a missing `isAuthenticated()` guard in the entity-level `isOwner()` check, let every anonymous visitor `DELETE` or view-as-owner any row with `user_id === 0` (`AnonymousUser::id()` also returns `0`).

### Real-world example: permission-gated publication (CW-v1 WP-0)

`NodeAccessPolicy::fieldAccess()` also edit-Forbids `status`/`workflow_state` for any account lacking `NodeAccessPolicy::PUBLISH_PERMISSION` (`'use editorial transition publish'`) — a different shape than the ownership-field lock above: it applies on create AND update alike (no `isNew()` carve-out, since the concern is "may this account publish at all", not "may this account rewrite history"). See `docs/specs/api-layer.md`'s CW-v1 WP-0 entry for the companion `JsonApiController::store()` unpublished-floor that keeps a born-published entity constructor default (e.g. `Node`) from bypassing this gate on create.

## View vs Edit Denial

### JSON:API Serialization (ResourceSerializer)

**File:** `packages/api/src/ResourceSerializer.php`

When access context is provided, `serialize()` omits view-denied fields from the attributes object:

```php
public function serialize(
    EntityInterface $entity,
    ?EntityAccessHandler $accessHandler = null,
    ?AccountInterface $account = null,
): array
```

- View-denied field: omitted entirely from response attributes.
- Edit-denied field: still included in view response (edit denial only affects mutation).

### Schema Generation (SchemaPresenter)

**File:** `packages/api/src/Schema/SchemaPresenter.php`

When access context is provided, `present()` annotates the JSON Schema:

```php
public function present(
    EntityTypeInterface $entityType,
    array $fieldDefinitions = [],
    ?EntityInterface $entity = null,
    ?EntityAccessHandler $accessHandler = null,
    ?AccountInterface $account = null,
): array
```

- View-denied fields: removed from schema entirely (frontend never sees them).
- Edit-denied fields: marked `readOnly: true` with `x-access-restricted: true`.

### `x-access-restricted` Extension

`x-access-restricted: true` is a JSON Schema extension that signals the admin SPA to show the field as a disabled widget. This is distinct from system `readOnly` (used for `id`, `uuid`) which hides the field from forms entirely.

```json
{
  "properties": {
    "status": {
      "type": "boolean",
      "readOnly": true,
      "x-access-restricted": true
    }
  }
}
```

Frontend behavior:
- System `readOnly` without `x-access-restricted`: field hidden from edit forms.
- `readOnly` with `x-access-restricted`: field shown as disabled widget (user can see value but not change it).

### JSON:API Controller (JsonApiController)

**File:** `packages/api/src/JsonApiController.php`

- GET (index/show): passes access context to serializer; view-denied fields omitted.
- POST (store) / PATCH (update): checks edit access for each submitted field before applying. Returns 403 JSON:API error if any submitted field is edit-denied.

## Paired Nullable Parameters

`ResourceSerializer::serialize()` and `SchemaPresenter::present()` accept `?EntityAccessHandler` + `?AccountInterface`. Both must be non-null or both null -- only two of four states are meaningful.

```php
// Correct guard pattern:
if ($accessHandler !== null && $account !== null) {
    $viewableFields = $accessHandler->filterFields($entity, $fieldNames, 'view', $account);
}
```

When both are null, no field filtering occurs and the full entity/schema is returned.

## Wiring

**File:** `public/index.php`

The front controller creates the access handler and passes it through the call chain:

```php
$account = $httpRequest->attributes->get('_account');
$accessHandler = new EntityAccessHandler([]);
// Pass to JsonApiController constructor (already accepts optional params)
// Pass to SchemaController constructor (accepts optional params)
```

For `SchemaController`, a prototype entity is created for policy evaluation:

```php
$class = $entityType->getClass();
$prototypeEntity = new $class([]); // User/Node accept (array $values)
$schema = $schemaPresenter->present($entityType, $fields, $prototypeEntity, $accessHandler, $account);
```

With no policies registered, `EntityAccessHandler` returns Neutral for all fields. Field-level semantics (`!isForbidden()`) means all fields pass through unchanged.

## Testing Field Access

### Anonymous Classes for Intersection Types

PHPUnit `createMock()` cannot mock intersection types. Use real anonymous classes:

```php
$policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        if ($fieldName === 'secret_field' && $operation === 'view') {
            return AccessResult::forbidden('Restricted.');
        }
        return AccessResult::neutral();
    }
};

$handler = new EntityAccessHandler([$policy]);
```

### Testing Patterns

- Test that Neutral from `checkFieldAccess()` passes `!isForbidden()` (open-by-default).
- Test that Forbidden from any policy short-circuits.
- Test that policies not implementing `FieldAccessPolicyInterface` are skipped.
- Test `filterFields()` with mixed access results.
- Avoid double `$storage->create()` in access checks: when checking field access before persisting a new entity, create once and reuse for both the access check and the save.

### Unit Test Locations

```
packages/access/tests/Unit/FieldAccessPolicyTest.php
packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php
packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php
packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php
packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php
tests/Integration/Phase6/FieldAccessIntegrationTest.php
```

## File Reference

```
packages/access/src/
    FieldAccessPolicyInterface.php   - Field access policy contract
    AccessPolicyInterface.php        - Entity access policy contract (companion)
    AccessResult.php                 - Tri-state value object
    EntityAccessHandler.php          - checkFieldAccess(), filterFields(), viewableLabel()

packages/api/src/
    ResourceSerializer.php           - Omits view-denied fields
    JsonApiController.php            - Checks edit access on mutations
    Schema/
        SchemaPresenter.php          - x-access-restricted annotation

packages/admin/app/
    composables/useSchema.ts         - Reads x-access-restricted
    components/schema/SchemaForm.vue - Disabled prop for restricted fields
    components/schema/SchemaField.vue - Passes disabled to widgets

public/index.php                     - Wires access context into controllers
```
