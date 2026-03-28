<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Twig\Environment;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\SSR\Flash\Flash;
use Waaseyaa\SSR\Flash\FlashMessageService;
use Waaseyaa\SSR\Twig\FlashTwigExtension;

final class SsrServiceProvider extends ServiceProvider
{
    private static ?Environment $twigEnvironment = null;
    private static ?FieldFormatterRegistry $formatterRegistry = null;

    public function register(): void
    {
        // SSR currently wires Twig directly in boot(); no container binding needed yet.
    }

    public function boot(): void
    {
        if ($this->projectRoot === '') {
            return;
        }

        self::$twigEnvironment = ThemeServiceProvider::getTwigEnvironment()
            ?? self::createTwigEnvironment($this->projectRoot, $this->config);
        self::$formatterRegistry = new FieldFormatterRegistry($this->manifestFormatters);

        $flashService = new FlashMessageService();
        Flash::setService($flashService);
        if (self::$twigEnvironment !== null) {
            self::$twigEnvironment->addExtension(new FlashTwigExtension($flashService));
        }
    }

    public static function getTwigEnvironment(): ?Environment
    {
        return self::$twigEnvironment;
    }

    public static function getFormatterRegistry(): ?FieldFormatterRegistry
    {
        return self::$formatterRegistry;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createTwigEnvironment(string $projectRoot, array $config = []): Environment
    {
        return ThemeServiceProvider::createTwigEnvironment($projectRoot, $config);
    }
}
