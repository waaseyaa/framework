<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\HealthCheckHandler;
use Waaseyaa\CLI\Handler\HealthReportHandler;
use Waaseyaa\CLI\Handler\RevisionsEnableHandler;
use Waaseyaa\CLI\Handler\SchemaCheckHandler;
use Waaseyaa\CLI\Handler\SchemaListHandler;
use Waaseyaa\CLI\Handler\SchemaSyncHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class HealthSchemaServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'health:check',
            description: 'Run all diagnostic health checks and report results',
            options: [
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Output results as JSON',
                ),
            ],
            handler: [HealthCheckHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'health:report',
            description: 'Generate a full diagnostic report for operator review',
            options: [
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Output as JSON',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Write report to file',
                ),
            ],
            handler: [HealthReportHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'schema:check',
            description: 'Detect schema drift between entity type definitions and database tables',
            options: [
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Output results as JSON',
                ),
            ],
            handler: [SchemaCheckHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'schema:list',
            description: 'List registered schemas with versions and compatibility policy',
            handler: [SchemaListHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'schema:sync',
            description: 'Materialize the storage schema (tables) for every registered entity type. Idempotent.',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report which entity tables would be created without writing them.',
                ),
            ],
            handler: [SchemaSyncHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'revisions:enable',
            description: 'Make an existing entity type revisionable: ensure its revision schema and backfill an initial revision for each existing row.',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type ID to enable revisions for (must already be registered with revisionable: true).',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report what would happen without writing schema or backfilling.',
                ),
            ],
            handler: [RevisionsEnableHandler::class, 'execute'],
        );
    }
}
