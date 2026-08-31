# Authority and entrypoint map

This is a source-backed navigation map, **not** a declaration that each authority
is correctly wired. Baseline and status vocabulary are in [the index](README.md).
The owner column assigns audit work, not a new runtime abstraction.

| Boundary | Owner / collaborators | Authority to establish | Inspected entry/composition seam | Remaining proof |
|---|---|---|---|---|
| B01 Identity, persistence, access | A2 / A1,A4 | Entity identity, mutation policy and transaction ownership | `EntityTypeManagerFactory` → repository/driver; `EntityRepository` → `UnitOfWork`; real HTTP/tool/job callers | Driver SPI versus repository identity; transaction refusal, language/revision and actor parity |
| B02 Runtime/configuration lifetime | A3 / A2,A4,A6 | Bootstrap policy versus persisted configuration versus request/job state | `public/index.php` → `HttpKernel`; `ConsoleKernel`; shared `AbstractKernel::boot()`; provider registry; scoped queue authority | Full repeated boot/request/job isolation; static wiring versus real runtime wiring |
| B03 Deployment/recovery custody | A5 / A1,A2,A6 | Artifact / Preserve / IdentityMerge policy and recoverable handoff | `FrameworkRuntimeTableCatalogue` → `SqliteArtifactPreparer` → `SqliteArtifactInstaller` | Data/identity and schema semantics; live WAL, process-death and supported-platform recovery |
| B04 Schema ownership/evolution | A1 / A2,A3,A5 | Materialization, authored evolution, ledger, preview and diagnostics | Kernel schema/migration boot; CLI init/migration paths; materializer and compiler/ledger composition | Fresh/upgrade/replay across sql-blob and sql-column; mixed plans; deployer schema interaction |
| B05 Async effects/invalidation | A3 / A2,A4,A6 | Commit ownership, effect ordering, retry/cancellation and invalidation | `UnitOfWork`; `DatabaseFenceGuard::execute()` through `LeaseExecutionContext::effect()`; queue `Worker` | Outermost commit versus savepoint release; post-event failure isolation; duplicate delivery and external effects |
| B06 Adapter/tool parity | A4 / A2,A3 | Domain rules versus transport/auth/catalogue choices | Provider route registration; MCP provider/route provider; HTTP/API/GraphQL/tool/import boundaries | Same domain action under allowed/denied actors; catalogue contribution/conflict rules; optional feature boundaries |
| B07 Content/frontend/optional domains | A4 / A2,A3 | Entity/byte ownership, publication and projections, serve-time frontend policy | Admin provider → host → shared kernel access handler; media download router/audited source reader | Real browser/backend contracts; byte authorization; publication/index consistency; every optional package |
| B08 Evidence/distribution/retirement | A6 / all | Public surface, consumer availability, verification truth and removal criteria | Composer manifests, public-surface map, layer rules, contract tests, package/skeleton/release paths | Independent consumers; real composition tests; exact command exits; replacement before retirement |

## Source anchors and interpretation

### B01 — one mutation is more than one repository call

Start at `packages/foundation/src/Kernel/EntityTypeManagerFactory.php`,
`packages/entity-storage/src/EntityRepository.php` and `UnitOfWork.php` in that
package. A driver's ability to preserve an identity does not prove repository
hydration or externally visible refusal semantics. Existing #2670 owns divergent
SPI identity. CP01/#2728 exposed a real deletion-guard timing defect. Post-baseline
#2735 fixes guard-before-delete timing; it does not close #2733's untyped refusal
surface or #2734's outer-transaction/effect-order problem.

### B02 — distinguish scopes before claiming a singleton leak

`public/index.php` creates `HttpKernel` inside its handler, including the
FrankenPHP loop. Do not assume that entrypoint reuses one kernel. Shared boot is
`packages/foundation/src/Kernel/AbstractKernel.php`; HTTP, CLI and restricted
schema boot do not automatically exercise identical compositions.
`EnvLoader`, `ConfigLoader` and `RuntimePolicy` in the same directory establish
bootstrap inputs. Persisted configuration is a separate authority, not an
interchangeable environment-variable fallback.

