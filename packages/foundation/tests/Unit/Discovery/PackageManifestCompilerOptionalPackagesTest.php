<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability\Fixture\OptionalPackageAbsentProvider;
use Waaseyaa\Foundation\Tests\Unit\ServiceProvider\Capability\Fixture\OptionalPackagePresentProvider;

/**
 * Discovery must not advertise console-command providers whose optional
 * package is absent; the roster and the console runtime read one gate (#2826).
 */
#[CoversClass(PackageManifestCompiler::class)]
final class PackageManifestCompilerOptionalPackagesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
        mkdir($this->tempDir . '/vendor/composer', 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function console_command_roster_omits_providers_with_unsatisfied_optional_packages(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/fixture-cli',
                    'extra' => ['waaseyaa' => ['providers' => [
                        OptionalPackageAbsentProvider::class,
                        OptionalPackagePresentProvider::class,
                    ]]],
                ],
            ],
        ];
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertContains(OptionalPackageAbsentProvider::class, $manifest->providers, 'The provider itself is still discovered and registered.');
        self::assertContains(OptionalPackagePresentProvider::class, $manifest->providers);
        self::assertSame(
            [OptionalPackagePresentProvider::class],
            array_values(array_filter(
                $manifest->consoleCommandProviders,
                static fn(string $class): bool => str_starts_with($class, 'Waaseyaa\\Foundation\\Tests\\'),
            )),
            'Only the provider whose optional package is present is advertised as a console-command provider.',
        );
    }
}
