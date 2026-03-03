# Policy Auto-Discovery Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace hardcoded policy wiring in `index.php` with attribute-driven auto-discovery via `PackageManifest`, so adding a new access policy only requires adding `#[PolicyAttribute]` to the class.

**Architecture:** Extend `PolicyAttribute` to accept `string|array` entity types. Change manifest storage from `[entityType => class]` to `[class => entityTypes[]]`. Add the attribute to all 5 existing policy classes. Replace the hardcoded instantiation block in `index.php` with a manifest-driven loop. Update `Gate` to handle multi-entity-type indexing.

**Tech Stack:** PHP 8.3, PHPUnit 10.5

---

### Task 1: PolicyAttribute — update to support string|array

**Files:**
- Modify: `packages/access/src/Gate/PolicyAttribute.php`

**Step 1: Write the failing test**

Add a new test in the existing `GateTest.php` for the updated attribute shape. But first, update `PolicyAttribute` itself — the existing `policyAttributeEntityTypeProperty` test at line 354 needs to change.

In `packages/access/tests/Unit/Gate/GateTest.php`, find the test at line 354:

```php
#[Test]
public function policyAttributeEntityTypeProperty(): void
{
    $attr = new PolicyAttribute(entityType: 'node');

    $this->assertSame('node', $attr->entityType);
}
```

Replace with:

```php
#[Test]
public function policyAttributeEntityTypesPropertyFromString(): void
{
    $attr = new PolicyAttribute(entityType: 'node');

    $this->assertSame(['node'], $attr->entityTypes);
}

#[Test]
public function policyAttributeEntityTypesPropertyFromArray(): void
{
    $attr = new PolicyAttribute(entityType: ['node_type', 'taxonomy_vocabulary']);

    $this->assertSame(['node_type', 'taxonomy_vocabulary'], $attr->entityTypes);
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Gate/GateTest.php --filter policyAttribute`
Expected: FAIL — `entityTypes` property does not exist.

**Step 3: Update PolicyAttribute implementation**

