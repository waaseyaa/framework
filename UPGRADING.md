# Upgrading

## Unreleased

### MCP identity and Registry configuration are now separate

`mcp.server_card` no longer owns `name` or `version`, and no longer accepts
`registry_name`, `repository_url`, `website_url`, or `schema_url`. Move shared
identity to `mcp.implementation` and official Registry inputs to
`mcp.registry`:

```php
'mcp' => [
    'implementation' => [
        'name' => 'Waaseyaa',
        // Optional. A framework checkout uses VERSION; an installed site uses
        // the installed waaseyaa/mcp package version when this is absent.
        'version' => '0.1.0-alpha.286',
    ],
    'server_card' => [
        'description' => 'AI-native content management system',
        'endpoint' => '/mcp',
        'auth_type' => 'none',
    ],
    'registry' => [
        'name' => 'io.github.waaseyaa/framework',
        'description' => 'Access-controlled CMS content and editorial tools',
        'remote_url' => 'https://cms.example/mcp',
        'repository_url' => 'https://github.com/waaseyaa/framework',
        'website_url' => 'https://waaseyaa.org',
    ],
],
```

The compatibility card remains at `/.well-known/mcp.json`. The distinct
`McpRegistryManifest` service produces official `server.json` content pinned to
the Registry's 2025-12-11 schema. It refuses missing, loopback, relative, or
plain-HTTP remote URLs. Do not configure or publish a Registry manifest until
the named deployment is publicly reachable and namespace ownership can be
authenticated.

Malformed values now raise `ConfigException` instead of falling back. In
particular, an unknown card `auth_type` no longer silently advertises `none`.
The informational `serverInfo.version` returned by legacy `initialize` and
modern discovery now reports the same implementation version as the card and
Registry manifest; MCP protocol compatibility still comes exclusively from
`protocolVersion`.

### Field-API item/value-object layer removed — custom field types extend `AbstractFieldType` (Waaseyaa\Field)

The dead Drupal-lineage field item-object layer was removed (audit C-24). The
following types are **gone**:

- `Waaseyaa\Field\FieldItemBase` (the old field-type plugin base)
- `Waaseyaa\Field\FieldItemInterface`, `Waaseyaa\Field\FieldItemListInterface`
- `Waaseyaa\Field\FieldItemList`, `Waaseyaa\Field\PropertyValue`
- `Waaseyaa\Field\ComputedFieldInterface` and the `computed` field type
  (`Waaseyaa\Field\Item\ComputedItem`)

**If you have a custom field type**, change its parent class from `FieldItemBase`
to the new public base `Waaseyaa\Field\AbstractFieldType`:

```php
// Before:
use Waaseyaa\Field\FieldItemBase;
#[FieldType(id: 'my_type', /* … */)]
final class MyTypeItem extends FieldItemBase
{
    public static function schema(): array { /* … */ }
    public static function jsonSchema(): array { /* … */ }
    // propertyDefinitions()/mainPropertyName() — no longer needed; remove them.
}

// After:
use Waaseyaa\Field\AbstractFieldType;
#[FieldType(id: 'my_type', /* … */)]
final class MyTypeItem extends AbstractFieldType
{
    public static function schema(): array { /* … */ }
    public static function jsonSchema(): array { /* … */ }
}
```

The static descriptor seam (`schema`, `jsonSchema`, `defaultSettings`,
`defaultValue`, `jsonSchemaFor`, `schemaFor`) and `FieldTypeInterface` are
unchanged — `FieldTypeManager` resolution is identical. The removed instance
methods (`get`/`set`/`getValue`/`getProperties`/`toArray`/`isEmpty`/`validate`/
`getString`) and the static `propertyDefinitions()`/`mainPropertyName()` were
instantiated/called nowhere in production; entity field values flow through the
`_data`/`$values` path on `ContentEntityBase`, not an item-object layer. There is
no shim (pre-1.0-alpha): update the parent class. If you relied on the `computed`
field type or `ComputedFieldInterface`, note it had no production wiring (its
`compute()` had zero callers); compute derived values in your application code.

### TypedData instance type-system removed (Waaseyaa\TypedData)

The TypedData instance type-system — the ancestry of the field item layer
removed above — had no production consumers once that layer was gone, and is
removed (audit C-24, train 3). The following types are **gone**:

- `Waaseyaa\TypedData\TypedDataInterface`, `ComplexDataInterface`,
  `ListInterface`, `PrimitiveInterface`, `TypedDataManagerInterface`
- `Waaseyaa\TypedData\TypedDataManager` and the six
  `Waaseyaa\TypedData\Type\{Boolean,Float,Integer,List,Map,String}Data`
- `Waaseyaa\TypedData\CastTokenMapper` (no production caller — `ValueCaster`
  never used it)
- the **concrete** `Waaseyaa\TypedData\DataDefinition` (created only by the
  removed `TypedDataManager`)

**Kept and unchanged** — the live half of the package:

