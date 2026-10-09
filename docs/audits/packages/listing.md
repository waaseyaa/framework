# `waaseyaa/listing` convergence audit

- **Milestone:** assessed. Repair ready: no. Converged: not claimed.
- **Audit state:** assessed. **Remediation state:** resolved.
- **Base:** `f2b2da6dd58092d611ad52f5a2bf0fb95712ffbc`, audited `2026-10-08`.
- **Dependency identity:** `composer.lock` SHA-256 `f4b7a1f592d50e45f33a503391aedc9cc7a8f19dc478ba09b16c0a78bd75d036`; PHP `8.5.5`; native Windows.
- **Evidence freshness:** current at the base; original checkout clean. Scratch source clone binds the same source and lock.
- **Owner:** `waaseyaa/framework#3195` (program #3118). Retained audit history at the original base; see FW-LISTING-CONVERGENCE-01 for the repair candidate.
- **Profiles applied:** domain contracts, kernel/runtime, HTTP/query input and distribution. Persistence/execution and generation/build do not apply: listing owns reads and cache eviction, no writes/schema/jobs/generation.
- **Structured ledger:** `docs/audits/packages/listing.ledger.json`: 11 findings, 6 refuted leads, 122 checklist answers, 0 handoffs, 2 decisions (0 open), 1 uncertainties (1 open), 3 probe entries, 12 evidence runs.

## Summary

The package owns declarative access-filtered read listings with deterministic paging and optional tagged caching. All 27 production files were reviewed. The source tests pass: 399 tests and 859 assertions across the package, related integrations and generator caller checks.
The passing suite misses lost equality conjunctions, case-sensitive text matching contrary to the spec, stale cached totals after ordinary repository deletion with a tagged cache bound, and rejection of successfully parsed typed date overrides. Production fast-path and cached reconstruction follow-ups already have issues. Documentation and public-surface metadata also drift.
A security-sensitive authorization-lifecycle finding is handled privately. Independent verification is complete. Final critic review passed. Durable private custody was verified on 2026-10-08, settling the assessment blocker. Source evidence does not qualify installed packages.

## Charter

- **Owns:** Declarative single-entity-type read listings: definitions, discovery, validation, exposed filters, access-filtered deterministic pagination, optional tagged cache and lifecycle eviction.
- **Does not own:** Entity persistence/schema, policy authoring, cache backend implementation, routes/controllers/templates, cross-entity joins, cursor paging or saved listing UI.
- **Consumers:** Framework closure installs provider; cli directly requires listing and PublishedContentRecipe emits a nonempty app provider/controller. Runtime applications consume registry/parser/resolver/result. Reference source consumers include Phase14/29 and bundle/anonymous gate integration. External installed consumers not used as qualification.
- **Dependencies:** Required foundation (provider/log/request), entity (types/fields), entity-storage (repository/events), field (metadata validation), access (gate), cache (contexts/tag contract). Direct typed-data requirement lacks source calls and needs disposition. Symfony event interfaces are reached transitively through foundation. Dev database-legacy and phpunit support tests. Cache backend itself optional.
- **Public surface:** 23 declaration entries for 25 source symbols; factories, immutable definitions/result, parser/value input, registry/resolver, provider capability and refusal exceptions. Two exports unclassified, stability comments drift. No transported JSON response owned here; enum backing strings and hash bytes form persisted/cache identity.
- **Installation profiles:** Primitive-only and standalone split direct use; kernel composition; framework closure; generated application recipe. Metapackage composition is conditional via CLI in expanded graph, not a core-only capability. Supported closures need named qualified evidence; none inferred from root tests.
- **Evidence it works:** 331 package tests/582 assertions and 62 Phase14/29 tests/217 assertions pass at the exact source/lock on native Windows. Synthetic provider composition reproduces behavior; no installed or hosted qualification claimed.

## Roster

Every shipped file outside tests is in the structured roster. The source contains 25 declarations in 25 files. No assets, README or changelog ship in this package.

| File | Role | Classification |
| --- | --- | --- |
| `composer.json` | Package/dependency/discovery/export identity | duplicated or drifting contract |
| `public-surface.php` | Public/internal symbol authority | duplicated or drifting contract |
| `src/EntityRepositoryRegistry.php` | Entity-type repository lookup | owned and coherent |
| `src/Exception/ListingCoercionException.php` | Strict parsing failure metadata | owned and coherent |
| `src/Exception/UnknownListingException.php` | Registry miss | owned and coherent |
| `src/Exception/UnsupportedListingException.php` | Invalid definition metadata | owned and coherent |
| `src/ExposedFilterCoercer.php` | Operator-aware URL value coercion | duplicated or drifting contract |
| `src/ExposedFilterParser.php` | Definition-bound permissive/strict parser | duplicated or drifting contract |
| `src/ExposedFilterValues.php` | Parsed input and hash identity | duplicated or drifting contract |
| `src/Filter.php` | Filter factories | owned and coherent |
| `src/FilterDefinition.php` | Operator value-shape invariant | duplicated or drifting contract |
| `src/HasListingsInterface.php` | Provider declaration capability | owned and coherent |
| `src/ListingCacheInvalidator.php` | Best-effort entity lifecycle eviction | duplicated or drifting contract |
| `src/ListingCacheKeyBuilder.php` | Definition/input/context cache identity | owned and coherent |
| `src/ListingCacheProjection.php` | Identifier-only cached payload | duplicated or drifting contract |
| `src/ListingDefinition.php` | Immutable declaration and effective contexts | duplicated or drifting contract |
| `src/ListingDefinitionRegistry.php` | Read-only id lookup | owned and coherent |
| `src/ListingDefinitionValidator.php` | Finalization-time entity/field/backend proxy checks | owned and coherent |
| `src/ListingDiscoverer.php` | Provider flattening and duplicate refusal | owned and coherent |
| `src/ListingResolver.php` | Query planning, filtering, access, pagination and cache | duplicated or drifting contract |
| `src/ListingResult.php` | Rows and pagination/cache metadata | owned and coherent |
| `src/Operator.php` | Stable operator vocabulary | owned and coherent |
| `src/Pagination.php` | Exact/unknown total metadata | owned and coherent |
| `src/ServiceProvider.php` | Kernel bus composition, listener registration and finalization | duplicated or drifting contract |
| `src/Sort.php` | Sort factories | owned and coherent |
| `src/SortDefinition.php` | Sort declaration | owned and coherent |
| `src/SortDirection.php` | Sort vocabulary | owned and coherent |

## Intake

Exact owner/co-owner searches across every committed ledger finding and handoff returned zero items. Older human-record cross-package tables were reviewed. Open listing issues #2996, #3005 and #3016 were revalidated; broader #3006/#2859 retain their owners. No private routed brief was supplied. No open listing PR was found.

## Package findings

| ID | Title | Severity | Level | Verification | Destination |
| --- | --- | --- | --- | --- | --- |
| `LST-QUERY-001` | Repeated equality filters lose AND semantics | medium | reproduced | A | S1 |
| `LST-QUERY-002` | Text operators contradict the case-insensitive contract | medium | reproduced | A | S1 |
| `LST-CACHE-001` | Repository deletion leaves cached listing metadata stale | medium | reproduced | A | #2996 |
| `LST-DISCOVERY-001` | Discovery depends on a private kernel property | info | reviewed | C | S2 |
| `LST-SURFACE-001` | Package surface declarations leave two exports unclassified | info | reviewed | C | S3 |
| `LST-CACHE-002` | Cached row reconstruction performs one repository read per row | low | reviewed | B | #3016 |
| `LST-STRUCTURE-001` | Internal duplication and obsolete branches need bounded consolidation | info | reviewed | C | S3 |
| `LST-FILTER-001` | Supported date coercion produces values the resolver rejects | medium | reproduced | A | S1 |
| `LST-SEC-001` | Cached authorization may outlive current authorization | withheld | reproduced | A | framework advisory; owner waaseyaa/listing |

### `LST-QUERY-001`: Repeated equality filters lose AND semantics

- **Observed:** ListingResolver.php:412 writes each native EQ to one field-keyed criteria map; :420 overwrites bundle criteria. Conflicting weight=10 AND weight=20 yields id2. A later bundle scope similarly overwrites an explicit bundle-key criterion.
- **Expected:** FR-002 AND-composes every filter; the implicit bundle scope narrows the same conjunction.
- **Consequence:** Accepted definitions produce wrong rows and totals. This is a logical filter error; it does not independently establish unauthorized disclosure.
- **Refutation:** AND is explicit FR-002; native criteria overwriting is not an intentional override rule. Exposed values can replace one declaration, but that does not authorize a second declaration to erase its peer. Identical repeated equality is harmless; incompatible repeated equality and explicit bundle conflicts have no constructor refusal.
- **Destination:** S1.
- **Acceptance:** Conflicting repeated EQ returns no rows, identical repeated EQ retains matches, filter order cannot change membership, and explicit bundle criteria cannot broaden the conjunction. Cover in-memory, SQLite and bundle-deferred paths.

### `LST-QUERY-002`: Text operators contradict the case-insensitive contract

- **Observed:** ListingResolver.php:701-702 uses str_starts_with and str_contains without case folding. Alpha matched by alpha or al returns no rows in the retained scratch behavior probe.
- **Expected:** FR-007 explicitly defines case-insensitive STARTS_WITH and CONTAINS with literal percent/underscore handling.
- **Consequence:** Search-like listings omit otherwise matching rows. Existing same-case tests cannot detect this.
- **Refutation:** The current normative FR-007 says case-insensitive. These operators run in PHP, so database collation cannot restore the promised behavior. Repeated same-case tests demonstrate functioning substring matching but do not justify overriding the contract.
- **Destination:** S1.
- **Acceptance:** Mixed-case positive/negative controls, literal percent/underscore and an explicit Unicode policy pass across supported backends. Reconcile the canonical spec if the intended contract differs.

### `LST-CACHE-001`: Repository deletion leaves cached listing metadata stale

- **Observed:** ServiceProvider.php:179 subscribes only to AfterDeleteEvent, while EntityRepository::delete publishes EntityEvents::POST_DELETE. A real ProviderRegistry probe caches page1 total2, deletes off-page id2, receives cached total2, then gets fresh total1 after manual eviction.
- **Expected:** FR-038/039 and the cookbook promise that successful repository deletes invalidate tagged listing results.
- **Consequence:** Hosts explicitly binding a tagged cache retain obsolete totals and navigation after committed deletion. Default composition is not assumed to bind a tagged cache.
- **Refutation:** On-page missing rows trigger a real cache miss, which does not detect off-page deletion. Cache is optional; a backend is explicitly supplied by the host in the probe. No default deployed tagged cache or universal impact is claimed.
- **Destination:** #2996.
- **Acceptance:** Repository delete through the real provider/listener/cache path invalidates off-page totals; save remains a positive control, no-cache works, rollback/refusal does not publish invalidation, and the selected canonical lifecycle delivers once.

### `LST-FILTER-001`: Supported date coercion produces values the resolver rejects

- **Observed:** ExposedFilterParser.php:94 exposes withTypeResolver; ExposedFilterCoercer.php:98/255 accepts a valid ISO date and returns DateTimeImmutable, but ListingResolver.php:641 reconstructs FilterDefinition and throws because comparison/EQ shapes accept only scalars.
- **Expected:** A successfully parsed supported date override can be consumed by the listing resolver using one canonical value representation.
- **Consequence:** Applications using the supported datetime type resolver fail on valid requests instead of resolving the listing. The default inferred string path does not establish this seam works.
- **Refutation:** The default inferred parser yields string and works, but withTypeResolver is a documented supported integration seam and the coercer advertises datetime/date. A successful parse must be consumable by the resolver. This is distinct from invalid manually constructed cookbook filters.
- **Destination:** S1.
- **Acceptance:** Settle one date representation across coercion, construction, hashing, query planning and comparisons; prove valid/invalid date overrides in permissive/strict modes and supported memory/SQLite paths.

## Cross-package findings

| ID | Owner | Finding | Severity | Destination |
| --- | --- | --- | --- | --- |
| `LST-ACCESS-001` | waaseyaa/access | Production gate does not expose listing fast-path capability | low | #3005 |
| `LST-DOCS-001` | waaseyaa/framework | Published first-listing examples do not match the shipped API | low | waaseyaa/framework documentation delta under #3118 |

These repairs are separable from listing-owned slices. Neither blocks settling the charter. No uninvestigated cross-package handoffs were added.

## Structural and profile dispositions

- **Unused paths/state:** remove the unreachable empty-accessOps branch, redundant parser type guard and immediately rejected legacy cache payload branch after caller/export review. No class is called dead from a search alone.
- **Repeated mechanisms:** consolidate equivalent listing hash and page logic. Foundation canonical JSON has different float semantics, so direct replacement is refuted.
- **Competing authorities:** canonical field registry supplies definition validation and bundle planning. Provider discovery should use the foundation capability source rather than private reflection. Cache backend contract supplies CacheItem shape; a broad object-data adapter needs no maintained external boundary.
- **Adapters:** entity-type repository lookup, request context and identifier-only cache projections serve current domain boundaries. Gate is a necessary access bridge; its production fast-path capability remains access-owned. Cache is host-provided and optional.
- **Symfony fit:** generic value/list/collection checks overlap installed Validator v7.4.10; domain operator/backend policy remains Waaseyaa. Public provider capability composition is the immediate seam; generic tagged-service composition belongs to foundation. D2 names the remaining equivalence and dependency decision. See [Symfony Type](https://symfony.com/doc/7.4/reference/constraints/Type.html) and [service tags](https://symfony.com/doc/7.4/service_container/tags.html).
- **Domain:** constructors/refusals, dense access paging, language and backend tests reviewed; conjunction/text/delete defects have bounded acceptance. Partially checked IN/BETWEEN element domains and cached language rehydration need discriminating follow-up.
- **Kernel:** finalization handles late fields and rejects invalid definitions. Early singleton resolution/failed-attempt reuse retain #2859 lifecycle ownership and S2 controls.
- **HTTP:** owns URL parsing and typed PHP results, no routes or transported response schema. In-process gate tests do not prove the full middleware journey.
- **Distribution:** all profiles below have explicit qualification owners. Source suites and synthetic composition remain source evidence.

## Refuted leads

- `LST-R-001`: Foundation intentionally omits JSON_PRESERVE_ZERO_FRACTION; listing preserves it. Blind replacement changes cache identity. Internal listing duplication still belongs to S3.
- `LST-R-002`: Current non-fast path filters all candidates before slicing, preserving dense pages and accessible totals. Deep-page performance is independent.
- `LST-R-003`: Current adapter resolves anonymous/scope principal; listing anonymous allow test passes. Missing host gate intentionally denies.
- `LST-R-004`: Current registry demotes bundle fields and complete mixed sorts before paging. Missing registry is an explicit custom-host qualification gap, not current kernel failure.
- `LST-R-005`: Missing id does force a refresh, but surviving first-page ids can return obsolete total metadata. Does not refute LST-CACHE-001.
- `LST-R-006`: Root autoload/path packages prove source only. Installed standalone, closure and generated roots require separate artifacts.

## Qualification by profile

| Profile | Supported | Evidence class | Gap owner |
| --- | --- | --- | --- |
| primitive-only | yes | source | S4 standalone split qualification |
| standalone split | yes | none | S4 |
| kernel composition | yes | source | S4 and ci/split-artifact-acceptance |
| metapackage | conditional via cli | none | S4 |
| framework closure | yes | none | ci/split-artifact-acceptance |
| generated application | yes | none | S4 and skeleton generated-app CI |

No installed form is marked qualified. Hosted closure boot must assert the listing package is included; standalone split and generated application behavior require their own roots.

## Decisions and uncertainties

| ID | Decision or uncertainty | What settles it | Owner |
| --- | --- | --- | --- |
| D1 (settled 2026-10-08) | Durable private custody for the security brief. | Maintainer-designated custody, SHA-256 copy verification and restricted ACLs verified; private receipt held by Russell Jones. | Russell |
| D2 | Set a bounded generic-validation and provider-discovery infrastructure disposition. | Compare supported Symfony Validator constraints with current failure/shape policy, preserve hash identities, and route generic composition work to foundation without introducing a second container. | Framework maintainer |
| U1 | Installed-profile and actual external-consumer exposure have not been qualified. | Exact-base sealed no-dev split/framework install and generated app exercise through its composition root, plus privately held consumer evidence where applicable. | Framework qualification owner |

## Remediation plan

Proposed slices are reviewable acceptance boundaries. File new slices before remediation. Existing #2996/#3016/#3005 retain their scope. Private work is sequenced through advisory triage.

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |
| S1 | LST-QUERY-001, LST-QUERY-002, LST-FILTER-001 | Preserve conjunction, declared text semantics and coherent date input representation with backend and negative controls; reconcile only the affected spec. | none |
| S2 | LST-DISCOVERY-001 | Canonical live provider capability discovery, late boot and early-resolution/refusal controls; remove private-property reflection. | none |
| S3 | LST-SURFACE-001, LST-STRUCTURE-001 | Exhaustive surface disposition, current source docs, equivalent hash/page consolidation and dependency justification. No legacy aliases. | S1, S2 |
| S4 | LST-SURFACE-001 | Sealed no-dev split/closure and nonempty generated app qualification, optional cache controls and executable examples; record exact runner identities. | S3 |
| S5 | LST-CACHE-001 | Execute #2996 acceptance through canonical lifecycle and commit/rollback controls. | Canonical deletion event and after-commit delivery decision with entity-storage |
| S6 | LST-CACHE-002 | Execute #3016 batching acceptance with measured reads and intact authorization. | none |

## Not reviewed and host limits

- External installed applications, live services and complete rendered HTTP journey.
- Sealed standalone/no-dev exported package and exact-base hosted closure/generated-app qualification.
- Private routed briefs from prior audits were not supplied. Exact committed ledger owner/co-owner/handoff intake is zero; all older record cross-package tables reviewed.
- Native Windows cannot replace hosted Linux POSIX/symlink/permission evidence. ci/split-artifact-acceptance and relevant generated-app jobs own missing qualification.
- Original checkout has no vendor. Dependencies installed only in an immutable scratch clone with the exact lock. No source mutation or unqualified donor vendor reuse.

## Evidence

| Check | Result | Proves |
| --- | --- | --- |
| `composer install --no-interaction --prefer-dist --no-progress --no-scripts` | exit0; PHPunit13.1.14 and Deptrac4.7.2; source package junctions remain source evidence. | Exact source lock installation in disposable scratch checkout; original checkout untouched. |
| `php vendor/bin/phpunit packages/listing/tests --no-coverage --do-not-cache-result` | exit0; 331 tests, 582 assertions; 7.268 seconds; logs/listing-tests.log | Focused source behavior over package unit/backend/contract/integration. |
| `php vendor/bin/phpunit tests/Integration/Phase14 tests/Integration/Phase29 --no-coverage --do-not-cache-result` | exit0; 62 tests, 217 assertions; 0.858 seconds; logs/integration-tests.log | Related source integration, cache and two-axis controls. |
| `php vendor/bin/phpunit packages/cli/tests/Unit/Site/PublishedContentRecipeTest.php --no-coverage --do-not-cache-result` | exit0; 6 tests, 60 assertions; 0.662 seconds; logs/generated-recipe.log | Direct generator caller assertions; source recipe shape, not installed generated runtime. |
| `php ../probes/behavior.php; php ../probes/composition.php` | Both exit0 reproductions. No claim of live incident or installed qualification. | Nonvacuous defect discriminators, synthetic fixtures and actual provider composition as labeled. |
| `php vendor/bin/deptrac --config-file=../deptrac.yaml --no-cache --no-progress --fail-on-uncovered --report-uncovered --formatter=json` | exit0; 297 allowed, 0 violations/uncovered; controls discriminate all three classes. | Charter dependency classification, not complete runtime correctness. |
| `php bin/check-package-layers --output=json` | exit0; 73 packages scanned; no violations. | Existing architecture authority. |

Every run binds the full base and lock above. Original-base public probes are retained under tests/Fixtures/Audits/Listing; focused repair regressions live in the package suites. Logs remain session evidence. Security evidence is separate.

## Scorecard

- **Sizing:** medium; 25 source files, 3517 source lines, 27 production files; 4 sequential analysis phases. Budget at most 10 agents and 2 hours, with tiered verification and one critic.
- **Agents:** 4 agents including the orchestrator; subagent tokens unavailable. No parallel implementation or repository edits.
- **Findings:** 9 package-owned IDs and 2 cross-package IDs. Severity follows reconciled verifier verdicts, with security details withheld.
- **Verification:** Independent verification A and B complete; final critic correction recheck passed with no remaining blockers. Reversals are recorded in the ledger.
- **Probes:** 2 public root probes plus independent role controls. Two original-base probes retained in governed fixture paths. Dependency controls cover allowed, forbidden and unclassified edges.
- **Open:** 0 open decisions; 1 open uncertainties; durable private custody verified. Bounded repair destinations are complete; separately owned work retains its existing issues.
- **Authority:** Audit history. Repository repair work is authorized under FW-LISTING-CONVERGENCE-01; no external mutation or release is authorized.


## Maintainability supplement, 2026-10-08

This bounded supplement reviews the repaired source at
`40df73a2425e96f019ad9af414bc68c5b1e974b1`, on top of the new repository skill
criteria. It preserves the original-base audit and probes above. All 26 current
production PHP files, manifest, surface declaration and README were reviewed.
Independent grouped verification found documentation drift and one equivalent
hash implementation; these extend LST-SURFACE-001 and LST-STRUCTURE-001 under #3195.
The repair does not claim the private-custody or converged milestones.

| Concern | Disposition and evidence |
| --- | --- |
| SoC/SRP | Retain resolver orchestration: query, current access, pagination and cache eligibility share correctness ordering. Named private sections expose those paths; tiny extracted services would widen state interfaces without demonstrated benefit. |
| DRY | Delegate context identity to ListingHash; preserve established hashes with empty, reordered, Unicode/slash, numeric-looking-map and list goldens. Operator switches serve distinct shape, coercion, field-compatibility and evaluation policies. |
| File navigation | Retain flat named discovery/declaration/input/cache/resolution groups and proportional Exception directory. No traced task justified a move, split or merge. |
| Discovery | Retain provider capability/discoverer/registry separation; correct the interface's false manifest-time and defensive-validation promises to current runtime ownership. |
| Input and validation | Retain parser/coercer and shallow/boot validation: URL presence/refusal mode, scalar conversion, input shapes and registered field relationships have different reasons to change. |
| Cache responsibilities | Retain projection/hash/invalidator boundaries for payload, identity and lifecycle. Correct canonical-delete listener docs and resolve summary to match rehydration and bounded pagination. |
| Factories and results | Retain Filter/Sort factories and DTOs/enums; forwarding adds declaration ergonomics. Pagination owns metadata invariants, ListingResult composes rows and metadata. Three exceptions carry distinct refusal contexts. |
| PHPDoc | Correct internal @api claims, exception links, key-sort semantics, validator queryability and unbounded-rule conditions. Native types plus existing list/map/callable shapes remain the contract; no invented extension seam or analyzer suppression. |
| Human comments | Preserve explanations of boot finalization, no-OFFSET windows and access-before-pagination. Remove stale future-work promises, obsolete spec paths and misleading cache/SQL-column descriptions. |
| Tool enforcement | Installed PHPStan 2.1.54 (level 5/strict rules), Deptrac 4.7.2. Public-surface.php owns classification; @api/prose alone supplies no mechanical enforcement. Scoped dependency controls remain source evidence. |

Representative maintainer tasks:

- Declare a listing: README -> HasListingsInterface -> provider capability source
  -> ListingDiscoverer -> registry -> finalizer/validator -> capability and boot
  tests -> cookbook/current spec.
- Add an operator: Operator -> Filter/FilterDefinition -> coercer -> field
  validator -> resolver refinement -> unit/contract/backend controls. Similar
  switches represent separate obligations, not one interchangeable mechanism.
- Change caching: provider bindings -> resolver eligibility/rehydration -> key
  builder/hash -> projection -> invalidator wiring -> cache/lifecycle tests.

The observed human-navigation defect was contradictory documentation. Folder
moves and wider resolver decomposition are not supported by this review.
Installed and hosted qualification evidence is reconciled separately after the
exact candidate runs; original source results remain historical evidence.


## Landing and qualification, 2026-10-08

Bounded repairs landed on main at `10bfd868e740c754d123b06dfb7f1df8abfc200e` through PR #3196.
Full hosted run [37850251513](https://github.com/waaseyaa/framework/actions/runs/37850251513) passes, including `ci/full-qualification`.
The split job `113561101751` seals tested PR merge `f88a3f97f564aa6e9bd444128885beefd4f39a1a`; its Git tree
`8a9e3b2d68d95809f868522949b6dd8ebba1ebfb` is identical to the reviewed branch head.
This distinction preserves the actual tested object rather than relabeling it.

The standalone no-dev listing-only consumer boots real provider composition and
passes parsed Unicode text, equality conjunction, optional cache and refusal
controls. The installed skeleton compiles the published-content recipe, registers
the emitted provider through literal Composer authority, synchronizes schema and
serves a public listing that includes the published witness and excludes a draft.
The additive full metapackage and framework closure boot; no core-only listing
claim or canonical detail-URL qualification is inferred. All six selected profiles
are qualified to their recorded surfaces in the ledger.

Focused source results: 426 tests, 1,069 assertions. The index/ledger repair adds
134 metadata tests, 1,091 assertions. Required local pre-push checks execute
41 gates and reuse two with equivalent inputs; three are not applicable.
Independent immutable review approves the repaired source and metadata delta.
Current source dependency analysis passes with 306 allowed edges and zero
violations, uncovered edges, warnings or errors.

D2 settles the bounded infrastructure disposition: public foundation discovery
and listing-owned operator/field policy. Installed Symfony Validator v7.4.10
Type/Choice/DateTime validators report violations, while this boundary must also
convert raw operator-shaped values and consult boot metadata. Adding adapters
would retain that domain policy; no second generic validation authority is added.
Revisit on material growth in generic validation/composition obligations.

Original-base findings, charter observations and source results above remain
historical evidence. Their bounded repair slices are complete; #3005/#2859
retain separately owned production fast-path work. D1 private custody remains
open. External existing applications were not inspected and no live exposure,
incident, release or deployment is claimed. Assessed/converged stays unclaimed.


## 2026-10-08 assessment closeout

The maintainer designated durable private custody. The original brief and both
independent review/probe evidence sets were copied and verified by SHA-256 and
restricted access controls; a private receipt is held by Russell Jones. Source
files were retained. D1 is settled and the audit is assessed. Earlier checkpoint
statements that custody was pending describe their original dates.

No runtime evidence was rerun for this metadata-only closeout. The landed repair
and installed qualification above retain their exact identities. U1 remains an
explicit external-exposure limitation, not an unowned assessment blocker.
#3005/#2859 retain the separately owned production-composition work. No new
whole-package convergence, private advisory publication or deployment is claimed.
