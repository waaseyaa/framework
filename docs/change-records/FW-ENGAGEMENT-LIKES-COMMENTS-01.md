# FW-ENGAGEMENT-LIKES-COMMENTS-01

**Status:** Implementation in progress, 13 November 2026. Persistence foundation and likes/comments service with migrations implemented locally. Service, HTTP adapters and exact installed synthetic-consumer qualification are recorded below; published adoption remains pending.
**Scope:** Consumer-neutral Framework capability for post likes, comments, and one-level replies
**Issue ownership:** Framework #3246 owns the supported likes/comments/replies capability under #3197. Framework #2756 remains owner of target integrity and lifecycle across reaction, comment, and follow. This record can qualify an explicitly bounded portion of #2756 only after that acceptance is recorded; it does not transfer or close the wider follow scope.

## Decision summary

The Framework will provide supported, policy-aware engagement operations and queries over immutable post targets. The consuming application supplies current account eligibility, blocking, moderation, and visibility policy through an explicit integration contract. The Framework must refuse an operation when a required policy decision is unavailable.

Russell accepted the initial reply/removal/revision defaults and requested settlement of the remaining implementation decisions. Revision 2 of the [owning spec](../specs/engagement-likes-comments.md) now fixes text normalization and bounds, editing, quotas/storage, ordering/pages/cursors, counts, block-hidden parents, service/HTTP boundaries, mandatory repository enforcement, uniqueness/migration, retry custody, retention and maintained-component fit. Those choices define the implementation target. Runtime acceptance remains pending.

This is an upstream, consumer-neutral record. Examples and qualification fixtures use synthetic actors and content. Product attribution, application identity, adult-content rules, interface layout, concrete moderation policy, and production data are outside this change.

## Supported capability boundary

The intended public behavior is:

- Set a post's liked state to true or false, returning authoritative viewer state and a policy-permitted count.
- Read engagement summaries for a bounded set of visible feed posts without a query per card.
- Create a text comment with durable actor-scoped operation identity.
- Create a reply anchored to its immutable root post and a top-level comment on that same post.
- Edit an owned comment using its expected aggregate version, returning the new revision and edit time.
- Remove an owned comment or remove it through explicitly authorized moderation, preserving the distinction in lifecycle/audit semantics.
- List top-level comments and page replies using deterministic cursors and visibility-aware totals. Discussion totals include permitted comments and replies, excluding placeholders.

Actor identity is derived from authenticated server context. Target, author, and reply-parent references are immutable after creation. Current target, account, and operation eligibility are checked inside the canonical write boundary. HTTP adapters additionally enforce their session, CSRF, and validation contracts; transport checks alone are insufficient. Generic entity APIs, service/repository entry points, and relevant jobs or tools must not bypass the same write authority.

An inaccessible root hides its whole discussion and associated reactions from ordinary reads, pages, and counts. Restoring the root does not silently restore comments independently moderated or removed. Replaying an earlier operation cannot disclose a result after visibility is revoked.

## Work packages and dependencies

### WP1: Behavior, interfaces, and boundaries settled

**Status:** Revision-2 contract authored. Independent agent review and focused document consistency checks apply to these settled decisions; runtime implementation has not begun.

**Deliver:** Operation and transition tables; permission authority for every operation; supported query and mutation boundary; refusal/error outcomes; component fit and installation impact; the settled defaults and explicit runtime acceptance obligations. The spec/change record exist and are linked from the social contract and roadmap.

**Acceptance:** Every operation and negative case has a named authority and defined result. Required policy cannot default to allow. Public API, HTTP, generic entity, and non-HTTP entry points are mapped to one canonical enforcement boundary. The owning spec supplies each previously open choice; implementation must demonstrate those decisions through focused tests.

**Focused intended commands:** Inspect the relevant interfaces and callers with `rg`; inspect the applicable package metadata and supported-version constraints; run only the narrow documentation, static, or API checks selected after the repository's delivery policy and actual commands are confirmed. Record exact commands and results when run.

**Review:** Independent review applies to permission/persistence and significant shared-architecture decisions before integration, following repository risk policy. Review may cover a coherent batch of work packages. The integrator records exact candidate qualification under the existing repository workflow.

### WP2: Establish target integrity for this slice

**Depends on:** WP1 accepted canonical write/read policy contract. Coordinate with #2756; do not imply its wider follow scope is complete.

**Deliver:** Canonical target resolution and authorization; immutable target references; lifecycle/read behavior; existing-row inventory and an explicit remediation or refusal strategy for unresolved references and duplicate reactions; enforcement for generic HTTP and trusted non-HTTP writes; support for both `sql-blob` and `sql-column` layouts.

**Acceptance:** Missing, hidden, and removed targets refuse unauthorized writes and are hidden from ordinary reads, pages, and counts. Actor spoofing and target reassignment fail. No generic HTTP, service/repository, or relevant job/tool path bypasses canonical authority. Both layouts pass fresh and upgraded schema qualification, including mandatory invariant coverage and non-revisionable aggregate-token initialization/backfill. Any bounded #2756 acceptance is named, evidenced, and reviewed while #2756 retains wider ownership.

**Focused intended commands:** The exact schema/migration checks and narrow target-integrity tests for each supported storage layout, plus focused entry-point tests discovered during WP1. Commands are selected from repository policy and actual test names; no runtime qualification is claimed by this record.


