<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\SsrServiceProvider;

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
    public function bootInitializesSharedTwigEnvironment(): void
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_ssr_boot_' . uniqid();
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/templates/page.html.twig', '<h1>Booted</h1>');

        $provider = new SsrServiceProvider();
        $provider->setKernelContext($projectRoot, []);
        $provider->register();
        $provider->boot();

        $twig = SsrServiceProvider::getTwigEnvironment();
        $this->assertNotNull($twig);
        $this->assertSame('<h1>Booted</h1>', $twig->render('page.html.twig'));

        $this->removeDirectory($projectRoot);
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
