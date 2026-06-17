# CLI Kernel

## Purpose

`packages/cli/` provides the native command-line runtime for the Waaseyaa framework. It replaces the former Symfony Console dependency with a self-contained kernel that parses argv, dispatches commands via a typed registry, and returns POSIX exit codes — with no `use Symfony\Component\Console` statements anywhere in the runtime boot path.

The package is the sole entry point for `bin/waaseyaa` and exposes a testing harness (`CliTester`) that gives command authors the same ergonomics they had with Symfony's `CommandTester`, without carrying the Console component into production.

## Layer Placement

`waaseyaa/cli` sits at **Layer 6 — Interfaces**. It may import from any lower layer (Foundation through AI) but no package below Layer 6 may import from `waaseyaa/cli`. Upward communication from lower layers is via DomainEvents; the CLI kernel is a consumer, not a library.

The package is enforced by `bin/check-package-layers`. The `CliServiceProvider` is auto-discovered via `PackageManifestCompiler` and registered by `ConsoleKernel` at boot.

## Public Surface

| Class / Interface | Namespace | Role |
|---|---|---|
| `CliKernel` | `Waaseyaa\CLI` | Stateless dispatcher: `run(argv): int` |
| `CliApplication` | `Waaseyaa\CLI` | Process entry point — wires argv, STDIN/STDOUT/STDERR, calls `exit()` |
| `CommandDefinition` | `Waaseyaa\CLI` | Immutable record describing a single command |
| `ArgumentDefinition` | `Waaseyaa\CLI` | Positional parameter spec |
| `OptionDefinition` | `Waaseyaa\CLI` | Named-flag spec |
| `CommandRegistry` | `Waaseyaa\CLI` | Registry keyed by command name; built once at boot |
| `HasNativeCommandsInterface` | `Waaseyaa\CLI` | Provider capability contract: `nativeCommands(): iterable<CommandDefinition>` |
| `CliIO` | `Waaseyaa\CLI\Io` | Per-invocation context: parsed args/options, writers, prompts |
| `CliTester` | `Waaseyaa\CLI\Testing` | Test harness wrapping `CliKernel` with capture buffers |

Supporting types (`ArgumentDefinition`, `OptionDefinition`, `CommandRegistry`, `CliOutput`, `StdinSource`, `EmptyStdinSource`, `StringQueueStdinSource`, `HelpRenderer`) are also public but secondary.

Source paths:
- Kernel: `packages/cli/src/CliKernel.php`
- Application: `packages/cli/src/CliApplication.php`
- IO: `packages/cli/src/Io/`
- Help: `packages/cli/src/Help/HelpRenderer.php`
- Testing: `packages/cli/src/Testing/`
- Commands: `packages/cli/src/Command/`
- Parser: `packages/cli/src/Parser/`

## Argv Parser Semantics

The parser supports a bounded, audited subset of the argv surface. Everything outside this list is a parse error (exit `2`).

### Supported

| Feature | Example |
|---|---|
| Required positional argument | `<name>` |
| Optional positional argument | `[<name>]` |
| Array positional (trailing, collects remaining) | `[<files>...]` |
| Long option | `--verbose` |
| Short option | `-v` |
| Option modes | `NONE`, `REQUIRED`, `OPTIONAL`, `ARRAY`, `NEGATABLE` |
| `--key=value` and `--key value` equivalence | `--format=json` or `--format json` |
| Stacked short flags (NONE-mode only) | `-abc` ≡ `-a -b -c` |
| End-of-options sentinel | `--` (all tokens after are positional) |
| Negatable toggle | `--no-foo` sets a NEGATABLE `--foo` option to `false` |
| ARRAY accumulation | `--tag=a --tag=b` yields `['a', 'b']` |
| Default values for absent options | applied automatically from `OptionDefinition` |

### Explicitly NOT Supported

