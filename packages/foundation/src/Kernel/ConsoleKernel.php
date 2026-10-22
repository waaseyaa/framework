<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\CLI\Provider\ConfigCacheDbAuditServiceProvider;
use Waaseyaa\CLI\Provider\MaintenanceServiceProvider;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\VersionResolver;
use Waaseyaa\CLI\WaaseyaaConsoleApplication;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;

/**
 * @api
 */
final class ConsoleKernel extends AbstractKernel
{
    private bool $initializingDatabase = false;

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

        // Construct these commands before parent framework boot. The site
        // handlers operate without a database; ordinary boot would create the
        // storage they must precede or inspect without mutation (#2644).
        // site:apply executes a previously reviewed request without compiling
        // it (#2789). project:init also keeps its parent boot-free, but runs
        // site:init and install:init as separate children: the latter retains
        // its own schema-sync boot and intentionally materializes the database.
        if (in_array($input->getFirstArgument(), ['site:init', 'site:doctor', 'site:apply', 'project:init'], true)) {
            $application = new WaaseyaaConsoleApplication(
                version: new VersionResolver($this->projectRoot)->resolve(),
                logger: $this->logger,
            );
            $application->addCommand(SiteServiceProvider::projectInitCommand($this->projectRoot));
            $application->addCommand(SiteServiceProvider::siteInitCommand($this->projectRoot));
            $application->addCommand(SiteServiceProvider::siteDoctorCommand($this->projectRoot));
            $application->addCommand(SiteServiceProvider::siteApplyCommand($this->projectRoot));

            return $application->run($input, $output);
        }

        if ($input->getFirstArgument() === 'install:init') {
            return $this->runInstallation($input, $output);
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
            if ($input->getFirstArgument() === 'entity:backfill-mutation-authorities') {
                $this->bootForMutationAuthorityBackfill();
            } elseif (in_array($input->getFirstArgument(), [
                'schema:sync',
                'migrate',
                'migrate:rollback',
                'migrate:status',
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

    protected function bootDatabase(): void
    {
        if (!$this->initializingDatabase) {
            parent::bootDatabase();

            return;
        }

        $this->database = new DatabaseBootstrapper()->initializeForInstallation(
            $this->projectRoot,
            $this->config,
            $this->logger,
        );
    }

    private function runInstallation(ArgvInput $input, ConsoleOutput $output): int
    {
        $application = new WaaseyaaConsoleApplication(
            version: new VersionResolver($this->projectRoot)->resolve(),
            logger: $this->logger,
        );
        $command = MigrateServiceProvider::installInitCommand();
        // Symfony handles help and validates the canonical command definition
        // before entering this callback, so only execution can create storage.
        $command->setCode(function () use ($input, $output): int {
            $this->initializingDatabase = true;
            try {
                $this->bootForSchemaSync();
            } finally {
                $this->initializingDatabase = false;
            }

            return new ConsoleApplicationFactory(
                kernel: $this,
                container: $this->buildHandlerContainer(),
                providers: $this->getProviders(),
                logger: $this->logger,
            )->create()->run($input, $output);
        });
        $application->addCommand($command);

        return $application->run($input, $output);
    }

    private function canRunWithoutFrameworkBoot(ArgvInput $input): bool
    {
        return $input->getFirstArgument() === null
            || $input->hasParameterOption(['--version', '-V'], true);
    }
}
