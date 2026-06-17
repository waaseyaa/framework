<?php

declare(strict_types=1);

namespace Waaseyaa\CLI;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Waaseyaa\CLI\Command\Config\ConfigCommand;
use Waaseyaa\CLI\Command\Config\ConfigExportCommand;
use Waaseyaa\CLI\Command\Config\ConfigImportCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\ConfigExportHandler;
use Waaseyaa\CLI\Handler\ConfigImportHandler;
use Waaseyaa\Config\Exception\ConfigCommandCollisionException;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;

final class ConsoleApplicationFactory
{
    /**
     * @param list<object> $providers
     */
    public function __construct(
        private readonly AbstractKernel $kernel,
        private readonly ContainerInterface $container,
        private readonly array $providers,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?VersionResolver $versionResolver = null,
    ) {}

    public function create(): WaaseyaaConsoleApplication
    {
        $logger = $this->logger ?? new NullLogger();
        $versionResolver = $this->versionResolver ?? new VersionResolver($this->kernel->getProjectRoot());
        $application = new WaaseyaaConsoleApplication($versionResolver->resolve(), $logger);

        foreach ($this->providers as $provider) {
            if (!$provider instanceof ProvidesConsoleCommandsInterface) {
                continue;
            }

            foreach ($provider->consoleCommands() as $entry) {
                try {
                    $command = $this->resolveCommand($entry);
                    if (!$command instanceof Command) {
                        $logger->warning(sprintf(
                            'Console command provider "%s" yielded non-command value %s; skipped.',
                            $provider::class,
                            get_debug_type($entry),
                        ));
                        continue;
                    }

                    $name = $command->getName();
                    if ($name === null || $name === '') {
                        $logger->warning(sprintf('Console command from "%s" has no name; skipped.', $provider::class));
                        continue;
                    }

                    $this->assertNoNamespaceCollision($name, $command);

                    if ($application->has($name)) {
                        $logger->warning(sprintf(
                            'Duplicate command "%s" from provider "%s"; existing command kept.',
                            $name,
                            $provider::class,
                        ));
                        continue;
                    }

                    $application->addCommand($command);
                } catch (ConfigCommandCollisionException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $logger->warning(sprintf(
                        'Could not register console command from provider "%s": %s',
                        $provider::class,
                        $e->getMessage(),
                    ));
                }
            }
        }

        return $application;
    }

    private function resolveCommand(mixed $entry): mixed
    {
        if ($entry instanceof Command) {
            if ($entry instanceof HandlerCommand) {
                return $entry->withContainer($this->container);
            }

            return $entry;
        }

        if (is_string($entry)) {
            $resolved = $this->container->has($entry) ? $this->container->get($entry) : null;
            if ($resolved instanceof Command) {
                return $resolved;
            }

            if (class_exists($entry) && is_subclass_of($entry, Command::class)) {
                return new $entry();
            }
        }

        return $entry;
    }

    private function assertNoNamespaceCollision(string $name, Command $command): void
    {
        $sourceClass = $command instanceof HandlerCommand ? $command->sourceClass() : $command::class;
        $sourceClass = match ($sourceClass) {
            ConfigExportHandler::class => ConfigExportCommand::class,
            ConfigImportHandler::class => ConfigImportCommand::class,
            default => $sourceClass,
        };

        ConfigCommand::assertNoCollision($name, $sourceClass);
    }
}
