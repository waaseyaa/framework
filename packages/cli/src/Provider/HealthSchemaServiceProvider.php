<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Handler\HealthCheckHandler;
use Waaseyaa\CLI\Handler\HealthReportHandler;
use Waaseyaa\CLI\Handler\RevisionsEnableHandler;
use Waaseyaa\CLI\Handler\SchemaCheckHandler;
use Waaseyaa\CLI\Handler\SchemaListHandler;
use Waaseyaa\CLI\Handler\SchemaSyncHandler;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Preflight\FieldAccessPreflightScanner;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Queue\Security\SignedQueuePayload;

final class HealthSchemaServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void
    {
        $this->singleton(FieldAccessPreflightHandler::class, function (): FieldAccessPreflightHandler {
            $database = $this->resolve(DatabaseInterface::class);
            $manager = $this->resolve(EntityTypeManager::class);
            $signer = $this->resolveOptional(SignedQueuePayload::class);
            assert($database instanceof DatabaseInterface);
            assert($manager instanceof EntityTypeManager);

            return new FieldAccessPreflightHandler(
                new DatabaseFieldAccessInventoryScanner(
                    $database,
                    $manager,
                    $signer instanceof SignedQueuePayload ? $signer : null,
                ),
                $manager,
                new FieldAccessPreflightScanner(),
                $this->projectRoot,
            );
        });
    }

    public function consoleCommands(): iterable
    {
        yield self::fieldAccessPreflightCommand();

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

    /** Restricted-bootstrap descriptor; constructing it resolves no application service. */
    public static function fieldAccessPreflightCommand(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'field-access:preflight',
            description: 'Read-only inventory of field classifications and activation blockers.',
            options: [
                new HandlerOption(
                    name: 'format',
                    mode: HandlerOptionMode::Required,
                    description: 'Machine-readable output format (json).',
                    default: 'json',
                ),
                new HandlerOption(
                    name: 'write-artifact',
                    mode: HandlerOptionMode::None,
                    description: 'Write the checksum-bound result to .waaseyaa/field-access-preflight.json.',
                ),
            ],
            handler: [FieldAccessPreflightHandler::class, 'execute'],
        );
    }
}
