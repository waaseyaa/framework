# `waaseyaa/user` audit

- **Milestone:** in progress. Repair ready: no. Converged: not claimed.
- **Audit state:** in progress. **Remediation state:** not triaged
- **Base:** `3161864a5d1af57621e4819e9720f12e12ccdae7`, audited `2026-10-19`
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5, native Windows.
- **Evidence freshness:** current at `3161864a5d1af57621e4819e9720f12e12ccdae7`.
- **Owner:** `waaseyaa/framework#3118`
- **Structured ledger:** `docs/audits/packages/user.ledger.json`: 3 findings, 0 refuted leads, 186 checklist answers, 0 handoffs, 0 decisions (0 open), 2 uncertainties (2 open), 1 probe entries, 2 evidence runs.

## Summary

User owns account entities, roles, session and bearer middleware, CSRF/cache behavior and authentication mail presentation. Its 402-test source suite passes, while independent checks confirm native-session interface drift. Required private verification remains incomplete; evidence custody is established.

- Required independent verification of one private lead is incomplete, so this package remains in progress.
- The ordinary findings, source roster, profile gaps and final record review are complete; no convergence claim.

## Charter

- **Owns:** User and blocking entities, read policies, roles and registered-role assignment, session/bearer/CSRF/cache middleware, authentication mail presentation. Evidence: All 28 source files and UserServiceProvider reviewed.
- **Does not own:** Login/reset orchestration, access capability interfaces, strict read receipt persistence, generic storage or kernel composition. Evidence: auth orchestrates; access declares closed reader ports; audit implements them; foundation composes.
- **Consumers:** HTTP middleware stack, auth, SSR, OIDC, routing, MCP and account-management consumers. Evidence: Nine declared inbound packages; SessionMiddleware and UserServiceProvider.
- **Dependencies:** Required entity, access, foundation, mail, Symfony HttpFoundation and Twig. Database, entity-storage, field and SSR are development dependencies. Optional mail template configuration does not imply an optional mail package. Evidence: composer.json; AuthMailer; UserServiceProvider.
- **Public surface:** User and role/session contracts are exposed and consumed beyond the four public-surface entries. NativeSession advertises Symfony SessionInterface while retaining flat session data used by current auth/SSR code. Evidence: public-surface.php; Session/NativeSession; auth/SSR consumers.
- **Installation profiles:** Kernel composition is current. Individual no-dev split and generated account lifecycle profiles remain unqualified in this run. Evidence: composer.json; source tests; J1 #3199 and generated account issues #2443/#2441.

## Roster

The ledger lists every one of the 32 production candidates individually. The split uses the package subtree; tests are excluded from the production roster. Evidence levels distinguish inventory, source review, synthetic reproduction and installation qualification.

- 5 files: reviewed / necessary but under-specified.
- 26 files: reviewed / owned and coherent.
- 1 files: reviewed / duplicated or drifting contract.

## Intake

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |

## Package findings

GitHub intake captured all open issues and PRs with pagination, plus all 343 Framework Project 4 items. The ledger distinguishes completed reconciliation from acceptance still requiring review. This publication records the assessment; it implements no repairs and closes no issues.

| ID | Title | Severity | Confidence | Level | Verified | Destination |
| --- | --- | --- | --- | --- | --- | --- |
| USER-CONTRACT-001 | Native session behavior diverges from its advertised Symfony interface | low | confirmed | reproduced | B | USER-S1 |
| USER-SEC-001 | A suspected ownership-integrity issue requires private review. | withheld | suspected | reviewed | pending | private report (framework advisory; owner waaseyaa/user) |
| USER-DOC-001 | Documentation and responsibility navigation need a bounded refresh | info | confirmed | reviewed | C | USER-S2 |

## Cross-package findings

