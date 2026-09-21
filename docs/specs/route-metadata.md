# Route metadata composition

Status: **proposed for Framework #3123**. This document records the accepted
target and migration contract. It does not describe behavior available at
`5196f173b06bac698f14d292c7b827f2ddac388f`.

## Purpose and boundary

The application route table is a completed, immutable metadata snapshot. The
same definitions feed HTTP matching, URL generation, `route:list`, and Bimaaji
graph inspection. Matching and inspection do not construct controllers, resolve
execution services or credentials, start sessions, query application data,
perform durable writes, invoke handlers, or retain request state.

This is a route production and inspection contract. Ordinary kernel boot may
intentionally perform work outside this boundary. A metadata-only acceptance
test must therefore measure calls made while collecting and inspecting routes,
separately from any broader boot activity.

Route metadata describes declared transport access. Effective access can be
narrower after middleware, entity and field policy, domain-router, and handler
checks. Route-table parity does not establish full dispatcher parity, which
remains separately owned by #3013, or effective access parity, owned by #3004.

## Ownership and dependency direction

`waaseyaa/foundation` owns the layer-neutral contribution protocol, value
objects, collection lifecycle, readiness states, completed snapshot, and
composition input identity. Service providers already belong to Foundation,
so their contribution contract must not import Layer 4 Routing types. This
removes the existing `ServiceProvider::routes(WaaseyaaRouter, ...)` coupling
tracked by #1866 rather than introducing a new Foundation to Routing cycle.

`waaseyaa/routing` validates and compiles Foundation metadata values to Symfony
routes, matches and generates URLs, and resolves a handler reference only after
a request has matched. Foundation's HTTP kernel may compose these pieces under
its existing kernel exemption. CLI and Bimaaji consume the completed Foundation
snapshot and do not rebuild it.

## Contribution API

The proposed provider capability is conceptually:

```php
interface ContributesRouteMetadataInterface
{
    /** @return iterable<RouteDefinition> */
    public function routeDefinitions(RouteContributionContext $context): iterable;
}
```

The exact PHP names may change during implementation, but these semantics may
not. `RouteContributionContext` exposes copied, finalized declaration inputs:

- non-secret scalar, list, and map configuration selected for route exposure;
- installed-capability presence facts computed without loading an execution
  controller or resolving a service;
- an immutable entity-definition and API-exposure projection produced after
  entity registration and exposure declarations freeze;
- the contribution source ID and stable source order.

It exposes no container, entity manager, repository, credential resolver,
session, request, provider instance, live controller, or general callable.
Capability presence means that an installed and configured feature declares a
route. It does not assert that the route's execution service is healthy.

The entity projection contains only the IDs, bundle/path components, and
exposure decisions needed to generate routes. Foundation creates it after the
existing entity registration phase is finalized by snapshotting
`EntityTypeManager::getDefinitions()`. At the baseline that method returns the
in-memory registered definition array directly and does not construct storage
or repositories (`packages/entity/src/EntityTypeManager.php:447-450`). The
projection reads only definition metadata getters and copied, finalized,
non-secret scalar exposure config. It does not change the entity registration
source or roster. It validates and freezes the projected values before the
route epoch.

Projection generation may not call `getStorage()`, `getRepository()`,
`resolveFieldDefinitions()`, resolve any other service, query storage, inspect
application data, autoload a class, invoke a provider, or call an execution
factory. Installed-capability facts are supplied by the already-compiled
package manifest/declaration inventory, not by `class_exists()` or controller
resolution. The context never exposes the manager or exposure policy. If entity
registration or exposure declarations have not finalized successfully, route
composition is unavailable rather than partial. Moving a prohibited operation
into a pre-epoch projection step does not satisfy this contract.

PHP cannot sandbox an arbitrary contributor. The supported contract is enforced
by the restricted context, value validation, code review, and poison acceptance
fixtures. A contributor that performs hidden global I/O violates the contract.
The framework does not claim to make malicious PHP pure.

### Closed route-participation inventory

