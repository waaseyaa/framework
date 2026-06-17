<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\CLI\CliKernel;
use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\CommandRegistry;
use Waaseyaa\CLI\Help\HelpRenderer;
use Waaseyaa\CLI\Io\BufferedCliOutput;
use Waaseyaa\CLI\Io\EmptyStdinSource;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\CLI\OptionMode;
use Waaseyaa\CLI\CliIO;
use Waaseyaa\CLI\Provider\CliKernelServiceProvider;
use Waaseyaa\CLI\Provider\MiscBServiceProvider;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Foundation\Discovery\PackageManifest;

/**
 * Integration tests for CliKernel::run().
 *
 * Each test builds a real registry + kernel using in-memory IO.
 */
#[CoversNothing]
final class CliKernelDispatchTest extends TestCase
{
    private function makeKernel(
        CommandRegistry $registry,
        ?ContainerInterface $container = null,
    ): array {
        $stdout = new BufferedCliOutput();
        $stderr = new BufferedCliOutput();
        $container ??= $this->makeContainer([]);

        $kernel = new CliKernel(
            registry: $registry,
            container: $container,
            help: new HelpRenderer(),
            stdout: $stdout,
            stderr: $stderr,
            stdin: new EmptyStdinSource(),
        );

        return [$kernel, $stdout, $stderr];
    }

    private function makeContainer(array $bindings): ContainerInterface
    {
        return new class ($bindings) implements ContainerInterface {
            public function __construct(private readonly array $bindings) {}

            public function get(string $id): mixed
            {
                if (!isset($this->bindings[$id])) {
                    throw new class ($id) extends \RuntimeException implements NotFoundExceptionInterface {
                        public function __construct(string $id)
                        {
                            parent::__construct("No entry found for: {$id}");
                        }
                    };
                }
                return $this->bindings[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->bindings[$id]);
            }
        };
    }

    private function greetCommand(): CommandDefinition
    {
        return new CommandDefinition(
            name: 'greet',
            description: 'Greet someone.',
            options: [
                new OptionDefinition(
                    name: 'name',
                    mode: OptionMode::Required,
                    description: 'Name to greet.',
                    default: 'World',
                ),
            ],
            handler: static function (CliIO $io): int {
                $io->writeln('Hello, ' . $io->option('name') . '!');
                return 0;
            },
        );
    }

