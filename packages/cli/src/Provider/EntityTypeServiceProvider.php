<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\EntityCreateHandler;
use Waaseyaa\CLI\Handler\EntityListHandler;
use Waaseyaa\CLI\Handler\EntityTypeListHandler;
use Waaseyaa\CLI\Handler\TypeDisableHandler;
use Waaseyaa\CLI\Handler\TypeEnableHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class EntityTypeServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'entity:create',
            description: 'Create a new entity of a given type',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type ID',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'values',
                    mode: HandlerOptionMode::Required,
                    description: 'JSON string of entity values (inline; prefer --field/--values-file to avoid shell quoting)',
                    default: '{}',
                ),
                new HandlerOption(
                    name: 'field',
                    mode: HandlerOptionMode::Array_,
                    description: 'Field value as name=value (repeatable). No JSON; quoting-free in PowerShell/cmd/POSIX.',
                ),
                new HandlerOption(
                    name: 'field-file',
                    mode: HandlerOptionMode::Array_,
                    description: 'Load a field from a file: name=@path (repeatable). For large fields like a Markdown body.',
                ),
                new HandlerOption(
                    name: 'values-file',
                    mode: HandlerOptionMode::Required,
                    description: 'Read the whole value set as a JSON file path, or "-" for stdin.',
                ),
            ],
            handler: [EntityCreateHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'entity:list',
            description: 'List entities of a given type',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type ID',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'limit',
                    shortcut: 'l',
                    mode: HandlerOptionMode::Required,
                    description: 'Maximum number of entities to list',
                    default: '25',
                ),
            ],
            handler: [EntityListHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'entity-type:list',
            description: 'List all registered entity types',
            handler: [EntityTypeListHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'type:enable',
            description: 'Re-enable a previously disabled content type',
            arguments: [
                new HandlerArgument(
                    name: 'type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type ID to enable (e.g. note)',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'actor',
                    mode: HandlerOptionMode::Required,
                    description: 'Actor ID for the audit log',
                    default: 'cli',
                ),
                new HandlerOption(
                    name: 'tenant',
                    mode: HandlerOptionMode::Required,
                    description: 'Tenant ID (optional, for per-tenant enable)',
                    default: '',
                ),
            ],
            handler: [TypeEnableHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'type:disable',
            description: 'Disable a registered content type (does not delete it)',
            arguments: [
                new HandlerArgument(
                    name: 'type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type ID to disable (e.g. note)',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'actor',
                    mode: HandlerOptionMode::Required,
                    description: 'Actor ID for the audit log',
                    default: 'cli',
                ),
                new HandlerOption(
                    name: 'tenant',
                    mode: HandlerOptionMode::Required,
                    description: 'Tenant ID (optional, for per-tenant disable)',
                    default: '',
                ),
                new HandlerOption(
                    name: 'force',
                    mode: HandlerOptionMode::None,
                    description: 'Allow disabling the last enabled type for the tenant',
                ),
                new HandlerOption(
                    name: 'yes',
                    shortcut: 'y',
                    mode: HandlerOptionMode::None,
                    description: 'Skip confirmation prompt',
                ),
            ],
            handler: [TypeDisableHandler::class, 'execute'],
        );
    }
}