- `Waaseyaa\TypedData\DataDefinitionInterface` — the field-definition contract,
  extended by `Waaseyaa\Field\FieldDefinitionInterface`.
- `Waaseyaa\TypedData\Coercion\EntityCastCoercion` and
  `Waaseyaa\TypedData\Coercion\CoercionException` — the entity-cast coercion
  seam consumed by `Waaseyaa\Entity\Cast\ValueCaster`. Entity `$casts` behaviour
  is unchanged.

If you constructed `TypedDataManager` or the `Type\*Data` value objects directly
(no framework path did), there is no shim (pre-1.0-alpha). The `DataDefinition`
*interface* remains; only the standalone concrete value object is gone.

### `FieldDefinition` constructor parameters added (Waaseyaa\Field)

`Waaseyaa\Field\FieldDefinition::__construct` gained two trailing optional
parameters: `string $group = ''` and `array $promptAliases = []`.

- **Named-argument call sites** (recommended idiom) continue to work unchanged.
  No action required.
- **Positional call sites that pass `$fieldTypeManager` as the last argument**
  continue to work unchanged.
- **Positional call sites that pass arguments after `$fieldTypeManager`** —
  none should exist before this release because no such positional slots
  existed. If you have such call sites, switch to named arguments:

  ```php
  // Before:
  new FieldDefinition('title', 'string', 1, [], '', null, false, false, null, 'Title');

  // After (recommended — works regardless of constructor evolution):
  new FieldDefinition(
      name: 'title',
      type: 'string',
      label: 'Title',
  );
  ```

`getGroup(): string` and `getPromptAliases(): array` were added to
`FieldDefinitionInterface`. Custom implementations of the interface must
implement these two methods.

Reason: support for bundle-keyed field templates (mission
`single-entity-work-surface-01KQ7M1P`) requires per-field grouping and
alias metadata as first-class properties of the field definition. Per
`DIR-003`, no compatibility shim is provided — implementers update in
the same release.

### `EntityRepositoryInterface` gained the two-axis translation surface (Waaseyaa\Entity)

`Waaseyaa\Entity\Repository\EntityRepositoryInterface` gained 3 two-axis methods
promoted from the concrete `EntityRepository` (added to the concrete in
alpha.196–198): `saveTranslation`, `loadTranslation`, `listTranslationRevisions`.

- **Consumers** no longer need to narrow with `instanceof EntityRepository` to
  reach the two-axis (revisionable × translatable) translation API — call it on
  the interface. The methods are valid only on a two-axis entity type and throw
  on a single-axis type (unchanged behavior).
- **Third-party implementers of `EntityRepositoryInterface`** must add these 3
  methods. Single-axis implementations may throw (e.g. `BadMethodCallException`),
  mirroring the concrete repository's `assertTwoAxis()` guard. Per `DIR-003`, no
  compatibility shim is provided — implementers update in the same release.

The lower-level per-revision API (`saveTranslationRevision`/`saveTranslationRevisions`,
`loadTranslationRevision`, `loadTranslationTip`, `translationLangcodes`) stays on the
concrete `EntityRepository` only and is intentionally not part of the interface
contract until a consumer needs it there. (alpha.200 briefly carried all 8 on the
interface; alpha.201 narrowed it to the 3 consumers actually call.)

## 2026-04-27 - Attribute-first entity definition (M1)

The `EntityType` constructor no longer accepts `fieldDefinitions:`. The entity class itself is the source of truth for field shape — declare fields with `#[Waaseyaa\Entity\Attribute\Field]` on typed PHP properties and register the type via `EntityType::fromClass()`. The class-level `#[ContentEntityType]` attribute also gained `label:` and `description:` parameters so the human-facing strings live next to the id.

### What changed

- `Waaseyaa\Entity\EntityType::__construct` no longer accepts `fieldDefinitions:`. Passing it is a `TypeError`.
- `Waaseyaa\Entity\EntityTypeManager::assertClassMetadataMatchesEntityType()` is removed (with a single source of truth, drift is impossible).
- `Waaseyaa\Entity\Attribute\Field` is the canonical way to declare fields.
- `Waaseyaa\Entity\EntityType::fromClass($class, ...$overrides)` is the canonical factory for content entity types.
- `Waaseyaa\Entity\Attribute\ContentEntityType` accepts `label:` and `description:`.

### Migration recipe

**Before:**

```php
// src/Entity/Note.php
#[ContentEntityType(id: 'note')]
final class Note extends ContentEntityBase { /* properties only */ }
```

```php
// In NoteServiceProvider::register():
$this->entityType(new EntityType(
    id: 'note',
    label: 'Note',
    class: Note::class,
    keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle', 'langcode' => 'langcode'],
    fieldDefinitions: [
        'title' => new FieldDefinition(name: 'title', type: 'string', required: true),
        'body'  => new FieldDefinition(name: 'body', type: 'text'),
        'status' => new FieldDefinition(name: 'status', type: 'string', defaultValue: 'draft'),
    ],
));
```