### WP3: Deliver likes with coherent reads

**Depends on:** WP1 accepted policy/query contract and WP2 accepted canonical target integrity.

**Deliver:** Set liked state; viewer state and permitted count; bounded multi-post summaries; durable uniqueness and transaction-safe conflict handling; cache behavior consistent with current visibility policy.

**Acceptance:** Concurrent likes create one logical reaction. Repeated unlike succeeds. Actor identity is server-derived. Current permission is rechecked before returning a retry/conflict result. Overlapping like/unlike ordering is defined, and clients can reconcile authoritative state after an ambiguous response. Direct reads, summaries, counts, and warmed caches agree after target or policy changes.

**Focused intended commands:** Exact like-state, uniqueness/concurrency, authorization, summary-query, and affected cache tests; narrow schema checks if WP2 did not already cover the relevant migration. Use actual discovered commands and record them with results.


### WP4: Deliver comments, edits, removals, and replies

**Depends on:** WP1 accepted lifecycle and retry contracts and WP2 accepted canonical target integrity. WP3 may proceed independently; WP4 does not depend on WP3 unless shared code introduced by implementation makes that dependency explicit and reviewed.

**Deliver:** Text comment creation and durable retry identity; revision-checked owned edits; author and authorized moderation removal; one-level same-post replies; deterministic comment and reply pages; visibility-aware totals and lifecycle behavior.

**Acceptance:** Identical retries return one logical comment; conflicting payloads for the same operation identity refuse without duplicate creation. Cross-post replies and excessive depth refuse. A stale edit cannot restore removed content; edit/edit and edit/remove outcomes follow the revision and removal-wins contract. Removed-parent placeholders are neutral and conceal body and author only where policy permits surviving replies to remain visible. Pages and counts remain consistent under filtering, ties, empty pages, moderation, and parent changes.

**Focused intended commands:** Exact create/retry, edit revision, removal race, same-post reply, pagination/cursor, policy visibility, and affected schema tests discovered from the implementation. Use actual repository test commands and record results.


### WP5: Qualify integrated queries and installed-consumer use

**Depends on:** WP2, WP3, and WP4 completed for the capabilities claimed. A partial release must identify precisely which coherent write/query capability is included.

**Deliver:** Integration evidence for visibility-aware reads, pages, counts, cache invalidation, opt-in package boot, and exact installed candidate use. Application policy adapter responsibilities and refusal behavior are documented without embedding consumer policy upstream.

**Acceptance:** Direct reads, pages, counts, and warmed caches agree after block, moderation, and parent changes. The exact candidate passes real consumer journeys in normal and no-development composition. Candidate exports contain copied package bytes, one consistent affected dependency closure, locked provenance, and a fresh no-development boot. Installed-consumer evidence must exercise copied package bytes. Later published adoption requires compatible released identities in the consumer lock and repeated affected journeys.

**Focused intended commands:** Exact candidate export/lock verification, fresh no-development boot, focused consumer journeys, and affected package checks. The repository delivery policy determines mandatory checks; record exact invocations, candidate identities, and outputs. Do not infer release or production qualification from this draft.


## Review policy

Apply the repository risk policy to the integrated candidate. Permission, persistence and significant shared-architecture changes require an independent reviewer who did not author them. Record findings and disposition before integration. Use the existing evidence format and integration ownership, with no additional approval or qualification role introduced by this record.

## Qualification matrix

Use synthetic actors and content. Cover signed-in author, stranger, anonymous, suspended, and blocked states; visible, missing, hidden, and removed targets; actor spoofing and target reassignment; simultaneous likes; repeated and conflicting create submissions; cross-post replies and excessive nesting; edit/edit and edit/remove races; partial-write rollback; cursor ties, filtered pages, and empty pages; warmed caches after policy changes; fresh and upgraded schema in both storage layouts; and actual generic HTTP, service/repository, and relevant job/tool entry points.

Record expected outcomes and exact candidate, commands, results and relevant layout/entry-point coverage in the existing evidence format. Permission and persistence changes require independent review. No scenario is qualified merely by being listed here.

## Settled implementation decisions

The spec is the authority for exact values and interfaces. Revision 2 selects these defaults.

| Area | Decision |
| --- | --- |
| Text | Plain UTF-8, 1–2,000 code points and at most 8,000 bytes after newline/outer-whitespace normalization. |
| Edits | No time window while eligible, using the existing aggregate mutation version on non-revisionable comments, no-op handling and removal winning against stale writes. |
| Ordering and pages | Oldest first with stable ID tie-breaker; 20 default, 50 maximum; 200 candidate scan budget and explicit continuation. |
| Summaries/counts | Up to 50 roots; visible comments plus replies, excluding placeholders; exact current counts with an explicit first-slice query-cost tradeoff. |
| Cursors/cache | Purpose-bound encrypted cursor, 15 minutes, viewer/root/policy binding; fresh reads and private/no-store transport. |
| Block-hidden parent | Neutral placeholder for permitted surviving replies, no blocked identity/text, no new replies to invisible parents. |
| Quotas | Fixed-window actor budgets for create, edit and like operations; Symfony RateLimiter with short canonical-database admission transactions and single-host Symfony Flock locking before the engagement writer transaction. Removal remains available without a posting quota. |
| Integrity | Immutable resolved root UUID, mandatory configured-repository guard, service-only writes, generic Reaction/Comment CRUD cutover, both layouts. API owns HTTP routes through optional provider assembly. |
| Retry | Sealed actor/root/parent-bound submission key, 48 hours, receipt with hashes/result reference and no body snapshot. Expired keys never recreate an operation. |
| Retention | Immediate body/revision erasure on author/moderator removal, metadata minimization after 30 days, root-deletion purge within 24 hours, audit default 90 days under its owner. |
| Migration | Deterministic like deduplication, eligible comments backfilled as top-level, inaccessible invalid rows quarantined for at most 30 days before erasure. |
| Package fit | Existing provider/repository/access/transaction/keyring authorities, Symfony Validator and planned RateLimiter/Lock additions; no publishing dependency for Layer 2 retry behavior. |

