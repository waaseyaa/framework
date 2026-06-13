# Implementation Plan — bimaaji-wakeup-01KS5VEY

**Mission:** `bimaaji-wakeup-01KS5VEY`
**Target branch:** `main`
**Plan date:** 2026-05-21
**Design doc:** `docs/history/plans/2026-05-21-ai-ecosystem-beta-tightening.md` (M1 of 5)

---

## Branch contract

- **Current branch at plan start:** `main`
- **Planning base branch:** `main`
- **Final merge target:** `main` (squash-merge via PR, per `docs/specs/workflow.md`)
- Spec Kitty lanes work against `main`; each WP is a short-lived lane that squashes back.

---

## Engineering alignment

This mission is purely additive. The 25 existing classes in `packages/bimaaji/src/` are
functionally complete and must not be changed (C-003). The work is:

1. Add a `ServiceProvider` that wires them together.
2. Add a CLI command that exposes them to the user.
3. Add integration tests that prove the wired pipeline works end-to-end.
4. Refresh documentation.
5. Verify the cross-mission gate (M2 can consume without further wiring).

No new `GraphSectionProviderInterface` implementations, no mutation pipeline changes, no
HTTP routes, no MCP transport.

---

## Architecture decisions

### AD-01 — `BimaajiServiceProvider` location

**Decision:** `packages/bimaaji/src/BimaajiServiceProvider.php`

**Rationale:** Bimaaji is a standalone L5 package with its own `composer.json`. Placing
the provider inside the package (a) keeps it self-contained, (b) allows external consumers
to install `waaseyaa/bimaaji` and get the wiring automatically via
`extra.waaseyaa.providers`, and (c) mirrors every other package that owns its own provider
(e.g. `FoundationServiceProvider` in `packages/foundation/`). The alternative (a provider
in `packages/cli/`) would make the CLI package a hard coupling point for a subsystem that
M3 will also need to reach from `packages/mcp/`.

**Discovery:** `ProviderDiscovery` reads `extra.waaseyaa.providers` from each installed
package's `composer.json` (confirmed in
`packages/foundation/src/ServiceProvider/ProviderDiscovery.php:27`). Adding the FQCN to
`packages/bimaaji/composer.json` under `extra.waaseyaa.providers` is the only required
step; `PackageManifestCompiler` picks it up on the next `optimize:manifest` run.

### AD-02 — Tagged service collection for `GraphSectionProviderInterface`

**Pattern:** `ServiceProvider::tag(string $abstract, string $tag)` exists on the base
`ServiceProvider` class (`packages/foundation/src/ServiceProvider/ServiceProvider.php:107`).
The tag key is the string `'bimaaji.graph_section_providers'` — a namespaced string
constant defined in `BimaajiServiceProvider` as `public const string TAG = ...`.

**Resolution:** `BimaajiServiceProvider::register()` binds each of the 6 concrete
provider classes as singletons, then tags them. It binds `ApplicationGraphGenerator` as a
singleton whose factory resolves all tagged instances via `$this->resolveTagged(TAG)` (or
equivalent — see implementation note below). Third-party packages call
`$this->tag(TheirProvider::class, BimaajiServiceProvider::TAG)` in their own
`register()` to extend the collection.

**Implementation note on resolveTagged:** The base `ServiceProvider` exposes
`getTags(): array` and individual `resolve(string $abstract)` calls. The
`BimaajiServiceProvider` factory for `ApplicationGraphGenerator` iterates
`$this->getTags()[self::TAG]` and resolves each one. This is the same pattern used by the
container compiler in `ContainerCompiler` — no new framework machinery is needed.

**Tag identity documented in:** `packages/bimaaji/README.md` (FR-004) and
`docs/specs/bimaaji.md`.

### AD-03 — CLI command location

**Decision:** `packages/bimaaji/src/Command/GraphDumpCommand.php` — **preferred,
self-contained.**

**Rationale:** The command handler lives in the bimaaji package because:

