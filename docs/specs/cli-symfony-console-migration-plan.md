# CLI Migration Plan: Replace Native Kernel with Symfony Console

Status: proposed implementation plan
Scope: `waaseyaa/cli`, `waaseyaa/foundation` console boot, command discovery, command registration, command execution, help, IO, testing, and related docs/tests.

## Executive Summary

The current CLI subsystem is a custom console runtime built around `CliApplication`, `CliKernel`, `CommandRegistry`, `CommandDefinition`, `ArgvParser`, `ConsoleCliIO`, custom output/input abstractions, and Foundation provider discovery through `HasNativeCommandsInterface`. It deliberately replaced Symfony Console, but most of its public behavior already imitates Symfony Console help, exit codes, global options, command tester ergonomics, and command naming.

The lowest-friction migration is staged:

1. Introduce Symfony Console as the process runtime and adapt existing `CommandDefinition` + `CliIO` handlers through temporary adapter commands.
2. Rewrite each first-party handler as a real `Symfony\Component\Console\Command\Command`.
3. Delete the adapter layer and every native kernel/parser/registry/help/IO abstraction.

## Current CLI Architecture Inventory

### Process Entry And Kernel

| Component | Path | Purpose | Kernel Interaction |
|---|---|---|---|
| `bin/waaseyaa` | `packages/cli/bin/waaseyaa` | Composer bin entrypoint. Resolves project root from `getcwd()`, requires `vendor/autoload.php`, calls `CliApplication::main()`. | Hands argv and project root to the native CLI application. |
| `Waaseyaa\CLI\CliApplication` | `packages/cli/src/CliApplication.php` | Static process bootstrap. Boots `ConsoleKernel` when no providers/container are supplied, loads package manifest, builds registry and kernel, exits in `main()`. | Owns the top-level native runtime assembly. |
| `Waaseyaa\CLI\CliKernel` | `packages/cli/src/CliKernel.php` | Stateless dispatcher for built-ins, command lookup, parsing, IO creation, handler resolution, exceptions, and SIGINT check. | Central custom kernel to remove. |
| `Waaseyaa\Foundation\Kernel\ConsoleKernel` | `packages/foundation/src/Kernel/ConsoleKernel.php` | Foundation console composition root. Extends `AbstractKernel`; `handle()` calls `CliApplication::run()`. | Should become the Symfony Console application factory/runner. |
| `Waaseyaa\CLI\Provider\CliKernelServiceProvider` | `packages/cli/src/Provider/CliKernelServiceProvider.php` | Static builder for `CommandRegistry` and `CliKernel`; resolves native command providers from `PackageManifest`. | Bridges Foundation package discovery into native registry. |
| `Waaseyaa\CLI\Kernel\MigrateHandlerContainerDecorator` | `packages/cli/src/Kernel/MigrateHandlerContainerDecorator.php` | Special DI decorator for migrate handlers that need kernel-created migrator and migration-loader closures. | Used by `CliApplication` after `ConsoleKernel::bootForCli()`. |

### Command Model And Registry

| Component | Path | Purpose | Kernel Interaction |
|---|---|---|---|
| `CommandDefinition` | `packages/cli/src/CommandDefinition.php` | Immutable command metadata: name, description, arguments, options, handler closure or `[class, method]` reference. | Registered into `CommandRegistry`; parsed and dispatched by `CliKernel`. |
| `ArgumentDefinition` / `ArgumentMode` | `packages/cli/src/ArgumentDefinition.php`, `ArgumentMode.php` | Positional argument metadata. Supports required, optional, array. | Consumed by `ArgvParser` and `HelpRenderer`. |
| `OptionDefinition` / `OptionMode` | `packages/cli/src/OptionDefinition.php`, `OptionMode.php` | Option metadata. Supports none, required, optional, array, negatable; forbids native global names/shortcuts. | Consumed by `ArgvParser` and `HelpRenderer`. |
| `CommandRegistry` | `packages/cli/src/CommandRegistry.php` | In-memory map of command name to `CommandDefinition`; duplicate detection; sorted listing. | Queried by `CliKernel::run()` and listing renderer. |
| `HasNativeCommandsInterface` | `packages/foundation/src/ServiceProvider/Capability/HasNativeCommandsInterface.php` | Foundation provider capability that yields `CommandDefinition`s without importing CLI into Foundation. | Detected by `PackageManifestCompiler`; consumed by `CliKernelServiceProvider`. |
| `PackageManifest::$nativeCommandProviders` | `packages/foundation/src/Discovery/PackageManifest.php` | Cached provider classes implementing `HasNativeCommandsInterface`. | Runtime command discovery source. |
| `PackageManifestCompiler` native command scan | `packages/foundation/src/Discovery/PackageManifestCompiler.php` | Adds providers implementing `HasNativeCommandsInterface` to manifest. | Enables registry build without scanning all classes at runtime. |

