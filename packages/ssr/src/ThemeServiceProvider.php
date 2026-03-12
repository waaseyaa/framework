<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\SSR\Twig\WaaseyaaExtension;

final class ThemeServiceProvider extends ServiceProvider
{
    private static ?Environment $twigEnvironment = null;

    public function register(): void
    {
        // SSR/Twig wiring happens in boot().
    }

    public function boot(): void
    {
        if ($this->projectRoot === '') {
            return;
        }

        self::$twigEnvironment = self::createTwigEnvironment($this->projectRoot, $this->config);
    }

    public static function getTwigEnvironment(): ?Environment
    {
        return self::$twigEnvironment;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createTwigEnvironment(string $projectRoot, array $config = []): Environment
    {
        $activeTheme = '';
        if (is_array($config['ssr'] ?? null) && is_string($config['ssr']['theme'] ?? null)) {
            $activeTheme = trim((string) $config['ssr']['theme']);
        }

        $loader = self::createTemplateChainLoader($projectRoot, $activeTheme);

        $env = new Environment($loader, [
            'cache' => false,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        if (class_exists(\Waaseyaa\User\Middleware\CsrfMiddleware::class)) {
            $env->addFunction(new TwigFunction(
                'csrf_token',
                [\Waaseyaa\User\Middleware\CsrfMiddleware::class, 'token'],
                ['is_safe' => ['html']],
            ));
        }

        $configFactory = null;
        if (interface_exists(ConfigFactoryInterface::class)) {
            // ConfigFactory will be available at runtime when the config package is loaded.
            // For static factory usage (tests), configFactory stays null and config() returns ''.
        }

        $env->addExtension(new WaaseyaaExtension(
            assetBasePath: (string) ($config['ssr']['asset_base_path'] ?? ''),
            configFactory: $configFactory,
            envWhitelist: (array) ($config['ssr']['env_whitelist'] ?? []),
        ));

        return $env;
    }

    public static function createTemplateChainLoader(string $projectRoot, string $activeTheme = ''): ChainLoader
    {
        $chain = new ChainLoader();
        $root = rtrim($projectRoot, '/');

        // 1) App templates (highest priority)
        self::addPathLoaderIfExists($chain, $root . '/templates');

        // 2) Active theme templates
        $themes = self::discoverThemes($projectRoot);
        if ($activeTheme !== '' && isset($themes[$activeTheme])) {
            foreach ($themes[$activeTheme]->templateDirectories() as $dir) {
                self::addPathLoaderIfExists($chain, $dir);
            }
        }

        // 3) Package templates
        $packageTemplateDirs = glob($root . '/packages/*/templates');
        if ($packageTemplateDirs !== false) {
            foreach ($packageTemplateDirs as $dir) {
                if ($dir === $root . '/packages/ssr/templates') {
                    continue;
                }
                self::addPathLoaderIfExists($chain, $dir);
            }
        }

        // 4) Base SSR theme templates (lowest priority)
        self::addPathLoaderIfExists($chain, $root . '/packages/ssr/templates');

        return $chain;
    }

    /**
     * Discover theme packages from composer installed metadata.
     *
     * @return array<string, ThemeInterface>
     */
    public static function discoverThemes(string $projectRoot): array
    {
        $installedPath = rtrim($projectRoot, '/') . '/vendor/composer/installed.json';
        if (!is_file($installedPath)) {
            return [];
        }

        try {
            $installed = json_decode((string) file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        $packages = $installed['packages'] ?? $installed;
        if (!is_array($packages)) {
            return [];
        }

        $themes = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $themeMeta = $package['extra']['waaseyaa']['theme'] ?? null;
            if ($themeMeta === null) {
                continue;
            }

            $packageName = is_string($package['name'] ?? null) ? $package['name'] : '';
            if ($packageName === '') {
                continue;
            }

            $themeId = self::extractThemeId($themeMeta, $packageName);
            $dirs = self::extractThemeTemplateDirs($projectRoot, $packageName, $themeMeta);
            if ($themeId === '' || $dirs === []) {
                continue;
            }

            $themes[$themeId] = new Theme($themeId, $dirs);
        }

        return $themes;
    }

    private static function addPathLoaderIfExists(ChainLoader $chain, string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $chain->addLoader(new FilesystemLoader($path));
    }

    private static function extractThemeId(mixed $themeMeta, string $packageName): string
    {
        if (is_string($themeMeta) && $themeMeta !== '') {
            return $themeMeta;
        }

        if (is_array($themeMeta) && is_string($themeMeta['id'] ?? null) && $themeMeta['id'] !== '') {
            return $themeMeta['id'];
        }

        $nameParts = explode('/', $packageName);
        return (string) end($nameParts);
    }

    /**
     * @return list<string>
     */
    private static function extractThemeTemplateDirs(string $projectRoot, string $packageName, mixed $themeMeta): array
    {
        $root = rtrim($projectRoot, '/');
        $paths = [];
        $packagePath = self::resolvePackagePath($projectRoot, $packageName);
        if ($packagePath === null) {
            return [];
        }

        if (is_array($themeMeta) && is_string($themeMeta['templates'] ?? null) && $themeMeta['templates'] !== '') {
            $paths[] = rtrim($packagePath, '/') . '/' . ltrim($themeMeta['templates'], '/');
        } else {
            $paths[] = rtrim($packagePath, '/') . '/templates';
        }

        // Legacy monorepo fallback when package path resolution fails for path repos.
        $nameParts = explode('/', $packageName);
        if (count($nameParts) === 2) {
            $paths[] = $root . '/packages/' . $nameParts[1] . '/templates';
        }

        return array_values(array_unique(array_filter(
            $paths,
            static fn(string $p): bool => is_dir($p),
        )));
    }

    private static function resolvePackagePath(string $projectRoot, string $packageName): ?string
    {
        $root = rtrim($projectRoot, '/');
        $installedPath = $root . '/vendor/composer/installed.json';
        if (!is_file($installedPath)) {
            return null;
        }

        try {
            $installed = json_decode((string) file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $packages = $installed['packages'] ?? $installed;
        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            if (($package['name'] ?? null) !== $packageName) {
                continue;
            }
            $installPath = is_string($package['install-path'] ?? null) ? $package['install-path'] : '';
            if ($installPath === '') {
                continue;
            }

            if (str_starts_with($installPath, '../')) {
                return realpath($root . '/vendor/' . $installPath) ?: null;
            }

            return realpath($root . '/' . ltrim($installPath, '/')) ?: null;
        }

        return null;
    }
}
