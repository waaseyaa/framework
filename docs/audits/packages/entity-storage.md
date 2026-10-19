# `waaseyaa/entity-storage` audit

- **Milestone:** assessed. Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** planned
- **Base:** `3161864a5d1af57621e4819e9720f12e12ccdae7`, audited `2026-10-19`
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5, native Windows.
- **Evidence freshness:** current at `3161864a5d1af57621e4819e9720f12e12ccdae7`.
- **Owner:** `waaseyaa/framework#3118`
- **Structured ledger:** `docs/audits/packages/entity-storage.ledger.json`: 3 findings, 3 refuted leads, 188 checklist answers, 5 handoffs, 0 decisions (0 open), 3 uncertainties (2 open), 0 probe entries, 3 evidence runs.

## Summary

All 151 shipped files received source or metadata review. Entity-storage owns persistence, mutation fencing, schema materialization and active configuration. Six documentation mismatches and two privately held boundary findings were independently reviewed; inherited scaling, concurrency and profile work keeps its existing owners.

- Assessed after independent finding verification, final citation/redaction review and protected-state checks.
- Every shipped file is classified; profile, sampling and historical acceptance gaps have named owners. Repairs and convergence are not claimed.

## Charter

- **Owns:** Repository persistence, field-backend gateways, hydration, mutation authority, configuration persistence/activation, schemas, revisions, translation and transaction completion. Evidence: packages/entity-storage/composer.json; README; production inventory and current composition.
- **Does not own:** Entity value policy, application access decisions, database primitives and operator command presentation. Evidence: Peer package contracts and existing issue ownership.
- **Consumers:** 20 declared inbound packages, entity manager/kernel composition, audit, API, groups/listing/messaging, migration and publishing. Evidence: Composer inbound inventory and source entrypoints.
- **Dependencies:** Access, cache, config, database, entity, field, foundation, i18n and Symfony EventDispatcher are required. Test-only contract helpers use development autoload. Evidence: packages/entity-storage/composer.json
- **Public surface:** Public repository, driver and field-backend contracts plus opaque row/snapshot roles. Historical V1/V2 and revision interfaces coexist; alpha compatibility does not by itself justify them. Evidence: packages/entity-storage/public-surface.php and actual source callers.
- **Installation profiles:** Source, kernel and framework closure are distinguished from standalone split and generated-application qualification. Fresh installed evidence is incomplete. Evidence: Distribution checklist and J1 #3199.

## Roster

The ledger lists every one of the 151 production candidates individually. The split uses the package subtree; tests are excluded from the production roster. Evidence levels distinguish inventory, source review, synthetic reproduction and installation qualification.

- 6 files: reviewed / duplicated or drifting contract.
- 133 files: reviewed / owned and coherent.
- 12 files: reviewed / necessary but under-specified.

## Intake

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |
| GRP-PERSIST-001 | groups audit | re-owned | none |
| GRP-ACCESS-004 | groups audit | re-owned | none |
| GRP-DOMAIN-006 | groups audit | re-owned | none |
| GRP-LAYER-002 | groups audit | re-owned | none |
| GRP-TEST-005 | groups audit | re-owned | none |
| H2 | historical kernel/storage audit intake | re-owned | none |
| H6 | historical kernel/storage audit intake | re-owned | none |
| H8 | historical kernel/storage audit intake | confirmed | none |
| H11 | historical kernel/storage audit intake | re-owned | none |
| H12 | historical kernel/storage audit intake | re-owned | none |

## Package findings

GitHub intake captured all open issues and PRs with pagination, plus all 343 Framework Project 4 items. The ledger distinguishes completed reconciliation from acceptance still requiring review. This publication records the assessment; it implements no repairs and closes no issues.