| Unsupported feature | Reason |
|---|---|
| Glued short-option values (`-fbar` for REQUIRED-mode) | Ambiguous with stacked NONE flags; not used in any shipped command |
| Multiple shortcuts per option | Symfony alias feature; not present in shipped surface |
| Auto-completion descriptors | Deferred to a possible `waaseyaa/cli-completion` package |

The shipped command surface was audited (145 first-party files); none rely on the unsupported features. A strict parser yields bounded test matrices and clear error messages.

### JSON Array Defaults

`ARRAY`-mode options default to `[]` (empty PHP array) when not supplied. `CliIO::getOption()` always returns an array for ARRAY-mode options — never `null`. Tests that previously asserted `null` for missing ARRAY options must be updated to assert `[]`.

### HelpRenderer Parity Invariants

`HelpRenderer` produces output byte-compatible with pre-cut Symfony Console snapshots:

1. **Declaration order for arguments** — arguments appear in the order they are declared on `CommandDefinition`.
2. **Alphabetical order for options** — options are sorted by long name. This eliminates flakiness tied to declaration order and is enforced by snapshot tests.
3. **Kernel-injected options** — `--help` (`-h`), `--verbose` (`-v`), `--quiet` (`-q`), `--no-interaction` (`-n`), `--version` are appended automatically to every command's help block. They are never declared per command.
4. **No word-wrap on piped stdout** — when `STDOUT` is not a TTY, `HelpRenderer` emits full lines with no width limit. TTY width detection uses `stream_isatty()`.

Help output format (three sections, separated by blank lines):

```
Usage:
  <command-name> [options] [--] <required_arg> [<optional_arg>] [<array_arg>...]

Arguments:
  <arg_name>        Description

Options:
  --long-option     Description
  -s, --short       Description
  -h, --help        Display help for the given command
  -v, --verbose     Increase the verbosity of messages
```

## Exit-Code Policy

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Handler-reported failure — handler returned `1`, threw a domain exception caught by the kernel, or otherwise signalled failure |
| `2` | Parse error — unknown command, unknown option, missing required argument, missing required option value |
| `64`–`78` | Reserved (sysexits.h range); kernel never emits today |
| `130` | SIGINT — process interrupted via Ctrl-C; kernel registers a PHP signal handler via `pcntl_signal()` if `pcntl` is available |

This aligns with POSIX / sysexits.h conventions and with the exit-code expectations of operator scripts that previously called Symfony Console commands.

**Parse-error display rule:** On exit `2`, a single-line error is written to stderr. No PHP stack trace unless `--verbose` was present in argv. On handler exception (exit `1`) with `--verbose`, the full trace is emitted to stderr.

## Provider Contract

Commands are registered via `HasNativeCommandsInterface`. Any `ServiceProvider` that implements this interface is discovered by `PackageManifestCompiler` and queried once at kernel boot.

### Interface

```php
namespace Waaseyaa\CLI;

interface HasNativeCommandsInterface
{
    /** @return iterable<CommandDefinition> */
    public function nativeCommands(): iterable;
}
```

### Constraints

- `nativeCommands()` is called exactly once per process boot, during manifest compilation.
- The method MUST be pure — no side effects, idempotent on repeated invocation.
- Multiple providers may register commands; `CommandRegistry` sorts by name, so cross-provider ordering does not affect public behaviour.
- The reserved long names `help`, `verbose`, `quiet`, `no-interaction`, `version` and shortcuts `h`, `v`, `q` are forbidden in user-defined `OptionDefinition`s (kernel auto-injects these).

### Example Provider

```php
namespace Waaseyaa\MyPackage;

use Waaseyaa\CLI\ArgumentDefinition;
use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\HasNativeCommandsInterface;
use Waaseyaa\CLI\Io\CliIO;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MyPackageServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void {}

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'my-package:greet',
            description: 'Print a greeting',
            arguments: [
                new ArgumentDefinition(name: 'name', description: 'Person to greet'),
            ],
            options: [
                new OptionDefinition(name: 'shout', description: 'Uppercase output', mode: OptionDefinition::NONE),
            ],
            handler: function (CliIO $io): int {
                $greeting = 'Hello, ' . $io->getArgument('name') . '!';
                if ($io->getOption('shout')) {
                    $greeting = strtoupper($greeting);
                }
                $io->writeln($greeting);
                return 0;
            },
        );
    }
}
```

