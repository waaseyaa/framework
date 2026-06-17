<?php

declare(strict_types=1);

namespace Waaseyaa\CLI;

use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Exception\ParseException;
use Waaseyaa\CLI\Help\HelpRenderer;
use Waaseyaa\CLI\Io\BufferedCliOutput;
use Waaseyaa\CLI\Io\CliOutput;
use Waaseyaa\CLI\Io\ConsoleCliIO;
use Waaseyaa\CLI\Io\StdinSource;
use Waaseyaa\CLI\Parser\ArgvParser;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Native CLI kernel: resolves commands, parses argv, dispatches handlers.
 *
 * Usage:
 *   $exitCode = $kernel->run(array_slice($_SERVER['argv'], 1));
 *   exit($exitCode);
 *
 * Full contract: kitty-specs/native-cli-kernel-01KR2NR7/contracts/cli-kernel.md
 */
final class CliKernel
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly ContainerInterface $container,
        private readonly HelpRenderer $help,
        private readonly CliOutput $stdout,
        private readonly CliOutput $stderr,
        private readonly StdinSource $stdin,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run the CLI application.
     *
     * @param list<string> $argv  The argv WITHOUT the script name (i.e. $_SERVER['argv'] sliced from index 1).
     * @return int                Exit code (0 success, 1 handler failure, 2 parse error, 130 SIGINT).
     */
    public function run(array $argv): int
    {
        // 1. --version flag (anywhere in argv)
        if (in_array('--version', $argv, true)) {
            $this->stdout->writeln($this->resolveVersion());
            return 0;
        }

        // 2. Top-level --help → command listing (help for the implicit `list` command).
        if ($argv === ['--help'] || $argv === ['-h']) {
            $this->renderListing();
            return 0;
        }

        // 3. Bare invocation → point the user at `list` instead of dumping everything.
        if ($argv === []) {
            $this->renderUsageHint();
            return 0;
        }

        // 4. Pop command name
        $commandName = array_shift($argv);

        // `list` (and its `help` alias) render the full command listing. These are
        // kernel built-ins — like --help and --version — so they are not held in the
        // CommandRegistry and always resolve here regardless of provider state.
        if ($commandName === 'list' || $commandName === 'help') {
            $this->renderListing();
            return 0;
        }

        $command = $this->registry->get($commandName);

        if ($command === null) {
            $this->stderr->writeln(sprintf('Unknown command: %s', $commandName));
            $this->stderr->writeln('Run "waaseyaa list" to see the available commands.');
            return 2;
        }

        // 4. --help for a specific command
        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            $this->stdout->write($this->help->render($command));
            return 0;
        }

        // 5. Parse argv
        $parser = new ArgvParser();
        $verbose = false;

        try {
            $parsed = $parser->parse($argv, $command);
            $verbose = (bool) ($parsed->options['verbose'] ?? false);
        } catch (ParseException $e) {
            $this->stderr->writeln(sprintf('Error: %s', $e->parseError->message));
            if ($verbose) {
                $this->stderr->writeln($e->getTraceAsString());
            }
            return 2;
        }

        // 6. Build IO, resolve handler, dispatch
        $stdoutBuffer = new BufferedCliOutput();
        $stderrBuffer = new BufferedCliOutput();

        $io = new ConsoleCliIO(
            input: $parsed,
            stdout: $this->teeOutput($this->stdout, $stdoutBuffer),
            stderr: $this->teeOutput($this->stderr, $stderrBuffer),
            stdin: $this->stdin,
            verbose: $verbose,
        );

        // Register SIGINT handler if pcntl is available
        $sigintFired = false;
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, static function () use (&$sigintFired): void {
                $sigintFired = true;
            });
        }

        $handler = $this->resolveHandler($command);
        $exitCode = 0;

        try {
            $exitCode = $handler($io);

            // Dispatch pending signals
            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            if ($sigintFired) {
                return 130;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            $this->stderr->writeln(sprintf('%s: %s', $e::class, $e->getMessage()));
            if ($verbose) {
                $this->stderr->writeln($e->getTraceAsString());
            }
            return 1;
        }

        return $exitCode;
    }

    /**
     * Resolve the handler closure, injecting the container for [FQN, method] handlers.
     *
     * @return \Closure(CliIO): int
     */
    private function resolveHandler(CommandDefinition $command): \Closure
    {
        $ref = $command->handlerReference;

        if ($ref === null) {
            // Already a plain closure.
            return $command->handler;
        }

        [$fqn, $method] = $ref;
        $instance = $this->container->get($fqn);

        return static fn(CliIO $io): int => $instance->{$method}($io);
    }

    /**
     * Render a brief usage hint pointing the user at the `list` command.
     *
     * Shown for a bare invocation (no argv) so the default output is a short
     * pointer rather than the full command dump.
     */
    private function renderUsageHint(): void
    {
        $this->stdout->writeln('Waaseyaa CLI');
        $this->stdout->writeln('');
        $this->stdout->writeln('Run "waaseyaa list" to see all available commands.');
        $this->stdout->writeln('Run "waaseyaa <command> --help" for help on a specific command.');
    }

    /**
     * Render a listing of all registered commands to stdout.
     */
    private function renderListing(): void
    {
        $commands = $this->registry->all();

        if ($commands === []) {
            $this->stdout->writeln('No commands registered.');
            return;
        }

        $this->stdout->writeln('Available commands:');
        $this->stdout->writeln('');

        $nameWidth = max(array_map('strlen', array_keys($commands)));
        foreach ($commands as $name => $command) {
            $this->stdout->writeln(sprintf(
                '  %s  %s',
                str_pad($name, $nameWidth),
                $command->description,
            ));
        }
        $this->stdout->writeln('');
    }

    /**
     * Create an output that writes to both the primary output and a buffer.
     */
    private function teeOutput(CliOutput $primary, BufferedCliOutput $buffer): CliOutput
    {
        return new class ($primary, $buffer) implements CliOutput {
            public function __construct(
                private readonly CliOutput $primary,
                private readonly BufferedCliOutput $buffer,
            ) {}

            public function write(string $text): void
            {
                $this->primary->write($text);
                $this->buffer->write($text);
            }

            public function writeln(string $text = ''): void
            {
                $this->primary->writeln($text);
                $this->buffer->writeln($text);
            }
        };
    }

    private function resolveVersion(): string
    {
        return 'Waaseyaa CLI';
    }
}
