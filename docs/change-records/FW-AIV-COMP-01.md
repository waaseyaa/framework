# FW-AIV-COMP-01 — one composition owner and lifecycle contract for ai-vector

- Forge mirror: `waaseyaa/framework#3139` (parent #3137, program #3118)
- Findings: AIV-COMP-001, AIV-COMP-002, AIV-EXEC-002 in `docs/audits/packages/ai-vector.md`
- Base: `eafd7a3fb25290c9afeb871e7c49b5404629e413`
- Branch: `claude/ai-vector-composition-3139`
- Depends on: #3138 (landed as `4512c0d9a`: `DatabaseEmbeddingStorage` and the migration-owned table)
- Related: #3140 (backend selection), #3142 (execution model, including asynchronous indexing)
- Authority: repository source, tests and PR. No release, publication or
  deployment. Landing needs the maintainer's approval.

## Problem, reproduced at the base

Red tests at `1e3baeb8a` pinned all three defects before the fix.

- **AIV-COMP-001, two compositions.** `HttpKernel::finalizeBoot()` built its own `DatabaseEmbeddingStorage` and called `EmbeddingProviderFactory::fromConfig()` again for the listeners. So the listeners never used the storage and provider bound by `AiVectorServiceProvider`. `SearchRouter` built a third pair on every request.
- **AIV-COMP-002, no CLI lifecycle.** `ConsoleKernel` registered no ai-vector listeners. Entities deleted or unpublished from the CLI, imports or queue workers kept their vectors until `semantic:refresh` ran.
- **AIV-EXEC-002, post-commit failure surfaced.** When vector removal failed during the POST_SAVE of a committed unpublish, the save was reported as `EntityMutationCommittedSideEffectsFailedException`, and later listeners for the event didn't run.

## Decisions (maintainer, 2026-09-24)

- **D1, composition owner, option O1.** The storage and provider bound by `AiVectorServiceProvider` are the instances every entry point uses. Selecting a different storage backend belongs to #3140.
  - Rejected: a config key now (O2), and changing the kernel bus to "last binding wins" (O3).
  - #3139's acceptance was reworded from "host-bound" to "provider-bound".
- **D2, lifecycle outside HTTP, option B with safe invalidation.** Outside HTTP (CLI, imports, workers), a save or delete removes any existing vector, including a save of indexable content, and never calls the embedding provider. `semantic:refresh` re-indexes.
  - Rejected: embedding on every save everywhere (A), and keeping HTTP-only listeners (C).
- **D3, post-commit failures are best-effort.** They are logged and never surfaced as a failure of the committed mutation.

## Implementation

- **`AiVectorServiceProvider`** implements `ConfiguresHttpKernelInterface`.
  - `boot()` registers `EntityEmbeddingCleanupListener` and an `invalidateOnly` `EntityEmbeddingListener` in every kernel, using the bound storage and the kernel logger.
  - `configureHttpKernel()`, which only `HttpKernel` calls, swaps the save listener for the embedding one when a provider is bound. With no provider, HTTP saves invalidate like every other entry point.
  - Both are idempotent.
- **`EntityEmbeddingListener`** gains `invalidateOnly`: on a save or pointer move it removes the vector, without reading the entity or calling the provider. Every storage call and the re-sourcing read are best-effort: it catches, logs one error and returns. If the re-sourcing read fails, it removes the vector rather than leave it possibly stale.
- **`EntityEmbeddingCleanupListener`** gains an optional `?LoggerInterface`, and removal is best-effort.
- **`HttpKernel`** no longer composes ai-vector. It gives `SearchRouter` a resolver, `semanticSearchServices()`, which returns the bound storage and provider from the kernel services bus. It returns null when ai-vector isn't installed, and search then answers 501, as before.
- **`EventListenerRegistrar::registerEmbeddingLifecycleListeners()`** and the registrar's now-unused secret-registry parameter are removed.
- **Unchanged:** `SearchController`, the storage, the schema and the queue message. The queue dispatch stays with #3142.

## Evidence (native Windows host)

**New tests** in `tests/Integration/AiVector/` (root integration tests, since they boot kernels across several packages):

- **`EmbeddingCompositionTest` (4 tests)** boots real `HttpKernel` and `ConsoleKernel` instances from a temp project, with no symlinks. It asserts, by identity, that the HTTP listeners, search, the warmer and the bus use the bound storage and provider. It also covers:
  - HTTP without a provider invalidates;
  - the console kernel invalidates and holds no provider;
  - re-entered boot and HTTP configuration register each listener once.
- **`ConsoleVectorInvalidationTest` (2 tests):** a console-kernel save of indexable content, and a delete, each remove an existing vector while a provider is configured.
  - That provider points at a closed port, so a design that embedded on save would keep the old vector.
  - With the base source swapped in, both tests fail.
- **`PostCommitVectorFailureTest` (2 tests):** through a real repository and unit of work, with a failing storage, a delete and an unpublish report success, log one error, and let later listeners run.

**Other checks:**
- `SearchRouterTest` has a new 501 case for when no embedding services are bound.
- Focused run: 208 tests across ai-vector, the affected foundation, CLI and ai-tools tests, and the Phase 8, 14, 15 and 24 integration tests. The only errors are two `HttpKernelTest` teardown errors (Windows SQLite WAL locks), which also occur on unmodified `main`.
- The #3138 drift probe still passes all 5 cases.
