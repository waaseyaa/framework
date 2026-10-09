# Messaging package convergence assessment

- **Milestone:** assessed (structural supplement independently reviewed). Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** planned.
- **Base:** `2ba3821a8224950b443fc5060e5541fb29e4962a`, audited `2026-10-07` America/Toronto.
- **Dependency identity:** `composer.lock` SHA-256 `f4b7a1f592d50e45f33a503391aedc9cc7a8f19dc478ba09b16c0a78bd75d036`; PHP 8.5.5, native Windows.
- **Evidence freshness:** current at the frozen base; local and live main matched at intake and after verification.
- **Owner:** waaseyaa/framework#3118; incorporates existing #2753.
- **Profiles applied:** domain, persistence/execution, kernel wiring, HTTP boundary and distribution. Introspection/CLI and generation/build do not apply: no package-owned command, generator or build artifact.
- **Structured ledger:** `docs/audits/packages/messaging.ledger.json`: 11 findings, 0 refuted leads, 23 checklist answers, 1 handoffs, 4 decisions (2 open), 1 uncertainties (1 open), 3 probe entries, 4 evidence runs.
- **Publication boundary:** assessment and bounded S3/S7 repair landed in PR #3189. The index records assessed with remaining remediation planned. Whole-package convergence is not claimed.

## Summary

Messaging is an opt-in L3 entity substrate. It owns thread, membership and message shapes, participant access, creator bootstrap and membership uniqueness. It does not supply a complete conversation lifecycle service; a missing service is not itself a defect.
The existing #2753 atomicity defect reproduces at current main: normal SQL creation yields one owner; injected membership failure leaves a durable thread without an owner and reports success. Memory is a testing composition, not another production backend.
The source suite passes 25 tests / 56 assertions. Two independent reviews confirmed atomicity and qualification gaps, narrowed documentation claims, and reduced the lifecycle finding to low. They also confirmed an API-owned identity-classification failure and a public adapter autoload mismatch.
Authority-sensitive observations have independent tier-A evidence and safe summaries below. Details remain in the local private brief. Two integrity/authority defects are confirmed; attribution is conditional on the ratified contract. No deployed exposure, Protected data disclosure or complete authenticated HTTP journey is claimed.
All assessment checkpoints are dispositioned in the local ledger. Repair readiness needs filed private work and bounded published slices; convergence is not claimed.

## Charter

- **Owns:** Thread/message/membership entities, participant-based access, creator bootstrap, uniqueness schema transition. Evidence: packages/messaging/src
- **Excludes:** Application consent, moderation, product UI, AI grounding, live push, notifications, attachment and media contracts. Evidence: docs/specs/messaging.md; #2054 remains a proposal.
- **Consumers:** Provider/policy manifest discovery, generic JSON:API entity routes and repository users. No concrete downstream production messaging consumer was established in the scoped local source search; product proposals are historical context. Evidence: composer.json extra.waaseyaa; JsonApiController; local anokii/src and studio/src search.
- **Dependencies:** Required access, database-legacy, entity, entity-storage and foundation; field is locally dev-declared but transitively runtime through entity-storage. Symfony event-dispatcher is the existing maintained mechanism. Evidence: packages/messaging/composer.json; packages/entity-storage/composer.json; Symfony 7.4 EventDispatcher docs.
- **Public surface:** MessageThread, ThreadMessage, ThreadParticipant, MessagingServiceProvider, MessagingAccessPolicy, its two Protected policy adapters, subscriber and schema transition. PSR-4 and manifest entries are public seams; no public-surface declaration exists. No removal authorized. Evidence: Nine production files; source annotations and Composer manifest.
- **Installation profiles:** Default entity storage is sql-blob on supported S1 SQLite. Opt-in kernel composition is intended. Standalone split and primitive-only qualification remain open. Root no-dev framework and curated metapackages omit messaging by design. Memory is a testing composition, not another production backend. Evidence: ContentEntityType default; root require-dev; #2753 terminology correction.
- **Observable evidence:** 25 source tests pass. Synthetic source provider composition confirms ordinary SQL creator membership and the known failure boundary. No installed-profile qualification is claimed. Evidence: source fixture and package PHPUnit run.

## Roster

