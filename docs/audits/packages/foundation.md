# `waaseyaa/foundation` audit

- **Milestone:** assessed. Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** planned
- **Base:** `3161864a5d1af57621e4819e9720f12e12ccdae7`, audited `2026-10-19`
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5, native Windows.
- **Evidence freshness:** current at `3161864a5d1af57621e4819e9720f12e12ccdae7`.
- **Owner:** `waaseyaa/framework#3123`
- **Structured ledger:** `docs/audits/packages/foundation.ledger.json`: 9 findings, 4 refuted leads, 188 checklist answers, 0 handoffs, 1 decisions (1 open), 3 uncertainties (3 open), 3 probe entries, 4 evidence runs.

## Summary

Foundation supplies composition, lifecycle, migration authority, HTTP infrastructure and shared primitives. All 369 shipped files received semantic source review. Independent verification confirmed eight ordinary contract, diagnostic and opt-in HTTP findings, plus a privately held finding with conditional reach. Supported installation and lifecycle qualification remain bounded gaps.

- Assessed after independent finding verification, final citation/redaction review and protected-state checks.
- Every shipped file is classified; profile, sampling and historical acceptance gaps have named owners. Repairs and convergence are not claimed.

## Charter

- **Owns:** Shared provider/capability and discovery contracts, manifest read models, common infrastructure primitives and application composition entrypoints. Evidence: packages/foundation/composer.json; README; production inventory and current composition.
- **Does not own:** Higher-layer entity, access, API, routing and CLI business policy even where kernels compose them. Evidence: Peer package contracts and existing issue ownership.
- **Consumers:** 56 declared inbound packages, HTTP/Console/MCP kernels, generated applications and metapackages. Evidence: Composer inbound inventory and source entrypoints.
- **Dependencies:** DBAL, PSR interfaces, Symfony Dotenv/EventDispatcher/HttpFoundation/ServiceContracts/UID and cache/database/queue. Higher-layer composition has explicit architectural exceptions and development-only closure edges. Evidence: packages/foundation/composer.json
- **Public surface:** Provider contracts, runtime/schema/security/diagnostic primitives, kernels and route metadata. Primitive-only use and full kernel composition require distinct installation evidence. Evidence: packages/foundation/public-surface.php and actual source callers.
- **Installation profiles:** Source, kernel and framework closure are distinguished from standalone split and generated-application qualification. Fresh installed evidence is incomplete. Evidence: Distribution checklist and J1 #3199.

## Roster

The ledger lists every one of the 369 production candidates individually. The split uses the package subtree; tests are excluded from the production roster. Evidence levels distinguish inventory, source review, synthetic reproduction and installation qualification.

- 314 files: reviewed / owned and coherent.
- 38 files: reviewed / necessary but under-specified.
- 7 files: reviewed / unwired or obsolete.
- 7 files: reviewed / duplicated or drifting contract.
- 3 files: reviewed / missing refusal, lifecycle, compatibility or distribution evidence.

## Intake

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |
| GRP-LIFECYCLE-002 | groups audit | confirmed | GRP-LIFECYCLE-002 |
| H1 | groups audit | refuted | none |
| H5 | groups audit | refuted | none |
| H21 | groups audit | refuted | none |
| H4 | groups audit | merged | FND-DIAGNOSTIC-001 |

## Package findings

GitHub intake captured all open issues and PRs with pagination, plus all 343 Framework Project 4 items. The ledger distinguishes completed reconciliation from acceptance still requiring review. This publication records the assessment; it implements no repairs and closes no issues.

