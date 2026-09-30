# FW-AIV-STORAGE-CONTRACT-01: canonical storage and semantic search

- Forge mirror: `waaseyaa/framework#3141`, candidate 2
- Base: `3541e1fda903306a92bcc0f4e6c23ecde2a1cc6b`
- Owner: root, implementation and integration
- Worktree: `C:/dev/waaseyaa/framework-3141-c2`
- Branch: `codex/3141-c2-storage-contract`
- Dependency lock SHA-256: `9fa4fef455335e6ffdc60153fdd7e199ca468374e31387d89d26db68d2cf52dc`
- Lease: retained explicit ownership; `bin/worktree-coordinator` refuses native
  Windows drive paths because its literal-path validator requires `/`. No
  coordinator safeguards or unrelated checkouts were changed.

## Inventory before implementation

| Surface | Actual contract / consumer | Disposition |
| --- | --- | --- |
| `EmbeddingStorageInterface` | `store(type, string id, vector)`, `findSimilar(query, type, limit)`, `delete(type, id)` | Canonical supported storage seam |
| `DatabaseEmbeddingStorage` | Only implementation of canonical seam; migration-owned JSON vectors on SQLite and PostgreSQL | Retain; validate inputs, deterministic scores/ties, explicit failures |
| `AiVectorServiceProvider` | First kernel binding wins; lifecycle, warmer, HTTP resolve same storage | Retain and qualify real composition |
| `EntityEmbeddingListener`, `EntityEmbeddingCleanupListener` | Save/pointer/revert indexing and post-delete cleanup | Retain default-deny policy and best-effort post-commit behavior; invalidate failed refresh |
| `SemanticIndexWarmer` | CLI warm and refresh, shared policy, currently counts swallowed listener failures | Confirm durable operations before counting; remove missing-row vectors |
| `SearchController` | `findSimilar` arrays, JSON:API resources, optional graph rerank | Declare wire; preserve string identities; sanitize failures |
| Foundation `HttpKernel`, `SearchRouter` | Resolve canonical pair and serve `/api/search` | Retain, check real response contract |
| CLI `SemanticWarmCommand`, `SemanticRefreshCommand` and semantic provider | Call warmer and emit operator reports | Retain; strict refresh failure behavior |
| ai-tools `VectorSearchTool` | Resolver docs name canonical seam, runtime calls nonexistent `search()` and expects DTO objects | Repair consumer; no translating compatibility adapter |
| `VectorStoreInterface`, `InMemoryVectorStore` | Second contract: language variants, metadata, DTO search/get/has; test/development consumers | Remove duplicate public API; migrate tests and instructions |
| `EntityEmbedder`, `EntityEmbedding`, `SimilarityResult`, `DistanceMetric` | Duplicate DTO/service family; full-entity text bypasses canonical indexing policy | Remove; document unsupported language/metadata features |
| Database cosine helper | Uses `InMemoryVectorStore::cosineSimilarity()` in production | Extract internal scaled cosine math |
| Aliases, decorators, compatibility layers | No storage aliases/decorators or implemented bridge found in repository production, manifests, exports, discovery, fixtures or declarations | Do not invent a bridge that discards language or metadata |
| bimaaji packaged skill, specs, README, public declarations, field-read roster, PHPStan baseline, Phase8 tests | Retained documentation and test consumers of duplicate family | Update with canonical contract |

Read-only inventory is Framework-scoped. No external application compatibility
is claimed and no other repository needs implementation changes. Alpha DIR-003
allows removal with explicit changelog and UPGRADING migration instructions.

## Decision and ownership

One vector per `(entity type, exact string ID)`; replacement is atomic, delete
is idempotent, search filters type and incompatible dimensions, uses finite
cosine scores in `[-1,1]`, scores zero vectors as zero, and breaks ties by
bytewise string ID. Nonempty finite numeric lists are required. Storage has
no language variants, provider-specific results or persisted entity metadata.
Missing schema refuses rather than reporting successful writes or empty search.

HTTP JSON:API and MCP keep their distinct envelopes and share result identity,
cosine semantics, current access-filtered metadata and explicit failure behavior.
No legacy-object fallback or silent shape coercion is supported. HTTP graph
rerank remains an explicitly described extension. Its cost and private security
remediation remain separately owned by #3143.

Root owns `packages/ai-vector`, affected ai-tools vector consumer/tests,
Foundation search router/tests, CLI affected tests, public search conformance, the
field-read roster and stale PHPStan entry, specs, packaged bimaaji instructions,
upgrade guide, changelog fragment, audit record and evidence. Other lanes are
preserved. No donor vendor tree, autoload override or dependency symlink to
another checkout is permitted. Candidate-local Composer junctions resolve into
this candidate, verified by reflection.

