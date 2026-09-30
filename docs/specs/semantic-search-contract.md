# Canonical embedding storage and semantic-search contract

Change record: `FW-AIV-STORAGE-CONTRACT-01`, #3141 candidate 2.
Public declarations: `packages/ai-vector/public-surface.php`.

## Storage

`EmbeddingStorageInterface` is the only supported contract. The built-in
`DatabaseEmbeddingStorage` implements it on migrated SQLite and PostgreSQL.
The same `EmbeddingStorageContract` tests run on both drivers. Host-provided
implementations must run those tests before binding the interface.

| Operation | Promise |
| --- | --- |
| `store(type, id, vector)` | Atomically replaces the one vector for the exact type and string ID. Reject invalid inputs before mutation. No successful silent no-op. |
| `delete(type, id)` | Idempotent; a missing ID is success, an unavailable backend is failure. |
| `findSimilar(query, type, limit)` | Exact type filter, matching dimensions only, at most limit results. Nonpositive limit returns an empty list after validation. |

Entity type must not be blank; ID must not be empty. IDs remain strings,
including leading zeros, UUIDs and values beyond PHP's integer range. No
normalization or integer cast is permitted. Vectors are nonempty numeric
lists with finite integer/float components. Invalid inputs throw
`InvalidArgumentException`. Zero vectors are valid.

Results are exactly `{id: string, score: float}`. Score means cosine similarity
in `[-1,1]`, higher is more similar, with zero for a zero vector. Scaled
arithmetic avoids overflow/underflow for finite extremes. Order is descending
score then ascending bytewise ID. Incompatible dimensions are skipped and
logged; they are not zero-scored or padded. Missing schema refuses with
`[AIV-STORAGE-001]`, malformed persisted vectors with `[AIV-STORAGE-002]`.
Other storage errors propagate. Search never treats corruption or a failed
backend as an empty successful match set. No serving path creates schema.

Storage has no metadata, language variants, language fallback, arbitrary
filters, get/has methods, DTO objects or provider-specific distance metrics.
The removed `VectorStoreInterface` family cannot be losslessly adapted to this
contract. See UPGRADING.md for explicit migration instructions.

## Composition and indexing

`AiVectorServiceProvider` owns opt-in composition. All consumers use the first
kernel binding of the storage/provider interfaces: save/revision/delete
listeners, warmer and refresh, HTTP router, and host-wired `vector.search`.
CLI handlers consume the provider's deferred warmer callbacks.

Candidate 1's default-deny `EmbeddingIndexPolicy` remains authoritative. Both
save-time and refresh indexing read only configured fields; label is explicit,
off-host permission is explicit, and malformed policy refuses with
`AIV-POLICY-001`. Excluded or empty projections remove their vector. A failed
reindex attempts to delete the old vector, rather than leaving stale data.
The served row is the source, so a forward draft cannot replace the published
projection. If cleanup itself fails, storage remains unavailable; no claim of
successful cleanup is made.

Post-commit provider/indexing listeners catch/log failures so committed entity
mutations remain successful. Transactional source-invalidation failures instead
abort and roll back the source mutation atomically; they are not best-effort. Operator warm/refresh calls propagate failure and count only
confirmed operations. Both refresh paths delete an exact ID if it disappears
between query and hydration. They never report swallowed listener failures as
stored. A provider-less declared type retains `skipped_no_provider`; excluded
types can still be reconciled without a provider.

## Execution and reconciliation (FW-AIV-EXECUTION-01)

HTTP saves, served revision pointer moves and reverts index synchronously after
true commit. Other kernels invalidate only. Lifecycle indexing and CLI
warm/refresh use one `EmbeddingExecutor` freshness protocol and fresh repository
reads, never an event snapshot or a preloaded entity chunk.

A served-source mutation emits `EntitySourceChangedEvent` after its writes and
inside its database transaction. The ai-vector subscriber advances the exact
identity's generation and invalidates potentially indexed vectors in that same transaction.
Publication obtains the generation lock, checks the operation token, rereads the
served projection without acquiring entity mutation locks, and replaces the
vector before releasing that lock. A concurrent source change must advance the
same generation before it can commit. Provider calls run outside source and
publication transactions. This closes the final-reread/publication race on both
supported backends; atomic vector replacement alone would not close it.
Generation tombstones survive deletion. Production invalidation is subscribed
only to the transactional source event, including HTTP mutations. There is no
post-delete or non-HTTP post-commit invalidation subscription: a delayed callback
must not advance a generation and delete a newer vector. HTTP post-commit indexing
is registered only with a configured provider. Superseded completion and failure
cleanup cannot overwrite or remove a newer vector. Only confirmed stores count
as stored; a superseded operation is not a successful store.

