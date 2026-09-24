# FW-AIV-COMP-01 — one composition owner and lifecycle contract for ai-vector

- Forge mirror: `waaseyaa/framework#3139` (parent #3137, program #3118)
- Findings: AIV-COMP-001, AIV-COMP-002, AIV-EXEC-002 in `docs/audits/packages/ai-vector.md`
- Base: `eafd7a3fb25290c9afeb871e7c49b5404629e413`
- Branch: `claude/ai-vector-composition-3139`
- Depends on: #3138 (landed as `4512c0d9a`: `DatabaseEmbeddingStorage` and the migration-owned table)
- Related: #3140 (backend selection), #3142 (execution model, including asynchronous indexing)
- Status: **design and failing tests only, for review.** No implementation, push or PR yet.

## Problem, reproduced at the base

Two failing tests pin the current behavior.

- **AIV-COMP-001, two compositions.** `HttpKernel::finalizeBoot()` builds its own `DatabaseEmbeddingStorage` and calls `EmbeddingProviderFactory::fromConfig()` again for the listeners. So the listeners never use the storage and provider bound by `AiVectorServiceProvider`. `SearchRouter` builds a third pair on every request. Only the CLI warmer uses the bound instances. (Scratch spike: HTTP listener storage was object #180 and the provider-bound storage was #12.)
- **AIV-COMP-002, no CLI lifecycle.** `ConsoleKernel` registers no ai-vector listeners. Entities deleted or unpublished from the CLI, imports or queue workers keep their vectors until `semantic:refresh` runs.
- **AIV-EXEC-002, post-commit failure surfaced.** Through a real repository, when vector removal fails during the POST_SAVE of an unpublish, the committed save is reported as `EntityMutationCommittedSideEffectsFailedException` (`TransactionCompletionException`, "vector storage unavailable"). Later listeners for the event don't run.

## Decisions for review

### D1. Composition owner, and what "host-bound" means

`AiVectorServiceProvider` becomes the only composition owner.

- Its bound `EmbeddingStorageInterface` and `EmbeddingProviderInterface` instances are the ones used by the lifecycle listeners, `SearchRouter`, the warmer, and any host that resolves them from the kernel services bus. This includes a host wiring `vector.search`, whose resolver closures the tool container can't autowire.
- `HttpKernel` stops constructing storage or a provider for ai-vector, and `EventListenerRegistrar::registerEmbeddingLifecycleListeners()` is removed.

The open question is how a host replaces the storage. The kernel services bus returns the **first** provider that binds an abstract, and package providers load before application providers. So a host provider binding `EmbeddingStorageInterface` today is shadowed by ai-vector's binding. There is no framework-wide override convention.

| Option | Meaning | Cost |
| --- | --- | --- |
| **O1 (recommended).** Identity with ai-vector's bindings; host replacement belongs to #3140. | #3139 guarantees every entry point uses the one bound instance. Choosing a different storage backend is #3140's backend-selection work, which already owns "every advertised backend has a composition path". | #3139's first acceptance line is reworded from "a host-bound storage" to "the provider-bound storage". |
| O2. A config key now. | For example `ai.embedding_storage`, naming a service or class that ai-vector resolves instead of its default. | Introduces backend selection ahead of #3140. |
| O3. Framework-wide "last binding wins". | Change bus precedence so application providers override package bindings. | A foundation-wide behavior change affecting every package; out of proportion for this issue. |

### D2. Lifecycle contract for CLI, imports and workers

The listeners must also run outside HTTP, but they differ in cost. De-indexing (deleting a vector) is a local database write. Indexing calls the embedding provider over the network, synchronously, with a 15–20 s timeout (AIV-EXEC-001, #3142).

| Option | Meaning | Cost |
| --- | --- | --- |
| A. Full listeners in every kernel. | CLI and worker saves embed synchronously. | Bulk imports make one provider call per save; imports slow down or stall when the provider is slow or down. |
| **B (recommended).** Removal everywhere, embedding only over HTTP. | Every kernel removes vectors on delete and when an entity stops being indexable. Only HTTP embeds new or changed content. CLI and worker saves that change indexable content require `semantic:refresh`, documented and pinned by a test. | A CLI edit to indexable content leaves the previous vector in place until refresh (stale but not orphaned). |
| C. HTTP only, as now; document reconcile for everything. | Deletes and unpublishes from the CLI keep their vectors until refresh. | Orphaned and stale vectors persist, the current defect, now documented. |

Under B the indexing listener runs without a provider outside HTTP. It already behaves that way when no provider is configured: it removes vectors for non-indexable entities and stores nothing.

The provider has to know which entry point it's composing for. The proposal is a kernel-context signal the provider reads at `boot()`; the exact mechanism is settled during implementation.

### D3. Post-commit failures are best-effort (no choice needed)

Every storage and re-sourcing call in `EntityEmbeddingListener` and `EntityEmbeddingCleanupListener` catches `\Throwable`, logs one error through an injected `LoggerInterface` (default `NullLogger`), and returns. This is the repository's stated rule for non-critical post-commit side effects. The committed mutation is reported as successful, and later listeners run. `EntityEmbeddingCleanupListener` gains an optional `?LoggerInterface $logger = null`. The provider injects the kernel logger into both listeners.

## Boundaries

- `SearchController` is not changed. `SearchRouter` changes only where its storage and provider come from.
- Listener registration moves into `AiVectorServiceProvider::boot()`, guarded against double registration, as `RelationshipServiceProvider::boot()` does. That matters for long-lived workers that re-enter provider boot.
- No schema, migration or storage-format change.
- Backend selection, asynchronous indexing and the synchronous timeout stay with #3140 and #3142.

## Test plan

**Written now (red at the base):**

`packages/ai-vector/tests/Integration/EmbeddingCompositionTest.php` boots real kernels from a temp project whose root `composer.json` declares `AiVectorServiceProvider`. It uses no symlinks, so it runs on Windows too.

- `http_kernel_lifecycle_listeners_use_the_provider_bound_storage_and_provider`: red, because the listeners hold a different storage instance.
- `console_kernel_registers_lifecycle_listeners_with_the_provider_bound_storage`: red, because there are no listeners. It asserts only that both listeners exist and share the bound storage. This holds under D2 option A or B, not C.

`packages/ai-vector/tests/Integration/PostCommitVectorFailureTest.php` uses a real repository and unit of work from a booted `ConsoleKernel`. The listeners are registered explicitly with an always-failing storage, so the test is independent of D1 and D2.

- `a_committed_delete_succeeds_logs_and_lets_later_listeners_run_when_vector_cleanup_fails`: red, because the cleanup listener has no logger and doesn't catch failures.
- `a_committed_non_indexable_save_succeeds_logs_and_lets_later_listeners_run_when_vector_removal_fails`: red with `EntityMutationCommittedSideEffectsFailedException`.

**To write after the D1 and D2 decisions:**

- Search uses the bound storage and provider. It needs a seam in `SearchRouter`; the test is written with that seam.
- CLI indexable-save contract, depending on D2:
  - under B, a CLI save of indexable content stores nothing and the documented contract says `semantic:refresh` is required;
  - under A, it stores a vector.
- The listeners are registered once, even if provider boot re-enters.
- If D1 is O2, a configured storage replaces the default at every entry point.
- Updates to `AiVectorServiceProviderTest`, `EventListenerRegistrarTest` and `HttpKernelTest` for the moved registration.

**Qualification at candidate time:**
- focused ai-vector and foundation tests;
- the drift probe from #3138 (serving paths still create no schema);
- a FETDER-copy boot and smoke;
- exact-head hosted CI and independent review.