1. `BimaajiServiceProvider` already implements `HasNativeCommandsInterface`
   (`packages/foundation/src/ServiceProvider/Capability/HasNativeCommandsInterface.php`) —
   this is the canonical way to register commands from a package without coupling to
   `packages/cli/`. The `CliKernelServiceProvider` discovers commands from all providers
   that implement this interface at manifest compile time.
2. The `graph:dump` command is conceptually part of bimaaji's own surface, not a generic
   CLI utility. Keeping it in `packages/bimaaji/` avoids a layer-direction coupling
   (cli = L6, bimaaji = L5 — cli may depend on bimaaji, but not the other way around).
3. Pattern validated by `MiscBServiceProvider`, `OptimizeServiceProvider`,
   `EntityTypeServiceProvider`, and `MigrateServiceProvider` in `packages/cli/src/Provider/`
   — all follow the same `HasNativeCommandsInterface` shape.

**Concrete shape:** `BimaajiServiceProvider` implements `HasNativeCommandsInterface`.
`nativeCommands()` yields a single `CommandDefinition` for `graph:dump`. The handler
closure resolves `ApplicationGraphGenerator` from `$this->resolve(...)` and delegates
to a thin `GraphDumpCommand` class (or inline — see WP02 for the decision on inline vs
extracted handler class).

**Discovery proof:** `PackageManifestCompiler::CAPABILITY_HAS_NATIVE_COMMANDS` is the
string `'Waaseyaa\\Foundation\\ServiceProvider\\Capability\\HasNativeCommandsInterface'`
— providers implementing it are flagged in the manifest and picked up by
`CliKernelServiceProvider`.

### AD-04 — Container dependency audit for the 6 default providers

| Provider | Constructor dependency | Bound? | Source |
|---|---|---|---|
| `EntityIntrospectionProvider` | `EntityTypeManagerInterface` | Yes | `AbstractKernel::buildHandlerContainer()` line ~625 — explicit kernel binding mapping `EntityTypeManagerInterface` → `EntityTypeManager` |
| `AdminIntrospectionProvider` | `EntityTypeManagerInterface` | Yes | Same |
| `RoutingIntrospectionProvider` | `RouteCollection` | **Gap — see below** | Not found as a discrete container binding |
| `JsonApiIntrospectionProvider` | `RouteCollection` | **Gap** | Same |
| `PublicSurfaceProvider` | `RouteCollection` | **Gap** | Same |
| `SovereigntyIntrospectionProvider` | `SovereigntyProfile` (enum) | **Gap — see below** | `SovereigntyConfigInterface` is bound, not raw `SovereigntyProfile` |
| `ApplicationGraphGenerator` | `iterable<GraphSectionProviderInterface>`, `?LoggerInterface`, `bool $strict` | Wired by `BimaajiServiceProvider` itself | — |

**RouteCollection gap:** `WaaseyaaRouter` constructs and owns a `RouteCollection`
internally (`packages/routing/src/WaaseyaaRouter.php:33`). The router is bound by the
kernel but the `RouteCollection` is not exposed as a standalone binding. The
`BimaajiServiceProvider` must bind `RouteCollection::class` as a singleton whose factory
resolves `WaaseyaaRouter` and calls `->getRouteCollection()` (or equivalent accessor). If
no public accessor exists, the plan adds a `getRouteCollection(): RouteCollection` method
to `WaaseyaaRouter` as part of WP01 (this is an additive change consistent with C-003 —
it touches routing, not bimaaji providers).

**SovereigntyProfile gap:** `SovereigntyProfile` is a PHP backed enum, not a class.
`FoundationServiceProvider` binds `SovereigntyConfigInterface`, not `SovereigntyProfile`
directly. The `BimaajiServiceProvider` binds `SovereigntyProfile::class` as a singleton
factory: `fn() => $this->resolve(SovereigntyConfigInterface::class)->getProfile()`. This
is a one-liner; no changes to `FoundationServiceProvider` needed.

**LoggerInterface:** `ApplicationGraphGenerator` takes `?LoggerInterface $logger = null`
— nullable, defaults to null. The factory passes `null` for beta (the generator emits
warnings via `?->warning()` which is a no-op on null). WP01 wires the logger if
`LoggerInterface` is resolvable; if not, null is acceptable per the existing default.