| ID | Owned by | Title | Destination |
| --- | --- | --- | --- |

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | source | Source composition reviewed at the base; exact scope and test outcomes are recorded separately. | waaseyaa/user #3118 |
| standalone split | undecided | none | No fresh isolated no-dev installation was qualified. Decide supported primitive and kernel closure, then exercise declared dependencies without root autoload help. | waaseyaa/user #3118 |
| framework closure | yes | none | Current docs-only CI 39295771289 succeeded; behavior and installed-profile jobs were skipped and do not qualify this profile. | waaseyaa/user #3118 |
| generated application | undecided | none | No fresh generated application was qualified; coordinate J1 #3199 and generator program #2844. | waaseyaa/framework #3199 |

## Profile and structural review

- **Identity and ownership:** Source, manifest and consumers establish account/middleware ownership. Closed-reader implementation belongs to audit and its interface to access; #3184's prior user ownership is corrected in this assessment. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **Entrypoints and runtime wiring:** Bearer middleware precedes session middleware; downstream account eligibility and principal projection matter. CSRF and cache middleware then apply request policy. Auth mail resolves closed readers and mail transport. Optional absence and installed-profile qualification remain gaps. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **Contracts and public surfaces:** NativeSession's maintained-interface promise needs conformance beyond its get/set consumers. User identity hydration distinguishes historical persisted rows from creation rules. Registered role assignment replaces the registered set while preserving unknown stored roles without granting permissions; this is intentional behavior. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **Dependency structure:** Actual direct and container-resolved consumers were traced. No new dependency parser was used; scoped Deptrac migration is coordinated under #3075. Runtime initialization and session globals remain coupling requiring lifetime qualification.
- **Code quality and cohesion:** Entity/policy, roles, middleware, mail and sessions form coherent responsibility families across root files and Authentication, Http, Middleware and Session directories. UserSession has no production instantiator in the bounded search but differs from nullable account context; no deletion is justified by that search. Shared role assignment is a single authority. Current session integration and documentation follow-ups own the remaining work.
- **PHPDoc and human comments:** Legacy access comments and interface coverage need reconciliation with V2 projection and actual adapter behavior. Native types and collection shapes are generally useful; @api alone does not prove a current caller or a supported installation profile. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **Custom infrastructure versus Symfony:** Symfony HttpFoundation 7.4.13 is installed. Its session implementation is the default comparison, but current AuthManager/identity/CSRF/SSR depend on flat stored session keys. Any integration must preserve or explicitly migrate that persisted obligation and retain Framework cookie, eligibility and generation policy. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **Tests and mechanical gates:** Explicit-file PHPUnit invocation passed 402 tests and 823 assertions. An initial directory invocation executed no tests and is not a pass. Focused NativeSession tests overlap the full package run. Source tests do not prove installed dependency absence or downstream session migration. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **Documentation and delivery forms:** Package documentation and public-surface coverage need a caller-based reconciliation. Fresh no-dev installation and generated-account qualification remain assigned to #3118 and J1 #3199.
- **Existing work, specifications and repository hygiene:** All 200 open issues, 15 open PRs and 343 Project items were captured with pagination. Relevant closed search is capped at 100; comments were refreshed for 55 selected issues, including 22 with discussion. All 244 document candidates have inventory dispositions; 17 named specification paths have current requirement/code/evidence traces. Whole-document semantic gaps and historical acceptance retain their named owners and are not fresh qualification.
- **Behavior and boundary integrity:** Current source and named tests were inspected, but complete real-composition refusal, failure, optionality and lifecycle evidence is not established. Owner: waaseyaa/user #3118.
- **domain-contracts:** Reviewed identity canonicalization/hydration, role assignment, account lifecycle vocabulary and mail inputs. Private boundary evidence is incomplete; no public authorization-completeness claim is made. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **http-ui-contracts:** Reviewed middleware order and downstream eligibility, CSRF metadata/JSON behavior and session-bound cache policy. Positive package tests pass. Full installed bearer/session/refusal matrices and private findings require remaining qualification. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **kernel-runtime:** Provider composition and middleware priorities reviewed. Repeated requests and no-user/optional compositions involve foundation and remain profile qualification gaps. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **persistence-execution:** User owns entity rules but repository persistence is entity-storage-owned. Flat session keys are a real persisted compatibility obligation; cookie regeneration and lifetime semantics require conformance controls. No live service or production data was touched. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **failure-handling:** Reader/storage failures, bearer fallback, mail configuration and CSRF refusal were reviewed in source. Independent private verification was incomplete, and final caller outcomes across installed applications remain unqualified. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **distribution:** All 32 non-test split files are rostered and source-reviewed. Root autoload success is not a standalone split proof. No-dev and generated profiles remain named gaps. Remaining item-specific proof is owned by waaseyaa/user #3118.
- **introspection-cli:** User contributes no commands or introspection engine. CLI account commands are consumers and are covered in the CLI audit.
- **generation-build:** No code generator or frontend build is shipped here. Generated account flows belong to CLI/skeleton.

