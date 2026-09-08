<!-- Spec reviewed 2026-09-05 - FW-SITE-BLUEPRINT-01D-2: the existing
boot-free site:init command accepts --decision-receipt for an exact approved
blueprint. Machine JSON writes preserve literal artifact bytes through raw
Symfony output. Coded GEN011/SITE050 refusals retain the existing envelope and
process exit normalization. Execution and evidence contracts are owned by
site-golden-path.md. -->

# CLI Console

<!-- Spec reviewed 2026-09-05 - #2638: `mcp:registry-manifest` is an optional
operator command gated on `waaseyaa/mcp`, sibling to `oidc:*` / `mcp:serve`.
It writes official Registry `server.json` to stdout and does not take over
the stdio JSON-RPC session. Discovery, optional-package gating, and the
rule that Layer 4 must not construct CLI command objects are unchanged. -->
<!-- #2846 slice 8 / FW-GENERATION-UNITS-08: site:init and site:doctor activate the shared unit authority; controlled apply binds the transported plan and reviewed state before staging. Other compiler migrations remain closed. -->

<!-- Spec reviewed 2026-09-03 - #2659: `mcp:serve` remains the optional,
local-development-only stdio command described below. Each `tools/call` now
binds its transport-owned correlation id into the inner AgentToolDispatcher as
well as the durable audit wrapper, so sanitized failure responses, safe log
contexts, reservations, and finalizations are joinable. The server's emergency
catch emits exception class and method only; exception messages, credentials,
arguments, and absolute paths reach neither stdout nor stderr. Command name,
profile, discovery, startup refusal, and exit-code semantics are unchanged.
Canonical transport detail and the real-SQLite proof live in ai-integration.md.
-->

<!-- Spec reviewed 2026-09-02 - #2442: `site:init` gains `--preset=minimal|editorial`,
an init-time-only shortcut resolved once by `SitePresetResolver` into an
ordinary `waaseyaa.site` answer document before the existing
`SiteManifestParser` → `SiteArtifactRendererFactory` → `SiteInitializationService`
pipeline runs; its non-interactive input is the closed, versioned
`waaseyaa.site-seed` v1 document. See "Site initialization" below and
"Init-time presets" in site-golden-path.md. No preset name is persisted, and no
second command path, transaction, validation, or ownership authority is
introduced. -->

<!-- Spec reviewed 2026-09-02 - #2828: the OIDC sibling of #2826. `OidcServiceProvider`
now implements `RequiresOptionalPackagesInterface` and gates `register()` and
`consoleCommands()` on `waaseyaa/oidc` (sentinel `SigningKeyRepository`), so a
`--no-dev` consumer without the OIDC package lists zero `oidc:*` commands
instead of commands whose handlers cannot resolve. See the `ai:*` paragraph
below for the shared contract; `tests/PackagedForm/check-cli-oidc-commands-optional`
proves the absent and present consumers from installed bytes. -->

<!-- Spec reviewed 2026-09-02 - #2810: `waaseyaa:version` / `bin/waaseyaa-version` provenance now binds Composer path installs that sit OUTSIDE the application root — the sibling-checkout topology (`../waaseyaa`, `../waaseyaa/packages/*`) used for local-main consumer development. This SUPERSEDES the 2026-07-14 R24 (#2020) note below, which recorded that lockfile dist paths outside the project root are rejected; that note is retained for history. The replacement safety contract is narrower than "inside the project root", not broader: candidates come only from `composer.lock` path-dist entries, a target must exist and be a directory, the reporter walks up from the resolved target to the nearest `.git` ancestor, and Git runs exactly once per discovered checkout root as `git -C <root> rev-parse HEAD` — never against an arbitrary path. The one-checkout invariant is enforced on the checkout ROOT, not the HEAD: two clones at the same SHA are reported as `multiple distinct Git checkout roots` with each root and HEAD named, and no single monorepo HEAD/root is published. Drift messages name the actual HEAD, the golden SHA, and the checkout; a target that is missing, outside any checkout, or unreadable by Git is reported by name and fails strict mode even when other path installs resolved. Human and `--json` output gain `pathMonorepoRoot` / per-package `checkoutRoot`. Command names, options, and exit-code semantics are unchanged. Canonical contract: version-provenance.md "Path-install topologies and the resolution contract". Acceptance: ComposerProvenanceReporterTest. -->

<!-- Spec reviewed 2026-08-27 - Framework #2621: migration dry-run and verify
diagnostic redaction consumes the shared RuntimePolicy production-like
classification instead of independently reading APP_ENV. -->

<!-- Spec reviewed 2026-08-27 - #2619: `about` receives the typed environment
and debug policy resolved from the kernel bootstrap inputs. It no longer reads
PHP environment superglobals independently, so operator output and boot policy
cannot disagree. Command name, arguments, exit code, and custom info overrides
are unchanged. -->

<!-- Spec reviewed 2026-08-26 - #2439: scaffold:auth publishes presentation files only and records per-file Framework provenance plus separate upstream/consumer digests in the versioned scaffold manifest. --check is read-only and reports added, removed, changed-upstream, changed-consumer, and conflict states; --strict is an explicit blocking policy. --accept-current updates only reviewed manifest baselines and never application files. Missing/malformed manifests fail safely without repair. The skeleton site audit invokes the check as a non-blocking diagnostic. Canonical ownership contract: auth-consumer-extensions.md. -->
<!-- Spec reviewed 2026-09-05 - #2833: scaffold:auth resolves upstream auth UI sources from the loaded waaseyaa/cli package sibling packages/admin/app when waaseyaa/framework is absent (ADR-004 direct-package consumers), in addition to in-tree and metapackage roots. -->
<!-- Spec reviewed 2026-08-26 - #2570: WorkflowsServiceProvider registers workflows:audit-serving-projection as a normal fully booted operator command. It is report-only by default; mutation requires one entity id plus the exact current finding fingerprint, and delegates the write to the guarded repository pointer operation. It is never a pre-boot, migration, request, or automatic repair path. The output contains selectors/projection metadata but no entity content. Full recovery procedure: operations-playbooks.md "Workflow Serving-Projection Recovery". -->
<!-- Spec reviewed 2026-08-26 - #2569: ValidationGateValidator now derives candidate publication from the supplied WorkflowState declaration rather than a literal state id. Command discovery, parsing, boot, I/O, and exit-code contracts are unchanged. -->
<!-- Spec reviewed 2026-08-16 - S1-FW-DB-03: workflows:backfill-state remains an explicit operator command, but every publication-pointer establishment now reads the current entity snapshot and supplies its opaque aggregate mutation token to setPublishedRevision(). A concurrent mutation therefore fails that item without emitting a pointer event or overwriting the newer aggregate; the command records the failure in its existing per-item accounting. Canonical concurrency contract: s1-concurrency-fencing.md. -->