These are selected implementation defaults under Russell's current request. Actor/account/moderator permissions still come from the consumer's required current-policy integration. The spec separates those decisions from evidence that the implementation works.

## Resource bounds and broaden triggers

The spec fixes page, scan, batch, depth, text and quota bounds. Focused implementation evidence must measure exact-count query plans and latency, enforce shared-host process limits, and exercise limiter/database lock failure. Run one multi-process database qualification at a time on the development host; use synthetic data and keep existing services available. Extra broad suites follow the local testing policy and affected boundaries.

Broaden review and qualification when implementation adds a new public operation or storage layout; changes account, block, moderation, visibility, or target lifecycle authority; exposes new generic entity/job/tool write paths; changes retry, retention, audit, or report-evidence semantics; adds a shared validator, limiter, cache, or idempotency mechanism; expands reply depth; or changes package dependencies, installation composition, or release identity. Such changes require the affected contract, independent review, and qualification matrix to be updated before acceptance.

## Release and scope

For application development, qualify an exact committed Framework/application pair through isolated installed-package exports with copied package bytes, one consistent affected dependency closure, locked provenance, and fresh no-development boot/journeys. Exercise installed package bytes separately from monorepo source tests. Published adoption requires compatible released identities in the real application lock and repeated affected journeys. Coordinated alpha timing and production authorization continue to govern publication and rollout.

This record defines the settled implementation target. It does not assert that implementation, tests, security review, installed-consumer qualification, publication, or rollout have occurred. It does not close #3246 or #2756.

## Initial document verification

Documentation candidate based on Framework `7f632a7c298793a786158cadceba1358ad711c59`. The spec now records settled defaults and runtime acceptance obligations. This change creates those two durable records and links the existing social contract and roadmap.

Run `bin/git diff --check` and a local relative-link check across the changed documents. Check accepted defaults against ENG-01 through ENG-11 and review the exact document bytes independently. Runtime tests are inapplicable to this documentation candidate. For implementation, the initial existing-package baseline is `php -d memory_limit=1G vendor/bin/phpunit packages/engagement/tests`; add focused real-storage/kernel cases for the affected operation and layouts. Final new test filenames and broader mandatory checks follow the implementation candidate and local testing policy.

Final documentation checks passed on 13 November 2026 using `bin/git diff --check` and a relative-link existence check across the four changed documents. Independent document review found two status/review-policy inconsistencies; the decision-summary attribution and consolidated risk-policy text above address both. Runtime qualification remains pending.

## Revision-2 review and verification

The main agent owns contract integration. Two lower-cost agents mechanically inventoried existing APIs/components and independently reviewed revision 2 without authoring it. Review findings and disposition are recorded below after review completes. Focused document/link checks apply. Runtime package, storage, multi-process and installed-consumer checks remain implementation work.

Final revision-2 independent review passed on 13 November 2026. Both lower-cost reviewers returned “Ready for implementation” after rechecking the corrected contract. The first reviewer covered authorization, integrity, retries, concurrency, retention and visibility. The second covered API ownership/layers, component dependencies, limiter storage/locking and cursor/keyring integration. Neither authored the spec.

Corrections specify the non-revisionable comment aggregate-version source, quota admission after an initially allowed request becomes revoked, the mandatory invariant SPI and exact repository method coverage, API-owned conditional routes, atomic limiter admission/storage ordering, and explicit `CURSOR_EXPIRED` HTTP 400.

Reviewed spec SHA-256 is `f400fdfea5ed848fdb8ddb2741863b7c5b3d03a1b1db2188b5889d636e01daf6`. Final whitespace and relative-link checks passed. These are document-readiness results. Runtime tests, dependency installation, schema/multi-process qualification and installed-consumer evidence remain pending.

## First implementation slice, 13 November 2026

Russell authorized implementation from the settled spec in chat. The first dependency is the mandatory write boundary. Work is isolated on `feat/engagement-likes-comments` from base `7f632a7c298793a786158cadceba1358ad711c59`; the earlier documentation checkout is preserved.

Implemented optional entity metadata through the existing attribute reader, a minimal invariant SPI/registry/context, lazy fail-closed kernel resolution, SQLite writer acquisition, and initial/final save/delete checks independent of optional validation. Ordinary and aggregate callbacks, immediate listeners and batch rollback are covered. Revision/translation/publication and unscoped maintenance mutations refuse for guarded types. `getStorage()` refuses before custom-storage cache/factory access. Existing unguarded types retain their normal path.