`packages/queue/src/Envelope/ScopedQueueAuthorityRuntime.php` owns a job-scoped
wrapper, and `packages/queue/src/Worker/Worker.php` owns worker sequencing.
CP02's bounded pipeline/queue probes do not prove every real principal resolver
or full-kernel static reset. #2729 separately records a fully booted entity-manager
wiring mismatch; static setter tests must not stand in for that runtime path.

### B03 — public handoff API is not the same as deployment recipe wiring

Inspect `packages/deployer/src/RuntimeState/FrameworkRuntimeTableCatalogue.php`,
`SqliteArtifactPreparer.php` and `SqliteArtifactInstaller.php`. CP03 exercised
these APIs and carried schema/graph restoration concerns to #2548 and identity
remapping to #2549. A recipe not invoking an API does not make a public API dead.
Bounded caught-exception recovery is not power-loss qualification. #2498, #2499
and #2545 retain their distinct live-snapshot/observation/clone-authority scope.

### B04 — no backend can stand in for the other

Inspect `packages/foundation/src/Migration/MigrationLoader.php`, `Migrator.php`,
schema services, and the handlers under `packages/cli/src`. CP04 exercised replay
and rollback (#2730/#2731). CP05 exercised actual SQLite schema layout and
diagnostics (#2732/#2682), not a whole installed application. Its sql-blob and
sql-column results are separate cells. Pending #2706/#2712 candidates are not
evidence that the baseline already has root V2 discovery or fresh-install policy.

### B05 — transaction ownership crosses package boundaries

The real composition to examine is
`packages/scheduler/src/Fence/DatabaseFenceGuard.php` →
`packages/scheduler/src/Execution/LeaseExecutionContext.php` → durable effect
closure → repository's `UnitOfWork`. A nested savepoint release is not necessarily
the durable commit. #2734 is therefore a live production-path lead, not a
hypothetical cascading-listener scenario. A fix must also prove the existing save
path, not only delete. CP05 contributes **no** qualification of this boundary.

### B06 — an intentionally smaller network surface is not missing wiring

`packages/mcp/src/McpServiceProvider.php::resolvePublicAuth()` consults the
application binding and otherwise constructs a deliberately anonymous public-read
strategy, with explicit opt-ins and a separate durable-token audience.
`publicEndpointEnabled()` gates contribution. Absence of a default local auth
binding in `register()` is intentional; it avoids shadowing the application.
Do not revive CL-2/CL-3 as defects without testing the present route/tier contract.
Likewise, duplicated adapter text is only a lead until domain semantics are compared.

### B07 — inspect the current owner, not an obsolete fallback

`packages/admin-surface/src/AdminSurfaceServiceProvider.php::discoverAccessHandler()`
reads the shared kernel-services handler; it no longer rebuilds access policy from
the manifest as #831 described. Its host fails closed if the handler is absent.
Current media entrypoints include `packages/media/src/Http/Router/MediaDownloadRouter.php`
and `packages/media/src/Http/AuditedMediaDownloadSourceReader.php`; the historical
claim that there is no byte-serving path cannot be carried forward unchanged.
Neither source observation is a complete authorization/browser test.

### B08 — declaration, reachability and correctness are separate claims

The public-map tokenizer resolves named declarations but does not autoload or
instantiate them. The [isolated autoload probe](qualification.md) demonstrates
that four test-directory declarations have a different availability boundary.
Root-monorepo tests may supply development maps that a downstream consumer lacks.
The package layer map is complete; its descriptive documentation has one omission.
Neither fact substitutes for a real split-package consumer test.

## How a future finding changes this map

Record source SHA/lock, entrypoint, expected authority, observed behavior, positive
control, supported variants, existing issue owner, and explicit unqualified cases.
Distinguish a broken wire, competing authorities, an obsolete path, and an intended
adapter difference. Only then propose the smallest coherent repair or retirement.
Do not convert every source seam above into a new service, registry or CI gate.
