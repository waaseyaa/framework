# Framework issue coverage for audit #2985

This is issue-body inventory triage, not a completed source audit. No tests, runtime audit, GitHub mutations, or source changes were performed. Every quoted body remains a lead until checked against a pinned source and dependency cohort.

## Coverage

```json
{
  "total_issues": 128,
  "original_issues_excluding_2985": 127,
  "primary_assignments": 128,
  "duplicate_primary_assignments": 0,
  "unassigned_issues": 0,
  "source_confirmed_findings": 0,
  "by_relevance": {
    "program": 12,
    "explicit-existing-finding": 17,
    "candidate": 82,
    "unrelated-feature": 17
  },
  "by_evidence_bucket": {
    "potential-related-or-coordination": 94,
    "direct-duplication-orphan-or-inaccessible-surface-claim": 17,
    "no-current-evidence": 17
  }
}
```

Counts describe issues, not distinct defects. Direct claims include public-surface/distribution defects; duplicate data/events alone are not classified as duplicate implementations.

## Ownership and disjoint review boundaries

### generation-scaffolding (10 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Generator entrypoints, field vocabulary, artifact-plan writers and starter compilation; exclude ingestion engine internals owned by ingestion-validation.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### identity-access-workflows (17 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Identity establishment, token/membership/access/workflow policy contracts; exclude media storage and queue settlement implementations.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### ingestion-validation (2 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Ingestion commands, envelopes, validators and entity-writing adapters; reuse 2984 evidence ownership, exclude generated adapter scaffolds.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### bootstrap-provider-discovery (8 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Kernel lifecycle, discovery, DI/provider composition and environment contracts; domain owners trace their services after registration.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### entity-database-search-cache (15 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Generic entity/database schemas, translation, search and cache; exclude media/CAS/upload paths reserved for media lane.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### media-upload-file-storage (6 issues)
Owner/status: user-Claude / worker-source-audit-reported-awaiting-independent-review.

Upload handlers, media source/CAS/versioning, authorized delivery and derivatives; no ingestion-pipeline implementation or generic database audit.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### http-api-integration-transport (7 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Shared HTTP transport, API/MCP/GraphQL request and response contracts and integration adapters; exclude credential state machines.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### async-delivery (9 issues)
Owner/status: user-Claude / assigned-pending-actual-start (queue/scheduler/notification only; other async paths unassigned).

Queue settlement, notification/broadcast/messaging/billing delivery and transactional effects; no identity authorization redesign.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### ai-developer-plane (13 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Agent execution, model/retrieval tools, client configuration adapters and local developer integration; specification lifecycle owned by governance.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### packaging-native-portability (13 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Package exports/autoload/consumer dependency closure and native runtime/tool portability; no domain behavior rewrites.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### release-delivery-operations (17 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Release, promotion, installation, qualification and delivery telemetry authority; generic persistence schema internals remain storage-owned.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### presentation-frontend-ssr (6 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

SSR/client presentation, rich text, head/assets and scaffolding presentation contracts; no credential or media authorization implementation.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

### audit-specification-governance (5 issues)
Owner/status: Unassigned / inventory-only-not-dispatched.

Audit/specification lifecycle, coverage and static-analysis policy; no duplicate capability source audit or implementation.

Output: call-path and registration matrix, compatibility inventory, source-pinned findings and focused proof proposals, with explicit unreviewed areas. Cross-lanes consult shared contracts; the primary lane owns the trace and finding to avoid repeat audits.

## Reconciliation and limitations

- Closed 2724 and 2719 are historical audit references, not rows in the 128-open-issue baseline. Their closure does not prove current capability coverage.
- 2984 remains the existing ingestion finding owner. 2985 coordinates coverage and revalidation; it must not recreate those four ingestion findings. Generator 2849 consumes the ingestion outcome rather than owning the ingestion audit.
- Umbrella rows coordinate child work rather than count additional defects. Counts below are issue classifications, never a distinct defect total.
- Older membership absence claims in 1627/1957 conflict with the newer 2762 description of GroupMembershipService; resolve source/cohort differences before creating replacement primitives or closing tickets.
- Media worker reports a source-audit checkpoint at f491f02d84287dd7297f83f175b9de07d00ac0b0, awaiting independent review with explicit scope gaps. This does not upgrade inventory rows to source-confirmed. User-Claude next reserved queue/scheduler/notification, pending actual start; historical 2822 is not added to the open baseline.

## Every open issue, one primary assignment