There are 22 first-party CLI providers under `packages/cli/src/Provider/`, registering 86 commands by yielding `CommandDefinition`s. Most handlers are DI-resolved `[Handler::class, 'execute']`; some are closures or `Closure::fromCallable()` wrappers because they need project-root state or inline construction.

### Parser, IO, Help, Signals, Testing

| Component | Path | Purpose | Kernel Interaction |
|---|---|---|---|
| `ArgvParser` | `packages/cli/src/Parser/ArgvParser.php` | Custom argv parser for positionals, long/short options, arrays, negatable options, `--`, and stacked boolean shorts. | Produces `ParsedInput`. |
| `ParsedInput`, `ParseError`, `ParseErrorKind`, `ParseException` | `packages/cli/src/Parser/*`, `packages/cli/src/Exception/ParseException.php` | Structured parse result and parse failure model. | Caught/formatted by `CliKernel`; parse errors exit `2`. |
| `CliIO` | `packages/cli/src/CliIO.php` | Public handler IO contract: argument/option access, stdout/stderr, prompts, verbose/interactivity checks. | Type accepted by current handlers. |
| `CliInput`, `CliOutput` | `packages/cli/src/Io/CliInput.php`, `CliOutput.php` | Minimal parsed input and writer abstractions. | Used by `ConsoleCliIO` and utilities. |
| `ConsoleCliIO` | `packages/cli/src/Io/ConsoleCliIO.php` | Concrete `CliIO` backed by parsed input, stdout/stderr writers, and `StdinSource`. | Constructed by `CliKernel` and `CliTester`; passed to handlers. |
| `BufferedCliOutput`, `StreamCliOutput` | `packages/cli/src/Io/*.php` | Capture/test output and real stream output. | Used by kernel, tests, and provider fallback streams. |
| `StdinSource`, `StreamStdinSource`, `StringQueueStdinSource`, `EmptyStdinSource`, `TtyDetector` | `packages/cli/src/Io/*.php` | Prompt input and TTY abstraction. | Used by `ConsoleCliIO`. |
| Anonymous tee output | `CliKernel::teeOutput()` | Writes to primary output and a buffer. | Wraps stdout/stderr passed to `ConsoleCliIO`. |
| `HelpRenderer` | `packages/cli/src/Help/HelpRenderer.php` | Custom help renderer with Symfony-like wording and global options. | Used for `<command> --help`. |
| `CliKernel::renderListing()` and `renderUsageHint()` | `packages/cli/src/CliKernel.php` | Native `list`/`help` and bare invocation output. | Runs before command dispatch. |
| `CliKernel::resolveVersion()` | `packages/cli/src/CliKernel.php` | Hard-coded top-level version text. | Used for `--version`. |
| `CliKernel` SIGINT hook | `packages/cli/src/CliKernel.php` | Generic `SIGINT` flag; dispatches after handler returns. | Returns `130` if fired. |
| `AiRunCommand` watch signal hook | `packages/cli/src/Command/Ai/AiRunCommand.php` | Command-local `SIGINT` handling while consuming SSE. | Preserves watch interruption behavior. |
| `Queue\Worker` signal hook | `packages/queue/src/Worker/Worker.php` | Handles `SIGTERM` and `SIGINT` for workers. | Invoked by `queue:work`; independent of CLI kernel. |
| `CliTester` | `packages/cli/src/Testing/CliTester.php` | Custom analogue to Symfony `CommandTester`; parses a single `CommandDefinition`, builds `ConsoleCliIO`, captures stdout/stderr. | Test-only native dispatch path. |

### Handler And Utility Inventory

