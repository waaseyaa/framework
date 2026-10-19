# `waaseyaa/audit` audit

- **Milestone:** assessed. Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** planned
- **Base:** `3161864a5d1af57621e4819e9720f12e12ccdae7`, audited `2026-10-19`
- **Dependency identity:** `composer.lock` SHA-256 `8719a36fb5792012ce6f08db9df52351d9339345606b882e9d07ed2bfe3461bc`; PHP 8.5.5, native Windows.
- **Evidence freshness:** current at `3161864a5d1af57621e4819e9720f12e12ccdae7`.
- **Owner:** `waaseyaa/framework#3118`
- **Structured ledger:** `docs/audits/packages/audit.ledger.json`: 6 findings, 0 refuted leads, 187 checklist answers, 0 handoffs, 0 decisions (0 open), 3 uncertainties (3 open), 1 probe entries, 2 evidence runs.

## Summary

Audit owns ordinary history, strict receipts, operation approvals and checkpoint custody with different guarantees. All 72 shipped files were reviewed. Independent checks support the closed status-batch contract gap and private triage, with current consumer and lifecycle qualification explicitly bounded.

- Assessed after independent finding verification, final citation/redaction review and protected-state checks.
- Every shipped file is classified; profile, sampling and historical acceptance gaps have named owners. Repairs and convergence are not claimed.

## Charter

- **Owns:** Ordinary best-effort event recording and query, strict protected-read and mutation receipt implementations, human operation approvals, checkpoint integrity/export and checkpoint-key succession. Evidence: AuditServiceProvider; Contracts, Writer, Integrity, Rekey and Storage namespaces.
- **Does not own:** Access capability policy, application domain mutations, kernel boot frequency, operator command presentation or database transaction primitives. Evidence: access and foundation ports; entity-storage gateways; cli audit commands.
- **Consumers:** Core kernel composition, API requests, entity lifecycle, publishing, agent tools, MCP, broadcast, closed User readers and CLI audit maintenance. Evidence: AuditServiceProvider registration and boot; package manifest and 12 declared inbound peers.
- **Dependencies:** Required: access, database, entity, entity-storage, foundation, scheduler, DBAL and Symfony UID. Event strings and listener duck typing add dynamic AI/MCP/broadcast contracts; user is a development dependency but closed readers operate on its current structural identity. Evidence: packages/audit/composer.json and provider/listener source.
- **Public surface:** Nineteen declarations plus real exposed classes require deliberate classification. Ordinary best-effort failures and strict reserve-before-read/finalize contracts are separate; checkpoint bytes are persisted integrity formats. Evidence: packages/audit/public-surface.php; Contracts; Writer; Integrity.
- **Installation profiles:** Kernel and framework closure are supported by current composition. Standalone primitives and no-dev split closure need explicit qualification; no fresh installed consumer was run. Evidence: Package manifest, source provider and explicit-file package tests.

## Roster

The ledger lists every one of the 72 production candidates individually. The split uses the package subtree; tests are excluded from the production roster. Evidence levels distinguish inventory, source review, synthetic reproduction and installation qualification.

- 14 files: reviewed / necessary but under-specified.
- 58 files: reviewed / owned and coherent.

## Intake

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |
| GRP-SEC-003 | groups audit | confirmed | GRP-SEC-003 |

## Package findings

GitHub intake captured all open issues and PRs with pagination, plus all 343 Framework Project 4 items. The ledger distinguishes completed reconciliation from acceptance still requiring review. This publication records the assessment; it implements no repairs and closes no issues.

