<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Baselines;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LicenseFileTest extends TestCase
{
    #[Test]
    public function licenseFileExistsAtRepoRoot(): void
    {
        $licensePath = __DIR__ . '/../../LICENSE';
        $this->assertFileExists($licensePath, 'LICENSE file must exist at repository root');
    }

    #[Test]
    public function licenseFileContainsGplText(): void
    {
        $content = file_get_contents(__DIR__ . '/../../LICENSE');
        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $content);
        $this->assertStringContainsString('Version 2', $content);
    }

    #[Test]
    public function composerJsonLicenseMatchesLicenseFile(): void
    {
        $composerJson = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('GPL-2.0-or-later', $composerJson['license']);
    }
}