**After:**

```php
// src/Entity/Note.php
namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'note', label: 'Note', description: 'Free-form authored content.')]
#[ContentEntityKeys(label: 'title', bundle: 'bundle', langcode: 'langcode')]
final class Note extends ContentEntityBase
{
    #[Field] public string $title;
    #[Field(type: 'text')] public ?string $body;
    #[Field(default: 'draft')] public string $status;
}
```

```php
// In NoteServiceProvider::register():
$this->entityType(EntityType::fromClass(Note::class));
```

See `kitty-specs/attribute-first-entity-definition-01KQ6DXE/quickstart.md` for the full inference table (PHP type → field type) and override patterns.

### Known transitional gaps (M1)

These specific cases need workarounds until follow-on missions ship; see the *Known Transitional Gaps* section in `docs/specs/entity-system.md` for details:

- `timestamp` field-type plugin not yet implemented — use `#[Field(type: 'integer', settings: ['subtype' => 'timestamp'])]`.
- ~~`enum` field-type plugin not yet implemented~~ — **Closed (mission `field-type-enum-plugin-01KQ6SJG`):** backed-enum-typed properties now resolve to the dedicated `'enum'` field-type plugin (`packages/field/src/Item/EnumItem.php`). `FieldTypeInferrer` emits `type: 'enum'` automatically; the canonical explicit form is `#[Field(type: 'enum', settings: ['enum_class' => MyEnum::class])]`. The transitional `'string' + settings.enum_class` bridge has been removed.
- `#[Field]` has no `stored:` parameter yet — entities that require `FieldStorage::Data` for universal core fields must keep using the raw `EntityType()` constructor for now.
- `FieldTypeInferrer` rejects `?int` / `?string` for `entity_reference` — declare those properties without a typed scalar (use `@var` PHPDoc) until the inferrer is extended.
- `packages/cli/stubs/provider-domain.stub` still emits the legacy `fieldDefinitions:` form; hand-edit scaffolded providers until the stub is updated.

### Tests

Test fixtures that previously passed `fieldDefinitions:` directly to the `EntityType` constructor should now use:

```php
use Waaseyaa\Entity\Tests\Helper\TestEntityType;

$type = TestEntityType::stub(id: 'fake', class: Fake::class, fieldDefinitions: [
    'title' => new FieldDefinition(name: 'title', type: 'string', required: true),
]);
```

`TestEntityType::stub()` is intentionally test-only; production code should always go through `EntityType::fromClass()`.

## 2026-04-20 - Entity-type collision guard for canonical group types

Framework packages now fail loudly when a consumer re-registers an entity type id that is already owned by the framework. The registry throws `Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException` instead of a generic duplicate-registration error.

### What changed

- Same-class duplicate registration now fails with `[ENTITY_TYPE_DUPLICATE]`.
- Shadow registration of a framework-owned canonical type now fails with `[ENTITY_TYPE_SHADOW_COLLISION]`.
- The rendered message names the entity type id, the already-registered provider class, the canonical entity class, the incoming provider class, and the conflicting entity class.
- Bundle-scoped writes now emit `[MISSING_BUNDLE_SUBTABLE]` through `LoggerInterface::notice()` when bundle-field values are present but the matching `{base}__{bundle}` subtable does not exist at save time.

### How to read the collision wording

- `[ENTITY_TYPE_DUPLICATE]` means the same entity type id was registered twice with the same class. Drop the duplicate registration; this is stale provider wiring, not an extension point.
- `[ENTITY_TYPE_SHADOW_COLLISION]` means a consumer tried to register an entity type id that the framework already owns, but with a different class. Drop the shadow registration and migrate callers to the canonical framework type.

### If you were shadowing `group` or `group_type`

Remove the duplicate `entityType()` registration from your consumer provider instead of trying to override the framework-owned id. The canonical owners are `Waaseyaa\Groups\GroupsServiceProvider`, `Waaseyaa\Groups\Group`, and `Waaseyaa\Groups\GroupType`.

If your app still has shadow classes or imports that assume consumer-owned group types, use the reconciliation ADR as the migration path:

- [`docs/history/superpowers/specs/2026-04-19-groups-reconciliation-adr.md`](docs/history/superpowers/specs/2026-04-19-groups-reconciliation-adr.md)

That ADR is the concrete path for the Minoo-shaped cleanup. Minoo `main` no longer carries live duplicate `group` / `group_type` registration in `AppServiceProvider`; the remaining migration case is shadow-class residue and call sites that still import those shadows. Later arc phases handle the `HasCommunityInterface` and `GroupType` key reconciliation that make those shadows removable.

### If you see `[MISSING_BUNDLE_SUBTABLE]`

Your app has registered bundle-scoped fields for a bundle whose storage subtable has not been materialized yet. The save path will keep the base-row write, but the bundle-field values for that write will not persist. Ship or run the schema migration / sync that creates the missing `{base}__{bundle}` subtable before saving that bundle in production.