| File | Role | Classification | Level | Findings |
| --- | --- | --- | --- | --- |
| `packages/messaging/README.md` | Package description | duplicated or drifting contract | reviewed | MSG-DOC-001 |
| `packages/messaging/composer.json` | Identity, dependency closure, autoload and provider/policy discovery | missing refusal, lifecycle, compatibility or distribution evidence | reviewed | MSG-QUAL-001 |
| `packages/messaging/src/MessageThread.php` | Conversation entity and defaults | necessary but under-specified | reviewed | MSG-CONTRACT-001 |
| `packages/messaging/src/ThreadMessage.php` | Message entity and constructor constraints | necessary but under-specified | reviewed | MSG-CONTRACT-001 |
| `packages/messaging/src/ThreadParticipant.php` | Membership, read state and schema declarations | necessary but under-specified | reviewed | MSG-CONTRACT-001 |
| `packages/messaging/src/MessagingAccessPolicy.php` | Entity, field and Protected read policies | necessary but under-specified | reviewed |  |
| `packages/messaging/src/MessagingServiceProvider.php` | Entity registration and subscriber boot | missing refusal, lifecycle, compatibility or distribution evidence | reviewed | MSG-QUAL-001 |
| `packages/messaging/src/EventSubscriber/ThreadParticipantBootstrapSubscriber.php` | Creator bootstrap lifecycle effect | missing refusal, lifecycle, compatibility or distribution evidence | reviewed | MSG-CREATE-001 |
| `packages/messaging/src/Schema/ThreadParticipantSchema.php` | Identity-column backfill and deterministic duplicate migration | owned and coherent | reviewed |  |

All nine exported production files are classified. No public surface removal is proposed.

## Intake

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |
| #2753 | FW-ARCH-2026-08 / #2724 / #2727 | confirmed | MSG-CREATE-001 |
| 2026-05 L2 package audit messaging section | docs/audits/2026-05-l2-content-types-audit.md | merged | MSG-DOC-001 |

Every committed package ledger was searched for messaging-owned/co-owned findings and handoffs. None routed a new finding. The groups ledger recognizes #2753 as separately owned. The earlier L2 audit establishes historical graduation and limited coverage, not a full convergence assessment. #2054 remains a feature proposal outside scope.

## Package findings

| ID | Title | Severity | Confidence | Level | Verified | Disposition | Destination | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| MSG-CREATE-001 | Required creator membership is a post-commit best-effort effect | medium | confirmed | reproduced | A | repair | #2753 | Ratify bounded slice |
| MSG-CONTRACT-001 | Membership and message lifecycle lacks a complete package contract | low | confirmed | reviewed | B | repair | S2 | Ratify bounded slice |
| MSG-DOC-001 | Documentation blurs current capability, schema authority and generic package ownership | low | confirmed | reviewed | B | repair | S3 | Ratify bounded slice |
| MSG-QUAL-001 | Tests and distribution evidence do not qualify canonical composition | low | confirmed | reviewed | B | repair | S4 | Ratify bounded slice |
| MSG-SURFACE-001 | Public adapter symbols require another class to be loaded first | low | confirmed | reproduced | B | repair | S3 | Ratify bounded slice |
| MSG-SEC-001 | Actor attribution lacks a settled authority contract | withheld | likely | reproduced | A | decision | private report (framework advisory; owner waaseyaa/messaging) | Private handling and contract disposition |
| MSG-SEC-002 | A mutation can persist without a valid authorization target | withheld | confirmed | reproduced | A | repair | private report (framework advisory; owner waaseyaa/messaging) | Private handling and contract disposition |
| MSG-SEC-003 | A mutation can cross its authorized conversation boundary | withheld | confirmed | reproduced | A | repair | private report (framework advisory; owner waaseyaa/messaging) | Private handling and contract disposition |

### `MSG-CREATE-001`: Required creator membership is a post-commit best-effort effect

- **Observed:** Existing #2753 revalidated at the base. Synthetic SQL source composition boots MessagingServiceProvider, creates one owner normally, then an injected participant insert failure leaves one thread and zero memberships without propagating failure. The memory testing composition creates no owner and rejects a manually seeded membership.
- **Expected contract:** Authenticated thread creation persists a thread and exactly one creator owner together, or neither.
- **Consequence:** Success can leave an inaccessible durable conversation. The memory testing driver cannot model the package contract.
- **Refutation:** Independent reviews confirmed the bounded observation; source composition is synthetic and no deployed consumer is established.
- **Dependencies:** D1
- **Acceptance:** Exercise canonical provider boot with real SQLite success and insertion failure; assert both rows or neither, actor recognition, retries and unique owner. Decide memory support explicitly.
- **Residual risk:** Installation qualification remains separate.
- **Next action:** Independent verification complete; ratify the named contract and authorize the bounded existing-owner slice.

