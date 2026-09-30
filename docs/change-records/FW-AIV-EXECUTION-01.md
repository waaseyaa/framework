# FW-AIV-EXECUTION-01: bounded synchronous embedding execution

- Forge mirror: #3142; parent #3137 remains open.
- Base: `701dca820f21d828b3b287a49202f889b38d40b1`.
- Owner: root integration; disjoint implementation owners handle entity-storage,
  http-client transport and documentation. Independent subagent review examines
  immutable candidates. Hosted exact-head full qualification owns final verdict.
- Worktree: `C:/dev/waaseyaa/framework-worktrees/ai-vector-execution-3142`.
- Branch: `codex/3142-embedding-execution`.
- Lease: explicit retained ownership. The coordinator refuses Windows drive
  paths; preserve all other lanes without changing its validator.
- Status: implementation checkpoint. Independent review and current exact-head
  qualification are pending. Historical test passes do not qualify current edits.

## Inventory and bounded decision

HTTP save/revert/served-pointer listeners embed once after true commit when the
shared default-deny projection allows current served content. CLI warm/refresh
embeds synchronously per entity. Semantic HTTP and MCP queries embed directly;
built-in batch providers loop over single calls. All lifecycle mutations invalidate through the transactional source event;
non-HTTP kernels have no post-commit indexing subscription. The original listener was the sole generic
`ai_vector.embed_entity` producer, with no production queue injection or handler.
QueueServiceProvider registers JobHandler, not this message. Remove that producer,
listener constructor argument and ai-vector dependency without inventing a worker.

Retain synchronous indexing. Save transfer budget is two seconds; regular
Ollama calls retain 15 seconds and OpenAI calls 20 seconds. One attempt, no
redirects/retries, verified TLS and a 1 MiB response bound apply. These bound
network transfer, not credentials, database work, whole requests or CLI batches.
Custom save providers must expose EmbeddingSaveProviderInterface and qualify its
budget. Missing capability refuses save indexing. PHP callbacks cannot be
forcibly preempted, so this limitation and host qualification obligation are
explicit rather than claiming universal runtime enforcement.

## Freshness and shared execution

EntityBase `postSave()` and `postDelete()` retain their transactional extension
contract for every host, including hosts without ai-vector. Only lifecycle
notification events are deferred until true commit. A throwing hook rolls back
source, authority, vector invalidation and related hook writes; a batch refusal
rolls back earlier database work without undoing in-memory hook invocations.
Enclosing transactions retain ownership of notification release or discard.
The architecture-review P2 at candidate `1cadf228` identified accidental hook
deferral; the follow-up restores the existing contract rather than introducing
a breaking migration. Real SQLite tests distinguish throwing save/delete hooks,
batch rollback and enclosing commit/rollback from after-commit notifications.

The inherited final-reread/publication race is in this slice's acceptance for
either execution model. Atomic vector replacement and a post-commit generation
advance alone do not prevent it, especially on PostgreSQL.

EntityRepository emits EntitySourceChangedEvent after served-source writes and
before true commit. The ai-vector subscriber advances the exact identity's
persistent generation and deletes its vector in that same source transaction.
Publication locks that generation, verifies its token, rereads the current served
projection without acquiring source mutation locks, and stores atomically under
the generation lock. A concurrent source change cannot commit before advancing
that same generation. Network calls occur outside entity and publication
transactions. Source invalidation is fail-closed: any failure rolls back the
source mutation atomically, unlike best-effort post-commit provider failure.
This applies even to policy-excluded entity types: removing any old vector is
part of source commit, so unavailable embedding storage can block otherwise
unrelated saves when ai-vector is active. That optional-capability failure impact
remains an explicit maintainer acceptance tradeoff, not a hook migration.
Production post-delete and non-HTTP/invalidate-only callbacks are removed.
Delayed callbacks cannot mint a newer generation and erase an already newer
vector. HTTP post-commit indexing is wired only with a configured provider.
The retained invalidateOnly constructor mode refuses AIV-EXECUTION-008 without
mutation; callers migrate to EntitySourceChangedEvent. Standalone cleanup needs
a fresh entity manager, locks the generation and verifies current absence before
deleting; missing manager refuses without mutation. Deletion retains tombstones. Older success and older failure cleanup
are conditional on their token, so they cannot replace or delete a newer vector.
Lifecycle indexing and both refresh entrypoints share EmbeddingExecutor and fresh
repository reads. Preloaded chunks and event-snapshot publication are removed.

Built-in source/guard/vector components must share one database connection.
Missing migration schema and unsupported atomic topology refuse explicitly.
Custom storage stays on EmbeddingStorageInterface and binds a compatible guard
qualified for source invalidation, publication and rollback on its backend.
Custom repository caches must participate or provide uncached served reads.
Policy is immutable per boot: configuration rollout quiesces old workers,
migrates, purges exclusions, restarts and refreshes. This guard does not claim
a durable cross-process configuration version.

