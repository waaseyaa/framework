# `waaseyaa/cli` audit

- **Milestone:** assessed. Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** planned
- **Base:** `3161864a5d1af57621e4819e9720f12e12ccdae7`, audited `2026-10-19`
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5, native Windows.
- **Evidence freshness:** Original assessment at `3161864a5d1af57621e4819e9720f12e12ccdae7`; bounded J1 supplement at published alpha.308 below. Other assessment scope is not refreshed.
- **Owner:** `waaseyaa/framework#3117`
- **Structured ledger:** `docs/audits/packages/cli.ledger.json`: 7 findings, 0 refuted leads, 187 checklist answers, 0 handoffs, 0 decisions (0 open), 3 uncertainties (3 open), 1 probe entries, 3 evidence runs.

## Summary

CLI owns command adaptation, operator tooling and application generation. All 292 production files have individual classifications; 82 retain explicit sampling limits. Independent command probes confirmed fractional-limit truncation and false artifact-write success. Four structural and documentation follow-ups have bounded acceptance.

- Assessed after independent finding verification, final citation/redaction review and protected-state checks.
- Every shipped file is classified; profile, sampling and historical acceptance gaps have named owners. Repairs and convergence are not claimed.

## Charter

- **Owns:** Operator commands, application introspection adapters, scaffolding/site generation and management-plan workflows. Evidence: packages/cli/composer.json; README; production inventory and current composition.
- **Does not own:** Domain persistence, authorization policy, schema transition authority and kernel composition contracts that commands invoke. Evidence: Peer package contracts and existing issue ownership.
- **Consumers:** Installed ConsoleKernel applications, waaseyaa/cms, generated projects and operators. Evidence: Composer inbound inventory and source entrypoints.
- **Dependencies:** Symfony Console/Finder and a broad content/presentation closure. Optional AI, MCP and OIDC integrations are distinct from hard requirements; #2821 owns remaining CLI closure reduction. Evidence: packages/cli/composer.json
- **Public surface:** Command names/options, exit statuses and machine-readable outputs, handlers/provider registrations, generated artifacts and plan digests. Evidence: packages/cli/public-surface.php and actual source callers.
- **Installation profiles:** Minimal J1 installed dev/no-dev and generated-application composition is qualified by the alpha.308 supplement. Standalone split and wider package profiles remain incomplete. Evidence: FW-J1-PUBLISHED-S1-01 and the portable published summary below.

## Roster

The ledger lists every one of the 292 production candidates individually. The split uses the package subtree; tests are excluded from the production roster. Evidence levels distinguish inventory, source review, synthetic reproduction and installation qualification.

- 262 files: reviewed / owned and coherent.
- 17 files: reviewed / duplicated or drifting contract.
- 13 files: reviewed / necessary but under-specified.

## Intake

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |
| H15 | groups audit | merged | CLI-SEC-001 |

## Package findings

GitHub intake captured all open issues and PRs with pagination, plus all 343 Framework Project 4 items. The ledger distinguishes completed reconciliation from acceptance still requiring review. This publication records the assessment; it implements no repairs and closes no issues.