<!-- Spec reviewed 2026-08-09 - issue #2322: HealthSchemaServiceProvider registers tenancy:repair-translation-peers <entity_type> with --dry-run and --json through CommunityTranslationPeerRepairHandler. It is a normal fully booted operator command, never a pre-boot exception, and performs no mutation unless invoked without --dry-run. -->
<!-- Spec reviewed 2026-08-13 - issue #2343 WP2: SiteServiceProvider registers the ordinary fully booted `site:init` command. Interactive mode asks plain-language product questions; automation supplies a complete `--answers` document. `--dry-run` is read-only and `--yes` is required for non-interactive publication. The handler delegates deterministic rendering and lock/journal/recovery authority to SiteInitializationService; it does not bypass the provider-neutral contract or create a pre-boot command. -->
<!-- Spec reviewed 2026-08-08 - Pre-boot maintenance commands: `ConsoleKernel` recognizes exactly `maintenance:on`, `maintenance:off`, and `maintenance:status` before framework or application boot. It loads environment settings, constructs the canonical maintenance commands directly from `MaintenanceSettings` and `MaintenanceState`, and performs only maintenance-flag I/O. These commands must not open a database, run migrations or entity-schema reconciliation, boot providers, or activate field access. They therefore remain available while the application database is missing, stale, or intentionally transitioning during a deployment. All other commands retain normal provider discovery and full console boot. Acceptance: ConsoleKernelTest. -->
<!-- Spec reviewed 2026-08-08 - Anokii boundary remediation: the canonical `db:init` command factory is reusable by the restricted console bootstrap, allowing schema initialization without booting application providers. Command options and normal provider discovery remain unchanged. -->
<!-- Spec reviewed 2026-08-29 - #2644: `site:init` and `site:doctor` both join the boot-free command seam. `SiteServiceProvider::siteInitCommand()` and `::siteDoctorCommand()` are now the single definitions of those commands, shared by ordinary provider discovery and by `ConsoleKernel::handle()`'s pre-boot branch, exactly as `ConfigCacheDbAuditServiceProvider::dbInitCommand()` is for `db:init`. Neither handler needs a container: both take only a project root, and `SiteArtifactRendererFactory::create()` composes its three recipes with `new`. `site:init` therefore leaves the restricted pre-boot set (which still opens the database) entirely, so the whole site-contract phase touches no database and `install:init` is the first command that creates one. The doctor reads only the filesystem, so nothing is lost; what is gained is that verification stops being a write. Ordinary CLI boot reaches `AbstractKernel::bootDatabase()` before every restricted-discovery guard, so verifying an uninitialized project created a zero-table `storage/waaseyaa.sqlite` plus `-wal`/`-shm` sidecars and then misreported the missing site contract as an inactive configuration generation. Command names, descriptions, options, and exit codes are unchanged. This SUPERSEDES the 2026-08-13 #2343 WP2 note above, which recorded `site:init` as an ordinary fully booted command that creates no pre-boot command; that note is retained for history. Acceptance: ConsoleKernelTest::siteContractCommandsRunWithoutBootingOrCreatingTheDatabase, SiteDoctorIsReadOnlyTest, and the ci/skeleton-create-project-windows lane that first caught the site:init case. -->

<!-- Spec reviewed 2026-07-25 - #2122 maintenance-mode commands: `MaintenanceServiceProvider` (ProvidesConsoleCommandsInterface) registers `maintenance:on` (`--retry-after`, `--message`), `maintenance:off`, and `maintenance:status` (`--json`) as conventional `HandlerCommand`s dispatching to `Maintenance{On,Off,Status}Handler`. The commands are idempotent with script-friendly exit codes (on/off → 0 on desired state reached, non-zero only on I/O failure; status → 0 serving, 1 in maintenance incl. fail-closed) so deploy tooling can bracket a DB swap. Their parsing, I/O, handler behavior, and normal provider registration remain unchanged; the 2026-08-08 contract above adds an equivalent pre-boot construction path for these exact names. Operator surface: docs/specs/operations-playbooks.md "Playbook I" + CLI Command Reference. Acceptance: MaintenanceCommandsTest. -->
<!-- Spec reviewed 2026-07-17 - #2064 WP2 adds optional reason-specific field-read declarations to HandlerCommand metadata. CliFieldReadCapabilityIssuer preserves the exact CLI-valid closed reason, opens an explicit live execution-boundary proof, and binds NoActingContext with a null actor; no CLI account principal or ambient privileged scope is created. -->
<!-- Spec reviewed 2026-07-17 - #2064 WP3 registers exact `field-access:preflight --format=json` names-only inventory output; it is read-only unless `--write-artifact` is supplied, and the artifact write is atomic. Normal entity accessor activation remains dormant. -->
<!-- Spec reviewed 2026-08-02 - #2171 `field-access:preflight` inventory keys are CANONICAL column names. `DatabaseFieldAccessInventoryScanner` reads names via `Waaseyaa\Database\Schema\TableColumnNames` rather than `array_keys(listTableColumns())`: Doctrine keys that map by the QUOTED identifier for reserved words, so a column named `key` previously entered the inventory as `<type>|*|"key"` — a live key no field definition could ever classify, leaving `unclassified_entries` non-empty and `ready` false forever for any consumer with a reserved-word column. The same literal also poisoned the schema fingerprint. Inventory key format is unchanged (`entityType|bundle|field`); what changed is that `field` is now the canonical name. Scanner generation is unchanged at 2. Acceptance: ReservedWordColumnPreflightTest. -->

