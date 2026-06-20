<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit\Binary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\FrankenPhp\Binary\AssetSelector;
use Waaseyaa\FrankenPhp\Binary\FrankenPhpAsset;

#[CoversClass(AssetSelector::class)]
#[CoversClass(FrankenPhpAsset::class)]
final class AssetSelectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool, string}>
     */
    public static function platforms(): iterable
    {
        // [osFamily, machine, expectedAsset, isArchive, binaryName]
        yield 'linux x86_64' => ['Linux', 'x86_64', 'frankenphp-linux-x86_64', false, 'frankenphp'];
        yield 'linux aarch64' => ['Linux', 'aarch64', 'frankenphp-linux-aarch64', false, 'frankenphp'];
        yield 'linux arm64 alias' => ['Linux', 'arm64', 'frankenphp-linux-aarch64', false, 'frankenphp'];
        yield 'mac arm64' => ['Darwin', 'arm64', 'frankenphp-mac-arm64', false, 'frankenphp'];
        yield 'mac aarch64 alias' => ['Darwin', 'aarch64', 'frankenphp-mac-arm64', false, 'frankenphp'];
        yield 'mac x86_64' => ['Darwin', 'x86_64', 'frankenphp-mac-x86_64', false, 'frankenphp'];
        yield 'windows AMD64' => ['Windows', 'AMD64', 'frankenphp-windows-x86_64.zip', true, 'frankenphp.exe'];
        yield 'windows x86_64' => ['Windows', 'x86_64', 'frankenphp-windows-x86_64.zip', true, 'frankenphp.exe'];
    }

    #[Test]
    #[DataProvider('platforms')]
    public function it_selects_the_correct_asset_per_os_and_arch(
        string $osFamily,
        string $machine,
        string $expectedAsset,
        bool $isArchive,
        string $binaryName,
    ): void {
        $asset = new AssetSelector()->select($osFamily, $machine);

        self::assertSame($expectedAsset, $asset->assetName);
        self::assertSame($isArchive, $asset->isArchive);
        self::assertSame($binaryName, $asset->binaryName);
    }

    #[Test]
    public function it_builds_the_pinned_versioned_download_and_api_urls(): void
    {
        $asset = new AssetSelector()->select('Linux', 'x86_64');

        self::assertSame(AssetSelector::DEFAULT_VERSION, $asset->version);
        self::assertSame(
            'https://github.com/php/frankenphp/releases/download/' . AssetSelector::DEFAULT_VERSION . '/frankenphp-linux-x86_64',
            $asset->url,
        );
        self::assertSame(
            'https://api.github.com/repos/php/frankenphp/releases/tags/' . AssetSelector::DEFAULT_VERSION,
            $asset->apiUrl,
        );
    }

    #[Test]
    public function it_normalizes_a_version_without_the_v_prefix(): void
    {
        $asset = new AssetSelector()->select('Linux', 'x86_64', '1.12.0');

        self::assertSame('v1.12.0', $asset->version);
        self::assertStringContainsString('/releases/download/v1.12.0/', $asset->url);
    }

    #[Test]
    public function windows_arm64_is_rejected_because_no_such_asset_is_published(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Windows/');

        new AssetSelector()->select('Windows', 'ARM64');
    }

    #[Test]
    public function an_unsupported_arch_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/architecture/i');

        new AssetSelector()->select('Linux', 'riscv64');
    }

    #[Test]
    public function an_unsupported_os_family_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OS family/i');

        new AssetSelector()->select('BSD', 'x86_64');
    }
}
