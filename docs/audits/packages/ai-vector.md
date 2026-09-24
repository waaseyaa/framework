# `waaseyaa/ai-vector` audit

- **Audit state:** in progress. It isn't assessed yet, for two reasons:
  1. some profile items are open or unqualified: the installed split-package and `--no-dev` behavior, search contract conformance (there's no declared schema to check against), and an end-to-end reproduction of AIV-EXEC-002 through a real repository;
  2. AIV-SEC-001 hasn't completed private triage.
- **Remediation state:** in progress. Umbrella #3137 with bounded child issues #3138–#3143. #3138 (AIV-PERSIST-001, AIV-PERSIST-002) is implemented by FW-AIV-PERSIST-01.
- **Base:** `bfba7f27d7a27a2228649bc75967fb1d261856c0`, audited 2026-09-23
- **Dependency identity:** `composer.lock` SHA-256 `1c0df008addb5ec580015e2340937b676a72f867b2aa136203dff102dcfe7a48` at the base; PHP 8.5.5, native Windows 11
- **Evidence freshness:** audited at `bfba7f27d7a27a2228649bc75967fb1d261856c0`. The one production change since then, FW-AIV-PERSIST-01 (#3138), is reconciled in the charter, roster and AIV-PERSIST entries:
  - `SqliteEmbeddingStorage` was replaced by `DatabaseEmbeddingStorage`;
  - the `embeddings` migration was added;
  - `waaseyaa/database-legacy` was declared.

  Nothing else was re-audited. The findings' observed evidence describes the base.
- **Owner issue:** program `waaseyaa/framework#3118`; remediation umbrella #3137
- **Profiles applied:** persistence-execution, kernel-runtime, domain-contracts, http-ui-contracts (the search route and its composition), distribution. **Not applied:** introspection-cli (ai-vector registers no commands; `semantic:warm` and `semantic:refresh` live in `waaseyaa/cli` and only call `SemanticIndexWarmer`), generation-build (the package generates nothing).

## Charter

- **Owns:** computing text embeddings through a configured provider (Ollama, OpenAI), storing one vector per entity in its migration-owned `embeddings` table, similarity search over stored vectors, keeping vectors in step with entity lifecycle events, and the semantic search controller.
- **Does not own:** schema authority (Foundation), entity storage and access policy (entity, access), publication rules (workflows), route registration (Foundation `BuiltinRouteRegistrar`), the `vector.search` AI tool (ai-tools), CLI commands (cli).
- **Consumers:**
  - Foundation `HttpKernel` registers its lifecycle listeners.
  - Foundation `SearchRouter` serves `GET /api/search`.
  - The CLI `semantic:warm` and `semantic:refresh` handlers use `SemanticIndexWarmer`.
  - ai-tools `VectorSearchTool` duck-types its interfaces.
  - It arrives in applications through `waaseyaa/cli` (a runtime `require`) and the `waaseyaa/full` metapackage; `core` and `cms` don't include it.
- **Dependencies:** requires entity, entity-storage, queue, api, access, workflows, foundation and (since FW-AIV-PERSIST-01) database-legacy, all used; there are no undeclared imports. Embedding providers are optional and chosen by config.
- **Storage:** there is one implementation. At the base it was SQLite through raw PDO. Since FW-AIV-PERSIST-01 it is `DatabaseEmbeddingStorage` over `DatabaseInterface`, on the migration-owned `embeddings` table. It isn't configurable (#3140).
- **Public surface:** see AIV-PUBLIC-001. `public-surface.php` declares five symbols, but nine classes carry `@api` without a declaration, and `SearchController` states a "stable" v1.0 wire contract that isn't declared anywhere.
- **Evidence it works:**
  - Source: at the base, 89 package unit tests and 12 related integration tests pass. With FW-AIV-PERSIST-01, 107 package tests pass, including the migration and serving-path schema-authority tests.
  - Distributed form: not qualified (see "Not reviewed").

## Roster

All 21 PHP files under `src/`, the migration and `public-surface.php` (23 PHP files), plus the manifest and the README. The rows for `DatabaseEmbeddingStorage` and the migration reflect FW-AIV-PERSIST-01 and replace the base's `SqliteEmbeddingStorage` row.

| File | Role | Classification | Evidence level | Notes |
| --- | --- | --- | --- | --- |
| `src/AiVectorServiceProvider.php` | Binds storage, provider and warmer | duplicated or drifting contract | reviewed | Its bindings are bypassed by Foundation (AIV-COMP-001) |
| `src/DatabaseEmbeddingStorage.php` | The only `EmbeddingStorageInterface` implementation, over `DatabaseInterface` | owned and coherent | reproduced | Replaced `SqliteEmbeddingStorage` (AIV-PERSIST-001, AIV-PERSIST-002) in FW-AIV-PERSIST-01; no DDL, no raw PDO |
| `migrations/2026_09_24_000001_embeddings_schema.php` | Owns the `embeddings` table | owned and coherent | reproduced | Creates it, adopts a compatible table in place, refuses others with `[AIV-DB001]` (FW-AIV-PERSIST-01) |
| `src/EmbeddingStorageInterface.php` | Storage contract used in production | owned and coherent | reviewed | Competes with `VectorStoreInterface` (AIV-PUBLIC-001) |
| `src/EntityEmbeddingListener.php` | Re-index on save and revision moves | necessary but under-specified | reproduced | Synchronous remote call (AIV-EXEC-001); indexability rule (AIV-DOMAIN-001) |
| `src/EntityEmbeddingCleanupListener.php` | Delete the vector on entity delete | necessary but under-specified | reproduced | Triggered the lazy DDL at the base (AIV-PERSIST-001) |
| `src/SearchController.php` | Semantic and keyword search, graph rerank | necessary but under-specified | reproduced (synthetic) | AIV-SEC-001, AIV-HTTP-001 |
| `src/SemanticIndexWarmer.php` | Batch index or reconcile, used by CLI | owned and coherent | reviewed | The CLI's only indexing path (AIV-COMP-002) |
| `src/EmbeddingProviderFactory.php` | Builds the provider from config | owned and coherent | reviewed | Fails closed on bad OpenAI credential config; called in three places (AIV-COMP-001) |
| `src/EmbeddingProviderInterface.php` | Single-text embedding contract | owned and coherent | reviewed | |
| `src/EmbeddingInterface.php` | Adds batch and dimension methods | necessary but under-specified | reviewed | Only implemented, never required by a caller |
| `src/OllamaEmbeddingProvider.php` | Ollama HTTP provider | owned and coherent | reviewed | `file_get_contents`, 15 s timeout (AIV-SYMFONY-001) |
| `src/OpenAiEmbeddingProvider.php` | OpenAI HTTP provider | owned and coherent | reviewed | Credential through `SecretHandle`; 20 s timeout |
| `src/OpenAiEmbeddingCredentialOperation.php` | Secret-consumer operation | owned and coherent | reviewed | `@internal`, registered with the secret registry |
| `src/ProviderCredentialConfigurationException.php` | Credential config refusal | owned and coherent | reviewed | |
| `src/VectorStoreInterface.php` | Second storage contract | duplicated or drifting contract | reviewed | No production implementation (AIV-PUBLIC-001) |
| `src/InMemoryVectorStore.php` | In-memory `VectorStoreInterface` | unwired, unreachable or obsolete | reviewed | Test and dev only; hosts `cosineSimilarity()` used by production |
| `src/EntityEmbedder.php` | Embed-and-search service over `VectorStoreInterface` | unwired, unreachable or obsolete | reviewed | No production caller |
| `src/EntityEmbedding.php` | Value object for `VectorStoreInterface` | duplicated or drifting contract | reviewed | |
| `src/SimilarityResult.php` | Value object for `VectorStoreInterface` | duplicated or drifting contract | reviewed | |
| `src/DistanceMetric.php` | Metric enum | unwired, unreachable or obsolete | reviewed | Declared public; no production consumer |
| `src/Testing/FakeEmbeddingProvider.php` | Deterministic test provider | wrong package or wrong layer | reviewed | Ships in production autoload (AIV-DIST-001) |
| `composer.json` | Manifest and provider discovery | owned and coherent | reviewed | |
| `README.md` | Package description | duplicated or drifting contract | reviewed | Names `VectorStoreInterface` as key; describes RAG integration that isn't wired |
| `public-surface.php` | Public declarations | duplicated or drifting contract | reviewed | AIV-PUBLIC-001 |

## Findings

Security-sensitive findings carry a safe summary only. Reproduction details are handled through the repository's private reporting route (see AIV-SEC-001).

| ID | Title | Severity | Confidence | Level | Disposition | Owner | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `AIV-PERSIST-001` | Lifecycle listeners create `embeddings` on the authoritative database outside schema authority | high | confirmed | reproduced | repair | #3138 (FW-AIV-PERSIST-01) | Migration-owned table; remediation candidate in #3138 |
| `AIV-PERSIST-002` | Raw PDO and SQLite-only SQL on a driver-agnostic connection | medium | confirmed | reviewed | repair | #3138 (FW-AIV-PERSIST-01) | `DatabaseEmbeddingStorage` in #3138; server-database qualification in #3140 |
| `AIV-COMP-001` | Three separately constructed storage and provider instances | medium | confirmed | reviewed | repair | pending split (WP-B) | Define one composition path |
| `AIV-COMP-002` | Lifecycle listeners exist only under `HttpKernel` | medium | confirmed | reviewed | repair | pending split (WP-B) | Decide whether CLI and workers keep vectors in step |
| `AIV-BACKEND-001` | `pgvector` is advertised by a sovereignty profile but never used | medium | confirmed | reviewed | repair or document | pending split (WP-B) | Implement and qualify, or stop advertising and refuse clearly |
| `AIV-SEC-001` | Search response metadata can disclose entities removed from the results | withheld | confirmed | reproduced (synthetic) | repair | private report | Private report, then fix |
| `AIV-HTTP-001` | Every semantic search loads all relationship entities | medium | confirmed | reviewed | repair | pending split (WP-SEC) | Bound or index the rerank query |
| `AIV-DOMAIN-001` | Every non-node entity type is indexed and sent to the provider | medium | confirmed | reviewed | repair or document | pending split (WP-C) | Decide an explicit indexability policy |
| `AIV-EXEC-001` | Embedding runs synchronously on save; the queue message has no handler | medium | confirmed | reviewed | repair or remove | pending split (WP-C) | Wire async indexing or remove the dead path |
| `AIV-EXEC-002` | Storage failures on the delete paths fail an already-committed entity mutation | high | confirmed | reviewed | repair | pending split (WP-A) | Make post-commit storage failures best-effort, and test through a real repository |
| `AIV-PUBLIC-001` | Two storage contracts and public declarations that don't match | low | confirmed | reviewed | document, deprecate or remove | pending split (WP-C) | Choose the canonical contract |
| `AIV-DIST-001` | ai-vector is installed by default through `waaseyaa/cli`; a test helper ships in production | medium | confirmed | reviewed | repair | pending split (WP-B) | Make the capability opt-in |
| `AIV-SYMFONY-001` | Hand-rolled HTTP client in both providers | low | likely | reviewed | defer | pending split (WP-C) | Evaluate `waaseyaa/http-client` |
| `AIV-R-001` | Lead: any first entity save creates the table | — | refuted | reproduced | refuted | — | none |
| `AIV-R-002` | Lead: the package imports undeclared dependencies | — | refuted | reviewed | refuted | — | none |
| `AIV-R-003` | Lead: the in-`src` test helper breaks `--no-dev` boot | — | refuted | reviewed | refuted | — | none |
| `AIV-R-004` | Lead: keyword-mode search creates the table | — | refuted | reviewed | refuted | — | none |

### `AIV-PERSIST-001`: lifecycle listeners create `embeddings` outside schema authority

- **Observed, with evidence:** `SqliteEmbeddingStorage::ensureSchema()` (`src/SqliteEmbeddingStorage.php:104`) runs `CREATE TABLE IF NOT EXISTS embeddings` before any store, delete or search. `HttpKernel` (`packages/foundation/src/Kernel/HttpKernel.php:211`) hands it the application's own database connection. Reproduced by `tests/Fixtures/Audits/AiVector/embeddings-schema-drift-probe.php`, a standalone script. It uses a throwaway SQLite file from the `TemporarySqliteDatabase` test utility, and the framework's own `EntitySchemaSyncRunner` for both coordinated transitions, the path #3110 describes. All 4 cases behave as recorded:
  - after a coordinated transition, `EntityEmbeddingCleanupListener::onPostDelete()` creates the table and the next transition fails with `[S1-DB109]`;
  - with no embedding provider configured, `EntityEmbeddingListener::onPostSave()` on a node that isn't publicly served does the same;
  - saving a non-node entity with no provider doesn't (see AIV-R-001);
  - the control shows the next transition succeeding without ai-vector activity.

  Reviewed: with a provider configured, anonymous `GET /api/search` also reaches `findSimilar()` and so the same DDL (`packages/foundation/src/Http/Router/SearchRouter.php:72`).
- **Expected contract:** `docs/specs/s1-schema-authority.md` (the runtime-table paragraph): a serving path's tables are migration-owned or live on their own file.
- **Consequence and consumers:** any application that installs `waaseyaa/cli` (AIV-DIST-001) and serves HTTP gets drift on its first qualifying delete or save. After that, every coordinated transition refuses, including `install:init` in a new release. This blocked FETDER's production release (fetder-waaseyaa#160) and is the ai-vector half of #3110.
- **Severity and confidence:** high; confirmed by reproduction. The spec already names ai-vector as a drift source, so this was known but unowned.
- **Refutation:** considered "the deployer catalogue classifies `embeddings` as a runtime artifact table, so it's intended". Not a refutation: `FrameworkRuntimeTableCatalogue` governs deploy handoff, not schema authority, and the spec requires migration ownership or a separate file.
- **Disposition and owner:** repair; #3138 (FW-AIV-PERSIST-01). FETDER was unblocked on 2026-09-23 by a guarded one-time manifest re-record (fetder-waaseyaa#160); the repair prevents recurrence.
- **Dependencies:** #3110 (Foundation schema adoption) and AIV-PERSIST-002.
- **Acceptance:** the probe's two drift cases (the delete and the draft-node save) flip; save, delete and search leave the manifest fingerprint unchanged (search is source-reviewed here, not reproduced by the probe, so the repair needs its own search case); strict verification and the next coordinated transition stay green on a real SQLite file.
- **Residual risk:** databases already drifted need a supported adoption path (#3110).
- **Next action:** decided 2026-09-23: a migration-owned table on the authoritative database; already-drifted databases use the documented re-adoption procedure (`docs/specs/ai-integration.md`), with general adoption left to #3110. Remediation candidate in #3138.

### `AIV-PERSIST-002`: raw PDO and SQLite-only SQL on a driver-agnostic connection

- **Observed, with evidence:** `SqliteEmbeddingStorage` takes `\PDO`, sets `ERRMODE_EXCEPTION` on the shared connection, and uses `INSERT OR REPLACE` (`src/SqliteEmbeddingStorage.php:15-42`). `HttpKernel` passes `getNativeConnection()` for whatever driver the application uses.
- **Expected contract:** supporting tables go through `DatabaseInterface`; raw PDO is a listed forbidden pattern (repository entity-storage invariant).
- **Consequence and consumers:** on MySQL or Postgres, `store()` fails. The listener logs the failure, so no vectors are ever written and semantic search silently returns nothing. The constructor also changes the shared connection's error mode.
- **Severity and confidence:** medium; confirmed by source review. Not run against a non-SQLite database.
- **Refutation:** none found.
- **Disposition and owner:** repair; #3138 (FW-AIV-PERSIST-01).
- **Dependencies:** AIV-PERSIST-001's storage decision.
- **Acceptance:** storage uses the framework database layer; the store/search/delete contract passes on SQLite and one server database; the shared connection's attributes are unchanged.
- **Residual risk:** none after the repair.
- **Next action:** remediation candidate in #3138. Server-database qualification moved to #3140.

### `AIV-COMP-001`: three separately constructed storage and provider instances

- **Observed, with evidence:**
  - `AiVectorServiceProvider` binds an `EmbeddingStorageInterface` singleton and a provider.
  - `HttpKernel` builds another `SqliteEmbeddingStorage` and calls `EmbeddingProviderFactory::fromConfig()` again for the listeners (`EventListenerRegistrar.php:147`). Since FW-AIV-PERSIST-01 it builds a `DatabaseEmbeddingStorage` instead; the duplication is unchanged.
  - `SearchRouter` builds a third storage and provider on every request.
  - All three are hard-wired to the SQLite class, so the interface binding can't substitute storage for HTTP.
- **Expected contract:** one composition owner and one selected storage, shared by listeners, HTTP, CLI and tools.
- **Consequence and consumers:** a host that rebinds `EmbeddingStorageInterface` changes only the CLI warmer, not indexing or search. Provider configuration is resolved three times.
- **Severity and confidence:** medium; confirmed.
- **Refutation:** considered "Foundation's kernel and router directories are documented layer exemptions". That covers the imports, not three divergent compositions.
- **Disposition and owner:** repair; WP-B.
- **Dependencies:** WP-A's storage decision.
- **Acceptance:** listeners, `SearchRouter`, the warmer and tools resolve the same bound storage and provider, proven by a rebinding test.
- **Residual risk:** none.
- **Next action:** WP-B design.

### `AIV-COMP-002`: lifecycle listeners exist only under `HttpKernel`

- **Observed, with evidence:** `registerEmbeddingLifecycleListeners()` is called only from `HttpKernel.php:216`; nothing registers it under `ConsoleKernel`.
- **Expected contract:** entity lifecycle side effects behave the same across the supported entry points, or the difference is documented.
- **Consequence and consumers:** entities saved, unpublished or deleted from the CLI, imports or workers keep stale vectors, or orphaned vectors for deleted entities, until `semantic:refresh` runs.
- **Severity and confidence:** medium; confirmed by source review.
- **Refutation:** considered "`semantic:refresh` is the intended reconcile path". It exists, but nothing says HTTP-only indexing is the contract.
- **Disposition and owner:** repair; WP-B.
- **Dependencies:** AIV-COMP-001.
- **Acceptance:** a CLI-profile save and delete update the stored vectors, or the documented contract states that reconcile is required.
- **Residual risk:** none.
- **Next action:** WP-B.

### `AIV-BACKEND-001`: `pgvector` advertised but never used

- **Observed, with evidence:** `SovereigntyDefaults` sets `embeddings` and `vector_store` to `pgvector` for the `northops` profile. No ai-vector or Foundation code reads either key; every composition path builds `SqliteEmbeddingStorage` (`DatabaseEmbeddingStorage` since FW-AIV-PERSIST-01), and `findSimilar()` scans every row of a type in PHP.
- **Expected contract:** an advertised backend is implemented, or a request for it is refused clearly.
- **Consequence and consumers:** a northops deployment believes it has pgvector and silently gets SQLite storage in its application database, with full-scan search.
- **Severity and confidence:** medium; confirmed.
- **Refutation:** none found.
- **Disposition and owner:** implement and qualify, or stop advertising; WP-B.
- **Dependencies:** AIV-COMP-001.
- **Acceptance:** every advertised backend has a composition path and a qualification run, or selecting it fails with a clear message.
- **Residual risk:** none.
- **Next action:** WP-B decision.

### `AIV-SEC-001`: search response metadata can disclose entities removed from the results

- **Observed, with evidence (safe summary):** under a specific configuration, the public search endpoint's response metadata can include identifiers and ranking data for entities that the visibility or access filters removed from the result list. Reproduced with a synthetic unit-level probe. The probe and details are withheld from this public record.
- **Expected contract:** a search response reveals nothing about entities the caller can't see.
- **Consequence and consumers:** applications that enable semantic search. Scope and severity will be assessed in a private report. The report is in private triage.
- **Severity and confidence:** withheld from this record; confirmed. Maintainer-side triage (2026-09-23): high static confidence, first in the exploitability queue.
- **Refutation:** none found.
- **Disposition and owner:** repair through the private reporting route (`SECURITY.md`).
- **Dependencies:** none.
- **Acceptance:** to be defined in the private report once it is filed.
- **Residual risk:** to be defined in the private report once it is filed.
- **Next action:** the maintainer authorizes and files the private report.

### `AIV-HTTP-001`: every semantic search loads all relationship entities

- **Observed, with evidence:** `SearchController::rerankWithRelationshipContext()` runs an unfiltered relationship query and `findMany()` over every result, on each semantic search, whenever the relationship entity type exists (`src/SearchController.php`, rerank method). The route is `allowAll()` (`BuiltinRouteRegistrar.php`, `api.search`).
- **Expected contract:** per-request cost on a public route is bounded by the result size.
- **Consequence and consumers:** search cost grows with the whole relationship table, on an anonymous endpoint.
- **Severity and confidence:** medium; confirmed by review, not load-tested.
- **Refutation:** none found.
- **Disposition and owner:** repair; handled alongside AIV-SEC-001 because both touch the rerank path.
- **Dependencies:** none.
- **Acceptance:** the rerank query is limited to relationships touching the candidate IDs, with a bounded-cost test.
- **Residual risk:** none.
- **Next action:** pair with the AIV-SEC-001 fix.

### `AIV-DOMAIN-001`: every non-node entity type is indexed and sent to the provider

- **Observed, with evidence:** `EntityEmbeddingListener::isIndexable()` returns true for any entity type other than `node` (`src/EntityEmbeddingListener.php:149`). The text built from `title`, `name`, `body`, `description` and the label is sent to the configured provider on every save.
- **Expected contract:** an explicit policy for which entity types and fields are indexed, and whether their content may leave the host.
- **Consequence and consumers:** with a remote provider configured, content such as user names can be sent to a third-party API. This is a data-sovereignty question as much as a search question.
- **Severity and confidence:** medium; confirmed by review.
- **Refutation:** considered "only nodes need publication checks". That explains the node branch, not indexing everything else.
- **Disposition and owner:** repair or document; WP-C, with a sovereignty review.
- **Dependencies:** none.
- **Acceptance:** indexable types and fields are declared; undeclared types are never embedded; tests cover a user-like type.
- **Residual risk:** existing stored vectors for newly excluded types need removal.
- **Next action:** WP-C policy decision.

### `AIV-EXEC-001`: synchronous embedding on save; the queue message has no handler

- **Observed, with evidence:**
  - The listener calls the provider's HTTP endpoint inline during the save, with a 15–20 s timeout. Failures of this embed-and-store step are logged and swallowed. The delete paths aren't; see AIV-EXEC-002.
  - It can also dispatch a `GenericMessage` of type `ai_vector.embed_entity`, but every production construction passes `queue: null`, and no handler for that type exists in the repository.
- **Expected contract:** remote work on the save path is bounded or asynchronous, and no dead dispatch path is left in place.
- **Consequence and consumers:** entity saves over HTTP wait on the embedding provider when one is configured. The queue path is dead code.
- **Severity and confidence:** medium; confirmed.
- **Refutation:** none found.
- **Disposition and owner:** wire async indexing with a handler, or remove the message; WP-C.
- **Dependencies:** AIV-COMP-001.
- **Acceptance:** saves don't block on the provider, or the synchronous behavior is documented, and the message either has a handler with tests or is removed.
- **Residual risk:** none.
- **Next action:** WP-C.

### `AIV-EXEC-002`: storage failures on the delete paths fail an already-committed entity mutation

- **Observed, with evidence:**
  - `EntityEmbeddingCleanupListener::onPostDelete()` calls `storage->delete()` with no `try` (`src/EntityEmbeddingCleanupListener.php`).
  - In `EntityEmbeddingListener`, only the embed-and-store block is inside `try` (`src/EntityEmbeddingListener.php:121-132`). The non-indexable branch's `storage->delete()` (lines 111-114) and the `repository->find()` re-sourcing (line 102) are outside it.
  - POST_SAVE and POST_DELETE are notification events, buffered and dispatched only after the entity mutation commits (`packages/entity-storage/src/EntityRepository.php`, `dispatchEvent()` and its delete path at lines 1539-1543).
  - A listener exception there becomes `EntityMutationCommittedSideEffectsFailedException` (`EntityRepository.php:832`).
  - Traced in source; not reproduced end to end through a real repository.
- **Expected contract:** a best-effort side effect of a committed mutation logs its failure and doesn't turn the committed mutation into a reported failure (repository guidance on best-effort side effects), or the failure contract is documented.
- **Consequence and consumers:** if the vector storage fails after an entity delete or save (a locked or read-only database, the lazy DDL failing, a table with the wrong shape):
  - the entity change is committed, but the caller, such as the HTTP API, is told the mutation's side effects failed;
  - the entity's vector may remain;
  - POST_* listeners registered after these for the same event don't run, because the dispatcher stops at the exception.
- **Severity and confidence:** high; confirmed by source trace.
- **Refutation:** considered "`EntityEmbeddingListener` catches failures". It does, but only around embed-and-store, not the delete branch or the re-sourcing read. The cleanup listener catches nothing.
- **Disposition and owner:** repair; WP-A, because it touches the same storage calls as AIV-PERSIST-001.
- **Dependencies:** none; it can land with WP-A.
- **Acceptance:** with a storage that fails on delete, an entity delete and a non-indexable save through a real repository report success, log the failure, and let later listeners run.
- **Residual risk:** a vector left behind after a failed cleanup until `semantic:refresh` runs.
- **Next action:** include in WP-A with an end-to-end regression test.

### `AIV-PUBLIC-001`: two storage contracts; declarations don't match

- **Observed, with evidence:**
  - Production uses `EmbeddingStorageInterface`. A second family (`VectorStoreInterface`, `InMemoryVectorStore`, `EntityEmbedder`, `EntityEmbedding`, `SimilarityResult`, `DistanceMetric`) has no production implementation or caller; ai-tools `VectorSearchTool` documents it but duck-types.
  - `public-surface.php` declares 5 symbols, while `@api` also marks `AiVectorServiceProvider`, `EntityEmbedder`, `EntityEmbedding`, `InMemoryVectorStore`, `OllamaEmbeddingProvider`, `OpenAiEmbeddingProvider`, `SimilarityResult`, `FakeEmbeddingProvider` and two listener methods.
  - The README names `VectorStoreInterface` as a key class.
  - `SearchController` states a "stable" v1.0 search contract that no declaration covers.
- **Expected contract:** one canonical storage contract, and declarations that match real consumers.
- **Consequence and consumers:** extension authors can't tell which contract is supported; removing either family later is a compatibility break.
- **Severity and confidence:** low; confirmed.
- **Refutation:** none found.
- **Disposition and owner:** choose the canonical contract; deprecate or remove the other with a compatibility note; reconcile declarations; WP-C.
- **Dependencies:** WP-A and WP-B settle the storage shape first.
- **Acceptance:** public-surface parity, a declaration for the search wire contract, and an updated README.
- **Residual risk:** downstream users of the second family, if any exist outside this repository.
- **Next action:** WP-C.

### `AIV-DIST-001`: installed by default through `waaseyaa/cli`; a test helper ships in production

- **Observed, with evidence:**
  - `packages/cli/composer.json` has a runtime `require` on `waaseyaa/ai-vector`.
  - Once installed, `HttpKernel` activates the lifecycle listeners purely on `class_exists`.
  - `src/Testing/FakeEmbeddingProvider.php` is in the production autoload.
- **Expected contract:** an AI capability that writes to the application database is opt-in; test helpers live under `autoload-dev`.
- **Consequence and consumers:** almost every application gets vector storage side effects without choosing them. This is how FETDER gained `embeddings`.
- **Severity and confidence:** medium; confirmed.
- **Refutation:** considered "`class_exists` gating makes it optional". Only for applications without the CLI.
- **Disposition and owner:** repair; WP-B.
- **Dependencies:** AIV-COMP-001.
- **Acceptance:** the CLI no longer requires ai-vector at runtime, or activation needs explicit config; installed and absent profiles are both qualified.
- **Residual risk:** applications relying on implicit installation need an upgrade note.
- **Next action:** WP-B.

### `AIV-SYMFONY-001`: hand-rolled HTTP client in both providers

- **Observed, with evidence:** both providers call `file_get_contents()` with a stream context. There's no retry, the timeouts are fixed, and errors are detected by string checks.
- **Expected contract:** outbound HTTP uses the framework's client where one fits.
- **Consequence and consumers:** duplicated transport code and weak failure semantics.
- **Severity and confidence:** low; likely. The fit of `waaseyaa/http-client` was not verified.
- **Refutation:** not assessed.
- **Disposition and owner:** defer; WP-C.
- **Dependencies:** AIV-EXEC-001.
- **Acceptance:** an equivalence test for timeout, error and response-shape handling if replaced.
- **Residual risk:** none.
- **Next action:** evaluate during WP-C.

### Refuted leads

- **`AIV-R-001`, "any first entity save creates the table":** refuted by probe case 4. A non-node save with no provider neither stores nor deletes. Creation needs a delete, a non-indexable node save, a configured provider, or a semantic search.
- **`AIV-R-002`, "the package imports undeclared dependencies":** refuted. Every `Waaseyaa\*` import is within the declared `require` list, and `bin/check-package-layers` passes.
- **`AIV-R-003`, "the in-`src` test helper breaks `--no-dev` boot":** refuted by review. `FakeEmbeddingProvider` extends nothing and uses no dev-only symbol. Its location is still a finding (AIV-DIST-001).
- **`AIV-R-004`, "keyword-mode search creates the table":** refuted by review. With no provider, `SearchController` uses repository keyword queries and never calls storage.

## Profile checklists

- **Persistence and execution:**
  - Storage access and schema authority: AIV-PERSIST-001 and AIV-PERSIST-002.
  - The deployer catalogue lists `embeddings` as a runtime artifact table, which is consistent with the table's existence but not with schema authority.
  - Backend claims: AIV-BACKEND-001.
  - Upgrade on an existing database: not applicable until migration ownership exists.
  - Transactions: store, delete and search are single statements.
  - Counted limits: none.
  - Retries and idempotency: `INSERT OR REPLACE` is idempotent per entity.
  - Reported versus durable outcome: embed-and-store failures are logged and swallowed (AIV-EXEC-001). Delete-path failures aren't; they surface as a committed-side-effects failure of the entity mutation (AIV-EXEC-002).
  - Restart and recovery: `semantic:refresh` reconciles.
- **Kernel and runtime:**
  - Composition: AIV-COMP-001. Profile differences: AIV-COMP-002.
  - Early resolution: the provider binding resolves config once at register time.
  - Boot failure: a misconfigured OpenAI credential throws at register time, from the provider and again from `HttpKernel`'s listener wiring, and fails closed (reviewed, acceptable).
  - Boot retry and state: ai-vector keeps no state between attempts, because its bindings are closures. A retry with the same config fails the same way. Kernel-level retry state belongs to Foundation (#3123).
  - Repeated execution: the storage caches its schema check per instance, and `SearchRouter` creates a new instance per request.
- **Domain contracts:**
  - Invariants and lifecycle: indexing follows served content (CW-v1 option 1) for HTTP saves, pointer moves and reverts (reviewed).
  - Indexability: AIV-DOMAIN-001.
  - Events: POST_SAVE, POST_DELETE, `RevisionPointerMovedEvent` and REVISION_REVERTED, registered only by `HttpKernel` (AIV-COMP-002), after the discovery and MCP read-cache listeners.
  - Ordering and veto: these are notification events dispatched after commit, so they can't veto a mutation. A listener exception stops later listeners for the same event (AIV-EXEC-002).
  - Extension seams: AIV-PUBLIC-001.
- **HTTP, UI and wire contracts:**
  - The route is `allowAll()`.
  - Validation: missing `q` or `type` gives 400; an unknown type gives 404; the package being absent gives 501.
  - Refusals: provider failure falls back to keyword mode and says so in `meta`.
  - Access filtering applies to `data`: AIV-SEC-001.
  - Cost: AIV-HTTP-001.
  - Wire contract declaration: AIV-PUBLIC-001.
  - Contract conformance: `SearchController` states a "stable" v1.0 contract, but there's no declared schema and no conformance test, so conformance can't be checked. Only the unit tests' response shapes were reviewed. This remains open.
  - No CSRF concern: the route is GET only.
- **Distribution:**
  - Installation profiles: AIV-DIST-001.
  - `core` and `cms` exclude the package; `full` and `cli` include it.
  - Foundation's `class_exists` guards cover absence (search returns 501; no listeners).
  - The split package and `--no-dev` install were not qualified (see below).

## Not reviewed

- The split-package install, a `--no-dev` install, a generated application, and a real server-database (MySQL or Postgres) run. All are needed for WP-D.
- The `semantic:warm` and `semantic:refresh` handlers beyond their use of `SemanticIndexWarmer`.
- ai-tools `VectorSearchTool` behavior. It's owned by ai-tools; only its dependency on these interfaces was checked.
- Load or latency measurement for AIV-HTTP-001 and AIV-EXEC-001.
- The Deptrac model: none exists for this package. Its configuration shape is coordinated with #3075.

## Host limits

Everything in this record ran on native Windows 11 with PHP 8.5.5. Nothing here depends on symlinks or POSIX-only gates. Hosted Linux CI owns the repository gates for this change.

## Evidence

| Command or probe | Base | Dependency identity | Runner | Proves | Result |
| --- | --- | --- | --- | --- | --- |
| `php tests/Fixtures/Audits/AiVector/embeddings-schema-drift-probe.php` | `bfba7f27d7a27a2228649bc75967fb1d261856c0` | lock `1c0df008…fcfe7a48` | local, native Windows, PHP 8.5.5 | real classes and `EntitySchemaSyncRunner` on a throwaway SQLite file | 4 cases, all as recorded: the two drift cases are refused with `[S1-DB109]`; the control and the non-node save succeed |
| `php vendor/bin/phpunit packages/ai-vector/tests --no-coverage` | same | same | same | source read (unit, mocked) | 89 tests, 306 assertions pass |
| `php vendor/bin/phpunit tests/Integration/Phase8/VectorSearchIntegrationTest.php tests/Integration/Phase15/SemanticWarmBaselineIntegrationTest.php --no-coverage` | same | same | same | injected integration | 12 tests, 62 assertions pass |
| `php bin/check-package-layers` | same | same | same | declared dependency layers | pass |
| Private probe for AIV-SEC-001 | same | same | same | synthetic unit reproduction | withheld; kept with the private report, which is in private triage |

## Proposed remediation split (for maintainer approval; no issues opened)

- **WP-A, persistence and FETDER unblock:** AIV-PERSIST-001, AIV-PERSIST-002 and AIV-EXEC-002. Decide migration-owned versus dedicated projection storage, move off raw PDO, remove serving-path DDL, prove save/delete/search can't cause drift. Coordinates with #3110.
- **WP-B, composition, backends and installation:** AIV-COMP-001, AIV-COMP-002, AIV-BACKEND-001 and AIV-DIST-001. One storage and provider composition path for every entry point; implement or stop advertising pgvector; make the capability opt-in.
- **WP-C, public API, indexing policy and execution:** AIV-PUBLIC-001, AIV-DOMAIN-001, AIV-EXEC-001 and AIV-SYMFONY-001.
- **WP-SEC, private:** AIV-SEC-001, with AIV-HTTP-001 on the same code path.
- **WP-D, qualification:** source tests, the real SQLite schema-authority regression, split `--no-dev` install, a `waaseyaa/full` consumer, a generated application, package absence, and every advertised backend. Exact-head hosted CI and independent review.
