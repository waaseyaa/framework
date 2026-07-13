<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\SSR\ThemeServiceProvider;

#[CoversClass(SsrServiceProvider::class)]
final class SsrServiceProviderTest extends TestCase
{
    #[Test]
    public function createTwigEnvironmentDiscoversAppAndPackageTemplates(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_ssr_provider_' . uniqid();
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/packages/demo/templates', 0755, true);

        file_put_contents($projectRoot . '/templates/page.html.twig', '<h1>App {{ path }}</h1>');
        file_put_contents($projectRoot . '/packages/demo/templates/demo.html.twig', '<p>Package template</p>');

        $twig = SsrServiceProvider::createTwigEnvironment($projectRoot);

        $this->assertSame('<h1>App /</h1>', $twig->render('page.html.twig', ['path' => '/']));
        $this->assertSame('<p>Package template</p>', $twig->render('demo.html.twig'));

        $this->removeDirectory($projectRoot);
    }

    #[Test]
    public function setTwigEnvironmentWiresAndResetsTheRenderEnvironment(): void
    {
        // Regression for #1604: createTwigEnvironment() is a pure factory; the
        // render path reads the static via getTwigEnvironment(). setTwigEnvironment()
        // wires that static for tests, and null resets it for isolation.
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_ssr_provider_' . uniqid();
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/templates/page.html.twig', '<h1>{{ path }}</h1>');

        $env = SsrServiceProvider::createTwigEnvironment($projectRoot);
        SsrServiceProvider::setTwigEnvironment($env);
        $this->assertSame($env, SsrServiceProvider::getTwigEnvironment());

        SsrServiceProvider::setTwigEnvironment(null);
        $this->assertNull(SsrServiceProvider::getTwigEnvironment());

        $this->removeDirectory($projectRoot);
    }

    #[Test]
    public function bootInitializesSharedTwigEnvironment(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_ssr_boot_' . uniqid();
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/templates/page.html.twig', '<h1>Booted</h1>');

        $themeProvider = new ThemeServiceProvider();
        $themeProvider->setKernelContext($projectRoot, [], []);
        $themeProvider->boot();
        $provider = new SsrServiceProvider();
        $provider->setKernelContext($projectRoot, [], ['string' => \Waaseyaa\SSR\Formatter\PlainTextFormatter::class]);
        $provider->register();
        $provider->boot();

        $twig = SsrServiceProvider::getTwigEnvironment();
        $this->assertNotNull($twig);
        $this->assertSame('<h1>Booted</h1>', $twig->render('page.html.twig'));
        $registry = SsrServiceProvider::getFormatterRegistry();
        $this->assertNotNull($registry);
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $registry->format('string', '<b>x</b>'));

        $this->removeDirectory($projectRoot);
    }

    #[Test]
    public function consecutiveRequestBootsReplaceBothStaticTwigEnvironments(): void
    {
        $firstRoot = sys_get_temp_dir() . '/waaseyaa_ssr_worker_first_' . uniqid();
        $secondRoot = sys_get_temp_dir() . '/waaseyaa_ssr_worker_second_' . uniqid();
        mkdir($firstRoot . '/templates', 0755, true);
        mkdir($secondRoot . '/templates', 0755, true);
        file_put_contents($firstRoot . '/templates/page.html.twig', 'first request');
        file_put_contents($secondRoot . '/templates/page.html.twig', 'second request');

        $firstThemeProvider = new ThemeServiceProvider();
        $firstThemeProvider->setKernelContext($firstRoot, [], []);
        $firstThemeProvider->boot();
        $firstSsrProvider = new SsrServiceProvider();
        $firstSsrProvider->setKernelContext($firstRoot, [], []);
        $firstSsrProvider->boot();
        $firstThemeEnvironment = ThemeServiceProvider::getTwigEnvironment();
        $firstSsrEnvironment = SsrServiceProvider::getTwigEnvironment();

        $secondThemeProvider = new ThemeServiceProvider();
        $secondThemeProvider->setKernelContext($secondRoot, [], []);
        $secondThemeProvider->boot();
        $secondSsrProvider = new SsrServiceProvider();
        $secondSsrProvider->setKernelContext($secondRoot, [], []);
        $secondSsrProvider->boot();
        $secondThemeEnvironment = ThemeServiceProvider::getTwigEnvironment();
        $secondSsrEnvironment = SsrServiceProvider::getTwigEnvironment();

        $this->assertNotNull($firstThemeEnvironment);
        $this->assertNotNull($firstSsrEnvironment);
        $this->assertNotNull($secondThemeEnvironment);
        $this->assertNotNull($secondSsrEnvironment);
        $this->assertNotSame($firstThemeEnvironment, $secondThemeEnvironment);
        $this->assertNotSame($firstSsrEnvironment, $secondSsrEnvironment);
        $this->assertSame($secondThemeEnvironment, $secondSsrEnvironment);
        $this->assertSame('second request', $secondSsrEnvironment->render('page.html.twig'));

        $this->removeDirectory($firstRoot);
        $this->removeDirectory($secondRoot);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
