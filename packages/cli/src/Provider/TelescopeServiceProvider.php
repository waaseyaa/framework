<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\TelescopeClearHandler;
use Waaseyaa\CLI\Handler\TelescopeListHandler;
use Waaseyaa\CLI\Handler\TelescopePruneHandler;
use Waaseyaa\CLI\Handler\TelescopeValidateHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class TelescopeServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'telescope',
            description: 'List recent telescope entries',
            options: [
                new HandlerOption(
                    name: 'type',
                    shortcut: 't',
                    mode: HandlerOptionMode::Required,
                    description: 'Filter by entry type (query, event, request, cache, job, exception)',
                ),
                new HandlerOption(
                    name: 'limit',
                    shortcut: 'l',
                    mode: HandlerOptionMode::Required,
                    description: 'Maximum entries to show',
                    default: '20',
                ),
                new HandlerOption(
                    name: 'slow',
                    mode: HandlerOptionMode::Required,
                    description: 'Show only slow queries exceeding threshold in ms',
                ),
            ],
            handler: [TelescopeListHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'telescope:clear',
            description: 'Clear all telescope entries',
            options: [
                new HandlerOption(
                    name: 'type',
                    shortcut: 't',
                    mode: HandlerOptionMode::Required,
                    description: 'Clear only entries of a specific type',
                ),
            ],
            handler: [TelescopeClearHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'telescope:prune',
            description: 'Prune telescope entries older than retention period',
            options: [
                new HandlerOption(
                    name: 'hours',
                    mode: HandlerOptionMode::Required,
                    description: 'Prune entries older than N hours',
                    default: '24',
                ),
            ],
            handler: [TelescopePruneHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'telescope:validate',
            description: 'Validate codified context for a session and compute drift score',
            arguments: [
                new HandlerArgument(
                    name: 'session_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'Session ID to validate',
                ),
                new HandlerArgument(
                    name: 'output_file',
                    mode: HandlerArgumentMode::Optional,
                    description: 'Path to write validation report JSON',
                ),
            ],
            handler: [TelescopeValidateHandler::class, 'execute'],
        );
    }
}
