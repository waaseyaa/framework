---
work_package_id: WP01
title: Audit + ServiceProvider scaffold
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-009
- NFR-002
- C-001
- C-003
- C-006
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T001
- T002
- T003
- T004
- T005
- T006
- T007
history: []
authoritative_surface: packages/bimaaji/src/
execution_mode: code_change
owned_files:
- packages/bimaaji/src/BimaajiServiceProvider.php
- packages/bimaaji/composer.json
- packages/routing/src/WaaseyaaRouter.php
- packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php
- phpstan-dead-code-baseline.neon
tags: []
---

## Objective

Create `BimaajiServiceProvider` — the single missing piece that promotes bimaaji from a
library with no consumers to a fully-wired Layer 5 package. The provider binds
`ApplicationGraphGenerator` as a singleton, registers all 6 default
`GraphSectionProviderInterface` implementations into a tagged service collection, and
implements `HasNativeCommandsInterface` (command registration is completed in WP02).

This WP also resolves the two container-dependency gaps identified in plan AD-04: the
`RouteCollection` accessor on `WaaseyaaRouter` and the `SovereigntyProfile` binding via
`SovereigntyConfigInterface`. Both are additive changes with no impact on existing
behaviour. The WP concludes with a unit test that exercises `register()` without a full
kernel boot.

## Context

`packages/bimaaji/` ships 25 PHP classes that are functionally complete but entirely
undiscoverable: `packages/bimaaji/composer.json` declares no `extra.waaseyaa.providers`
entry, so `PackageManifestCompiler` never loads it. The result is that
`ApplicationGraphGenerator` and the 6 default providers (Entity, Routing, JsonApi, Admin,
Sovereignty, PublicSurface) are unreachable from any framework consumer.

Plan AD-01 confirms provider discovery uses `extra.waaseyaa.providers` in each package's
`composer.json` (confirmed at `ProviderDiscovery.php:27`). Plan AD-02 specifies the tagged
collection pattern using `ServiceProvider::tag()` with the public constant
`BimaajiServiceProvider::TAG = 'bimaaji.graph_section_providers'`. Plan AD-04 documents
the two gaps: `RouteCollection` is owned privately by `WaaseyaaRouter` (needs a
`getRouteCollection()` accessor), and `SovereigntyProfile` is a backed enum that must be
bound via `SovereigntyConfigInterface::getProfile()`.

C-001 requires that bimaaji stays in Layer 5 (L5) and imports only from L0–L4. The routing
accessor change lives in `packages/routing/` (L4), which bimaaji already imports — no new
`composer.json` `require` entries are needed.

## Subtasks

### T001 — Audit container bindings and expose `RouteCollection`

**Purpose:** Confirm which constructor dependencies of the 6 default providers are already
bound in the container, resolve the `RouteCollection` gap, and document the
`SovereigntyProfile` binding strategy before writing any provider code.

**Steps:**

1. Grep `WaaseyaaRouter` for an existing `getRouteCollection` method:
   ```
   grep -n "getRouteCollection" packages/routing/src/WaaseyaaRouter.php
   ```
   If absent, open `packages/routing/src/WaaseyaaRouter.php`, locate the `$routes`
   property (expected around line 33 per plan AD-04), and add:
   ```php
   /**
    * @api
    */
   public function getRouteCollection(): RouteCollection
   {
       return $this->routes;
   }
   ```
   The `@api` tag prevents the dead-code gate from flagging this new public method (plan R1).

2. Confirm `EntityTypeManagerInterface` is bound in `AbstractKernel`:
   ```
   grep -n "EntityTypeManagerInterface" packages/foundation/src/Kernel/AbstractKernel.php | head -5
   ```

3. Confirm `SovereigntyConfigInterface` is bound in `FoundationServiceProvider`:
   ```
   grep -n "SovereigntyConfigInterface" packages/foundation/src/ServiceProvider/FoundationServiceProvider.php | head -5
   ```

4. Confirm `symfony/yaml` is available (for WP02 planning):
   ```
   grep -r "symfony/yaml" packages/bimaaji/composer.json packages/foundation/composer.json
   ```