| ID | Title | Severity | Confidence | Level | Verified | Destination |
| --- | --- | --- | --- | --- | --- | --- |
| FND-CONTRACT-001 | Deferred-provider documentation differs from eager provider execution | low | confirmed | reproduced | B | [#3123](https://github.com/waaseyaa/framework/issues/3123); FND-S1 |
| FND-DIAGNOSTIC-001 | Default health-check composition omits available field diagnostics | low | confirmed | reproduced | B | [#3214](https://github.com/waaseyaa/framework/issues/3214); [#3123](https://github.com/waaseyaa/framework/issues/3123); FND-S2 |
| GRP-LIFECYCLE-002 | Generic provider-boot documentation promises unavailable cross-package services | low | confirmed | reproduced | B | [#3123](https://github.com/waaseyaa/framework/issues/3123); FND-S3 |
| FND-SEC-001 | Lifecycle configuration requires private triage. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/foundation) |
| FND-HTTP-001 | Opt-in compression accepts explicitly rejected gzip encoding | low | confirmed | reproduced | B | [#3215](https://github.com/waaseyaa/framework/issues/3215); FND-S4 |
| FND-HTTP-002 | Opt-in conditional responses lose metadata and miss valid validators | low | confirmed | reproduced | B | [#3216](https://github.com/waaseyaa/framework/issues/3216); FND-S5 |
| FND-MONITOR-001 | Monitor event name can disagree with its latest timestamp | low | confirmed | reproduced | B | [#3217](https://github.com/waaseyaa/framework/issues/3217); FND-S6 |
| FND-LOG-001 | Missing configured log default fails after successful construction | low | confirmed | reproduced | B | [#3218](https://github.com/waaseyaa/framework/issues/3218); FND-S7 |
| FND-GUIDANCE-001 | Schema-drift guidance recommends unscoped database deletion | low | confirmed | reproduced | B | [#3219](https://github.com/waaseyaa/framework/issues/3219); FND-S8 |

## Cross-package findings

| ID | Owned by | Title | Destination |
| --- | --- | --- | --- |

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | source | Source composition reviewed at the base; exact scope and test outcomes are recorded separately. | waaseyaa/foundation #3123 |
| standalone split | undecided | none | No fresh isolated no-dev installation was qualified. Decide supported primitive and kernel closure, then exercise declared dependencies without root autoload help. | waaseyaa/foundation #3123 |
| framework closure | yes | none | Current docs-only CI 39295771289 succeeded; behavior and installed-profile jobs were skipped and do not qualify this profile. | waaseyaa/foundation #3123 |
| generated application | undecided | none | No fresh generated application was qualified; coordinate J1 #3199 and generator program #2844. | waaseyaa/framework #3199 |

## Profile and structural review

- **Identity and ownership:** The package owns kernels, provider discovery/capability composition, runtime and schema coordination, diagnostics, logging and security infrastructure ports. Kernel exceptions cross layers intentionally; primitive-only installation is a different promise from full Framework composition. Owner: waaseyaa/foundation #3123.
- **Entrypoints and runtime wiring:** HttpKernel, ConsoleKernel, provider registry and manifest bootstrap compose different runtime profiles. Console has boot-free, restricted and ordinary branches. The shared route snapshot is now present, but failed/restricted kernel reuse and sequential process state remain #2859 qualification work. Owner: #3123.
- **Contracts and public surfaces:** Current route metadata and explicit handler service capabilities supersede the old snapshot-absence claim. ServiceProviderInterface still promises deferred registration and retains stale route-hook prose. Primitive ports and concrete runtime seams need explicit supported profiles and public-surface disposition. Owner: #3123.
- **Dependency structure:** DBAL, Symfony and PSR components supply core primitives; cache/database/queue are required peers. Accepted layer cycles remain visible in the layer gate. Kernel dynamic resolution, first-binding precedence and request-scoped context are separate from imports and need #2859 proof. Deptrac migration remains #3075. Remaining item-specific proof is owned by waaseyaa/foundation #3118.
- **Behavior and boundary integrity:** Current cycle cleanup and deterministic SQLite compare-and-set controls pass. Route consumers share one snapshot but route:list still lacks declared access posture under #3007. Full middleware refusal and failed-boot/repeated-request semantics remain source-profile gaps owned by #3123/#2859.
- **Code quality and cohesion:** Kernel, discovery, migration graph/executor/repository, logging and custody are separate change responsibilities. Preserve one schema/transaction authority and immutable migration identities. LogManager and ChannelLogger duplicate record-processing mechanics; compare a maintained logging component before extending them. Small route/provider adapters preserve precedence and scope. Tenant v1 paths and ProviderDiscovery have explicit dormant/caller uncertainty; AsFieldType is ignored, while deprecated AsEntityType is actively consumed. No removal set is established. Owner: #3123.
- **PHPDoc and human comments:** The README under-describes primitive versus full-kernel profiles. Current comments retain stale phase wording and moved responsibilities; FND-CONTRACT-001 and GRP-LIFECYCLE-002 cover verified lifecycle promises. Reconcile operator remediation separately. Preserve checksum-bound historical migration bytes. Owner: #3123.
- **Custom infrastructure versus Symfony:** Symfony Dotenv, HttpFoundation and event dispatch are already used. Generic home container/resolver, logging plumbing, file/cache helpers and rate-limit machinery are candidates, not verified replacements. Symfony DI must preserve explicit admission, provider precedence and request lifetimes. AsciiSlugger is not equivalent to the current Unicode-preserving slug contract. Owner: #3123; candidate equivalence gates remain open.
- **Tests and mechanical gates:** Independent reconciliation ran 34 tests/106 assertions across cycle guards, route consumers, optional vector activation and rate limiting. This overlaps no claimed package-wide count. Source layer gate passes with accepted cycles; package Deptrac allowed/forbidden/uncovered controls do not yet exist. Owner: #3123/#3075.
- **Documentation and delivery forms:** All 369 production files received attributed source or metadata review, including schema, migration, ingestion, logging and HTTP families. Current S1 support policy was renewed under #3206. That policy and source coverage do not certify installed consumers or every lifecycle/platform path; qualification remains with #3123/#3199.
- **Existing work, specifications and repository hygiene:** All 200 open issues, 15 open PRs and 343 Project items were captured with pagination. Relevant closed search is capped at 100; comments were refreshed for 55 selected issues, including 22 with discussion. All 244 document candidates have inventory dispositions; 17 named specification paths have current requirement/code/evidence traces. Whole-document semantic gaps and historical acceptance retain their named owners and are not fresh qualification.
- **domain-contracts:** Runtime epochs, capabilities, provider order, route identity and schema authority are domain contracts. All shipped files have source classifications and review; their ports are not interchangeable with generic container services. Complete runtime lifecycle and installed-profile qualification remain owned by #3123.
- **http-ui-contracts:** HttpFoundation is the transport primitive. H1 and H21 have independently settled current-source dispositions: documented CSRF ordering and supported kernel closure explain the historical leads. Full middleware/principal-lifetime, route-access and final error-rendering qualification remains with #3123/#3203; the two opt-in HTTP findings have bounded repair acceptance.
- **kernel-runtime:** Canonical route composition and cycle guards are present. Deferred-provider and access-readiness promises, default diagnostic injection and failed-kernel semantics remain bounded review subjects. #2859 owns full entrypoint/retry/worker qualification; #3123 owns package convergence.
- **persistence-execution:** Database rate limiting uses bounded compare-and-set accounting; #3102 now needs guarantee/evidence reconciliation rather than a duplicate conditional-update repair. Schema/migration coordinator correctness and process interruption are not freshly qualified here. Owner: #3123; #3110.
- **failure-handling:** ExceptionHandler, HttpKernel and WaaseyaaConsoleApplication are distinct final boundaries. #3203 owns consistent attribution and safe rendering. Catch-all optional resolution and partial boot cannot be treated as absence without an owning contract. Owner: #3123/#2859.
- **distribution:** Primitive-only versus full kernel closure must be stated separately. No fresh isolated no-dev or generated application install was run; source-root success does not prove package requirements. Owner: #3123; J1 #3199.
- **introspection-cli:** ConsoleKernel selects profiles and projects canonical route metadata. Current route:list omits access posture; Bimaaji route snapshots work in the synthetic source composition. Installed inspection and final status/diagnostic truth remain #3007/#3123 gaps.
- **generation-build:** Foundation produces discovery/schema/runtime metadata consumed by generated apps. No frontend build is owned. Compiler freshness, invalidation and split consumer loading remain unqualified #3123/#3199 gaps.

## Decisions and uncertainties

| ID | Status | Decision or uncertainty | Resolution or next evidence |
| --- | --- | --- | --- |
| D1 | open | Choose clear construction-time refusal or a correctly addressed fallback for an invalid explicitly selected logging default. | Foundation maintainer selects the policy and retains terminal sink controls for direct logging, unknown channel lookup and valid configurations. |
| U1 | open | Fresh installed/no-dev, generated-application and supported closure qualification is not established. | Owning package and #3199 run the supported profile matrix at an immutable candidate with declared dependencies only. |
| U2 | open | Some historical issue acceptance and specification traceability remains unreconciled. | Owning package completes per-item source/spec/test reconciliation from the captured issue, Project and document inventories. |
| U3 | open | Maintained-component equivalence is unqualified for the custom container, logging and filesystem/cache infrastructure. | Foundation #3123 compares Symfony DI against provider admission/order/lifetime/refusal controls, maintained logging against level/processor/sanitizer/channel controls, and Filesystem against exact bytes/atomicity/failure controls in supported no-dev profiles. Adopt equivalent generic mechanics and remove superseded implementation only in an authorized repair; retain a custom mechanism only with an evidenced capability gap and review trigger. Unicode-preserving slug policy remains domain-owned. |

## Remediation plan

Every ordinary finding now has a filed issue linked in its destination above. Issue routing leaves the recorded acceptance, evidence limits and assessment milestone unchanged.

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| FND-S1 | FND-CONTRACT-001 | Record the intended lifecycle explicitly and make package-discovery, public method documentation and executable composition evidence agree. The bounded repair may document the current eager provider lifecycle and distinguish lazy factories, or a separately accepted slice may implement deferred provider loading. Do not remove or deprecate public provides/isDeferred APIs based on this audit. Preserve ordinary eager registration, sibling binding availability and singleton behavior unless an explicit lifecycle decision says otherwise. | none |
| FND-S2 | FND-DIAGNOSTIC-001 | Pass the canonical kernel field registry into the default checker. Add a kernel-composed checker or health:check test showing the supported bundle and column-data drift diagnostics, with clean schema controls and the existing stable diagnostic codes/results. Keep the same registry instance and cached checker lifecycle. Limit this slice to the health-checker wiring part of inherited H4. | none |
| FND-S3 | GRP-LIFECYCLE-002 | Narrow package-discovery's generic boot promise to services available at that phase and explicitly defer access-handler-dependent services until completed access-policy discovery/runtime boot. Align it with the already correct specific documentation. Preserve Groups optional-declaration behavior; add/retain controls that absent declaration yields resolveOptional null and post-boot hard resolution identifies StaffDirectoryReadDeclaration. If maintainers instead want stronger boot-time availability, route that explicit lifecycle decision to the existing Foundation/#2859 owner rather than silently reordering authority composition. | none |
| FND-S4 | FND-HTTP-001 | Use an explicit supported coding/quality contract that rejects quality zero and matches coding tokens correctly. Keep positive gzip, absent/unsupported headers, small-body, existing encoding, streamed-response and Vary preservation controls. Review Vary on all negotiated representation branches within the existing middleware boundary audit, without changing cache ownership. | none |
| FND-S5 | FND-HTTP-002 | For the same selected representation, verify exact/weak/list/wildcard/mismatch and GET/HEAD controls. Preserve the applicable original 304 metadata and clear body/content-specific fields through the maintained response behavior. Preserve noncacheable method, non-success, empty and streamed-response eligibility; explicitly settle If-Modified-Since precedence before substitution. Do not enable this middleware by default as part of this repair. | none |
| FND-S6 | FND-MONITOR-001 | Select latest timestamp and event name from the same row and explicitly define deterministic tie handling, such as a stable id secondary ordering. Add a regression where lexical order opposes timestamp order, and another where equal timestamps exercise the selected tie policy. Preserve 24-hour counts, excluded rows, channel ordering, empty-table shape and controller JSON field names. Do not add a new catch-all storage-failure success contract as part of this repair. | none |
| FND-S7 | FND-LOG-001 | Specify and implement one missing-default policy: clear stable construction-time refusal, or fallback reachable by both direct logging and unknown channel selection. Use a terminal sink discriminator so a no-op logger cannot satisfy successful fallback tests. Preserve valid named/default configurations, empty-channel fallback, omitted default behavior, configured-channel operation, level filters, processors and the mandatory final sanitizer. No late undefined-array warning, null handler Error or ChannelLogger TypeError for this configuration path. | none |
| FND-S8 | FND-GUIDANCE-001 | Lead with non-destructive inspection and supported explicit coordinated repair or migration guidance appropriate to the actual drift; do not promise blanket schema:sync resolves every drift. Keep the diagnostic code, severity, exit status and drift details stable; update both rendered text and JSON expectations through the shared message. If retained, scope delete/recreate guidance expressly to verified disposable development data with an explicit initialization/replay step. Preserve read-only diagnostic behavior. This repair must not add deletion, self-heal or mutation. | none |

## Not reviewed

- Fresh no-dev split, metapackage, generated application, downstream and worker lifecycle qualification.
- Exhaustive all-consumer, cross-platform and performance behavior. Every shipped source file was reviewed, but that is not a test of every path.

## Host limits

- Native Windows has known SQLite teardown, permission, symlink and POSIX gate limits (#3096, #3081). Missing installed and generated evidence belongs to hosted Linux jobs; no waiver or pass is claimed.

## Evidence

| Command or probe | Proves | Result |
| --- | --- | --- |
| PHPStan analyse audit, entity-storage, foundation, cli and user src paths with scratch config including repository config; unmatched baseline reports disabled for scoped analysis. | Scoped production-source static analysis under the existing baseline. No installed or runtime qualification. | Exit 0; zero reported errors and zero file errors. Cache and output confined to audit scratch. |
| Five absolute-path router and SQLite exactness test files; --no-coverage --no-progress | Bounded source channel/cursor/lifecycle and SQLite parser/refusal contracts; no installed-profile or concurrency qualification. | PASS: 240 tests, 437 assertions; PHP 8.5.5 / PHPUnit 13.1.14 native Windows. foundation/root-supplement-tests.xml. Initial relative-path invocation ran no tests and is not a pass. |
| Independent opt-in http-probe.php | Actual middleware composition and terminal response semantics using synthetic handlers; no installed profile. | All stated HTTP defect and refusal controls passed; all three source reconciliation claims independently checked. |
| Independent foundation-diagnostics/probe.php | Synthetic real adapter/controller, local logging sink, and read-only diagnostic rendering. No installed-profile or production proof. | 39 checks passed, exit0, empty stderr. One initial floating timestamp fixture assertion corrected before final run. |

## Scorecard

- 369 production files; 33729 PHP lines; 56 declared inbound packages.
- Root plus three reused independent reviewers. Parallel investigation is user-authorized; token usage unavailable.
- **Open:** 1 open decisions; 3 open uncertainties; remediation filing and delivery remain separate.
- **Elapsed:** Started 2026-10-19 17:41 UTC; generated 2026-10-19 20:06 UTC. Initial allocation 120 minutes with reported checkpoint; phase-specific token metrics unavailable.
- **Verification corrections:** 8; **findings without a filed issue:** 1. Private advisory routing is separate.
- **Probe retention:** 3 nonsecurity entries; 0 retained. Findings whose notes say reproduced (probe not retained) use the validator's reproduced enum; their repair regression becomes durable evidence.
