<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\SSR\ThemeServiceProvider;

final class ThemeServiceProviderVendorTemplatesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_ssr_vendor_tpl_' . uniqid();
        mkdir($this->root . '/vendor/composer', 0o755, true);
    }

    protected function tearDown(): void
    {
(new Filesystem())->remove($this->root);
    }

    #[Test]
    public function vendor_package_templates_directory_is_on_chain_loader(): void
    {
        $relativePkg = 'vendor/waaseyaa/demo-pkg';
        mkdir($this->root . '/' . $relativePkg . '/templates', 0o755, true);
        file_put_contents(
            $this->root . '/' . $relativePkg . '/templates/vendortest.html.twig',
            'vendor-ok',
        );

        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/demo-pkg',
                    'install-path' => '../waaseyaa/demo-pkg',
                ],
            ],
        ];
        file_put_contents(
            $this->root . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $chain = ThemeServiceProvider::createTemplateChainLoader($this->root, '');

        $this->assertInstanceOf(ChainLoader::class, $chain);
        $expected = realpath($this->root . '/' . $relativePkg . '/templates');
        $this->assertNotFalse($expected);

        $found = false;
        foreach ($chain->getLoaders() as $loader) {
            if (!$loader instanceof FilesystemLoader) {
                continue;
            }
            foreach ($loader->getPaths() as $path) {
                $resolved = realpath($path);
                if ($resolved !== false && $resolved === $expected) {
                    $found = true;
                    break 2;
                }
            }
        }

        $this->assertTrue($found, 'Composer vendor package templates/ must be discoverable on the Twig chain');
    }
}
