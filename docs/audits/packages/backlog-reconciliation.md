# Framework backlog reconciliation

Snapshot: 2026-10-08. Source base: `f099100ceedb5160a9ccc78b6ee2e365a096ecae`.
Owner: #3118 / [FW-PACKAGE-CONVERGENCE-01](../../change-records/FW-PACKAGE-CONVERGENCE-01.md).

Intake covers all 196 open issues returned by the paginated repository API and all 10 open pull requests. This is a routing snapshot, not a new finding ledger or a current reproduction verdict. Existing issue acceptance and package ledgers remain authoritative. Refresh this table before final program closeout; do not use it as an independent live queue.

Every row retains its existing issue as the work destination. Package names identify the primary audit intake owner, not exclusive implementation ownership. `framework` means root governance, CI, distribution or cross-package coordination. Co-owners and dependencies stay in each linked issue.

Kinds separate intentional future features from required work. `Repair / revalidate` preserves an existing defect or contract/documentation obligation until its owner checks current acceptance; it does not promote historical reports to newly confirmed defects. `Decision`, `Qualification` and `Program` are not future-feature exemptions. No priority, readiness, release membership or milestone is inferred from this classification.

## Reconciliation decisions

- #3118 now uses the framework-wide finish line: resolve confirmed defects and required repairs, qualify cross-package journeys, and reconcile specs/docs/GitHub. Assessment alone is an intermediate milestone.
- #2667 remains the board synchronization owner. Its implemented synchronizer supplies the reviewed mirror plan. Missing priority and contradictory readiness remain explicit label decisions; a one-time sync does not close that issue.
- #3116 and #3163 overlap on SSR fallback. Use #3116 as the broader repair owner; carry forward #3163 package-template exclusions, status, layout-failure and positive-path controls, close #3163 as duplicate tracking, never as a fixed defect.
- #1627 has been corrected to acknowledge shipped relationship-backed membership support. It remains open for role/lifecycle/access reconciliation; #2762 owns atomic identity and duplicate cleanup, and #1957 remains the separate management UI feature.
- Listing and messaging use their existing audit ledgers. #3005/#2859 stay independent listing composition owners. Messaging lifecycle/qualification decisions remain open until the maintainer settles them.
- Open PRs #3173, #3177, #3185, #3186, #3187, #3190, #3191, #3192, #3193 and #3194 are admin dependency updates. Route them to `admin` dependency and installed-build review; they neither resolve the runtime backlog nor authorize blind merging.

## Open-issue work map at intake

