# Package audit checklist

The general checklist for every package. Use the sections that apply, and the
role profiles in `profiles/` for depth. Record evidence paths and consumers,
not just counts.

## Identity and ownership

- Composer or ecosystem package identity, versioning, exports, and split target
- Stated purpose in README, specifications, change records, and package metadata
- Capabilities explicitly owned and excluded
- Upstream authority and downstream consumers
- Source, generated-application, runtime, and distributed-package composition roots
- Supported installation profiles and the dependency closure each promises; distinguish primitive-only use from kernel/metapackage composition

## Entrypoints and runtime wiring

- Service providers, container bindings, factories, registries, and feature discovery
- HTTP routes, controllers, middleware, commands, jobs, events, and listeners
- Configuration, environment inputs, migrations, storage, and cache behavior
- Optional integrations and behavior when each dependency is absent
- Startup, shutdown, retry, idempotency, and failure-state behavior

## Contracts and public surfaces

- Exported classes, interfaces, traits, functions, constants, DTOs, and exceptions
- Composer autoload and package public-surface declarations
- Unclassified exports versus explicitly internal symbols; omission from a declaration is not proof that a real consumer is unsupported
- Request, response, event, persistence, JSON, TypeScript, schema, and generated contracts
- Compatibility policy, lifecycle, deprecation path, and error semantics
- Canonical definitions and every derived or duplicated representation

## Dependency structure

- Internal layers derived from the package charter
- Required, optional, development-only, adapter, and framework dependencies
- Inbound dependencies from peer packages and applications
- Outbound dependencies, including dynamic and container-resolved edges
- Cycles, layer bypasses, uncovered dependencies, allowlists, and expiry conditions
- Coupling to peer internals versus narrow public contracts; concrete consumers and compatibility obligations
- Temporal coupling: initialization order, early resolution, retries and coordinated readiness across packages
- Shared mutable state, captured service/provider objects and service-locator dependencies hidden from import graphs
- Change coupling: a concrete change that requires synchronized edits across owners, and whether that coordination is intentional
- Adapter chains from a supported composition root to their terminal implementations; what each layer owns, whether the terminal implementation is production-reachable, and whether a wrapper conceals rather than repairs defective behavior

For PHP, prefer a scoped Deptrac configuration with uncovered dependencies reported and failed. Include controls proving an allowed edge passes, a forbidden edge fails, and an unclassified edge fails.

## Behavior and boundary integrity

- Authentication, authorization, field access, CSRF, and information-oracle behavior
- Validation order and stable refusal mapping
- Refusal stack: route options, real middleware, handler non-invocation, HTTP status, and response shape
- Concurrency, revision, mutation-token, replay, and idempotency contracts
- Optional capability discovery versus actual route or service availability
- Malformed input, unavailable dependency, partial failure, and recovery behavior
- Adapter truthfulness across inputs, fields, authorization, refusals, errors, mutations, persistence effects, transaction boundaries, retries, and lifecycle transitions

## Code quality and cohesion

- Concentrated hosts, providers, controllers, or managers with multiple policy roles
- Repeated translation, validation, serialization, or authorization logic
- Interfaces with no production implementation or consumer
- Implementations reachable only from tests or obsolete examples
- Abstractions that hide ownership rather than clarifying it
- Adapters backed by test-only or fake delegates, effective no-ops, manufactured success, swallowed failures, lossy translation, or an unavailable terminal capability

Do not remove a suspected dead surface until dynamic registration, reflection, serialization names, generated consumers, package exports, fixtures, and downstream repositories have been checked.

## Custom infrastructure versus Symfony

Inventory generic custom mechanisms in runtime packages and build/maintenance tooling, including console, routing, dependency injection, configuration, validation, serialization, events, caching, HTTP, messaging, locking and process execution. Maintained Symfony components are the default where behavior fits. Evaluate suitable components even when not installed. This roster drives bounded remediation, not automatic implementation authority.

- Current custom responsibility, real callers and observable behavior
- Candidate Symfony component, installed/supported version, and verified source/test or official-documentation evidence of fit
- Waaseyaa domain semantics retained, including authorization, sovereignty, lifecycle, error and wire contracts
- Direct use versus a thin adapter; whether the adapter actually reduces maintenance or only relocates coupling
- Compatibility for public symbols, generated artifacts, stored formats and supported application extensions
- Required/optional dependency and split-installation impact, migration effort and ongoing maintenance tradeoff
- Equivalence controls for normal behavior, refusals, failure mapping and relevant lifecycle transitions
- Disposition: simplify around Symfony or replace when fit is established; retain only with an evidenced capability/compatibility gap, owner and review trigger; defer only with bounded migration acceptance and a removal condition
- Remediation destination for each verified replacement opportunity, including those outside the current consumer unblock

Keep an unverified candidate separate from a demonstrated replacement opportunity. Missing installation, fewer dependencies, fewer lines or existing custom tests alone do not justify retention. Preserve Waaseyaa policy and use minimal integration code rather than recreating a component behind a wrapper. Audit findings do not authorize replacement implementation.

## Tests and mechanical gates

- Unit tests for pure contracts and policies
- Integration tests through real composition roots
- Boundary tests for authorization, refusal, optional dependencies, and failure mapping
- Producer conformance that fails on undeclared emitted keys and missing required keys
- Canonical-to-consumer compatibility that fails on extra or missing keys, presence, nullability, and value drift
- Non-empty optional and nested fixtures typed against or mechanically validated by the canonical contract
- Source-parser controls for module discovery, nested structures, and empty-result failure when parsing cannot be avoided
- Schema, generated-output freshness, and workflow path-filter checks
- Architecture controls with positive, negative, and uncovered cases
- Mutation or discriminator evidence where a passing test could otherwise be vacuous
- Real-composition adapter controls with a positive path, an applicable refusal or failure path, an asserted terminal effect or returned state, and a discriminator that fails for a no-op or fake delegate

## Documentation and delivery forms

- Package charter and public composition examples
- Hard versus optional dependency documentation
- Source and split-package installation
- Generated-application consumption
- Frontend build and committed distribution freshness where applicable
- Distribution form details: see `profiles/distribution.md`
- Refreshed dependency views after production behavior changes
- Upgrade and deprecation guidance
- Changelog fragment and stable change record

## Recording

Record results in the [audit record](audit-record-template.md) and its structured ledger; see the skill's "Record findings" step for the fields, attribution and evidence levels. Every item here gets an answer in the ledger: answered with evidence, a finding, "does not apply" with a reason, or a gap with a destination. List unreviewed areas separately from confirmed findings.