| Class family | Current role | Migration disposition |
|---|---|---|
| `packages/cli/src/Provider/*.php` | Register command definitions and sometimes bind handlers. | Convert to Symfony command providers or command service tags; delete native command capability. |
| `packages/cli/src/Handler/*Handler.php` | Plain command handlers with `execute(CliIO): int`. | Rewrite as Symfony commands or keep as domain services called by command shells. |
| `packages/cli/src/Command/Ai/*`, `Audit/PruneCommand`, `Import/*`, `Oidc/*`, `Config/*` | Command-shaped classes using `CliIO`, plus config namespace policy. | Rewrite concrete command-shaped classes as Symfony `Command`s; adapt `ConfigCommand` as registration policy. |
| `packages/cli/src/Command/Make/AbstractMakeHandler.php` and `AbstractMakeCommand.php` | Duplicate make/scaffold helper bases. | Consolidate into a Symfony command base or a non-command helper service. |
| `packages/cli/src/Command/Migration/*` | Migration dry-run, verification, template, emitter, backfill support. | Keep mostly unchanged; these are command support utilities. |
| `packages/cli/src/Ingestion/*` | Source connectors, validators, normalizers, diagnostics. | Keep unless they should move to an ingestion package. |
| `packages/cli/src/Provenance/*`, `Support/*` | Composer/package provenance and path helpers. | Keep. |
| `packages/cli/src/Exception/*DefinitionException.php`, `DuplicateCommandException`, `ParseException` | Native definition/registry/parser failures. | Remove after adapter deletion. |

## Symfony Console Mapping

| Current custom component | Symfony Console equivalent | Disposition |
|---|---|---|
| `bin/waaseyaa` | `Application::run()` | Adapt; keep project-root guard. |
| `CliApplication` | `Application` plus Waaseyaa factory | Replace; optional deprecated facade during transition. |
| `CliKernel` | `Application`, `Command`, `ArgvInput`, `ConsoleOutput` | Replace entirely. |
| `ConsoleKernel` | Foundation composition root that builds Symfony `Application` | Adapt. |
| `CliKernelServiceProvider` | command factory/loader/registry service | Replace then remove. |
| `CommandRegistry` | `Application::add()`, `CommandLoaderInterface`, `ContainerCommandLoader` | Replace. |
| `CommandDefinition` | `Command::configure()`, `InputDefinition` | Temporary wrapper, then remove. |
| `ArgumentDefinition` / `ArgumentMode` | `InputArgument` | Temporary wrapper, then remove. |
| `OptionDefinition` / `OptionMode` | `InputOption`, including `VALUE_NEGATABLE` | Temporary wrapper, then remove. |
| `ArgvParser` | `ArgvInput` / `InputDefinition::bind()` | Remove. |
| `ParsedInput` | `InputInterface` | Remove. |
| `ParseError*` / `ParseException` | Symfony input exceptions | Remove; custom app may map exits. |
| `CliIO` | `InputInterface` + `OutputInterface` + `SymfonyStyle` | Temporary `SymfonyCliIO`, then remove. |
| `CliInput` | `InputInterface` | Remove. |
| `CliOutput` | `OutputInterface` | Remove or keep only as non-console wrapper. |
| `ConsoleCliIO` | `SymfonyStyle` / `QuestionHelper` | Replace. |
| `BufferedCliOutput` | `BufferedOutput` | Replace. |
| `StreamCliOutput` | `StreamOutput` / `ConsoleOutput` | Replace. |
| `StdinSource` family | `QuestionHelper`, `StreamableInputInterface`, `CommandTester::setInputs()` | Replace. |
| `TtyDetector` | Symfony interactivity and terminal helpers | Remove. |
| `HelpRenderer` | `DescriptorHelper`, Symfony `list` / `help` | Replace. |
| Anonymous tee output | Output decorators if needed | Remove. |
| `CliTester` | `CommandTester` / `ApplicationTester` | Replace. |
| `MigrateHandlerContainerDecorator` | Explicit DI service factories | Replace. |
| `HasNativeCommandsInterface` | `ProvidesConsoleCommandsInterface` or command service tags | Deprecate then remove. |
| `PackageManifest::$nativeCommandProviders` | `console_command_providers` / command service metadata | Replace. |
| `ConfigCommand` | Registration validation/DI compiler policy | Adapt. |
| `CliServiceProvider` | CLI package service provider for console services | Adapt. |

### Concrete Class Disposition Index