The existing manifest compile/discovery phase must add one closed classification
for every declared provider: `none`, `declarative`, or `legacy`. Each record
contains the provider FQCN, classification, declaring method/capability owner,
versioned classification-input digest, and compiler schema/code identity. It
contains no local source path. The classification-input digest binds the leaf
provider declaration; the transitive provider parent chain; recursively used
traits and trait aliases; the effective `routes()` declaring method owner and
source; the pure contributor capability/interface definition and effective
implementation when applicable; and Foundation's base no-op method and class
identity when `none` is claimed. The digest uses content and symbolic
provenance, never absolute source locations.

This inventory is produced during ordinary manifest bootstrap,
before route contribution begins, and is an input to the composition epoch.

The compiler already autoloads declared provider classes with `class_exists()`
and reads interfaces with `class_implements()` at
`PackageManifestCompiler.php:156-172`; the same compile pass uses
`ReflectionClass` for scanned declaration classes beginning at line 174. The
proposed provider-method reflection belongs to that existing discovery boundary.
It determines provenance without instantiating a provider or executing
`routes()`. Manifest bootstrap validates the compiled classification-input and
cohort/compiler identities and hands route composition an already validated
inventory token plus records. Route contribution and inspection compare only
that token and record identity. They may not locate source, read or hash source
files, autoload, reflect, or silently recompile. A missing record, identity
mismatch, unknown enum value, or unvalidated token makes route readiness
unavailable and requires a separate manifest compile/bootstrap.

Classification is deterministic:

1. a provider implementing the pure route contributor capability is
   `declarative`; this takes precedence even while it retains a compatibility
   `routes()` method;
2. otherwise, a `routes()` method whose declaring class is exactly Foundation's
   base `ServiceProvider` no-op is `none`;
3. every other concrete `routes()` implementation is `legacy`;
4. a declared provider whose method/capability provenance cannot be established
   is unknown and refuses manifest/route readiness.

Using the reflected declaring class correctly treats an inherited custom parent
override and a method imported from a trait as `legacy`, while an ordinary class
inheriting Foundation's no-op is `none`. A direct interface implementation that
declares `routes()` is also `legacy` unless it implements the pure capability.
Existing provider eligibility rules still apply separately. `none` providers
remain part of ordinary registration and boot; only route contribution ignores
them.

## Definition shape

Every `RouteDefinition` is deeply immutable and contains only scalar values and
immutable Foundation value objects:

- unique route name;
- path;
- normalized uppercase HTTP methods;
- host pattern, schemes, and Symfony condition expression;
- parameter requirements and defaults;
- routing options, including priority and parameter bindings;
- one `HandlerReference`;
- declared access requirements (`public`, authenticated, session, permission,
  role, gate, and CSRF posture);
- rendering, JSON:API, and refusal-transport metadata;
- contribution source ID and declaration ordinal.

Defaults and options must contain only null, booleans, integers, finite floats,
strings, and recursively immutable lists/maps of those values. Objects,
resources, closures, callable arrays, and invokable values are rejected before
publication. Secret configuration values are forbidden from definitions,
diagnostics, and composition identity material.

Path, host, schemes, methods, requirements, defaults, options, and condition are
all lossless metadata fields. An absent field retains Symfony's ordinary
default. A condition is stored as its expression string and evaluated by the
Routing compiler/matcher at request time with Symfony's supported expression
context. Contribution and inspection never evaluate it. An expression form
that cannot be represented and compiled without executing application PHP is
rejected as unsupported; it is never omitted or coerced. The same fail-closed
rule applies to unsupported nested defaults and options.

### Handler references

The serialized forms are stable identifiers:

- `builtin:<sentinel>` identifies a reviewed Foundation domain-router sentinel,
  such as `builtin:render.page` or `builtin:media.download`. The allowed roster
  is explicit. Arbitrary strings cannot silently become built-ins.
- `service:<service-id>::<method>` identifies a container service and public
  method. The service ID and method syntax are validated during composition,
  but the service is resolved from the request-local execution resolver only
  after matching.
- `class:<fqcn>::<method>` is shorthand for a class-string service ID. It is
  allowed only when the application's compiled service declarations explicitly
  register that FQCN for request-local resolution. Inspection does not call
  `class_exists`, autoload, reflect, instantiate, or infer constructor wiring.

