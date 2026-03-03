# Policy Auto-Discovery Design

**Date:** 2026-03-03
**Status:** Approved
**Goal:** Replace hardcoded policy wiring in `index.php` with attribute-driven auto-discovery via `PackageManifest`.

## Context

The `PackageManifestCompiler` already scans for `#[PolicyAttribute]` and stores discovered policies in `PackageManifest.policies`. However, no policy classes currently carry the attribute — they're all instantiated manually in `index.php`. This design closes that gap.

The main complication is `ConfigEntityAccessPolicy`, which takes a list of entity type IDs in its constructor. Simple policies like `NodeAccessPolicy` take no arguments.

## Design

### 1. PolicyAttribute: string|array entity types

Extend `PolicyAttribute` to accept one or many entity types:

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PolicyAttribute
{
    /** @var string[] */
    public readonly array $entityTypes;

    public function __construct(string|array $entityType)
    {
        $this->entityTypes = is_array($entityType) ? $entityType : [$entityType];
    }
}
```

Constructor parameter stays `entityType` (singular) for ergonomic single-entity usage. Property becomes `entityTypes` (plural, array). Breaking change — consumers updated in this same changeset.

### 2. Manifest storage: class-centric map

Change `policies` from `[entityType => className]` to `[className => entityTypes[]]`:

```php
// Before
['node' => 'Waaseyaa\Node\NodeAccessPolicy']

// After
['Waaseyaa\Node\NodeAccessPolicy' => ['node']]
['Waaseyaa\Access\ConfigEntityAccessPolicy' => ['node_type', 'taxonomy_vocabulary', ...]]
```

No class duplication. Natural deduplication.

### 3. PackageManifestCompiler update

```php
// Before
$policies[$instance->entityType] = $class;

// After
$policies[$class] = $instance->entityTypes;
```

### 4. Add #[PolicyAttribute] to all policy classes

- `NodeAccessPolicy` → `#[PolicyAttribute(entityType: 'node')]`
- `TermAccessPolicy` → `#[PolicyAttribute(entityType: 'taxonomy_term')]`
- `UserAccessPolicy` → `#[PolicyAttribute(entityType: 'user')]`
- `MediaAccessPolicy` → `#[PolicyAttribute(entityType: 'media')]`
- `ConfigEntityAccessPolicy` → `#[PolicyAttribute(entityType: ['node_type', 'taxonomy_vocabulary', 'media_type', 'workflow', 'pipeline', 'path_alias', 'menu', 'menu_link'])]`

### 5. index.php instantiation from manifest

Replace hardcoded policy block:

```php
$policies = [];
foreach ($manifest->policies as $class => $entityTypes) {
    $ref = new \ReflectionClass($class);
    $constructor = $ref->getConstructor();
    if ($constructor !== null && $constructor->getNumberOfParameters() > 0) {
        $policies[] = new $class($entityTypes);
    } else {
        $policies[] = new $class();
    }
}
$accessHandler = new EntityAccessHandler($policies);
```

Convention: if a policy's constructor accepts parameters, the entity types array is passed. This works for `ConfigEntityAccessPolicy(array $entityTypeIds)`. Simple policies have no-arg constructors.

### 6. Gate updates

`Gate::detectEntityType()` reads `$attr->entityType` — update to iterate `$attr->entityTypes` and register the policy for each entity type.

### 7. Test updates

- `GateTest`: update attribute usage from `entityType` to new shape
- `PackageManifestCompilerTest`: update policy discovery assertions for class-centric format
- `PolicyAttribute` unit test: verify string, array, and edge cases

### 8. Cleanup

- Remove individual policy `use` imports from `index.php`
- Update CLAUDE.md: remove step 6 from "Adding an access policy" checklist (no longer manual)
- Update roadmap: Layer 5 → Done

## Files affected

| File | Change |
|------|--------|
| `packages/access/src/Gate/PolicyAttribute.php` | `entityType` string → `entityTypes` array |
| `packages/access/src/Gate/Gate.php` | Multi-type indexing in `detectEntityType()` |
| `packages/foundation/src/Discovery/PackageManifestCompiler.php` | Class-centric storage |
| `packages/node/src/NodeAccessPolicy.php` | Add `#[PolicyAttribute]` |
| `packages/taxonomy/src/TermAccessPolicy.php` | Add `#[PolicyAttribute]` |
| `packages/user/src/UserAccessPolicy.php` | Add `#[PolicyAttribute]` |
| `packages/media/src/MediaAccessPolicy.php` | Add `#[PolicyAttribute]` |
| `packages/access/src/ConfigEntityAccessPolicy.php` | Add `#[PolicyAttribute]` |
| `public/index.php` | Replace hardcoded block with manifest loop |
| `packages/access/tests/Unit/Gate/GateTest.php` | Update for new attribute shape |
| `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php` | Update assertions |
| `CLAUDE.md` | Update checklist |
| `docs/roadmap.md` | Layer 5 → Done |