| Classes | Disposition |
|---|---|
| `ArgumentMode`, `OptionMode` | Remove after legacy adapter deletion. |
| `ArgumentDefinition`, `OptionDefinition`, `CommandDefinition` | Replace with Symfony `InputArgument`, `InputOption`, and `Command::configure()`; keep only as bridge input during transition. |
| `CommandRegistry` | Replace with Symfony `Application` / command loader. |
| `CliApplication`, `CliKernel`, `Provider\CliKernelServiceProvider` | Replace with `ConsoleApplicationFactory`, `WaaseyaaConsoleApplication`, and Symfony command registration. |
| `Kernel\MigrateHandlerContainerDecorator` | Replace with explicit DI factories for migrate commands. |
| `CliIO`, `Io\CliInput`, `Io\CliOutput`, `Io\ConsoleCliIO` | Replace with `InputInterface`, `OutputInterface`, `SymfonyStyle`; keep temporary `SymfonyCliIO` bridge. |
| `Io\BufferedCliOutput`, `Io\StreamCliOutput` | Replace with `BufferedOutput`, `StreamOutput`, `ConsoleOutput`. |
| `Io\StdinSource`, `Io\EmptyStdinSource`, `Io\StringQueueStdinSource`, `Io\StreamStdinSource`, `Io\TtyDetector` | Replace with Symfony input interactivity, `QuestionHelper`, and `CommandTester::setInputs()`. |
| `Parser\ArgvParser`, `Parser\ParsedInput`, `Parser\ParseError`, `Parser\ParseErrorKind`, `Exception\ParseException` | Replace with Symfony input binding and exceptions; delete. |
| `Exception\DuplicateCommandException`, `Exception\InvalidArgumentDefinitionException`, `Exception\InvalidCommandDefinitionException`, `Exception\InvalidOptionDefinitionException` | Delete when native definition validation is gone. |
| `Help\HelpRenderer` | Replace with Symfony descriptor/help/list rendering. |
| `Testing\CliTester` | Replace with Symfony `CommandTester` / `ApplicationTester`; optional deprecated facade only during transition. |
| `CliServiceProvider` | Adapt to register console application services and command providers. |
| Provider classes: `AiServiceProvider`, `AuditServiceProvider`, `BundleFixtureServiceProvider`, `ConfigCacheDbAuditServiceProvider`, `EntityTypeServiceProvider`, `HealthSchemaServiceProvider`, `ImportServiceProvider`, `IngestSearchSemanticServiceProvider`, `MakeServiceProviderA`, `MakeServiceProviderB`, `MakeStorageMigrationServiceProvider`, `MigrateServiceProvider`, `MiscAServiceProvider`, `MiscBServiceProvider`, `NorthCloudServiceProvider`, `OidcServiceProvider`, `OptimizeServiceProvider`, `OtherScaffoldsServiceProvider`, `QueueServiceProvider`, `SchedulePerfServiceProvider`, `UserPermissionServiceProvider` | Adapt from `HasNativeCommandsInterface::nativeCommands()` to `ProvidesConsoleCommandsInterface` / command service IDs. |
| Handler classes with `execute(CliIO): int`: `AboutHandler`, `AdminBuildHandler`, `AdminDevHandler`, `AuditLogHandler`, `BundleScaffoldHandler`, `CacheClearHandler`, `ConfigExportHandler`, `ConfigImportHandler`, `DbInitHandler`, `DebugContextHandler`, `EntityCreateHandler`, `EntityListHandler`, `EntityTypeListHandler`, `EventListHandler`, `ExtensionScaffoldHandler`, `FixtureGenerateHandler`, `FixturePackRefreshHandler`, `FixtureScaffoldHandler`, `HealthCheckHandler`, `HealthReportHandler`, `IngestDashboardHandler`, `IngestRunHandler`, `InstallHandler`, `MakeEntityHandler`, `MakeEntityTypeHandler`, `MakeJobHandler`, `MakeListenerHandler`, `MakeMigrationHandler`, `MakePluginHandler`, `MakePolicyHandler`, `MakeProviderHandler`, `MakePublicHandler`, `MakeStorageMigrationHandler`, `MakeTestHandler`, `MigrateDefaultsHandler`, `MigrateHandler`, `MigrateRollbackHandler`, `MigrateStatusHandler`, `NcSyncHandler`, `OptimizeClearHandler`, `OptimizeConfigHandler`, `OptimizeHandler`, `OptimizeManifestHandler`, `PerformanceBaselineHandler`, `PerformanceCompareHandler`, `PermissionListHandler`, `QueueFailedHandler`, `QueueFlushHandler`, `QueueRetryHandler`, `QueueWorkHandler`, `RelationshipTypeScaffoldHandler`, `RevisionsEnableHandler`, `RouteListHandler`, `ScaffoldAuthHandler`, `SchemaCheckHandler`, `SchemaListHandler`, `SchemaSyncHandler`, `ScheduleListHandler`, `ScheduleRunHandler`, `SearchReindexHandler`, `SemanticRefreshHandler`, `SemanticWarmHandler`, `ServeHandler`, `SyncRulesHandler`, `TypeDisableHandler`, `TypeEnableHandler`, `UserAssignRoleHandler`, `UserCreateHandler`, `UserRoleHandler`, `WaaseyaaVersionHandler`, `WorkflowScaffoldHandler` | Rewrite as Symfony commands or split into a Symfony command shell plus reusable domain service. |
| Command-shaped classes with `execute(CliIO): int`: `Command\Ai\AiRunCommand`, `AiPurgeRunsCommand`, `AiReapStalledRunsCommand`, `Command\Audit\PruneCommand`, `Command\Config\ConfigDiffCommand`, `ConfigExportCommand`, `ConfigImportCommand`, `ConfigResetCommand`, `ConfigStatusCommand`, `ConfigValidateCommand`, `Command\Import\ImportResetCommand`, `ImportResumeCommand`, `ImportRollbackCommand`, `ImportRunAllCommand`, `ImportRunCommand`, `ImportStatusCommand`, `Command\Oidc\RotateSigningKeyCommand` | Rewrite to extend Symfony `Command`. |
| `Command\Config\ConfigCommand` | Adapt as a command namespace policy helper or registration validator; it is not itself a runnable command. |
| `Command\Make\AbstractMakeCommand`, `Command\Make\AbstractMakeHandler` | Consolidate into one shared make/scaffold helper or Symfony command base. |
| `Command\Migration\BackfillHelper`, `DryRun*`, `OutputSanitizer`, `StorageMigration*`, `Verify*`, migration exceptions | Keep as command support utilities unless later moved to a migration package. |
| `Ingestion\*` source connectors, normalizers, validators, emitters, planners, inference helpers | Keep as command support/domain utilities unless later moved to an ingestion package. |
| `Provenance\ComposerProvenanceReporter`, `InstalledWaaseyaaPackage`, `ProvenanceReport` | Keep as diagnostic support utilities. |
| `Support\AdminPackagePathResolver` | Keep. |