5. Document findings in a short comment block at the top of `BimaajiServiceProvider.php`
   (removed before final commit if redundant).

**Files touched:**
- `packages/routing/src/WaaseyaaRouter.php` — conditional: add `getRouteCollection()` if absent
- `phpstan-dead-code-baseline.neon` — conditional: add entry for `getRouteCollection` if the
  `@api` tag is not sufficient (verify after `composer phpstan`)

**Validation:**
- `grep -n "getRouteCollection" packages/routing/src/WaaseyaaRouter.php` returns a result.
- `composer phpstan` passes (no new dead-code findings).

---

### T002 — Create `BimaajiServiceProvider` with tagged collection

**Purpose:** Implement the core provider wiring — singleton bindings for each of the 6
default providers, the tagged collection, and the `ApplicationGraphGenerator` factory.

**Steps:**

1. Create `packages/bimaaji/src/BimaajiServiceProvider.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Waaseyaa\Bimaaji;

   use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
   use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
   use Waaseyaa\Bimaaji\ApplicationGraphGenerator;
   use Waaseyaa\Bimaaji\Provider\EntityIntrospectionProvider;
   use Waaseyaa\Bimaaji\Provider\AdminIntrospectionProvider;
   use Waaseyaa\Bimaaji\Provider\RoutingIntrospectionProvider;
   use Waaseyaa\Bimaaji\Provider\JsonApiIntrospectionProvider;
   use Waaseyaa\Bimaaji\Provider\PublicSurfaceProvider;
   use Waaseyaa\Bimaaji\Provider\SovereigntyIntrospectionProvider;
   use Waaseyaa\Routing\WaaseyaaRouter;
   use Waaseyaa\Sovereignty\Config\SovereigntyConfigInterface;
   use Waaseyaa\Sovereignty\SovereigntyProfile;

   final class BimaajiServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
   {
       public const string TAG = 'bimaaji.graph_section_providers';

       public function register(): void
       {
           // Bind RouteCollection via WaaseyaaRouter accessor (AD-04)
           $this->singleton(\Symfony\Component\Routing\RouteCollection::class, function () {
               return $this->resolve(WaaseyaaRouter::class)->getRouteCollection();
           });

           // Bind SovereigntyProfile via SovereigntyConfigInterface (AD-04)
           $this->singleton(SovereigntyProfile::class, function () {
               return $this->resolve(SovereigntyConfigInterface::class)->getProfile();
           });

           // Bind and tag the 6 default providers
           foreach ($this->defaultProviders() as $providerClass) {
               $this->singleton($providerClass, $providerClass);
               $this->tag($providerClass, self::TAG);
           }

           // Bind ApplicationGraphGenerator — resolves tagged providers at call time
           $this->singleton(ApplicationGraphGenerator::class, function () {
               $providers = array_map(
                   fn(string $fqcn) => $this->resolve($fqcn),
                   $this->getTags()[self::TAG] ?? []
               );
               return new ApplicationGraphGenerator($providers);
           });
       }

       public function nativeCommands(): array
       {
           // Implemented in WP02 — returns [] for now so the interface is satisfied
           return [];
       }

       /** @return list<class-string> */
       private function defaultProviders(): array
       {
           return [
               EntityIntrospectionProvider::class,
               AdminIntrospectionProvider::class,
               RoutingIntrospectionProvider::class,
               JsonApiIntrospectionProvider::class,
               PublicSurfaceProvider::class,
               SovereigntyIntrospectionProvider::class,
           ];
       }
   }
   ```

2. Verify all imported FQCNs exist by grepping each class:
   ```
   grep -rn "class EntityIntrospectionProvider" packages/bimaaji/src/
   grep -rn "class RoutingIntrospectionProvider" packages/bimaaji/src/
   grep -rn "class SovereigntyIntrospectionProvider" packages/bimaaji/src/
   ```
   Adjust import paths if actual filenames differ.

3. Verify `ApplicationGraphGenerator` constructor signature:
   ```
   grep -n "__construct" packages/bimaaji/src/ApplicationGraphGenerator.php
   ```
   Adjust the factory call if the constructor takes `iterable` vs `array`.

