---
name: waaseyaa-package-convergence
description: Audit and converge one Waaseyaa package when its purpose, boundaries, dependency structure, public API, contracts, wiring, tests, documentation, or distribution quality need a thorough cleanup. Use for package-charter reviews and issue-to-PR cleanup programs, not ordinary feature work.
---

# Waaseyaa Package Convergence

Use this skill to decide what a package is for, prove what it actually does, and bring the two into agreement. A clean package has one reviewable purpose, intentional dependencies, truthful public seams, exercised composition, and matching runtime, contract, test, documentation, and distribution behavior.

Read the repository guidance first. For user-authorized implementation, GitHub mutation, qualification, or landing, also use the local `waaseyaa-delivery` skill. This skill supplies the package-analysis method; it does not grant mutation or merge authority.

## Establish the audit boundary

Record the repository, exact base, package path, package identity, existing issues and PRs, and the requested completion boundary. Revalidate the current package and its consumers instead of relying on a prior roadmap. Preserve neighboring packages and separately owned issues.

Distinguish an inventory, a substantive audit, remediation, package convergence, and downstream qualification. Keep a review roster marking each area as inventoried, reviewed, reproduced or exercised, and qualified in its supported installation form; these are evidence dimensions, not interchangeable completion claims. Unreviewed areas and untested profiles remain explicit.

Before proposing structural changes, write a short package charter that answers:

- What capability does this package own?
- What does it explicitly not own?
- Who composes and consumes it at runtime, in generated applications, and as a split package?
- Which dependencies are required, optional, or adapters at the boundary?
- Which symbols and wire contracts are public, and what lifecycle does each promise?
- What observable evidence proves the package works in source and distributed form?

If these answers cannot be supported from code and current consumers, record the uncertainty as a finding. Do not invent a cleaner architecture and treat it as current fact.

## Build the complete surface inventory

Inventory the package before deleting, moving, or sealing anything. Read [the package audit checklist](references/package-audit-checklist.md) and apply the relevant rows. Trace declared public surfaces to real callers and composition roots. Trace runtime entrypoints back to their contracts, dependencies, failure behavior, and tests. Include frontend or generated artifacts when the package ships them.

For kernels, providers, containers, or other stateful composition owners, also read [kernel and runtime checks](references/kernel-runtime-checklist.md). For graph inspection, diagnostics, generated descriptions, or command adapters, read [introspection and CLI checks](references/introspection-cli-checklist.md). Apply both when the package spans both roles. These extend the shared method; they are not separate per-package workflows.

Classify each reviewed surface as one of:

- owned and coherent;
- necessary but under-specified;
- duplicated or drifting contract;
- wrong package or wrong layer;
- unwired, unreachable, or obsolete;
- optional behavior presented as required, or required behavior presented as optional;
- missing refusal, lifecycle, compatibility, or distribution evidence.

Absence of a repository search result is a lead, not deletion proof. Check dynamic registration, provider manifests, generated code, reflection, serialization names, package exports, fixtures, documentation examples, and downstream consumers before calling code dead.

## Model dependency boundaries

Review coupling beyond imports: dependence on another package's internals, initialization order, shared mutable state, provider-bound callbacks, service-locator lookups, and changes that require coordinated edits across owners. Trace a concrete producer/consumer interaction and its failure or change impact. Distinguish necessary composition from accidental coupling; extra interfaces or wrappers do not by themselves reduce it. Use lifecycle and consumer probes for coupling that static dependency tools cannot establish.

For PHP dependency architecture, prefer Deptrac as the maintained authority. Do not add another custom dependency parser when Deptrac can express the boundary. Define reviewed layers from the package charter, classify internal and external dependencies, enable uncovered-dependency reporting, and make unclassified production dependencies fail the scoped check. Seed allowed, forbidden, and uncovered controls so the gate is discriminating.

Use Deptrac's JSON formatter for machine assertions so CI terminal detection cannot change the evidence shape. Reserve console output for human diagnosis and Mermaid output for a committed architecture view. Tests should parse the structured report and retain discriminators for legal edges, forbidden edges, uncovered dependencies, and the clean zero-uncovered case.

Adopt Deptrac incrementally when repository-wide migration is not part of the current issue. Keep existing architecture checks active until their replacement is separately accepted. Exceptions must name the dependency, reason, and owner, with a removal condition for transitional exceptions or a maintained invariant and review trigger for intentional long-lived adapters. Generated reports and diagrams are evidence views, not the architectural authority.

Refresh generated architecture views after any production-code change, including behavioral repairs. A new dependency edge can change counts or topology even when the intended layering remains unchanged.

For non-PHP surfaces, use the ecosystem's mechanical compatibility and import checks. Prefer exact structural compatibility, schema validation, build exports, and real package installation over prose comparisons or hand-maintained symbol lists. Ordinary assignability can allow an optional extra field to disappear silently; duplicated structural contracts should fail on missing or extra keys, required-versus-optional drift, nullability drift, and incompatible values.

Before calling a wire contract canonical, enumerate every Framework-owned request and response that crosses the boundary, including secondary actions such as schema, history, preview, restore, and errors. Trace each crossing from its producer or validator through its transport to the consuming type. A green mirror gate is incomplete when a real crossing remains represented only by a local cast or consumer-private type.

Derive field presence from actual serialization paths. Treat an omitted field, an optional field, a required nullable field, and a required non-null field as distinct contracts. When a field carries authority or lifecycle state, test transitions as well as snapshots: for example, authority becoming null, a capability disappearing, a session ending, or an optional service becoming unavailable. Static compatibility cannot prove that stale cached authority is revoked.