## Provider Mapping

| Current provider | Current commands | Migration disposition |
|---|---|---|
| `AiServiceProvider` | `ai:run`, `ai:purge-runs`, `ai:reap-stalled-runs` | Rewrite; use Symfony signal support for watch where useful. |
| `AuditServiceProvider` | `audit:prune` | Rewrite `PruneCommand`. |
| `BundleFixtureServiceProvider` | `scaffold:bundle`, `fixture:scaffold`, `fixture:generate`, `fixture:pack:refresh` | Rewrite or adapt first. |
| `ConfigCacheDbAuditServiceProvider` | `config:export`, `config:import`, `cache:clear`, `db:init`, `audit:log` | Rewrite; inject project root into `db:init`. |
| `EntityTypeServiceProvider` | `entity:create`, `entity:list`, `entity-type:list`, `type:enable`, `type:disable` | Rewrite; preserve argument names. |
| `HealthSchemaServiceProvider` | `health:check`, `health:report`, `schema:check`, `schema:list`, `schema:sync`, `revisions:enable` | Rewrite; preserve JSON/human output. |
| `ImportServiceProvider` | `import:run`, `import:run-all`, `import:status`, `import:resume`, `import:rollback`, `import:reset` | Rewrite; move lock factory into DI. |
| `IngestSearchSemanticServiceProvider` | `ingest:run`, `ingest:dashboard`, `semantic:warm`, `semantic:refresh`, `search:reindex` | Rewrite command shells; keep utilities. |
| `MakeServiceProviderA/B`, `MakeStorageMigrationServiceProvider` | `make:*` | Rewrite; keep stub helper. |
| `MigrateServiceProvider` | `migrate`, `migrate:rollback`, `migrate:status`, `migrate:defaults` | Rewrite with explicit kernel-service factories. |
| `MiscAServiceProvider` | `about`, `admin:build`, `admin:dev`, `debug:context`, `event:list` | Rewrite; inject project root into admin/about services. |
| `MiscBServiceProvider` | `install`, `route:list`, `serve`, `sync-rules`, `waaseyaa:version` | Rewrite closures as command classes. |
| `NorthCloudServiceProvider` | `northcloud:sync` | Rewrite closure wrapper. |
| `OidcServiceProvider` | `oidc:rotate-signing-key` | Rewrite or extend Symfony `Command`. |
| `OptimizeServiceProvider` | `optimize`, `optimize:clear`, `optimize:config`, `optimize:manifest` | Rewrite; inject project root. |
| `OtherScaffoldsServiceProvider` | `scaffold:relationship`, `scaffold:workflow`, `scaffold:extension`, `scaffold:auth` | Rewrite; inject project root for auth scaffold. |
| `QueueServiceProvider` | `queue:work`, `queue:failed`, `queue:retry`, `queue:flush` | Rewrite; keep worker signal behavior. |
| `SchedulePerfServiceProvider` | `schedule:list`, `schedule:run`, `perf:baseline`, `perf:compare` | Rewrite. |
| `UserPermissionServiceProvider` | `user:create`, `user:role`, `user:assign-role`, `permission:list` | Rewrite. |

