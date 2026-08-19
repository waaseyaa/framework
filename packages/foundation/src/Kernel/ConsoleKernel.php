<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\CLI\Provider\MaintenanceServiceProvider;
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

        $maintenanceCommand = $input->getFirstArgument();
        if (in_array($maintenanceCommand, ['maintenance:on', 'maintenance:off', 'maintenance:status'], true)) {
            EnvLoader::load($this->projectRoot . '/.env');
            $application = new WaaseyaaConsoleApplication(
                version: new VersionResolver($this->projectRoot)->resolve(),
                logger: $this->logger,
            );
            $application->addCommand(MaintenanceServiceProvider::standaloneCommand($maintenanceCommand, $this->projectRoot));

            return $application->run($input, $output);
        }

        if ($input->getFirstArgument() === 'db:init') {
            $application = new WaaseyaaConsoleApplication(
                version: new VersionResolver($this->projectRoot)->resolve(),
                logger: $this->logger,
            );
            $application->addCommand(ConfigCacheDbAuditServiceProvider::dbInitCommand($this->projectRoot));

            return $application->run($input, $output);
        }

        $restrictedFieldAccessCommand = $input->getFirstArgument();
        if (in_array($restrictedFieldAccessCommand, [
            'field-access:preflight',
            'field-access:upgrade-legacy-entity-data',
        ], true)) {
            try {
                $this->bootForFieldAccessPreflight();
            } catch (\Throwable $e) {
                $application = new WaaseyaaConsoleApplication(
                    version: new VersionResolver($this->projectRoot)->resolve(),
                    logger: $this->logger,
                );

                return $application->renderWaaseyaaThrowable($e, $output);
            }

            return new ConsoleApplicationFactory(
                kernel: $this,
                container: $this->buildHandlerContainer(),
                providers: $this->getProviders(),
                logger: $this->logger,
            )->createFieldAccessMaintenanceOnly($restrictedFieldAccessCommand)->run($input, $output);
        }

        try {
            if (in_array($input->getFirstArgument(), [
                'schema:sync',
                'migrate',
                'migrate:rollback',
                'migrate:status',
                'site:init',
                // #2428: the installation phase must not construct runtime
                // consumers that require the generation it is about to create.
                'install:init',
            ], true)) {
                $this->bootForSchemaSync();
            } else {
                $this->bootForCli();
            }
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