## AIV-SYMFONY-001 disposition and maintenance ownership

Transport/deadline changes fire the review trigger. Replace duplicated provider
HTTP mechanisms with maintained Symfony HttpClient under the existing
waaseyaa/http-client owner. The earlier package-local cURL draft and the argument
that Symfony was absent from composer.lock are superseded by maintainer direction.
The additive SymfonyHttpClient implements the existing HTTP-client boundary;
ai-vector's small EmbeddingHttpTransport keeps JSON/payload/status validation,
and providers keep credential, endpoint and network-classification policy.

The split dependency is symfony/http-client ^7.4 and contracts ^3.0, with
HttpClient 7.4.20 in this candidate lock. Symfony owns connection, TLS, streaming,
redirect and timeout mechanics. max_duration supplies total transfer enforcement,
alongside timeout for inactivity, and the adapter enforces the response cap.
Native fallback permits installation without ext-curl. Existing StreamHttpClient
and ai-agent transports remain outside this bounded repair; their maintainers
own consolidation at their next transport/retry/deadline change. Do not copy the
separately owned Symfony-governance lane into this candidate.

Equivalence acceptance covers real HTTP success and refusal, payload/credential
headers, JSON/vector validation, no redirects/retries, stalled headers/body and
trickling bodies, a regular operation exceeding two seconds, and installation
profiles. Freshness acceptance covers overlapping update, delete, unpublish,
exclusion and older failure across lifecycle and refresh using real SQLite and
PostgreSQL storage, including independent-connection source/publication races.

## Reconciliation and limitations

The application operator schedules a full semantic:refresh sweep at its declared
freshness SLA, monitors source-mutation errors and all post-commit indexing errors,
command failures and eligible search
coverage, and reruns reconciliation after provider/storage recovery. No retry
worker or automatic repair loop is supplied. Source changes invalidate at commit;
coverage can remain absent until successful HTTP indexing or scheduled/manual
refresh. Imports, CLI and worker mutations therefore have eventual coverage at
the application's reconciliation cadence. Historical orphan queue messages are
removed by the application owner, never replayed. Refresh failures propagate and
stored counts represent only confirmed stores. Failed cleanup is not success:
`AIV-EXECUTION-007` exposes unconfirmed cleanup, retaining the initiating failure
as the chained cause. Operators reconcile after restoring storage availability.

## Qualification and delivery boundary

Focused affected local suites and default preflight run on native Windows with
required hooks. No overlapping broad local runs. Candidate-local dependencies
and representative class origins are mandatory; no donor vendor or autoload
workaround is permitted. Independent subagents review immutable candidates,
including freshness, budgets/Symfony integration, compatibility and installation
profiles. Exact-head hosted full qualification supplies the complete verdict.
The split-artifact no-dev consumer executes the installed provider and Symfony
Native/Curl adapters against a synthetic loopback peer. Its origin guard refuses
source-checkout production classes; the native fallback is always exercised and
the cURL profile runs when that extension is installed. This is hosted-owned
installation evidence, not a local packaged-form qualification claim.

Open a PR after initial independent review, repair review/CI findings, then
report the green exact SHA and evidence for Russell and the separate architecture
review chat. Stop before merge. Do not close #3142/#3137, release, tag, deploy,
start #3143 or private AIV-SEC-001 work. No whole-package assessment/convergence
claim is made. Final review and qualification evidence remains pending here
until recorded against the immutable PR head.

Standalone cleanup uses `runWithCurrent` to lock the existing generation without
advancing it, reads fresh served source and deletes only if absent. An obsolete
delete callback therefore cannot cancel current in-flight indexing. Missing
generation refuses with AIV-EXECUTION-009. Custom guards must implement the same
non-superseding inspection semantics.

Artifact installation classifies `embedding_generations` as serving-owned
`Preserve` state in runtime catalogue version 4. The exact serving token and
deletion-tombstone set replaces artifact generation rows, including omission of
artifact-only identities. Real SQLite preparation tests exercise conflicting
tokens, tombstones and artifact-only rows. Embeddings remain artifact-owned
derived data under the existing rollout and reconciliation contract.

The split-artifact harness installs Symfony Process in a disposable test-tooling
graph because that job intentionally has no root development vendor tree. The
production probe loads only the installed no-dev consumer autoloader and retains
its origin refusal. Test tooling is neither a production dependency nor a source
autoload fallback. S1 dependency-byte authority binds the updated Composer lock;
the four reviewed database dependency byte digests remain unchanged.
