# Framework backlog reconciliation

Snapshot: 2026-10-09. Source base: `98189412dbd4055c22c9583a01b3adca736b5906`.
Owner: #3118 / [FW-PACKAGE-CONVERGENCE-01](../../change-records/FW-PACKAGE-CONVERGENCE-01.md).

Complete intake: 197 open issues plus new J1 #3199, 10 open dependency PRs,
and all 338 Project items before synchronization. Queries: issue list open limit
1000, PR list open limit 100, Project item list limit 1000 with total checked.
This is a dated planning disposition, not fresh reproduction of every defect.
Issue acceptance and package ledgers remain authoritative. Refresh before final
closeout; do not maintain a second live queue.

## Decisions

- Keep one convergence umbrella #3118. Native sub-issues encode existing program
  ownership; #2851 remains a separate beta gate. Closed #2719/#2727 supply history.
- Retain existing defect owners, including #3116 after duplicate #3163 retirement.
  No remaining defect is closed merely because it appears in this work map.
- Assign missing priorities using P1 for integrity/access/install or shared audit
  review, P2 for normal review, P3 for later feature/nonblocking/housekeeping
  review. Existing priorities stay. These are review ordering, not new severity.
- Make readiness unambiguous: missing carrier becomes Needs Triage; explicit
  Decision supersedes redundant design/blocked/triage carriers and Deferred
  supersedes a redundant design carrier. Preserve actual dependency edges.
- Keep one active Framework Project. Simplify views; retire the personal all-Done
  board as history. #2667 still owns continuous sync and drift detection.
- J1 #3199 is the only new sprint candidate, ready to start assessment. Existing
  bootstrap/schema/SSR candidates must be reproduced before entering repair.
- PRs #3173/#3177/#3185/#3186/#3187/#3190/#3191/#3192/#3193/#3194 remain admin
  dependency/build review, not audit closure or authorization to merge them.

## Open-issue work map

Every issue retains itself as its work destination. Primary intake identifies
where to review, not exclusive implementation ownership. Repair/revalidate is a
preserved obligation, not a claim of current reproduction. Programs, decisions
and qualification are not future-feature exemptions. Existing milestones and
release membership are unchanged. Next action applies to the recorded scope.

