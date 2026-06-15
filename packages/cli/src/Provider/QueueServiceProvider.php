<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\ArgumentDefinition;
use Waaseyaa\CLI\ArgumentMode;
use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\Handler\QueueFailedHandler;
use Waaseyaa\CLI\Handler\QueueFlushHandler;
use Waaseyaa\CLI\Handler\QueueRetryHandler;
use Waaseyaa\CLI\Handler\QueueWorkHandler;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\CLI\OptionMode;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class QueueServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void {}

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'queue:work',
            description: 'Process jobs from the queue',
            arguments: [
                new ArgumentDefinition(
                    name: 'queue',
                    mode: ArgumentMode::Optional,
                    description: 'The queue to process',
                    default: 'default',
                ),
            ],
            options: [
                new OptionDefinition(
                    name: 'sleep',
                    mode: OptionMode::Required,
                    description: 'Seconds to sleep when no jobs available',
                    default: '3',
                ),
                new OptionDefinition(
                    name: 'tries',
                    mode: OptionMode::Required,
                    description: 'Max attempts before failing a job',
                    default: '3',
                ),
                new OptionDefinition(
                    name: 'timeout',
                    mode: OptionMode::Required,
                    description: 'Seconds a job may run',
                    default: '60',
                ),
                new OptionDefinition(
                    name: 'max-jobs',
                    mode: OptionMode::Required,
                    description: 'Process N jobs then exit (0 = unlimited)',
                    default: '0',
                ),
                new OptionDefinition(
                    name: 'max-time',
                    mode: OptionMode::Required,
                    description: 'Run for N seconds then exit (0 = unlimited)',
                    default: '0',
                ),
                new OptionDefinition(
                    name: 'memory',
                    mode: OptionMode::Required,
                    description: 'Memory limit in MB',
                    default: '128',
                ),
            ],
            handler: [QueueWorkHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'queue:failed',
            description: 'List all failed queue jobs',
            handler: [QueueFailedHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'queue:retry',
            description: 'Retry a failed queue job',
            arguments: [
                new ArgumentDefinition(
                    name: 'id',
                    mode: ArgumentMode::Required,
                    description: 'The failed job ID, or "all" to retry everything',
                ),
            ],
            handler: [QueueRetryHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'queue:flush',
            description: 'Remove all failed queue jobs',
            options: [
                new OptionDefinition(
                    name: 'yes',
                    shortcut: 'y',
                    mode: OptionMode::None,
                    description: 'Skip the confirmation prompt (required to flush in non-interactive mode).',
                ),
            ],
            handler: [QueueFlushHandler::class, 'execute'],
        );
    }
}
