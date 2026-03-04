<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class SsrServiceProvider extends ServiceProvider
{
    private static ?Environment $twigEnvironment = null;

    public function register(): void
    {
        // SSR currently wires Twig directly in boot(); no container binding needed yet.
    }

    public function boot(): void
    {
        if ($this->projectRoot === '') {
            return;
        }

        self::$twigEnvironment = self::createTwigEnvironment($this->projectRoot);
    }

    public static function getTwigEnvironment(): ?Environment
    {
        return self::$twigEnvironment;
    }

    public static function createTwigEnvironment(string $projectRoot): Environment
    {
        $loader = new FilesystemLoader();

        // App-level templates override package templates.
        $appTemplates = rtrim($projectRoot, '/') . '/templates';
        if (is_dir($appTemplates)) {
            $loader->addPath($appTemplates);
        }

        $packageTemplateDirs = glob(rtrim($projectRoot, '/') . '/packages/*/templates');
        if ($packageTemplateDirs !== false) {
            foreach ($packageTemplateDirs as $dir) {
                if (is_dir($dir)) {
                    $loader->addPath($dir);
                }
            }
        }

        return new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);
    }
}