### AD-05 — Layer compliance

Bimaaji is documented as L5 (AI layer) in `docs/specs/bimaaji.md` and the CLAUDE.md
orchestration table. The current `packages/bimaaji/composer.json` requires
`waaseyaa/entity` (L1) and `waaseyaa/foundation` (L0) — both lower layers. The three
providers that need `RouteCollection` use `symfony/routing` directly (already a dependency
via `packages/bimaaji/composer.json`). None of the 6 providers import from L6 (cli,
admin-surface, etc.). `bin/check-package-layers` must pass without exemptions.

If adding `WaaseyaaRouter::getRouteCollection()` requires a routing package change, that
change stays within `packages/routing/` (L4), which bimaaji already imports. No new
`composer.json` `require` entries are expected.

---

## Test strategy

### Unit tests (`packages/bimaaji/tests/Unit/`)

**`BimaajiServiceProviderTest`** (FR-009):
- Instantiates `BimaajiServiceProvider` directly (not via the kernel).
- Calls `register()`, then asserts:
  - `getBindings()` contains `ApplicationGraphGenerator::class`.
  - `getTags()[BimaajiServiceProvider::TAG]` contains exactly 6 FQCNs.
  - Each of the 6 tagged FQCNs is also in `getBindings()`.
- Tests lazy resolution: passes a mock container stub with pre-resolved deps; asserts
  `resolve(ApplicationGraphGenerator::class)` returns an instance.
- Uses anonymous classes / stubs for container dependencies to avoid real kernel boot.

### Integration tests (`tests/Integration/PhaseN/Bimaaji/`)

The `PhaseN/` directory is the catch-all namespace for newer integration tests not yet
assigned a numbered phase. The Bimaaji subdirectory follows the same namespace convention
as `PhaseN/AgentRuntime/` and `PhaseN/EntityQueryAccessCheck/`.

**Namespace:** `Waaseyaa\Tests\Integration\PhaseN\Bimaaji\`

**`ApplicationGraphIntegrationTest`** (FR-010):
- Boots the ConsoleKernel (or a minimal test kernel that loads `BimaajiServiceProvider`).
- Resolves `ApplicationGraphGenerator` from the container.
- Calls `generate()`, asserts:
  - `getSections()` returns exactly 6 items (or ≥ 6, to allow future additions).
  - Section keys include: `entities`, `routing`, `jsonapi`, `admin`, `sovereignty`,
    `public_surface`.
  - Each section has a non-empty `version` string.
  - NFR-001: execution completes in ≤ 100 ms (measured with `hrtime(true)`; soft assertion
    logged as a warning, not a hard PHPUnit failure, to avoid flakiness on slow CI
    runners — a comment in the test documents the budget).
- Uses `#[CoversClass(ApplicationGraphGenerator::class)]`.

**`GraphDumpCommandTest`** (FR-011, FR-012, FR-013):
- Uses Symfony `CommandTester` per CLAUDE.md convention.
- Three test methods:
  - `dumpsFullGraph`: invokes `graph:dump`, parses JSON output, asserts 6 section keys.
  - `scopesToSection`: invokes `graph:dump --section=routing`, asserts only `routing` key
    present.
  - `failsOnUnknownSection`: invokes `graph:dump --section=nonexistent`, asserts non-zero
    exit, error message contains "Available sections:" with the 6 known keys.
- Does **not** boot a full HTTP kernel — uses `CliApplication` or `CommandTester` with a
  pre-wired container.
- Uses `#[CoversClass(GraphDumpCommand::class)]` (or the handler class).

---

## WP breakdown

### WP01 — Audit + ServiceProvider scaffold

**Goal:** All container gaps resolved; `BimaajiServiceProvider` exists, is wired, and
passes the unit test. `composer verify` green after this WP.

**Files created/modified:**

| File | Action |
|---|---|
| `packages/bimaaji/src/BimaajiServiceProvider.php` | Create |
| `packages/bimaaji/composer.json` | Edit — add `extra.waaseyaa.providers` |
| `packages/routing/src/WaaseyaaRouter.php` | Edit — add `getRouteCollection()` accessor if not present |
| `packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php` | Create |
| `phpstan-dead-code-baseline.neon` | Edit if new public methods trigger dead-code gate |

