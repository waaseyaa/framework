# Feature Specification: CLI Command DI Resolution (`make:public`, `route:list`)

**Mission:** `cli-command-di-resolution-01KVGEPD` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-19

## Summary

Two console commands crash on a stock downstream app (`C:\Users\jones\Codex\my-app`, patched to alpha.226) because of how they are wired into the CLI handler container, not because of any logic bug in the commands themselves:

- `make:public --force` — the **canonical** way to (re)install an app's front controller — crashes because `MakePublicHandler` declares a required scalar `string $projectRoot` constructor parameter and is wired by class-reference, so the kernel handler container cannot auto-wire it.
- `route:list` crashes because `WaaseyaaRouter` is never bound in any container; it is only ever constructed per-request inside `HttpKernel`, so the console path has nothing to resolve.

Both are high-leverage operator commands. `make:public` being broken means there is no clean CLI path to repair a front controller; `route:list` being broken removes the operator's primary way to see why a route (e.g. D5's `/story/{uuid}`) does not resolve. This mission makes both commands resolve and run correctly from a booted `ConsoleKernel`, and closes the test gaps that let the defects ship.

**Constraint:** no deployed downstream apps depend on the current (broken) behaviour, so prefer the correct clean wiring over compatibility shims.

## Actors

- **App operator / cold agent** — runs `waaseyaa make:public --force` to install/repair `public/index.php`, and `waaseyaa route:list` to inspect the served route table, from a downstream app on Windows or POSIX.
- **Framework maintainer** — relies on CLI integration tests exercising the real kernel handler container, not hand-built fakes.

## Evidence (the failures to eliminate)

1. **D2 — `make:public --force` crash.** `make:public` is registered with the class-reference handler form `handler: [MakePublicHandler::class, 'execute']` ([packages/cli/src/Provider/MakeServiceProviderB.php:86](packages/cli/src/Provider/MakeServiceProviderB.php)). At dispatch `HandlerCommand::resolveHandler()` calls `$this->container->get(MakePublicHandler::class)` ([packages/cli/src/Command/HandlerCommand.php:142](packages/cli/src/Command/HandlerCommand.php)). `MakePublicHandler::__construct(private readonly string $projectRoot)` has a required scalar with no default ([packages/cli/src/Handler/MakePublicHandler.php:15](packages/cli/src/Handler/MakePublicHandler.php)). `MakeServiceProviderB::register()` is an empty no-op, so no binding exists; reflection auto-wiring in `AbstractKernel::buildHandlerContainer()` skips builtin `string` and, finding no default, throws "Cannot auto-wire …: unresolvable parameter `$projectRoot`" ([packages/foundation/src/Kernel/AbstractKernel.php:954-977](packages/foundation/src/Kernel/AbstractKernel.php)).
2. **D2 — proven-good sibling pattern.** `make:content-type` avoids this by eagerly building `new MakeContentTypeHandler(projectRoot: $projectRoot)` in a closure ([MakeServiceProviderB.php:52](packages/cli/src/Provider/MakeServiceProviderB.php), `$projectRoot` from `$this->projectRoot ?: getcwd()` at line 27). `make:public` is the only make handler that both has a required scalar param **and** is wired by class-reference.
3. **D2 — masked by a fake container.** `MakePublicCommandTest` substitutes a fake container that hard-codes `new MakePublicHandler($this->projectRoot)` ([packages/cli/tests/Integration/Command/Make/MakePublicCommandTest.php:88-103](packages/cli/tests/Integration/Command/Make/MakePublicCommandTest.php)), so the real `KernelHandlerContainer` path is never exercised — green CI, broken in production.
4. **D3 — `route:list` crash.** The `route:list` closure calls `$this->resolve(\Waaseyaa\Routing\WaaseyaaRouter::class)` ([packages/cli/src/Provider/MiscBServiceProvider.php:79](packages/cli/src/Provider/MiscBServiceProvider.php)). `WaaseyaaRouter` has no `bind()`/`singleton()`/kernel-binding anywhere; `ServiceProvider::resolve()` falls through to the kernel-services bus, finds nothing, and throws `RuntimeException('No binding registered for Waaseyaa\Routing\WaaseyaaRouter.')` ([packages/foundation/src/ServiceProvider/ServiceProvider.php:149](packages/foundation/src/ServiceProvider/ServiceProvider.php)).
5. **D3 — request-scoped only.** The single non-test construction is `new WaaseyaaRouter($context)` inside `HttpKernel::serveHttpRequest()` ([packages/foundation/src/Kernel/HttpKernel.php:285](packages/foundation/src/Kernel/HttpKernel.php)), populated by `BuiltinRouteRegistrar::register()` (line 286-287). The console path never builds a router.
6. **D3 — masked test.** `RouteListHandlerTest` injects a hand-built router and never covers the missing-binding path.
7. Both defects reproduce against the shipped artifact: my-app's `vendor/waaseyaa/cli/...` (alpha.226) carries identical wiring.

## User Scenarios & Testing