| ID | Title | Severity | Confidence | Level | Verified | Destination |
| --- | --- | --- | --- | --- | --- | --- |
| EST-SEC-001 | Retention integrity requires private triage. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/entity-storage) |
| EST-SEC-002 | Data scope consistency requires private triage. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/entity-storage) |
| EST-DOC-001 | Storage documentation diverges from active composition and public contracts | info | confirmed | reviewed | C | [#3213](https://github.com/waaseyaa/framework/issues/3213); EST-S1 |

## Cross-package findings

| ID | Owned by | Title | Destination |
| --- | --- | --- | --- |

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | source | Source composition reviewed at the base; exact scope and test outcomes are recorded separately. | waaseyaa/entity-storage #3118 |
| standalone split | undecided | none | No fresh isolated no-dev installation was qualified. Decide supported primitive and kernel closure, then exercise declared dependencies without root autoload help. | waaseyaa/entity-storage #3118 |
| framework closure | yes | none | Current docs-only CI 39295771289 succeeded; behavior and installed-profile jobs were skipped and do not qualify this profile. | waaseyaa/entity-storage #3118 |
| generated application | undecided | none | No fresh generated application was qualified; coordinate J1 #3199 and generator program #2844. | waaseyaa/framework #3199 |

## Profile and structural review

- **Identity and ownership:** Answered in charter and all-file roster. Split export uses the individually classified package subtree. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Entrypoints and runtime wiring:** Normal factory, configuration provider, backend provider/registry, repository, schema sync/materializer and lifecycle event callers traced. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Contracts and public surfaces:** Opaque adapters retain active boundary responsibilities; legacy-looking SQL drivers remain real terminals. Public metadata has specific stale descriptions. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Dependency structure:** No custom dependency parser used. Package convergence qualification under #3118 owns authoritative Deptrac controls, cycle/allowlist review and complete dynamic closure. The current production factory and 20 declared inbound consumers were inspected. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Behavior and boundary integrity:** Mutation/refusal source paths reviewed; current source tests reused. No whole HTTP or consumer policy qualification claimed. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Code quality and cohesion:** EntityRepository extraction remains #3009. Distinguish active authority adapters from alternate schema/query/hydration scaffolding. Current consumer review must precede removals. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners. Retain responsibility directories; README and declaration route corrections are needed. Publication task crosses repository/UnitOfWork/workflow/audit consumers.
- **PHPDoc and human comments:** Public metadata and selected transaction comments disagree with code. See notes for exact examples. No comment-density metric used. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Custom infrastructure versus Symfony:** Symfony EventDispatcher 7.4.9 already owns generic dispatch; DBAL4.4.3 owns SQL/transaction primitives. Domain authority/persisted generation rules are retained. Generic infrastructure ownership decisions remain with Foundation #3123. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Tests and mechanical gates:** Root focused 31 tests/115 assertions plus lane JSON-shape probe. Package convergence qualification under #3118 owns PHPStan2.1.54/Deptrac4.7.2 controls and clean-state verification. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Documentation and delivery forms:** README/public-surface cleanup and #2961/#3199 qualification obligations retained; no frontend assets or build outputs in roster. Remaining evidence owner: waaseyaa/entity-storage #3118, with the named existing child owners.
- **Existing work, specifications and repository hygiene:** All 200 open issues, 15 open PRs and 343 Project items were captured with pagination. Relevant closed search is capped at 100; comments were refreshed for 55 selected issues, including 22 with discussion. All 244 document candidates have inventory dispositions; 17 named specification paths have current requirement/code/evidence traces. Whole-document semantic gaps and historical acceptance retain their named owners and are not fresh qualification.
- **domain-contracts:** Entity lifecycle/mutation states and repository ordering reviewed; shared required events before commit, notifications after completion. Actual event consumers include workflow pointer guards, messaging participant bootstrap, audit pointer listeners, cache and AI-vector projection listeners. Generic host branches route through metadata; bundle backend/layout-specific branches are contract points, not new domain entity-type policy. Opaque V2 wrappers have production implementations. Alternate public builder/hydrator/query seams need bounded dynamic/downstream review before removal. Full event consumer terminal effects and illegal-transition matrix remain a named domain qualification gap.
- **http-ui-contracts:** No HTTP route, middleware, controller or UI owned here. API maps typed storage refusals and mutation outcomes; terminal wire qualification belongs to API/foundation owners.
- **kernel-runtime:** Canonical factory, schema readiness and provider registration traced. Explicit testing ports are separate. Package convergence qualification under #3118 owns failed boot/retry, early singleton resolution, restricted transitions, sequential account/community execution and nonempty provider journey controls. Optional multi-backend coordinator and alternate hydration/schema paths need explicit installation-profile contract before broader guarantees. No fresh real-kernel or installed boot evidence from this lane.
- **persistence-execution:** DatabaseInterface/DBAL is used throughout reviewed production bodies; no raw PDO found in those bodies. SQLite-specific expressions/migration triggers align with S1; PostgreSQL/MySQL remain unsupported. Five migrations own configuration and mutation authority; coordinated schema entrypoints own entity tables; runtime asserts ready schema. Deployer catalogue source matches twelve configuration tables and mutation authority. Restore/import behavior not qualified. Mutation tags/versions and exact scope/identity guard ordinary writes. Config activation uses expected token, request-id idempotency and monotonic counters. Upgrade/repeat/no-op, interruption and recovery matrices remain assigned to #3028/#3029/#3030/#3110/#3035 and installed journey qualification.
- **failure-handling:** Typed conflicts, missing account, unsupported field/backend and committed completion outcomes are explicit in source. Partial backend completion is distinct from outer durable transaction commitment. Contextual read failure returns closed empty output; existing groups diagnostics owner retained. HTTP status/CLI exit/log redaction end-to-end, failed logger and interruption outcomes remain boundary-owner qualification gaps; source catch paths are not qualifications.
- **distribution:** Source evidence only. No installation, other checkout or exported archive changes performed. Primitive-only public APIs exist; standalone no-dev, kernel, core/cms/full, framework closure and generated apps each need exact installed evidence. Root #2961 owns contract-helper distribution; J1/#3199 owns installed-boundary controls. No src class reviewed extends PHPUnit; test helper classes under src are production-loadable helpers, not proof of no-dev closure. Export-ignore/archive byte equality not independently rechecked; the intake supplied complete shipped roster.
- **introspection-cli:** No command entrypoint owned here. SchemaSyncReport and repair result DTOs are consumed by CLI; emitter type-mapping ownership requires CLI coordination.
- **generation-build:** No source generator or frontend artifact owned here. Schema projections/materializers and CLI-generated migrations have cross-package acceptance obligations, retained under existing owners.

## Decisions and uncertainties

| ID | Status | Decision or uncertainty | Resolution or next evidence |
| --- | --- | --- | --- |
| U1 | open | Fresh installed/no-dev, generated-application and supported closure qualification is not established. | Owning package and #3199 run the supported profile matrix at an immutable candidate with declared dependencies only. |
| U2 | open | Some historical issue acceptance and specification traceability remains unreconciled. | Owning package completes per-item source/spec/test reconciliation from the captured issue, Project and document inventories. |
| U3 | settled | H2, H6, H8, H11 and H12 have independently checked current-source dispositions. | Independent current-source refutation and memory-only ordinary controls completed. H2 unsupported platform, H6 concurrency gap, H8 #3006 scope, H11 policy/legacy-shape split and H12 fixture-only qualification are retained explicitly. |

## Remediation plan

Every ordinary finding now has a filed issue linked in its destination above. Issue routing leaves the recorded acceptance, evidence limits and assessment milestone unchanged.

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| EST-S1 | EST-DOC-001 | Describe active repository -> V2 adapter -> SQL backend composition and explicit schema entrypoints; distinguish real test fixture and extension interface. Do not say all named engines/interfaces were removed. Describe the actual post-filter boundary and its qualification limits. Do not infer its performance as one SQL query or claim it is wired solely because the class comment says a provider wires it. Document nonempty langcode list, normalization and duplicate semantics; retain current accepted input behavior unless separately reviewed. Document the actual builder output and relationship to the normal schema path. Reconcile alternative public contracts before any implementation merge/removal. Correct comment to actual transactional ordering. No behavior change or migration source rewrite required. Describe raw revision persistence role and leave multi-language orchestration with actual repository/coordinator owner. | none |

## Not reviewed

- Exhaustive PHPDoc/comment correctness across all files; important seams were sampled.
- All downstream, reflection, serialized-name and dynamic consumers of apparently unused APIs.
- Full installed/split/no-dev/metapackage/generated-app runtime qualification.
- Complete event-consumer and wire/refusal/failure diagnostic matrices.
- Full existing-data upgrade/restart/concurrency and storage backend combinations.
- Deptrac allowed/forbidden/uncovered controls await the repository migration under #3075; the existing layer gate passed.

## Host limits

- Native Windows has known SQLite teardown, permission, symlink and POSIX gate limits (#3096, #3081). Missing installed and generated evidence belongs to hosted Linux jobs; no waiver or pass is claimed.

## Evidence

| Command or probe | Proves | Result |
| --- | --- | --- |
| PHPStan analyse audit, entity-storage, foundation, cli and user src paths with scratch config including repository config; unmatched baseline reports disabled for scoped analysis. | Scoped production-source static analysis under the existing baseline. No installed or runtime qualification. | Exit 0; zero reported errors and zero file errors. Cache and output confined to audit scratch. |
| Storage JSON integer/string query-shape discriminator (memory-only SQLite fixture) | Storage primitive distinguishes integer and string JSON criteria; no installed consumer or endpoint-listener qualification. | Two stored rows match their corresponding typed criteria; native SQLite compile option reports MAX_VARIABLE_NUMBER=32766, not a universal threshold. |
| Focused entity-storage UnitOfWork, mutation-authority and gateway tests | Source-level fencing, recreation, completion and gateway boundaries only. | 31 tests, 115 assertions passed; overlapping tests are not added to other runs. |

## Scorecard

- 151 production files; 22506 PHP lines; 20 declared inbound packages.
- Root plus three reused independent reviewers. Parallel investigation is user-authorized; token usage unavailable.
- **Open:** 0 open decisions; 2 open uncertainties; remediation filing and delivery remain separate.
- **Elapsed:** Started 2026-10-19 17:41 UTC; generated 2026-10-19 20:06 UTC. Initial allocation 120 minutes with reported checkpoint; phase-specific token metrics unavailable.
- **Verification corrections:** 1; **findings without a filed issue:** 2. Private advisory routing is separate.
- **Probe retention:** 0 nonsecurity entries; 0 retained. Findings whose notes say reproduced (probe not retained) use the validator's reproduced enum; their repair regression becomes durable evidence.
