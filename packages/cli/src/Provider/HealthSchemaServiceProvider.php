<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\Handler\HealthCheckHandler;
use Waaseyaa\CLI\Handler\HealthReportHandler;
use Waaseyaa\CLI\Handler\SchemaCheckHandler;
use Waaseyaa\CLI\Handler\SchemaListHandler;
use Waaseyaa\CLI\Handler\SchemaSyncHandler;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\CLI\OptionMode;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class HealthSchemaServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void {}

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'health:check',
            description: 'Run all diagnostic health checks and report results',
            options: [
                new OptionDefinition(
                    name: 'json',
                    mode: OptionMode::None,
                    description: 'Output results as JSON',
                ),
            ],
            handler: [HealthCheckHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'health:report',
            description: 'Generate a full diagnostic report for operator review',
            options: [
                new OptionDefinition(
                    name: 'json',
                    mode: OptionMode::None,
                    description: 'Output as JSON',
                ),
                new OptionDefinition(
                    name: 'output',
                    shortcut: 'o',
                    mode: OptionMode::Required,
                    description: 'Write report to file',
                ),
            ],
            handler: [HealthReportHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'schema:check',
            description: 'Detect schema drift between entity type definitions and database tables',
            options: [
                new OptionDefinition(
                    name: 'json',
                    mode: OptionMode::None,
                    description: 'Output results as JSON',
                ),
            ],
            handler: [SchemaCheckHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'schema:list',
            description: 'List registered schemas with versions and compatibility policy',
            handler: [SchemaListHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'schema:sync',
            description: 'Materialize the storage schema (tables) for every registered entity type. Idempotent.',
            options: [
                new OptionDefinition(
                    name: 'dry-run',
                    mode: OptionMode::None,
                    description: 'Report which entity tables would be created without writing them.',
                ),
            ],
            handler: [SchemaSyncHandler::class, 'execute'],
        );
    }
}
