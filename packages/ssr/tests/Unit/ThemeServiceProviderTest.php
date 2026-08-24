<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\SSR\Theme;
use Waaseyaa\SSR\ThemeServiceProvider;

#[CoversClass(Theme::class)]
#[CoversClass(ThemeServiceProvider::class)]
final class ThemeServiceProviderTest extends TestCase
{
    #[Test]
    public function discoverThemesReadsComposerMetadata(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_theme_discovery_' . uniqid();
        mkdir($projectRoot . '/vendor/composer', 0755, true);
        mkdir($projectRoot . '/vendor/acme/nebula/templates', 0755, true);
        mkdir($projectRoot . '/vendor/acme/minimal/views', 0755, true);

        file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode([
            'packages' => [
                [
                    'name' => 'acme/nebula',
                    'install-path' => 'vendor/acme/nebula',
                    'extra' => ['waaseyaa' => ['theme' => 'nebula']],
                ],
                [
                    'name' => 'acme/minimal',
                    'install-path' => 'vendor/acme/minimal',
                    'extra' => ['waaseyaa' => ['theme' => [
                        'id' => 'minimal',
                        'templates' => 'views',
                    ]]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $themes = ThemeServiceProvider::discoverThemes($projectRoot);

        $this->assertArrayHasKey('nebula', $themes);
        $this->assertArrayHasKey('minimal', $themes);
        $normalizedRoot = str_replace('\\', '/', $projectRoot);
        $this->assertSame([$normalizedRoot . '/vendor/acme/nebula/templates'], $themes['nebula']->templateDirectories());
        $this->assertSame([$normalizedRoot . '/vendor/acme/minimal/views'], $themes['minimal']->templateDirectories());

        (new Filesystem())->remove($projectRoot);
    }

    #[Test]
    public function createTwigEnvironmentUsesConfiguredThemeBeforePackageAndBaseTemplates(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_theme_loader_' . uniqid();
        mkdir($projectRoot . '/vendor/composer', 0755, true);
        mkdir($projectRoot . '/vendor/acme/nebula/templates', 0755, true);
        mkdir($projectRoot . '/packages/demo/templates', 0755, true);
        mkdir($projectRoot . '/packages/ssr/templates', 0755, true);

        file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'acme/nebula',
                'install-path' => 'vendor/acme/nebula',
                'extra' => ['waaseyaa' => ['theme' => 'nebula']],
            ]],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/vendor/acme/nebula/templates/page.html.twig', 'theme');
        file_put_contents($projectRoot . '/packages/demo/templates/page.html.twig', 'package');
        file_put_contents($projectRoot . '/packages/ssr/templates/page.html.twig', 'base');

        $twig = ThemeServiceProvider::createTwigEnvironment($projectRoot, ['ssr' => ['theme' => 'nebula']]);

        $this->assertSame('theme', $twig->render('page.html.twig'));

        (new Filesystem())->remove($projectRoot);
    }

    #[Test]
    public function appTemplatesOverrideActiveThemeTemplates(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_theme_app_override_' . uniqid();
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/vendor/composer', 0755, true);
        mkdir($projectRoot . '/vendor/acme/nebula/templates', 0755, true);
        mkdir($projectRoot . '/packages/ssr/templates', 0755, true);

        file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'acme/nebula',
                'install-path' => 'vendor/acme/nebula',
                'extra' => ['waaseyaa' => ['theme' => 'nebula']],
            ]],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/templates/page.html.twig', 'app');
        file_put_contents($projectRoot . '/vendor/acme/nebula/templates/page.html.twig', 'theme');
        file_put_contents($projectRoot . '/packages/ssr/templates/page.html.twig', 'base');

        $twig = ThemeServiceProvider::createTwigEnvironment($projectRoot, ['ssr' => ['theme' => 'nebula']]);

        $this->assertSame('app', $twig->render('page.html.twig'));

        (new Filesystem())->remove($projectRoot);
    }

    #[Test]
    public function twigCacheIsDisabledByDefault(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_twig_cache_default_' . uniqid();
        mkdir($projectRoot . '/packages/ssr/templates', 0755, true);

        $twig = ThemeServiceProvider::createTwigEnvironment($projectRoot);

        $this->assertFalse($twig->getCache(true), 'Cache must be false when no cache_dir is configured.');

        (new Filesystem())->remove($projectRoot);
    }

    #[Test]
    public function twigCacheUsesConfiguredCacheDir(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_twig_cache_dir_' . uniqid();
        $cacheDir = sys_get_temp_dir() . '/waaseyaa_twig_cache_' . uniqid();
        mkdir($projectRoot . '/packages/ssr/templates', 0755, true);

        $twig = ThemeServiceProvider::createTwigEnvironment($projectRoot, ['ssr' => ['cache_dir' => $cacheDir]]);
        $cache = $twig->getCache(true);

        $this->assertNotFalse($cache, 'Cache must be enabled when cache_dir is configured.');
        $this->assertStringContainsString($cacheDir, (string) $cache);

        (new Filesystem())->remove($projectRoot);
    }

}