| Issue | Primary lane | Classification | Body-grounded reason |
|---|---|---|---|
| [#2985](https://github.com/waaseyaa/framework/issues/2985) audit: trace competing implementations and reconcile framework-wide duplication coverage | audit-specification-governance | program | Umbrella requires revalidation of historical audits and complete capability coverage; existing ingestion findings remain separately owned. |
| [#2984](https://github.com/waaseyaa/framework/issues/2984) ingestion: consolidate four implementations, correct stale specs, and resolve unmarked public surface | ingestion-validation | explicit-existing-finding | Reports four ingestion paths, validators without callers, and advertised extension surfaces missing from distribution; reconcile its existing four-root inventory rather than open duplicate findings. |
| [#2981](https://github.com/waaseyaa/framework/issues/2981) site-contract: ship a governed community-events starter for Studio MVP | generation-scaffolding | candidate | Community-events starter must use the canonical parser/compiler rather than relabel the editorial fixture or introduce a Studio compiler. |
| [#2978](https://github.com/waaseyaa/framework/issues/2978) oauth-provider: support identity-only login without email lookup or offline consent | identity-access-workflows | candidate | Published providers request email/offline access for identity-only login; supported provider options should avoid application-local URL forks. |
| [#2961](https://github.com/waaseyaa/framework/issues/2961) packaging: public conformance/IO helpers are unreachable in a packaged consumer (autoload-dev is root-only) | packaging-native-portability | explicit-existing-finding | Reports declared public conformance and IO classes hidden behind package autoload-dev and unavailable to packaged consumers. |
| [#2956](https://github.com/waaseyaa/framework/issues/2956) DORA: configure product mappings and qualify end-to-end dashboard inputs | release-delivery-operations | unrelated-feature | Missing DevLake project deployment/incident mapping is delivery-data configuration, not evidence of competing runtime implementations. |
| [#2955](https://github.com/waaseyaa/framework/issues/2955) DORA: define incident and deployment-rework evidence with truthful recovery metrics | release-delivery-operations | unrelated-feature | Requests deployment-linked incident evidence; missing incident data is not proof of zero failures or duplicated code. |
| [#2954](https://github.com/waaseyaa/framework/issues/2954) DORA: bind consumer production promotions to delivered application revisions | release-delivery-operations | candidate | Promotion adapter lacks application revision lineage; inspect its producer contract before extending telemetry. |
| [#2905](https://github.com/waaseyaa/framework/issues/2905) release: define and enforce signed tag provenance across monorepo and split packages | release-delivery-operations | unrelated-feature | Unsigned release tags need a signing trust chain; this does not establish a duplicate or orphaned implementation. |
| [#2873](https://github.com/waaseyaa/framework/issues/2873) phpstan: establish a governed level-8 fail-on-new ratchet | audit-specification-governance | candidate | Static-analysis baseline and ratchet changes may expose drift, but the requested rule level is not itself a source finding. |
| [#2869](https://github.com/waaseyaa/framework/issues/2869) release-ops: governed delivery telemetry and agent-quality dashboard | release-delivery-operations | program | Delivery telemetry program consolidates facts from GitHub and agents while retaining an authoritative event ledger. |
| [#2860](https://github.com/waaseyaa/framework/issues/2860) release: make beta eligibility derive from the governed lifecycle gate | release-delivery-operations | candidate | Body identifies inconsistent beta eligibility and obsolete support claims; compare release criteria authorities rather than add another gate. |
| [#2859](https://github.com/waaseyaa/framework/issues/2859) kernel: qualify composition authority and boot lifecycle across entrypoints | bootstrap-provider-discovery | explicit-existing-finding | Reports independently composed kernel services/process globals and lifecycle discrepancies between retry behavior and documented provider overrides. |
| [#2851](https://github.com/waaseyaa/framework/issues/2851) Program: gate Framework beta on governed lifecycle integrity | release-delivery-operations | program | Beta lifecycle umbrella coordinates child authority and evidence work; avoid recounting its child findings as new defects. |
| [#2850](https://github.com/waaseyaa/framework/issues/2850) acceptance: qualify application generation across packaged upgrades and supported hosts | generation-scaffolding | candidate | Packaged generator acceptance explicitly checks parallel writers, field vocabulary, upgrades and native hosts; these are audit targets, not completed proof. |
| [#2849](https://github.com/waaseyaa/framework/issues/2849) cli: generate ingestion, search, and seed extensions with companion tests | generation-scaffolding | candidate | Existing ingest/reindex commands do not provide canonical generated application extensions; generation work depends on ingestion contract reconciliation. |
| [#2848](https://github.com/waaseyaa/framework/issues/2848) cli: generate policy and workflow extensions through canonical governance contracts | generation-scaffolding | candidate | Policy stubs and workflow descriptive output need canonical executable governance registration and default-deny behavior. |
| [#2847](https://github.com/waaseyaa/framework/issues/2847) cli: converge entity and content-type generators on canonical field metadata | generation-scaffolding | explicit-existing-finding | Reports overlapping entity/content-type generators with different write semantics and a private hard-coded field map. |
| [#2844](https://github.com/waaseyaa/framework/issues/2844) Program: make application generation and CLI scaffolding production-grade | generation-scaffolding | program | Generation umbrella owns convergence through child issues, including the concrete generator overlap tracked by 2847. |
| [#2834](https://github.com/waaseyaa/framework/issues/2834) Program: harden governed workflows through Anokii and Sheguiandah field evidence | identity-access-workflows | program | Governed-workflow program seeks consumer-backed hardening without inventing a parallel workflow engine. |
| [#2825](https://github.com/waaseyaa/framework/issues/2825) health: define the public health-check contribution seam | bootstrap-provider-discovery | candidate | Health checker is kernel-bound and CLI-consumed, but application contribution has no declared public composition seam. |
| [#2824](https://github.com/waaseyaa/framework/issues/2824) api: decide the public API-versioning boundary for applications | http-api-integration-transport | unrelated-feature | API version negotiation is an unresolved feature/policy boundary; the body does not establish a competing implementation. |
| [#2823](https://github.com/waaseyaa/framework/issues/2823) http: decide the public credential-consuming outbound client boundary | http-api-integration-transport | candidate | Domain-specific HTTP/secret consumers exist while a general public integration seam is missing; compare contracts before extraction. |
| [#2821](https://github.com/waaseyaa/framework/issues/2821) packaging(cli): decouple content and presentation packages from the core CLI closure | packaging-native-portability | candidate | CLI distribution pulls content/presentation packages that core commands do not need; trace dependency closure before splitting packages. |
| [#2819](https://github.com/waaseyaa/framework/issues/2819) audit: support transaction-joined application lifecycle records | entity-database-search-cache | candidate | Best-effort audit and strict audit ledger have different transactional promises; determine how application mutation can join the latter. |
| [#2818](https://github.com/waaseyaa/framework/issues/2818) queue: define an isolated worker boundary for filesystem, process, network and resources | async-delivery | candidate | Worker isolation adds filesystem/process/network authority boundaries distinct from existing queue settlement issues. |
| [#2817](https://github.com/waaseyaa/framework/issues/2817) security: provide production secret-provider composition for integration credentials | bootstrap-provider-discovery | candidate | Secret-provider SPI lacks a production composition implementation; distinguish an intentional refusal default from an orphaned service. |
| [#2816](https://github.com/waaseyaa/framework/issues/2816) security(auth): bind tenant scope through session and durable bearer authentication | identity-access-workflows | candidate | Server-side tenant selection lacks a validated membership binding between request attributes and durable identity. |
| [#2815](https://github.com/waaseyaa/framework/issues/2815) security(entity): add tenantId-scoped declarative tenancy boundary | entity-database-search-cache | candidate | Tenant principal identity and entity storage scope are not a single enforced declarative boundary. |
| [#2805](https://github.com/waaseyaa/framework/issues/2805) P1: SQLite copy-and-replace ALTER degrades the target table, and a shipped OIDC migration still compares whole schemas | entity-database-search-cache | candidate | SQLite copy/replace alteration and Doctrine introspection can lose real schema constraints; compare represented and installed schema. |
| [#2794](https://github.com/waaseyaa/framework/issues/2794) media: return entity-keyed authorized URLs from MediaAssetStore | media-upload-file-storage | candidate | Media catalog identity and public URL/content hash do not establish authorized delivery for each policy-bearing media row. |
| [#2787](https://github.com/waaseyaa/framework/issues/2787) site:init: compile approved application blueprints through the existing transaction | generation-scaffolding | candidate | Approved blueprint compilation must reuse existing artifact rendering and initialization transactions rather than create a second compiler. |
| [#2783](https://github.com/waaseyaa/framework/issues/2783) Program: compile governed application blueprints through the canonical site contract | generation-scaffolding | program | Blueprint program also flags weak or unconsumed AI schema output; use child ownership for implementation and retain the schema lead. |
| [#2782](https://github.com/waaseyaa/framework/issues/2782) release(ai-development): establish governed public package distribution | packaging-native-portability | candidate | Implemented package split is not available through a published package/release; distinguish source presence from supported consumer availability. |
| [#2775](https://github.com/waaseyaa/framework/issues/2775) fix(auth): make reset and invite token spending atomic and predicate-complete | identity-access-workflows | explicit-existing-finding | Reports reset/invitation token flows retaining legacy consumption while verified email uses a predicate-complete consumption path. |
| [#2769](https://github.com/waaseyaa/framework/issues/2769) security(auth): move public verification resend behind a durable mail outbox | identity-access-workflows | candidate | Uniform resend responses still expose synchronous transport timing; an outbox boundary needs review without conflating response shape and execution. |
| [#2768](https://github.com/waaseyaa/framework/issues/2768) C-22 left translation runtime split: repository hydration omits translations and the public contract targets a deleted engine | entity-database-search-cache | explicit-existing-finding | Reports translation contract split after storage-engine removal, missing repository hydration, and a pending-deletion queue without callers. |
| [#2765](https://github.com/waaseyaa/framework/issues/2765) design(deployer): make SQLite activation crash-recoverable and directory-durable | release-delivery-operations | candidate | Two filesystem renames provide in-process rollback but not the crash-recovery durability claimed by installation documentation. |
| [#2763](https://github.com/waaseyaa/framework/issues/2763) architecture(search): make projection failures observable and recoverable | entity-database-search-cache | candidate | Search subscriber can hide projection failure with a null logger and lazy schema creation; trace actual provider composition. |
| [#2762](https://github.com/waaseyaa/framework/issues/2762) contract(groups): make membership and content-assignment identity atomic | identity-access-workflows | candidate | Membership find-then-create lacks uniqueness despite idempotency claims; duplicate rows are not duplicate implementations. |
| [#2759](https://github.com/waaseyaa/framework/issues/2759) architecture(media): consolidate provider and HTTP upload policy authority | media-upload-file-storage | explicit-existing-finding | Reports provider upload configuration and router-created upload handlers using different configuration keys and authority paths. |
| [#2756](https://github.com/waaseyaa/framework/issues/2756) contract(engagement): decide integrity and lifecycle for polymorphic targets | entity-database-search-cache | candidate | Engagement can write a nonexistent target before reads deny it; the body calls for a policy decision rather than asserting a new parallel engine. |
| [#2753](https://github.com/waaseyaa/framework/issues/2753) messaging creator membership is non-atomic and broken on in-memory storage | async-delivery | candidate | Messaging post-save effects are not atomic with persistence; orphaned data and memory-driver proof limits need transactional review. |
| [#2751](https://github.com/waaseyaa/framework/issues/2751) converge analytics and GitHub clients on the shared HTTP transport authority | http-api-integration-transport | explicit-existing-finding | Reports analytics and GitHub integrations maintaining private stream transports instead of the shared HTTP client, with divergent error semantics. |
| [#2750](https://github.com/waaseyaa/framework/issues/2750) billing webhook claim can suppress retries before durable effects complete | async-delivery | candidate | Webhook claims can acknowledge before effects and provider composition omits the claim callback; repeated events are not proof of duplicate engines. |
| [#2747](https://github.com/waaseyaa/framework/issues/2747) BroadcastStorage retained writes leak live messages on failure and retry | async-delivery | candidate | Retained broadcast writes can fail after publishing a live event; the body supplies historical partial-effect evidence requiring current revalidation. |
| [#2745](https://github.com/waaseyaa/framework/issues/2745) Persistent async notifications lose recipient routing for mail delivery | async-delivery | candidate | Async recipient reconstruction loses mail routing available in synchronous notification delivery. |
| [#2743](https://github.com/waaseyaa/framework/issues/2743) Queue retry claims survive dead processes without a recoverable handoff outcome | async-delivery | candidate | Retry claim state can remain stranded after worker death between enqueue and deletion; examine ownership and handoff semantics. |
| [#2741](https://github.com/waaseyaa/framework/issues/2741) Queue claim settlement is not fenced against an obsolete worker after reclaim | async-delivery | candidate | Identifier-only settlement can let an obsolete worker settle a reclaimed job; review fencing rather than replace the queue wholesale. |
| [#2733](https://github.com/waaseyaa/framework/issues/2733) PRE_DELETE guard refusals are an untyped RuntimeException no caller can map (500s; blocked retention purges) | entity-database-search-cache | candidate | Retention delete guards throw errors that are not consistently mapped through HTTP and retention paths. |
| [#2729](https://github.com/waaseyaa/framework/issues/2729) ContentEntityBase never receives the process EntityTypeManager, so every entity reports its type as non-translatable | bootstrap-provider-discovery | explicit-existing-finding | Reports no production call to ContentEntityBase entity-type-manager injection while fallback translation behavior contradicts registered types. |
| [#2708](https://github.com/waaseyaa/framework/issues/2708) StreamHttpClient returns truncated response bodies as successful responses | http-api-integration-transport | candidate | HTTP byte-limit truncation can return a successful status with a partial body; inspect failure semantics at the shared transport boundary. |
| [#2707](https://github.com/waaseyaa/framework/issues/2707) CI: handle bot-authored PR workflow approvals and reconcile check reporting | release-delivery-operations | unrelated-feature | Bot PR check reporting differs between workflow-dispatch rollups and REST checks; this is delivery wiring, not a runtime duplicate claim. |
| [#2702](https://github.com/waaseyaa/framework/issues/2702) arch(skeleton): decide the production container topology — FPM compatibility vs a self-serving FrankenPHP default | packaging-native-portability | candidate | Skeleton FPM container topology differs from documented FrankenPHP/Caddy operation; reconcile supported runtime packaging. |
| [#2699](https://github.com/waaseyaa/framework/issues/2699) Provide a secure local auth-token delivery mechanism | identity-access-workflows | candidate | Development token retrieval must work with redaction rather than weakening the logger; inspect the intended identity/bootstrap seam. |
| [#2697](https://github.com/waaseyaa/framework/issues/2697) Centralize production auth controller construction | identity-access-workflows | explicit-existing-finding | Reports repeated manual auth-controller dependency construction and silent fallback registries/loggers that can degrade policy. |
| [#2693](https://github.com/waaseyaa/framework/issues/2693) test(bimaaji): prove the file-level sandbox target guard on Windows, or record why it cannot be | packaging-native-portability | candidate | Windows containment needs target/ancestor junction proof; the body explicitly frames a proof gap, not a known exploit or duplicate implementation. |
| [#2687](https://github.com/waaseyaa/framework/issues/2687) Windows waaseyaa dev FrankenPHP binary omits ext-sodium | packaging-native-portability | candidate | Downloaded Windows runtime may lack sodium despite system PHP satisfying requirements; inspect the actual selected runtime capability. |
| [#2686](https://github.com/waaseyaa/framework/issues/2686) bimaaji: decide ownership of the shared root AGENTS.md path across client transformers | ai-developer-plane | candidate | Multiple client adapters need ownership-safe AGENTS generation and pruning; coexistence alone does not prove duplicate runtime authority. |
| [#2681](https://github.com/waaseyaa/framework/issues/2681) Qualify packaged-consumer parity on native Windows and Linux | packaging-native-portability | candidate | Packaged native install/upgrade/serve acceptance must verify released artifacts rather than monorepo-only behavior. |
| [#2680](https://github.com/waaseyaa/framework/issues/2680) Prove AI-first local development parity on native Windows and Linux | ai-developer-plane | candidate | Local stdio and generated client configuration need native Windows/Linux equivalence without WSL assumptions. |
| [#2679](https://github.com/waaseyaa/framework/issues/2679) Make supported developer and consumer tooling native-host portable | packaging-native-portability | candidate | Unix assumptions in repository tools require portable shared implementations rather than divergent skeleton copies. |
| [#2678](https://github.com/waaseyaa/framework/issues/2678) CI: require native Windows and Linux contract coverage | packaging-native-portability | candidate | Native Windows required checks need a bounded supported-host proof matrix, separate from broader optional qualification. |
| [#2676](https://github.com/waaseyaa/framework/issues/2676) Program: establish native Windows and Linux parity across Waaseyaa | packaging-native-portability | program | Native-host parity umbrella coordinates existing packaging and developer-tool prerequisites. |
| [#2670](https://github.com/waaseyaa/framework/issues/2670) Driver write() takes row identity twice and no driver reconciles a divergent $values id | entity-database-search-cache | explicit-existing-finding | Reports conflicting identifier inputs producing divergent SQL insert/update and memory-storage behavior. |
| [#2667](https://github.com/waaseyaa/framework/issues/2667) roadmap: restore Framework Project synchronization or retire its all-open-issues claim | audit-specification-governance | unrelated-feature | Stale project-board state requires reconciliation; it is not evidence of orphaned runtime code. |
| [#2666](https://github.com/waaseyaa/framework/issues/2666) developer-tools: define sovereignty-aware logs, last-error, URL, and schema-shape diagnostics | ai-developer-plane | unrelated-feature | Read-only diagnostic API/redaction is future design work, without an existing competing implementation established in the body. |
| [#2665](https://github.com/waaseyaa/framework/issues/2665) acceptance: prove the AI-first journey from packaged fresh and upgraded applications | ai-developer-plane | candidate | Packaged AI-development acceptance must compose existing identity, client and corpus owners into real fresh/upgrade journeys. |
| [#2664](https://github.com/waaseyaa/framework/issues/2664) foundation: orchestrate project:init, project upgrades, and AI generated-state verification | generation-scaffolding | candidate | Project initialization and AI update/verify should compose the existing site and artifact hash engines rather than fork them. |
| [#2663](https://github.com/waaseyaa/framework/issues/2663) dx: generate portable project-local MCP descriptors and machine-safe client configuration | ai-developer-plane | candidate | Portable MCP descriptors and client configuration need stable merge/uninstall ownership across machines. |
| [#2662](https://github.com/waaseyaa/framework/issues/2662) ai-docs: ship cited version-matched documentation search with SQLite FTS5 | ai-developer-plane | candidate | Version-matched documentation search needs corpus provenance and missing-corpus diagnostics before adding another retrieval authority. |
| [#2661](https://github.com/waaseyaa/framework/issues/2661) specs: add lifecycle metadata and compile a sanitized versioned agent corpus | audit-specification-governance | candidate | Corpus lifecycle must distinguish live specifications from history and drafts; coordinate the existing specification program. |
| [#2660](https://github.com/waaseyaa/framework/issues/2660) dx: generate equivalent guidelines and Agent Skills for supported coding clients | ai-developer-plane | candidate | Claude skill output and Codex AGENTS output have different capability coverage; compare adapter contracts rather than assume byte equivalence. |
| [#2653](https://github.com/waaseyaa/framework/issues/2653) Program: ship Waaseyaa's AI-first local development plane | ai-developer-plane | program | AI-first local-development umbrella composes package, identity, stdio and documentation work already owned by children. |
| [#2650](https://github.com/waaseyaa/framework/issues/2650) release: replace the root framework dist with a governed allowlist and size budget | packaging-native-portability | explicit-existing-finding | Reports root distribution duplicating package source and unrelated repository material; this is distribution duplication, not proof of two runtime engines. |
| [#2640](https://github.com/waaseyaa/framework/issues/2640) Kernel body-size refusal preempts three transport-guard answers on MCP routes | http-api-integration-transport | candidate | Kernel body-size middleware and MCP transport guards expose different rejection envelopes; identify the governing layer. |
| [#2549](https://github.com/waaseyaa/framework/issues/2549) fix(deployer): merge user identities by stable UUID, not numeric primary key alone | release-delivery-operations | candidate | Artifact identity merge uses primary keys while serving identity also has stable UUID uniqueness; preserve actual identity constraints. |
| [#2548](https://github.com/waaseyaa/framework/issues/2548) fix(deployer): preserve interdependent runtime schemas across artifact handoff | release-delivery-operations | candidate | Schema signatures can reject equivalent affinities while table-by-table restoration misses cross-table authority and triggers. |
| [#2545](https://github.com/waaseyaa/framework/issues/2545) docs(config): define the authority contract for cloned databases | release-delivery-operations | candidate | Cloned database path identity can invalidate configuration authority without a supported rebind procedure. |
| [#2527](https://github.com/waaseyaa/framework/issues/2527) Program: remove recurring delivery friction across Framework, Sheg, and Anokii | release-delivery-operations | program | Delivery-friction umbrella includes duplicated environment hygiene checks and stale-base qualification; reconcile existing child ownership. |
| [#2525](https://github.com/waaseyaa/framework/issues/2525) Delivery: add stale-base detection, bounded landing queues, and exact merge handoffs | release-delivery-operations | candidate | Stacked PRs repeat expensive qualification and defer landing; inspect handoff authority and stale-base triggers before changing gates. |
| [#2499](https://github.com/waaseyaa/framework/issues/2499) foundation/upgrade: add a canonical observation digest and versioned preflight evidence envelope | release-delivery-operations | candidate | Preflight evaluator lacks a canonical observation envelope, creating a risk of consumer-specific interpretations rather than an established duplicate. |
| [#2498](https://github.com/waaseyaa/framework/issues/2498) deployer: add a consistent SQLite artifact snapshot seam (VACUUM INTO) to RuntimeState | release-delivery-operations | unrelated-feature | Requests a supported SQLite snapshot producer; absence of a new producer is not an orphaned existing implementation. |
| [#2479](https://github.com/waaseyaa/framework/issues/2479) Audit environment, bootstrap-configuration, and secret-policy ownership across Framework, Anokii, and Sheg | bootstrap-provider-discovery | candidate | Framework and consumer environment classification/validation ownership needs reconciliation for auth-token secrets. |
| [#2449](https://github.com/waaseyaa/framework/issues/2449) Define the Waaseyaa beta support contract from alpha field and portability evidence | release-delivery-operations | program | Beta support assessment umbrella depends on published evidence and must not be treated as release authorization. |
| [#2448](https://github.com/waaseyaa/framework/issues/2448) Assess MySQL/MariaDB readiness after the PostgreSQL portability assessment | entity-database-search-cache | candidate | MySQL support assessment needs real-driver evidence distinct from SQLite and PostgreSQL claims. |
| [#2447](https://github.com/waaseyaa/framework/issues/2447) Assess PostgreSQL readiness after Sheguiandah alpha field evidence | entity-database-search-cache | candidate | PostgreSQL assessment must distinguish proposed support from the current SQLite-only evidence boundary. |
| [#2443](https://github.com/waaseyaa/framework/issues/2443) Acceptance: prove the complete account lifecycle in a fresh generated application | identity-access-workflows | candidate | Generated account lifecycle acceptance must exercise real packaged interfaces and recipes rather than isolated fixtures. |
| [#2442](https://github.com/waaseyaa/framework/issues/2442) site:init: offer minimal and editorial initialization profiles without forking Framework internals | generation-scaffolding | candidate | Minimal/editorial profiles should share site initialization without copying package internals. |
| [#2441](https://github.com/waaseyaa/framework/issues/2441) DX: ship executable recipes for safe auth and account customization | identity-access-workflows | candidate | Auth customization recipes need executable public extension points rather than consumer backend forks. |
| [#2440](https://github.com/waaseyaa/framework/issues/2440) Admin/Auth: publish the complete account UI without copying backend security logic | presentation-frontend-ssr | candidate | Auth scaffolding covers only part of the account journey; presentation ownership must not duplicate credential logic. |
| [#2433](https://github.com/waaseyaa/framework/issues/2433) Configuration rollback and candidate sweep have no production authorization policy | identity-access-workflows | candidate | Rollback and sweep authorization interfaces currently refuse by default and lack a production producer; keep distinct from forward activation authority. |
| [#2432](https://github.com/waaseyaa/framework/issues/2432) Destructive configuration import (config:import --delete-orphans) has no authorization policy | identity-access-workflows | candidate | Signed destructive import still lacks explicit delete authorization; signatures must not stand in for mutation permission. |
| [#2342](https://github.com/waaseyaa/framework/issues/2342) feat: add reusable Open Graph package and build-time validation | presentation-frontend-ssr | unrelated-feature | Future Open Graph package depends on a head hook; its warning about creating an orphan is hypothetical, not a current orphan finding. |
| [#2229](https://github.com/waaseyaa/framework/issues/2229) Standardize the native specification contract | audit-specification-governance | program | Native specification umbrella governs lifecycle and validators; reuse its authority instead of opening a parallel specification system. |
| [#2182](https://github.com/waaseyaa/framework/issues/2182) Admin media library shows file URIs instead of image thumbnails | media-upload-file-storage | candidate | Media file URIs, thumbnails and authorized derivative URLs need a defined delivery/source/cache boundary. |
| [#2169](https://github.com/waaseyaa/framework/issues/2169) GraphQL schema reveals entity type names for types whose rows are fully access-denied (non-blocking) | http-api-integration-transport | candidate | GraphQL type visibility and row authorization require an explicit discovery policy, not an assumed data leak. |
| [#2159](https://github.com/waaseyaa/framework/issues/2159) Anonymous published reads return no data when a content-group entity's status field is not Protected+authorizationInput | identity-access-workflows | candidate | Content status protection and anonymous read defaults differ from recipe expectations; trace registered field/access configuration. |
| [#2132](https://github.com/waaseyaa/framework/issues/2132) Rich-text safety: unsanitized fields.*.raw twin, sanitizer not injectable, widget/field-type coupling unenforced, CSP not configurable | presentation-frontend-ssr | candidate | Raw and formatted rich-text output allow different consumption paths; inspect sanitization, DI and widget contracts before calling either path dead. |
| [#2123](https://github.com/waaseyaa/framework/issues/2123) Asset-manifest helper for consumer public assets | presentation-frontend-ssr | unrelated-feature | Asset-manifest helper ownership and build-tool support remain a feature design question without a current duplicate claim. |
| [#2055](https://github.com/waaseyaa/framework/issues/2055) C-22 dead-code gate missed distribution repos; dormant getStorage() seam fails silently | packaging-native-portability | explicit-existing-finding | Reports monorepo dead-code conclusions missing packaged consumer getStorage calls and resulting hidden login failures; public-contract scope needs revalidation. |
| [#2054](https://github.com/waaseyaa/framework/issues/2054) proposal: first-class chat surface for Waaseyaa applications | ai-developer-plane | candidate | Consumer chat glue may warrant a first-class composition seam, but does not by itself establish duplicated Framework engines. |
| [#1957](https://github.com/waaseyaa/framework/issues/1957) groups: membership management surface — admin SPA UI + API exposure (CW-v1 WP-4 follow-up) | identity-access-workflows | candidate | Membership UI relies on older absence claims while related notes describe addMember behavior; reconcile against newer membership findings before implementation. |
| [#1866](https://github.com/waaseyaa/framework/issues/1866) Lift ServiceProvider::routes() signature to a routing-owned interface (L0→L4/L1 type-hint coupling baselined in WP7) | bootstrap-provider-discovery | candidate | Provider route declarations couple foundation to routing; review the proposed routing-owned SPI and all callers together. |
| [#1861](https://github.com/waaseyaa/framework/issues/1861) cache: EntityCacheSubscriber::register() has zero callers — orphaned entity-cache invalidation helper | entity-database-search-cache | explicit-existing-finding | Reports entity-cache subscriber registration and invalidator absent from production callers while kernel invalidation exists separately. |
| [#1762](https://github.com/waaseyaa/framework/issues/1762) Feature: build the media source-plugin substrate + authorized media download | media-upload-file-storage | candidate | Media source plugins and authorized downloads need a shared source contract; desired deduplication is not itself proof of existing duplicate sources. |
| [#1742](https://github.com/waaseyaa/framework/issues/1742) Media versioning/CAS trigger is dormant + pending-upload store is per-request in-memory (latent data loss) | media-upload-file-storage | explicit-existing-finding | Reports content-addressed media versioning structurally present but setPendingUpload has no production caller and pending state is request-local. |
| [#1687](https://github.com/waaseyaa/framework/issues/1687) downstream: composer require waaseyaa/framework:<exact> writes an exact pin that blocks point upgrades | packaging-native-portability | unrelated-feature | Exact Composer pin upgrade policy is distribution guidance, not a current competing implementation finding. |
| [#1658](https://github.com/waaseyaa/framework/issues/1658) EntityRepositoryInterface growth breaks consumer in-memory test doubles; ship a reusable base | entity-database-search-cache | candidate | Consumer repository test doubles drift as the public interface grows; inventory the contract before treating them as supported alternate engines. |
| [#1640](https://github.com/waaseyaa/framework/issues/1640) OAuth 2.1 resource-server auth for the MCP endpoint (RFC 9728 / resource indicators / PKCE) | identity-access-workflows | candidate | MCP resource-server OAuth profile needs discovery, PKCE and scope contracts aligned with existing provider boundaries. |
| [#1639](https://github.com/waaseyaa/framework/issues/1639) enhancement(ai-tools/mcp): add a media upload agent tool | media-upload-file-storage | unrelated-feature | Agent media upload is a new tool dependent on authorized media/source contracts; it does not establish an existing duplicate route. |
| [#1633](https://github.com/waaseyaa/framework/issues/1633) structured-import is GFM single-entity (prompt→value), not a multi-row CSV importer | ingestion-validation | candidate | Structured import currently means single-entity GFM rather than tabular CSV; clarify its contract before adding another ingestion pipeline. |
| [#1631](https://github.com/waaseyaa/framework/issues/1631) No app hook to server-render <head> meta (OpenGraph/SEO): provider middleware is pre-controller; app can't override the Inertia renderer | presentation-frontend-ssr | explicit-existing-finding | Reports a consumer SEO workaround querying raw PDO and repeating routing knowledge because a supported head hook is absent. |
| [#1629](https://github.com/waaseyaa/framework/issues/1629) Docs: entity-modeling guidance — relationship-vs-FK, reserved-word type ids, per-vendor taxonomy scoping | entity-database-search-cache | candidate | Relationship, reserved type and taxonomy examples need explicit storage contracts, but the body does not prove duplicate engines. |
| [#1627](https://github.com/waaseyaa/framework/issues/1627) groups package ships Group/GroupType but no membership primitive | identity-access-workflows | candidate | Older claim that membership primitives are absent conflicts with newer GroupMembershipService findings; revalidate historical scope before new design. |
| [#1626](https://github.com/waaseyaa/framework/issues/1626) Inertia on-ramp residuals: align page payload with stock data-page mount-node attribute + ship a starter vite.config (publicDir:false) — asset-base + configurable bundle/entry landed | presentation-frontend-ssr | candidate | Inertia payload/mount and starter Vite configuration need a single supported client contract rather than undocumented divergence. |
| [#1625](https://github.com/waaseyaa/framework/issues/1625) schema:check VARCHAR(n) false-positive: typesCompatible() still doesn't strip the length suffix + audit_* migrations still emit VARCHAR(n) (oidc_client base cols partially fixed) — strip suffix in comparator + emit TEXT in audit migrations | entity-database-search-cache | candidate | Schema comparator type normalization must agree with migration output across the supported driver matrix. |
| [#1624](https://github.com/waaseyaa/framework/issues/1624) Notifications: no first-party Mercure channel, and NotificationDispatcher channels are build-time only | async-delivery | unrelated-feature | Mercure notification channel and configuration are new feature design, not an existing orphan or competing implementation claim. |
| [#1623](https://github.com/waaseyaa/framework/issues/1623) Policy DI now resolves register() bindings + degrades nullable deps, but discovered #[PolicyAttribute] policies are still eager-at-boot and an unbound required ctor dep is a whole-kernel fatal — make lazy or non-fatal diagnostic | bootstrap-provider-discovery | candidate | Attribute policy dependency resolution can fail the entire eager boot; determine the supported lazy/error boundary. |
| [#1609](https://github.com/waaseyaa/framework/issues/1609) #[ContentEntityType] does not register an entity; you must also add it to config/entity-types.php | bootstrap-provider-discovery | candidate | Entity attributes still require separate configuration registration; reconcile documented discovery behavior and actual registration authority. |
| [#1608](https://github.com/waaseyaa/framework/issues/1608) Agent endpoint surfaces NullLlmProvider as a success-shaped run — return 'no model configured' instead of a 200 placeholder (boot warning shipped) | ai-developer-plane | candidate | Null LLM provider can yield a success-shaped agent response despite missing configuration; inspect failure propagation through composition. |
| [#1607](https://github.com/waaseyaa/framework/issues/1607) OpenAiCompatibleProvider has no tool/function calling; #[AsAgentDefinition] tools only work with AnthropicProvider | ai-developer-plane | candidate | OpenAI-compatible tool calling lacks parity with the existing executor/provider contract; capability differences require explicit support semantics. |
| [#1606](https://github.com/waaseyaa/framework/issues/1606) ai-vector remains non-turnkey: vector.search is unwirable and passage provenance is undefined | ai-developer-plane | explicit-existing-finding | Original report explicitly describes unregistered vector/schema providers, an unsubscribed listener and a missing message handler; newer disposition says embedding CLI shipped, so historical orphan claims require selective current revalidation rather than wholesale reuse. |
| [#1605](https://github.com/waaseyaa/framework/issues/1605) DX: distinguish unauthorized-from-empty over JSON:API — signal when an access policy filters a collection to empty (already fail-closed, no leak) | http-api-integration-transport | unrelated-feature | Uniform unauthorized/empty API behavior is a policy enhancement; the described current denial does not establish duplicated runtime authority. |
| [#1588](https://github.com/waaseyaa/framework/issues/1588) [mission-rework] database-legacy-retirement-01KSEFV2 — must migrate consumers before deletion | entity-database-search-cache | program | Database-legacy retirement ledger tracks manifest dependencies that must migrate before removal; it is not evidence those dependencies are dead. |
| [#1579](https://github.com/waaseyaa/framework/issues/1579) [admin-spa] M4A-5b: Workflow guard editing UI + persistence design | identity-access-workflows | unrelated-feature | Workflow guard editing and persistence are future UI capabilities beyond the current read-only matrix. |
| [#1578](https://github.com/waaseyaa/framework/issues/1578) [notification] Delivery log + channel enable/disable + 2-tab notifications admin | async-delivery | unrelated-feature | Notification delivery-log and channel administration are future capabilities with separate delivery dependencies. |
| [#1415](https://github.com/waaseyaa/framework/issues/1415) [admin-spa] M5 residual: AI observability dashboard + AI pipeline inspector (MCP endpoint admin + Mercure broadcast monitor already shipped) | ai-developer-plane | unrelated-feature | AI observability and pipeline inspection are future surfaces; existing admin/monitoring components should be reused, not counted as orphan findings. |

The [JSON companion](issue-coverage.json) preserves baseline/body hashes, exact body excerpts, cross-lanes and individual evidence status. Read the [baseline](issue-baseline.json) full body where an excerpt is incomplete. Nothing here establishes that a feature is obsolete, unused by packaged consumers, or safe to remove.