## Breaking Changes

### Public APIs And Behaviors That Change

| Current API/behavior | New Symfony behavior | Compatibility action |
|---|---|---|
| `CliApplication::main()` / `run()` | Symfony app runner through `ConsoleKernel` | Deprecate for one release or leave facade. |
| `CliKernel::run(array $argv): int` | No native kernel | Deprecate; tests use `ApplicationTester` / `CommandTester`. |
| `CommandDefinition`, `ArgumentDefinition`, `OptionDefinition`, mode enums | Commands configure `InputDefinition` directly | Deprecate during bridge; remove after rewrite. |
| `HasNativeCommandsInterface::nativeCommands()` | New Symfony command provider contract/service tags | Add `ProvidesConsoleCommandsInterface`; deprecate old. |
| `CliIO` | `InputInterface`, `OutputInterface`, `SymfonyStyle` | Temporary adapter preserves `CliIO`. |
| `CliTester` | Symfony testers | Provide migration guide and optional deprecated facade. |
| Parse errors exit `2` | Symfony defaults vary, usually `1` | Custom application maps usage/input errors to `2`. |
| `help` aliases `list` | Symfony `help <command>` renders command help | Keep no-arg `help` as list during BC window. |
| Bare `waaseyaa` hint | Symfony may print app help/list | Override no-arg behavior to preserve hint. |
| `--version` hard-coded text | Symfony application version | Set app version via resolver and preserve text if needed. |
| Non-TTY prompt defaults emit custom notice | Symfony question behavior | Preserve only where operator scripts rely on it. |

### CLI-Facing Behavior To Preserve

- All existing command names and options unless explicitly deprecated.
- Project-root invocation contract in `bin/waaseyaa`.
- Exit codes: `0`, `1`, `2`, `130`.
- Clear unknown-command message with `waaseyaa list` hint.
- Bare invocation short hint.
- `waaseyaa list`, `waaseyaa help`, `waaseyaa --help`, and `<command> --help`.
- Destructive-operation guard semantics for `--confirm`, `--yes`, `--dry-run`.
- stdout/stderr split and JSON outputs.
- Non-interactive deploy/CI behavior.
- `queue:work` and `ai:run --watch` signal behavior.

### Internal Behavior Safe To Remove

- Native parser restrictions such as rejecting glued short option values.
- Native help renderer and Symfony-output emulation.
- Native registry/listing implementation.
- Anonymous tee buffers.
- Manifest key `native_command_providers`.
- `CliTester` interleaving internals.
- Direct fallback provider instantiation in `CliKernelServiceProvider`.

## Replacement CLI Specification

This is the target architecture for `docs/specs/cli-kernel.md`.

### Purpose

`packages/cli/` provides the Symfony Console based command-line interface for Waaseyaa applications. The CLI entry point boots the Waaseyaa Foundation console kernel, constructs a `Symfony\Component\Console\Application`, registers framework and app commands from service providers, and delegates command parsing, help rendering, input/output handling, and execution to Symfony Console.

### Command Discovery

Commands are discovered during Foundation console boot from service providers declared in `extra.waaseyaa.providers`.

Supported discovery forms:

1. Preferred: providers implement `ProvidesConsoleCommandsInterface` and return Symfony command service IDs, command FQCNs, or command instances.
2. Transitional: providers implementing deprecated `HasNativeCommandsInterface` are adapted by converting each `CommandDefinition` into a legacy adapter command.
3. Optional future form: command classes annotated with `#[AsConsoleCommand]` may be discovered by `PackageManifestCompiler`.

### Command Registration