| Issue and scope | Primary intake | Kind | Next action |
| --- | --- | --- | --- |
| [#1415](https://github.com/waaseyaa/framework/issues/1415) [admin-spa] M5 residual: AI observability dashboard + AI pipeline inspector (MCP endpoint admin + Mercure broadcast monitor already shipped) | `admin` | Future feature | Retain future backlog; design before implementation |
| [#1578](https://github.com/waaseyaa/framework/issues/1578) [notification] Delivery log + channel enable/disable + 2-tab notifications admin | `notification` | Future feature | Retain future backlog; design before implementation |
| [#1579](https://github.com/waaseyaa/framework/issues/1579) [admin-spa] M4A-5b: Workflow guard editing UI + persistence design | `admin` | Future feature | Retain future backlog; design before implementation |
| [#1588](https://github.com/waaseyaa/framework/issues/1588) [mission-rework] database-legacy-retirement-01KSEFV2 — must migrate consumers before deletion | `database-legacy` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1605](https://github.com/waaseyaa/framework/issues/1605) DX: distinguish unauthorized-from-empty over JSON:API — signal when an access policy filters a collection to empty (already fail-closed, no leak) | `api` | Decision | Settle owning contract before implementation |
| [#1606](https://github.com/waaseyaa/framework/issues/1606) ai-vector remains non-turnkey: vector.search is unwirable and passage provenance is undefined | `ai-vector` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1607](https://github.com/waaseyaa/framework/issues/1607) OpenAiCompatibleProvider has no tool/function calling; #[AsAgentDefinition] tools only work with AnthropicProvider | `ai-agent` | Decision | Settle owning contract before implementation |
| [#1608](https://github.com/waaseyaa/framework/issues/1608) Agent endpoint surfaces NullLlmProvider as a success-shaped run — return 'no model configured' instead of a 200 placeholder (boot warning shipped) | `ai-agent` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1609](https://github.com/waaseyaa/framework/issues/1609) #[ContentEntityType] does not register an entity; you must also add it to config/entity-types.php | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1623](https://github.com/waaseyaa/framework/issues/1623) Policy DI now resolves register() bindings + degrades nullable deps, but discovered #[PolicyAttribute] policies are still eager-at-boot and an unbound required ctor dep is a whole-kernel fatal — make lazy or non-fatal diagnostic | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1624](https://github.com/waaseyaa/framework/issues/1624) Notifications: no first-party Mercure channel, and NotificationDispatcher channels are build-time only | `notification` | Decision | Settle owning contract before implementation |
| [#1625](https://github.com/waaseyaa/framework/issues/1625) schema:check VARCHAR(n) false-positive: typesCompatible() still doesn't strip the length suffix + audit_* migrations still emit VARCHAR(n) (oidc_client base cols partially fixed) — strip suffix in comparator + emit TEXT in audit migrations | `database-legacy` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1626](https://github.com/waaseyaa/framework/issues/1626) Inertia on-ramp residuals: align page payload with stock data-page mount-node attribute + ship a starter vite.config (publicDir:false) — asset-base + configurable bundle/entry landed | `inertia` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1627](https://github.com/waaseyaa/framework/issues/1627) groups: reconcile membership roles and lifecycle with shipped relationship primitives | `groups` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1629](https://github.com/waaseyaa/framework/issues/1629) Docs: entity-modeling guidance — relationship-vs-FK, reserved-word type ids, per-vendor taxonomy scoping | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1631](https://github.com/waaseyaa/framework/issues/1631) No app hook to server-render <head> meta (OpenGraph/SEO): provider middleware is pre-controller; app can't override the Inertia renderer | `inertia` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1633](https://github.com/waaseyaa/framework/issues/1633) structured-import is GFM single-entity (prompt→value), not a multi-row CSV importer | `structured-import` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1639](https://github.com/waaseyaa/framework/issues/1639) enhancement(ai-tools/mcp): add a media upload agent tool | `ai-tools` | Future feature | Retain future backlog; design before implementation |
| [#1640](https://github.com/waaseyaa/framework/issues/1640) OAuth 2.1 resource-server auth for the MCP endpoint (RFC 9728 / resource indicators / PKCE) | `mcp` | Decision | Settle owning contract before implementation |
| [#1658](https://github.com/waaseyaa/framework/issues/1658) EntityRepositoryInterface growth breaks consumer in-memory test doubles; ship a reusable base | `testing` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1687](https://github.com/waaseyaa/framework/issues/1687) downstream: composer require waaseyaa/framework:<exact> writes an exact pin that blocks point upgrades | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1742](https://github.com/waaseyaa/framework/issues/1742) Media versioning/CAS trigger is dormant + pending-upload store is per-request in-memory (latent data loss) | `media` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1762](https://github.com/waaseyaa/framework/issues/1762) Feature: build the media source-plugin substrate + authorized media download | `media` | Decision | Settle owning contract before implementation |
| [#1861](https://github.com/waaseyaa/framework/issues/1861) cache: qualify and repair revision-pointer invalidation through production wiring | `cache` | Qualification | Qualify named boundary; reuse existing evidence |
| [#1866](https://github.com/waaseyaa/framework/issues/1866) Lift ServiceProvider::routes() signature to a routing-owned interface (L0→L4/L1 type-hint coupling baselined in WP7) | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#1957](https://github.com/waaseyaa/framework/issues/1957) groups: membership management surface — admin SPA UI + API exposure (CW-v1 WP-4 follow-up) | `groups` | Future feature | Retain future backlog; design before implementation |
| [#2054](https://github.com/waaseyaa/framework/issues/2054) proposal: first-class chat surface for Waaseyaa applications | `messaging` | Future feature | Retain future backlog; design before implementation |
| [#2055](https://github.com/waaseyaa/framework/issues/2055) C-22 dead-code gate missed distribution repos; dormant getStorage() seam fails silently | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2123](https://github.com/waaseyaa/framework/issues/2123) Asset-manifest helper for consumer public assets | `ssr` | Future feature | Retain future backlog; design before implementation |
| [#2132](https://github.com/waaseyaa/framework/issues/2132) Rich-text safety: unsanitized fields.*.raw twin, sanitizer not injectable, widget/field-type coupling unenforced, CSP not configurable | `admin` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2159](https://github.com/waaseyaa/framework/issues/2159) Anonymous published reads return no data when a content-group entity's status field is not Protected+authorizationInput | `access` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2169](https://github.com/waaseyaa/framework/issues/2169) GraphQL schema reveals entity type names for types whose rows are fully access-denied (non-blocking) | `graphql` | Decision | Settle owning contract before implementation |
| [#2182](https://github.com/waaseyaa/framework/issues/2182) Admin media library shows file URIs instead of image thumbnails | `admin` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2229](https://github.com/waaseyaa/framework/issues/2229) Standardize the native specification contract | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2342](https://github.com/waaseyaa/framework/issues/2342) feat: add reusable Open Graph package and build-time validation | `seo` | Future feature | Retain future backlog; design before implementation |
| [#2432](https://github.com/waaseyaa/framework/issues/2432) Destructive configuration import (config:import --delete-orphans) has no authorization policy | `config` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2433](https://github.com/waaseyaa/framework/issues/2433) Configuration rollback and candidate sweep have no production authorization policy | `config` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2440](https://github.com/waaseyaa/framework/issues/2440) Admin/Auth: publish the complete account UI without copying backend security logic | `admin` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2441](https://github.com/waaseyaa/framework/issues/2441) DX: ship executable recipes for safe auth and account customization | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2442](https://github.com/waaseyaa/framework/issues/2442) site:init: offer minimal and editorial initialization profiles without forking Framework internals | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2443](https://github.com/waaseyaa/framework/issues/2443) Acceptance: prove the complete account lifecycle in a fresh generated application | `framework` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2447](https://github.com/waaseyaa/framework/issues/2447) Assess PostgreSQL readiness after Sheguiandah alpha field evidence | `database-legacy` | Decision | Settle owning contract before implementation |
| [#2448](https://github.com/waaseyaa/framework/issues/2448) Assess MySQL/MariaDB readiness after the PostgreSQL portability assessment | `database-legacy` | Decision | Settle owning contract before implementation |
| [#2449](https://github.com/waaseyaa/framework/issues/2449) Define the Waaseyaa beta support contract from alpha field and portability evidence | `framework` | Decision | Settle owning contract before implementation |
| [#2479](https://github.com/waaseyaa/framework/issues/2479) Audit environment, bootstrap-configuration, and secret-policy ownership across Framework, Anokii, and Sheg | `foundation` | Decision | Settle owning contract before implementation |
| [#2498](https://github.com/waaseyaa/framework/issues/2498) deployer: add a consistent SQLite artifact snapshot seam (VACUUM INTO) to RuntimeState | `deployer` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2499](https://github.com/waaseyaa/framework/issues/2499) foundation/upgrade: add a canonical observation digest and versioned preflight evidence envelope | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2525](https://github.com/waaseyaa/framework/issues/2525) Delivery: add stale-base detection, bounded landing queues, and exact merge handoffs | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2527](https://github.com/waaseyaa/framework/issues/2527) Program: remove recurring delivery friction across Framework, Sheg, and Anokii | `framework` | Program | Coordinate retained children/evidence |
| [#2545](https://github.com/waaseyaa/framework/issues/2545) docs(config): define the authority contract for cloned databases | `config` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2548](https://github.com/waaseyaa/framework/issues/2548) fix(deployer): preserve interdependent runtime schemas across artifact handoff | `deployer` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2549](https://github.com/waaseyaa/framework/issues/2549) fix(deployer): merge user identities by stable UUID, not numeric primary key alone | `deployer` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2640](https://github.com/waaseyaa/framework/issues/2640) Kernel body-size refusal preempts three transport-guard answers on MCP routes | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2650](https://github.com/waaseyaa/framework/issues/2650) release: replace the root framework dist with a governed allowlist and size budget | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2653](https://github.com/waaseyaa/framework/issues/2653) Program: ship Waaseyaa's AI-first local development plane | `framework` | Program | Coordinate retained children/evidence |
| [#2660](https://github.com/waaseyaa/framework/issues/2660) dx: generate equivalent guidelines and Agent Skills for supported coding clients | `bimaaji` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2661](https://github.com/waaseyaa/framework/issues/2661) specs: add lifecycle metadata and compile a sanitized versioned agent corpus | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2662](https://github.com/waaseyaa/framework/issues/2662) ai-docs: ship cited version-matched documentation search with SQLite FTS5 | `bimaaji` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2663](https://github.com/waaseyaa/framework/issues/2663) dx: generate portable project-local MCP descriptors and machine-safe client configuration | `bimaaji` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2664](https://github.com/waaseyaa/framework/issues/2664) foundation: orchestrate project:init, project upgrades, and AI generated-state verification | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2665](https://github.com/waaseyaa/framework/issues/2665) acceptance: prove the AI-first journey from packaged fresh and upgraded applications | `framework` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2666](https://github.com/waaseyaa/framework/issues/2666) developer-tools: define sovereignty-aware logs, last-error, URL, and schema-shape diagnostics | `bimaaji` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2667](https://github.com/waaseyaa/framework/issues/2667) roadmap: restore Framework Project synchronization or retire its all-open-issues claim | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2670](https://github.com/waaseyaa/framework/issues/2670) Driver write() takes row identity twice and no driver reconciles a divergent $values id | `entity-storage` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2676](https://github.com/waaseyaa/framework/issues/2676) Program: establish native Windows and Linux parity across Waaseyaa | `framework` | Program | Coordinate retained children/evidence |
| [#2678](https://github.com/waaseyaa/framework/issues/2678) CI: require native Windows and Linux contract coverage | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2680](https://github.com/waaseyaa/framework/issues/2680) Prove AI-first local development parity on native Windows and Linux | `framework` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2681](https://github.com/waaseyaa/framework/issues/2681) Qualify packaged-consumer parity on native Windows and Linux | `framework` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2686](https://github.com/waaseyaa/framework/issues/2686) bimaaji: decide ownership of the shared root AGENTS.md path across client transformers | `bimaaji` | Decision | Settle owning contract before implementation |
| [#2687](https://github.com/waaseyaa/framework/issues/2687) Windows waaseyaa dev FrankenPHP binary omits ext-sodium | `frankenphp` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2693](https://github.com/waaseyaa/framework/issues/2693) test(bimaaji): prove the file-level sandbox target guard on Windows, or record why it cannot be | `bimaaji` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2697](https://github.com/waaseyaa/framework/issues/2697) Centralize production auth controller construction | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2699](https://github.com/waaseyaa/framework/issues/2699) Provide a secure local auth-token delivery mechanism | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2702](https://github.com/waaseyaa/framework/issues/2702) arch(skeleton): decide the production container topology — FPM compatibility vs a self-serving FrankenPHP default | `foundation` | Decision | Settle owning contract before implementation |
| [#2707](https://github.com/waaseyaa/framework/issues/2707) CI: handle bot-authored PR workflow approvals and reconcile check reporting | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2729](https://github.com/waaseyaa/framework/issues/2729) ContentEntityBase never receives the process EntityTypeManager, so every entity reports its type as non-translatable | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2733](https://github.com/waaseyaa/framework/issues/2733) PRE_DELETE guard refusals are an untyped RuntimeException no caller can map (500s; blocked retention purges) | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2741](https://github.com/waaseyaa/framework/issues/2741) Queue claim settlement is not fenced against an obsolete worker after reclaim | `queue` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2743](https://github.com/waaseyaa/framework/issues/2743) Queue retry claims survive dead processes without a recoverable handoff outcome | `queue` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2745](https://github.com/waaseyaa/framework/issues/2745) Persistent async notifications lose recipient routing for mail delivery | `notification` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2747](https://github.com/waaseyaa/framework/issues/2747) BroadcastStorage retained writes leak live messages on failure and retry | `api` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2750](https://github.com/waaseyaa/framework/issues/2750) billing webhook claim can suppress retries before durable effects complete | `billing` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2751](https://github.com/waaseyaa/framework/issues/2751) converge analytics and GitHub clients on the shared HTTP transport authority | `http-client` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2756](https://github.com/waaseyaa/framework/issues/2756) contract(engagement): decide integrity and lifecycle for polymorphic targets | `engagement` | Decision | Settle owning contract before implementation |
| [#2759](https://github.com/waaseyaa/framework/issues/2759) architecture(media): consolidate provider and HTTP upload policy authority | `media` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2762](https://github.com/waaseyaa/framework/issues/2762) contract(groups): make membership and content-assignment identity atomic | `groups` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2763](https://github.com/waaseyaa/framework/issues/2763) architecture(search): make projection failures observable and recoverable | `search` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2765](https://github.com/waaseyaa/framework/issues/2765) design(deployer): make SQLite activation crash-recoverable and directory-durable | `deployer` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2768](https://github.com/waaseyaa/framework/issues/2768) C-22 left translation runtime split: repository hydration omits translations and the public contract targets a deleted engine | `entity-storage` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2769](https://github.com/waaseyaa/framework/issues/2769) security(auth): move public verification resend behind a durable mail outbox | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2775](https://github.com/waaseyaa/framework/issues/2775) fix(auth): make reset and invite token spending atomic and predicate-complete | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2782](https://github.com/waaseyaa/framework/issues/2782) release(ai-development): establish governed public package distribution | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2783](https://github.com/waaseyaa/framework/issues/2783) Program: compile governed application blueprints through the canonical site contract | `site-contract` | Program | Coordinate retained children/evidence |
| [#2787](https://github.com/waaseyaa/framework/issues/2787) site:init: compile approved application blueprints through the existing transaction | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2794](https://github.com/waaseyaa/framework/issues/2794) media: return entity-keyed authorized URLs from MediaAssetStore | `media` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2805](https://github.com/waaseyaa/framework/issues/2805) P1: SQLite copy-and-replace ALTER degrades the target table, and a shipped OIDC migration still compares whole schemas | `database-legacy` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2815](https://github.com/waaseyaa/framework/issues/2815) security(entity): add tenantId-scoped declarative tenancy boundary | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2816](https://github.com/waaseyaa/framework/issues/2816) security(auth): bind tenant scope through session and durable bearer authentication | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2817](https://github.com/waaseyaa/framework/issues/2817) security: provide production secret-provider composition for integration credentials | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2818](https://github.com/waaseyaa/framework/issues/2818) queue: define an isolated worker boundary for filesystem, process, network and resources | `queue` | Decision | Settle owning contract before implementation |
| [#2819](https://github.com/waaseyaa/framework/issues/2819) audit: support transaction-joined application lifecycle records | `audit` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2821](https://github.com/waaseyaa/framework/issues/2821) packaging(cli): decouple content and presentation packages from the core CLI closure | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2823](https://github.com/waaseyaa/framework/issues/2823) http: decide the public credential-consuming outbound client boundary | `http-client` | Decision | Settle owning contract before implementation |
| [#2824](https://github.com/waaseyaa/framework/issues/2824) api: decide the public API-versioning boundary for applications | `api` | Decision | Settle owning contract before implementation |
| [#2825](https://github.com/waaseyaa/framework/issues/2825) health: define the public health-check contribution seam | `foundation` | Decision | Settle owning contract before implementation |
| [#2834](https://github.com/waaseyaa/framework/issues/2834) Program: harden governed workflows through Anokii and Sheguiandah field evidence | `framework` | Program | Coordinate retained children/evidence |
| [#2844](https://github.com/waaseyaa/framework/issues/2844) Program: make application generation and CLI scaffolding production-grade | `framework` | Program | Coordinate retained children/evidence |
| [#2847](https://github.com/waaseyaa/framework/issues/2847) cli: converge entity and content-type generators on canonical field metadata | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2848](https://github.com/waaseyaa/framework/issues/2848) cli: generate policy and workflow extensions through canonical governance contracts | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2849](https://github.com/waaseyaa/framework/issues/2849) cli: generate ingestion, search, and seed extensions with companion tests | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2850](https://github.com/waaseyaa/framework/issues/2850) acceptance: qualify application generation across packaged upgrades and supported hosts | `framework` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2851](https://github.com/waaseyaa/framework/issues/2851) Program: gate Framework beta on governed lifecycle integrity | `framework` | Program | Coordinate retained children/evidence |
| [#2859](https://github.com/waaseyaa/framework/issues/2859) kernel: qualify composition authority and boot lifecycle across entrypoints | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2860](https://github.com/waaseyaa/framework/issues/2860) release: make beta eligibility derive from the governed lifecycle gate | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2869](https://github.com/waaseyaa/framework/issues/2869) release-ops: governed delivery telemetry and agent-quality dashboard | `framework` | Program | Coordinate retained children/evidence |
| [#2873](https://github.com/waaseyaa/framework/issues/2873) phpstan: establish a governed level-8 fail-on-new ratchet | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2905](https://github.com/waaseyaa/framework/issues/2905) release: define and enforce signed tag provenance across monorepo and split packages | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2954](https://github.com/waaseyaa/framework/issues/2954) DORA: bind consumer production promotions to delivered application revisions | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2955](https://github.com/waaseyaa/framework/issues/2955) DORA: define incident and deployment-rework evidence with truthful recovery metrics | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2956](https://github.com/waaseyaa/framework/issues/2956) DORA: configure product mappings and qualify end-to-end dashboard inputs | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2961](https://github.com/waaseyaa/framework/issues/2961) packaging: public conformance/IO helpers are unreachable in a packaged consumer (autoload-dev is root-only) | `testing` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2978](https://github.com/waaseyaa/framework/issues/2978) oauth-provider: support identity-only login without email lookup or offline consent | `oauth-provider` | Decision | Settle owning contract before implementation |
| [#2984](https://github.com/waaseyaa/framework/issues/2984) ingestion: consolidate four implementations, correct stale specs, and resolve unmarked public surface | `ingestion` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2985](https://github.com/waaseyaa/framework/issues/2985) audit: trace competing implementations and reconcile framework-wide duplication coverage | `framework` | Program | Coordinate retained children/evidence |
| [#2992](https://github.com/waaseyaa/framework/issues/2992) auth: preserve failed-login throttling under concurrent attempts | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2993](https://github.com/waaseyaa/framework/issues/2993) auth: successful login must not erase unrelated IP failure history | `auth` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#2994](https://github.com/waaseyaa/framework/issues/2994) auth: qualify atomic rate limiter SQL across supported database drivers | `auth` | Qualification | Qualify named boundary; reuse existing evidence |
| [#2997](https://github.com/waaseyaa/framework/issues/2997) entity: qualify translation-write delivery to derived stores and audit consumers | `entity-storage` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3001](https://github.com/waaseyaa/framework/issues/3001) audit: reconcile all 41 antipattern findings into the delivery roadmap | `framework` | Program | Coordinate retained children/evidence |
| [#3002](https://github.com/waaseyaa/framework/issues/3002) events: reconcile documented listener discovery with production ordering | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3003](https://github.com/waaseyaa/framework/issues/3003) mcp: preserve endpoint transport for kernel rate-limit refusals | `mcp` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3004](https://github.com/waaseyaa/framework/issues/3004) routing: qualify explicit access posture across packaged builders and assembled routes | `routing` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3005](https://github.com/waaseyaa/framework/issues/3005) listing: qualify fast-path capability through production gate wiring | `listing` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3006](https://github.com/waaseyaa/framework/issues/3006) pagination: bound deep-page hydration through canonical repository queries | `entity-storage` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3007](https://github.com/waaseyaa/framework/issues/3007) cli: report composed routes and declared access posture in route:list | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3008](https://github.com/waaseyaa/framework/issues/3008) publishing: define supported repository composition for ContentPublisher | `publishing` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3009](https://github.com/waaseyaa/framework/issues/3009) entity-storage: plan responsibility extraction with transaction-preserving evidence | `entity-storage` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3010](https://github.com/waaseyaa/framework/issues/3010) retention: qualify complete and partial scan outcome reporting | `field` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3011](https://github.com/waaseyaa/framework/issues/3011) retention: validate persisted policy decoding before destructive processing | `field` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3012](https://github.com/waaseyaa/framework/issues/3012) entity: surface lifecycle status persistence failures | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3013](https://github.com/waaseyaa/framework/issues/3013) routing: declare domain-router ownership independently of package ordering | `routing` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3014](https://github.com/waaseyaa/framework/issues/3014) security: qualify write-tool error sanitization across shipped tool families | `ai-tools` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3015](https://github.com/waaseyaa/framework/issues/3015) audit: qualify checkpoint sealing and export under overlapping execution | `audit` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3017](https://github.com/waaseyaa/framework/issues/3017) admin: align translated UI keys with supported locale lookup | `admin` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3018](https://github.com/waaseyaa/framework/issues/3018) graphql: qualify bounded query execution and access-aware totals | `graphql` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3019](https://github.com/waaseyaa/framework/issues/3019) mcp: define anonymous rate-limit partitioning and aggregate protection | `mcp` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3020](https://github.com/waaseyaa/framework/issues/3020) mcp: clarify and verify tool side-effect declarations | `mcp` | Decision | Settle owning contract before implementation |
| [#3021](https://github.com/waaseyaa/framework/issues/3021) container: report circular service resolution with a bounded diagnostic | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3022](https://github.com/waaseyaa/framework/issues/3022) entity: document and qualify revision event meanings for pointer movement | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3023](https://github.com/waaseyaa/framework/issues/3023) admin-surface: make required entity identifier validation consistent | `admin-surface` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3024](https://github.com/waaseyaa/framework/issues/3024) entity-storage: quote registered identifiers during translation ID allocation | `entity-storage` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3025](https://github.com/waaseyaa/framework/issues/3025) cli: reconcile cache-clear inventory with configured bins | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3026](https://github.com/waaseyaa/framework/issues/3026) discovery: tolerate concurrent cache-directory creation | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3027](https://github.com/waaseyaa/framework/issues/3027) admin: render pipeline navigation with the registered component name | `admin` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3028](https://github.com/waaseyaa/framework/issues/3028) migration: qualify entity and ID-map transaction connection identity | `migration` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3029](https://github.com/waaseyaa/framework/issues/3029) cli: reconcile dormant revision migration generators with current storage docs | `cli` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3030](https://github.com/waaseyaa/framework/issues/3030) entity-storage: bound revision language-pointer memo lifetime | `entity-storage` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3031](https://github.com/waaseyaa/framework/issues/3031) admin: reuse the canonical CSRF cookie decode contract for uploads | `admin` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3035](https://github.com/waaseyaa/framework/issues/3035) transaction: qualify committed outcomes in remaining CLI/MCP and repository callers | `entity-storage` | Qualification | Qualify named boundary; reuse existing evidence |
| [#3072](https://github.com/waaseyaa/framework/issues/3072) Investigate whether waaseyaa/api should hard-require waaseyaa/media | `api` | Decision | Settle owning contract before implementation |
| [#3073](https://github.com/waaseyaa/framework/issues/3073) Align generated owner roles with admin surface authorization | `admin-surface` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3075](https://github.com/waaseyaa/framework/issues/3075) architecture: adopt Deptrac and retire overlapping hand-written dependency analysis | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3078](https://github.com/waaseyaa/framework/issues/3078) refactor(entity): centralize Node creation timestamp lifecycle | `entity` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3079](https://github.com/waaseyaa/framework/issues/3079) refactor(admin-surface): declare config-entity mutability outside the generic host | `admin-surface` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3082](https://github.com/waaseyaa/framework/issues/3082) refactor(admin-surface): decompose generic host coordination seams | `admin-surface` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3083](https://github.com/waaseyaa/framework/issues/3083) refactor(admin-surface): separate provider routing and static delivery coordination | `admin-surface` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3084](https://github.com/waaseyaa/framework/issues/3084) contract(admin-surface): resolve legacy aggregate contract exports | `admin-surface` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3093](https://github.com/waaseyaa/framework/issues/3093) ci: pin floating ubuntu-latest runners and reconcile action version comments | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3095](https://github.com/waaseyaa/framework/issues/3095) docs: give CI structure an owning spec and route it in CLAUDE.md and the drift detector | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3097](https://github.com/waaseyaa/framework/issues/3097) ci: retain JUnit from the random-order shards so unique-detection can be measured | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3099](https://github.com/waaseyaa/framework/issues/3099) chore: WSL ~/dev consolidation record — framework, studio, anokii (2026-09-18) | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3100](https://github.com/waaseyaa/framework/issues/3100) Unify upload-size policy and runtime limits with a usable 10 MiB file default | `media` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3102](https://github.com/waaseyaa/framework/issues/3102) foundation DatabaseRateLimiter is read-then-write while the auth limiter is atomic; RateLimiterInterface does not say which | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3103](https://github.com/waaseyaa/framework/issues/3103) queue: UniqueJob and RateLimited attributes are silently unenforced under DbalQueue (cross-process) | `queue` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3104](https://github.com/waaseyaa/framework/issues/3104) database: untyped query parameters bind integers as text; SQLite expression comparisons against them are always true | `database-legacy` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3109](https://github.com/waaseyaa/framework/issues/3109) media: authorized delivery cannot name a non-account owner; private files still addressed as public:// | `media` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3110](https://github.com/waaseyaa/framework/issues/3110) schema authority: tables created via schema()->createTable() outside the coordinator refuse every later transition (S1-DB109); no non-CLI adoption path | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3116](https://github.com/waaseyaa/framework/issues/3116) SSR page fallback renders any root template — including the framework's own home/page/entity/403/404/500 — as a 200 page at /{segment} | `ssr` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3117](https://github.com/waaseyaa/framework/issues/3117) Program: converge waaseyaa/cli package boundaries, command contracts, and distribution | `cli` | Program | Coordinate retained children/evidence |
| [#3118](https://github.com/waaseyaa/framework/issues/3118) Program: audit and converge the Framework package set | `framework` | Program | Coordinate retained children/evidence |
| [#3120](https://github.com/waaseyaa/framework/issues/3120) docs(skeleton): document and verify the development-to-production activation path | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3122](https://github.com/waaseyaa/framework/issues/3122) Program: trustworthy application graph and route composition for packaged consumers | `framework` | Program | Coordinate retained children/evidence |
| [#3123](https://github.com/waaseyaa/framework/issues/3123) Audit: converge waaseyaa/foundation contracts, composition lifecycle, and distribution | `foundation` | Program | Coordinate retained children/evidence |
| [#3124](https://github.com/waaseyaa/framework/issues/3124) Audit: converge waaseyaa/bimaaji graph contracts, mutation semantics, and distribution | `bimaaji` | Program | Coordinate retained children/evidence |
| [#3125](https://github.com/waaseyaa/framework/issues/3125) Audit: converge waaseyaa/routing composition, metadata, and installed contracts | `routing` | Program | Coordinate retained children/evidence |
| [#3129](https://github.com/waaseyaa/framework/issues/3129) fix(ci): align split-main job timeout with authoritative CI wait | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3130](https://github.com/waaseyaa/framework/issues/3130) fix(ci): diagnose random-order shard hang with replayable progress evidence | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3134](https://github.com/waaseyaa/framework/issues/3134) maintainer skills: deterministic precedence when repository and installed copies share a name | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3137](https://github.com/waaseyaa/framework/issues/3137) ai-vector: converge the package after the #3135 audit (remediation umbrella) | `ai-vector` | Program | Coordinate retained children/evidence |
| [#3143](https://github.com/waaseyaa/framework/issues/3143) ai-vector: bound the cost of public semantic search | `ai-vector` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3156](https://github.com/waaseyaa/framework/issues/3156) delivery governance: preserve reviewed provenance when updating PR branches from main | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3164](https://github.com/waaseyaa/framework/issues/3164) No supported way to rotate the application master; manual rotation leaves audit checkpoints unverifiable | `audit` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3171](https://github.com/waaseyaa/framework/issues/3171) delivery: narrow conservative preflight selectors | `framework` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3181](https://github.com/waaseyaa/framework/issues/3181) site:doctor: tracked Migration DDL is misclassified as runtime schema creation | `site-contract` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3184](https://github.com/waaseyaa/framework/issues/3184) Closed User reader lacks batch active-status API, causing seconds of strict audit writes in directory reads | `user` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3197](https://github.com/waaseyaa/framework/issues/3197) Specify shared social capabilities and cross-package lifecycle contracts | `framework` | Program | Coordinate retained children/evidence |
| [#3198](https://github.com/waaseyaa/framework/issues/3198) foundation: preserve safe exception diagnostics when trace keywords trigger whole-message redaction | `foundation` | Repair / revalidate | Revalidate current acceptance; repair under existing owner |
| [#3199](https://github.com/waaseyaa/framework/issues/3199) J1: audit and qualify clean installation through the first homepage request | `framework` | Program | Start J1 baseline |

## Verification boundary

The per-issue label plan records exact before/after labels and rationale for
review before mutation. Apply the existing board synchronizer only after those
issue changes, review its bound plan, revalidate immediately before apply, and
retain its receipt. Post-apply audit must report no missing items, priority gaps,
ambiguous readiness or mirror mismatches. Manual success does not close #2667.
Runtime reproduction, package convergence and release eligibility remain separate.