## Decisions and uncertainties

| ID | Status | Decision or uncertainty | Resolution or next evidence |
| --- | --- | --- | --- |
| U1 | open | Fresh installed/no-dev, generated-application and supported closure qualification is not established. | Owning package and #3199 run the supported profile matrix at an immutable candidate with declared dependencies only. |
| U2 | open | Some historical issue acceptance and specification traceability remains unreconciled. | Owning package completes per-item source/spec/test reconciliation from the captured issue, Project and document inventories. |

## Remediation plan

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| USER-S1 | USER-CONTRACT-001 | Pin stale-key removal, empty replacement, null versus absent, bag retrieval, metadata and lifetime behavior with positive controls. Evaluate a maintained Symfony integration while preserving current raw session consumers. Any public removal requires a separate Tier A decision. | none |
| USER-S2 | USER-DOC-001 | Document current session abstraction without renaming its persisted UID key; describe the actual development gate and projected-read policy path. Use accurate responsibility-family navigation. Establish UserSession's current obligation before any separately reviewed public change; qualify optional mail composition. | none |

## Not reviewed

- Fresh standalone/no-dev split, generated application and released-consumer qualification.
- Completion of independent review for a private lead remains unavailable; no completed verdict is claimed.
- External consumers of apparently unused public symbols; no removal recommendation is accepted without that evidence.

## Host limits

- Native Windows has known SQLite teardown, permission, symlink and POSIX gate limits (#3096, #3081). Missing installed and generated evidence belongs to hosted Linux jobs; no waiver or pass is claimed.

## Evidence

| Command or probe | Proves | Result |
| --- | --- | --- |
| Explicit-file packages/user/tests PHPUnit run --no-coverage --do-not-cache-result | Source package unit/integration assertions | 402 tests, 823 assertions, pass; 14.939 seconds. |
| PHPStan analyse audit, entity-storage, foundation, cli and user src paths with scratch config including repository config; unmatched baseline reports disabled for scoped analysis. | Scoped production-source static analysis under the existing baseline. No installed or runtime qualification. | Exit 0; zero reported errors and zero file errors. Cache and output confined to audit scratch. |

## Scorecard

- 32 production files; 3282 PHP lines; 9 declared inbound packages.
- Root plus three reused independent reviewers. Parallel investigation is user-authorized; token usage unavailable.
- **Open:** 0 open decisions; 2 open uncertainties; remediation filing and delivery remain separate.
- **Elapsed:** Started 2026-10-19 17:41 UTC; generated 2026-10-19 20:06 UTC. Initial allocation 120 minutes with reported checkpoint; phase-specific token metrics unavailable.
- **Verification corrections:** 3; **findings without a filed issue:** 3. Private advisory routing is separate.
- **Probe retention:** 1 nonsecurity entries; 0 retained. Findings whose notes say reproduced (probe not retained) use the validator's reproduced enum; their repair regression becomes durable evidence.
