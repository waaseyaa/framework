<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MigrateDefaultsHandler;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Handler\MigrateRollbackHandler;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MigrateServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'migrate',
            description: 'Run pending database migrations (use --dry-run to preview, --verify to audit)',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Preview pending migrations without applying any SQL or writing to the ledger.',
                ),
                new HandlerOption(
                    name: 'verify',
                    mode: HandlerOptionMode::None,
                    description: 'Compare ledger checksums against the live source. Read-only.',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit machine-readable JSON instead of human-readable text.',
                ),
            ],
            handler: [MigrateHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate:rollback',
            description: 'Roll back the last batch of migrations',
            handler: [MigrateRollbackHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate:status',
            description: 'Show the status of each migration',
            handler: [MigrateStatusHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate:defaults',
            description: 'Migrate default content type enablement for tenants',
            options: [
                new HandlerOption(
                    name: 'tenant',
                    mode: HandlerOptionMode::Array_,
                    description: 'Tenant IDs to migrate (repeatable)',
                ),
                new HandlerOption(
                    name: 'enable',
                    mode: HandlerOptionMode::Required,
                    description: 'Type ID to enable for all tenants (e.g. note)',
                    default: '',
                ),
                new HandlerOption(
                    name: 'actor',
                    mode: HandlerOptionMode::Required,
                    description: 'Actor ID for audit log entries',
                    default: 'cli',
                ),
                new HandlerOption(
                    name: 'yes',
                    shortcut: 'y',
                    mode: HandlerOptionMode::None,
                    description: 'Skip confirmation prompts',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report actions without making changes',
                ),
                new HandlerOption(
                    name: 'rollback',
                    mode: HandlerOptionMode::None,
                    description: 'Rollback previous migrate:defaults actions',
                ),
            ],
            handler: [MigrateDefaultsHandler::class, 'execute'],
        );
    }
}