## Acceptance and test plan

1. Shared real storage conformance on SQLite and hosted PostgreSQL: replacement,
   type/id isolation, deletion/recreation, dimensions, invalid inputs, finite
   extreme scores, deterministic ties and empty results.
2. Real storage exercised by HTTP controller/router and MCP tool with nonempty,
   empty, optional graph metadata, invalid input and sanitized failures.
3. Policy, provider-order composition, post-commit failure, save/refresh and CLI
   affected tests; failed reindex and missing hydration invalidate stale vectors.
4. Public declarations, annotations, README, specs, upgrade recipe and removed
   symbol consumers agree; governed surface and architecture gates pass.
5. Default preflight and focused affected suites on native Windows. No broad
   overlapping local qualification. Exact-head hosted full qualification owns
   complete verdict, PostgreSQL and distributed forms.
6. Independent subagent review of immutable candidate; root is not a reviewer.
   Identical green SHA lands by direct fast-forward, then exact-SHA main feedback.

Broaden only for new unexplained failures, dependency/bootstrap changes, base
conflicts or evidence invalidated by repairs. Root owns final qualification.

## Initial evidence and calibration

Two sequential subagent lenses verified/refuted removal and consumer claims on
the exact base. Both found required real consumers; absence of callers was not
used as removal proof. Refutation added missing-hydration cleanup acceptance.
Synthetic real-SQLite base probe reproduced: tool cannot use canonical storage,
missing schema does not refuse, finite extreme vectors yield nonfinite scores,
and failed reindex leaves the old vector. These are reproduced, not qualified.

Profiles: domain, persistence, runtime, wire and distribution. This is a bounded
remediation delta, not a fresh whole-package assessment. Budget: two sequential
finding lenses, one candidate review, affected repair review if needed; subagent
token usage unavailable. Inventory integrity initially detected only app-added
`vscode-merge-base` Git metadata. Work stopped, exact config delta was identified,
and fresh unchanged snapshot plus verifier confirmation restored custody. No
source/index/ref changes occurred. Both final lane-integrity checks passed.

Local affected qualification: 151 tests, 716 assertions pass (package storage,
policy/composition, HTTP/MCP wire, CLI, real lifecycle and semantic access).
Focused PHPStan on eight affected production files passes without new
suppressions. Default preflight bookkeeping repairs include explicit removal
fragments, infrastructure spec coupling and regenerated S1 construction roster.
Hooks doctor confirms installed/current. No package-scoped Deptrac model or
generated dependency view exists; the canonical package-layer gate remains the
architecture authority and passed. No dependency edges were added.

The focused field-read architecture inventory terminates PHP prematurely on
native Windows, including a focused diagnostic retry. It is not counted as a
candidate finding or pass; exact-head hosted Architecture owns that verdict.
No overlapping broad local suites were run. Default preflight passes: 43 gates
executed, 0 reused, 0 failed, 0 hosted-required, 3 not-applicable (216.6 seconds).
This is the default local verdict, not hosted full qualification. Candidate review,
hosted qualification and landing remain pending; exact evidence will be linked
on #3141 without changing qualified source bytes.
No release, tag, deployment or issue closure is part of this candidate checkpoint.

## Independent review repair

Immutable candidate `9125bdb5b4dd12209e8a75143c6fb6bfda440eaa` received
changes-requested for a bounded conformance gap, with no demonstrated runtime
regression. The subagent required real replacement-INSERT failure after DELETE,
persisted corruption, invalid identity, migration recovery on the same instance,
and colon/large string IDs. Both drivers now inherit those tests. SQLite uses a
test-only failure trigger; PostgreSQL uses a test-only CHECK constraint. Both are
installed after the production migration, with governed schema roster updates.

SQLite repair suite passes 17 tests, 65 assertions. Negative controls replacing
rollback with commit and swallowing corrupt-vector refusal each fail their
named test (exit 1); original production bytes were restored and checked against
the immutable commit. No runtime repair was required. Initial review lane
integrity passed. Repair review and final exact-head qualification remain pending.

Committed-diff preflight exposed coverage-companion ownership for changed
lifecycle/warmer/router branches. The real public wire/lifecycle suite now lives
in the package Contract directory as `PublicSemanticSearchConformanceTest`,
with coverage-bearing declarations for its six exercised production boundaries.
Its real operations and assertions remain intact, with no synthetic coverage
touches or duplicate mock-only replacement. Root owns this relocation and the
regenerated construction/schema rosters.
