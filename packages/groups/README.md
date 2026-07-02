# waaseyaa/groups

**Layer 2 — Content Types**

Multi-bundle `group` content entity type for Waaseyaa applications. The package defines two entity types — `group` (the bundle-aware content entity, keyed by `gid`/`uuid`, labeled by `name`, partitioned by `type`) and `group_type` (the config entity that declares bundle identities) — and ships with **zero pre-registered bundles and zero bundle-scoped fields**. Consuming applications declare their own `GroupType` bundles and register bundle-scoped fields; those field values land in per-bundle subtables named `group__{bundle}`, while the core keys plus the `FieldStorage::Data` universals (`status`, `created_at`, `updated_at`) live on the base `group` table. The package is framework-agnostic: it targets no single product domain. See `docs/specs/bundle-scoped-storage.md`.

## API exposure (parked)

`group` and `group_type` are **not exposed over JSON:API**. `JsonApiRouteProvider`
auto-mounts CRUD routes for every registered entity type, so `/api/group*`
routes exist — but this package ships zero `AccessPolicyInterface`
implementations for either type. With no applicable policy,
`EntityAccessHandler` always returns Neutral, and `JsonApiController` treats
Neutral as denied (deny-by-default, not fail-open): every `/api/group*`
request is rejected for every account, including admins. Both types are also
registered with `discoverable: false`, so they are omitted from the `GET /api`
discovery index.

This is a deliberate PARK decision (audit-remediation batch 2026-07-02, WP5),
not an oversight — no consumer exercises group-over-API today. A consumer
that wants group-over-API must ship its own access policy; this package
intentionally does not provide one. Full route suppression (removing the
dead routes instead of just denying/hiding them) is deferred to the planned
entity-type `#[ContentEntityType(..., api: false)]` exposure flag, tracked in
issue #1871.

## Install

Ships as part of `waaseyaa/framework` — consumers of the metapackage already have it. To depend on it directly:

```bash
composer require waaseyaa/groups
```

The `GroupsServiceProvider` is auto-discovered via `extra.waaseyaa.providers`; no manual wiring is required.

## Key API

### `Group` (extends `ContentEntityBase`)
The `group` content entity. Entity keys: `id` -> `gid`, `uuid`, `bundle` -> `type`, `label` -> `name`, `langcode`.

```php
public function __construct(
    array $values = [],
    string $entityTypeId = '',
    array $entityKeys = [],
    array $fieldDefinitions = [],
);
public function getName(): string;          // alias for label()
public function setName(string $name): static;
public function getGroupTypeId(): string;   // the group_type bundle id (bundle())
```

### `GroupType` (extends `ConfigEntityBase`)
Config entity declaring a single bundle (e.g. `'business'`, `'organization'`). Holds bundle identity plus a `description`; bundle-scoped field definitions are registered separately. Entity keys: `id`, `label`.

```php
public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = []);
public function getLabel(): string;
public function getDescription(): string;
public function setDescription(string $description): static;
```

### `GroupsServiceProvider` (extends `ServiceProvider`)
Registers the `group` content entity type (via `EntityType::fromClass`) and the `group_type` config entity type.

```php
public function register(): void;
```

Register bundle-scoped fields against the `group` entity type through the framework's `EntityTypeManager`:

```php
public function addBundleFields(string $entityTypeId, string $bundle, array $fields): void;
```

## Usage

Declare a bundle's fields, then create and persist a group in that bundle. Bundle-scoped values route to `group__{bundle}` automatically.

```php
// 1. Register fields for the 'business' bundle (grounded in TwoBundleCoexistenceTest).
$entityTypeManager->addBundleFields('group', 'business', [
    new FieldDefinition(
        name: 'email',
        type: 'string',
        targetEntityTypeId: 'group',
        targetBundle: 'business',
    ),
]);

// 2. Materialize the schema (creates `group` + `group__business`).
//    The framework runs this on the next install / schema pass.

// 3. Create and save a group in that bundle.
$group = $storage->create([
    'uuid'     => 'a1b2...',
    'type'     => 'business',
    'name'     => 'Acme Co.',
    'langcode' => 'en',
    'email'    => 'hello@acme.example',
]);
$storage->save($group);

$loaded = $storage->load($group->id());
$loaded->get('email');   // 'hello@acme.example' — merged back from group__business
```

Querying a field that exists in more than one bundle without a `type` constraint raises `BundleAmbiguousFieldException` — narrow with `->condition('type', 'business')`. Registered-but-unmaterialized bundle subtables are reported by `waaseyaa health:check` as `MISSING_BUNDLE_SUBTABLE`.

Key classes: `Group`, `GroupType`, `GroupsServiceProvider`.
