<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\Handler\AuditLogHandler;
use Waaseyaa\CLI\Handler\CacheClearHandler;
use Waaseyaa\CLI\Handler\ConfigExportHandler;
use Waaseyaa\CLI\Handler\ConfigImportHandler;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\CLI\OptionMode;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ConfigCacheDbAuditServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void {}

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'config:export',
            description: 'Export active configuration to the sync directory',
            handler: [ConfigExportHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'config:import',
            description: 'Import configuration from the sync directory',
            handler: [ConfigImportHandler::class, 'execute'],
        );

        yield new CommandDefinition(
            name: 'cache:clear',
            description: 'Clear one or all cache bins',
            options: [
                new OptionDefinition(
                    name: 'bin',
                    shortcut: 'b',
                    mode: OptionMode::Required,
                    description: 'Clear a specific cache bin instead of all bins',
                ),
                new OptionDefinition(
                    name: 'tags',
                    mode: OptionMode::Required,
                    description: 'Invalidate cache entries by comma-separated tags (requires a tag-aware backend)',
                ),
            ],
            handler: [CacheClearHandler::class, 'execute'],
        );

        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        $dbInitHandler = new DbInitHandler(projectRoot: $projectRoot);

        yield new CommandDefinition(
            name: 'db:init',
            description: 'Initialize the database on first deploy and apply pending migrations.',
            options: [
                new OptionDefinition(
                    name: 'dry-run',
                    mode: OptionMode::None,
                    description: 'Show what would happen without creating files or running migrations.',
                ),
                new OptionDefinition(
                    name: 'sync-schema',
                    mode: OptionMode::None,
                    description: 'After migrations, materialize tables for every registered entity type (idempotent). Closes the app-entity persistence gap.',
                ),
            ],
            handler: \Closure::fromCallable([$dbInitHandler, 'execute']),
        );

        yield new CommandDefinition(
            name: 'audit:log',
            description: 'Display the entity type lifecycle audit log, or entity-write audit log with --entity-type',
            options: [
                new OptionDefinition(
                    name: 'type',
                    mode: OptionMode::Required,
                    description: 'Filter lifecycle log by entity type ID (e.g. note)',
                    default: '',
                ),
                new OptionDefinition(
                    name: 'tenant',
                    mode: OptionMode::Required,
                    description: 'Filter lifecycle log by tenant ID',
                    default: '',
                ),
                new OptionDefinition(
                    name: 'entity-type',
                    mode: OptionMode::Required,
                    description: 'Show entity-write audit log, optionally filtered by type (e.g. note)',
                ),
            ],
            handler: [AuditLogHandler::class, 'execute'],
        );
    }
}