<!-- Spec reviewed 2026-07-14 - R24 CLI minors (#2020): Composer provenance rejects lockfile dist paths outside the project root; production migration diagnostics redact absolute Unix/Windows files and bare directories; ai:run clears prior SIGINT state and describes the default synchronous Messenger bus honestly; entity:list documents its intentional operator-level access-check opt-out. CLI actor context remains null by design because the console has no authenticated principal; introducing a fabricated system identity is explicitly not part of this sweep. -->

<!-- Spec reviewed 2026-07-14 - R21 WP6 (#2010): queue:retry now shares FailedJobRepositoryInterface's atomic retry claim with the HTTP API. A claim loser exits 1 without dispatching; dispatch exceptions release the claim; successful dispatch forgets the row. Command parsing, IO, and registration contracts are unchanged. -->

<!-- Spec reviewed 2026-08-24 - #2524: packages/cli/src/AdminBuild/ hosts REPOSITORY BUILD TOOLING, not console commands. Only HermeticAdminBuildPipeline is reachable from a console command (admin:build via AdminBuildHandler); the AdminDist* classes (AdminDistWorkspaceGuard, AdminDistTreeInventory, AdminDistSourceMarkerPolicy, AdminDistAcceptance, AdminDistAcceptanceManifest, AdminDistAcceptanceVerifier, AdminDistAcceptanceResult, AdminDistAcceptanceException) are invoked exclusively by the repo-root bin entrypoints bin/build-admin-dist and bin/admin-dist-acceptance, which sit outside the analysed package path set and outside command discovery. They register no command, participate in no provider, and are marked @api for that reason. Their behavioural contract is owned by docs/specs/admin-spa.md, not by this spec; nothing here changes command parsing, discovery, IO, or exit codes. -->

## Purpose

`packages/cli/` provides the Symfony Console based command-line interface for Waaseyaa applications. The CLI entry point boots the Waaseyaa Foundation console kernel, constructs a `Symfony\Component\Console\Application`, registers framework and app commands from service providers, and delegates command parsing, help rendering, input/output handling, and execution to Symfony Console.

The CLI package sits at **Layer 6 - Interfaces**. Lower layers expose services, schedules, migrations, and domain operations that CLI commands consume; lower layers must not depend on `waaseyaa/cli`.

## Public Surface

| Surface | Role |
|---|---|
| `packages/cli/bin/waaseyaa` | Composer bin entrypoint. Must be invoked from a project root containing `composer.json`. |
| `Waaseyaa\Foundation\Kernel\ConsoleKernel` | Console composition root. Boots the framework and runs the Symfony Console application. |
| `Waaseyaa\CLI\ConsoleApplicationFactory` | Creates and configures the Symfony Console application. |
| `Waaseyaa\CLI\WaaseyaaConsoleApplication` | Waaseyaa-specific Symfony application subclass for compatibility policies such as exit codes and terse errors. |
| `Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface` | Provider capability for registering Symfony command services, FQCNs, or command instances. |

Command classes extend `Symfony\Component\Console\Command\Command` and declare their input contract with `InputArgument` and `InputOption`.

## Command Discovery

Commands are discovered during Foundation console boot from service providers declared in `extra.waaseyaa.providers`.

The three deployment-boundary commands `maintenance:on`, `maintenance:off`, and `maintenance:status` are the deliberate exception. `ConsoleKernel` dispatches them before Foundation or application boot through `MaintenanceServiceProvider::standaloneCommand()`. Their dependency surface is limited to project-root environment resolution and maintenance-flag state; they cannot depend on database availability or schema compatibility. This lets deployment tooling establish, inspect, and clear the maintenance boundary safely around a database or artifact transition.

Supported discovery forms:

1. Providers implement `ProvidesConsoleCommandsInterface` and return Symfony command service IDs, command FQCNs, or command instances.
2. Command services may be exposed through the Waaseyaa DI container and loaded through a Symfony command loader.
3. A future attribute-based path may allow command classes annotated with `#[AsConsoleCommand]` to be cached by `PackageManifestCompiler`.

The package manifest may cache console command providers or command service metadata, but command execution is always performed by Symfony Console.

## Command Registration

`ConsoleKernel::bootForCli()` boots the provider registry and DI container. The console application factory then:

1. Creates `Application('Waaseyaa CLI', $version)`.
2. Configures exception handling and auto-exit for process or test context.
3. Adds command services from providers or the command loader.
4. Validates duplicate command names.
5. Enforces framework-owned command namespace policies, including the reserved `config:*` verbs.

Providers should register command services in `register()` and expose them through the console command provider capability. Commands that need project-root state receive it through constructor injection from a kernel-context service.

Entity repositories on the provider bus are always selected through the
kernel-owned `EntityTypeManagerInterface`; the bus intentionally exposes no
context-free `EntityRepositoryInterface`. In particular, `ai:purge-runs`
selects `agent_run` explicitly. The AI aggregate types declare the
`sql-column` backend used by their migrations and direct retention queries,
and `waaseyaa/ai-agent` declares its migration directory so both fresh and
upgraded installations receive the required entity base columns.

The `ai:*` commands are an optional contribution: `waaseyaa/cli` only suggests
`waaseyaa/ai-agent`, so `AiServiceProvider` implements
`RequiresOptionalPackagesInterface` (see `package-discovery.md`, "Optional
package contributions") and registers zero commands, and binds nothing, while
that package is absent. A `--no-dev` consumer without the agent runtime lists
no `ai:*` command and refuses `ai:purge-runs` as unknown instead of failing on
the `AgentRunRepository` binding (#2826). Core lifecycle commands never depend
on the optional package. `ConsoleApplicationFactory` applies the same gate
before enumerating any provider's commands, so a provider cannot advertise a
command whose handler cannot resolve.

The seven `oidc:*` commands are gated the same way (#2828, the OIDC sibling of
#2826): `waaseyaa/cli` only suggests `waaseyaa/oidc`, so `OidcServiceProvider`
implements `RequiresOptionalPackagesInterface` with `SigningKeyRepository` as
the sentinel and registers zero commands while that package is absent. A
`--no-dev` consumer without OIDC lists no `oidc:*` command and refuses
`oidc:init-signing-key` as unknown rather than failing on the signing-key
repository binding.

`mcp:serve` (ADR-022 D-9.2, #2659 — the local stdio MCP transport) is gated
the same third way: `McpStdioServiceProvider` implements
`RequiresOptionalPackagesInterface` with sentinels for both
`waaseyaa/ai-agent` and `waaseyaa/ai-tools`, which `waaseyaa/cli` only suggests.
That keeps both Layer-5 packages out of a production `waaseyaa/cms` closure
that reaches `cli`, while a development install carrying both exposes the
command. A `--no-dev` consumer missing either package lists no `mcp:serve`
command. Once started, the
command takes over stdin/stdout for JSON-RPC (`Waaseyaa\CLI\Mcp\Stdio\StdioMcpServer`)
and never uses `SymfonyCommandIO::write()`/`writeln()` — only `error()`, so
every diagnostic including a refused `LocalOperatorPrincipal` attestation
lands on stderr, never interleaved with a protocol frame on stdout. Full
transport contract: `docs/specs/ai-integration.md` "Local stdio MCP transport
(`mcp:serve`, ADR-022 D-9.2, #2659)".

`mcp:registry-manifest` (#2638) is a fourth optional contribution, gated on
`waaseyaa/mcp` rather than the Layer-5 AI plane. `McpRegistryServiceProvider`
implements `RequiresOptionalPackagesInterface` with `McpRegistryManifest` as
the sentinel. A `--no-dev` consumer without the HTTP MCP package lists no
`mcp:registry-manifest` command. When present, the command resolves the
existing Layer-4 manifest binding and writes official Registry `server.json`
to stdout as the exact bytes returned by `McpRegistryManifest::toJson()`;
human-output style normalization does not touch the artifact. Configuration
refusals go to stderr and exit non-zero. It does
not publish to the Registry and does not share stdout with `mcp:serve`.

Command presentation belongs to this Layer-6 package even when the domain operation belongs lower in the stack. For example, `BearerTokenServiceProvider` owns the `bearer-token:issue|list|rotate|revoke` Symfony commands and depends downward on auth's `BearerTokenStoreInterface`; `AuthServiceProvider` owns the durable credential binding and exposes no Symfony Console types. A lower-layer provider must never construct CLI command objects, including through hidden string FQCNs.

`HealthSchemaServiceProvider` registers `tenancy:repair-translation-peers <entity_type> [--dry-run] [--json]`. `CommunityTranslationPeerRepairHandler` resolves the entity type, delegates to the entity-storage repairer, and renders either a stable JSON report or concise operator output. This command follows ordinary full console boot because it requires entity metadata and a database connection. It is not part of the restricted pre-boot command set. Applying repairs is an explicit operator action and requires the quiesce procedure in `docs/specs/operations-playbooks.md`.

`db:init` and `migrate` resolve SQLite paths through the same environment-aware
S1 topology authority used by HTTP boot. In-memory databases are permitted only
in the explicit local/development/testing allowlist; production, staging, and
unknown environment names fail closed before filesystem, lock, migration, or
connection work. Relative paths resolve against the injected project root, not
the caller's current working directory.

`db:init` classifies a pre-existing database file four ways before it writes
(#2644):

| State | Disposition |
|---|---|
| absent | create and migrate |
| present, has `waaseyaa_migrations` | migrate |
| present, holds no tables at all | adopt as a bootstrap artifact and migrate |
| present, holds any other table | refuse; the operator moves it aside |

The empty case exists because the framework creates that file itself. Any kernel
boot reaches `bootDatabase()` before the restricted-discovery guards, and
`DBALDatabase::createSqlite()` opens eagerly, so a zero-table file plus its
`-wal`/`-shm` sidecars is the normal residue of having run any command. Refusing
it told an operator to move aside a file they never made, with no recovery short
of manual filesystem surgery. It is safe to adopt precisely because it is empty;
a file with any table in it still belongs to someone else, and a connection that
cannot be inspected is treated as occupied so refusal stays the fail-safe answer.

`db:init` is a database-administration command. It is not part of the canonical
fresh-project lifecycle, which materializes schema through `install:init`.

### Migration catalogue and V2 execution

`MigrateServiceProvider` composes one lazy migration runtime from the injected
project root and database configuration. Its `migrate` and `migrate:status`
handlers consume both legacy and V2 catalogues from the stock `MigrationLoader`.
Root applications declare `extra.waaseyaa.migrations` in their Composer manifest
under their Composer package name, through the same discovery contract as
installed packages. V2 namespace discovery requires an optimized application
classmap; see [ADR 009](../adr/009-migration-manifest-discovery.md).

The provider's `Migrator` receives `V2PlanExecutor` with a SQLite compiler built
for the live database version. `db:init`, `install:init`, and the kernel-exposed
migrator use the same executor contract. `migrate:status` reports pending and
completed V2 identities as well as legacy migrations; resolving or executing
read-only status does not create the migration ledger. `migrate --dry-run` and
`--verify` observe the same V2 catalogue used by apply.
`db:init --dry-run` also enumerates pending V2 identities on initialized
databases without executing plans or updating schema/ledger. A declared root
path resolving to the canonical `migrations/` directory keeps historical `app:*`
ledger IDs; discovery upgrades do not rename or replay applied migrations.
All three Migrator composition sites pass the shared `RuntimePolicy` and a
runtime/configured logger. Development-like checksum drift warns and skips;
production-like drift throws without updating the applied ledger row.

This composition does not define new fresh-install plan semantics. In particular,
V2 entity evolution against absent or already-current tables remains the separate
contract decision in framework #2701; discovery support does not make an
unconditional `AddColumn` plan safe for both states.

`migrate --dry-run` and `migrate --verify` also receive their diagnostic
redaction posture from the shared bootstrap `RuntimePolicy`. Only `local`,
`dev`, `development`, and `testing`, after trim and case normalization, retain
absolute paths for development diagnostics. Production, staging, unknown,
missing, empty, and malformed environment values redact paths. Provider wiring
must not re-read `APP_ENV` or PHP environment superglobals.

## Framework rule synchronization

`sync-rules [--force] [--dry-run]` reads the canonical `.claude/rules/`
resources shipped by `waaseyaa/foundation`, anchored on that package's loaded
`ServiceProvider` class location. It supports direct-package and aggregate
consumers and follows Foundation when Composer loads it outside the CLI package's parent directory. This does not widen the separate provenance policy. Neither
`waaseyaa/framework` nor an application-side source copy is required. The
application root determines only the target `.claude/rules/` directory.
Dry-run reports additions/updates without creating that directory or writing
files; existing overwrite, counts and missing-source diagnostics are unchanged.

## Configuration authority commands and diagnostics

The reserved `config:*` namespace is owned by the framework CLI. Provider
discovery rejects both third-party collisions and duplicate framework handlers
for those verbs. The canonical command set is `config:export`, `config:import`,
`config:manifest:sign`, `config:diff`, `config:status`, `config:validate`, and
`config:reset`.

`config:manifest:sign` (#2430) is the CFG-03 authoring command and the only one
of the seven that belongs on a different host from the rest. It runs where
signing custody lives — a maintainer machine or a protected CI environment —
validates the authored sync directory, and writes the signed envelope beside it.
It reads no active configuration and activates nothing. A profile without
`config_manifest_signing.signing_key` composes no signer, so the command refuses
there rather than degrading: a verifier-only host is not a signing host. The
importing consumer receives the sync directory, the envelope, and the public
trust keys only; the signing secret must never reach a pull-request workflow or
ordinary production runtime. It is reserved (rather than left to apps) because
an app-owned `config:manifest:sign` could shadow the one command that mints
signing evidence.

Every command resolves the same `configuration.authority.v1` capability and
`ConfigurationAuthorityContext` as the HTTP kernel. Export, diff, status,
validate, reset, cache compilation, and import therefore cannot select an
independent directory or active store. Import is additionally guarded by the
deployment preflight boundary and compares the sync artifact against the exact
active references before any mutation. The `--no-dependency-check` option does
not bypass authority or deployment preflight.

`config:import` and `config:reset` submit one caller-identified activation
request against the complete expected active token. Production stages an
immutable successor generation and publishes it with compare-and-swap; stale
tokens, request-ID reuse with different input, missing mutation authorization,
or any failed transaction return a nonzero command result without changing the
serving generation. Lost-response retries with the same canonical request are
idempotent. Explicit tombstones, rather than omitted sync entries, authorize
deletion.

`about`, `health:check`, and `health:report` expose the resolved authority ID,
active generation, sync path, and selector provenance. They do not print secret
values. An unavailable or divergent authority is a boot/composition failure,
not a diagnostic warning.

`about` also reports the environment and debug mode from the same typed
bootstrap `RuntimePolicy` used by the kernel. It does not re-read `APP_ENV`,
`APP_DEBUG`, or PHP environment superglobals after configuration assembly.

`migrate --dry-run` reports the operations that would actually run. When a
precondition resolver is available it filters out operations the live schema
already satisfies, so the plan is truthful for both lifecycle states rather than
advertising SQL that apply would skip.

Because dry-run executes nothing, it cannot observe state that an earlier
operation changes. Uncertainty is therefore tracked across the **whole ordered
migration graph**, walking the same topological order the `Migrator` walks, not
within a single migration:

- A pending **legacy** migration is opaque — its `up()` body is imperative and
  cannot be pre-compiled — so every node ordered after it is uncertain.
- A pending **v2** migration marks the tables it *affects* as uncertain for every
  later node. Affected is not the same as prerequisite: a rename requires its
  source but changes both its source and its destination.
- An **already-applied** migration adds no uncertainty, because its effects are
  already present in the database the snapshot describes.

Uncertain operations are **preserved** rather than filtered, and the node is
reported `state_dependent` — `[pending][state-dependent]` in text and
`"state_dependent": true` in JSON. Showing work that may prove unnecessary is
honest; silently omitting work that will run is not. Uncertain operations are
also exempt from the incompatibility refusal, because a preceding operation may
be exactly what changes the state being judged.

Preview and apply share the operation-target model, the ordering, and the
precondition rule. They diverge in one documented place: the executor resolves
against ground truth because it has already applied preceding operations, and
preview cannot, so unknown state resolves to "outstanding, never refused". The
steps and the uncertainty flag are produced by a single analysis, so they cannot
disagree.

`db:init` enumerates registered entity types **before** the migration run and
releases that kernel's own database connection first, so targeted materialization
(#2701) runs on the migration connection and no second handle contends for the
SQLite write lock while a node's transaction is open. The enumeration is skipped
entirely when the V2 catalogue is empty, and a project with no registered content
types yields an empty snapshot rather than a `db:init` failure — every V2 plan then
fails closed on real SQL. `--no-sync-schema` still suppresses the full schema-sync
step; it does not suppress targeted materialization, which is part of applying a
migration rather than a separate provisioning pass.


## Installation lifecycle

### Legacy mutation-authority upgrade

`entity:backfill-mutation-authorities --reason=<audit reason> [--json]` is an
explicit pre-runtime upgrade command for aggregates persisted before DB-03.
`ConsoleKernel::handle()` routes only this exact command name through
`bootForMutationAuthorityBackfill()`, so it remains reachable when ordinary provider boot
correctly refuses a persisted aggregate with no authority row. It never runs
implicitly during boot, reads, migration, schema sync, or fresh installation.

The command requires a non-empty audit reason. Repositories implementing the
framework repair seam and database authority boundary are processed; other
repositories are skipped and named explicitly rather than invoked through an
invented repair path. Repository construction and repair failures do not
prevent later types from running and make the command return nonzero. Output
contains the reason's SHA-256 digest, aggregate total, per-entity-type committed
counts (or `null` when a foreign failure makes the count unknowable), skipped type
names, and failed type names; the raw reason, token material, and exception
details are never rendered. The aggregate `created` value is also `null`
(rendered as `unknown` in text mode) whenever any per-type count is unknown;
it is never presented as a false exact total. Each type is preflighted and
repaired atomically across all declared communities. Legacy empty community
owners bind to `_global`, matching hydration, and translatable types derive
authority only from their canonical language row. Existing authorities are
preserved. A completed retry reports zero created rows. Audit
events are buffered with the write and dispatched after commit; they are
notifications rather than the sole durable audit authority. A delivery failure
therefore reports the exact already-committed count rather than zero. Operators
retain the invocation reason with the digest-bound exit/count report as the
durable upgrade evidence.

`schema:sync [--dry-run]` reports created tables, already-existing tables, and
— since #2732 — the subset of already-existing tables whose synchronization
adds a column or index (`altered`, distinct from the genuinely untouched
`unchanged` subset). Before #2732, a table's pre-run existence alone decided
the summary, so a field (and its index) registered against an already-created
table was described as "already exist(s)" / "in sync" even though `--dry-run`
would apply a real schema change and the real run did. `--dry-run` and the
applied run derive `altered` from the same read-only traversal (see
`EntitySchemaSyncRunner`/`SchemaSyncReport` in
[entity-system.md](entity-system.md)), so the two cannot disagree.

That read-only traversal only exists for a SQLite connection with no mutation
already active — a real MySQL/MariaDB/PostgreSQL connection (or one already
mid-migration) has no equivalent read-only preview. The #2732 fix's first cut
folded that "cannot determine" state into `altered` too, via
`CoordinatedEntitySchemaExecutor::requiresMutation()`'s conservative
"assume mutation" default — meaning `schema:sync` printed "Altered N existing
table(s)" / "would alter" on **every** run on those platforms, even when
nothing changed. A review pass (#2732 follow-up) caught this before it
shipped: a separate `indeterminate` subset now carries ids whose status could
not be determined at all, distinct from both `altered` and `unchanged`.
`SchemaSyncReport::changed()` never returns `true` from indeterminacy alone.
On `--dry-run`, `schema:sync` prints `N existing table(s); pending
column/index work cannot be previewed on this database platform — apply to
find out.` On a real (non-dry-run) run the sync still executes against those
tables — it cannot skip the mutation coordinator just because it cannot
preview — and the command instead prints `Synced N existing table(s); pending
column/index work could not be previewed on this database platform before
applying.` SQLite behaviour (the common local/CI case) is unchanged: the
`indeterminate` subset stays empty there. See `EntityTypeSchemaPlan` and
`CoordinatedEntitySchemaExecutor::canPreviewMutation()` in
[entity-system.md](entity-system.md).

`install:init` is the governed installation phase (#2428) and belongs to the
restricted pre-boot command set alongside `schema:sync` and `migrate*`.
`ConsoleKernel::handle()` routes it through `bootForSchemaSync()`, so it never
constructs a runtime consumer that would require the configuration generation it
is producing, and it exits without entering ordinary runtime boot. It is the
first command in the lifecycle that opens the database, which is correct: it is
the command that creates it.

`site:init` left that set in #2644 for the boot-free seam below. Restricted boot
still calls `bootDatabase()`, so routing the site-contract phase through it
created an empty database before any bootstrap command had run.

It applies migrations, synchronizes entity schema, and activates the canonical
empty configuration generation through the genesis seam described in
[entity-system.md](entity-system.md). It is idempotent: a site that already has
an active generation reports it and exits successfully, a repeated installation
request replays its committed result, and contradictory partial state is refused
rather than resolved by minting a second generation.

It reports the configuration authority, database path, database identity, and
sync path it resolved before it does anything else. Every configuration
lifecycle row is keyed by authority, and `DatabaseBootstrapper::resolveDatabasePath` gives
`config['database']` precedence over `WAASEYAA_DB`, so an operator who believes
they are installing into a copied database otherwise has no way to discover
that they are not. Reporting identity is diagnostic only; it does not widen what
the command may do, and `install:init` remains not a rebinding path.

It is distinct from `install`, which seeds site content and an admin account
through ordinary runtime boot. A fresh site runs `install:init` first; `install`
could not previously succeed on a new site at all, because it writes
configuration and no generation existed to write into.

Within the canonical fresh-project lifecycle (#2644), `site:init` runs before
`install:init`, so the entity types a recipe declares reach `install:init`'s
schema synchronization in the same pass. Because `install:init` is idempotent,
it is also the correct command to re-run after any later `site:init` that
changes the declared content model. `install:init` — not `db:init`, and not
`migrate` plus `schema:sync` — is the materialization step a consumer-facing
document names, because it is the only one of the three that also activates the
configuration generation. A site materialized without it passes site
verification while being an invalid installation.

## Fresh-project orchestration

`project:init` composes `site:init` followed by `install:init` in separate child
processes through the selected project's installed CLI. The parent runs on the
boot-free seam and opens no database. Ordinary command discovery and pre-boot
dispatch share `SiteServiceProvider::projectInitCommand()`. The authoritative
bounded contract is `FW-PROJECT-INITIALIZER-01`.

The accepted options are `--answers`, `--decision-receipt`, `--preset`,
`--project-root`, `--config-authorization`, `--dry-run`, `--json`, and
`--yes`/`-y`. Site options are
forwarded as argv values; semantic validation remains in `site:init`.
`--dry-run` never starts installation. A failed site phase or unconfirmed child
cleanup prevents installation. Plain mode preserves attached streams; JSON
mode emits one bounded parent result. Cleanup failure reports the owned PID
and bounded diagnostic as `PROJECT_INIT006_CHILD_CLEANUP_FAILED`.

`--config-authorization` is an opt-in fresh-project path. Before invoking
`project:init`, an authoring host runs `project:config:authorize` with the same
answers and decision receipt. That command renders the canonical site plan and
exact generated `config/sync` bytes, then signs them through the existing
configuration-manifest signer. The consumer is provisioned once with the
corresponding public `config_manifest_signing.trust_keys` entry and a canonical
sync directory selected by `config.sync_path` or `WAASEYAA_CONFIG_SYNC_PATH`;
it receives no signing key or secret-provider configuration.

After `site:init` and `install:init`, the parent invokes
`project:config:activate` in the generated project. The child derives the
committed site's canonical manifest and plan digests itself and accepts only an
authorization bound to that identity and to the complete current sync bytes.
Initial activation requires the exact empty genesis generation and refuses an
existing application. An exact retry revalidates the signed bundle, current
sync, committed request, and current activation token before reporting
`already_completed`. Once the activation child has started, an absent,
malformed, open-ended, or contradictory result leaves the parent phase
`uncertain`; only the closed versioned result contract can establish completion
or a definite refusal. The parent never claims rollback of an unobservable
activation.

Verification remains `composer site-verify` after successful initialization.
This command adds no cross-phase rollback, private completion ledger, upgrade,
AI update/verification, or generated-output removal mode. `--upgrade` is unknown.

## Site initialization

`SiteServiceProvider` registers `site:init [--answers=PATH] [--preset=minimal|editorial]
[--project-root=PATH] [--dry-run] [--yes] [--json]`. It runs on the boot-free command
seam, alongside `site:doctor` and `db:init`, and composes the Layer-0
site-contract package. `SiteInitHandler` takes only a project root and
`SiteArtifactRendererFactory::create()` composes its recipes with `new`, so no
container is required; routing it through restricted boot would open the
database, and the site-contract phase runs before the framework has one (#2644).
Interactive mode asks product questions in plain language. Non-interactive mode
requires a complete answer document and explicit `--yes`; `--dry-run` performs
no writes. Publication is serialized by the project initialization lock and
delegates collision refusal, durable journaling, rollback, crash recovery, and
generated ownership to `SiteInitializationService`.

`--json` is opt-in and emits one invocation object: `evaluation` (the plan,
observed project state, both identities, per-artifact statuses, set delta and
refusals), `result` (the versioned artifact-result document), and `receipts`
(the ordered change receipts). Failure output also carries an `errors` list;
when evaluation or a normal apply result is unavailable its member is null.
JSON mode requires `--answers`, and publication also requires `--yes`; plain output and existing exit codes remain
unchanged. Preview and pre-apply cancellation emit no apply receipt. Recovery
receipts use `site.recover` and a digest of validated recovery instructions;
they never claim the lost original plan identity. Receipts are returned and
emitted, with no receipt file or retention sink.

The public `apply(ArtifactApplyRequest)` service seam accepts the transported
plan without recompiling it. Under the existing lock it recovers first,
compares plan and project-state digests, evaluates through the same authority,
and publishes only the reviewed state. A mismatched or unverifiable state
refuses `GEN005`; an aggregate state hash cannot identify a changed location,
so the refusal is location-free unless independently provable. Default
initialization uses the same gate after confirmation.

## Reviewed apply

`SiteServiceProvider` registers `site:apply --request=PATH [--decision-receipt=PATH]
[--project-root=PATH] [--json]` (#2789) — ADR-025 D-6.5's *second process*, installed. It joins the
boot-free command seam with `site:init` and `site:doctor`: `SiteApplyHandler`
takes only a project root and constructs no renderer, wizard or compiler at
all, so it must not be routed through restricted boot, which would open the
database this phase precedes (#2644).

`--request` names a canonical `waaseyaa.artifact_apply_request` v1 document —
the reviewed plan with its bytes, `plan_digest` and `project_state_digest` —
read exactly once and decoded by `ArtifactApplyRequest::fromCanonicalJson()`.
`--decision-receipt` names the separate approved
`waaseyaa.blueprint_decision` v1 document, read exactly once and
decoded by the shared `DecisionReceiptInput` helper with the same closed
`SITE050` contract as `site:init`. The receipt is never a member of the apply
request and is passed to `SiteInitializationService::apply()` as its own
argument; blueprint execution without a matching approval is
`GEN011_UNAUTHORIZED_SET_DELTA`. The command recompiles nothing: a generator that names its target from a
compile-time clock reading would otherwise produce a different, equally valid
plan, and the operator's review would bind nothing. Decoding is fail-closed on
unknown, missing, duplicate or wrong-typed members, on an invalid nested plan,
and on any bytes that are not the canonical serialization of the document they
decode to (a re-ordered, pretty-printed, slash-escaped or duplicate-keyed
document decodes to *something*, and applying it would mean applying a document
nobody emitted). One terminating newline — this framework's own on-disk framing
for a canonical document — is the only tolerated difference. A decode refusal
carries the shared `SITE0xx` structural codes and their JSON Pointer, and
happens before any lock, journal or write exists.

The two digests are **not** verified at decode. Whether the transported plan
hashes to its reviewed `plan_digest`, and whether the project still matches
`project_state_digest`, stay `GEN005` questions the execution authority answers
under its exclusive lock; a decoder that answered them early would be a second,
lock-free authority on staleness. A request therefore binds exactly one
reviewed state: replaying an already-published request is `GEN005`, while
re-evaluating the same plan against the state it will actually meet reports
`no_changes`.

Receipts use the existing `site.init` operation, because the governed operation
is the same publication — only the entrypoint differs. `--json` emits `result`
(the versioned artifact-result document), `receipts` (the ordered change
receipts) and `errors`; a governed refusal publishes its coded violations
inside `result.errors` and leaves `errors` empty, while a failure that produced
no result at all (an undecodable document, an unreadable path, a terminated
execution) reports it in `errors` with `result` null — the same polarity
`site:init --json` uses. The handler returns `0` for applied or no changes and
`2` for refused or failed; as for every command, `WaaseyaaConsoleApplication`
then normalizes any non-zero result other than `130` to `1`, so a refusal
reaches the shell as `1` and the coded detail is read from the envelope, not
from the exit status.

### Development-only interruption seam

Crash recovery is a promise about a *later process*, so proving it end to end
needs a real process that really stops mid-transaction. When — and only when —
`APP_ENV` is exactly `development`, `site:apply` registers
`--interrupt-after-journal` (#2789 phase 3). Armed, it abandons the publication
at the first target replacement: after the transaction journal is durable, and
before publication completes. The durable journal, stage and backup trees it
leaves are the ones the real transaction wrote, and the next ordinary
`site:apply` recovers them before completing its own work, emitting the
`site.recover` receipt first and reporting
`recovered_interrupted_transaction: true`.

`APP_ENV=development` is exact and narrower than
`RuntimePolicy::isDevelopmentEnvironment()`, which also admits `dev`, `local`
and `testing`: those are environments people work in, and abandoning a
transaction must not be one keystroke away there. Outside that environment the
option is not registered at all, so it is an unknown option, and the handler
re-reads the environment before constructing the injector, so a stale or
hand-built command definition cannot arm it either. The seam takes no stage,
path, index or count from the operator — it is not a fault-injection API — and
it bypasses nothing: the lock, both digests, path containment and every
collision check run first and refuse first.

An interrupted run exits `130`, the code this CLI reserves for an interrupted
run and the only non-zero result a handler can return without being normalized
to `1`. A bespoke code would be invisible to the black-box harness the seam
exists for, and widening that normalization would change the exit-code contract
of every command to serve a development-only seam.

<!-- Spec reviewed 2026-09-06 - #2789: `site:apply` is the reviewed command migration ADR-025 D-12.1 constraint 1 requires before a further entrypoint may transport generation results. It enters exactly one execution-authority seam — `apply()` — and no other; `tests/Architecture/GenerationStagedActivationBoundaryTest.php` records it alongside `SiteInitHandler`/`SiteDoctorHandler` as the closed roster. Acceptance: `SiteApplyHandlerTest`, `ArtifactApplyRequestDecodingTest`, and the `site:apply` case in `ConsoleKernelTest::siteContractCommandsRunWithoutBootingOrCreatingTheDatabase`. -->

`--preset` (#2442) is an init-time-only shortcut, resolved once by
`SitePresetResolver` into an ordinary answer document before it ever reaches
the pipeline above — see "Init-time presets" in
[site-golden-path.md](site-golden-path.md) for the full contract. It adds no
second command path: `--answers` still names a document (a closed, versioned
`waaseyaa.site-seed` v1 identity/content-type seed rather than a complete
manifest, when `--preset` is also given), interactive mode still asks
questions when neither is given, and the resolved YAML runs through the exact
same `SiteManifestParser` → `SiteArtifactRendererFactory` →
`SiteInitializationService` sequence as a hand-written answer document. A
malformed seed fails the command with the same `SITE0xx` code and JSON Pointer
a malformed manifest does; a preset selects capabilities and publishes their
artifacts. Each selected first-party recipe's fixed Composer provider
registration compiles into the same root `ArtifactPlan` and merges into
literal root `composer.json` through the existing D-6.6 transaction, for both
ordinary and blueprint `site:init` (ADR-025 D-15.2, #2857) — no Composer
solving and no new lifecycle phase. A recipe's Composer fragment file remains
a generated compatibility artifact; it is never provider-discovery authority,
because `PackageManifestCompiler` reads only literal root `composer.json`.

Regeneration across a renderer change is carried by the manifest rebind, not by
a migration engine: there is none, and `generator_version` is read from the
project's own manifest, so the framework cannot raise it. Rebinding
`framework.observed_lock_sha256` to the reviewed dependency lock changes the
manifest digest, which is the signal that distinguishes an upgrade from a
substitution. See [site-golden-path.md](site-golden-path.md) "Initialization" for
the full disposition of a changed artifact set versus changed managed bytes.

Forge, release, and deployment behavior are outside this command.

## Scaffolded content types

`make:content-type <name> --fields=… [--field-read=…] [--force]` preserves generated
entity and provider semantics, its Indigenous-orthography support, its
no-overwrite refusal and its next-step output, but it no longer writes anything
itself (#2789 phase 2). `ContentTypeScaffoldCompiler`
(`packages/cli/src/Site/Scaffold/`) turns the handler's already-validated input
into one immutable `ArtifactPlan`, and `SiteInitializationService::initialize()`
publishes it — the single-invocation flow ADR-025 D-6.5 describes, where
compile, evaluate and apply happen once in one process through the same
two-digest gate a transported plan passes. There is one publication engine, not
a scaffold-shaped second one.

Field type admission and PHP property metadata are derived through the field
package's [canonical scaffold projection](field-scaffold-projection.md)
(FW-FIELD-PROJECTION-01). The manual command preserves authored reference
settings and label-key selection. Registered type ids are escaped as PHP
literals; `text` and `datetime` property representations match blueprint output.
`MakeServiceProviderB` lazily supplies the real command with the boot-scoped
registered field manager. Missing or incompatible registry services refuse.
Standalone `MakeContentTypeHandler` and `ContentTypeScaffoldCompiler` callers
must supply an explicit `FieldScaffoldProjection`; no production built-ins
fallback remains.

`--field-read="title:public,summary:protected"` declares explicit per-field
read visibility using canonical `FieldReadLevel` values (`public`, `protected`,
`internal`). Each selection must name a field declared by `--fields`; duplicate
selections, unknown fields, reserved `status`, unsupported levels and malformed
entries refuse before publication. Omitted selections retain the registered
entity runtime's `Internal` default; the generator never widens fields merely
because a search projector requests them. Selected levels are emitted in field
attributes and participate in the artifact plan digest. Reordering selections
alone does not change their meaning or generated field order. Existing seeded
ownership and no-overwrite rules still apply.

The compiler is a pure function of its validated input, resolved registered
field metadata and its own version: no filesystem observation or clock. Equal
inputs produce the same plan digest. The unit is `scaffold:content-type:<name>` with disposition
**seeded** and `Frozen` set evolution. Seeded is the substantive change: D-2.2
publishes a scaffold exactly once and then treats it as the developer's, so the
authority never re-renders it and `--force` can no longer overwrite an edited
scaffold — it only skips the handler's pre-write "already exists" refusal, and
the run reports the unit unchanged. `ContentTypeScaffoldCompiler` is the first
member of `SiteInitializationService`'s closed `SEEDED_COMPILERS` admission
list; a compiler cannot assert its own eligibility to create seeded units.

The provider registration travels in the plan as a D-6.6
`ComposerProviderRegistration` rather than a `json_decode`/mutate/`json_encode`
of the application's manifest, so it is enacted inside the same transaction as
the two files and preserves the application's own `composer.json` bytes and
formatting. Path containment, unowned-target collision refusal, the durable
journal, rollback, receipts and both state digests are the authority's, not the
handler's.

Publication therefore requires an initialized site: unit ownership is recorded
in `.waaseyaa/generated.json`, and before `site:init` there is no roster to
record it in, so scaffolding an uninitialized project refuses
`GEN003_COLLISION_REFUSED` ("A non-root unit requires an initialized site")
instead of writing ungoverned files. That is the order the canonical lifecycle
already prescribes.

<!-- Spec reviewed 2026-09-06 - #2789 phase 2: make:content-type is the first seeded-compiler migration ADR-025 D-2.2/D-6.6 anticipated. Two behaviour consequences are deliberate and recorded here rather than hidden: an uninitialized project is now refused, and --force no longer overwrites a published scaffold. Acceptance: MakeContentTypeCustodyTest, plus the unchanged assertions of MakeContentTypeHandlerTest whose fixtures now start from an initialized site. -->

## Scaffolded search projections

`make:search-projection <entity-type> --fields=… [--force]` scaffolds an
application-owned search extension: an `EntitySearchProjectorInterface`
implementation under `src/Search/`, a `ProvidesEntitySearchProjectorsInterface`
provider under `src/Provider/` that binds it, and a companion test under
`tests/Search/`. Both consumed contracts are declared `public` by
`packages/search/public-surface.php`; the generator scaffolds *against* the
search package's extension points and never copies its internals, so
`EntitySearchProjectionRegistry`, `EntitySearchCandidateResolver` and the
FTS5 indexer stay framework-owned.

`SearchProjectionScaffoldCompiler` (`packages/cli/src/Site/Scaffold/`) is a pure
function of its validated input and its own version, and
`SiteInitializationService::initialize()` publishes the plan — the same
single-invocation flow as `make:content-type`, through the one publication
engine. The unit is `scaffold:search-projection:<entity-type>` with disposition
**seeded** and `Frozen` set evolution, making the compiler the second member of
the closed `SEEDED_COMPILERS` admission list. The provider registration travels
as a D-6.6 `ComposerProviderRegistration`, and the plan populates D-6.6's
`companion_tests` member with the generated test path — the first non-blueprint
compiler to do so. Publication requires an initialized site for the same
reason content-type scaffolding does.

Three refusals are specific to this generator, all of them before any write.
First, the optional capability is negotiated by name: the handler resolves the
five search symbols the generated projector, provider and test reference and
refuses with a stable diagnostic naming the first missing one, leaving the
application untouched. `waaseyaa/cli` currently *requires* `waaseyaa/search`,
and `core` ships no CLI at all, so no reachable install can fail this today —
the guard exists so a narrowed dependency or a partial vendor tree fails at
the generator rather than at the consumer's boot. It is deliberately not
`GeneratorFeatureNegotiation`, which arbitrates manifest-authored
generator-feature tokens against the installed generation authority, not the
presence of an optional runtime package.

Second, the container resolves exactly one
`ProvidesEntitySearchProjectorsInterface`, so scaffolding a second provider
would silently shadow the first depending on registration order; the handler
scans `src/Provider/` and refuses before compiling, naming the existing
provider and directing the additional projector into its
`entitySearchProjectors()` list. Third, ordinary input validation refuses a
field name that is not snake_case, a duplicate field, and an empty field list.

The generated projector reads every field through the guarded
`EntityInterface::get()` accessor and catches
`FieldReadDenied`/`MissingFieldReadContext`, so index-time projection — which
runs with no account scope — releases only `FieldReadLevel::Public` fields and
omits the rest rather than leaking them.

That omission is silent by design, and it interacts with a live gap: a
*registered* entity type resolves every undeclared field to
`FieldReadLevel::Internal` (`EntityReadRuntime`), while `make:content-type` without explicit `--field-read` selections
emits no `read:` argument for those fields, so a scaffolded entity indexed
without further edits projects an empty document rather than an error.

The framework's answer to that is a **deliberate visibility decision per
field, not a blanket widening**. The restricted default is the correct one and
is preserved: it keeps unclassified data out of a public index. The command's
next-step output and the generated companion test's failure message therefore
present two options of equal standing — declare `read: FieldReadLevel::Public`
on content genuinely meant to be searchable, or leave the field restricted and
stop indexing it — and both say explicitly not to widen a field merely to
silence the omission. Closing the emitter side belongs to the
entity/content-type generator convergence (#2847), not here.

Two integration proofs bind this behaviour to a *registered* entity type,
because read levels resolve differently for registered and unregistered types
and the unregistered fixture idiom would pass while a real application
released nothing.
`tests/Integration/Generation/SearchProjectionScaffoldRuntimeTest.php` drives
`EntitySearchCandidateResolver` with the real `EntityAccessHandler` and
`AccountFieldReadScope`; `SearchProjectionReindexTest.php` drives
`search:reindex` over a real FTS5 index on SQLite and asserts against the
stored rows, which is the only place "protected content never enters the index
file" can be checked rather than inferred.

`tests/PackagedForm/check-search-projection-scaffold-acceptance` proves the
same properties from a packaged consumer built out of the candidate tree with
its own resolved dependencies, driving `site:init` → `install:init` →
`make:content-type` → a deliberate per-field visibility decision →
`make:search-projection` → `schema:sync` → `entity:create` → `search:reindex`.
Its load-bearing assertion is that reindex indexes exactly one document: no
built-in projector supports the scaffolded entity type, so that count can only
be reached if the generated provider was discovered from literal root
`composer.json` and its projector consulted ahead of the built-in default. A
negative control removes only that registration, leaves the generated classes
byte-identical on disk, and requires reindex to index zero.

<!-- Spec reviewed 2026-09-07 - #2849: make:search-projection is the second seeded-compiler migration, and the first compiler to populate ADR-025 D-6.6 companion_tests. Recorded rather than hidden: the by-name capability guard and why GeneratorFeatureNegotiation is not the applicable machinery, the single-provider refusal (the container resolves one ProvidesEntitySearchProjectorsInterface), and the read-level interaction whereby a registered entity type defaults undeclared fields to Internal so an unedited scaffolded entity indexes empty. Guidance requires a per-field visibility decision and preserves the restricted default rather than recommending Public. Acceptance: MakeSearchProjectionCustodyTest, SearchProjectionScaffoldRuntimeTest, SearchProjectionReindexTest, GenerationUnitActivationBoundaryTest's widened seeded roster, and GenerationStagedActivationBoundaryTest's widened refusal-carrier allowlist. -->

## Input And Output

Commands use Symfony Console input/output:

- `InputInterface` for arguments and options.
- `OutputInterface` or `ConsoleOutputInterface` for stdout and stderr.
- `SymfonyStyle` for interactive questions and structured operator output where appropriate.
- `QuestionHelper` and `InputInterface::isInteractive()` for prompt behavior.

Command implementations must preserve established stdout/stderr behavior for script-facing commands, especially JSON output modes and concise error messages.

## Error Handling

The Symfony Console application owns top-level exception rendering. Waaseyaa-specific behavior:

- Input and usage errors return exit code `2`.
- Command/domain failures return exit code `1`.
- Unknown commands include a hint to run `waaseyaa list`.
- Stack traces are shown only at verbose/debug verbosity.
- Uncaught command exceptions are logged through `LoggerInterface` when available.

Command-level validation should print concise stderr messages and return `Command::FAILURE`.

## Help And Usage Rendering

Symfony Console owns:

- `waaseyaa list`
- `waaseyaa help`
- `waaseyaa help <command>`
- `waaseyaa <command> --help`
- global options
- argument and option formatting

Waaseyaa preserves these operator-facing requirements:

- A bare `waaseyaa` invocation prints a short usage hint and exits `0`.
- `waaseyaa list` lists all registered commands.
- `waaseyaa help` with no command lists available commands during the backward-compatibility window.
- Existing command names, descriptions, argument descriptions, and option descriptions are migrated into Symfony command configuration.

Snapshot tests should assert semantic command/help content unless exact byte formatting is declared a compatibility contract for a specific command.

## Versioning

The Symfony application version is resolved from the project `VERSION` file, package provenance, or a dedicated `VersionResolver` service. `waaseyaa --version` is handled by Symfony Console.

`waaseyaa:version` remains a richer diagnostic command and continues to support its JSON and strict/report-only modes.

## Exit Codes

| Code | Meaning |
|---|---|
| `0` | Success. |
| `1` | Command/domain failure or uncaught handler exception. |
| `2` | Usage/input error: unknown command, invalid option, missing required argument, or invalid argument shape. |
| `64`-`78` | Reserved for future sysexits-style categories. |
| `130` | Interrupted run: SIGINT where command/application signal handling reports it, or `site:apply --interrupt-after-journal` under `APP_ENV=development` (#2789). It is the only non-zero handler result `WaaseyaaConsoleApplication::normalizeExitCode()` does not collapse to `1`. |

## Signal Handling

Commands that need signal handling use Symfony Console signal facilities where available or command-local `pcntl_signal()` handling when the extension exists.

Required behaviors:

- `queue:work` delegates to `Waaseyaa\Queue\Worker`, which handles `SIGTERM` and `SIGINT`.
- `ai:run --watch` preserves clean interruption of the SSE watcher while leaving the agent run active server-side.
- Generic console boot must not install broad signal handlers that hide command-specific shutdown semantics.

## Testing

Use Symfony Console testing tools:

- `Symfony\Component\Console\Tester\CommandTester` for individual commands.
- `Symfony\Component\Console\Tester\ApplicationTester` for application-level behavior.

Required coverage:

- command registration from providers
- no-arg invocation
- `list`, `help`, `<command> --help`
- unknown command and input-error exit code `2`
- version output
- stdout/stderr split
- JSON output modes
- non-interactive prompt defaults
- signal-aware commands

## Migration Companion

The implementation roadmap, current subsystem inventory, one-to-one component mapping, breaking-change analysis, and risk assessment live in [`cli-symfony-console-migration-plan.md`](./cli-symfony-console-migration-plan.md).


## Manual governance scaffold output

<!-- Spec reviewed 2026-09-08 - #2848 fixture-source-fresh: WorkflowDefinitionEmitter
assignments emission is CFG-03 writable sync (not bare key/value YAML). Scaffold
stdout JSON assignment maps and definition hydration are unchanged. -->

`make:policy <name> --entity=<id> [--grant=<operation>:<permission>]...`
requires an explicit entity id; an optional `--entity-class` supplies its application
class. It renders the canonical access-policy interface and attribute through the
blueprint emitter. Zero grants return Neutral; entity access remains denied absent
an explicit allowance. Syntactically valid names do not establish application
registry membership. Missing entity or malformed grants refuse with usage exit 2.

`scaffold:workflow --id=<id> --entity-type=<id> --bundle=<id>` accepts state,
transition and initial-state options and returns a JSON `workflow` hydration array
and `assignment` map keyed by `entity_type.bundle`. Duplicate transition IDs,
unknown referenced states and undeclared initial states refuse with exit 2.
The blueprint PHP renderer and manual JSON output share one canonical definition
transformation; serialization must not duplicate workflow semantics.

When the blueprint compiler emits workflow artifacts through
`WorkflowDefinitionEmitter`, each declared workflow still yields a PHP definition
class whose `DEFINITION` hydrates `Waaseyaa\Workflows\Workflow`. The aggregate
`config/sync/workflows.assignments.yml` is emitted only when at least one binding
exists, and it is a CFG-03 writable sync artifact for
`workflows.assignments@1`: `_meta` carries the deterministic uuid, schema id /
version / canonical hash, and owner contract from
`WorkflowAssignmentsConfig::register`, and the field map is the authored
`entity_type.bundle → workflow_id` rows. Bare key/value YAML without that envelope
is not the emitter contract. Emit-time registration reads schema identity only;
it does not run the package's semantic binding-admissibility validator against
installed entity types. Zero bindings still emit no `config/sync/*` assignments
file (never an empty CFG-03 object). Schema ownership and import-time semantic
gates remain in [`content-workflow.md`](./content-workflow.md) and
[`config-management.md`](./config-management.md); site-compiler product shape
remains in [`site-golden-path.md`](./site-golden-path.md).

Both commands retain ADR-025 D4 stdout-only behavior. Output is not registered or
applied by printing it. Application admission, registration and activation use the
existing canonical governance path and require separate evidence. These required
options and output shapes replace the previously invalid policy interface and
non-hydratable workflow JSON. See
[FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01](../change-records/FW-GOVERNANCE-SCAFFOLD-CONVERGENCE-01.md)
for migration, acceptance and remaining packaged proof.

### Optional agent-guidance verifier transport

`AiVerifyCommand` is a transport-only adapter supplied a typed callback by the optional Bimaaji provider. CLI owns option normalization and output; Bimaaji owns verification, client policy and the shared human/JSON report. CLI adds no runtime dependency on Bimaaji. See [bimaaji-install.md](bimaaji-install.md) for `ai:verify` semantics.
