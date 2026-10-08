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

## Existing work, specifications and repository hygiene

Apply [repository reconciliation](repository-reconciliation.md):

- Complete issue/PR inventory with search scope, pagination and observation time; relevant closed/merged decisions and discussions
- Every relevant existing issue dispositioned against current acceptance evidence; reuse existing owners and IDs
- Related specs, ADRs, records, READMEs, recipes, examples and shipped/maintainer skills inventoried outside the package as well as inside it
- Requirement-to-spec-to-code-to-test traceability, including undocumented behavior, unimplemented promises and conflicting authorities
- Intended behavior settled before repairing code or revising a spec; no spec rewrite merely to bless a defect
- Superseded documents/passages deleted after preserving current obligations and updating links, manifests, routing and evidence consumers; explicit retention rationale otherwise
- Framework-level composition journeys and cross-package dead-chain/consumer coverage, with owners and installation evidence
- Refreshed issue/Project reconciliation at closeout; future features separated from unresolved defects and required repairs

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

- Separation of concerns and single responsibility: group code by coherent ownership and reasons to change; identify policy, orchestration and infrastructure mixed in one host without requiring one class per method
- DRY at the contract and mechanism level: consolidate repeated knowledge around its canonical owner; retain similar-looking code when different domain rules or change lifecycles justify it
- Human navigation: consistent file, namespace and symbol names; related responsibilities easy to locate; directory depth and grouping proportional to package size; avoid vague dumping grounds and needless nesting
- Trace a maintainer task from package entrypoint to implementation, contract, tests and usage docs; record concrete navigation friction and a justified retain/move/split/merge disposition
- For proposed moves, name the responsibility and destination, affected imports/autoload, dynamic names, exports, generated consumers, docs and tests; keep ownership and behavior intact and give the repair bounded acceptance
- Unused locals, fields, parameters, callbacks, imports, flags, configuration and branches, including leftovers excluded from static-tool findings
- Deprecated no-ops, old/new implementations, aliases, fallbacks and compatibility shims; apply the skill's alpha Framework policy instead of assuming retention
- Concentrated hosts, providers, controllers, or managers with multiple policy roles
- Repeated translation, validation, serialization, or authorization logic
- Competing sources of truth and dual representations; identify the canonical authority and whether derived views are mechanically checked
- Interfaces with no production implementation or consumer
- Implementations reachable only from tests or obsolete examples
- Abstractions that hide ownership rather than clarifying it
- Adapters backed by test-only or fake delegates, effective no-ops, manufactured success, swallowed failures, lossy translation, or an unavailable terminal capability
- Adapters introduced to make internal paths agree: identify the original producer/consumer mismatch, normalization/defaults/coercions and whether correcting one canonical contract removes the adapter
- Repeated execution versus repeated implementation; measure query or translation cost before claiming a performance defect

Do not remove a suspected dead surface until dynamic registration, reflection, serialization names, generated consumers, package exports, fixtures, and downstream repositories have been checked.

Record these results during the initial audit, including concrete cleanup
opportunities and intentional repetitions. Distinguish obsolete code from
required integration boundaries and persisted-data migration. An active
consumer is a repair-impact input, not an automatic compatibility exception.
Every retained adapter needs a current boundary obligation; every competing
authority needs a canonical-contract disposition. Do not defer this review
until after implementing the first behavioral repair.

## PHPDoc and human comments

- Prefer native types where expressive; use PHPDoc for missing precision such as collection element types, array shapes, generics and callable signatures. Check annotations against actual producers, consumers and refusal paths
- Public and extension contracts explain purpose, meaningful input/output semantics, relevant exceptions, side effects and lifecycle or ownership constraints; internal contracts document non-obvious obligations where their callers need them
- Keep PHPDoc, native signatures, public-surface metadata, specs and behavior consistent. Remove stale promises, future-state descriptions and redundant tags that add no type or semantic information
- Explain non-obvious intent, invariants, ordering, security decisions, transaction/cache boundaries and tradeoffs near the affected code. Describe why the rule exists and what would break if it changed; link a stable spec or change record when useful
- Refactor confusing names or flow before adding explanatory prose. Remove narration of obvious statements, commented-out implementations and misleading comments; bounded workarounds identify their owner and removal condition
- Treat inline `@var`, suppressions and `@api` as claims requiring evidence, not shortcuts to a green gate. Fix the producer's type where possible; annotations must not manufacture runtime guarantees or conceal dead code
- Check the installed PHPStan and Deptrac versions and configuration before relying on a tag. PHPStan consumes richer PHPDoc types; Deptrac checks dependency rules and supports documentation-tag classification when configured. Prove the claimed enforcement with relevant controls

Sample the important public seams and complex internal boundaries explicitly;
record missing or misleading documentation as findings or bounded gaps. Do not
use comment density, blanket docblocks, file counts or a generic "enterprise
grade" label as acceptance. The evidence is accurate contracts, understandable
ownership, safe behavior and maintainable change paths.

Tool semantics: [PHPStan PHPDocs](https://phpstan.org/writing-php-code/phpdocs-basics),
[PHPDoc types](https://phpstan.org/writing-php-code/phpdoc-types), and
[Deptrac configuration](https://deptrac.github.io/deptrac/configuration/).
Verify against the installed versions; these links are references, not a
requirement to upgrade tools or enable every annotation feature.

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