This slice supports non-revisionable untranslated entities on S1 SQLite in both blob and column layouts. The future engagement domain guard, operation-scoped service authority, target-policy adapter, uniqueness/migrations, likes/comments/replies service, limiter, crypto cursors/retry receipts, HTTP routes and installed-consumer acceptance remain pending. No engagement entity is opted in until that coherent domain service is available. WP2 as a whole is therefore still in progress.

Focused and broadened verification used PHP 8.5.4, Composer 2.9.5 and the candidate-local locked dependency install.

- `php -d memory_limit=1G vendor/bin/phpunit packages/entity-storage/tests --no-coverage` passed 1,031 tests and 3,398 assertions.
- Focused metadata/manager/factory/invariant tests passed 179 tests and 487 assertions before the strengthened publication-driver case, which subsequently passed within the final 1,031-test storage run. Counts overlap and must not be summed.
- Focused source PHPStan passed with no errors for the changed entity, storage mutation, repository and factory source.
- Public surface parity, package layers, Composer policy, generated SQLite/schema rosters and whitespace checks passed. Generated declarations/maps and test-occurrence rosters are included.

Two independent lower-cost agents reviewed the implementation without authoring it. Findings fixed before their final passing verdicts included misplaced repeated refusal checks, a publication flag path that could reach callbacks with an unusual supplied revision driver, and the supported custom-storage bypass. Regression coverage now supplies that non-null revision driver and verifies no callback, guard or revision-driver effects. A broader storage test exposed an undeclared database-interface call, replaced with canonical `DatabaseInterface::query()`. PHPStan also corrected the resolver return contract to match object-valued container resolution.

Reviewed package-file digest (SHA-256 of sorted changed package paths, NUL, file bytes, NUL) is `be1065d163694bde9fba633f66e12fa2a679d92760cee1b3594a38c571533ce2`. Final preflight and history outcomes are recorded below. These source/test verdicts do not establish installed-consumer, release or production readiness.

Additional verification passed 1,017 entity/kernel tests with 2,459 assertions. The final required-invariant tests passed 12 tests and 71 assertions, including a file-backed two-connection check proving the SQLite writer is held during both guard invocations and released after commit. The clone uses native `core.autocrlf=input`; this resolved the distribution-policy comparison failure caused by inherited CRLF checkout settings. Git history was fetched with historical blobs deferred so the history-based gates can evaluate real ancestry.

The default repository preflight passed all 43 executed gates, with zero failures, zero hosted-required and three not-applicable. Command used `php bin/check-pr-preflight --base=7f632a7c298793a786158cadceba1358ad711c59`; its report is `/tmp/framework-engagement-preflight-qualified.json`. The history fetch completed, and `git rev-parse --is-shallow-repository` returns false. The final focused guard run reported 12 tests and 73 assertions. Both reviewers' final verdicts cover the corrected core source and the two-connection test. The candidate is a local persistence-foundation checkpoint, with the remaining domain work listed above.


## Service and migrations implementation, 13 November 2026

Russell requested the next service/migration slice from checkpoint `f9fc6122b`. The main agent integrated the domain service, required policy contract, immutable result DTOs, repository invariant, protected projections, provider wiring and retention. Lower-cost agents implemented mechanical token/limiter and migration work and real-storage regression tests. Separate agents reviewed source they did not author; their test contributions are distinguished from source review.

The package now supplies desired-state likes, comment keys/create/replay, one-level replies, aggregate-version edits, author removal, authorized moderator removal, direct projections, batched summaries and sealed oldest-first pages. Normal actor writes use current canonical policy before admission and again under SQLite writer custody. Reaction/Comment generic CRUD is disabled. The mandatory repository guard freezes the authorized payload and checks identity and transitions before and after callbacks. Both SQL layouts use the existing canonical repositories and entity schema authority.

The auxiliary migration owns unique like identity, discussion projections, receipts, fixed-window quota state, visibility generation, resumable upgrade progress and deletion queues. `engagement:upgrade` synchronizes canonical entity columns and logs bounded body-free batch evidence. Existing non-like reactions are untouched. Unpublished legacy comments preserve their text/status in the non-serving `unpublished` lifecycle, without a removal expiry. Invalid reference rows are quarantined and duplicate likes retain the earliest row. Application traffic refuses incomplete upgrades.

Foundation keyring purposes seal installation/viewer/root/parent-bound cursors and submission keys. Receipt identity derives from authenticated claims, so equivalent wire envelopes cannot create duplicate operations. The new Symfony RateLimiter/Lock dependencies provide fixed windows and single-host nonblocking Flock admission. Scalar window state persists in short canonical transactions committed before the external lock is released. No PHP-object deserialization is used.

Removal blanks the canonical body immediately and records actor and author/moderator kind. Existing scheduler discovery supplies an hourly bounded retention entry; no system job is installed. Deletion queues erase root/account content and actor receipts, including unindexed actor quarantine rows. Metadata/quarantine expiry is 30 days; expired receipt/limiter rows are swept. Retained parents expose only neutral projections when permitted replies survive. Applications must run the scheduler to meet deletion timing.

Review repairs included non-like preservation, unpublished legacy preservation, quarantine row-ID evidence, malformed reaction denial, current comment publication checks and final access-handler composition. An intermittent test-only read-context failure was traced to garbage collection of a prior fixture resetting the process-global guard. Cleanup moved to deterministic PHPUnit teardown. This repair does not alter runtime authorization.