**Files touched:**
- `packages/bimaaji/src/BimaajiServiceProvider.php` — create

**Validation:**
- `composer cs-check` passes on the new file.
- `composer phpstan` passes.

---

### T003 — Register provider in `composer.json`

**Purpose:** Wire `BimaajiServiceProvider` into `PackageManifestCompiler` auto-discovery
by adding it to `extra.waaseyaa.providers` in `packages/bimaaji/composer.json`.

**Steps:**

1. Open `packages/bimaaji/composer.json`. Locate the `extra` section (or add it if absent).
2. Add:
   ```json
   "extra": {
       "waaseyaa": {
           "providers": [
               "Waaseyaa\\Bimaaji\\BimaajiServiceProvider"
           ]
       }
   }
   ```
   If an `extra` section already exists, merge into it — do not replace other keys.
3. Ensure `"sort-packages": true` is present in `config` (composer policy CP001):
   ```
   grep "sort-packages" packages/bimaaji/composer.json
   ```
   Add under `"config"` if absent.
4. Run `composer check-composer-policy` to verify CP001/CP002/CP003/CP006 all pass.

**Files touched:**
- `packages/bimaaji/composer.json` — edit

**Validation:**
- `composer check-composer-policy` exits 0.
- `bin/check-package-layers` exits 0.

---

### T004 — Verify `HasNativeCommandsInterface` discovery path

**Purpose:** Confirm `PackageManifestCompiler` picks up providers that implement
`HasNativeCommandsInterface` so that WP02's command registration will work without
additional wiring.

**Steps:**

1. Grep for the capability constant:
   ```
   grep -n "HasNativeCommandsInterface\|CAPABILITY_HAS_NATIVE_COMMANDS" \
     packages/foundation/src/ServiceProvider/PackageManifestCompiler.php \
     packages/cli/src/Provider/CliKernelServiceProvider.php
   ```
2. Confirm the interface FQCN matches what `BimaajiServiceProvider` implements:
   ```
   grep -n "interface HasNativeCommandsInterface" \
     packages/foundation/src/ServiceProvider/Capability/HasNativeCommandsInterface.php
   ```
3. Confirm the `nativeCommands()` return type matches what `CliKernelServiceProvider`
   iterates. If it expects `CommandDefinition[]`, note this for WP02.
4. If discovery requires an additional manifest key not covered by the `ServiceProvider`
   subclass check, add a comment in `BimaajiServiceProvider` explaining the extra step —
   do not add the step itself (WP02 owns that).

**Files touched:**
- None (audit-only) — findings inform WP02's T008.

**Validation:**
- Document the discovery path in a brief comment at the top of T005 findings or in the
  PR description. No code change required in this subtask.

---

### T005 — Add `extra.waaseyaa.providers` to manifest and regenerate

**Purpose:** After T003 and T004, run `bin/waaseyaa optimize:manifest` to rebuild the
attribute-discovery manifest and confirm `BimaajiServiceProvider` appears.

**Steps:**

1. Run:
   ```
   bin/waaseyaa optimize:manifest
   ```
2. Grep the generated manifest for `BimaajiServiceProvider`:
   ```
   grep -r "BimaajiServiceProvider" var/ bootstrap/ storage/ 2>/dev/null || \
     grep -r "BimaajiServiceProvider" packages/foundation/src/ 2>/dev/null
   ```
   (Actual manifest location depends on `PackageManifestCompiler` output path — adjust
   grep target to wherever manifests are written.)
3. If the manifest command is not available without a running kernel, use:
   ```
   composer dump-autoload
   ```
   and verify via `vendor/autoload.php` that `Waaseyaa\Bimaaji\BimaajiServiceProvider`
   is loadable.

**Files touched:**
- None (verification step — manifest is a generated artifact, not tracked in git unless
  the project tracks it).

**Validation:**
- `class_exists('Waaseyaa\Bimaaji\BimaajiServiceProvider')` returns true after autoload.
- No PHP parse errors on `require`.

---

### T006 — Baseline regen if new dead-code findings appear

**Purpose:** New public methods (`getRouteCollection`, `nativeCommands`) may trigger
the dead-code gate if reflection-based entrypoint marking does not cover them. Resolve
before the unit test step.