| ID | Title | Severity | Confidence | Level | Verified | Destination |
| --- | --- | --- | --- | --- | --- | --- |
| AUD-SEC-001 | Cumulative resource use requires private triage. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/audit) |
| GRP-SEC-003 | Inherited authority-retention report reviewed in private. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/audit) |
| AUD-SEC-002 | Inherited identity-resolution report reviewed in private. | withheld | confirmed | reproduced | A | private report (framework advisory; owner waaseyaa/audit) |
| AUD-CONTRACT-001 | The closed User reader lacks the status-only batch seam required by its consumer | medium | confirmed | reproduced | A | [#3184](https://github.com/waaseyaa/framework/issues/3184); AUD-S1 |
| AUD-DOC-001 | Package navigation and lifecycle documentation need reconciliation | info | confirmed | reviewed | C | [#3212](https://github.com/waaseyaa/framework/issues/3212); AUD-S2 |
| AUD-COMPONENT-001 | File export needs a bounded maintained-filesystem equivalence review | info | confirmed | reviewed | C | [#3015](https://github.com/waaseyaa/framework/issues/3015); AUD-S3 |

### `AUD-CONTRACT-001`: The closed User reader lacks the status-only batch seam required by its consumer

- **observed:** The access-owned closed User reader declares six single-entity operations and its audit implementation offers no status-only batch operation. A fresh API-shape discriminator confirms the generic public batch operation exists while the closed seam is absent. packages/access/src/User/UserInternalFieldReaderInterface.php:11; packages/audit/src/Bootstrap/AuditedUserInternalFieldReader.php:26; packages/audit/src/AuditedFieldRead.php:106.
- **expected contract:** #3184 requires fresh active status for a batch of Users without requiring mail/verification data and while preserving exact per-entity audit evidence.
- **consequence:** The FETDER consolidation consumer still needs an application-owned narrow interim boundary. Reported Linux latency is historical issue evidence, not remeasured by this audit.
- **refutation:** Generic batch machinery already exists. No batch receipt row reduction is promised. Nested caller transactions can roll back generic receipts, so outer-transaction durability semantics must be settled rather than assumed.
- **acceptance:** Provide the closed status-only batch port and implementation with fresh disable/reactivate controls, exact status-only metadata and one truthful receipt per entity. Explicitly decide/refuse/qualify caller-transaction behavior; preserve reserve-before-obtain and terminal failure evidence. Qualify the existing consumer with synthetic Linux workload at the candidate.
- **residual risk:** Does not address broader history lifecycle or imply a latency target measured here.
- **next action:** Audit owns implementation; access owns the port contract; User remains a consumer.

## Cross-package findings

| ID | Owned by | Title | Destination |
| --- | --- | --- | --- |

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | source | Source composition reviewed at the base; exact scope and test outcomes are recorded separately. | waaseyaa/audit #3118 |
| standalone split | undecided | none | No fresh isolated no-dev installation was qualified. Decide supported primitive and kernel closure, then exercise declared dependencies without root autoload help. | waaseyaa/audit #3118 |
| framework closure | yes | none | Current docs-only CI 39295771289 succeeded; behavior and installed-profile jobs were skipped and do not qualify this profile. | waaseyaa/audit #3118 |
| generated application | undecided | none | No fresh generated application was qualified; coordinate J1 #3199 and generator program #2844. | waaseyaa/framework #3199 |

## Profile and structural review

- **Identity and ownership:** The split subtree, provider and four lifecycle families establish current ownership. No package README exists. Standalone primitive versus kernel closure remains a named qualification gap under #3118.
- **Entrypoints and runtime wiring:** The provider binds ordinary and strict ports, closed readers, integrity custody, operation approvals and rekey contributions. Boot asserts installed schemas and registers listeners. Optional-present and optional-absent installed combinations remain unqualified. Owner: waaseyaa/audit #3118.
- **Contracts and public surfaces:** Strict and best-effort histories have different failure promises. Migrations preserve historical persisted identities. The bounded interface/enum declaration check found declared shapes represented. Public concrete classes do not automatically require manifest entries. Remaining full export and installed caller qualification is owned by #3118.
- **Dependency structure:** Ports are access/foundation-owned and database adapters audit-owned. Dynamic event shapes and provider lookup add temporal coupling. Existing layer checks remain authoritative until #3075 Deptrac migration; no new parser or dependency configuration was introduced. Remaining item-specific proof is owned by waaseyaa/audit #3118.
- **Code quality and cohesion:** Four lifecycle families are navigable by directory, but provider setup spans them all. Succession canonical record/body encodings repeat between adapter and verifier. Legacy listener shape fallbacks need real current-caller obligations; no removal is justified solely by absence in a search. Checkpoint capture/materialization needs scale evidence before a performance claim. Remaining item-specific proof is owned by waaseyaa/audit #3118.
- **PHPDoc and human comments:** Provider schema-install wording lags current nonmutating validation. Canonical byte formats and historical migrations retain real persisted-data obligations. Reconcile stale purpose text without changing behavior to match prose. AUD-DOC-001 owns ordinary navigation and schema wording.
- **Custom infrastructure versus Symfony:** Domain receipt capabilities, cryptographic formats and rekey transitions are Framework policy, not generic infrastructure replacement targets. File export can use maintained Filesystem primitives while retaining audit custody and failure policy. That fit needs a bounded proof, including atomic export/failure behavior, under #3015 rather than an unqualified replacement claim.
- **Tests and mechanical gates:** The explicit-file package run completed 369 tests and 1024 assertions with one failing refusal fixture on this Windows host. Existing tests and private verification prove only their stated boundaries. Overlapping runs are not added. Owner: waaseyaa/audit #3118; Linux CI owns portable refusal confirmation.
- **Documentation and delivery forms:** Runtime source review is fresh. Current docs-only CI does not qualify installed or generated behavior. Package README, lifecycle runbook and public boundary reconciliation remain owned by waaseyaa/audit #3118.
- **Existing work, specifications and repository hygiene:** All 200 open issues, 15 open PRs and 343 Project items were captured with pagination. Relevant closed search is capped at 100; comments were refreshed for 55 selected issues, including 22 with discussion. All 244 document candidates have inventory dispositions; 17 named specification paths have current requirement/code/evidence traces. Whole-document semantic gaps and historical acceptance retain their named owners and are not fresh qualification.
- **Behavior and boundary integrity:** Current source and named tests were inspected, but complete real-composition refusal, failure, optionality and lifecycle evidence is not established. Owner: waaseyaa/audit #3118.
- **domain-contracts:** Ordinary recording, privileged reads, mutation approvals and integrity custody have distinct contracts. The closed User status batch operation remains #3184; its acceptance must settle outer-transaction semantics. Owner: waaseyaa/audit, with waaseyaa/access for the port.
- **http-ui-contracts:** The package contributes request attribution and field-read middleware, but owns no general route or frontend wire surface. Final HTTP refusal/composition evidence depends on API, access and foundation. Existing middleware tests prove only their stated source boundary. Remaining item-specific proof is owned by waaseyaa/audit #3118.
- **kernel-runtime:** Source provider composition was reviewed. Optional installed-package matrices and failure recovery remain qualification gaps owned by waaseyaa/audit #3118. Private findings are handled separately.
- **persistence-execution:** Writer, schema, migration, checkpoint and succession source was read. Ordinary retention #3010/#3011 and overlapping sealing/export #3015 retain existing ownership. Source tests do not qualify production maintenance or crash recovery. Remaining item-specific proof is owned by waaseyaa/audit #3118.
- **failure-handling:** Ordinary writer is best-effort; strict ports propagate reserve/finalization failure. The observer contract forbids throwing, so an imagined throwing observer is not a demonstrated production defect. Export and checkpoint outcomes remain #3015 qualification work. Remaining item-specific proof is owned by waaseyaa/audit #3118.
- **distribution:** Every non-test split-subtree file is inventoried and reviewed in source. No fresh standalone no-dev or installed application was tested; those evidence classes remain named gaps under #3118 and J1 #3199.
- **introspection-cli:** Commands live in waaseyaa/cli. Audit provides query and integrity services consumed by those commands; command exit/envelope semantics are reviewed in the CLI audit.
- **generation-build:** No application code generator, frontend build or committed binary distribution is owned by this package. Schema migrations are covered by persistence.

## Decisions and uncertainties

| ID | Status | Decision or uncertainty | Resolution or next evidence |
| --- | --- | --- | --- |
| U1 | open | Fresh installed/no-dev, generated-application and supported closure qualification is not established. | Owning package and #3199 run the supported profile matrix at an immutable candidate with declared dependencies only. |
| U2 | open | Some historical issue acceptance and specification traceability remains unreconciled. | Owning package completes per-item source/spec/test reconciliation from the captured issue, Project and document inventories. |
| U3 | open | Restricted historical source referenced by existing retention and export issues was not found in the designated private custody locations. | The #3001 source holder supplies the referenced restricted artifact, or the issue owner explicitly re-establishes bounded acceptance from current authorized evidence without publishing private details. |

## Remediation plan

Every ordinary finding now has a filed issue linked in its destination above. Issue routing leaves the recorded acceptance, evidence limits and assessment milestone unchanged.

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| AUD-S1 | AUD-CONTRACT-001 | Provide the closed status-only batch port and implementation with fresh disable/reactivate controls, exact status-only metadata and one truthful receipt per entity. Explicitly decide/refuse/qualify caller-transaction behavior; preserve reserve-before-obtain and terminal failure evidence. Qualify the existing consumer with synthetic Linux workload at the candidate. | none |
| AUD-S2 | AUD-DOC-001 | Add a package/service map, correct schema-lifecycle comments, preserve historical migration bytes, and compare canonical format helpers byte-for-byte before consolidation. Inventory exact current event-shape obligations before any separately reviewed removal. | none |
| AUD-S3 | AUD-COMPONENT-001 | Under #3015 compare append versus replacement, locking, failure mapping, stdout and exact bytes. Keep custody policy with Audit and choose the smallest maintained-component integration only after equivalence controls pass. | none |

## Not reviewed

- Fresh installed/no-dev split, generated application and released-consumer qualification.
- Current acceptance for every historical issue still requires reconciliation; issue titles are not proof of defect persistence.
- The restricted historical artifact behind parts of #3010/#3011/#3015 was not recovered; existing issue ownership is retained.
- Current affected consumer configurations and full installed routes were not qualified; private source findings do not establish deployed exposure.

## Host limits

- Native Windows has known SQLite teardown, permission, symlink and POSIX gate limits (#3096, #3081). Missing installed and generated evidence belongs to hosted Linux jobs; no waiver or pass is claimed.

## Evidence

| Command or probe | Proves | Result |
| --- | --- | --- |
| Explicit-file packages/audit/tests PHPUnit run --no-coverage --do-not-cache-result | Source package tests, not installed qualification | 369 tests, 1024 assertions, one failure: /proc is writable on native Windows. |
| PHPStan analyse audit, entity-storage, foundation, cli and user src paths with scratch config including repository config; unmatched baseline reports disabled for scoped analysis. | Scoped production-source static analysis under the existing baseline. No installed or runtime qualification. | Exit 0; zero reported errors and zero file errors. Cache and output confined to audit scratch. |

## Scorecard

- 72 production files; 7339 PHP lines; 12 declared inbound packages.
- Root plus three reused independent reviewers. Parallel investigation is user-authorized; token usage unavailable.
- **Open:** 0 open decisions; 3 open uncertainties; remediation filing and delivery remain separate.
- **Elapsed:** Started 2026-10-19 17:41 UTC; generated 2026-10-19 20:06 UTC. Initial allocation 120 minutes with reported checkpoint; phase-specific token metrics unavailable.
- **Verification corrections:** 5; **findings without a filed issue:** 3. Private advisory routing is separate.
- **Probe retention:** 1 nonsecurity entries; 1 retained. Findings whose notes say reproduced (probe not retained) use the validator's reproduced enum; their repair regression becomes durable evidence.
