<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\ManifestCompilationContext;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

/** Native Windows and POSIX both keep the default non-selected compiler contract. */
final class ManifestCacheLegacyPortabilityTest extends TestCase
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/manifest-legacy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/vendor/composer', 0o700, true);
        mkdir($this->root . '/.waaseyaa', 0o700);
        file_put_contents($this->root . '/composer.json', '{"name":"local/legacy-portability"}');
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages":[]}');
    }
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }
    public function testLegacyDefaultCompileAndCacheRemainNativePortableWithoutLinks(): void
    {
        $compiler = new PackageManifestCompiler($this->root, $this->root . '/storage');
        $compiler->load();
        $path = $this->root . '/storage/framework/packages.php';
        self::assertSame($path, $compiler->selectedManifestPath());
        self::assertSame('legacy', $compiler->manifestCacheWitness()['context']);
        self::assertSame(hash_file('sha256', $path), $compiler->manifestCacheWitness()['manifest_sha256']);
        $before = file_get_contents($path);
        new PackageManifestCompiler($this->root, $this->root . '/storage')->load();
        self::assertSame($before, file_get_contents($path));
    }
    public function testPlatformCapabilityAndUnsupportedSelectionAreExplicit(): void
    {
        self::assertSame(PHP_OS_FAMILY !== 'Windows', ManifestCompilationContext::artifactBindingSupported());
        if (ManifestCompilationContext::artifactBindingSupported()) {
            return;
        }
        file_put_contents($this->root . '/.waaseyaa/manifest-cache-layout', "artifact-bound-v1\n");
        try {
            new PackageManifestCompiler($this->root, $this->root . '/storage');
            self::fail('Unsupported selected mode accepted.');
        } catch (\RuntimeException $error) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
            self::assertStringContainsString('POSIX filesystem host', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->root . '/storage');
        }
    }
}
