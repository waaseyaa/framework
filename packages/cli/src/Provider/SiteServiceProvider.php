<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SiteApplyHandler;
use Waaseyaa\CLI\Handler\SiteDoctorHandler;
use Waaseyaa\CLI\Handler\SiteInitHandler;
use Waaseyaa\CLI\Site\DevelopmentInterruptionSeam;
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
        $this->singleton(SiteApplyHandler::class, fn(): SiteApplyHandler => new SiteApplyHandler(
            $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd(),
        ));
    }

    public function consoleCommands(): iterable
    {
        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        yield self::siteInitCommand($projectRoot);
        yield self::siteDoctorCommand($projectRoot);
        yield self::siteApplyCommand($projectRoot);
    }

    /**
     * The single definition of `site:init`, shared by ordinary provider
     * discovery and by `ConsoleKernel`'s boot-free command seam.
     *
     * `SiteInitHandler` needs only a project root, and
     * `SiteArtifactRendererFactory::create()` composes its three recipes with
     * `new` and no container, so nothing here requires a booted framework.
     * Routing it through restricted boot still reached
     * `AbstractKernel::bootDatabase()`, so initializing a site contract created
     * an empty database before any bootstrap command had run — the phantom
     * file `db:init` then had to be taught to adopt (#2644).
     */
    public static function siteInitCommand(string $projectRoot): HandlerCommand
    {
        $handler = new SiteInitHandler($projectRoot);

        return new HandlerCommand(
            name: 'site:init',
            description: 'Initialize or deterministically regenerate the governed site contract',
            options: [
                new HandlerOption('answers', mode: HandlerOptionMode::Required, description: 'Complete YAML answer document for automation (a preset seed document, when --preset is also given)'),
                new HandlerOption('decision-receipt', mode: HandlerOptionMode::Required, description: 'JSON decision receipt approving the exact blueprint and site manifest'),
                new HandlerOption('preset', mode: HandlerOptionMode::Required, description: "Init-time preset resolving deterministically to a manifest: 'minimal' or 'editorial'. Not persisted; see docs/specs/site-golden-path.md"),
                new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'Application project root'),
                new HandlerOption('dry-run', mode: HandlerOptionMode::None, description: 'Inspect and report the complete change set without writing'),
                new HandlerOption('json', mode: HandlerOptionMode::None, description: 'Emit the evaluated plan, apply result and change receipts as JSON'),
                new HandlerOption('yes', shortcut: 'y', mode: HandlerOptionMode::None, description: 'Publish the reviewed transaction non-interactively'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    /**
     * The single definition of `site:doctor`, shared by ordinary provider
     * discovery and by `ConsoleKernel`'s boot-free command seam.
     *
     * `SiteDoctorHandler` needs only a project root, and `SiteDoctorService`
     * reads nothing but the filesystem, so the command is constructible without
     * a container. That is what lets the kernel run it without booting: a full
     * boot reaches `AbstractKernel::bootDatabase()` before every
     * restricted-discovery guard, and would create the very database this
     * read-only diagnostic exists to report on (#2644).
     */
    /**
     * The single definition of `site:apply`, shared by ordinary provider
     * discovery and by `ConsoleKernel`'s boot-free command seam.
     *
     * This is ADR-025 D-6.5's second process (#2789): it executes a reviewed
     * apply request that was emitted earlier, elsewhere, and recompiles
     * nothing. `SiteApplyHandler` needs only a project root — it constructs no
     * renderer, wizard or compiler at all — so like its two siblings it must
     * not be routed through restricted boot, which would open the database the
     * site-contract phase exists to precede (#2644).
     */
    public static function siteApplyCommand(string $projectRoot): HandlerCommand
    {
        $handler = new SiteApplyHandler($projectRoot);
        $options = [
            new HandlerOption('request', mode: HandlerOptionMode::Required, description: 'Canonical waaseyaa.artifact_apply_request JSON document carrying the reviewed plan and its two digests'),
            new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'Application project root'),
            new HandlerOption('json', mode: HandlerOptionMode::None, description: 'Emit the artifact result and change receipts as JSON'),
        ];
        // #2789 phase 3: the crash-recovery seam is not merely refused outside
        // an explicit development environment — it does not exist there, so the
        // option is unknown and the command refuses it as a usage error.
        if (DevelopmentInterruptionSeam::isPermitted()) {
            $options[] = new HandlerOption(
                DevelopmentInterruptionSeam::OPTION,
                mode: HandlerOptionMode::None,
                description: 'Development only: abandon the publication once its transaction journal is durable, so a later apply can prove recovery',
            );
        }

        return new HandlerCommand(
            name: 'site:apply',
            description: 'Publish a reviewed artifact apply request exactly as emitted, without recompiling it',
            options: $options,
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    public static function siteDoctorCommand(string $projectRoot): HandlerCommand
    {
        $handler = new SiteDoctorHandler($projectRoot);

        return new HandlerCommand(
            name: 'site:doctor',
            description: 'Verify the governed site contract and architecture boundaries',
            options: [
                new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'Application project root'),
                new HandlerOption('strict', mode: HandlerOptionMode::None, description: 'Fail on every warning or error'),
                new HandlerOption('format', mode: HandlerOptionMode::Required, description: 'Output format: text or json'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }
}
