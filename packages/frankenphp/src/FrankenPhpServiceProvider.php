<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\FrankenPhp\Command\DevCommand;
use Waaseyaa\FrankenPhp\Command\InstallCommand;

/**
 * Optional FrankenPHP dev-runtime provider.
 *
 * Registers the `frankenphp:install` and `dev` console commands when the
 * `waaseyaa/frankenphp` package is installed. The framework CORE has no
 * dependency on this package and no FrankenPHP coupling — these commands exist
 * only when an app opts in (the skeleton does, by default). The runtime-agnostic
 * `serve` (plain `php -S`) stays in core as the zero-dependency fallback.
 *
 * Discovered via `extra.waaseyaa.providers` in this package's composer.json.
 *
 * @api
 */
final class FrankenPhpServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        yield new InstallCommand($projectRoot);
        yield new DevCommand($projectRoot);
    }
}
