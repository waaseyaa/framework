<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\NcSyncHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\NorthCloud\Sync\NcSyncService;

final class NorthCloudServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'northcloud:sync',
            description: 'Pull content from the NorthCloud Search API and persist entities via registered mappers',
            options: [
                new HandlerOption(
                    name: 'limit',
                    shortcut: 'l',
                    mode: HandlerOptionMode::Required,
                    description: 'Maximum hits to fetch',
                    default: '20',
                ),
                new HandlerOption(
                    name: 'since',
                    shortcut: 's',
                    mode: HandlerOptionMode::Required,
                    description: 'Fetch content from this date (YYYY-MM-DD)',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report what would be created without persisting',
                ),
                new HandlerOption(
                    name: 'explain',
                    mode: HandlerOptionMode::None,
                    description: 'Show skip reason breakdown and sampled hit diagnostics',
                ),
                new HandlerOption(
                    name: 'sample',
                    mode: HandlerOptionMode::Required,
                    description: 'Capture up to N created/skipped samples in output',
                    default: '10',
                ),
                new HandlerOption(
                    name: 'report-json',
                    mode: HandlerOptionMode::Required,
                    description: 'Write sync report JSON to this path',
                ),
            ],
            handler: function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                $northcloudConfig = $this->config['northcloud'] ?? [];
                $northcloudConfig = is_array($northcloudConfig) ? $northcloudConfig : [];
                $statusPath = $northcloudConfig['sync']['status_path'] ?? null;

                $handler = new NcSyncHandler(
                    syncService: $this->resolve(NcSyncService::class),
                    statusPath: is_string($statusPath) ? $statusPath : null,
                );

                return $handler->execute($io);
            },
        );
    }
}