The built-in database guard requires source, generation and vector storage on
the same database connection. Missing generation schema refuses activation or
source mutation/execution. Custom storage requires a compatible explicitly bound guard qualified
for atomic source invalidation and publication, and a repository that supplies
fresh served reads. Cached custom repositories must participate in that protocol.
`EmbeddingStorageInterface` remains the sole storage contract. The retained
`invalidateOnly` mode refuses `[AIV-EXECUTION-008]` without mutation; applications
migrate to transactional source events. Standalone `EntityEmbeddingCleanupListener`
requires a fresh manager, locks the generation and verifies source absence before
removing a vector. Missing manager refuses without mutation.

### Never-indexed availability (FW-AIV-UNINDEXED-AVAILABILITY-01)

The built-in guard records monotonic `potentially_indexed` history under the
same generation-row lock. Only a policy-undeclared identity with authoritative
history `0` can skip projection deletion during source mutation or pure cleanup.
Its source transaction still advances the token. Indexing intent and canonical
direct stores promote history to `1` before any provider or vector write; stores
preserve an existing token and commit history with vector replacement. Deletion,
exclusion and failure cleanup never clear that marker. Lifecycle and refresh
share this distinction; excluded cleanup does not manufacture indexing history.
Refresh counts such a bypass as processed, with zero stored and zero removed;
authoritative never-indexed proof does not claim a projection deletion.

This isolates healthy entity/generation state from projection outage for proven
never-indexed identities. Declared types, historical vectors, provider intent and
uncertain history keep transactional, fail-closed invalidation. Missing/corrupt
history refuses; arbitrary entity-database failure is not tolerated. Older
generation rows are conservatively historical after migration, even if they
originated only from source changes. Their availability is not promised here.
Custom guards retain their existing conservative contract unless qualified for
the same history invariant. Public storage/guard interface signatures are unchanged.

Quiesce older writers, migrate indexing history, and backfill legacy vectors
before activating the bypass. Standalone embeddings-only storage stays supported;
later execution activation requires backfill. Artifact installation preserves
serving tokens and tombstones, then conservatively records imported vector
identities as potentially indexed with truthful transformed-history evidence.
Operators still repair projection failures and run refresh; this does not add
deferred deletion, retries or broader outage availability for indexed entities.

| Operation | Built-in network-transfer budget |
| --- | --- |
| HTTP lifecycle `embedForSave()` | 2 seconds |
| CLI warm/refresh and semantic query, Ollama `embed()` | 15 seconds |
| CLI warm/refresh and semantic query, OpenAI `embed()` | 20 seconds |

Each transfer has one attempt, no retries or redirect following, verified TLS,
and a 1 MiB response cap. Symfony's total `max_duration` bounds connection and
body, alongside its inactivity timeout. These are network bounds, not a whole
HTTP request, credential-resolution, database, or multi-entity batch deadline.
The longer operator/query budgets preserve legitimate workloads exceeding the
save-time budget. Timeout, HTTP refusal and malformed response fail explicitly.
Post-commit provider failures are logged; operator failures propagate. Source
invalidation failures abort the source transaction. Operators monitor source
mutation errors and all post-commit indexing failures separately.
`AIV-EXECUTION-007` additionally identifies unconfirmed guarded cleanup. Cleanup
is conditional on the current token and is never reported successful if it fails.

HTTP custom providers must implement `EmbeddingSaveProviderInterface` and honor
its one-attempt two-second network obligation. Missing capability refuses
save-time embedding and invalidates. Arbitrary PHP providers or callable
transport overrides cannot be preempted; their owners must qualify the budget.

`waaseyaa/http-client` owns the maintained Symfony HTTP implementation.
ai-vector's minimal `EmbeddingHttpTransport` retains payload, status and JSON
validation, while providers retain credential and endpoint policy. The split
http-client package requires Symfony HttpClient ^7.4 and contracts ^3.0; the
candidate lock uses HttpClient 7.4.20. Symfony's native fallback supports hosts
without ext-curl. Existing StreamHttpClient and ai-agent transport consolidation
remain with their package maintainers, outside this slice.

There is no embedding queue producer, retry worker or automatic repair loop.
The unsupported listener `queue` argument, `ai_vector.embed_entity` dispatch and
queue dependency are removed. Application owners remove historical orphan
messages rather than replay them. The application operator owns reconciliation:
schedule a full `semantic:refresh` sweep at the application's declared freshness
SLA, monitor listener errors, nonzero command exits and eligible-content search
coverage, and rerun the sweep after provider/storage recovery. Committed source
changes invalidate immediately; coverage can remain absent until a successful
HTTP indexing attempt or scheduled/manual refresh. No immediate coverage promise
is made for imports, CLI saves, worker mutations or failed provider calls.

Policy configuration is immutable for a booted process. Deployments changing
projection or exclusion must quiesce old workers, apply migrations, purge
excluded vectors, restart with the new policy and run a full refresh before
claiming reconciled coverage. The generation guard is a content freshness fence,
not a durable cross-process policy-version authority. The default-deny policy,
explicit label/egress permission and AIV-POLICY-001 refusal remain unchanged.

