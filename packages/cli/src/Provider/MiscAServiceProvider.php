<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractsEventDispatcherInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\AboutHandler;
use Waaseyaa\CLI\Handler\AdminBuildHandler;
use Waaseyaa\CLI\Handler\AdminDevHandler;
use Waaseyaa\CLI\Handler\DebugContextHandler;
use Waaseyaa\CLI\Handler\EventListHandler;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogManager;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MiscAServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresCapabilitiesInterface
{
    public function register(): void
    {
        $this->singleton(AdminBuildHandler::class, function (): AdminBuildHandler {
            $logger = $this->resolveOptional(LoggerInterface::class);
            $sanitizer = $logger instanceof LogManager
                ? $logger->sinkSanitizer()
                : new RedactorProcessor();

            return new AdminBuildHandler(
                projectRoot: $this->projectRoot,
                sanitizer: $sanitizer,
            );
        });
        $this->singleton(AboutHandler::class, function (): AboutHandler {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($context instanceof ConfigurationAuthorityContext);

            return new AboutHandler(
                configurationAuthority: $context,
                projectRoot: $this->projectRoot,
                runtimePolicy: RuntimePolicy::resolve($this->config),
            );
        });
        $this->singleton(EventListHandler::class, function (): EventListHandler {
            /** @var ContractsEventDispatcherInterface $dispatcher */
            $dispatcher = $this->resolve(ContractsEventDispatcherInterface::class);

            if (!$dispatcher instanceof EventDispatcherInterface) {
                throw new \RuntimeException('EventListHandler requires Symfony EventDispatcherInterface.');
            }

            return new EventListHandler(dispatcher: $dispatcher);
        });
    }

    public function capabilityRequirements(): iterable
    {
        yield CapabilityRequirement::exact('configuration.authority.v1', 1);
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'about',
            description: 'Display information about the Waaseyaa installation',
            handler: [AboutHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'admin:build',
            description: 'Build the Nuxt admin SPA for static hosting (npm run generate)',
            handler: [AdminBuildHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'admin:dev',
            description: 'Run the Nuxt admin SPA in development (npm run dev)',
            handler: \Closure::fromCallable([new AdminDevHandler(
                projectRoot: $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd(),
            ), 'execute']),
        );

        yield new HandlerCommand(
            name: 'debug:context',
            description: 'Render deterministic debug panels for workflow, traversal, and SSR context',
            options: [
                new HandlerOption(
                    name: 'entity-type',
                    mode: HandlerOptionMode::Required,
                    description: 'Source entity type',
                    default: 'node',
                ),
                new HandlerOption(
                    name: 'entity-id',
                    mode: HandlerOptionMode::Required,
                    description: 'Source entity ID',
                    default: '1',
                ),
                new HandlerOption(
                    name: 'workflow-state',
                    mode: HandlerOptionMode::Required,
                    description: 'Workflow state',
                    default: 'draft',
                ),
                new HandlerOption(
                    name: 'status',
                    mode: HandlerOptionMode::Required,
                    description: 'Status flag (0/1)',
                    default: '0',
                ),
                new HandlerOption(
                    name: 'relationship-counts',
                    mode: HandlerOptionMode::Required,
                    description: 'Traversal counts in outbound:inbound form',
                    default: '0:0',
                ),
                new HandlerOption(
                    name: 'view-mode',
                    mode: HandlerOptionMode::Required,
                    description: 'SSR view mode',
                    default: 'full',
                ),
                new HandlerOption(
                    name: 'preview',
                    mode: HandlerOptionMode::Required,
                    description: 'SSR preview mode (0/1)',
                    default: '0',
                ),
            ],
            handler: [DebugContextHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'event:list',
            description: 'List all registered events and listeners',
            handler: [EventListHandler::class, 'execute'],
        );
    }
}