1. **Reinstall a front controller.** An operator in a downstream app runs `waaseyaa make:public --force`; the command exits 0 and writes `<root>/public/index.php` from the canonical stub ([packages/cli/templates/public/index.php.stub](packages/cli/templates/public/index.php.stub)), resolving its `$projectRoot` from the current working directory.
2. **First-time front controller.** `waaseyaa make:public` (no `--force`) on an app with no `public/index.php` writes the stub and exits 0; with an existing file and no `--force`, it declines without crashing.
3. **Inspect routes.** An operator runs `waaseyaa route:list`; the command exits 0 and prints the actual served route table (built-in framework routes plus app/provider routes), so the operator can confirm what URL shapes are registered (directly supports diagnosing D5).

### Edge cases

- `make:public` run from a directory that is not an app root must fail with a clear, non-crashing message (not an unresolvable-parameter exception).
- `route:list` must distinguish "no routes registered" from "router could not be built"; it must never print "No routes found." when the real cause is a missing binding.
- The CLI now runs on Symfony Console (`HandlerCommand`/`WaaseyaaConsoleApplication` extend Symfony Console as of commit 614d88f47); error rendering must not swallow the real failure cause. *(See Assumptions — the CLAUDE.md "hand-rolled, not Symfony Console" note is stale and should be corrected by the spec-maintenance follow-up.)*

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | `make:public` and `make:public --force` MUST resolve and execute through the real kernel handler container (a booted `ConsoleKernel` / `AbstractKernel::buildHandlerContainer()`), with `$projectRoot` supplied by the same eager-instantiation pattern used by `make:content-type` (closure capturing `$this->projectRoot ?: getcwd()`). | Proposed |
| FR-002 | `make:public --force` MUST write `<projectRoot>/public/index.php` from the canonical stub and exit 0; `make:public` without `--force` MUST not overwrite an existing front controller and MUST exit non-zero with a clear message. | Proposed |
| FR-003 | `route:list` MUST resolve a fully-populated `WaaseyaaRouter` in the console context and exit 0, printing the served route collection (paths/names) including framework built-ins. | Proposed |
| FR-004 | The console-resolved route collection MUST be built through the SAME source of truth as the HTTP path (`BuiltinRouteRegistrar` over the kernel's entity-type manager + providers), so `route:list` reflects the actually-served table rather than a partial or duplicated set. | Proposed |
| FR-005 | A sweep MUST confirm no other class-referenced CLI handler declares a required non-auto-wireable scalar constructor parameter; any found MUST be wired via the eager/closure pattern or reported. | Proposed |

### Non-Functional / Security (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | `route:list` is read-only and MUST NOT register, mutate, or persist routes as a side effect of listing them. | Proposed |
| NFR-002 | Route composition for the CLI MUST keep a single source of truth (`BuiltinRouteRegistrar`); route definitions MUST NOT be duplicated into the CLI provider. | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | No BC shims / deprecation layers — no deployed downstream apps depend on the broken behaviour. | Accepted |
| C-002 | Fix MUST be scoped to wiring/binding (CLI provider + kernel handler container) and MUST NOT change the command/handler behaviour contracts. | Accepted |

## Success Criteria

- SC-001: `make:public --force` exits 0 and writes the stub when run against a temp project root through a booted console kernel (no fake container).
- SC-002: `route:list` exits 0 and lists known framework routes (e.g. a `/api/...` path, `public.page`) through a booted console kernel.
- SC-003: New tests fail against the current `main` (reproduce both crashes) and pass after the fix; the fake-container path in `MakePublicCommandTest` no longer hides the defect.
- SC-004: `composer verify` (cs/phpstan/dead-code/getquery gates) stays green.

## Key Entities

- `MakeServiceProviderB`, `MakePublicHandler`, `MiscBServiceProvider`, `RouteListHandler` — CLI wiring/handlers.
- `AbstractKernel::buildHandlerContainer()` / `ConsoleKernel` — the resolution surface.
- `WaaseyaaRouter`, `BuiltinRouteRegistrar` — route composition (routing boundary).

## Assumptions

- A-001: The eager-instantiation (closure) pattern is the intended remedy for handlers needing `$projectRoot`, mirroring `make:content-type`; a broader container feature for scalar binding is out of scope.
- A-002: `route:list` is allowed to boot the framework (`canRunWithoutFrameworkBoot` is true only for no-arg/`--version`), so relying on a booted kernel is acceptable.
- A-003: The CLI runtime extends Symfony Console (commit 614d88f47); the CLAUDE.md statement that the CLI is "hand-rolled … does NOT use Symfony Console" is stale — flagged for a spec-maintenance correction, not in scope to fix here.

## Scope

**In:** wiring fixes for `make:public` (eager `$projectRoot`) and `route:list` (resolvable, fully-populated `WaaseyaaRouter` via `BuiltinRouteRegistrar`); real-container CLI integration tests for both; a sweep for sibling latent scalar-auto-wire failures.

**Out:** changing the stub template contents; changing route definitions or precedence (that is D5 / #1632); a generic scalar-binding container feature; the broader policy-boot auto-instantiation issue (#1623, related pattern only).