**Audit checklist (to perform at WP start, not defer):**

1. Verify `WaaseyaaRouter` exposes `RouteCollection` — grep for `getRouteCollection`.
   If absent, add the method (one-liner returning `$this->routes`).
2. Verify `EntityTypeManagerInterface` is resolvable — confirmed in `AbstractKernel:625`.
3. Verify `SovereigntyConfigInterface` is resolvable — confirmed in
   `FoundationServiceProvider:17`.
4. Verify `bin/check-package-layers` passes with the new `extra.waaseyaa.providers` entry.
5. Run `composer cs-check` and `composer phpstan` after creation.

**Acceptance:** `BimaajiServiceProviderTest` green; `composer verify` green.

---

### WP02 — CLI command

**Goal:** `bin/waaseyaa graph:dump` is discoverable and all three CLI integration tests pass.

**Files created/modified:**

| File | Action |
|---|---|
| `packages/bimaaji/src/BimaajiServiceProvider.php` | Edit — implement `HasNativeCommandsInterface`, add `nativeCommands()` |
| `packages/bimaaji/src/Command/GraphDumpHandler.php` | Create — thin handler class |
| `tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php` | Create |

**Command spec:**

```
graph:dump [--section=<key>] [--format=json|yaml] [--strict]
```

- `--format` default: `json`. YAML via `symfony/yaml` (already available as a transitive
  dep via `packages/foundation`; confirm before WP02 start — demote to follow-up if
  absent rather than adding a new `require`).
- `--strict` passes `strict: true` to `ApplicationGraphGenerator` constructor; the
  factory in `BimaajiServiceProvider` must accept this flag at dispatch time (resolved
  per-invocation, not at bind time — the closure in `nativeCommands()` constructs a fresh
  `ApplicationGraphGenerator` with the flag from `$io->getOption('strict')`).
- Exit codes: 0 on success; 1 on unknown section; 1 on provider exception in strict mode.
- Unknown section: collect `array_keys($graph->getSections())`, sort, output
  `"Unknown section \"<key>\". Available sections: <list>"` to stderr.

**NFR-003 (stable output):** Use `JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR` and sort
section keys alphabetically in `ApplicationGraph::toArray()`. If `toArray()` already
sorts, no change needed — verify during WP02.

**Acceptance:** `GraphDumpCommandTest` green (3 methods); `bin/waaseyaa list` includes
`graph:dump`; `composer verify` green.

---

### WP03 — Booted-pipeline integration test

**Goal:** `ApplicationGraphIntegrationTest` passes, proving the provider → container →
generator pipeline works over a real (minimal) booted kernel.

**Files created/modified:**

| File | Action |
|---|---|
| `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` | Create |

**Kernel boot strategy:** Use the lightest kernel that loads `BimaajiServiceProvider`.
Options (in preference order):

1. Instantiate `ConsoleKernel` with `APP_ENV=testing` — same pattern as
   `PhaseN/AgentRuntime/` tests.
2. If full kernel boot is too slow (> 200 ms), instantiate the providers directly via
   `ContainerCompiler` with a minimal provider list: `FoundationServiceProvider`,
   `EntityServiceProvider` (for `EntityTypeManager`), `RoutingServiceProvider` (for
   `WaaseyaaRouter`), `BimaajiServiceProvider`.

The preferred approach (1) is tried first; if it exceeds the NFR-001 budget for the
generator call alone, the test documents the overhead separately and keeps the timing
assertion on `generate()` only.

**Acceptance:** All assertions pass; NFR-001 budget noted in test comment; `composer verify` green.

---

### WP04 — README + spec + docs refresh

**Goal:** All documentation FRs satisfied (FR-007, FR-008); CLAUDE.md gains the
"Adding a Bimaaji graph section provider" checklist.

**Files created/modified:**