| Issue and scope | Primary intake | Kind |
| --- | --- | --- |
| [#1415](https://github.com/waaseyaa/framework/issues/1415) [admin-spa] M5 residual: AI observability dashboard + AI pipeline inspector (MCP endpoint admin + Mercure broadcast monitor already shipped) | `admin` | Future feature |
| [#1578](https://github.com/waaseyaa/framework/issues/1578) [notification] Delivery log + channel enable/disable + 2-tab notifications admin | `notification` | Future feature |
| [#1579](https://github.com/waaseyaa/framework/issues/1579) [admin-spa] M4A-5b: Workflow guard editing UI + persistence design | `admin` | Future feature |
| [#1588](https://github.com/waaseyaa/framework/issues/1588) [mission-rework] database-legacy-retirement-01KSEFV2 â€” must migrate consumers before deletion | `database-legacy` | Repair / revalidate |
| [#1605](https://github.com/waaseyaa/framework/issues/1605) DX: distinguish unauthorized-from-empty over JSON:API â€” signal when an access policy filters a collection to empty (already fail-closed, no leak) | `api` | Decision |
| [#1606](https://github.com/waaseyaa/framework/issues/1606) ai-vector remains non-turnkey: vector.search is unwirable and passage provenance is undefined | `ai-vector` | Repair / revalidate |
| [#1607](https://github.com/waaseyaa/framework/issues/1607) OpenAiCompatibleProvider has no tool/function calling; #[AsAgentDefinition] tools only work with AnthropicProvider | `ai-agent` | Decision |
| [#1608](https://github.com/waaseyaa/framework/issues/1608) Agent endpoint surfaces NullLlmProvider as a success-shaped run â€” return 'no model configured' instead of a 200 placeholder (boot warning shipped) | `ai-agent` | Repair / revalidate |
| [#1609](https://github.com/waaseyaa/framework/issues/1609) #[ContentEntityType] does not register an entity; you must also add it to config/entity-types.php | `foundation` | Repair / revalidate |
| [#1623](https://github.com/waaseyaa/framework/issues/1623) Policy DI now resolves register() bindings + degrades nullable deps, but discovered #[PolicyAttribute] policies are still eager-at-boot and an unbound required ctor dep is a whole-kernel fatal â€” make lazy or non-fatal diagnostic | `foundation` | Repair / revalidate |
| [#1624](https://github.com/waaseyaa/framework/issues/1624) Notifications: no first-party Mercure channel, and NotificationDispatcher channels are build-time only | `notification` | Decision |
| [#1625](https://github.com/waaseyaa/framework/issues/1625) schema:check VARCHAR(n) false-positive: typesCompatible() still doesn't strip the length suffix + audit_* migrations still emit VARCHAR(n) (oidc_client base cols partially fixed) â€” strip suffix in comparator + emit TEXT in audit migrations | `database-legacy` | Repair / revalidate |
| [#1626](https://github.com/waaseyaa/framework/issues/1626) Inertia on-ramp residuals: align page payload with stock data-page mount-node attribute + ship a starter vite.config (publicDir:false) â€” asset-base + configurable bundle/entry landed | `inertia` | Repair / revalidate |
| [#1627](https://github.com/waaseyaa/framework/issues/1627) groups package ships Group/GroupType but no membership primitive | `groups` | Repair / revalidate |
| [#1629](https://github.com/waaseyaa/framework/issues/1629) Docs: entity-modeling guidance â€” relationship-vs-FK, reserved-word type ids, per-vendor taxonomy scoping | `entity` | Repair / revalidate |
| [#1631](https://github.com/waaseyaa/framework/issues/1631) No app hook to server-render <head> meta (OpenGraph/SEO): provider middleware is pre-controller; app can't override the Inertia renderer | `inertia` | Repair / revalidate |
| [#1633](https://github.com/waaseyaa/framework/issues/1633) structured-import is GFM single-entity (promptâ†’value), not a multi-row CSV importer | `structured-import` | Repair / revalidate |
| [#1639](https://github.com/waaseyaa/framework/issues/1639) enhancement(ai-tools/mcp): add a media upload agent tool | `ai-tools` | Future feature |
| [#1640](https://github.com/waaseyaa/framework/issues/1640) OAuth 2.1 resource-server auth for the MCP endpoint (RFC 9728 / resource indicators / PKCE) | `mcp` | Decision |
| [#1658](https://github.com/waaseyaa/framework/issues/1658) EntityRepositoryInterface growth breaks consumer in-memory test doubles; ship a reusable base | `testing` | Repair / revalidate |
| [#1687](https://github.com/waaseyaa/framework/issues/1687) downstream: composer require waaseyaa/framework:<exact> writes an exact pin that blocks point upgrades | `framework` | Repair / revalidate |
| [#1742](https://github.com/waaseyaa/framework/issues/1742) Media versioning/CAS trigger is dormant + pending-upload store is per-request in-memory (latent data loss) | `media` | Repair / revalidate |
| [#1762](https://github.com/waaseyaa/framework/issues/1762) Feature: build the media source-plugin substrate + authorized media download | `media` | Decision |
| [#1861](https://github.com/waaseyaa/framework/issues/1861) cache: qualify and repair revision-pointer invalidation through production wiring | `cache` | Qualification |
| [#1866](https://github.com/waaseyaa/framework/issues/1866) Lift ServiceProvider::routes() signature to a routing-owned interface (L0â†’L4/L1 type-hint coupling baselined in WP7) | `foundation` | Repair / revalidate |
| [#1957](https://github.com/waaseyaa/framework/issues/1957) groups: membership management surface â€” admin SPA UI + API exposure (CW-v1 WP-4 follow-up) | `groups` | Future feature |
| [#2054](https://github.com/waaseyaa/framework/issues/2054) proposal: first-class chat surface for Waaseyaa applications | `messaging` | Future feature |
| [#2055](https://github.com/waaseyaa/framework/issues/2055) C-22 dead-code gate missed distribution repos; dormant getStorage() seam fails silently | `framework` | Repair / revalidate |
| [#2123](https://github.com/waaseyaa/framework/issues/2123) Asset-manifest helper for consumer public assets | `ssr` | Future feature |
| [#2132](https://github.com/waaseyaa/framework/issues/2132) Rich-text safety: unsanitized fields.*.raw twin, sanitizer not injectable, widget/field-type coupling unenforced, CSP not configurable | `admin` | Repair / revalidate |
| [#2159](https://github.com/waaseyaa/framework/issues/2159) Anonymous published reads return no data when a content-group entity's status field is not Protected+authorizationInput | `access` | Repair / revalidate |
| [#2169](https://github.com/waaseyaa/framework/issues/2169) GraphQL schema reveals entity type names for types whose rows are fully access-denied (non-blocking) | `graphql` | Decision |
| [#2182](https://github.com/waaseyaa/framework/issues/2182) Admin media library shows file URIs instead of image thumbnails | `admin` | Repair / revalidate |
| [#2229](https://github.com/waaseyaa/framework/issues/2229) Standardize the native specification contract | `framework` | Repair / revalidate |
| [#2342](https://github.com/waaseyaa/framework/issues/2342) feat: add reusable Open Graph package and build-time validation | `seo` | Future feature |
| [#2432](https://github.com/waaseyaa/framework/issues/2432) Destructive configuration import (config:import --delete-orphans) has no authorization policy | `config` | Repair / revalidate |
| [#2433](https://github.com/waaseyaa/framework/issues/2433) Configuration rollback and candidate sweep have no production authorization policy | `config` | Repair / revalidate |
| [#2440](https://github.com/waaseyaa/framework/issues/2440) Admin/Auth: publish the complete account UI without copying backend security logic | `admin` | Repair / revalidate |
| [#2441](https://github.com/waaseyaa/framework/issues/2441) DX: ship executable recipes for safe auth and account customization | `auth` | Repair / revalidate |
| [#2442](https://github.com/waaseyaa/framework/issues/2442) site:init: offer minimal and editorial initialization profiles without forking Framework internals | `cli` | Repair / revalidate |
| [#2443](https://github.com/waaseyaa/framework/issues/2443) Acceptance: prove the complete account lifecycle in a fresh generated application | `framework` | Qualification |
| [#2447](https://github.com/waaseyaa/framework/issues/2447) Assess PostgreSQL readiness after Sheguiandah alpha field evidence | `database-legacy` | Decision |
| [#2448](https://github.com/waaseyaa/framework/issues/2448) Assess MySQL/MariaDB readiness after the PostgreSQL portability assessment | `database-legacy` | Decision |
| [#2449](https://github.com/waaseyaa/framework/issues/2449) Define the Waaseyaa beta support contract from alpha field and portability evidence | `framework` | Decision |
| [#2479](https://github.com/waaseyaa/framework/issues/2479) Audit environment, bootstrap-configuration, and secret-policy ownership across Framework, Anokii, and Sheg | `foundation` | Decision |
| [#2498](https://github.com/waaseyaa/framework/issues/2498) deployer: add a consistent SQLite artifact snapshot seam (VACUUM INTO) to RuntimeState | `deployer` | Repair / revalidate |
| [#2499](https://github.com/waaseyaa/framework/issues/2499) foundation/upgrade: add a canonical observation digest and versioned preflight evidence envelope | `foundation` | Repair / revalidate |
| [#2525](https://github.com/waaseyaa/framework/issues/2525) Delivery: add stale-base detection, bounded landing queues, and exact merge handoffs | `framework` | Repair / revalidate |
| [#2527](https://github.com/waaseyaa/framework/issues/2527) Program: remove recurring delivery friction across Framework, Sheg, and Anokii | `framework` | Program |
| [#2545](https://github.com/waaseyaa/framework/issues/2545) docs(config): define the authority contract for cloned databases | `config` | Repair / revalidate |
| [#2548](https://github.com/waaseyaa/framework/issues/2548) fix(deployer): preserve interdependent runtime schemas across artifact handoff | `deployer` | Repair / revalidate |
| [#2549](https://github.com/waaseyaa/framework/issues/2549) fix(deployer): merge user identities by stable UUID, not numeric primary key alone | `deployer` | Repair / revalidate |
| [#2640](https://github.com/waaseyaa/framework/issues/2640) Kernel body-size refusal preempts three transport-guard answers on MCP routes | `foundation` | Repair / revalidate |
| [#2650](https://github.com/waaseyaa/framework/issues/2650) release: replace the root framework dist with a governed allowlist and size budget | `framework` | Repair / revalidate |
| [#2653](https://github.com/waaseyaa/framework/issues/2653) Program: ship Waaseyaa's AI-first local development plane | `framework` | Program |
| [#2660](https://github.com/waaseyaa/framework/issues/2660) dx: generate equivalent guidelines and Agent Skills for supported coding clients | `bimaaji` | Repair / revalidate |
| [#2661](https://github.com/waaseyaa/framework/issues/2661) specs: add lifecycle metadata and compile a sanitized versioned agent corpus | `framework` | Repair / revalidate |
| [#2662](https://github.com/waaseyaa/framework/issues/2662) ai-docs: ship cited version-matched documentation search with SQLite FTS5 | `bimaaji` | Repair / revalidate |
| [#2663](https://github.com/waaseyaa/framework/issues/2663) dx: generate portable project-local MCP descriptors and machine-safe client configuration | `bimaaji` | Repair / revalidate |
| [#2664](https://github.com/waaseyaa/framework/issues/2664) foundation: orchestrate project:init, project upgrades, and AI generated-state verification | `foundation` | Repair / revalidate |
| [#2665](https://github.com/waaseyaa/framework/issues/2665) acceptance: prove the AI-first journey from packaged fresh and upgraded applications | `framework` | Qualification |
| [#2666](https://github.com/waaseyaa/framework/issues/2666) developer-tools: define sovereignty-aware logs, last-error, URL, and schema-shape diagnostics | `bimaaji` | Repair / revalidate |
| [#2667](https://github.com/waaseyaa/framework/issues/2667) roadmap: restore Framework Project synchronization or retire its all-open-issues claim | `framework` | Repair / revalidate |
| [#2670](https://github.com/waaseyaa/framework/issues/2670) Driver write() takes row identity twice and no driver reconciles a divergent $values id | `entity-storage` | Repair / revalidate |
| [#2676](https://github.com/waaseyaa/framework/issues/2676) Program: establish native Windows and Linux parity across Waaseyaa | `framework` | Program |
| [#2678](https://github.com/waaseyaa/framework/issues/2678) CI: require native Windows and Linux contract coverage | `framework` | Repair / revalidate |
| [#2680](https://github.com/waaseyaa/framework/issues/2680) Prove AI-first local development parity on native Windows and Linux | `framework` | Qualification |
| [#2681](https://github.com/waaseyaa/framework/issues/2681) Qualify packaged-consumer parity on native Windows and Linux | `framework` | Qualification |
| [#2686](https://github.com/waaseyaa/framework/issues/2686) bimaaji: decide ownership of the shared root AGENTS.md path across client transformers | `bimaaji` | Decision |
| [#2687](https://github.com/waaseyaa/framework/issues/2687) Windows waaseyaa dev FrankenPHP binary omits ext-sodium | `frankenphp` | Repair / revalidate |
| [#2693](https://github.com/waaseyaa/framework/issues/2693) test(bimaaji): prove the file-level sandbox target guard on Windows, or record why it cannot be | `bimaaji` | Qualification |
| [#2697](https://github.com/waaseyaa/framework/issues/2697) Centralize production auth controller construction | `foundation` | Repair / revalidate |
| [#2699](https://github.com/waaseyaa/framework/issues/2699) Provide a secure local auth-token delivery mechanism | `auth` | Repair / revalidate |
| [#2702](https://github.com/waaseyaa/framework/issues/2702) arch(skeleton): decide the production container topology â€” FPM compatibility vs a self-serving FrankenPHP default | `foundation` | Decision |
| [#2707](https://github.com/waaseyaa/framework/issues/2707) CI: handle bot-authored PR workflow approvals and reconcile check reporting | `framework` | Repair / revalidate |
| [#2729](https://github.com/waaseyaa/framework/issues/2729) ContentEntityBase never receives the process EntityTypeManager, so every entity reports its type as non-translatable | `entity` | Repair / revalidate |
| [#2733](https://github.com/waaseyaa/framework/issues/2733) PRE_DELETE guard refusals are an untyped RuntimeException no caller can map (500s; blocked retention purges) | `entity` | Repair / revalidate |
| [#2741](https://github.com/waaseyaa/framework/issues/2741) Queue claim settlement is not fenced against an obsolete worker after reclaim | `queue` | Repair / revalidate |
| [#2743](https://github.com/waaseyaa/framework/issues/2743) Queue retry claims survive dead processes without a recoverable handoff outcome | `queue` | Repair / revalidate |
| [#2745](https://github.com/waaseyaa/framework/issues/2745) Persistent async notifications lose recipient routing for mail delivery | `notification` | Repair / revalidate |
| [#2747](https://github.com/waaseyaa/framework/issues/2747) BroadcastStorage retained writes leak live messages on failure and retry | `api` | Repair / revalidate |
| [#2750](https://github.com/waaseyaa/framework/issues/2750) billing webhook claim can suppress retries before durable effects complete | `billing` | Repair / revalidate |
| [#2751](https://github.com/waaseyaa/framework/issues/2751) converge analytics and GitHub clients on the shared HTTP transport authority | `http-client` | Repair / revalidate |
| [#2756](https://github.com/waaseyaa/framework/issues/2756) contract(engagement): decide integrity and lifecycle for polymorphic targets | `engagement` | Decision |
| [#2759](https://github.com/waaseyaa/framework/issues/2759) architecture(media): consolidate provider and HTTP upload policy authority | `media` | Repair / revalidate |
| [#2762](https://github.com/waaseyaa/framework/issues/2762) contract(groups): make membership and content-assignment identity atomic | `groups` | Repair / revalidate |
| [#2763](https://github.com/waaseyaa/framework/issues/2763) architecture(search): make projection failures observable and recoverable | `search` | Repair / revalidate |
| [#2765](https://github.com/waaseyaa/framework/issues/2765) design(deployer): make SQLite activation crash-recoverable and directory-durable | `deployer` | Repair / revalidate |
| [#2768](https://github.com/waaseyaa/framework/issues/2768) C-22 left translation runtime split: repository hydration omits translations and the public contract targets a deleted engine | `entity-storage` | Repair / revalidate |
| [#2769](https://github.com/waaseyaa/framework/issues/2769) security(auth): move public verification resend behind a durable mail outbox | `auth` | Repair / revalidate |
| [#2775](https://github.com/waaseyaa/framework/issues/2775) fix(auth): make reset and invite token spending atomic and predicate-complete | `auth` | Repair / revalidate |
| [#2782](https://github.com/waaseyaa/framework/issues/2782) release(ai-development): establish governed public package distribution | `framework` | Repair / revalidate |
| [#2783](https://github.com/waaseyaa/framework/issues/2783) Program: compile governed application blueprints through the canonical site contract | `site-contract` | Program |
| [#2787](https://github.com/waaseyaa/framework/issues/2787) site:init: compile approved application blueprints through the existing transaction | `cli` | Repair / revalidate |
| [#2794](https://github.com/waaseyaa/framework/issues/2794) media: return entity-keyed authorized URLs from MediaAssetStore | `media` | Repair / revalidate |
| [#2805](https://github.com/waaseyaa/framework/issues/2805) P1: SQLite copy-and-replace ALTER degrades the target table, and a shipped OIDC migration still compares whole schemas | `database-legacy` | Repair / revalidate |
| [#2815](https://github.com/waaseyaa/framework/issues/2815) security(entity): add tenantId-scoped declarative tenancy boundary | `entity` | Repair / revalidate |
| [#2816](https://github.com/waaseyaa/framework/issues/2816) security(auth): bind tenant scope through session and durable bearer authentication | `auth` | Repair / revalidate |
| [#2817](https://github.com/waaseyaa/framework/issues/2817) security: provide production secret-provider composition for integration credentials | `foundation` | Repair / revalidate |
| [#2818](https://github.com/waaseyaa/framework/issues/2818) queue: define an isolated worker boundary for filesystem, process, network and resources | `queue` | Decision |
| [#2819](https://github.com/waaseyaa/framework/issues/2819) audit: support transaction-joined application lifecycle records | `audit` | Repair / revalidate |
| [#2821](https://github.com/waaseyaa/framework/issues/2821) packaging(cli): decouple content and presentation packages from the core CLI closure | `cli` | Repair / revalidate |
| [#2823](https://github.com/waaseyaa/framework/issues/2823) http: decide the public credential-consuming outbound client boundary | `http-client` | Decision |
| [#2824](https://github.com/waaseyaa/framework/issues/2824) api: decide the public API-versioning boundary for applications | `api` | Decision |
| [#2825](https://github.com/waaseyaa/framework/issues/2825) health: define the public health-check contribution seam | `foundation` | Decision |
| [#2834](https://github.com/waaseyaa/framework/issues/2834) Program: harden governed workflows through Anokii and Sheguiandah field evidence | `framework` | Program |
| [#2844](https://github.com/waaseyaa/framework/issues/2844) Program: make application generation and CLI scaffolding production-grade | `framework` | Program |
| [#2847](https://github.com/waaseyaa/framework/issues/2847) cli: converge entity and content-type generators on canonical field metadata | `cli` | Repair / revalidate |
| [#2848](https://github.com/waaseyaa/framework/issues/2848) cli: generate policy and workflow extensions through canonical governance contracts | `cli` | Repair / revalidate |
| [#2849](https://github.com/waaseyaa/framework/issues/2849) cli: generate ingestion, search, and seed extensions with companion tests | `cli` | Repair / revalidate |
| [#2850](https://github.com/waaseyaa/framework/issues/2850) acceptance: qualify application generation across packaged upgrades and supported hosts | `framework` | Qualification |
| [#2851](https://github.com/waaseyaa/framework/issues/2851) Program: gate Framework beta on governed lifecycle integrity | `framework` | Program |
| [#2859](https://github.com/waaseyaa/framework/issues/2859) kernel: qualify composition authority and boot lifecycle across entrypoints | `foundation` | Repair / revalidate |
| [#2860](https://github.com/waaseyaa/framework/issues/2860) release: make beta eligibility derive from the governed lifecycle gate | `framework` | Repair / revalidate |
| [#2869](https://github.com/waaseyaa/framework/issues/2869) release-ops: governed delivery telemetry and agent-quality dashboard | `framework` | Program |
| [#2873](https://github.com/waaseyaa/framework/issues/2873) phpstan: establish a governed level-8 fail-on-new ratchet | `framework` | Repair / revalidate |
| [#2905](https://github.com/waaseyaa/framework/issues/2905) release: define and enforce signed tag provenance across monorepo and split packages | `framework` | Repair / revalidate |
| [#2954](https://github.com/waaseyaa/framework/issues/2954) DORA: bind consumer production promotions to delivered application revisions | `framework` | Repair / revalidate |
| [#2955](https://github.com/waaseyaa/framework/issues/2955) DORA: define incident and deployment-rework evidence with truthful recovery metrics | `framework` | Repair / revalidate |
| [#2956](https://github.com/waaseyaa/framework/issues/2956) DORA: configure product mappings and qualify end-to-end dashboard inputs | `framework` | Repair / revalidate |
| [#2961](https://github.com/waaseyaa/framework/issues/2961) packaging: public conformance/IO helpers are unreachable in a packaged consumer (autoload-dev is root-only) | `testing` | Repair / revalidate |
| [#2978](https://github.com/waaseyaa/framework/issues/2978) oauth-provider: support identity-only login without email lookup or offline consent | `oauth-provider` | Decision |
| [#2984](https://github.com/waaseyaa/framework/issues/2984) ingestion: consolidate four implementations, correct stale specs, and resolve unmarked public surface | `ingestion` | Repair / revalidate |
| [#2985](https://github.com/waaseyaa/framework/issues/2985) audit: trace competing implementations and reconcile framework-wide duplication coverage | `framework` | Program |
| [#2992](https://github.com/waaseyaa/framework/issues/2992) auth: preserve failed-login throttling under concurrent attempts | `auth` | Repair / revalidate |
| [#2993](https://github.com/waaseyaa/framework/issues/2993) auth: successful login must not erase unrelated IP failure history | `auth` | Repair / revalidate |
| [#2994](https://github.com/waaseyaa/framework/issues/2994) auth: qualify atomic rate limiter SQL across supported database drivers | `auth` | Qualification |
| [#2997](https://github.com/waaseyaa/framework/issues/2997) entity: qualify translation-write delivery to derived stores and audit consumers | `entity-storage` | Qualification |
| [#3001](https://github.com/waaseyaa/framework/issues/3001) audit: reconcile all 41 antipattern findings into the delivery roadmap | `framework` | Program |
| [#3002](https://github.com/waaseyaa/framework/issues/3002) events: reconcile documented listener discovery with production ordering | `foundation` | Repair / revalidate |
| [#3003](https://github.com/waaseyaa/framework/issues/3003) mcp: preserve endpoint transport for kernel rate-limit refusals | `mcp` | Repair / revalidate |
| [#3004](https://github.com/waaseyaa/framework/issues/3004) routing: qualify explicit access posture across packaged builders and assembled routes | `routing` | Qualification |
| [#3005](https://github.com/waaseyaa/framework/issues/3005) listing: qualify fast-path capability through production gate wiring | `listing` | Qualification |
| [#3006](https://github.com/waaseyaa/framework/issues/3006) pagination: bound deep-page hydration through canonical repository queries | `entity-storage` | Repair / revalidate |
| [#3007](https://github.com/waaseyaa/framework/issues/3007) cli: report composed routes and declared access posture in route:list | `cli` | Repair / revalidate |
| [#3008](https://github.com/waaseyaa/framework/issues/3008) publishing: define supported repository composition for ContentPublisher | `publishing` | Repair / revalidate |
| [#3009](https://github.com/waaseyaa/framework/issues/3009) entity-storage: plan responsibility extraction with transaction-preserving evidence | `entity-storage` | Repair / revalidate |
| [#3010](https://github.com/waaseyaa/framework/issues/3010) retention: qualify complete and partial scan outcome reporting | `field` | Qualification |
| [#3011](https://github.com/waaseyaa/framework/issues/3011) retention: validate persisted policy decoding before destructive processing | `field` | Repair / revalidate |
| [#3012](https://github.com/waaseyaa/framework/issues/3012) entity: surface lifecycle status persistence failures | `entity` | Repair / revalidate |
| [#3013](https://github.com/waaseyaa/framework/issues/3013) routing: declare domain-router ownership independently of package ordering | `routing` | Repair / revalidate |
| [#3014](https://github.com/waaseyaa/framework/issues/3014) security: qualify write-tool error sanitization across shipped tool families | `ai-tools` | Repair / revalidate |
| [#3015](https://github.com/waaseyaa/framework/issues/3015) audit: qualify checkpoint sealing and export under overlapping execution | `audit` | Qualification |
| [#3017](https://github.com/waaseyaa/framework/issues/3017) admin: align translated UI keys with supported locale lookup | `admin` | Repair / revalidate |
| [#3018](https://github.com/waaseyaa/framework/issues/3018) graphql: qualify bounded query execution and access-aware totals | `graphql` | Qualification |
| [#3019](https://github.com/waaseyaa/framework/issues/3019) mcp: define anonymous rate-limit partitioning and aggregate protection | `mcp` | Repair / revalidate |
| [#3020](https://github.com/waaseyaa/framework/issues/3020) mcp: clarify and verify tool side-effect declarations | `mcp` | Decision |
| [#3021](https://github.com/waaseyaa/framework/issues/3021) container: report circular service resolution with a bounded diagnostic | `foundation` | Repair / revalidate |
| [#3022](https://github.com/waaseyaa/framework/issues/3022) entity: document and qualify revision event meanings for pointer movement | `entity` | Repair / revalidate |
| [#3023](https://github.com/waaseyaa/framework/issues/3023) admin-surface: make required entity identifier validation consistent | `admin-surface` | Repair / revalidate |
| [#3024](https://github.com/waaseyaa/framework/issues/3024) entity-storage: quote registered identifiers during translation ID allocation | `entity-storage` | Repair / revalidate |
| [#3025](https://github.com/waaseyaa/framework/issues/3025) cli: reconcile cache-clear inventory with configured bins | `cli` | Repair / revalidate |
| [#3026](https://github.com/waaseyaa/framework/issues/3026) discovery: tolerate concurrent cache-directory creation | `foundation` | Repair / revalidate |
| [#3027](https://github.com/waaseyaa/framework/issues/3027) admin: render pipeline navigation with the registered component name | `admin` | Repair / revalidate |
| [#3028](https://github.com/waaseyaa/framework/issues/3028) migration: qualify entity and ID-map transaction connection identity | `migration` | Qualification |
| [#3029](https://github.com/waaseyaa/framework/issues/3029) cli: reconcile dormant revision migration generators with current storage docs | `cli` | Repair / revalidate |
| [#3030](https://github.com/waaseyaa/framework/issues/3030) entity-storage: bound revision language-pointer memo lifetime | `entity-storage` | Repair / revalidate |
| [#3031](https://github.com/waaseyaa/framework/issues/3031) admin: reuse the canonical CSRF cookie decode contract for uploads | `admin` | Repair / revalidate |
| [#3035](https://github.com/waaseyaa/framework/issues/3035) transaction: qualify committed outcomes in remaining CLI/MCP and repository callers | `entity-storage` | Qualification |
| [#3072](https://github.com/waaseyaa/framework/issues/3072) Investigate whether waaseyaa/api should hard-require waaseyaa/media | `api` | Decision |
| [#3073](https://github.com/waaseyaa/framework/issues/3073) Align generated owner roles with admin surface authorization | `admin-surface` | Repair / revalidate |
| [#3075](https://github.com/waaseyaa/framework/issues/3075) architecture: adopt Deptrac and retire overlapping hand-written dependency analysis | `framework` | Repair / revalidate |
| [#3078](https://github.com/waaseyaa/framework/issues/3078) refactor(entity): centralize Node creation timestamp lifecycle | `entity` | Repair / revalidate |
| [#3079](https://github.com/waaseyaa/framework/issues/3079) refactor(admin-surface): declare config-entity mutability outside the generic host | `admin-surface` | Repair / revalidate |
| [#3082](https://github.com/waaseyaa/framework/issues/3082) refactor(admin-surface): decompose generic host coordination seams | `admin-surface` | Repair / revalidate |
| [#3083](https://github.com/waaseyaa/framework/issues/3083) refactor(admin-surface): separate provider routing and static delivery coordination | `admin-surface` | Repair / revalidate |
| [#3084](https://github.com/waaseyaa/framework/issues/3084) contract(admin-surface): resolve legacy aggregate contract exports | `admin-surface` | Repair / revalidate |
| [#3093](https://github.com/waaseyaa/framework/issues/3093) ci: pin floating ubuntu-latest runners and reconcile action version comments | `framework` | Repair / revalidate |
| [#3095](https://github.com/waaseyaa/framework/issues/3095) docs: give CI structure an owning spec and route it in CLAUDE.md and the drift detector | `framework` | Repair / revalidate |
| [#3097](https://github.com/waaseyaa/framework/issues/3097) ci: retain JUnit from the random-order shards so unique-detection can be measured | `framework` | Repair / revalidate |
| [#3099](https://github.com/waaseyaa/framework/issues/3099) chore: WSL ~/dev consolidation record â€” framework, studio, anokii (2026-09-18) | `framework` | Repair / revalidate |
| [#3100](https://github.com/waaseyaa/framework/issues/3100) Unify upload-size policy and runtime limits with a usable 10 MiB file default | `media` | Repair / revalidate |
| [#3102](https://github.com/waaseyaa/framework/issues/3102) foundation DatabaseRateLimiter is read-then-write while the auth limiter is atomic; RateLimiterInterface does not say which | `foundation` | Repair / revalidate |
| [#3103](https://github.com/waaseyaa/framework/issues/3103) queue: UniqueJob and RateLimited attributes are silently unenforced under DbalQueue (cross-process) | `queue` | Repair / revalidate |
| [#3104](https://github.com/waaseyaa/framework/issues/3104) database: untyped query parameters bind integers as text; SQLite expression comparisons against them are always true | `database-legacy` | Repair / revalidate |
| [#3109](https://github.com/waaseyaa/framework/issues/3109) media: authorized delivery cannot name a non-account owner; private files still addressed as public:// | `media` | Repair / revalidate |
| [#3110](https://github.com/waaseyaa/framework/issues/3110) schema authority: tables created via schema()->createTable() outside the coordinator refuse every later transition (S1-DB109); no non-CLI adoption path | `foundation` | Repair / revalidate |
| [#3116](https://github.com/waaseyaa/framework/issues/3116) SSR page fallback renders any root template â€” including the framework's own home/page/entity/403/404/500 â€” as a 200 page at /{segment} | `ssr` | Repair / revalidate |
| [#3117](https://github.com/waaseyaa/framework/issues/3117) Program: converge waaseyaa/cli package boundaries, command contracts, and distribution | `cli` | Program |
| [#3118](https://github.com/waaseyaa/framework/issues/3118) Program: audit and converge the Framework package set | `framework` | Program |
| [#3120](https://github.com/waaseyaa/framework/issues/3120) docs(skeleton): document and verify the development-to-production activation path | `framework` | Repair / revalidate |
| [#3122](https://github.com/waaseyaa/framework/issues/3122) Program: trustworthy application graph and route composition for packaged consumers | `framework` | Program |
| [#3123](https://github.com/waaseyaa/framework/issues/3123) Audit: converge waaseyaa/foundation contracts, composition lifecycle, and distribution | `foundation` | Program |
| [#3124](https://github.com/waaseyaa/framework/issues/3124) Audit: converge waaseyaa/bimaaji graph contracts, mutation semantics, and distribution | `bimaaji` | Program |
| [#3125](https://github.com/waaseyaa/framework/issues/3125) Audit: converge waaseyaa/routing composition, metadata, and installed contracts | `routing` | Program |
| [#3129](https://github.com/waaseyaa/framework/issues/3129) fix(ci): align split-main job timeout with authoritative CI wait | `framework` | Repair / revalidate |
| [#3130](https://github.com/waaseyaa/framework/issues/3130) fix(ci): diagnose random-order shard hang with replayable progress evidence | `framework` | Repair / revalidate |
| [#3134](https://github.com/waaseyaa/framework/issues/3134) maintainer skills: deterministic precedence when repository and installed copies share a name | `framework` | Repair / revalidate |
| [#3137](https://github.com/waaseyaa/framework/issues/3137) ai-vector: converge the package after the #3135 audit (remediation umbrella) | `ai-vector` | Program |
| [#3143](https://github.com/waaseyaa/framework/issues/3143) ai-vector: bound the cost of public semantic search | `ai-vector` | Repair / revalidate |
| [#3156](https://github.com/waaseyaa/framework/issues/3156) delivery governance: preserve reviewed provenance when updating PR branches from main | `framework` | Repair / revalidate |
| [#3163](https://github.com/waaseyaa/framework/issues/3163) SSR path-template fallback serves welcome, layout and error templates with HTTP 200 for unrelated paths | `ssr` | Repair / revalidate |
| [#3164](https://github.com/waaseyaa/framework/issues/3164) No supported way to rotate the application master; manual rotation leaves audit checkpoints unverifiable | `audit` | Repair / revalidate |
| [#3171](https://github.com/waaseyaa/framework/issues/3171) delivery: narrow conservative preflight selectors | `framework` | Repair / revalidate |
| [#3181](https://github.com/waaseyaa/framework/issues/3181) site:doctor: tracked Migration DDL is misclassified as runtime schema creation | `site-contract` | Repair / revalidate |
| [#3184](https://github.com/waaseyaa/framework/issues/3184) Closed User reader lacks batch active-status API, causing seconds of strict audit writes in directory reads | `user` | Repair / revalidate |

## Document intake and remaining work

Inventory searches covered tracked root guidance, `.agents/skills`, shipped `packages/bimaaji/resources/skills`, package READMEs, `docs/specs`, governance, ADRs, cookbooks, upgrade notes, change records, audits, history and `kitty-specs`, plus code/tool/test references to the retirement set. A path inventory is not a content audit of every file. Package audits must read the related documents and bind requirement, intended spec, implementation, consumer and acceptance evidence.

| Surface | Disposition / existing owner |
| --- | --- |
| README and maintainer/shipped skills | Method/README batch landed at `f099100ce`; remaining shipped-skill accuracy belongs to #2660 and package audits. |
| Superseded config/migration mission specs | Delete; live contracts are `config-management.md` and `migration-platform.md`. Remove corpus entries, repair references. |
| Retired M-004 two-axis spec, planning spec, cookbook and upgrade guide | Delete; current model is `revision-system-unified.md`. No supported upgrade obligation was identified for the never-kernel-wired retired stack. |
| Frozen `docs/specs/missions` filing manifests | Delete; retired Spec Kitty metadata has no live generator/test consumer. Git history preserves the original filing state. |
| Corpus pilot | Keep compiler lifecycle behavior tested with purpose-built fixtures. Compile live successors instead of retaining obsolete documents to feed the test. #2661 remains responsible for wider lifecycle migration. |
| Live entity/storage docs | #1629, #2768 and #3029 own known stale guidance and generator contracts. Keep current requirements; do not relabel runtime defects as documentation cleanup. |
| Remaining live specs | #2229 and each package audit own current lifecycle/SDD traceability. The filename `v1` or age alone is not evidence of obsolescence. |
| Audit snapshots and bound evidence | Retain byte-bound records while existing finding/coverage consumers depend on them. Historical paths remain historical evidence, not working-tree links or live authority. |
| Other `docs/history` and `kitty-specs` material | Review named evidence consumers before deleting. #3118 owns remaining hygiene intake; this bounded retirement is not an exhaustive historical-tree purge. |

## Published reconciliation result

The reviewed synchronizer plan `bfc971120969b2f1daf5d2b58bfd9e8bcb7352beacdb66fb05245485d6177e77`
was verified and applied: 16 operations, 22 writes, no failed operation. All three
missing issues were added; closed-item readiness was cleared; #3118 mirrors its
authoritative in-progress label. After #3163 was consolidated into #3116, its
closed-item readiness was cleared separately. There are 195 remaining open
issues; the 196-row table above preserves the complete intake and the duplicate
handoff. No defect was declared repaired by closing the duplicate.

The post-apply audit found 79 missing priorities and 15 ambiguous readiness
labels. These remain with #2667 and the linked issue owners. They are distinct
from package defects and are not silently normalized from board ordering.