## Cross-package findings and handoffs

| ID | Owner | Finding | Severity | Destination | Blocks this assessment |
| --- | --- | --- | --- | --- | --- |
| MSG-API-001 | waaseyaa/api | Generic API misclassifies messaging identities as config machine IDs | medium | waaseyaa/api convergence audit under #3118 | no |

Both independent reviewers reproduced ordinary thread label POST failure with real SQLite. JsonApiController identifies a non-default identity key without a bundle as config-style and generates a string identity from title/body/role. Messaging declares numeric content identities with UUID and no bundle. Label omission or a numeric diagnostic label succeeds in the same composition.

Acceptance: Create thread, message and membership through generic API with normal labels and no client identity; prove durable numeric storage identities and correct UUID representation; preserve real config machine names and refusal of content identity injection.

- H1 to waaseyaa/entity (co-owners waaseyaa/access, waaseyaa/messaging): One reviewer observed a Protected-selector representation failure with the real field-read guard. Reconcile layout self-input omission with policy dependencies and independently determine ownership; no confirmed defect or security disclosure is assigned here.

API owns its identity heuristic. Historical closed #254 concerns config entities and is a regression control, not the owner of the new numeric-content misclassification. No adjacent whole-package audit or repair is included.

## Refuted leads and calibrated claims

Both reviewers rejected absent-service-as-defect, omitted-opt-in-package-as-broken-boot, and broad-member-CRUD-as-escalation without a ratified narrower contract. These are corrections to existing findings/decisions, not additional counted leads. Unknown deployed consumers and absent direct source callers do not authorize public API removal.

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap owner |
| --- | --- | --- | --- | --- |
| primitive-only | undecided | none | No installed-profile evidence reused. Source fixture is reproduced source evidence only. | S4 / waaseyaa/messaging / D3 |
| standalone split | undecided | none | No installed-profile evidence reused. Source fixture is reproduced source evidence only. | S4 / waaseyaa/messaging / D3 |
| kernel composition | yes intended; unqualified | none | No installed-profile evidence reused. Source fixture is reproduced source evidence only. | S4 / waaseyaa/messaging / D3 |
| metapackage | no by default; explicit opt-in is a separate composition | none | No installed-profile evidence reused. Source fixture is reproduced source evidence only. | none |
| framework closure | no by default; explicit opt-in is a separate composition | none | No installed-profile evidence reused. Source fixture is reproduced source evidence only. | none |
| generated application | undecided | none | No installed-profile evidence reused. Source fixture is reproduced source evidence only. | S4 / waaseyaa/messaging / D3 |

Independent generic principal-source tracing is complete: tool hosts and authenticated MCP are conditional on host configuration and grants; public/default MCP mutations are closed; CLI is trusted and actorless; jobs require application authority composition. These source dispositions do not qualify an installed host or consumer.
Root development dependencies contain messaging, but framework/core/cms/full runtime closures omit it by design. An ordinary closure boot cannot qualify messaging. Supplied repositories and copied root development dependencies are source diagnostics, not installed-package evidence.

## Profile checklists