Both service forms use the same request-aware resolver. It performs lookup only
after matching and supplies the current request execution context. The lookup
honors the service container's explicit lifetime declaration: a shared service
remains shared, while a factory or request-scoped service follows its declared
lifetime. This prevents a route snapshot from capturing a live instance; it
does not impose a universal controller lifetime. There is no magic zero-argument
construction and no fallback from a missing service to `new`.
Providers that currently publish class-method strings must add or identify an
explicit request-local service declaration during `register()`. A non-static
method works only through that registered instance. A genuinely static method
uses the same registered-service path rather than a separate reflection path.
Resolution failure is an execution-time failure with the route and handler ID
in a non-secret diagnostic. It does not alter the completed snapshot.

## Composition order and duplicates

Foundation collects sources in this order:

1. Foundation built-ins currently emitted before providers;
2. discovered provider contributions in canonical provider discovery order;
3. terminal Foundation routes, currently `public.home` then `public.page`.

Within a source, declaration order is retained. Publication validates the whole
set, rejects an exact duplicate route name, then sorts by priority descending
with original collection order as the stable tiebreaker. This preserves current
`WaaseyaaRouter::sortRoutesByPriority()` behavior: a provider route at default
priority remains ahead of the default-priority terminal `public.page`, while an
explicit higher priority wins independent of registration timing. Duplicate
path patterns with distinct names remain legal and are resolved by this order.
There is no last-writer override.

## Lifecycle, identity, and failure

A composition epoch begins after provider discovery and route-declaration
inputs are finalized. Collection has the states `unavailable`, `collecting`,
`complete`, and `failed`.

- Access before the epoch is ready reports `unavailable` with the boot profile.
- Recursive access while collecting reports `collecting` and refuses.
- Any invalid definition, duplicate, required contributor failure, or legacy
  incompatibility moves the epoch to terminal `failed`; no snapshot is
  published. Retry requires a new kernel and new epoch.
- Only successful validation publishes `complete` atomically.
- A failed epoch never returns a prior or partial snapshot.

The snapshot belongs to one kernel composition epoch. Its identity includes the
contract schema version, Framework code identity, ordered cohort/source IDs,
definition digests, and a versioned deterministic digest of normalized
non-secret declaration inputs. Secret values and filesystem paths are excluded
rather than hashed. The snapshot contains no live provider, container, router,
controller, request, account, session, or mutable Symfony collection. It is
never stored in a process-static property. Consumers receive the immutable
snapshot or immutable projections, so one consumer cannot mutate another's
view.

Restricted discovery and schema-sync profiles cannot transition in-place to a
runtime route epoch. Reuse is explicitly refused; runtime composition requires
a new runtime kernel. This slice does not solve broader #2859 transitions.

An unsupported boot profile receives a structured unavailable result. Optional
capability absence is a successful, recorded composition decision when based on
declared installation/configuration facts. Failure to resolve or construct a
service is not an absence decision and must never be used during collection.

## HTTP use

Routing lazily compiles the completed snapshot to a request-context-free
Symfony collection once per composition epoch. It may cache that compiled
collection inside the owning kernel epoch, never process-wide. Each request
supplies its own Symfony request context for matching and URL generation.
After a match, the request-local execution resolver turns the handler ID into an
executable target. Existing middleware, parameter conversion, access checks,
and domain-router precedence continue afterward.

No independent HTTP-only contribution path is supported after migration. This
is what makes HTTP, CLI, and Bimaaji observe the same canonical definitions.

## Legacy migration

Calling `ServiceProvider::routes()` and then stripping controllers or cloning a
Symfony collection is forbidden. The legacy hook can execute arbitrary code and
already captures shared nested objects despite collection cloning. Sanitizing
its output cannot establish purity.

Legacy handler values have explicit dispositions:

| Legacy value | Canonical disposition |
|---|---|
| Reviewed opaque string used by a Foundation domain router, such as `render.page` | Map explicitly to the allowlisted `builtin:` ID. Unknown opaque strings are refused. |
| `Class::method` string for a non-static method | Register the class/service and migrate to `class:<fqcn>::<method>` or `service:<id>::<method>`. Inspection does not autoload or test callability. |
| `Class::method` string for a static method | Register a service adapter or the class service and use the same `class:`/`service:` lookup path. Static invocation is not inferred during inspection. |
| `[ClassName::class, 'method']` callable array | `RouteBuilder` currently normalizes this to `Class::method`; migrate with the corresponding registered class/service rule above. |
| `[object, 'method']` callable array | Register the object's construction and lifetime as a service and publish only its `service:` ID. |
| Named function string or first-class function callable | Register a small service adapter and publish its `service:` ID. A bare function is not a canonical handler. |
| Invokable object | Register it as a service with an explicit method such as `__invoke`; publish only its `service:` ID. |
| Closure, including a provider-bound lazy factory | Move captured inputs and construction into a registered service/factory and publish only its `service:` ID. |

Every provider that actually contributes routes, including contributors whose
current body appears declarative, must opt into the new contributor capability.
Providers classified `none` do not need an empty contributor implementation.
Automatic adaptation of an actual route hook would still invoke arbitrary
legacy PHP and would make compatibility depend on unreviewed behavior. During a
bounded transition:

- the first compatibility release defaults existing applications to an
  explicitly reported `legacy` HTTP mode;
- that mixed-mode HTTP registrar examines each provider exactly once per epoch:
  `none` performs no route work; `declarative` collects and compiles metadata
  into execution-compatible routes; `legacy` invokes that provider's old hook
  exactly once. It never selects two paths for one provider, so converted routes
  neither disappear nor register twice while legacy remains the default;
- canonical snapshot publication, CLI inspection, and Bimaaji inspection still
  refuse the whole cohort if any provider is classified `legacy`. Providers
  classified `none` do not block publication. Unknown, missing, or stale
  classifications refuse readiness. A partial snapshot is never published;
- an application opts into `canonical` mode only after its provider scan reports
  no legacy-only provider; canonical mode fails closed if one later appears;
- metadata consumers refuse with the names of legacy-only providers;
- the legacy mode cannot claim HTTP, CLI, or Bimaaji parity;
- providers do not contribute through both paths in one epoch;
- the default changes and the old hook is removed only after all first-party
  providers, the skeleton, generated recipe output, and named installed
  consumers qualify canonical mode through the governed breaking-change path.

Closure and object handlers migrate to request-local service IDs. Static file
reads and HTML rewriting move behind execution handlers. Availability gates
move to finalized configuration or installed-capability declarations. Controller
factories, repositories, entity managers, mailers, rate limiters, and workflow
services remain in request execution wiring.

Downstream application providers require the same migration. Framework can
enumerate and qualify its own source roster, skeleton template, generated recipe,
and named FETDER consumer, but it cannot claim completeness for arbitrary
external PHP providers.

## Acceptance

Before remediation can be called complete, evidence must show:

1. a nonempty application contribution is visible identically to HTTP matching,
   `route:list`, and Bimaaji graph inspection;
2. poison factories for services, credentials, sessions, queries, writes, and
   handlers remain uncalled during contribution and inspection;
3. a legacy-only provider is named in an explicit refusal and is not executed;
4. equivalent inputs produce byte-equivalent ordered definitions and identity;
5. duplicates and invalid values fail before publication;
6. early, recursive, failed, and unsupported-profile access refuse explicitly;
7. a consumer cannot mutate the shared snapshot or retain request state in it;
8. HTTP resolves a service handler per request from the same matched metadata;
9. source, relevant split-package, generated-application, and installed-consumer
   forms prove the crossings they claim.
10. a cohort containing `none` plus `declarative` providers publishes
    successfully without invoking the inherited no-op;
11. a cohort containing an actual `legacy` contributor refuses canonical
    publication and inspection without invoking its hook;
12. stale, missing, unknown, and classification-input-mismatched participation
    records refuse readiness rather than guessing `none` or refreshing during
    inspection. Controls include an unchanged leaf provider with a changed
    parent override, imported trait, capability interface/implementation, or
    Foundation base no-op.

Items 10-12 are required future fixture-matrix evidence once the contributor
and participation APIs exist. The current source-consumer red test cannot prove
an interface that has not yet been implemented.

These checks qualify route production and consumption. They do not establish
all domain-router dispatch, every effective authorization policy, or arbitrary
external application compatibility.