### CommandDefinition Invariants

Violations throw `InvalidCommandDefinitionException`:

- `name` matches `/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)*$/`
- Argument names are unique within the command
- Option long names and shortcuts are unique within the command
- At most one `ArgumentDefinition` has `isArray = true`, and it must be last
- A required-mode argument may not follow an optional-mode argument
- `handler` is a `\Closure` of arity 1 returning `int`, or a `[ClassFqn::class, 'methodName']` array resolvable through the container

## CliKernel Lifecycle

### Dispatch Table

| argv | Kernel behaviour |
|---|---|
| `[]` (bare invocation) | Emit a brief usage hint pointing at `list` to stdout, exit `0` |
| `['--help']` or `['-h']` | Emit command listing to stdout, exit `0` (help for the implicit `list` command) |
| `['list']` or `['help']` | Emit command listing to stdout, exit `0`; `help` is an alias for `list` |
| `['--version']` | Emit framework version to stdout, exit `0` |
| `['unknown-name']` | `Unknown command: unknown-name` + a `Run "waaseyaa list" …` pointer to stderr, exit `2` |
| `['cmd', '--help']` | Emit help block for `cmd` to stdout, exit `0`; handler NOT invoked |
| `['cmd', ...]` | Parse argv, build `CliIO`, resolve handler, invoke, return its `int` |
| Parse error during dispatch | Single-line error to stderr, exit `2` |
| Uncaught exception in handler | `<class>: <message>` to stderr, exit `1`; full trace with `--verbose` |
| SIGINT | Return `130` |

`list` and `help` are **kernel built-ins**, not `CommandRegistry` entries — like `--help`/`--version`, they resolve in `CliKernel::run()` before the registry lookup and therefore work regardless of provider state (and do not appear as rows in their own listing). A bare invocation deliberately prints a short pointer rather than the full command dump; the full listing is reached via `list`, `help`, or `--help`.

### Constructor

```php
final class CliKernel
{
    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly ContainerInterface $container,
        private readonly Help\HelpRenderer $help,
        private readonly Io\CliOutput $stdout,
        private readonly Io\CliOutput $stderr,
        private readonly Io\StdinSource $stdin,
        private readonly ?LoggerInterface $logger = null,
    );

    /**
     * @param list<string> $argv  argv WITHOUT the script name (i.e. $_SERVER['argv'] sliced from index 1).
     * @return int                Exit code.
     */
    public function run(array $argv): int;
}
```

`CliKernel` is stateless with respect to argv — `run()` may be called multiple times within the same process (used by `CliTester`).

### CliIO API

```php
$io->getArgument(string $name): mixed
$io->getArguments(): array<string, mixed>
$io->getOption(string $name): mixed
$io->getOptions(): array<string, mixed>
$io->hasOption(string $name): bool   // true iff option was present on argv

$io->write(string $text): void
$io->writeln(string $text): void
$io->error(string $text): void       // writes to stderr

$io->ask(string $question, ?string $default = null): string
$io->confirm(string $question, bool $default = false): bool
```

`getArgument()` and `getOption()` throw `UnknownArgumentException` / `UnknownOptionException` for undeclared names. They return the declared default (or `null`) for declared-but-absent parameters.

Non-TTY prompts: when `STDIN` is not a TTY (or `CliIO` is constructed with `EmptyStdinSource`), `ask()` returns the default immediately and `confirm()` returns the default without blocking.

## Testing

### CliTester

`CliTester` wraps `CliKernel` with capture buffers. It is the direct replacement for Symfony's `CommandTester`.