- **identity / Identity, version, split, exports (answered):** waaseyaa/messaging is an opt-in L3 Composer library, PHP >=8.5, version cohort alpha.305. Split target exists; production roster has 9 files. Evidence: composer.json; VERSION; split.yml; git archive base.
- **identity / Charter and product ownership (finding):** Current descriptions conflate primitives, planned services and product names. Evidence: README; messaging spec
- **domain / Invariants and lifecycle (finding):** Creator ownership has failure evidence; remaining lifecycle choices need a package-level contract. Evidence: Source and #2753
- **domain / Events, payload, order, veto (finding):** WeakMap pairs PRE_SAVE to POST_SAVE by entity. Repository emits POST_SAVE after transaction commit, so required bootstrap cannot veto durable creation. Evidence: Subscriber and EntityRepository
- **domain / Extension seams and real callers (answered):** Schema attributes invoke transition through SqlSchemaHandler; manifest invokes provider and policy. No custom route, command, queue job, app event or cache provider exists. Evidence: Package source; SqlSchemaHandler; manifest discovery.
- **kernel / Discovery and boot dependency chain (gap):** Kernel service bus supplies manager, Symfony adapter and account holder. Provider returns early when mandatory services are unavailable. Synthetic supplied-service fixture works, but full kernel proof is unrun. Evidence: ProviderRegistryKernelServices; MessagingServiceProvider; source fixture
- **kernel / Request context and repeated boot (gap):** Subscriber reads the current holder at notification time, not constructor time. SessionMiddleware sets it. Repeated boot/retry and non-HTTP actor scope need real entrypoint coverage. Evidence: AccountContextInterface; SessionMiddleware; destination S4.
- **http / Authentication, entity/field/Protected access (gap):** Policies are declared and component read/outsider tests pass. Authority review and complete HTTP refusal stack remain separate verification work. Evidence: MessagingAccessPolicyTest; generic API route and controller; destination S2/S4.
- **http / Canonical wire contract and mechanical consumer checks (answered):** The package emits no dedicated wire protocol or frontend build; generic JSON:API is API-owned. Entity field declarations remain the package-owned inputs. Evidence: ContentEntityType api=true; generic API definitions.
- **http / CSRF and malformed request refusal (gap):** Owned by the shared HTTP/API composition, no independent messaging middleware. Complete messaging HTTP journey is not executed here. Evidence: Destination S4; no dedicated route in package.
- **persistence / Backend and storage authority (answered):** No package raw PDO. Default sql-blob entity schema, declared transition and unique key go through SqlSchemaHandler. sql-column and non-S1 databases are not claimed. Evidence: ContentEntityType; ThreadParticipant declarations; SqlSchemaHandler.
- **persistence / Schema upgrade and repeated application (answered):** Existing real SQLite tests backfill identity columns, merge legacy duplicate roles/read positions and reject later duplicate writes; transition is applied before declared unique keys. Evidence: ThreadParticipantSchemaTest; SqlSchemaHandler.
- **persistence / Transaction, durable outcome, failure (finding):** Creator membership remains outside thread commit and failures are swallowed. Evidence: Source fixture
- **persistence / Concurrency, retries and recovery (finding):** Composite database key fences duplicate pairs. Creation idempotency and crash recovery across thread/owner are not a settled package contract. Evidence: Schema tests; #2753
- **dependencies / Import layers and temporal coupling (answered):** Scoped diagnostic Deptrac classifies all messaging source responsibilities and dependencies: 139 allowed edges, 0 violations, 0 uncovered. Allowed, forbidden and uncovered seeded controls return the expected JSON counters and exit statuses. This scratch model is not a new committed architecture authority; coordinate adoption under #3075. Temporal bootstrap coupling remains in D1/S1. Evidence: Pinned Deptrac 4.7.2; diagnostic JSON reports and controls in audit output area.
- **dependencies / Adapter truthfulness (finding):** Provider-to-subscriber-to-real-SQL positive and failure effects observed with synthetic service bus. Full canonical kernel and installed-profile controls remain absent. Evidence: Source fixture; direct-registration tests
- **infrastructure / Custom mechanisms and Symfony fit (answered):** The only generic event mechanism already uses Symfony subscriber/dispatcher contracts. WeakMap pairing and participant merging encode entity/domain lifecycle, not an asynchronous message bus. Symfony Messenger does not supply conversation membership or an entity cross-write transaction. Retain current component reuse; decide bootstrap transaction authority in D1. Evidence: symfony/event-dispatcher v7.4.9 and messenger v7.4.12 source; https://symfony.com/doc/7.4/event_dispatcher.html; https://symfony.com/doc/7.4/messenger.html.
- **quality / Cohesion, alternate authorities, dead seams (answered):** Small package, 825 PHP source lines. Attributes are schema authority; boot owns only event registration. No unused public surface is proposed for removal, and downstream reach is not fully established. Evidence: Full source roster and caller searches.
- **tests / Unit, integration and refusal coverage (finding):** 25 tests / 56 assertions pass, with SQLite happy paths and stubbed membership policy. Missing canonical boot, failure and installation discriminators. Evidence: Package PHPUnit
- **distribution / No-dev install and optional closure (gap):** Root runtime and core/cms/full omit messaging. Split opt-in must be installed explicitly; no no-dev boot evidence is borrowed from the default closure. Evidence: Root Composer; metapackages; destination S4.
- **distribution / Exported runtime bytes and resources (answered):** 9 production files under package, no frontend, generated artifact or package-local resources. Split matrix includes messaging; artifact byte comparison and independent installed boot remain a qualification gap. Evidence: Git inventory and split.yml.
- **documentation / Composition, errors, upgrades and public contract (finding):** README lacks install/composition/failure/upgrade examples and blurs present primitives with future capability rationale; the historical spec assigns schema backfill to the provider although declarations and SqlSchemaHandler now own it. Evidence: README and spec
- **generation / Generation, build and introspection/CLI (does not apply):** Package owns no generator, command, CLI parser, build output or dedicated introspection service. Generic discovery and JSON:API are consuming authorities. Evidence: Production roster.

