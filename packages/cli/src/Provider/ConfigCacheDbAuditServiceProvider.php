<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\AuditLogHandler;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Handler\ConfigExportHandler;
use Waaseyaa\CLI\Handler\ConfigImportHandler;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ConfigCacheDbAuditServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'config:export',
            description: 'Export active configuration to the sync directory',
            handler: [ConfigExportHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'config:import',
            description: 'Import configuration from the sync directory',
            handler: [ConfigImportHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'cache:clear',
            description: 'Clear one or all cache bins',
            options: [
                new HandlerOption(
                    name: 'bin',
                    shortcut: 'b',
                    mode: HandlerOptionMode::Required,
                    description: 'Clear a specific cache bin instead of all bins',
                ),
                new HandlerOption(
                    name: 'tags',
                    mode: HandlerOptionMode::Required,
                    description: 'Invalidate cache entries by comma-separated tags (requires a tag-aware backend)',
                ),
            ],
            handler: [CacheClearHandler::class, 'execute'],
        );

        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        $dbInitHandler = new DbInitHandler(projectRoot: $projectRoot);

        yield new HandlerCommand(
            name: 'db:init',
            description: 'Initialize the database on first deploy and apply pending migrations.',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Show what would happen without creating files or running migrations.',
                ),
                new HandlerOption(
                    name: 'sync-schema',
                    mode: HandlerOptionMode::None,
                    description: 'After migrations, materialize tables for every registered entity type (idempotent). Closes the app-entity persistence gap.',
                ),
            ],
            handler: \Closure::fromCallable([$dbInitHandler, 'execute']),
        );

        yield new HandlerCommand(
            name: 'audit:log',
            description: 'Display the entity type lifecycle audit log, or entity-write audit log with --entity-type',
            options: [
                new HandlerOption(
                    name: 'type',
                    mode: HandlerOptionMode::Required,
                    description: 'Filter lifecycle log by entity type ID (e.g. note)',
                    default: '',
                ),
                new HandlerOption(
                    name: 'tenant',
                    mode: HandlerOptionMode::Required,
                    description: 'Filter lifecycle log by tenant ID',
                    default: '',
                ),
                new HandlerOption(
                    name: 'entity-type',
                    mode: HandlerOptionMode::Required,
                    description: 'Show entity-write audit log, optionally filtered by type (e.g. note)',
                ),
            ],
            handler: [AuditLogHandler::class, 'execute'],
        );
    }
}