`ConsoleKernel::bootForCli()` boots the provider registry and DI container. `ConsoleApplicationFactory` creates `Application('Waaseyaa CLI', $version)`, configures exception/auto-exit behavior, adds commands from the container or providers, adapts legacy definitions during transition, and validates duplicate names and reserved `config:*` rules.

Command classes extend `Symfony\Component\Console\Command\Command`, declare names in `configure()` or default-name metadata, and define arguments/options with `InputArgument` and `InputOption`.

### Input And Output

New commands use `InputInterface`, `OutputInterface`, and optionally `SymfonyStyle`. During the bridge phase, `SymfonyCliIO` implements legacy `CliIO` over Symfony input/output. After all commands are rewritten, `CliIO` and `SymfonyCliIO` are removed.

### Error Handling

The Symfony application owns top-level exception rendering. Waaseyaa may subclass `Application` to preserve input-error exit `2`, unknown-command hints, terse non-verbose errors, and logger integration. Command-level domain validation returns `Command::FAILURE` and writes concise stderr messages.

### Help And Usage Rendering

Symfony Console owns `list`, `help`, `<command> --help`, argument/option descriptions, and global options. Waaseyaa preserves bare invocation as a short hint, preserves no-arg `help` as list during the BC window, and migrates all current descriptions into Symfony command configuration.

### Versioning

The Symfony application version is resolved from `VERSION`, package provenance, or a dedicated `VersionResolver`. `waaseyaa --version` is handled by Symfony. `waaseyaa:version` remains the richer diagnostic command.

### Exit Codes

| Code | Meaning |
|---|---|
| `0` | Success. |
| `1` | Command/domain failure or uncaught handler exception. |
| `2` | Usage/input parse error, unknown command, missing required argument, invalid option. |
| `64`-`78` | Reserved for future sysexits-style categories. |
| `130` | Interrupted by SIGINT where command/application signal handling reports it. |

### Signal Handling

Commands that need signal handling should implement Symfony Console signal facilities where available or keep command-local `pcntl_signal()` handling. `queue:work` keeps delegating to `Waaseyaa\Queue\Worker`; `ai:run --watch` preserves its current interruption behavior.

## Migration Roadmap

### Phase 0: Dependency And Boundary Preparation

1. Add `symfony/console` to `packages/cli/composer.json` and root `composer.json`.
2. Confirm `.symfony-import-allowlist.json` allows `packages/cli/` and Foundation console boot code.
3. Add an ADR reversing the no-Symfony CLI decision.
4. Replace `docs/specs/cli-kernel.md` with the Symfony Console specification.

### Phase 1: Introduce Symfony Application Beside Native Runtime

Create:

- `Waaseyaa\CLI\ConsoleApplicationFactory`
- `Waaseyaa\CLI\WaaseyaaConsoleApplication extends Symfony\Component\Console\Application`
- `Waaseyaa\CLI\Legacy\LegacyCommandDefinitionAdapter extends Command`
- `Waaseyaa\CLI\Legacy\SymfonyCliIO implements CliIO`
- `Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface`

Refactor:

- `ConsoleKernel::handle()` to build and run the Symfony application.
- Keep `CliApplication::run()` as deprecated wrapper if downstream callers exist.
- Keep `HasNativeCommandsInterface` support through the adapter.

### Phase 2: Port Command Registration

1. Convert providers to implement `ProvidesConsoleCommandsInterface`.
2. Return service IDs/FQCNs for real Symfony commands where migrated.
3. Move project-root construction into service factories.
4. Replace `MigrateHandlerContainerDecorator` with explicit migrate command factories.
5. Enforce duplicate command and `config:*` collision policy during registration.

### Phase 3: Rewrite Commands Incrementally

Recommended order:

1. Low-risk diagnostics: `about`, `waaseyaa:version`, `event:list`, `permission:list`.
2. Read-only diagnostics: `health:*`, `schema:list`, `route:list`, `telescope`.
3. Cache, optimize, admin.
4. Scaffolding and make commands.
5. Migration/import commands and lock-sensitive workflows.
6. Queue and long-running/signal-aware commands.
7. AI watch and SSE signal behavior.
8. Destructive commands: prune, rollback, reset, type disable.

### Phase 4: Delete Native Runtime

Delete after no production provider yields `CommandDefinition`:

- `CliKernel.php`, `CliApplication.php`, `Provider/CliKernelServiceProvider.php`, `Kernel/MigrateHandlerContainerDecorator.php`
- `CommandRegistry.php`, `CommandDefinition.php`, `ArgumentDefinition.php`, `OptionDefinition.php`, `ArgumentMode.php`, `OptionMode.php`
- `Parser/*`, `Help/HelpRenderer.php`, `Testing/CliTester.php`
- `Io/*` and `CliIO.php` unless retained as non-kernel wrappers
- native definition/parse/duplicate exceptions
- Foundation `HasNativeCommandsInterface`
- manifest `nativeCommandProviders` field and compiler scan

Update bin comments, README, specs, ADRs, changelog, upgrading notes, and public surface maps.

## Recommended PR Sequence

| PR | Scope | Risk |
|---|---|---|
| 1 | Add Symfony dependency, ADR, replacement spec | Low |
| 2 | Add Symfony app factory, custom app, new provider interface, legacy adapter | Medium |
| 3 | Switch runtime to Symfony app with adapter | High |
| 4 | Convert provider registration while still adapting legacy handlers | Medium |
| 5 | Migrate diagnostics/read-only commands | Low |
| 6 | Migrate scaffolding/make/admin/optimize commands | Medium |
| 7 | Migrate migration/import/config commands | High |
| 8 | Migrate queue/AI/signal-aware commands | High |
| 9 | Delete native parser/registry/help/IO/tester and manifest support | High |
| 10 | Refresh snapshots/docs/public API surface | Medium |

## Testing Strategy

- Replace parser/help/definition tests with adapter tests during transition, then delete them.
- Rewrite handler tests as Symfony `CommandTester` tests.
- Add application tests for exit-code mapping, unknown command output, no-arg behavior, version output, and verbose exception rendering.
- Keep `BinScriptTest` and provider registration tests.
- Run snapshot tests through the bridge to prove parity, then replace byte snapshots with semantic assertions where exact Symfony spacing is not a product contract.

Minimum verification:

```bash
vendor/bin/phpunit packages/cli/tests/Unit --no-coverage
vendor/bin/phpunit packages/cli/tests/Integration --no-coverage
vendor/bin/phpunit tests/Integration/Phase28/ConfigCommandCollisionBootTest.php --no-coverage
composer verify
```

Manual smoke:

```bash
bin/waaseyaa
bin/waaseyaa --version
bin/waaseyaa list
bin/waaseyaa help
bin/waaseyaa about
bin/waaseyaa health:check --json
bin/waaseyaa schema:list
bin/waaseyaa make:entity Example --help
bin/waaseyaa queue:work --max-jobs=1
```

## Risk Assessment

| Risk | Impact | Mitigation |
|---|---|---|
| Help output changes break snapshots/docs | Medium | Use semantic tests except where output is declared stable. |
| Usage errors return `1` instead of `2` | High | Custom application maps Symfony input errors to `2`. |
| `help` behavior changes | Medium | Keep no-arg `help` as list during BC window. |
| Global option conflicts | Medium | Validate command registration. |
| Provider discovery shifts too quickly | High | Keep legacy adapter until all providers migrate. |
| DI resolution differs from native `[Class, method]` | High | Use explicit command factories and test every command boots. |
| Project-root injection lost | High | Introduce `ProjectRoot` / kernel-context service. |
| Migration commands lose migrator/loader closures | High | Replace decorator with explicit factories. |
| Prompt behavior changes in CI | Medium | Preserve non-interactive defaults in commands/tests. |
| Long-running commands lose signal behavior | High | Add command-level signal tests. |
| Public API removals break downstream packages | High | Deprecate first; document in `UPGRADING.md`. |
| Manifest cache incompatibility | Medium | Accept old and new keys for one release; invalidate cache. |

## Implementation Checklist

- [ ] Add Symfony Console dependency.
- [ ] Add ADR and replacement spec.
- [ ] Add `ProvidesConsoleCommandsInterface`.
- [ ] Add Symfony application factory and custom application.
- [ ] Add legacy adapter from `CommandDefinition` to Symfony `Command`.
- [ ] Add `SymfonyCliIO` bridge.
- [ ] Switch Foundation `ConsoleKernel` runtime.
- [ ] Migrate provider registration.
- [ ] Port commands in staged groups.
- [ ] Replace `CliTester` usage with `CommandTester`.
- [ ] Remove native kernel/parser/help/IO/registry.
- [ ] Remove old Foundation native command capability and manifest key.
- [ ] Refresh docs, changelog, upgrade notes, and public surface map.