**Steps:**

1. Run:
   ```
   composer verify 2>&1 | grep -i "dead\|unused\|BimaajiServiceProvider\|getRouteCollection"
   ```
2. If new findings appear:
   a. For `getRouteCollection`: confirm `@api` is present on the method. If the gate
      still fires, append to `phpstan-dead-code-baseline.neon`:
      ```yaml
      -
          message: '#Method Waaseyaa\\Routing\\WaaseyaaRouter::getRouteCollection\(\) is never called#'
          path: packages/routing/src/WaaseyaaRouter.php
      ```
      Include an inline comment: `# @api — consumed by BimaajiServiceProvider::register()`
   b. For `nativeCommands`: this is a `HasNativeCommandsInterface` method. The
      `WaaseyaaEntrypointProvider` should mark it — verify in
      `tools/phpstan/WaaseyaaEntrypointProvider.php`. If not, add:
      ```yaml
      -
          message: '#Method Waaseyaa\\Bimaaji\\BimaajiServiceProvider::nativeCommands\(\) is never called#'
          path: packages/bimaaji/src/BimaajiServiceProvider.php
      ```
      Include comment: `# HasNativeCommandsInterface — discovered by CliKernelServiceProvider`
3. Re-run `composer verify` to confirm green.

**Files touched:**
- `phpstan-dead-code-baseline.neon` — conditional edit

**Validation:**
- `composer verify` exits 0 with no new dead-code findings.

---

### T007 — Unit test `BimaajiServiceProviderTest`

**Purpose:** Verify `register()` correctness without a full kernel boot — covering FR-009.

**Steps:**

1. Create `packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Waaseyaa\Bimaaji\Tests\Unit;

   use PHPUnit\Framework\Attributes\CoversClass;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;
   use Waaseyaa\Bimaaji\BimaajiServiceProvider;
   use Waaseyaa\Bimaaji\ApplicationGraphGenerator;

   #[CoversClass(BimaajiServiceProvider::class)]
   final class BimaajiServiceProviderTest extends TestCase
   {
       #[Test]
       public function registerBindsApplicationGraphGenerator(): void
       {
           $provider = $this->buildProvider();
           $provider->register();

           $bindings = $provider->getBindings();
           self::assertArrayHasKey(ApplicationGraphGenerator::class, $bindings);
       }

       #[Test]
       public function registerTagsExactlySixDefaultProviders(): void
       {
           $provider = $this->buildProvider();
           $provider->register();

           $tags = $provider->getTags();
           self::assertArrayHasKey(BimaajiServiceProvider::TAG, $tags);
           self::assertCount(6, $tags[BimaajiServiceProvider::TAG]);
       }

       #[Test]
       public function allTaggedProvidersHaveBindings(): void
       {
           $provider = $this->buildProvider();
           $provider->register();

           $bindings = $provider->getBindings();
           $tagged = $provider->getTags()[BimaajiServiceProvider::TAG] ?? [];
           foreach ($tagged as $fqcn) {
               self::assertArrayHasKey($fqcn, $bindings,
                   "Provider {$fqcn} is tagged but not bound as singleton.");
           }
       }

       #[Test]
       public function tagConstantIsStableString(): void
       {
           // SC-005 / plan note: TAG is public API — M3 (MCP) depends on it being stable.
           self::assertSame('bimaaji.graph_section_providers', BimaajiServiceProvider::TAG);
       }

       private function buildProvider(): BimaajiServiceProvider
       {
           // Use a stub container; adjust if ServiceProvider constructor requires DI.
           return new BimaajiServiceProvider();
       }
   }
   ```

2. Run the unit test in isolation:
   ```
   ./vendor/bin/phpunit packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php --no-coverage
   ```
3. Fix any failures — common causes: constructor arg required by `ServiceProvider` base,
   `getBindings()`/`getTags()` method names differ. Inspect base class:
   ```
   grep -n "getBindings\|getTags\|function register" packages/foundation/src/ServiceProvider/ServiceProvider.php | head -20
   ```
   Adjust test helper accordingly.
