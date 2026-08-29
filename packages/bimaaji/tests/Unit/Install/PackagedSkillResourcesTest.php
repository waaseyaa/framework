<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\PackagedSkillResources;

#[CoversClass(PackagedSkillResources::class)]
final class PackagedSkillResourcesTest extends TestCase
{
    #[Test]
    public function resolvesTheSkillDirectoryRelativeToTheInstalledPackage(): void
    {
        // Anchored on the running class file, never on a project root or the
        // process cwd — that is what lets one path work both in this checkout
        // and in a consumer's vendor/waaseyaa/bimaaji (#2656).
        self::assertSame(
            \dirname(__DIR__, 3) . '/resources/skills',
            PackagedSkillResources::directory(),
        );
    }

    #[Test]
    public function theResolvedDirectoryIsStableAcrossTheProcessWorkingDirectory(): void
    {
        $before = PackagedSkillResources::directory();
        $original = getcwd();
        self::assertIsString($original);

        try {
            chdir(sys_get_temp_dir());
            self::assertSame($before, PackagedSkillResources::directory());
        } finally {
            chdir($original);
        }
    }
}
