# FW-AIV-COMP-01 — one composition owner and lifecycle contract for ai-vector

- Forge mirror: `waaseyaa/framework#3139` (parent #3137, program #3118)
- Findings: AIV-COMP-001, AIV-COMP-002, AIV-EXEC-002 in `docs/audits/packages/ai-vector.md`
- Base: `3479dec2fcce623e68fa9ad3fe21a0c0d2d9cc21`
- Branch: `claude/ai-vector-composition-3139`
- Landed: 2026-09-24 as `d58d526ab` through #3155 (governed squash of reviewed head `a43c4c1c8` onto `b21e36309`, tree identical to the reviewed head). `a43c4c1c8` merged `main` at `b21e36309` into the reviewed `8a738bc9d` without rewriting its commits; the feature diff was byte-identical. #3139 is closed.
- Depends on: #3138 (landed as `4512c0d9a`: `DatabaseEmbeddingStorage` and the migration-owned table)
- Related: #3140 (backend selection), #3142 (execution model, including asynchronous indexing)
- Authority: repository source, tests and PR. No release, publication or
  deployment. Landed with the maintainer's approval.

## Problem, reproduced at the base

Red tests at `55bbd6c5d` pinned all three defects before the fix.

- **AIV-COMP-001, two compositions.** `HttpKernel::finalizeBoot()` built its own `DatabaseEmbeddingStorage` and called `EmbeddingProviderFactory::fromConfig()` again for the listeners. So the listeners never used the storage and provider bound by `AiVectorServiceProvider`. `SearchRouter` built a third pair on every request.
- **AIV-COMP-002, no CLI lifecycle.** `ConsoleKernel` registered no ai-vector listeners. Entities deleted or unpublished from the CLI, imports or queue workers kept their vectors until `semantic:refresh` ran.
- **AIV-EXEC-002, post-commit failure surfaced.** When vector removal failed during the POST_SAVE of a committed unpublish, the save was reported as `EntityMutationCommittedSideEffectsFailedException`, and later listeners for the event didn't run.

## Decisions (maintainer, 2026-09-24)

- **D1, composition owner, option O1.** The storage and provider bound by `AiVectorServiceProvider` are the instances every entry point uses. Selecting a different storage backend belongs to #3140.
  - Rejected: a config key now (O2), and changing the kernel bus to "last binding wins" (O3).
  - #3139's acceptance was reworded from "host-bound" to "provider-bound".
  - **One rule for every consumer.** Every consumer resolves the two interfaces through the kernel services: the first provider to bind an interface wins, as it already did for search and for hosts reading the bus. By default that is `AiVectorServiceProvider`. If a provider that loads earlier binds either interface, every consumer uses that binding. The rule applies to each interface separately: ai-vector binds the embedding provider only when one is configured, so without one, a later provider's embedding-provider binding is the first and every consumer uses it. The storage and provider can come from different providers, but every consumer gets the same pair. This closes a split found in review, where the listeners and the warmer used ai-vector's local binding while search and the bus used the first binding.
- **D2, lifecycle outside HTTP, option B with safe invalidation.** Outside HTTP (CLI, imports, workers), a save or delete removes any existing vector, including a save of indexable content, and never calls the embedding provider. `semantic:refresh` re-indexes.
  - Rejected: embedding on every save everywhere (A), and keeping HTTP-only listeners (C).
- **D3, post-commit failures are best-effort.** They are logged and never surfaced as a failure of the committed mutation.

## Implementation

- **`AiVectorServiceProvider`** implements `ConfiguresHttpKernelInterface`.
  - `boot()` registers `EntityEmbeddingCleanupListener` and an `invalidateOnly` `EntityEmbeddingListener` in every kernel, using the bound storage and the kernel logger.
  - `configureHttpKernel()`, which only `HttpKernel` calls, swaps the save listener for the embedding one when a provider is bound. With no provider, HTTP saves invalidate like every other entry point.
  - Both are idempotent.
  - The listeners and the `SemanticIndexWarmer` binding get the storage and provider from the kernel services (`composedStorage()`, `composedProvider()`), not from the provider's local bindings. Without kernel services, when the provider is constructed bare, they fall back to its own bindings.
- **`EntityEmbeddingListener`** gains `invalidateOnly`: on a save or pointer move it removes the vector, without reading the entity or calling the provider. Every storage call and the re-sourcing read are best-effort: it catches, logs one error and returns. If the re-sourcing read fails, it removes the vector rather than leave it possibly stale.
- **`EntityEmbeddingCleanupListener`** gains an optional `?LoggerInterface`, and removal is best-effort.
- **`HttpKernel`** no longer composes ai-vector. It gives `SearchRouter` a resolver, `semanticSearchServices()`, which returns the storage and provider from the kernel services bus, by the same first-binding rule. It returns null when ai-vector isn't installed, and search then answers 501, as before.
- **`EventListenerRegistrar::registerEmbeddingLifecycleListeners()`** and the registrar's now-unused secret-registry parameter are removed.
- **Unchanged:** `SearchController`, the storage, the schema and the queue message. The queue dispatch stays with #3142.

## Evidence (native Windows host)

**New tests** in `tests/Integration/AiVector/` (root integration tests, since they boot kernels across several packages):

- **`EmbeddingCompositionTest` (8 tests)** boots real `HttpKernel` and `ConsoleKernel` instances from a temp project, with no symlinks. It asserts, by identity, that the HTTP listeners, search, the warmer and the bus use the bound storage and provider. It also covers:
  - HTTP without a provider invalidates;
  - the console kernel invalidates and holds no provider;
  - re-entered boot and HTTP configuration register each listener once;
  - provider order: a host provider that binds its own storage and provider and loads **before** ai-vector is what every consumer uses, over HTTP and in the console kernel; one that loads **after** is used by none while ai-vector binds both interfaces. The host-first tests fail against the provider as it was before the review fix, with the listeners on ai-vector's storage;
  - per interface: with no configured provider, ai-vector binds only the storage, so a host that loads after it and binds an embedding provider is the one the listeners, warmer, search and bus all use, and HTTP saves embed with it. This test fails if the provider is taken only from ai-vector's own configuration.
- **`ConsoleVectorInvalidationTest` (2 tests):** a console-kernel save of indexable content, and a delete, each remove an existing vector while a provider is configured.
  - That provider points at a closed port, so a design that embedded on save would keep the old vector.
  - With the base source swapped in, both tests fail.
- **`PostCommitVectorFailureTest` (2 tests):** through a real repository and unit of work, with a failing storage, a delete and an unpublish report success, log one error, and let later listeners run.

**Unit companions** (`#[CoversClass]`, public boundaries only):

- **`AiVectorServiceProviderTest`:** on a real dispatcher over an in-memory database:
  - `boot()` invalidates on save and delete;
  - a second boot registers each listener once;
  - `configureHttpKernel()` swaps in the embedding listener once when a provider is bound, and keeps invalidating without one;
  - the lifecycle listeners use a storage the kernel services return from an earlier binding. This fails if the listeners take the provider's local binding.
- **`EntityEmbeddingListenerTest`:** invalidate-only mode never calls the provider; failed removal and a failed re-sourcing read are both logged, not thrown.
- **`EntityEmbeddingCleanupListenerTest`:** a failed removal is logged, not thrown.
- **`HttpKernelTest`:** `/api/search` served through `HttpKernel::handle()` returns keyword mode with ai-vector composed, and 501 without it.
- **`SearchRouterTest`:** 501 when no embedding services are bound.

**Other checks:**
- Focused run: 218 tests and 862 assertions across the new tests, ai-vector, the affected foundation, CLI and ai-tools tests, and the Phase 8, 14, 15 and 24 integration tests, with no assertion failures.
  - Four errors are Windows teardown locks on SQLite WAL files. Two are pre-existing `HttpKernelTest` cases, which also fail on unmodified `main`; two are the new file-database `HttpKernelTest` cases, whose assertions pass.
  - Hosted Linux CI covers these.
- Governance:
  - `foundation` declares `waaseyaa/ai-vector` in `require-dev` for `HttpKernelTest`;
  - the S1 dependency-byte authority was regenerated with its governed writer, changing only the lock digest;
  - the S1 SQLite roster was regenerated for the new test-only constructions.
- `check-pr-preflight --full`: 46 of 47 gates pass. The failure is `check-dead-code`, on the same three `config` and `scheduler` findings that fail on unmodified `main` on this host.
- Both S1 installed-artifact contracts pass.
- Rebased onto `3479dec2f` after #3152 changed the lock and S1 authorities. The rebase kept all nine commits equivalent. Only the lock digest in `support/s1-sqlite-dependency-bytes.json` differs, regenerated with its governed writer; the lock and roster changes are the same lines as before. On the rebased head, 161 tests and 1515 assertions pass (the set below plus the coverage-index tests), `check-pr-preflight` passes 45 of 45 gates, and both S1 installed-artifact contracts pass. The runs in the two items below were made on the equivalent pre-rebase commits.
- After the second review fix (`e743631a6`, the per-interface rule): 148 tests and 630 assertions pass on the same set. `check-pr-preflight` passes 45 of 45 fast gates; `--full` fails only on the same host-only dead-code findings.
- After the first review fix (`416e9739c`): 147 tests and 613 assertions pass across `tests/Integration/AiVector`, the ai-vector package tests, `SearchRouterTest` and the Phase 8 and 15 integration tests. `HttpKernelTest`'s two search cases still hit only the Windows WAL teardown error. `check-pr-preflight --full` still passes 46 of 47 gates, with the same host-only dead-code findings, and both S1 installed-artifact contracts pass again.
- The #3138 drift probe still passes all 5 cases.
- Hosted: PR run `36036139251`, 56/56 checks on `a43c4c1c8`, with independent review finding nothing; post-merge `main` run `36038434121`, 54/54 jobs on `d58d526ab`.
