<?php

declare(strict_types=1);

namespace Waaseyaa\CLI;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class WaaseyaaConsoleApplication extends Application
{
    private LoggerInterface $logger;

    public function __construct(
        string $version,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct('Waaseyaa CLI', $version);

        $this->logger = $logger ?? new NullLogger();
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $input ??= new ArgvInput();
        $output ??= new \Symfony\Component\Console\Output\ConsoleOutput();

        try {
            return $this->normalizeExitCode($this->doRun($input, $output));
        } catch (\Throwable $e) {
            return $this->renderWaaseyaaThrowable($e, $output);
        }
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $first = $input->getFirstArgument();

        if ($first === null && !$input->hasParameterOption(['--version', '-V'], true)) {
            $this->renderBareInvocation($output);

            return 0;
        }

        if ($first === 'help' && $this->isNoArgHelp($input)) {
            return parent::doRun(new ArrayInput(['command' => 'list']), $output);
        }

        return parent::doRun($input, $output);
    }

    private function renderBareInvocation(OutputInterface $output): void
    {
        $output->writeln('Waaseyaa CLI');
        $output->writeln('');
        $output->writeln('Run "waaseyaa list" to see all available commands.');
        $output->writeln('Run "waaseyaa <command> --help" for help on a specific command.');
    }

    private function isNoArgHelp(InputInterface $input): bool
    {
        if (!$input instanceof ArgvInput) {
            return true;
        }

        return count($input->getRawTokens()) === 1;
    }

    public function renderWaaseyaaThrowable(\Throwable $e, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $exitCode = $this->mapThrowableToExitCode($e);

        if (!$e instanceof ConsoleExceptionInterface) {
            $this->logger->error(sprintf('%s: %s', $e::class, $e->getMessage()));
        }

        $message = $e->getMessage();
        if ($e instanceof CommandNotFoundException) {
            $message .= "\nRun \"waaseyaa list\" to see the available commands.";
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $errorOutput->writeln(sprintf('%s: %s', $e::class, $message));
            $errorOutput->writeln($e->getTraceAsString());
        } else {
            $errorOutput->writeln($message);
        }

        return $exitCode;
    }

    private function mapThrowableToExitCode(\Throwable $e): int
    {
        if ((int) $e->getCode() === 130) {
            return 130;
        }

        if ($e instanceof ConsoleExceptionInterface) {
            return 2;
        }

        return 1;
    }

    private function normalizeExitCode(int $exitCode): int
    {
        if ($exitCode === 130) {
            return 130;
        }

        return $exitCode === 0 ? 0 : 1;
    }
}