4. Run `composer cs-check` and `composer phpstan` after test creation.

**Files touched:**
- `packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php` — create

**Validation:**
- `./vendor/bin/phpunit packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php` exits 0.
- All 4 test methods pass.
- `composer verify` exits 0.

---

## Test strategy

**Unit tests** (`packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php`):
- 4 test methods: `registerBindsApplicationGraphGenerator`, `registerTagsExactlySixDefaultProviders`,
  `allTaggedProvidersHaveBindings`, `tagConstantIsStableString`.
- No kernel boot — exercises `ServiceProvider::getBindings()` and `getTags()` directly.
- PHPUnit 10.5 attributes: `#[Test]`, `#[CoversClass(BimaajiServiceProvider::class)]`.
- `final class` test class — no subclassing.

**Static analysis:**
- `composer phpstan` must pass with no new findings.
- `bin/check-package-layers` must pass (bimaaji L5 → routing L4, entity L1, foundation L0 all valid).

## Definition of Done

- [ ] `packages/bimaaji/src/BimaajiServiceProvider.php` exists and has zero parse errors.
- [ ] `ApplicationGraphGenerator::class` is in `getBindings()` after `register()` (FR-002).
- [ ] `getTags()[BimaajiServiceProvider::TAG]` contains exactly 6 FQCNs (FR-003).
- [ ] `packages/bimaaji/composer.json` has `extra.waaseyaa.providers` with the provider FQCN (FR-001).
- [ ] `packages/routing/src/WaaseyaaRouter.php` exposes `getRouteCollection()` (FR-003 / AD-04).
- [ ] `BimaajiServiceProvider` implements `HasNativeCommandsInterface` (C-002 / AD-03).
- [ ] All 4 unit tests pass (FR-009).
- [ ] `composer verify` exits 0 (C-006).
- [ ] `bin/check-package-layers` exits 0 (C-001).
- [ ] `bin/check-composer-policy` exits 0 (C-006).
- [ ] No changes to existing `GraphSectionProviderInterface` or `ApplicationGraphGenerator` contracts (C-003).

## Risks and notes

- **R1 (RouteCollection gap, HIGH/LOW):** Plan expects `$this->routes` to be the property
  name. Grep the actual property name before writing the accessor.
- **R4 (SovereigntyProfile enum):** `SovereigntyProfile::class` is a valid string constant
  for container keys in PHP 8.5. If `getProfile()` does not exist on
  `SovereigntyConfigInterface`, grep for the actual method name.
- **R5 (dead-code gate):** `nativeCommands()` returns `[]` in this WP — WP02 fills it.
  The dead-code gate should not fire on an interface-required method, but T006 handles it
  if it does.
- **Layer direction:** `BimaajiServiceProvider` imports from `packages/routing/` (L4) and
  `packages/foundation/` (L0) — both are correct downward imports for an L5 package.
  The routing accessor change does NOT import from bimaaji — direction is safe.

## Reviewer guidance

The opus reviewer should check:

1. **Tag constant stability** — `BimaajiServiceProvider::TAG` is the M3 dependency surface.
   Confirm it is `public const string TAG = 'bimaaji.graph_section_providers'` — no
   rename, no typo.
2. **Six providers exactly** — Verify all 6 FQCNs in `defaultProviders()` match actual
   class names in `packages/bimaaji/src/Provider/`. A mismatch causes a silent container
   miss at runtime.
3. **`RouteCollection` binding direction** — The factory resolves `WaaseyaaRouter` then
   calls `->getRouteCollection()`. The router must NOT be constructed fresh — it must be
   the singleton already bound by the kernel. Confirm `$this->resolve(WaaseyaaRouter::class)`
   is used, not `new WaaseyaaRouter(...)`.
4. **`SovereigntyProfile` binding** — Confirm `getProfile()` (or the actual method name)
   returns a `SovereigntyProfile` enum value, not a string or array.
5. **Composer policy** — `packages/bimaaji/composer.json` must have `"sort-packages": true`
   and no `@dev` constraints (CP001/CP002).
6. **Test isolation** — The unit test must not boot a kernel. If it calls `new ConsoleKernel()`
   anywhere, reject.
