<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MakeStorageMigrationHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the `make:storage-migration` command with the CLI kernel.
 *
 * @api
 */
final class MakeStorageMigrationServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    /**
     * @api
     */
    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'make:storage-migration',
            description: 'Generate a sql-column storage migration for an entity type',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type machine name (e.g. "node", "user")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'target',
                    mode: HandlerOptionMode::Required,
                    description: 'Target backend id (default: sql-column)',
                    default: 'sql-column',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Print the migration to stdout without writing a file',
                ),
                new HandlerOption(
                    name: 'force',
                    mode: HandlerOptionMode::None,
                    description: 'Overwrite an existing migration file for this entity type',
                ),
            ],
            handler: [MakeStorageMigrationHandler::class, 'execute'],
        );
    }
}
