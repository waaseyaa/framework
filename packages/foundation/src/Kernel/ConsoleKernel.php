<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\CLI\VersionResolver;
use Waaseyaa\CLI\WaaseyaaConsoleApplication;

/**
 * @api
 */
final class ConsoleKernel extends AbstractKernel
{
    public function handle(): int
    {
        $input = new ArgvInput();
        $output = new ConsoleOutput();

        if ($this->canRunWithoutFrameworkBoot($input)) {
            $application = new WaaseyaaConsoleApplication(
                version: new VersionResolver($this->projectRoot)->resolve(),
                logger: $this->logger,
            );

            return $application->run($input, $output);
        }

        try {
            $this->bootForCli();
        } catch (\Throwable $e) {
            $application = new WaaseyaaConsoleApplication(
                version: new VersionResolver($this->projectRoot)->resolve(),
                logger: $this->logger,
            );

            return $application->renderWaaseyaaThrowable($e, $output);
        }

        $factory = new ConsoleApplicationFactory(
            kernel: $this,
            container: $this->buildHandlerContainer(),
            providers: $this->getProviders(),
            logger: $this->logger,
        );

        return $factory->create()->run($input, $output);
    }

    private function canRunWithoutFrameworkBoot(ArgvInput $input): bool
    {
        return $input->getFirstArgument() === null
            || $input->hasParameterOption(['--version', '-V'], true);
    }
}