```php
use Waaseyaa\CLI\Testing\CliTester;

$tester = CliTester::for($definition, $container);

// Run via argv slice (mirrors kernel::run() exactly)
$tester->execute(['positional-value', '--shout']);

// Or via associative map (mirrors CommandTester::execute() ergonomics)
$tester->executeMap(['name' => 'World', '--shout' => true]);

$tester->getExitCode(): int
$tester->getStdout(): string
$tester->getStderr(): string
$tester->getOutput(): string   // stdout + stderr interleaved
```

Stdin injection:

```php
use Waaseyaa\CLI\Io\StringQueueStdinSource;

$tester = CliTester::for($definition, $container, stdin: new StringQueueStdinSource(['yes', 'Alice']));
```

### Migration Mapping from Symfony CommandTester

| Symfony API | Waaseyaa `CliTester` |
|---|---|
| `new CommandTester($command)` | `CliTester::for($definition, $container)` |
| `$tester->execute(['--opt' => 'v', 'arg' => 'x'])` | `$tester->executeMap(['--opt' => 'v', 'arg' => 'x'])` |
| `$tester->getStatusCode()` | `$tester->getExitCode()` |
| `$tester->getDisplay()` | `$tester->getStdout()` |
| `$tester->setInputs(['yes', 'foo'])` | Pass `StringQueueStdinSource(['yes', 'foo'])` to `CliTester::for(..., stdin: ...)` |

### Snapshot Fixture Immutability (WP01 Contract)

`packages/cli/tests/Integration/Snapshot/` contains byte-parity fixtures — one per public command — recorded against the pre-cut Symfony Console baseline. These fixtures MUST NOT be edited except during a mission that intentionally changes CLI output. Any test that modifies a snapshot fixture requires a corresponding spec update and WP entry explaining why the output changed.

## Integration with Foundation

### Manifest Discovery

`PackageManifestCompiler` scans every registered `ServiceProvider` for capability interfaces. `HasNativeCommandsInterface` is in its capability list alongside `HasMiddlewareInterface`, `HasEntityTypesInterface`, etc. Discovery requires an optimised autoloader (`composer dump-autoload --optimize`) for classmap-based scanning; PSR-4 fallback is used during development.

### Container Resolution

Handler closures declared as `[\SomeHandler::class, 'handle']` are resolved through `ContainerInterface` by `CliKernel` at dispatch time, not at registration time. This means providers can declare commands before their dependencies are fully wired, as long as resolution succeeds by the time the command is invoked.

### ConsoleKernel Boot Order

`ConsoleKernel::boot()` sequence:

1. `ManifestBootstrapper` — compiles or loads the cached package manifest
2. `ProviderRegistry` — discovers and instantiates all service providers
3. `HasNativeCommandsInterface` scan — iterates providers, collects `CommandDefinition`s
4. `CommandRegistry::build()` — validates and indexes commands by name
5. `CliKernel::run($argv)` — parse → registry lookup → dispatch → exit code

## Out of Scope

The following are deferred and not part of `packages/cli/`:

- Shell tab-completion descriptors (potential `waaseyaa/cli-completion` package)
- Progress bars
- Table renderer (e.g. `SymfonyStyle::table()`) — the current `health:check` and `schema:check` commands implement their own table rendering inline
- Colour/ANSI escape helper beyond the current `CliOutput` implementation

## Implementation gotchas

- **`MakeMigrationCommand` requires `$projectRoot`**: Constructor is `(string $projectRoot)` (not no-arg). `ConsoleKernel` must pass `$this->projectRoot`. The `--package` flag is not yet implemented (see #464).
- **Migration CLI commands take `\Closure` providers**: `MigrateCommand`, `MigrateRollbackCommand`, `MigrateStatusCommand` all accept `(Migrator, \Closure $migrationsProvider)`. The closure defers filesystem scanning until the command runs. In `ConsoleKernel`: `fn () => $this->migrationLoader->loadAll()`.

## Related Specs

- [`docs/specs/operator-diagnostics.md`](./operator-diagnostics.md) — health:check, health:report, schema:check commands
- [`docs/specs/infrastructure.md`](./infrastructure.md) — service provider lifecycle, package manifest