One canonical contract does not require one monolithic file. Split materially different protocols by concern when that improves ownership and reviewability, while preserving one documented public entrypoint and one compatibility story.

A mechanical gate must run when either the canonical definition or any checked mirror changes. Verify workflow path filters, dependency provisioning, and the owning required check. Do not add an ecosystem-specific gate to a preflight job that cannot provision its runtime merely for superficial parity.

## Test the charter against behavior

For substantial custom infrastructure, evaluate whether an existing Symfony component can own the generic mechanism while Waaseyaa retains its domain policy. Start with installed components and supported versions; verify candidate behavior against their source/tests and official documentation before recommending replacement. Compare actual semantics, public compatibility, failure behavior, supported installation profiles, dependency weight and migration cost. Identify the Waaseyaa-specific behavior that remains, the smallest adapter needed, and discriminating equivalence tests. Record a disposition of retain, simplify around Symfony, replace, or defer with evidence and an owner. Similar names or fewer custom lines are not sufficient evidence. Do not introduce Symfony merely to remove working code, replace domain authorization/sovereignty policy with generic mechanisms, or make unrelated replacement work a consumer-unblock prerequisite.

Review the package through these questions:

1. Does every responsibility in production code belong to the charter?
2. Are large hosts or providers coordinating policy that belongs in smaller application or contract seams?
3. Is there one canonical wire contract, with generated or mechanically checked consumer types?
4. Do public interfaces have production consumers, stable failure semantics, and composition documentation?
5. Are optional capabilities independently discoverable and unavailable without changing unrelated behavior?
6. Do authorization, mutation fencing, validation, and error mapping occur at the real boundary?
7. Do source, split-package, generated-application, and committed-distribution forms agree?
8. Can tests distinguish the intended architecture from the current accidental implementation?

Keep evidence claims narrower than their proof. Distinguish producer-to-canonical conformance from canonical-to-consumer compatibility, and do not claim every payload or complete conformance unless the evidence roster names each covered crossing. Producer conformance must reject both undeclared emitted keys and omitted required keys.

Use non-empty examples for optional and nested payloads so their structure is exercised. Type browser and integration fixtures against the canonical contract, or validate them mechanically; an untyped mock can preserve a false green path after the real wire contract changes.

For refusal behavior, prove the complete stack: route options, real middleware, handler non-invocation, and wire-level status and response shape. Distinguish kernel-level malformed-body refusal from host-level refusal of syntactically valid input with the wrong shape.

For an HTTP CSRF journey, bootstrap through the real application HTML, read the runtime-configured CSRF cookie name, retain the session cookie jar, and send the matching CSRF header on mutation. Also prove that a mismatched token cannot persist state. Do not hard-code a default cookie name when the runtime can configure it.

If conformance evidence parses contract source instead of importing a runtime schema, require module discovery, nested-structure handling, and a parser sanity control. A parser that silently finds nothing must fail rather than produce green evidence.

Run applicable governance scanners after changing test harnesses as well as production code. Test-only database construction, service composition, generated manifests, and dependency setup can still enter construction rosters or other governed authorities. Treat a scanner finding as an ownership signal to diagnose, not as permission to suppress or widen the candidate automatically.

Do not equate more interfaces, smaller files, or zero Deptrac violations with a good package. Prefer the smallest boundary set that expresses real ownership and makes invalid dependencies or contracts fail mechanically.

## Turn findings into a delivery program

Maintain one evidence-backed finding ledger with the observed behavior, consequence, owner, proposed disposition, acceptance evidence, and issue. Separate package convergence from unrelated product defects. Create bounded residual issues before landing when a coherent slice cannot safely absorb them.

For a consumer-driven multi-package audit, classify each finding separately as required for the consumer unblock, independent follow-up, or accepted limitation with rationale. Before selecting remediation, reconcile the affected producers and consumers: ownership, readiness, lifetime, failure/completeness semantics, effective access behavior, and supported installation profiles. Record conflicting contracts and unresolved evidence, and identify which uncertainties actually prevent a safe repair. Complete this scoped contract checkpoint across the participating packages; do not make unrelated whole-package convergence an automatic prerequisite for the consumer fix. Expand the audit roster when the proposed patch touches another package.

When implementation is authorized, sequence work so later changes depend on established contracts:

1. durable charter and change record;
2. dependency model and discriminating controls;
3. canonical contract convergence;
4. behavioral and failure-boundary repairs;
5. public-surface and documentation reconciliation;
6. source, packaged, generated, and distribution qualification;
7. immutable-candidate review, hosted checks, and authorized landing.

This is a dependency order, not a demand for one large PR. Split the work where reviewability, ownership, or risk improves. Keep the parent issue open until its complete acceptance is proven.

For committed distributions, check freshness early to expose drift, let source settle, rebuild through the canonical tool, commit the rebuilt artifact, and then run split-package and distribution acceptance from a clean exact head. Do not rebuild merely because tests or PHP harnesses changed. Rebuild only when a build-determining Admin source, dependency, configuration, or canonical distribution input changed, or when the freshness check proves drift.

## Completion standard

A package convergence claim requires:

- a committed charter matching observed ownership;
- an exhaustive reviewed dependency classification for the scoped production code;
- a disposition for every public surface and duplicated contract;
- no unexplained unwired production code;
- discriminating behavior and refusal evidence at real boundaries;
- verified source and relevant distributed forms;
- residual work linked to bounded issues;
- exact candidate and hosted qualification evidence.

Report partial slices as partial. A green dependency graph alone does not establish package correctness, usability, or distribution integrity.