| File | Action |
|---|---|
| `packages/bimaaji/README.md` | Rewrite |
| `docs/specs/bimaaji.md` | Edit — Implementation Status section |
| `CLAUDE.md` | Edit — Operation Checklists |
| `CHANGELOG.md` | Edit — `[Unreleased]` entry |

**README rewrite outline:**

1. One-line description (drop "scaffolding only").
2. Installation: `composer require waaseyaa/bimaaji` (dev dep for most consumers).
3. Default providers table: key, class, description, constructor deps.
4. Usage: `bin/waaseyaa graph:dump` invocation examples.
5. Extension: how to register a third-party `GraphSectionProviderInterface` impl
   (tag name `BimaajiServiceProvider::TAG`).
6. Link to `docs/specs/bimaaji.md`.

**CLAUDE.md addition** (under "Operation Checklists"):

```
**Adding a Bimaaji graph section provider:**
1. Create class implementing `GraphSectionProviderInterface` in your package's
   `src/Bimaaji/` directory.
2. Implement `getKey(): string` (unique snake_case key) and `provide(): GraphSection`.
3. In your `ServiceProvider::register()`, call:
   `$this->singleton(YourProvider::class, YourProvider::class);`
   `$this->tag(YourProvider::class, \Waaseyaa\Bimaaji\BimaajiServiceProvider::TAG);`
4. Run `bin/waaseyaa optimize:manifest` (or restart dev server).
5. Verify with `bin/waaseyaa graph:dump` — your section key should appear.
```

**`docs/specs/bimaaji.md` edit:** Flip Implementation Status from any "scaffolding" /
"deferred" framing to:

```
**Implementation Status (as of bimaaji-wakeup-01KS5VEY, alpha.188+):** Shipped.
`BimaajiServiceProvider` wires `ApplicationGraphGenerator` with the 6 default providers
as a tagged service collection. `bin/waaseyaa graph:dump` is the first-party CLI surface.
Integration tests live in `tests/Integration/PhaseN/Bimaaji/`.
```

**Acceptance:** `SC-004` satisfied (README no longer says "scaffolding"); drift detector
passes for `docs/specs/bimaaji.md`; PR reviewer confirms CLAUDE.md checklist is clear.

---

### WP05 — Cross-mission gate + full verify

**Goal:** Confirm SC-005 (M2 can resolve `ApplicationGraphGenerator` without additional
bimaaji wiring) and SC-006 (`composer verify` green on a clean merge commit).

**Files created/modified:**

| File | Action |
|---|---|
| `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` | Edit — add `crossMissionGate` test method |

**Cross-mission gate test** (`crossMissionGateSc005`):

A test method annotated `#[CoversNothing]` that:

1. Boots the same minimal kernel as WP03.
2. Resolves `ApplicationGraphGenerator::class` from the container.
3. Asserts the instance is not null — no `NotFoundException`.
4. Asserts `generate()` returns an `ApplicationGraph` with ≥ 1 section.
5. Comment: "SC-005: M2's first WP must be able to resolve ApplicationGraphGenerator
   without modifying packages/bimaaji/. This test is the CI proof."

**Full verify checklist:**

- `composer verify` (dead code + getquery bindings + phpstan + cs-check)
- `bin/check-package-layers`
- `bin/check-composer-policy`
- `./vendor/bin/phpunit --testsuite Unit` and `--testsuite Integration`
- Manual: `bin/waaseyaa graph:dump` on a local clone with `composer install` only

**Acceptance:** All gates green; PR created with mission ID + WP references in body;
squash-merged to `main`.

---

## File-change summary table

