<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SiteDoctorHandler;
use Waaseyaa\CLI\Handler\SiteInitHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class SiteServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function __construct(?string $projectRoot = null)
    {
        if ($projectRoot !== null) {
            $this->projectRoot = $projectRoot;
        }
    }

    public function register(): void
    {
        $this->singleton(SiteInitHandler::class, fn(): SiteInitHandler => new SiteInitHandler(
            $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd(),
        ));
        $this->singleton(SiteDoctorHandler::class, fn(): SiteDoctorHandler => new SiteDoctorHandler(
            $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd(),
        ));
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'site:init',
            description: 'Initialize or deterministically regenerate the governed site contract',
            options: [
                new HandlerOption('answers', mode: HandlerOptionMode::Required, description: 'Complete YAML answer document for automation'),
                new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'Application project root'),
                new HandlerOption('dry-run', mode: HandlerOptionMode::None, description: 'Inspect and report the complete change set without writing'),
                new HandlerOption('yes', shortcut: 'y', mode: HandlerOptionMode::None, description: 'Publish the reviewed transaction non-interactively'),
            ],
            handler: [SiteInitHandler::class, 'execute'],
        );
        yield new HandlerCommand(
            name: 'site:doctor',
            description: 'Verify the governed site contract and architecture boundaries',
            options: [
                new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'Application project root'),
                new HandlerOption('strict', mode: HandlerOptionMode::None, description: 'Fail on every warning or error'),
                new HandlerOption('format', mode: HandlerOptionMode::Required, description: 'Output format: text or json'),
            ],
            handler: [SiteDoctorHandler::class, 'execute'],
        );
    }
}
