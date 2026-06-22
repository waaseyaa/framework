<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\QueueFailedHandler;
use Waaseyaa\CLI\Handler\QueueFlushHandler;
use Waaseyaa\CLI\Handler\QueueRetryHandler;
use Waaseyaa\CLI\Handler\QueueWorkHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class QueueServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'queue:work',
            description: 'Process jobs from the queue',
            arguments: [
                new HandlerArgument(
                    name: 'queue',
                    mode: HandlerArgumentMode::Optional,
                    description: 'The queue to process',
                    default: 'default',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'sleep',
                    mode: HandlerOptionMode::Required,
                    description: 'Seconds to sleep when no jobs available',
                    default: '3',
                ),
                new HandlerOption(
                    name: 'tries',
                    mode: HandlerOptionMode::Required,
                    description: 'Max attempts before failing a job',
                    default: '3',
                ),
                new HandlerOption(
                    name: 'timeout',
                    mode: HandlerOptionMode::Required,
                    description: 'Seconds a job may run',
                    default: '60',
                ),
                new HandlerOption(
                    name: 'max-jobs',
                    mode: HandlerOptionMode::Required,
                    description: 'Process N jobs then exit (0 = unlimited)',
                    default: '0',
                ),
                new HandlerOption(
                    name: 'max-time',
                    mode: HandlerOptionMode::Required,
                    description: 'Run for N seconds then exit (0 = unlimited)',
                    default: '0',
                ),
                new HandlerOption(
                    name: 'memory',
                    mode: HandlerOptionMode::Required,
                    description: 'Memory limit in MB',
                    default: '128',
                ),
            ],
            handler: [QueueWorkHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'queue:failed',
            description: 'List all failed queue jobs',
            handler: [QueueFailedHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'queue:retry',
            description: 'Retry a failed queue job',
            arguments: [
                new HandlerArgument(
                    name: 'id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The failed job ID, or "all" to retry everything',
                ),
            ],
            handler: [QueueRetryHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'queue:flush',
            description: 'Remove all failed queue jobs',
            options: [
                new HandlerOption(
                    name: 'yes',
                    shortcut: 'y',
                    mode: HandlerOptionMode::None,
                    description: 'Skip the confirmation prompt (required to flush in non-interactive mode).',
                ),
            ],
            handler: [QueueFlushHandler::class, 'execute'],
        );
    }
}
