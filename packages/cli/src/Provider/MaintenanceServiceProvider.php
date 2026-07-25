<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MaintenanceOffHandler;
use Waaseyaa\CLI\Handler\MaintenanceOnHandler;
use Waaseyaa\CLI\Handler\MaintenanceStatusHandler;
use Waaseyaa\Foundation\Maintenance\MaintenanceSettings;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the `maintenance:on|off|status` quiesce commands (#2122). The flag
 * path and default Retry-After are resolved from the same
 * {@see MaintenanceSettings} source the pre-boot HTTP gate uses, so CLI and
 * runtime never disagree about which flag file is canonical.
 */
final class MaintenanceServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void
    {
        $this->singleton(MaintenanceState::class, function (): MaintenanceState {
            $settings = MaintenanceSettings::fromEnvironment($this->projectRoot);

            return new MaintenanceState($settings->flagPath);
        });

        $this->singleton(MaintenanceOnHandler::class, function (): MaintenanceOnHandler {
            $settings = MaintenanceSettings::fromEnvironment($this->projectRoot);
            $state = $this->resolve(MaintenanceState::class);
            assert($state instanceof MaintenanceState);

            return new MaintenanceOnHandler($state, $settings->defaultRetryAfter);
        });

        $this->singleton(MaintenanceOffHandler::class, function (): MaintenanceOffHandler {
            $state = $this->resolve(MaintenanceState::class);
            assert($state instanceof MaintenanceState);

            return new MaintenanceOffHandler($state);
        });

        $this->singleton(MaintenanceStatusHandler::class, function (): MaintenanceStatusHandler {
            $state = $this->resolve(MaintenanceState::class);
            assert($state instanceof MaintenanceState);

            return new MaintenanceStatusHandler($state);
        });
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'maintenance:on',
            description: 'Enable maintenance mode: serve a branded 503 + Retry-After to non-exempt traffic. Idempotent.',
            options: [
                new HandlerOption(
                    name: 'retry-after',
                    mode: HandlerOptionMode::Required,
                    description: 'Retry-After header value in seconds (default 120).',
                ),
                new HandlerOption(
                    name: 'message',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional message shown on the maintenance page.',
                ),
            ],
            handler: [MaintenanceOnHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'maintenance:off',
            description: 'Disable maintenance mode: clear the flag and restore normal service. Idempotent.',
            handler: [MaintenanceOffHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'maintenance:status',
            description: 'Report maintenance state. Exit 0 = serving, 1 = in maintenance (incl. fail-closed).',
            options: [
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Output status as JSON.',
                ),
            ],
            handler: [MaintenanceStatusHandler::class, 'execute'],
        );
    }
}
