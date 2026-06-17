<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\PerformanceBaselineHandler;
use Waaseyaa\CLI\Handler\PerformanceCompareHandler;
use Waaseyaa\CLI\Handler\ScheduleListHandler;
use Waaseyaa\CLI\Handler\ScheduleRunHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class SchedulePerfServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'schedule:list',
            description: 'List all registered scheduled tasks',
            handler: [ScheduleListHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'schedule:run',
            description: 'Run due scheduled tasks',
            handler: [ScheduleRunHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'perf:baseline',
            description: 'Generate a versioned performance baseline artifact',
            options: [
                new HandlerOption(
                    name: 'contract-version',
                    mode: HandlerOptionMode::Required,
                    description: 'Baseline contract version',
                    default: 'v1.0',
                ),
                new HandlerOption(
                    name: 'surface',
                    mode: HandlerOptionMode::Required,
                    description: 'Baseline surface ID',
                    default: 'performance_regression_gate',
                ),
                new HandlerOption(
                    name: 'snapshot-hash',
                    mode: HandlerOptionMode::Required,
                    description: 'Snapshot hash to lock',
                ),
                new HandlerOption(
                    name: 'threshold',
                    mode: HandlerOptionMode::Array_,
                    description: 'Latency threshold in surface:ms form (repeatable)',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional output file path',
                ),
            ],
            handler: [PerformanceBaselineHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'perf:compare',
            description: 'Compare current performance measurements against a baseline artifact',
            options: [
                new HandlerOption(
                    name: 'baseline',
                    mode: HandlerOptionMode::Required,
                    description: 'Baseline artifact JSON path',
                ),
                new HandlerOption(
                    name: 'current',
                    mode: HandlerOptionMode::Required,
                    description: 'Current measurements JSON path',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit JSON result payload',
                ),
            ],
            handler: [PerformanceCompareHandler::class, 'execute'],
        );
    }
}
