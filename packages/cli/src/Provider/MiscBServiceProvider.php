<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\InstallHandler;
use Waaseyaa\CLI\Handler\RouteListHandler;
use Waaseyaa\CLI\Handler\ServeHandler;
use Waaseyaa\CLI\Handler\SyncRulesHandler;
use Waaseyaa\CLI\Handler\WaaseyaaVersionHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MiscBServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        yield new HandlerCommand(
            name: 'install',
            description: 'Install Waaseyaa with initial configuration',
            options: [
                new HandlerOption(
                    name: 'site-name',
                    mode: HandlerOptionMode::Required,
                    description: 'The name of the site',
                    default: 'Waaseyaa',
                ),
                new HandlerOption(
                    name: 'site-mail',
                    mode: HandlerOptionMode::Required,
                    description: 'Site email address',
                    default: 'admin@example.com',
                ),
                new HandlerOption(
                    name: 'admin-email',
                    mode: HandlerOptionMode::Required,
                    description: 'Admin user email',
                    default: 'admin@example.com',
                ),
                new HandlerOption(
                    name: 'admin-password',
                    mode: HandlerOptionMode::Required,
                    description: 'Admin user password',
                ),
            ],
            handler: function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                /** @var \Waaseyaa\Config\ConfigManagerInterface $configManager */
                $configManager = $this->resolve(\Waaseyaa\Config\ConfigManagerInterface::class);
                /** @var \Waaseyaa\Entity\EntityTypeManagerInterface $entityTypeManager */
                $entityTypeManager = $this->resolve(\Waaseyaa\Entity\EntityTypeManagerInterface::class);

                return new InstallHandler(
                    entityTypeManager: $entityTypeManager,
                    configManager: $configManager,
                )->execute($io);
            },
        );

        yield new HandlerCommand(
            name: 'route:list',
            description: 'List all registered routes',
            options: [
                new HandlerOption(
                    name: 'path',
                    mode: HandlerOptionMode::Required,
                    description: 'Filter routes by path pattern',
                ),
            ],
            handler: function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io): int {
                /** @var \Waaseyaa\Routing\WaaseyaaRouter $router */
                $router = $this->resolve(\Waaseyaa\Routing\WaaseyaaRouter::class);

                return new RouteListHandler(router: $router)->execute($io);
            },
        );

        yield new HandlerCommand(
            name: 'serve',
            description: 'Start the PHP development server',
            options: [
                new HandlerOption(
                    name: 'host',
                    mode: HandlerOptionMode::Optional,
                    description: 'Specify which IP address the server should listen on. Set to 127.0.0.1 to restrict to localhost only. Can also be set via APP_HOST.',
                    default: (getenv('APP_HOST') !== false ? getenv('APP_HOST') : '0.0.0.0'),
                ),
                new HandlerOption(
                    name: 'port',
                    shortcut: 'p',
                    mode: HandlerOptionMode::Optional,
                    description: 'Specify which port the server should listen on. Can also be set via APP_PORT.',
                    default: (getenv('APP_PORT') !== false ? getenv('APP_PORT') : '8080'),
                ),
                new HandlerOption(
                    name: 'frankenphp',
                    mode: HandlerOptionMode::None,
                    description: 'Serve with FrankenPHP (concurrent worker runtime) instead of php -S. Uses config/frankenphp/php.ini so the SQLite default boots out of the box. Requires the frankenphp binary on PATH.',
                ),
            ],
            handler: \Closure::fromCallable([new ServeHandler(projectRoot: $projectRoot), 'execute']),
        );

        $rulesSourceDir = $projectRoot . '/vendor/waaseyaa/framework/.claude/rules';
        $rulesTargetDir = $projectRoot . '/.claude/rules';

        yield new HandlerCommand(
            name: 'sync-rules',
            description: 'Sync framework rules from Waaseyaa to this app',
            options: [
                new HandlerOption(
                    name: 'force',
                    shortcut: 'f',
                    mode: HandlerOptionMode::None,
                    description: 'Overwrite changed files without confirmation',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Show what would change without writing',
                ),
            ],
            handler: \Closure::fromCallable([new SyncRulesHandler(
                sourceDir: $rulesSourceDir,
                targetDir: $rulesTargetDir,
            ), 'execute']),
        );

        yield new HandlerCommand(
            name: 'waaseyaa:version',
            description: 'Print waaseyaa/* framework provenance (path SHA, lockfile versions, drift vs golden SHA)',
            options: [
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Machine-readable JSON',
                ),
                new HandlerOption(
                    name: 'strict',
                    mode: HandlerOptionMode::None,
                    description: 'Fail on drift when golden SHA is set (same as default; omit --report-only)',
                ),
                new HandlerOption(
                    name: 'report-only',
                    mode: HandlerOptionMode::None,
                    description: 'Print drift but always exit 0',
                ),
            ],
            handler: \Closure::fromCallable([new WaaseyaaVersionHandler(projectRoot: $projectRoot), 'execute']),
        );
    }
}