Verification uses native PHP 8.5.4 and the candidate-local Composer lock. The focused commands are `vendor/bin/phpunit --no-coverage packages/engagement/tests/Unit packages/engagement/tests/Integration`, source PHPStan for `packages/engagement/src`, `php bin/check-package-layers`, generated surface/SQLite/schema roster checks and `php bin/check-pr-preflight --base=f9fc6122b`. Final counts and independent review verdicts follow after retention and entry-point checks complete.

This is a local source checkpoint. HTTP routes, transport CSRF/session/cache contracts, installed no-development consumer journeys, release-cohort qualification and exact-count performance measurements remain acceptance work. No package was published, production deployed, issue closed or broader Follow lifecycle accepted.


Final package verification passed **75 tests and 454 assertions**, including both storage layouts, provider service/command resolution and command execution, real quota process contention, sealed-token tampering/expiry, retry-envelope aliases, one-level reply policy, aggregate-version conflict/no-op edits, cursor ordering/high-water/generation bounds, protected malformed-data denial, deterministic upgrades and retention. The final six retention tests passed 60 assertions. PHPStan reported no source errors. Tests exposed the legacy SQL-column false representations on original purged-row deletion; the maintenance invariant accepts only the known false scalars on already-blank authorless shells. Ordinary actor publication checks remain strict.

Euler's final independent source and test review returned ready for this service/migration scope after the corrected 75-test run. Dalton independently reviewed service/migration source and returned no remaining source blocker, then authored only the separate retention regression test file. Euler reviewed that later test and retention fixes independently. The earlier intermittent fixture failure and all substantive review findings are resolved in the final tests.

Final default preflight passed all **43 executed gates**, with zero failures, zero hosted-required and three not-applicable. Report: `/tmp/engagement-service-preflight-final.json`; evidence directory: `/tmp/engagement-service-preflight-final`. Public declarations/maps, schema-boundary classifications, SQLite construction roster, exact Symfony import rationales and the narrow missing-pcntl skip classification are included. The reviewed immutable patch before this evidence-only paragraph has SHA-256 `7630193cc7666dc1798453661022657cd056324968987223a9b2b73e8a7d07ea` at `/tmp/engagement-service-candidate.patch`.


## HTTP adapter implementation, 13 November 2026

Russell authorized the API adapter slice from service checkpoint `fcbb1d654`. Main integration owns strict request/response mapping, principal binding, sanitized errors, the immutable-root reply query and final qualification. Lower-cost workers own provider/manifest wiring, real-storage endpoint tests and response-privacy middleware with tests. A separate agent reviews source without authoring the candidate.

`waaseyaa/api` exposes the 11 settled routes under `/engagement/v1`. Target collections use `{type}/{id}`; individual comment and reply pages use UUID routes, with root resolution retained inside the same canonical read snapshot through `EngagementService::repliesForComment()`. The transport passes only current immutable decision principals and plain data into the service. It never reads entities or raw persistence. Exact JSON scalar types and allowed fields prevent author/status/identity spoofing. Known domain outcomes map to the settled HTTP codes; unknown exceptions/configuration failures are sanitized 503, and all adapter HEAD responses have empty bodies.

Every mutation explicitly opts into existing CSRF validation for JSON. Summaries POST remains a policy-checked read. The priority-120 API privacy middleware wraps the existing pipeline for matched engagement routes, including CSRF, rate-limit, body-limit and parsing refusals. Global failures before matching retain their existing kernel surface. API routing availability is copied at boot and inspected without resolving domain services. Both legacy and compiled request terminals share declarations and scalar action defaults; compiled closure dispatch derives its action from the canonical matched route.

Engagement is API `require-dev` only, with no new third-party dependencies. The intentional typed optional integration is recorded in the explicit package-layer exception with review triggers. Symfony HttpFoundation, existing JSON:API response objects, routing metadata, CSRF/session middleware and canonical domain operations supply the mechanics; no alternate authentication, CSRF, quota, retry or persistence implementation is introduced. Raw JSON object decoding preserves object-versus-array shape where the existing associative kernel parse cannot distinguish it.

Review and integration corrections include the exact UUID route paths, legacy availability propagation, compiled closure dispatch, private HEAD errors and removal of redundant typed checks. Focused and final verification results follow below. This slice does not claim installed no-development consumer journeys, publication, release, application adoption or production changes.

Final HTTP qualification passed **1,097 tests and 7,706 assertions** with `vendor/bin/phpunit --no-coverage packages/api/tests/Unit packages/api/tests/Integration packages/engagement/tests/Unit packages/engagement/tests/Integration`. This includes both SQLite layouts, API provider optional-package absence with a fresh autoloader, pure route inspection, legacy registration, actual compiled route matching/handler resolution/dispatch into real storage, key/create/replay/replies, strict input rejection, principal mismatch, cursor/revision/expiry outcomes, successful audit-attributed moderation, immediate erasure, CSRF precedence, privacy and HEAD responses. The compiled happy-path fixture passed one test with 76 assertions; those assertions are included in the combined count.

Scoped PHPStan passed for the API provider, controller, router, privacy middleware and changed engagement service. Package layers, added relative documentation links, generated surface maps, SQLite construction roster, Composer policy and whitespace checks passed. The default final preflight passed **43 executed gates**, with zero failures, zero hosted-required and three not-applicable. Report: `/tmp/engagement-http-preflight-final.json`; evidence directory: `/tmp/engagement-http-preflight-final`.