| ID | Title | Severity | Confidence | Level | Verified | Destination |
| --- | --- | --- | --- | --- | --- | --- |
| CLI-SEC-001 | A migration-integrity issue requires private triage. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/cli) |
| CLI-INPUT-001 | Three import commands truncate a fractional --limit of 1.9 to one record | low | confirmed | reproduced | B | [#3220](https://github.com/waaseyaa/framework/issues/3220); CLI-S1 |
| CLI-IO-002 | Four artifact output commands and forced sync-rules updates report success after a refused final write | low | confirmed | reproduced | B | [#3221](https://github.com/waaseyaa/framework/issues/3221); CLI-S2 |
| CLI-DOC-001 | Documentation diverges from current CLI contracts | info | confirmed | reviewed | C | [#3222](https://github.com/waaseyaa/framework/issues/3222); CLI-S3 |
| CLI-NAV-001 | Legacy config handlers need a bounded disposition against canonical config authority | info | confirmed | reviewed | C | [#3223](https://github.com/waaseyaa/framework/issues/3223); CLI-S4 |
| CLI-STRUCT-001 | Site initialization combines distinct policy responsibilities | info | confirmed | reviewed | C | [#3224](https://github.com/waaseyaa/framework/issues/3224); CLI-S5 |
| CLI-COMPONENT-001 | Custom process runners need a bounded maintained-component comparison | info | likely | reviewed | C | [#3225](https://github.com/waaseyaa/framework/issues/3225); CLI-S6 |

## Cross-package findings

| ID | Owned by | Title | Destination |
| --- | --- | --- | --- |

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | source | Source composition reviewed at the base; exact scope and test outcomes are recorded separately. | waaseyaa/cli #3117 |
| standalone split | undecided | none | No fresh isolated no-dev installation was qualified. Decide supported primitive and kernel closure, then exercise declared dependencies without root autoload help. | waaseyaa/cli #3117 |
| framework closure | yes (minimal J1 composition only) | installed consumer | Qualified minimal J1 production dev/no-dev ConsoleKernel/HTTP composition, own field activation, repeat content and refusal controls on observed Ubuntu24/ext4/Apache2.4/PHP-FPM8.5. FW-J1-PUBLISHED-S1-01; docs/audits/packages/evidence/j1/published-alpha308.json; run39985296383 at harness c0fe3e35acf487b781ce472227c45473f2eb8748, published alpha.308 baead2150a78883b82536282d1181cf5b7a92a10. Wider package closure behavior remains unqualified. | waaseyaa/cli #3117 |
| generated application | yes (minimal J1 only) | generated application | Qualified minimal generated J1 application only: actual published Skeleton c83a410bbae6ce9d491c733be7e339b540ec42fd, alpha.308 cohort, lock6bac4a1c9dec43a8796fc36abefc25670bc2aef319709ee7d1efff6444aa8001; cold/warm/restarted Twig homepage and refusal controls in separate dev/no-dev apps. FW-J1-PUBLISHED-S1-01; docs/audits/packages/evidence/j1/published-alpha308.json; run39985296383 at harness c0fe3e35acf487b781ce472227c45473f2eb8748, published alpha.308 baead2150a78883b82536282d1181cf5b7a92a10. Wider generation profiles remain #2844/#2850. | waaseyaa/cli #3117; wider generation #2844/#2850 |

## Profile and structural review

- **Identity and ownership:** Symfony Console provides dispatch/parsing; Waaseyaa owns provider command admission, operator presentation, project/site generation and bounded application work. The 29 provider declarations and large content closure make a core-versus-optional profile decision necessary. Owner: waaseyaa/cli #3117; #2821.
- **Entrypoints and runtime wiring:** ConsoleApplicationFactory admits provider commands through optional-package gates and binds handlers to KernelHandlerContainer. ConsoleKernel supplies boot-free/restricted/runtime composition. Audit provider commands reach real audit services. Full command-family presence/absence and name collision behavior need a profile matrix. Owner: #3117.
- **Contracts and public surfaces:** Command metadata, HandlerCommand and SymfonyCommandIO translate input/output; raw machine bytes require writeRaw. Exceptions are normalized to documented CLI status families. Public test helper delivery and generator interfaces remain #2961/#3117 work. No compatibility removal is proposed.
- **Dependency structure:** Current runtime no longer requires ai-vector. It still requires content/presentation peers including listing, search, path, seo, ssr and page-builder. #2821's old closure counts are historical and must be recomputed by installation. Layer gate passes; package Deptrac configuration remains #3075 work. Remaining item-specific proof is owned by waaseyaa/cli #3118.
- **Behavior and boundary integrity:** Focused command/discovery tests ran 29 tests/113 assertions: two Windows filesystem fixture failures, all other selected cases passed. Operator authority, partial outcomes and generated artifact fidelity require per-family terminal evidence. Private triage is handled separately. Owner: #3117.
- **Code quality and cohesion:** Command, Handler and Provider offer clear entrypoints but repeated registration/metadata and broad Misc providers make one command change span multiple files. SiteInitializationService combines planning, review, lock/journal and recovery across a large host; retain one publication authority while bounding extraction. Process execution is duplicated across project-init and admin-build with different host behavior. ProjectSourceDiscovery and ManagementInputDiscovery intentionally select different input universes; do not merge their policies. Owner: #3117.
- **PHPDoc and human comments:** Public surface and orchestration docs retain removed CLI kernel/parser names and historical blueprint activation wording. Current boot-free site commands supersede older full-boot descriptions. Byte preservation, cancellation and partial success need exact terminal semantics. Owner: #3117; #2961.
- **Custom infrastructure versus Symfony:** Console/Finder are already used. Symfony Process 8.0.8 is installed for development and is a candidate for repeated proc_open loops; equivalence must preserve bounded output, timeout, cleanup/termination confirmation, environment, sanitization and native Windows behavior. Finder could simplify architecture-source scanning while preserving its intentional exclusions. Symfony Lock is a candidate for low-level locking, not a replacement for the publication journal or artifact identity. None is yet a verified replacement. Owner: #3117.
- **Tests and mechanical gates:** Audit verify/prune/checkpoint and route-list controls passed in the focused run. Management-input mode and symlink fixtures failed on native Windows. Full CLI process, generated-app and no-dev tests were not run. Linux CI owns the unresolved POSIX cases. Owner: #3117.
- **Documentation and delivery forms:** Every one of 292 production files is classified. 210 have full, executable-body or attributed root review; 82 are explicitly sampled. #2821 owns core closure; #2961 owns public helper delivery; #3199 owns fresh app boot qualification. Source evidence does not qualify those profiles.
- **Existing work, specifications and repository hygiene:** All 200 open issues, 15 open PRs and 343 Project items were captured with pagination. Relevant closed search is capped at 100; comments were refreshed for 55 selected issues, including 22 with discussion. All 244 document candidates have inventory dispositions; 17 named specification paths have current requirement/code/evidence traces. Whole-document semantic gaps and historical acceptance retain their named owners and are not fresh qualification.
- **domain-contracts:** Command IO is an adapter contract; domain mutation belongs to each underlying package. Generation approval, artifact plans, publication journals and recovery are CLI-owned lifecycle policy. Incomplete terminal-family coverage remains #3117 work.
- **http-ui-contracts:** No HTTP serving runtime is owned. Generated route/access/controller artifacts and admin build output cross public wire contracts; source generator tests alone do not qualify the generated application. Owner: #3117/#3199.
- **kernel-runtime:** Normal provider command composition and pre-boot/restricted command routing differ intentionally. Command-name profile selection belongs with #2859 and #3117. Optional absence must omit command families truthfully without activating missing dependencies.
- **persistence-execution:** Operator commands invoke audit, migration, queue and schema services with distinct completion semantics. Publication journal/lock/recovery and process interruption need supported-host qualification. Private triage remains separate. Owner: #3117; owning service issues.
- **failure-handling:** WaaseyaaConsoleApplication centralizes normal exits, validation failures and cancellation. Domain exceptions and partial mutation outcomes must reach the final caller without success wording. #3203 and #3035 retain cross-package ownership; broad handler mapping remains unreviewed. Remaining item-specific proof is owned by waaseyaa/cli #3118.
- **distribution:** ai-vector optionality is verified in source. Core closure and public helper availability need actual no-dev/split consumer execution, without root namespace mappings. No fresh installed CLI was qualified. Owner: #2821/#2961/#3117.
- **introspection-cli:** Current route list sees the canonical snapshot but lacks access posture. Audit commands use real services. All commands still need descriptor/help/status/JSON/optional-gate conformance, especially empty/refused/partial cases. Owner: #3117; #3007.
- **generation-build:** Blueprint compilation, site initialization and admin build are active capabilities. Source entrypoints and execution/recovery seams were reviewed; the roster distinguishes sampled large bodies. Full generated artifact drift, executable output, reproducibility and crash recovery remain owned qualification gaps under #3117/#3199.

## Decisions and uncertainties

| ID | Status | Decision or uncertainty | Resolution or next evidence |
| --- | --- | --- | --- |
| U1 | open | Beyond the qualified minimal J1 alpha.308 dev/no-dev composition, standalone split and wider package/generator lifecycle qualification remains unestablished. | waaseyaa/cli #3117 and generator #2844/#2850 qualify their remaining supported profiles. Reuse the bounded J1 witness without treating it as full package convergence or S0-SHEG-02 certification. |
| U2 | open | Some historical issue acceptance and specification traceability remains unreconciled. | Owning package completes per-item source/spec/test reconciliation from the captured issue, Project and document inventories. |
| U3 | open | Finder and Lock fit beyond the independently reviewed Process candidate is source-only. | CLI #3117 runs bounded equivalence controls for path exclusions/symlink behavior and lock ownership/timeout/refusal under supported profiles. Adopt maintained generic mechanics when equivalent; preserve publication journals, artifact identities and uncertain-cleanup policy. No public surface removal is approved by this gap. |

## Remediation plan

Every ordinary finding now has a filed issue linked in its destination above. Issue routing leaves the recorded acceptance, evidence limits and assessment milestone unchanged.

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| CLI-S1 | CLI-INPUT-001 | Define and share one lexical positive-integer contract across the three builders, with an explicit in-range policy. Reject fractional values before runner or lock activity; verify 1.9 and 0.9, zero and malformed values. Keep absent-option and valid 2 behavior including resume position and run-all totals. Assert terminal writes/checkpoints and exit/stderr, not only a private builder return. | none |
| CLI-S2 | CLI-IO-002 | Check false and short final writes, return nonzero, report failure through stderr, and suppress success messages/counters. Verify the five named commands with ordinary-file, directory-target and pre-write refusal/no-overwrite controls. Preserve successful bytes and existing valid stdout/stderr behavior. Independently verify every further writer before expanding scope; post-domain-mutation recovery/logging needs distinct outcome semantics. | none |
| CLI-S3 | CLI-DOC-001 | Update descriptions to current behavior and ordering. Update emitted comments and their byte expectations, without implementing the implied future relationship consumer. Describe current compiler selection and publication gates distinctly. | none |
| CLI-S4 | CLI-NAV-001 | Record actual current caller and persisted-data/external integration obligations with config authority. In a separately authorized repair, choose a bounded convergence disposition and update current callers. Do not retain solely because @api or unknown hypothetical callers. No API removal is authorized by this assessment. Any removal finding or proposal requires Tier A review and appropriate scope; no deletion recommendation is made here. | none |
| CLI-S5 | CLI-STRUCT-001 | If separately selected for repair, retain one apply authority and exact plan/state and receipt lifecycle. Use byte-exact Composer and normal/refused/interrupted/recovery behavior comparisons. Preserve platform-specific durability, ownership, rollback refusal and uncertain cleanup semantics. | none |
| CLI-S6 | CLI-COMPONENT-001 | Require environment omission tests, Windows file/pipe capture, bounded per-stream output, cross-stream redaction and uncertain-cleanup outcomes before substitution. Retain distinct domain policies even if generic process mechanics can later be shared. Select dependency/runtime profile explicitly; a dev-locked library is not evidence of a no-dev production dependency or a supported newest-version recommendation. | none |

## Not reviewed

- 59 large source bodies and 23 provider registration files received bounded semantic sampling, not exhaustive whole-body review. Each structured-ledger roster entry records its review depth; remaining command-family and installed-profile qualification stays with #3117.
- Fresh installed/no-dev split, generated-application and native-host release qualification.
- Complete current acceptance for existing command-family and generation issues; retained per-item ownership is not a closure claim.

## Host limits

- Native Windows has known SQLite teardown, permission, symlink and POSIX gate limits (#3096, #3081). Missing installed and generated evidence belongs to hosted Linux jobs; no waiver or pass is claimed.

## Evidence

| Command or probe | Proves | Result |
| --- | --- | --- |
| PHPStan analyse audit, entity-storage, foundation, cli and user src paths with scratch config including repository config; unmatched baseline reports disabled for scoped analysis. | Scoped production-source static analysis under the existing baseline. No installed or runtime qualification. | Exit 0; zero reported errors and zero file errors. Cache and output confined to audit scratch. |
| Independent CLI command-probe.php | Synthetic source composition with real command adapters, runner checkpoints and filesystem outcomes. No installed profile. | 33 invocations; all expected defect and control assertions passed; no external or production data. |

## J1 installed-profile supplement, 2026-10-23

The [portable published summary](evidence/j1/published-alpha308.json) and
[FW-J1-PUBLISHED-S1-01](../../change-records/FW-J1-PUBLISHED-S1-01.md) retain
actual release, template, cohort/lock, per-installation activation and HTTP
identities. [Run39985296383](https://github.com/waaseyaa/framework/actions/runs/39985296383)
passed for separate native Linux production dev/no-dev applications. Independent
source and final custody/claims review passed. The two earlier failed runs remain
preserved harness failures, not repaired original applications.

This supplements the installation evidence only. The original audit base,
findings, source roster, open decisions/uncertainties and package convergence
state remain distinct. Standalone split, wider lifecycle/generator contracts,
#3198/#3203 diagnostics assessment and S0-SHEG-02 consumer certification retain
their own acceptance. No WSL2 serving certification is inferred.

| Command or probe | Proves | Result |
| --- | --- | --- |
| Canonical published J1 manual qualification, run39985296383, harness c0fe3e35 | Minimal generated/installed dev/no-dev activation, HTTP, repeat persistence and refusals on the observed native tuple only. | PASS both profiles; 29 hosted tests/5019 assertions. Own names-only scan/artifact, 226 dev/187 no-dev fields, Twig42 cold/warm/restarted and planned refusal/restoration controls. |

## Scorecard

- 292 production files; 41227 PHP lines; 1 declared inbound packages.
- Root plus three reused independent reviewers. Parallel investigation is user-authorized; token usage unavailable.
- **Open:** 0 open decisions; 3 open uncertainties; remediation filing and delivery remain separate.
- **Elapsed:** Started 2026-10-19 17:41 UTC; generated 2026-10-19 20:06 UTC. Initial allocation 120 minutes with reported checkpoint; phase-specific token metrics unavailable.
- **Verification corrections:** 2; **findings without a filed issue:** 1. Private advisory routing is separate.
- **Probe retention:** 1 nonsecurity entries; 0 retained. Findings whose notes say reproduced (probe not retained) use the validator's reproduced enum; their repair regression becomes durable evidence.