Symfony reuse: the locked EventDispatcher 7.4.9 already supplies the generic dispatcher/subscriber mechanism. Messenger 7.4.12 does not supply conversation membership or entity cross-write atomicity. Domain policy remains in messaging. Official component context was checked: [EventDispatcher](https://symfony.com/doc/7.4/event_dispatcher.html), [Messenger](https://symfony.com/doc/7.4/messenger.html). No replacement implementation is authorized.

## Decisions and uncertainties

| ID | Decision or uncertainty | Findings | What settles it | Owner |
| --- | --- | --- | --- | --- |
| D1 | Ratify authoritative creation operation and transaction boundary, actorless creation behavior, retry identity and explicit memory-test support. | MSG-CREATE-001 | Maintainer-approved creation success/failure matrix consumed by S1. | Framework maintainer with messaging and entity-storage ownership. |
| D2 | Ratify membership authority, sender attribution, editing/deletion, last-owner handling, parent lifecycle, read state and retention; keep product consent/moderation outside package. | MSG-CONTRACT-001, MSG-SEC-001 | Generic transition matrix and synthetic consumer contract; privately triaged authority review informs safe choices. | Framework maintainer. |
| D3 | Select primitive-only and standalone split support plus kernel/HTTP/CLI/system profiles to qualify. | MSG-QUAL-001 | Explicit support matrix and qualification owner. | Framework maintainer. |
| D4 | Choose durable private custody or explicitly waive that method requirement for the alpha-only local audit. | MSG-SEC-001, MSG-SEC-002, MSG-SEC-003 | Settled by user instruction; local retention limitation disclosed. | Russell. |
| U1 | No real downstream messaging installation or production request exposure was established in this scoped local search. | MSG-QUAL-001 | Named read-only consumer commit/lock and composition-root evidence, or maintainer disposition as a synthetic library-only assessment. | Framework maintainer / qualification owner |

Open contract choices are named decisions rather than invented product constraints. No private severity is published. D4 is settled by verified durable private custody as of 2026-10-08; this supersedes the earlier alpha-only scratch waiver.

## Bounded repair plan

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| S1 | MSG-CREATE-001 | Exercise canonical provider boot with real SQLite success and insertion failure; assert both rows or neither, actor recognition, retries and unique owner. Decide memory support explicitly. | D1 |
| S2 | MSG-CONTRACT-001 | Settle D2 first. Document and test chosen membership, attribution, read-state, edit/delete and retention semantics with synthetic consumers; do not infer owner-only, sender-only or cascade requirements from field names. | D2, S1 |
| S3 | MSG-DOC-001, MSG-SURFACE-001 | Use generic synthetic consumers; document opt-in install, canonical provider/policy wiring, actual schema authority, supported storage, and errors. Distinguish present primitives from future capabilities and correct stale schema authority. Keep and independently autoload retained public adapter symbols; factory-path control must remain green. | S1, S2 |
| S4 | MSG-QUAL-001 | Boot an explicitly installed messaging split without dev dependencies and through the kernel; prove durable owner, member read/write and outsider refusal; run schema upgrade twice, duplicate rejection and failure injection. Record hosted owner for unavailable profiles. | D3, S1, S2 |
| S5 | MSG-SEC-002 | Privately specified integrity controls establish a valid authorized target before every durable mutation. | D2, S1 |
| S6 | MSG-SEC-003 | Privately specified positive/refusal controls preserve the authorized conversation boundary on mutation. | D2, S1 |

Reuse #2753 for S1. File other bounded work before implementation. S2 settles generic lifecycle contracts; S3 reconciles docs and retained public symbols; S4 qualifies selected composition forms. API intake stays with API. Private slices retain private acceptance evidence and reporting route. This plan does not implement a chat feature.

## Not reviewed and host limits

- Real external installed consumers, live services and full HTTP middleware journey.
- No implementation, package migration, chat feature or adjacent package convergence.
- Initial isolation scan cannot hash Composer Windows junctions. No agents ran under that failed scan. Scratch dependencies were mirrored as copies before the successful pre-review snapshot.
- The first post-review isolation verification found a zero-byte Deptrac cache from the orchestrator diagnostic. It was preserved and a new immutable baseline taken. Critic and bounded followup integrity verifications subsequently passed; no repository or production-source change.
- Hosted Linux owns POSIX gates and split-artifact qualification. No unavailable check is counted as a local pass.

## Evidence

| Command or run | Evidence boundary | Result |
| --- | --- | --- |
| php -d memory_limit=1G vendor/bin/phpunit packages/messaging/tests --no-coverage --do-not-cache-result | Existing source package assertions, not installed or kernel-profile qualification. | Exit 0; 25 tests / 56 assertions; 6.924 seconds. |
| php run/probe.php | Synthetic provider composition reaches terminal SQLite effects and memory testing behavior. | Exit 0; observed known failure boundary. |
| Two independent verification roles: focused source PHP probes and static production registration/principal tracing | Independent evidence/refutation for public and privately held findings; source composition only. | Both completed; lifetime contract severity reduced to low; normal-label API failure and public-symbol load mismatch added. Private controls retained outside public candidate. Independent followup dispositions for generic principal sources completed; source-level only. |
| Pinned Deptrac diagnostic analyse with JSON output and allowed/forbidden/uncovered controls | Observed dependency classification and nonvacuous mechanical detection, not runtime or distribution qualification. | Production 139 allowed, 0 violations/uncovered. Controls: allowed exit0/1 allowed; forbidden exit1/1 violation; uncovered exit1/1 uncovered. A zero-byte scratch cache incidental write was detected and recorded before a new immutable review baseline. |

Every run binds the frozen base and lock in the ledger. The public portable probe candidates cover creator atomicity and API content identity only; they remain in scratch and are not committed. Private probes are excluded from public candidates. Existing issue evidence retains its historical base/runner; it is not silently upgraded to fresh qualification.

## Scorecard

- Final artifact dimensions and elapsed evidence window are recorded in the ledger scorecard.
- 9 production files; 7 PHP source files; 825 source lines; 2 historical intake entries; 5 profiles.
- 5 package-owned public findings (1 medium, 4 low), 1 API-owned medium finding, 3 safe private rows. Attribution remains a contract-sensitive decision.
- 2 independent verification runs completed; independent critic completed with eight citation checks; bounded followup disposition in ledger. Subagent tokens unavailable. Lifecycle severity reduced; docs/optional-profile claims narrowed.
- 3 public probe entries and private role probes; 0 probes committed. 2 portable public candidates retained in the draft. No installation profile qualified.
- **Open:** 2 open decisions; 1 open uncertainties; 4 package-owned public findings, 1 API finding and private work without new filed issues. #2753 is reused.

## Landed S1 delta, 2026-10-08

MSG-CREATE-001 is resolved by fdfedcb3a5b2ef0d817f92adcbcd7e81abbf15e8 on main; #2753 is closed. D1 is settled by FW-MESSAGING-ATOMIC-CREATE-01. Canonical provider creation now commits the thread and actual acting creator's owner together, or neither; failures propagate, SQL connections must share the transaction, and memory compositions refuse at boot. Actorless trusted creation remains supported, with fresh objects required after failure. Existing uniqueness fences duplicate membership.

Independent immutable/delta reviews approved. Exact-head full hosted qualification37725122998 passed; focused73tests326assertions and subsequent revision/messaging46tests156assertions passed. Native full dead-code diagnostics had seven unchanged config/scheduler messages, while hosted dead-code passed. No release or deployment. Main feedback37726101081 is separately observed.

The base findings above describe the frozen audit. Only S1 is resolved; D2/D3 and other findings retain their dispositions. The assessment remains a local draft and package convergence is not claimed. The committed coverage index remains not assessed; it was not changed by this repair.
Main-feedback37726101081 completed successfully on the identical landed SHA.

## Maintainer alpha policy correction, 2026-10-08

The maintainer explicitly rejects retaining obsolete Framework code for backward compatibility during alpha. The earlier rationale for preserving the deprecated empty ThreadParticipantBootstrapSubscriber::onPostSave callback is superseded: record it as an obsolete removal candidate in the next authorized messaging repair, with current caller/test updates. Public visibility, @api and hypothetical consumers do not establish a retention obligation.

The structural followup identified an unused schema local and repeated JSON normalization. Boot/mutation refusal guards and active Protected interface adapters still need present-boundary justification; do not collapse different refusal semantics or call them redundant merely because they look similar. Review internal glue that reconciles conflicting producer/consumer shapes against one canonical contract. These structural questions belonged in the initial assessment; the prior assessed claim had incomplete structural evidence. Skills were corrected to require this evidence and enforce the alpha policy. Existing historical verdicts are retained without alteration.

## Structural assessment supplement, 2026-10-08

This supplements the frozen assessment rather than rerunning it. Source identity
is 9945603c10a75036307ae371140840b332013790; messaging matches landed S1.
Old runtime results retain their original base and runner. The preceding frozen
rows preserve audit history; the ledger and this supplement own current dispositions.

| Surface | Current disposition | Evidence and bounded action |
| --- | --- | --- |
| Empty onPostSave callback | MSG-STRUCT-001, low, repair S7 | Unregistered empty deprecated callback. Alpha compatibility does not justify retention. Remove it; preserve PRE_SAVE/persisted dispatch. |
| Unused needsColumns | MSG-STRUCT-002, low, repair S7 | Assigned but unread; remove redundant schema metadata calls. |
| Repeated JSON decode | MSG-STRUCT-002, low, repair S7 | Backfill and duplicate merge normalize the same stored blobs separately. Use one normalization rule; retain malformed/scalar/valid controls and persisted data integrity. |
| Protected policy adapters | Required current boundary; MSG-SURFACE-001, repair S7 | EntityAccessHandler consumes interface factories with immutable principal, structure and compiled subject. These are not obsolete contract glue. Choose intentional public/internal concrete scope; independently autoload retained public classes. No compatibility shim. |
| EntityBase bound subject accessor | Necessary but coupled current authority boundary | Reads compiled structural identity rather than mutable field projection. Shared entity/access ownership should review a canonical accessor if changing this seam; do not replace it with ordinary field get or invent a new wrapper. |
| Boot/PRE_SAVE/transaction refusals | Retain | Boot rejects unsupported repository profile; PRE_SAVE covers direct subscriber composition; persisted event checks actual shared connection. Distinct failure boundaries. |
| Creator, read and activity metadata | D2 contract decision | Constructor/migration behavior exists; role-derived authority and live timestamp/read progression are not complete services. Do not infer field removal or add product policy. |
| Membership query repetition | S4 measurement gap | One shared lookup can execute repeatedly. No measured regression or mandatory cache is established. Measure real authorized listing/request composition before repair. |
| Missing provider service early return | MSG-QUAL-001 / D3 gap | Optional resolution can omit subscriber registration; missing-service profile needs explicit advertised support or refusal evidence. Existing synthetic composition is not full kernel proof. |

S7 is the next independent structural repair: remove obsolete callback/state,
consolidate decoding and reconcile adapter surface intent with positive/refusal
controls. It depends on landed S1, not on inventing D2's messaging lifecycle.
S2 still requires the generic authority/lifecycle decision before behavior changes.
S3 documents actual contracts; S4 owns installation and composition qualification.
API identity handling remains API-owned. No chat feature is introduced.

D2 remains a bounded maintainer decision covering membership grants/revocation,
actor-bound sender attribution, editing/deletion, parent lifetime, last-owner
handling and read/retention semantics. Consent and moderation stay with consumers.
D3 remains a support-profile decision: S1 SQLite opt-in kernel composition is
intended, while standalone split, HTTP and actorless entrypoints need explicit
qualification. Missing evidence is a gap, not a fabricated runtime failure.

No new runtime reproduction, deployment inspection or full audit is claimed.
The original private-evidence custody waiver and exposure limitations remain.
Independent verification and refutation completed: callback/state removal supported; JSON normalization consolidation must preserve actual stored input behavior. Final immutable-record review approved the structural supplement; isolation verification passed.

Current disposition: MSG-CREATE-001 resolved; D1 and the alpha custody-method
waiver settled. D2 and D3 remain named maintainer decisions. MSG-STRUCT-001 and
MSG-STRUCT-002 are low source-reviewed findings assigned to S7. MSG-SURFACE-001
joins S7; its prior compatibility-only acceptance is superseded. Issue #3188
is existing intake for documentation/public adapters and needs this bounded
structural acceptance incorporated before remediation. No package convergence
or newly executed installation qualification is claimed.

## Durable assessment candidate

This candidate records the assessment under #3118, including verified structural
findings and landed S1 evidence. Historical local-draft statements above describe
their original evidence checkpoints. The candidate index is assessed, remediation
planned under #3188; public disclosure contains no private reproduction details.

## Bounded repair candidate, FW-MESSAGING-PUBLIC-CONTRACT-01

S7 and S3 are implemented in this candidate, not yet landed. The deprecated
callback and unused state are removed, stored JSON normalization is shared,
public Protected adapters have canonical PSR-4 files, and README/spec describe
current generic capability. D2/D3, API intake and private findings remain open.

New production roster entries:

| File | Role | Classification | Evidence | Findings |
| --- | --- | --- | --- | --- |
| `packages/messaging/src/MessagingProtectedEntityReadPolicy.php` | Immutable-principal entity-read adapter | owned and coherent | reviewed; fresh-process source test | MSG-SURFACE-001 |
| `packages/messaging/src/MessagingProtectedFieldReadPolicy.php` | Immutable-principal field-read adapter | owned and coherent | reviewed; fresh-process source test | MSG-SURFACE-001 |

The original nine-file roster remains frozen-base evidence. Existing adapter
responsibilities were moved, not expanded. Source messaging tests: 34 tests /
100 assertions; no installed profile is qualified by that run.

## Landed structural/public-contract repair, 2026-10-08

PR #3189 landed at `59bf986d7152805a6d156ab54cb30f2cccbad89c`, the identical
head qualified by full hosted CI37817226478 (59 successful jobs, no failures).
Changelog and surface-parity workflows also passed. The earlier hosted lint
formatting failure was corrected before this final exact-head qualification.
Independent implementation, companion-test and formatting reviews approved;
169 focused tests / 1,170 assertions cover unchanged runtime/test inputs.
Native preflight passed with 42 executed gates and one governed equivalent-input
reuse; the main push reused 43 exact-identity gate results.

MSG-DOC-001, MSG-SURFACE-001, MSG-STRUCT-001 and MSG-STRUCT-002 are resolved.
The empty legacy callback and unused local are removed; stored-blob decoding is
shared; required Protected adapters independently autoload; current generic
documentation is reconciled. This supersedes the pre-landing candidate statements
above without upgrading historical probes to fresh installed-profile evidence.

The index remains assessed with remediation planned. D2 authority/lifecycle,
D3 installation profiles, MSG-CONTRACT-001, MSG-QUAL-001, API-owned intake and
private findings retain their dispositions. No release or deployment occurred.


## 2026-10-08 shared social design and custody checkpoint

The maintainer requested consumer-neutral shared social specifications before
further messaging implementation. Historical app code is discovery input, not accepted requirements or current
consumer qualification. [Shared social capabilities](../../specs/social-capabilities.md)
is a draft cross-package contract, with normal kernel applications as the first
proposed composition. It does not yet close D2/D3 or their qualification gaps.

The maintainer accepted familiar chat behavior as the direction; exact consent,
blocking, membership, history and retention transitions must be designed together.
Existing confirmed findings and landed slices retain their dispositions. No
runtime repair was attempted under an assumed complete social contract.

D4 now records durable private custody: the original brief and independent
verification/refutation evidence were copied, SHA-256 checked and restricted
ACLs verified. Russell Jones holds the private receipt and originals remain.
Historical waiver statements above describe earlier checkpoints only. Private
advisory publication and deployed exposure are not claimed.
