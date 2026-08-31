# CLI Console

<!-- Spec reviewed 2026-08-27 - Framework #2621: migration dry-run and verify
diagnostic redaction consumes the shared RuntimePolicy production-like
classification instead of independently reading APP_ENV. -->

<!-- Spec reviewed 2026-08-27 - #2619: `about` receives the typed environment
and debug policy resolved from the kernel bootstrap inputs. It no longer reads
PHP environment superglobals independently, so operator output and boot policy
cannot disagree. Command name, arguments, exit code, and custom info overrides
are unchanged. -->

<!-- Spec reviewed 2026-08-26 - #2439: scaffold:auth publishes presentation files only and records per-file Framework provenance plus separate upstream/consumer digests in the versioned scaffold manifest. --check is read-only and reports added, removed, changed-upstream, changed-consumer, and conflict states; --strict is an explicit blocking policy. --accept-current updates only reviewed manifest baselines and never application files. Missing/malformed manifests fail safely without repair. The skeleton site audit invokes the check as a non-blocking diagnostic. Canonical ownership contract: auth-consumer-extensions.md. -->
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

## Site initialization

`SiteServiceProvider` registers `site:init [--answers=PATH] [--project-root=PATH]
[--dry-run] [--yes]`. It runs on the boot-free command seam, alongside
`site:doctor` and `db:init`, and composes the Layer-0 site-contract package.
`SiteInitHandler` takes only a project root and
`SiteArtifactRendererFactory::create()` composes its recipes with `new`, so no
container is required; routing it through restricted boot would open the
database, and the site-contract phase runs before the framework has one (#2644).
Interactive mode asks product questions in plain language. Non-interactive mode
requires a complete answer document and explicit `--yes`; `--dry-run` performs
no writes. Publication is serialized by the project initialization lock and
delegates collision refusal, durable journaling, rollback, crash recovery, and
generated ownership to `SiteInitializationService`.

Regeneration across a renderer change is carried by the manifest rebind, not by
a migration engine: there is none, and `generator_version` is read from the
project's own manifest, so the framework cannot raise it. Rebinding
`framework.observed_lock_sha256` to the reviewed dependency lock changes the
manifest digest, which is the signal that distinguishes an upgrade from a
substitution. See [site-golden-path.md](site-golden-path.md) "Initialization" for
the full disposition of a changed artifact set versus changed managed bytes.

Forge, release, and deployment behavior are outside this command.

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
| `130` | Interrupted by SIGINT where command/application signal handling reports it. |

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