In `packages/access/src/Gate/PolicyAttribute.php`, replace the entire class body:

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PolicyAttribute
{
    /** @var string[] */
    public readonly array $entityTypes;

    /**
     * @param string|string[] $entityType One entity type ID or an array of them.
     */
    public function __construct(
        string|array $entityType,
    ) {
        $this->entityTypes = is_array($entityType) ? $entityType : [$entityType];
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Gate/GateTest.php --filter policyAttribute`
Expected: PASS

**Step 5: Commit**

```bash
git add packages/access/src/Gate/PolicyAttribute.php packages/access/tests/Unit/Gate/GateTest.php
git commit -m "feat(access): extend PolicyAttribute to accept string|array entity types"
```

---

### Task 2: Gate — update detectEntityType for multi-entity indexing

**Files:**
- Modify: `packages/access/src/Gate/Gate.php:68-108`
- Modify: `packages/access/tests/Unit/Gate/GateTest.php`

**Step 1: Update Gate::indexPolicies and detectEntityType**

In `packages/access/src/Gate/Gate.php`, replace the `indexPolicies()` method (lines 68-77):

```php
private function indexPolicies(): void
{
    foreach ($this->policies as $policy) {
        $entityTypes = $this->detectEntityTypes($policy);

        foreach ($entityTypes as $entityType) {
            $this->resolvedPolicies[$entityType] = $policy;
        }
    }
}
```

Replace `detectEntityType()` (lines 84-108) with `detectEntityTypes()`:

```php
/**
 * Detect the entity types a policy applies to.
 *
 * First checks for a #[PolicyAttribute], then falls back to naming convention.
 *
 * @return string[]
 */
private function detectEntityTypes(object $policy): array
{
    $reflection = new \ReflectionClass($policy);

    // Check for PolicyAttribute.
    $attributes = $reflection->getAttributes(PolicyAttribute::class);

    if ($attributes !== []) {
        /** @var PolicyAttribute $attr */
        $attr = $attributes[0]->newInstance();

        return $attr->entityTypes;
    }

    // Fall back to naming convention: "NodePolicy" -> "node".
    $shortName = $reflection->getShortName();

    if (str_ends_with($shortName, 'Policy')) {
        $typePart = substr($shortName, 0, -6); // Remove "Policy" suffix.

        return [$this->toSnakeCase($typePart)];
    }

    return [];
}
```

**Step 2: Run existing Gate tests to verify nothing broke**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Gate/GateTest.php`
Expected: All tests PASS. The fixture classes use single-entity `#[PolicyAttribute(entityType: 'node')]` which still works — `entityTypes` returns `['node']`, the loop registers one entry.

**Step 3: Commit**

```bash
git add packages/access/src/Gate/Gate.php
git commit -m "refactor(access): Gate uses detectEntityTypes for multi-entity policy indexing"
```

---

### Task 3: PackageManifestCompiler — class-centric policy storage

**Files:**
- Modify: `packages/foundation/src/Discovery/PackageManifestCompiler.php:109-112`
- Modify: `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php:249-282`

**Step 1: Update the test assertion**

In `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`, find the `compile_discovers_policy_classes` test (line 249). Replace the assertion at line 281:

```php
$this->assertSame('Waaseyaa\\TestFixtures\\NodePolicy', $manifest->policies['node'] ?? null);
```

With:

```php
$this->assertSame(['node'], $manifest->policies['Waaseyaa\\TestFixtures\\NodePolicy'] ?? null);
```

**Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php --filter compile_discovers_policy_classes`
Expected: FAIL — still stores old format.

**Step 3: Update compiler**

In `packages/foundation/src/Discovery/PackageManifestCompiler.php`, replace lines 109-112:

```php
foreach ($ref->getAttributes(self::POLICY_ATTRIBUTE) as $attr) {
    $instance = $attr->newInstance();
    $policies[$instance->entityType] = $class;
}
```

With:

```php
foreach ($ref->getAttributes(self::POLICY_ATTRIBUTE) as $attr) {
    $instance = $attr->newInstance();
    $policies[$class] = $instance->entityTypes;
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php --filter compile_discovers_policy_classes`
Expected: PASS

**Step 5: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifestCompiler.php packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php
git commit -m "refactor(foundation): store policies as class-centric map in manifest"
```

---

### Task 4: Add #[PolicyAttribute] to all policy classes

**Files:**
- Modify: `packages/node/src/NodeAccessPolicy.php:1-11`
- Modify: `packages/taxonomy/src/TermAccessPolicy.php:1-11`
- Modify: `packages/user/src/UserAccessPolicy.php:1-8`
- Modify: `packages/media/src/MediaAccessPolicy.php:1-8`
- Modify: `packages/access/src/ConfigEntityAccessPolicy.php:1-9`

**Step 1: Add attribute to NodeAccessPolicy**

In `packages/node/src/NodeAccessPolicy.php`, add the import and attribute:

After `use Waaseyaa\Entity\EntityInterface;` (line 10), add:
```php
use Waaseyaa\Access\Gate\PolicyAttribute;
```

Before `final class NodeAccessPolicy` (line 19), add:
```php
#[PolicyAttribute(entityType: 'node')]
```

**Step 2: Add attribute to TermAccessPolicy**

In `packages/taxonomy/src/TermAccessPolicy.php`, add the import and attribute:

After `use Waaseyaa\Entity\EntityInterface;` (line 10), add:
```php
use Waaseyaa\Access\Gate\PolicyAttribute;
```

Before `final class TermAccessPolicy` (line 18), add:
```php
#[PolicyAttribute(entityType: 'taxonomy_term')]
```

**Step 3: Add attribute to UserAccessPolicy**

In `packages/user/src/UserAccessPolicy.php`, add the import and attribute:

After `use Waaseyaa\Entity\EntityInterface;` (line 8), add:
```php
use Waaseyaa\Access\Gate\PolicyAttribute;
```

Before `final class UserAccessPolicy` (line 20), add:
```php
#[PolicyAttribute(entityType: 'user')]
```

**Step 4: Add attribute to MediaAccessPolicy**

In `packages/media/src/MediaAccessPolicy.php`, add the import and attribute:

After `use Waaseyaa\Entity\EntityInterface;` (line 8), add:
```php
use Waaseyaa\Access\Gate\PolicyAttribute;
```

Before `final class MediaAccessPolicy` (line 18), add:
```php
#[PolicyAttribute(entityType: 'media')]
```

**Step 5: Add attribute to ConfigEntityAccessPolicy**

In `packages/access/src/ConfigEntityAccessPolicy.php`, add the import and attribute:

After `use Waaseyaa\Entity\EntityInterface;` (line 8), add:
```php
use Waaseyaa\Access\Gate\PolicyAttribute;
```

Before `final class ConfigEntityAccessPolicy` (line 16), add:
```php
#[PolicyAttribute(entityType: ['node_type', 'taxonomy_vocabulary', 'media_type', 'workflow', 'pipeline', 'path_alias', 'menu', 'menu_link'])]
```

**Step 6: Run all access and entity tests to verify nothing broke**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests PASS.

**Step 7: Commit**

```bash
git add packages/node/src/NodeAccessPolicy.php packages/taxonomy/src/TermAccessPolicy.php packages/user/src/UserAccessPolicy.php packages/media/src/MediaAccessPolicy.php packages/access/src/ConfigEntityAccessPolicy.php
git commit -m "feat(access): add #[PolicyAttribute] to all access policy classes"
```

---

### Task 5: Wire manifest-driven policy loading in index.php

**Files:**
- Modify: `public/index.php:70-78` (imports)
- Modify: `public/index.php:336-355` (policy instantiation)

**Step 1: Add manifest compiler import and remove individual policy imports**

In `public/index.php`, replace lines 71-78:

```php
use Waaseyaa\Access\ConfigEntityAccessPolicy;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Taxonomy\TermAccessPolicy;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\Media\MediaAccessPolicy;
```

With:

```php
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
```

**Step 2: Replace hardcoded policy block with manifest-driven instantiation**

In `public/index.php`, replace the entity access handler block (lines 336-353):

```php
// --- Entity access handler -----------------------------------------------------

$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new UserAccessPolicy(),
    new MediaAccessPolicy(),
    new ConfigEntityAccessPolicy(entityTypeIds: [
        'node_type',
        'taxonomy_vocabulary',
        'media_type',
        'workflow',
        'pipeline',
        'path_alias',
        'menu',
        'menu_link',
    ]),
]);
```

With:

```php
// --- Entity access handler (auto-discovered via #[PolicyAttribute]) -----------

$manifestCompiler = new PackageManifestCompiler(
    basePath: dirname(__DIR__),
    storagePath: dirname(__DIR__) . '/storage',
);
$manifest = $manifestCompiler->load();

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

**Step 3: Start the dev server and verify a protected endpoint still works**

Run: `php -S localhost:8080 -t public &` then `curl -s http://localhost:8080/api/node | head -c 200`
Expected: JSON response (either data or 403 — either confirms the pipeline is running).

Kill the server afterward.

**Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests PASS.

**Step 5: Commit**

```bash
git add public/index.php
git commit -m "feat(access): wire manifest-driven policy auto-discovery in front controller

Policies are now discovered via #[PolicyAttribute] and instantiated
from PackageManifest. Adding a new access policy only requires the
attribute — no manual wiring in index.php needed."
```

---

### Task 6: Update CLAUDE.md checklist and roadmap

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/roadmap.md`

**Step 1: Update CLAUDE.md "Adding an access policy" checklist**

In `CLAUDE.md`, find step 6 in the "Adding an access policy" checklist:

```
6. Wire into `EntityAccessHandler` policy array in `public/index.php` (manual until policy auto-discovery)
```

Replace with:

```
6. Run `waaseyaa optimize:manifest` (or restart dev server) to pick up the new policy
```

**Step 2: Update roadmap**

In `docs/roadmap.md`, change Layer 5 status:

```
| 5 | Policy auto-discovery | Planned | Wire `PackageManifest.policies` into `EntityAccessHandler` instead of hardcoding in `index.php` |
```

To:

```
| 5 | Policy auto-discovery | Done | `#[PolicyAttribute]` on policy classes; `PackageManifestCompiler` discovers and `index.php` instantiates from manifest |
```

**Step 3: Commit**

```bash
git add CLAUDE.md docs/roadmap.md
git commit -m "docs: mark policy auto-discovery (Layer 5) as complete"
```

---

### Task 7: Run full test suite — final verification

**Step 1: Run all tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests PASS.

**Step 2: Verify no regressions**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All unit tests PASS.