## HTTP `semantic_search` v1.0

`GET /api/search?q=<nonblank>&type=<registered-type>&limit=<integer>` is served
by Foundation `SearchRouter` and `SearchController`. Default limit is 10,
clamped to 1..100. Direct controller blank queries and missing/blank route
parameters return 400; unknown types return 404. A missing activated vector
service returns 501. Backend, malformed provider vector, hydration or
serialization failures return sanitized 503 `SEMANTIC_SEARCH_UNAVAILABLE`.
Dependency-resolution failures at the router are sanitized 503 as well.

Success is JSON:API 1.1 `{jsonapi, data: [], meta}` conforming to
`packages/ai-vector/resources/semantic-search.schema.json`. Refusals conform to
`semantic-search-error.schema.json`, with no `data` or source exception text.
Entity and field access run before serialization, and nodes must satisfy the
canonical public workflow visibility rule. Deleted/missing hits are omitted.
Filtering may produce fewer than the requested limit; no refill is promised.

`data[].type/id` use the canonical JSON:API serializer: a string entity ID is
preserved, an integer entity ID uses its UUID when available. Semantic
`meta.scores[]` is in returned resource order, with `id` equal to `data[].id`,
`entity_id` equal to the exact storage ID, and `score` the cosine value.
`meta.score_semantics` is `cosine_similarity`. Empty semantic results contain
empty `data` and `scores` lists plus the complete contract envelope.

Required metadata: contract version `v1.0`, surface `semantic_search`, stability
`stable`, extension hooks `[graph_context_rerank]`, trimmed query, requested
type, effective limit and mode. No provider or vector payload is exposed.

Without a provider, mode is `keyword`; a provider exception also falls back to
keyword with paired `requested_mode: semantic` and
`fallback_reason: embedding_provider_error`. Keyword order is first occurrence
from title/name/body repository searches; it has no cosine scores. Backend
failure does not trigger keyword fallback.

Optional graph rerank orders the candidate set by `semantic + degree * 0.001`,
ties by original semantic order, and then applies visibility/access filtering.
When order changes, metadata includes `ranking: semantic+graph_context`, weights
`{semantic: 1.0, graph_context: 0.001}`, and object maps `score_breakdown` and
`graph_context_counts` keyed by visible storage `entity_id` only. Breakdown
contains semantic, graph_context, combined and zero-based base_rank. Combined
scores are ranking values and need not be within `[-1,1]`. The unmodified
cosine scores remain available separately. No hidden hit identity appears in
these response maps. Graph query cost remains #3143, not a conformance claim.

## MCP `vector.search` v1.0

ai-tools resolves the optional storage/provider lazily without an ai-vector
runtime dependency. It calls the canonical `findSimilar`, never legacy
`search`, and refuses malformed result arrays instead of interpreting DTOs.

Input is exactly `query` (nonblank string), optional `entity_type` (registered
nonblank string), optional `limit` (integer 1..50, default 10). Unknown keys,
invalid values and unknown type produce an error tool result before embedding.
Omitted type searches registered types; global order is descending cosine,
then bytewise type, then bytewise exact string ID. Deleted entities are always
omitted; per-entity view and field access follow the stock tool enforcement
contract. Capability-only hosts remain responsible for entity access.

Successful `structuredContent` and the JSON text content block represent
exactly `{results: [{entity_type, id, score, metadata}]}`, conforming to
`packages/ai-tools/resources/vector-search.schema.json`. ID is the exact
storage string; score is cosine. Metadata is an object of current hydrated,
JSON-normalized, viewable entity fields, not a stored embedding snapshot.
Empty results are `[]`; no raw vector or arbitrary provider result is emitted.

Missing optional services produce `vector_search_unavailable`. Resolver,
provider, storage, malformed result and hydration failures produce the existing
sanitized `vector.search` internal-error result. They never become successful
empty responses. Tool capability refusals remain the stock tool contract.

## Conformance evidence

Shared storage tests use real migrations and storage on SQLite and PostgreSQL.
Both drivers run failed-insert rollback, persisted-corruption refusal and
same-instance migration recovery tests. Test-only triggers/constraints force
the replacement INSERT to fail after DELETE; negative controls distinguish
rollback from committed deletion and corruption refusal from an empty success.
`VectorSearchIntegrationTest` exercises real storage through the
controller, router and MCP tool, checks nonempty/empty/optional/error responses
against the shipped schemas, and seeds invalid-schema controls. Synthetic
repository and account fixtures are labelled. Policy/composition and real
repository post-commit tests retain their independent boundary coverage.

Standalone cleanup uses `runWithCurrent` to lock the existing generation without
advancing it, reads fresh served source and deletes only if absent. An obsolete
delete callback therefore cannot cancel current in-flight indexing. Missing
generation refuses with AIV-EXECUTION-009. Custom guards must implement the same
non-superseding inspection semantics.