    // -------------------------------------------------------------------------
    // Bare invocation → usage hint pointing at `list`
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyArgvRendersUsageHintPointingAtList(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->greetCommand());

        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('waaseyaa list', $stdout->getContents());
        // The bare invocation must NOT dump the full listing.
        self::assertStringNotContainsString('Available commands:', $stdout->getContents());
    }

    // -------------------------------------------------------------------------
    // `list` / `help` command + top-level --help → listing
    // -------------------------------------------------------------------------

    #[Test]
    public function listCommandRendersListing(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->greetCommand());

        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['list']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Available commands:', $stdout->getContents());
        self::assertStringContainsString('greet', $stdout->getContents());
    }

    #[Test]
    public function listShowsCommandsFromRealProviders(): void
    {
        // Build a registry from the actual providers that ship `serve`
        // (MiscBServiceProvider) and `bimaaji:install` (BimaajiServiceProvider),
        // exactly as the kernel does at boot, then assert `list` surfaces both.
        $serveProvider = new MiscBServiceProvider();
        $serveProvider->setKernelContext((string) getcwd(), [], []);
        $bimaajiProvider = new BimaajiServiceProvider();
        $bimaajiProvider->setKernelContext((string) getcwd(), [], []);

        $registry = CliKernelServiceProvider::buildRegistry(
            manifest: new PackageManifest(),
            container: $this->makeContainer([]),
            providerInstances: [$serveProvider, $bimaajiProvider],
        );

        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['list']);

        self::assertSame(0, $exitCode);
        $out = $stdout->getContents();
        self::assertStringContainsString('Available commands:', $out);
        self::assertStringContainsString('serve', $out);
        self::assertStringContainsString('bimaaji:install', $out);
    }

    #[Test]
    public function helpCommandIsAliasForList(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->greetCommand());

        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['help']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Available commands:', $stdout->getContents());
        self::assertStringContainsString('greet', $stdout->getContents());
    }

    #[Test]
    public function helpFlagRendersListing(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->greetCommand());

        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['--help']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('greet', $stdout->getContents());
    }

    #[Test]
    public function emptyRegistryListingReportsNoCommands(): void
    {
        $registry = new CommandRegistry();
        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['list']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No commands registered', $stdout->getContents());
    }

    // -------------------------------------------------------------------------
    // --version
    // -------------------------------------------------------------------------

    #[Test]
    public function versionFlagPrintsVersionAndExits0(): void
    {
        $registry = new CommandRegistry();
        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['--version']);

        self::assertSame(0, $exitCode);
        self::assertNotEmpty($stdout->getContents());
    }

    // -------------------------------------------------------------------------
    // Unknown command → exit 2
    // -------------------------------------------------------------------------

    #[Test]
    public function unknownCommandExits2(): void
    {
        $registry = new CommandRegistry();
        [$kernel, $stdout, $stderr] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['no-such-command']);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Unknown command: no-such-command', $stderr->getContents());
        self::assertStringContainsString('waaseyaa list', $stderr->getContents());
    }

    // -------------------------------------------------------------------------
    // Command-level --help
    // -------------------------------------------------------------------------

    #[Test]
    public function commandHelpFlagRendersHelp(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->greetCommand());

        [$kernel, $stdout] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['greet', '--help']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('greet', $stdout->getContents());
        self::assertStringContainsString('Usage:', $stdout->getContents());
    }

    // -------------------------------------------------------------------------
    // Successful dispatch
    // -------------------------------------------------------------------------

    #[Test]
    public function successfulDispatchReturnsHandlerExitCode(): void
    {
        $registry = new CommandRegistry();
        $registry->register($this->greetCommand());

        [$kernel, $stdout, $stderr] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['greet', '--name=Alice']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Hello, Alice!', $stdout->getContents());
        self::assertEmpty($stderr->getContents());
    }

    #[Test]
    public function handlerReturning1Propagates(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new CommandDefinition(
            name: 'fail',
            description: 'Always fails.',
            handler: static fn (CliIO $io): int => 1,
        ));

        [$kernel] = $this->makeKernel($registry);
        self::assertSame(1, $kernel->run(['fail']));
    }

    #[Test]
    public function handlerReturning2Propagates(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new CommandDefinition(
            name: 'invalid',
            description: 'Returns 2.',
            handler: static fn (CliIO $io): int => 2,
        ));

        [$kernel] = $this->makeKernel($registry);
        self::assertSame(2, $kernel->run(['invalid']));
    }

    // -------------------------------------------------------------------------
    // Parse error → exit 2
    // -------------------------------------------------------------------------

    #[Test]
    public function parseErrorExits2WithMessageOnStderr(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new CommandDefinition(
            name: 'strict',
            description: 'Needs an option.',
            options: [
                new OptionDefinition(
                    name: 'required-opt',
                    mode: OptionMode::Required,
                    description: 'Must be provided.',
                ),
            ],
            handler: static fn (CliIO $io): int => 0,
        ));

        [$kernel, $stdout, $stderr] = $this->makeKernel($registry);
        // Pass an unknown option to trigger a parse error
        $exitCode = $kernel->run(['strict', '--unknown-flag=xyz']);

        self::assertSame(2, $exitCode);
        self::assertNotEmpty($stderr->getContents());
    }

    // -------------------------------------------------------------------------
    // Handler exception → exit 1
    // -------------------------------------------------------------------------

    #[Test]
    public function handlerThrowExits1WithClassAndMessage(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new CommandDefinition(
            name: 'boom',
            description: 'Throws.',
            handler: static function (CliIO $io): int {
                throw new \RuntimeException('something went wrong');
            },
        ));

        [$kernel, $stdout, $stderr] = $this->makeKernel($registry);
        $exitCode = $kernel->run(['boom']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('RuntimeException', $stderr->getContents());
        self::assertStringContainsString('something went wrong', $stderr->getContents());
    }

    // -------------------------------------------------------------------------
    // [FQN, method] handler → resolved via container
    // -------------------------------------------------------------------------

    #[Test]
    public function arrayHandlerIsResolvedViaContainer(): void
    {
        $handlerInstance = new class () {
            public function handle(CliIO $io): int
            {
                $io->writeln('from-container');
                return 0;
            }
        };

        $container = $this->makeContainer([$handlerInstance::class => $handlerInstance]);

        $registry = new CommandRegistry();
        $registry->register(new CommandDefinition(
            name: 'di-cmd',
            description: 'Uses DI.',
            handler: [$handlerInstance::class, 'handle'],
        ));

        [$kernel, $stdout] = $this->makeKernel($registry, $container);
        $exitCode = $kernel->run(['di-cmd']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('from-container', $stdout->getContents());
    }

    // -------------------------------------------------------------------------
    // Multiple commands in the registry
    // -------------------------------------------------------------------------

    #[Test]
    public function multipleCommandsListedAlphabetically(): void
    {
        $registry = new CommandRegistry();
        $registry->register(new CommandDefinition(
            name: 'zebra',
            description: 'Z command.',
            handler: static fn (CliIO $io): int => 0,
        ));
        $registry->register(new CommandDefinition(
            name: 'apple',
            description: 'A command.',
            handler: static fn (CliIO $io): int => 0,
        ));

        [$kernel, $stdout] = $this->makeKernel($registry);
        $kernel->run(['list']);
        $out = $stdout->getContents();

        $posApple = strpos($out, 'apple');
        $posZebra = strpos($out, 'zebra');

        self::assertNotFalse($posApple);
        self::assertNotFalse($posZebra);
        self::assertLessThan($posZebra, $posApple, 'apple should appear before zebra in the listing');
    }
}
