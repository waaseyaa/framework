# Framework route-hook audit for #3123

Baseline: `5196f173b06bac698f14d292c7b827f2ddac388f`.

Scope: all tracked production `routes()` definitions under `packages/**/src`,
the generated provider emitted by `PublishedContentRecipe`,
`BuiltinRouteRegistrar`, and known consumer/downstream leads. Search established
the roster; every listed body and delegated helper was then classified against
the proposed pure-metadata contract. This is a complete Framework source roster
at the baseline, not a complete roster of arbitrary external application code.

## Composition authority

`BuiltinRouteRegistrar` emits 14 pre-provider routes, invokes each provider's
legacy hook, emits `public.home` and `public.page`, then sorts by priority. Its
built-ins are scalar/string definitions, but it constructs Routing values in
Foundation and accepts arbitrary hook behavior. It therefore requires migration
to Foundation metadata values and an atomic completed snapshot. Current ordering
is priority descending with insertion order for ties. The proposed source
cohorts preserve that behavior.

`ServiceProviderInterface` and `ServiceProvider` define the legacy hook and
explicitly recommend lazy closures. That advice prevents some boot-scoped
service reuse but conflicts with immutable pure metadata. Both declarations
require replacement/deprecation under #1866. A legacy hook is never invoked by
metadata inspection.

The transitional HTTP registrar must preserve mixed cohorts from a closed
manifest classification: `none` for the inherited Foundation no-op,
`declarative` for the new capability, and `legacy` for an actual custom hook.
For each provider exactly once per epoch it performs no route work, compiles the
pure contribution, or invokes the legacy hook. This prevents route disappearance
and double registration as packages migrate independently. CLI, Bimaaji, and
canonical snapshot publication refuse actual `legacy` contributors. They ignore
`none` for route purposes, while the provider keeps its unrelated register/boot
behavior. Unknown or stale inventory refuses readiness.

## Provider roster and disposition

| Package and hook | Substantive classification | Migration disposition / owner |
|---|---|---|
| `admin-surface/AdminSurfaceServiceProvider` | Resolves/custom-builds admin hosts and optional page-builder services; reads the Admin `dist/index.html`; builds session-cookie policy; captures project/vendor/content/CSRF values in a closure. This mixes availability, file delivery, and execution state. | Declare stable paths/access/handler IDs only. Move file reads, HTML rewriting, host construction, and optional service resolution behind request execution. Coordinate #3083 and add `admin-surface` to #3122. |
| `ai-agent/Routing/AgentRouteServiceProvider` | Captures a provider-bound controller factory in four closures. Construction is delayed but the published route retains a live provider and callable. | Four request-local service handler IDs. Preserve current authentication and permissions. Add `ai-agent` audit. |
| `api/ApiServiceProvider` | Most handlers are strings. It constructs route-helper objects, uses explicit config/autoload presence for content search, and resolves `TransitionService` to decide workflow-route presence. Entity exposure facts also affect JSON:API definitions. | Convert helpers to immutable definitions. Replace workflow service resolution with finalized capability presence. Record entity-exposure/config inputs without retaining ETM. Add `api` audit. |
| `debug/DebugServiceProvider` | Reads debug enablement and eagerly constructs `ErrorPreviewController`. | Finalized debug boolean plus a request-local service handler. Add `debug` audit. |
| `genealogy/GenealogyServiceProvider` | Four scalar class-method routes; no resolution or construction in the hook. | Opt into the new contract explicitly and register each class handler for request-local resolution. Automatic legacy adaptation is forbidden. Add `genealogy` audit. |
| `graphql/GraphQlServiceProvider` | Constructs `GraphQlRouteProvider`, a route-definition helper, then registers scalar handler strings. The helper is not an execution controller, but it still mutates a live router. | Return immutable definitions directly or from a pure metadata factory. Add `graphql` audit. |
| `mcp/McpServiceProvider` | Constructs `McpRouteProvider` using parsed public-endpoint and OAuth-resource configuration. Helper output is metadata, but configuration validation and optional route decisions must be explicit inputs. | Pure definition factory over copied non-secret config. Preserve malformed-config refusal without leaking values. Add `mcp` audit. |
| `routing/AuthOidcRouteServiceProvider` | Auth helper resolves config, repositories, rate limiters, mailer, identity services, extensions and logger, then constructs controller objects. OIDC presence uses controller class checks; selected resolution failures are logged and routes omitted, so completeness is not structured. | Declarative auth/OIDC routes with request-local service IDs. Installed/configured absence is explicit; service failure cannot withdraw a route during inspection. Routing production owner #3125; auth/OIDC packages remain acceptance participants unless their code changes. |
| `ssr/SsrServiceProvider` | Three scalar class-method routes with public/render/priority metadata. | Explicit contributor adoption and request-local class-service registrations. Add `ssr` audit. |
| `wayfinding/WayfindingServiceProvider` | Four scalar class-method routes with public/auth/permission and priority metadata. | Explicit contributor adoption and request-local class-service registrations. Add `wayfinding` audit. |
| `workspace/WorkspaceServiceProvider` | One scalar class-method static-asset route. | Explicit contributor adoption; file serving remains execution. Add `workspace` audit. |
| generated `cli/Site/Recipe/PublishedContentRecipe` provider | Emits per-bundle closures that capture the generated provider and resolve `PublishedContentController`; sitemap is also a closure. Bundle definitions determine route names/paths. | Generator emits immutable definitions and stable request-local service IDs. Generated consumer test must prove nonempty output. CLI audit already exists; generated application shape needs qualification. |
| `skeleton/src/Provider/AppServiceProvider` | The application template contributes a closure that constructs a probe response at dispatch. It captures no live object, but a closure is still incompatible with a data-only snapshot. | Replace the template closure with an application service handler ID and qualify a newly generated skeleton. This is a downstream template change, not a twelfth Framework package hook or a package-audit addition. |

