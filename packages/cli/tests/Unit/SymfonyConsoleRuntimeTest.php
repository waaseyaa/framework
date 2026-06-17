<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\WaaseyaaConsoleApplication;
use Waaseyaa\Config\Exception\ConfigCommandCollisionException;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(WaaseyaaConsoleApplication::class)]
#[CoversClass(HandlerCommand::class)]
#[CoversClass(SymfonyCommandIO::class)]
final class SymfonyConsoleRuntimeTest extends TestCase
{
    public function testBareInvocationPrintsShortHint(): void
    {
        $tester = new ApplicationTester(new WaaseyaaConsoleApplication('test'));

        self::assertSame(0, $tester->run([]));
        self::assertStringContainsString('Waaseyaa CLI', $tester->getDisplay());
        self::assertStringContainsString('Run "waaseyaa list" to see all available commands.', $tester->getDisplay());
    }

    public function testNoArgHelpFallsBackToList(): void
    {
        $application = new WaaseyaaConsoleApplication('test');
        $application->addCommand(new class extends Command {
            public function __construct()
            {
                parent::__construct('about');
                $this->setDescription('Describe the installation.');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('about ran');

                return Command::SUCCESS;
            }
        });

        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run(['command' => 'help']));
        self::assertStringContainsString('about', $tester->getDisplay());
        self::assertStringNotContainsString('about ran', $tester->getDisplay());
    }

    public function testUnknownCommandExitsTwoWithListHint(): void
    {
        $tester = new ApplicationTester(new WaaseyaaConsoleApplication('test'));

        self::assertSame(2, $tester->run(['command' => 'missing'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Run "waaseyaa list" to see the available commands.', $tester->getErrorOutput());
    }

    public function testVerboseExceptionShowsTraceAndNonVerboseDoesNot(): void
    {
        $logger = new SpyLogger();
        $application = new WaaseyaaConsoleApplication('test', $logger);
        $application->addCommand(new class extends Command {
            public function __construct()
            {
                parent::__construct('explode');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                throw new \RuntimeException('boom');
            }
        });

        $normal = new ApplicationTester($application);
        self::assertSame(1, $normal->run(['command' => 'explode'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('boom', $normal->getErrorOutput());
        self::assertStringNotContainsString('#0', $normal->getErrorOutput());

        $verbose = new ApplicationTester($application);
        self::assertSame(1, $verbose->run(['command' => 'explode'], [
            'capture_stderr_separately' => true,
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]));
        self::assertStringContainsString('RuntimeException: boom', $verbose->getErrorOutput());
        self::assertStringContainsString('#0', $verbose->getErrorOutput());
        self::assertNotEmpty($logger->errors);
    }

    public function testHandlerCommandMapsArgumentsOptionsAndOutputs(): void
    {
        $definition = new HandlerCommand(
            name: 'legacy:test',
            description: 'Exercise legacy bridge.',
            arguments: [
                new HandlerArgument('required', HandlerArgumentMode::Required),
                new HandlerArgument('optional', HandlerArgumentMode::Optional, default: 'fallback'),
                new HandlerArgument('tail', HandlerArgumentMode::Optional, isArray: true),
            ],
            options: [
                new HandlerOption('flag', mode: HandlerOptionMode::None),
                new HandlerOption('required-option', mode: HandlerOptionMode::Required),
                new HandlerOption('optional-option', mode: HandlerOptionMode::Optional, default: 'opt-default'),
                new HandlerOption('many', mode: HandlerOptionMode::Array_),
                new HandlerOption('cache', mode: HandlerOptionMode::Negatable),
            ],
            handler: static function (SymfonyCommandIO $io): int {
                $io->writeln(json_encode([
                    'required' => $io->argument('required'),
                    'optional' => $io->argument('optional'),
                    'tail' => $io->argument('tail'),
                    'flag' => $io->option('flag'),
                    'requiredOption' => $io->option('required-option'),
                    'optionalOption' => $io->option('optional-option'),
                    'many' => $io->option('many'),
                    'cache' => $io->option('cache'),
                ], JSON_THROW_ON_ERROR));
                $io->error('legacy stderr');

                return 0;
            },
        );

        $tester = new CommandTester($definition->withContainer($this->container()));

        self::assertSame(0, $tester->execute([
            'required' => 'one',
            'optional' => 'two',
            'tail' => ['three', 'four'],
            '--flag' => true,
            '--required-option' => 'needed',
            '--optional-option' => 'given',
            '--many' => ['a', 'b'],
            '--no-cache' => true,
        ], ['capture_stderr_separately' => true]));

        self::assertJsonStringEqualsJsonString(
            '{"required":"one","optional":"two","tail":["three","four"],"flag":true,"requiredOption":"needed","optionalOption":"given","many":["a","b"],"cache":false}',
            trim($tester->getDisplay()),
        );
        self::assertSame("legacy stderr\n", $tester->getErrorOutput(true));
    }

    public function testHandlerCommandResolvesDiHandler(): void
    {
        $handler = new LegacyDiHandler();
        $definition = new HandlerCommand(
            name: 'legacy:di',
            description: 'DI handler.',
            handler: [LegacyDiHandler::class, 'execute'],
        );

        $tester = new CommandTester($definition->withContainer($this->container([
            LegacyDiHandler::class => $handler,
        ])));

        self::assertSame(0, $tester->execute([]));
        self::assertSame('resolved', trim($tester->getDisplay()));
    }

    public function testHandlerCommandPreservesNonInteractivePromptDefaults(): void
    {
        $definition = new HandlerCommand(
            name: 'legacy:prompt',
            description: 'Prompt defaults.',
            handler: static function (SymfonyCommandIO $io): int {
                $io->writeln((string) $io->ask('Name?', 'Ada'));
                $io->writeln($io->confirm('Continue?', true) ? 'yes' : 'no');

                return 0;
            },
        );

        $tester = new CommandTester($definition->withContainer($this->container()));

        self::assertSame(0, $tester->execute([], [
            'capture_stderr_separately' => true,
            'interactive' => false,
        ]));
        self::assertSame("Ada\nyes\n", $tester->getDisplay(true));
        self::assertStringContainsString('stdin is not a tty; using default for prompt "Name?"', $tester->getErrorOutput());
        self::assertStringContainsString('stdin is not a tty; using default for prompt "Continue?"', $tester->getErrorOutput());
    }

    public function testSymfonyInputErrorsMapToExitTwo(): void
    {
        $application = new WaaseyaaConsoleApplication('test');
        $application->addCommand((new HandlerCommand(
            name: 'legacy:required',
            description: 'Requires argument.',
            arguments: [new HandlerArgument('name', HandlerArgumentMode::Required)],
            handler: static fn(SymfonyCommandIO $io): int => 0,
        ))->withContainer($this->container()));

        $tester = new ApplicationTester($application);

        self::assertSame(2, $tester->run(['command' => 'legacy:required'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Not enough arguments', $tester->getErrorOutput());
    }

    public function testFactoryRegistersProviderCommandsAndKeepsFirstDuplicate(): void
    {
        $first = new NamedCommand('sample');
        $second = new NamedCommand('sample');
        $other = new NamedCommand('other');
        $provider = new class ([$first, $second, $other]) extends ServiceProvider implements ProvidesConsoleCommandsInterface {
            public function __construct(private readonly array $commands) {}

            public function register(): void {}

            public function consoleCommands(): iterable
            {
                yield from $this->commands;
            }
        };

        $application = new ConsoleApplicationFactory(
            kernel: $this->kernel(),
            container: $this->container(),
            providers: [$provider],
        )->create();

        self::assertTrue($application->has('sample'));
        self::assertTrue($application->has('other'));
        self::assertSame($first, $application->find('sample'));
    }

    public function testFactoryRejectsReservedConfigCommandCollisions(): void
    {
        $provider = new class extends ServiceProvider implements ProvidesConsoleCommandsInterface {
            public function register(): void {}

            public function consoleCommands(): iterable
            {
                yield new NamedCommand('config:export');
            }
        };

        $this->expectException(ConfigCommandCollisionException::class);

        new ConsoleApplicationFactory(
            kernel: $this->kernel(),
            container: $this->container(),
            providers: [$provider],
        )->create();
    }

    /**
     * @param array<string, object> $bindings
     */
    private function container(array $bindings = []): ContainerInterface
    {
        return new class ($bindings) implements ContainerInterface {
            /**
             * @param array<string, object> $bindings
             */
            public function __construct(private readonly array $bindings) {}

            public function get(string $id): object
            {
                if (!isset($this->bindings[$id])) {
                    throw new class ($id) extends \RuntimeException implements NotFoundExceptionInterface {
                        public function __construct(string $id)
                        {
                            parent::__construct(sprintf('Not found: %s', $id));
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

    private function kernel(): AbstractKernel
    {
        return new class (sys_get_temp_dir()) extends AbstractKernel {};
    }
}

final class NamedCommand extends Command
{
    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

final class LegacyDiHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $io->writeln('resolved');

        return 0;
    }
}

final class SpyLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<string> */
    public array $errors = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::ERROR) {
            $this->errors[] = (string) $message;
        }
    }
}