Carver independently reviewed authentication, status mapping and privacy; Confucius independently reviewed routing, compiled execution and optional-package boundaries. Both returned passing verdicts against immutable patch `/tmp/engagement-http-candidate.patch`, SHA-256 `5cf66c783678b53fc52904c46aa9832908496a83f973e95c9a3db92e22ed37dd`, based on `fcbb1d6545d18be4c69c1b273aca6f5b0625a535`. Neither authored the candidate. The artifact predates these evidence-only paragraphs. The historical pre-engagement route fixture remains unchanged; its test explicitly selects that earlier optional-domain cohort, while new tests qualify all 11 engagement routes independently.

The reviewed HTTP source slice is complete locally. Installed no-development consumer journeys, application adoption, exact-count performance measurements, release-cohort qualification and publication remain separate acceptance work. No remote branch, issue, release, production state or host scheduler was changed.

## Installed candidate qualification, 13 November 2026

Russell authorized installed-consumer qualification after HTTP checkpoint `b9e2dd345`. The fixture is a consumer-neutral synthetic application, not a FETDER adoption or production-data rehearsal. Main owns orchestration, byte provenance, contention/default-quota probes and integration; lower-cost workers own application fixture, journeys/performance and historical schema seeding. Independent agents review the resulting evidence and any runtime repairs.

The qualification recipe exports exact committed candidates, mirrors all solved Waaseyaa packages with Composer `symlink=false`, uses explicit cohort version labels only as solver metadata, verifies every installed package file against its export and repeats ordinary kernel/discovery/service/HTTP journeys in separate fresh no-development installations. No source namespace is added to the application autoloader and installed package files are never repaired in place. Published-cohort adoption remains separate.

Fixture construction exposed missing normal provider selection for the already-promised column layout. A narrow metadata factory override now lets the engagement provider select `engagement.storage_backend` before registration while preserving all attribute-derived fields, API exposure and required invariants. The backend override participates in factory cache identity. Both choices are install-time settings, not a conversion mechanism for existing data. Focused factory/provider regressions passed five tests and 24 assertions before installed execution.

### Completed installed qualification, 14 November 2026

Candidate **`b5e294ff63386e03bb27431ae5cffcd35927f213`** is candidate-qualified for the tracked synthetic engagement application. The fixture and application probes are exported from that same exact commit; the Framework archive SHA-256 is `04d3af9d132b04d8dcf4b78b1e159f3ffcd251e474069056abcd8f7997e2054e`. This is not FETDER adoption or a published-release claim. There is no pre-existing published application lock for this newly created synthetic consumer.

The complete run exited zero using `TMPDIR=/home/russell/.cache/waaseyaa-engagement-qualification bash tests/PackagedForm/check-engagement-consumer --evidence-dir=/tmp/engagement-installed-evidence-run7 --keep-work`. PHP was 8.5.4 and Composer 2.9.5. The runner used sequential profiles and at most two child processes within a contention phase. Final work stayed on disk-backed scratch after retained failed rehearsals exhausted the RAM-backed `/tmp`; failed task-owned copies were removed without touching project or production data.

Seven candidate profiles passed:

| Profile | Installed evidence |
| --- | --- |
| Normal, blob | Production kernel/discovery boot; 46 journey assertions; all 11 routes; byte checks before and after. |
| Fresh no-dev, blob | Same journeys from a separate vendor directory and database; PHPUnit absent; same solved lock. |
| Normal, column | Same journeys with the supported provider setting and mandatory invariant retained. |
| Fresh no-dev, column | Same column journeys, fresh production dependency composition and no development autoloads. |
| Historical blob upgrade | Real persisted data from `7f632a7c298793a786158cadceba1358ad711c59`; 10 upgrade assertions; day-one writes/replay and idempotent upgrade. |
| Historical column-schema upgrade | Explicit synthetic old canonical-manager/schema fixture; 10 upgrade assertions, preserved hidden text and non-like reaction, deterministic duplicate survivor. |
| API without engagement, no-dev | Actual installation without engagement; ordinary kernel boot, no engagement provider/class/routes and no PHPUnit. |

Each fresh profile installed 41 Waaseyaa members, all from the same export. Metapackages were checked as metadata-only; every installed code package matched its export recursively, with no source-root or vendor symlinks. Application fixture and probe files and overlay manifests were also byte-checked; unexpected application source/migration files fail verification. Fresh and upgraded candidate profiles share lock SHA-256 `3c1bb8f8ac6b6da6666a5d55a5d09c770221ab22096e5552b7d817e1756e4432`. The synthetic cohort label `0.1.0-alpha.308` is only Composer solver metadata; archive and package digests identify the candidate.

All four fresh profiles also passed two-process overlap for desired-state likes, same-key comment retries, edit/edit conflicts and edit/remove non-resurrection. Each passed the installed default fixed-window budget: 60 eligible like attempts admitted and the next returned 429 with Retry-After. High-volume synthetic data uses explicit named quota overrides; the default-budget probe has a separate installation identity and no overrides. Actual root repository deletion triggered the installed erasure listener and sweep, and normal scheduler discovery registered the retention entry.