There are 11 concrete tracked provider hooks, plus the generated hook, the
Foundation base/interface declarations, and `BuiltinRouteRegistrar`. Test-only
anonymous hooks are fixtures, not production contributors, and must be updated
where needed to discriminate the new contract.

## Reproducible source roster

From a clean checkout of the baseline SHA, the production hook roster is
reproduced with:

```powershell
git rev-parse HEAD
rg -n "function routes\s*\(" packages skeleton --glob "*.php" --glob "!**/tests/**" --glob "!**/Tests/**"
```

The semantic review followed the bodies and these delegated helpers at the
baseline:

- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php:33-219`, including
  provider invocation at `186-188`;
- `packages/foundation/src/ServiceProvider/ServiceProviderInterface.php:40-55`
  and `ServiceProvider.php:62-70`;
- `packages/foundation/src/Discovery/PackageManifestCompiler.php:156-177`, where
  the existing compiler autoloads provider classes through `class_exists()`,
  reads their interfaces through `class_implements()`, and begins its separate
  reflected declaration scan; the proposed provider-method reflection belongs
  to this discovery boundary and records a versioned classification-input
  digest over the leaf, ancestry, traits, effective route method, capability,
  and Foundation base identities as applicable, without executing the hook;
- `packages/entity/src/EntityTypeManager.php:447-450`, whose
  `getDefinitions()` is the in-memory finalized-definition snapshot read; the
  storage and repository construction paths are separate at `457-509`;
- `packages/routing/src/AuthOidcRouteServiceProvider.php:49-288` and
  `OidcHttpRoutes.php:30-113`;
- `packages/api/src/ApiServiceProvider.php:439-824` and
  `JsonApiRouteProvider.php:41-345`;
- `packages/admin-surface/src/AdminSurfaceServiceProvider.php:250-310`, route
  helpers at `356-393` and `427-474`;
- `packages/ai-agent/src/Routing/AgentRouteServiceProvider.php:58-126`;
- `packages/debug/src/DebugServiceProvider.php:18-32`;
- `packages/genealogy/src/GenealogyServiceProvider.php:89-133`;
- `packages/graphql/src/GraphQlServiceProvider.php:43-46` and
  `GraphQlRouteProvider.php:15-25`;
- `packages/mcp/src/McpServiceProvider.php:316-322` and
  `McpRouteProvider.php:55-109`;
- `packages/ssr/src/SsrServiceProvider.php:127-160`;
- `packages/wayfinding/src/WayfindingServiceProvider.php:101-153`;
- `packages/workspace/src/WorkspaceServiceProvider.php:33-48`;
- `packages/cli/src/Site/Recipe/PublishedContentRecipe.php:214-315`, whose
  generated hook string is at `292-307` and definitions reader at `309-313`;
- `skeleton/src/Provider/AppServiceProvider.php:28-42`.

Line anchors apply to the named baseline and are review locators, not portable
symbol identity. The review separately classified helper construction, captured
values, declaration reads, and execution-service work; the search result alone
does not establish purity.

## Consumers and downstream leads

- `HttpKernel` currently creates a router, runs `BuiltinRouteRegistrar`, and
  copies matched defaults. It must compile and match the canonical snapshot.
- CLI `MiscBServiceProvider` currently creates a registrar without discovered
  providers, the cause of incomplete `route:list` composition tracked by #3007.
  The command must consume the completed snapshot. Existing `writeRaw()` is
  already a lossless output path; output transport does not need redesign.
- Bimaaji `RoutingIntrospectionProvider` currently accepts a route collection
  and can serialize arbitrary `_controller` objects unsafely. It must project
  stable handler IDs and declared access from the snapshot.
- FETDER #145 uses Framework alpha.301 CLI and Bimaaji, but no complete FETDER
  application-provider hook roster has been audited. Its installed consumer
  acceptance is required; arbitrary downstream compatibility remains unknown.
- Domain-router sentinels are only references into an execution chain. Matching
  parity does not prove every sentinel has a supporting router in each install;
  that is #3013 acceptance.

## Exact package disposition

Expected production changes: `foundation`, `routing`, `admin-surface`,
`ai-agent`, `api`, `debug`, `genealogy`, `graphql`, `mcp`, `ssr`, `wayfinding`,
`workspace`, `cli`, and `bimaaji`.

The closed participation inventory correction does not expand this 14-package
roster. Ordinary packages whose providers inherit Foundation's no-op are
classified `none` and require no empty route contributor. Direct interface
implementations, inherited custom overrides, and trait-provided overrides are
classified from method provenance and cannot silently fall into `none`.

The repository `skeleton` template also changes, but it is not a Composer split
package. Generated applications created before the cutover are downstream
legacy providers and receive the same explicit incompatibility/refusal until
migrated.

Expected unchanged production packages: `auth` and `oidc` because Routing owns
the adapter; `access` because it enforces declared restrictions downstream;
`entity` because metadata must no longer receive an ETM; packages implementing
Foundation domain routers because their built-in sentinel execution contract is
not changed by route declaration. These classifications must be revisited if
implementation touches their source or cannot establish request-local handler
bindings without it.

## Evidence and limits

Inputs included repository source, existing package audits, live #3123/#3122
discussion, and the mechanical inventory produced for this work. No provider,
kernel, controller, dependency factory, or route handler was executed in this
documentation lane. The separate source-consumer regression owns executable
evidence: its real HTTP path passes one test and 11 assertions; its direct
registrar/Bimaaji inspection path reaches all intended assertions and then fails
on `execution_services_constructed`, actual one versus expected zero. Combined:
two tests, 22 assertions, one failure. This is not Console `graph:dump`, a split
or packaged install, or the future contributor API. Inventory and classification
establish the migration roster, not runtime remediation, distribution
qualification, or whole-package convergence.

Pending acceptance for the undefined future API must include: a `none` plus
`declarative` cohort that succeeds, an actual `legacy` cohort that refuses
without hook invocation, and stale/unknown/classification-input-mismatched
inventory that refuses without inspection-time recompile. Drift controls must
hold the leaf provider unchanged while changing its parent route override,
imported trait, contributor capability/interface, and Foundation base no-op in
turn. Runtime inspection compares only an already bootstrap-validated token and
record, with no source read/hash, autoload, reflection, or recompile. The
existing red test does not claim those controls.
