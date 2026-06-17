<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MakeEntityHandler;
use Waaseyaa\CLI\Handler\MakeJobHandler;
use Waaseyaa\CLI\Handler\MakeListenerHandler;
use Waaseyaa\CLI\Handler\MakeMigrationHandler;
use Waaseyaa\CLI\Handler\MakePolicyHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MakeServiceProviderA extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'make:entity',
            description: 'Generate a content entity class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity class name (e.g. "Article")',
                ),
            ],
            handler: [MakeEntityHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:job',
            description: 'Generate a queue job class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The job class name (e.g. "ProcessUpload")',
                ),
            ],
            handler: [MakeJobHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:listener',
            description: 'Generate an event listener class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The listener class name (e.g. "NotifyOnPublish")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'event',
                    mode: HandlerOptionMode::Required,
                    description: 'The event class to listen for',
                    default: 'object',
                ),
                new HandlerOption(
                    name: 'async',
                    mode: HandlerOptionMode::None,
                    description: 'Mark the listener as async',
                ),
            ],
            handler: [MakeListenerHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:migration',
            description: 'Generate a migration file',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The migration name (e.g. "create_comments_table")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'create',
                    mode: HandlerOptionMode::Required,
                    description: 'Table name to create',
                ),
                new HandlerOption(
                    name: 'table',
                    mode: HandlerOptionMode::Required,
                    description: 'Existing table name to modify',
                ),
                new HandlerOption(
                    name: 'package',
                    mode: HandlerOptionMode::Required,
                    description: 'Package name to write migration to (e.g. "waaseyaa/node")',
                ),
                new HandlerOption(
                    name: 'add-translations',
                    mode: HandlerOptionMode::Required,
                    description: 'Generate a translation-promotion migration for the given entity type id (FR-050)',
                ),
                new HandlerOption(
                    name: 'default-langcode',
                    mode: HandlerOptionMode::Required,
                    description: 'Default langcode for the translation-promotion migration. Required when --add-translations is used (FR-051)',
                ),
            ],
            handler: [MakeMigrationHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:policy',
            description: 'Generate an access policy class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The policy class name (e.g. "ContentPolicy")',
                ),
            ],
            handler: [MakePolicyHandler::class, 'execute'],
        );
    }
}