Each performance fixture created 50 new roots and 1,000 comments through canonical repositories/services. Ten warm exact-count samples per profile returned all 1,000 permitted comments. Warm p95 ranged from **14.49 to 14.79 ms** in this VM. The saved reports include exact SQL and SQLite EXPLAIN QUERY PLAN output for summary projections and page positioning. This characterizes the bounded synthetic fixture; it is not a production load test or SLO acceptance.

Qualification found and repaired a real legacy duplicate deletion failure: an old active like had no resolved root UUID when the mandatory delete guard ran. The upgrader now resolves and canonically saves that identity, reloads its current mutation token, then deletes the duplicate within the same batch transaction. The guard was not relaxed. The old installed fixture is the regression discriminator; earlier synthetic quarantined rows did not expose it.

The upgrade rehearsal also caught stale Composer path-package bytes when old and new archives used the same solver label and unchanged package manifests. The recipe now removes only the disposable old vendor before a genuinely fresh candidate install and checks provenance before boot. A saved negative verifier run rejected the mixed origin. Column legacy rows additionally require the existing governed `field-access:upgrade-legacy-entity-data` command before readiness activation; it changed six empty-list `_data` values to objects without changing column content. No installed Framework files were patched.

Historical source archive SHA-256 is `7cb1e768e707d36b9f1a3001ffdf5f46a09b597a5719ea91df650d3d1aa9fbed`. The stock old provider lacked a column selection setting. The historical column case therefore uses a clearly labeled legacy-only test helper to reconstruct canonical old metadata and a separate canonical factory manager over the booted old kernel database, then synchronizes empty tables and writes through repositories. It proves a historical column schema upgrade, not that the old normal provider exposed configuration it did not have.

Portable evidence, solved overlays/locks, package inventories, query plans and all per-profile results are committed under [evidence/engagement-installed](evidence/engagement-installed/README.md). Raw bounded logs remain at `/tmp/engagement-installed-evidence-run7`; earlier failed rehearsal logs are retained separately and are not passing evidence. HTTP probes use actual route matching, handler resolution and ControllerDispatcher with seeded immutable principals plus the installed CSRF/privacy middleware. They do not claim a rendered-browser or full login-cookie journey.

Published cohort qualification, real application adoption and its policy/browser journeys, production-scale performance acceptance and deployment remain separate gates. The broader Follow lifecycle issue and production are unchanged. Final source checks, preflight and independent result-review verdicts follow below.

Final source validation passed **1,732 tests and 9,102 assertions** across entity unit, API unit/integration and engagement unit/integration suites. Scoped PHPStan passed for the changed entity factory, engagement provider and data upgrader. Default preflight passed **43 executed gates**, zero failures, zero hosted-required and three not-applicable; reports are `/tmp/engagement-installed-preflight.json` and `/tmp/engagement-installed-source-final.log`. Generated schema classifications and staged whitespace checks passed.

Epicurus independently reviewed the mandatory invariant, canonical mutation/token ordering and transactional duplicate repair, and returned **PASS** with no findings against candidate `b5e294ff63386e03bb27431ae5cffcd35927f213`. Archimedes independently reconciled all seven profiles, archive identity, source provenance and result claims, returning **PASS for the stated installed-consumer scope** against the same candidate. Both independently reran lightweight byte verification on all seven final candidate installations; neither authored the reviewed source or fixtures. Lower-cost workers performed mechanical fixture work; main integration corrected and qualified the complete candidate.

The installed matrix alone does not claim the full ENG-01 through ENG-11 contract: opposite like/unlike races, injected partial-write rollback, tied-timestamp cursor cases and warmed-cache invalidation require their separate source-level evidence or further installed probes. The explicit synthetic historical column schema and seeded-principal HTTP limits above remain applicable. The reviewers' earlier pending-status observations predate this completed record. This final evidence/documentation and generated-roster checkpoint does not change the runtime package or consumer fixture bytes tested at `b5e294f`.

## Published-package qualification preparation, 14 November 2026

Russell requested published-package qualification, with cheaper agents for mechanical work and agents reviewing the result. This slice owns registry-only installation/provenance tooling and evidence. Runtime package bytes and consumer behavior are unchanged. Focused verification covers Bash/Python syntax, an actual published alpha.308 no-dev consumer and refusal discriminators; the existing installed-candidate journey evidence remains valid for its recorded bytes. Independent reviewers own source/provenance and publication-state/result claims. A future new-cohort run must execute the complete matrix after publication; this preparation does not waive any release or adoption gate.

Live canonical checks found remote `main` at `7f632a7c298793a786158cadceba1358ad711c59` and latest public package cohort `v0.1.0-alpha.308`. Public engagement source `7cbb9f7ecf624d5b20671e918026e289ed653aee` lacks `EngagementService.php` and `Migration/EngagementDataUpgrader.php`; public API source `b00c09035ed62eb786008ac7dfe60ebe44a0d5b0` lacks `Controller/EngagementController.php`. The published archive downloads corroborate the registry references. Therefore no currently published package set can qualify the new engagement implementation. This is a publication prerequisite, not an installed-candidate regression.

