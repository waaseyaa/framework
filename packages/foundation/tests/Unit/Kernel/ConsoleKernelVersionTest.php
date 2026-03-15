<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ConsoleKernelVersionTest extends TestCase
{
    #[Test]
    public function version_is_not_hardcoded_zero_dot_one(): void
    {
        $pkg = InstalledVersions::getRootPackage();
        $version = $pkg['pretty_version'] ?? $pkg['version'] ?? 'unknown';
        $this->assertNotSame('0.1.0', $version,
            'Version must not be the old hardcoded placeholder.');
    }
}