| File | WP | Action | Notes |
|---|---|---|---|
| `packages/bimaaji/src/BimaajiServiceProvider.php` | WP01 + WP02 | Create | Core deliverable |
| `packages/bimaaji/composer.json` | WP01 | Edit | Add `extra.waaseyaa.providers` |
| `packages/routing/src/WaaseyaaRouter.php` | WP01 | Edit (conditional) | Add `getRouteCollection()` if absent |
| `packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php` | WP01 | Create | FR-009 |
| `packages/bimaaji/src/Command/GraphDumpHandler.php` | WP02 | Create | Handler for `graph:dump` |
| `tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php` | WP02 | Create | FR-011/012/013 |
| `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` | WP03 + WP05 | Create | FR-010 + SC-005 gate |
| `packages/bimaaji/README.md` | WP04 | Rewrite | FR-007 |
| `docs/specs/bimaaji.md` | WP04 | Edit | FR-008 |
| `CLAUDE.md` | WP04 | Edit | Operation Checklists addition |
| `CHANGELOG.md` | WP04 | Edit | `[Unreleased]` bullet |
| `phpstan-dead-code-baseline.neon` | WP01 (if needed) | Edit | New `getRouteCollection()` or `@api` markers |

---

## Risk analysis

### R1 — `RouteCollection` not exposed from container (HIGH likelihood, LOW mitigation cost)

The grep confirmed `WaaseyaaRouter` owns the `RouteCollection` privately. Adding
`getRouteCollection(): RouteCollection` to `WaaseyaaRouter` is a one-liner and does not
change any existing interface. Risk: the dead-code gate may flag the new method if
nothing else calls it. Mitigation: add `@api` to the method (it is an intentional
extension point for bimaaji and future consumers).

### R2 — Layer compliance for `packages/routing` → `packages/bimaaji` direction (LOW)

Bimaaji (L5) imports from routing (L4) — this is correct downward. The reverse
(routing importing from bimaaji) must never happen. The `getRouteCollection()` addition
to routing has no bimaaji imports. `bin/check-package-layers` verifies this.

### R3 — `symfony/yaml` not available as a direct dep (MEDIUM likelihood)

`packages/bimaaji/composer.json` does not list `symfony/yaml`. It is available
transitively via `waaseyaa/foundation`, but relying on transitive deps is fragile.
Mitigation (WP02): check `composer show symfony/yaml` in the bimaaji vendor context; if
absent as a direct dep, add it or downgrade `--format=yaml` to a follow-up. The spec
assumption notes this risk.

### R4 — `SovereigntyProfile` as a PHP enum (LOW)

PHP backed enums can be used as container keys with `SovereigntyProfile::class`. The
`ServiceProvider::singleton()` method takes `string $abstract` — `SovereigntyProfile::class`
is a valid string. Confirmed PHP 8.5 compatibility. Risk is minimal.

### R5 — Dead-code gate on new `BimaajiServiceProvider` methods (MEDIUM)

`nativeCommands()`, `register()`, and `TAG` constant are all reflection-discovered
entrypoints. `register()` is called by `ContainerCompiler`; `nativeCommands()` is called
by `CliKernelServiceProvider` via the `HasNativeCommandsInterface` check in
`PackageManifestCompiler`. Both paths are string-based, not direct-call. The
`WaaseyaaEntrypointProvider` in `tools/phpstan/` already marks `ServiceProvider`
subclasses as used if registered via `extra.waaseyaa.providers` — verify this covers
`HasNativeCommandsInterface` implementors or add a baseline entry.

### R6 — M-G "PHP-only" posture vs. this mission (RESOLVED, NOT A RISK)

The 2026-05-20 M-G decision deferred *external* exposure (MCP, HTTP). This mission wires
*internal* surfaces only. The design doc (D3) explicitly reverses the deferred-external
posture for M3 only. M1 is fully consistent with M-G. No conflict.

### R7 — Integration test kernel boot phase number (LOW)

`PhaseN/` is the conventional catch-all for newer integration tests (confirmed by
directory listing). No phase number assignment needed. Test namespace:
`Waaseyaa\Tests\Integration\PhaseN\Bimaaji\`.

---

## Dependencies on downstream missions

This plan deliberately does not design M2/M3/M5 internals. The only forward-looking
constraint is SC-005: after M1 merges, M2's first WP must be able to do
`$container->get(ApplicationGraphGenerator::class)` without touching `packages/bimaaji/`.
The cross-mission gate test in WP05 is the CI proof.

M3 (MCP bridge) depends on the tag identity `BimaajiServiceProvider::TAG` being stable.
Treat it as a public API after M1 merges — do not rename without a deprecation cycle.