The existing `check-engagement-consumer` runner gains an explicit `--published-tag` mode instead of duplicating its journeys. The harness candidate and immutable public package source are recorded separately. Published mode verifies the tag against canonical origin, uses an isolated Composer home without global repository configuration, pins direct Framework requirements exactly, and permits no application repository overrides. Its helper verifies installed dev/no-dev lock closure, uniform cohort, official split origins, application bytes and full package bytes against the exact exported release. The same seven profiles remain required, including both fresh no-dev layouts, actual published old-blob upgrade, explicit synthetic old-column schema and optional absence. All installed Framework members must belong to the intended cohort; caret constraints cannot silently admit a mixed future cohort.

Publication requires a separately authorized normal landing of the reviewed candidate and the canonical `release-cut.yml` workflow. That workflow must prove the exact release head through its hosted gates, create the next alpha, split it and verify Packagist before the registry-only matrix can establish published-package qualification. No manual tag, package override, vendor patch, public release, remote branch update, FETDER adoption, deployment or production change is performed in this preparation.

Focused preparation checks passed for normal and optional-absence manifests, exact VERSION refusal, corrupt-manifest refusal and wrong-cohort refusal. A genuine published alpha.308 no-dev install completed with 41 Framework members, 15 application files verified and PHPUnit absent. Full package-tree comparison against public release source `baead2150a78883b82536282d1181cf5b7a92a10` passed. This supporting baseline reused the native Composer cache and does not claim anonymous fresh retrieval. The first parallel download attempt was stopped after stalling; a bounded sequential-HTTP retry completed without changing installed code.

Exact harness `22a48d0d340c0f397c18967471358b7f9331a5c0` against the real alpha.308 tag passed package provenance and `install:init`, then exited 1 at field-access preflight because `Waaseyaa\Engagement\EngagementTokens` is absent. This is the expected obsolete-cohort refusal, not a passing feature journey. A first rehearsal merely lacked the local immutable tag; fetching the public tag corrected that local prerequisite. Neither failed rehearsal is passing evidence.

Poincare independently reviewed runner/helper source and provenance against `22a48d0d3`. Review found inherited Composer authentication/cache configuration; `66c2aeae9261de4dc090d77280e3d5f527d3fafd` isolates the cache under the temporary Composer home and unsets authentication overrides. Poincare returned **PASS** on that repair. Beauvoir independently reran full baseline byte verification and confirmed the publication blocker. An earlier review finding about an in-progress helper was withdrawn after checking the actual committed helper and rerunning it successfully. Neither reviewer authored the candidate. The lower-cost worker authored only the mechanical published helper; main integrated and verified it.

Final default preflight passed all **43 applicable gates** (42 executed, one equivalent-input reuse), with zero failures, zero hosted-required and three not-applicable. Report: `/tmp/engagement-published-preflight.json`. The original fragment-format error was repaired before this pass. Portable baseline evidence is under [evidence/engagement-published](evidence/engagement-published/README.md). New-cohort published-package qualification remains pending publication; the candidate-qualified state is unchanged.

The final exact `66c2aeae9` published-mode run used its own initially empty Composer cache/home with authentication overrides cleared. It successfully downloaded and installed the normal alpha.308 consumer, verified all 41 Framework members and 15 application files against the real public release, and completed `install:init`. It then exited 1 at field-access preflight because the public cohort lacks `EngagementTokens`. This clean-cache negative result is saved under `isolated-alpha308-*` in the portable readiness evidence. Later profiles were correctly not attempted after refusal. Raw logs: `/tmp/engagement-published-alpha308-isolated`; outer log: `/tmp/engagement-published-readiness/alpha308-isolated-runner.log`. New feature publication and the seven-profile published qualification remain pending.

Both independent reviewers checked the final isolated-run artifacts and confirmed the expected alpha.308 refusal with no remaining source findings. They explicitly distinguished the first normal/dev profile from the separately completed no-dev baseline. This evidence-only checkpoint changes neither the runtime packages nor the reviewed runner/helper bytes at `66c2aeae9`. Authorization to land and release is the remaining prerequisite; no remote mutation was made.

## Authorized landing and next-alpha release, 14 November 2026

Russell explicitly authorized landing reviewed `8638c5172`, the governed next-alpha release and complete published-package qualification, including cheaper workers and independent agents. Canonical `main` fast-forwarded from `7f632a7c2` to `8638c51725e43957900c4fd3ea127d4296eef525`. Main-feedback run `43609046655` found two CS trailing commas and 18 dead-code diagnostics on intentionally consumer-facing projections, domain refusals, root-bound service paging, read-only upgrade inspection and the mandatory guard context/vocabulary. Release-cut `43609092507` for alpha.309 correctly refused Gate 1 before tagging. Main owns this repair; no tag or package was created.

The repair adds precise API annotations at those genuine consumer boundaries and the two missing trailing commas. The operation vocabulary remains unchanged, including rejected guarded operations; existing contract tests discriminate its complete values. A provisional attempt to remove unused enum cases failed that test and was discarded before integration. No dead-code baseline, gate or invariant is weakened. Focused storage-mutation, engagement and API suites passed **1,116 tests and 7,801 assertions**. Exact source review, renewed dead-code/style checks and the next canonical release attempt follow below.

Independent review rejected an API annotation on internal, test-only upgrade readiness inspection. The repair instead makes that read-only check a canonical internal static operation used by the production retention readiness gate and its upgrade tests, removing the retention gate's duplicate completion query. No new public upgrade API is declared. Schema incompleteness still refuses readiness before retention writes.
