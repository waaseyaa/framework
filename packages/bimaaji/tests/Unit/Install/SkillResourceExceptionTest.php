<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\SkillResourceException;
use Waaseyaa\Bimaaji\Install\SkillResourceFailure;

#[CoversClass(SkillResourceException::class)]
final class SkillResourceExceptionTest extends TestCase
{
    #[Test]
    public function aMissingPackagedDirectoryNamesThePathAndTheReinstallRemedy(): void
    {
        $exception = SkillResourceException::missingDirectory('/vendor/waaseyaa/bimaaji/resources/skills', false);

        self::assertSame(SkillResourceFailure::Missing, $exception->failure);
        self::assertSame('/vendor/waaseyaa/bimaaji/resources/skills', $exception->directory);
        self::assertNull($exception->skillFile);
        self::assertStringContainsString('/vendor/waaseyaa/bimaaji/resources/skills', $exception->getMessage());
        self::assertStringContainsString('does not exist', $exception->getMessage());
        self::assertStringContainsString('composer reinstall waaseyaa/bimaaji', $exception->getMessage());
    }

    #[Test]
    public function aMissingConfiguredDirectoryNamesTheOverrideInstead(): void
    {
        $exception = SkillResourceException::missingDirectory('/opt/app-skills', true);

        self::assertStringContainsString('bimaaji.skills_directory', $exception->getMessage());
        self::assertStringNotContainsString('composer reinstall', $exception->getMessage());
    }

    #[Test]
    public function anUnreadableDirectoryPointsAtFilesystemPermissions(): void
    {
        $exception = SkillResourceException::unreadableDirectory('/vendor/waaseyaa/bimaaji/resources/skills', false);

        self::assertSame(SkillResourceFailure::Missing, $exception->failure);
        self::assertStringContainsString('could not be read', $exception->getMessage());
        self::assertStringContainsString('permissions', $exception->getMessage());
    }

    #[Test]
    public function anEmptyDirectorySaysWhatItExpectedToFind(): void
    {
        $exception = SkillResourceException::emptyDirectory('/opt/app-skills', true);

        self::assertSame(SkillResourceFailure::Missing, $exception->failure);
        self::assertStringContainsString('contains no <skill-id>/SKILL.md', $exception->getMessage());
        self::assertStringContainsString('bimaaji.skills_directory', $exception->getMessage());
    }

    #[Test]
    public function aCorruptSkillNamesTheFileTheReasonAndTheDirectory(): void
    {
        $exception = SkillResourceException::corruptSkill(
            '/vendor/waaseyaa/bimaaji/resources/skills',
            '/vendor/waaseyaa/bimaaji/resources/skills/api-layer/SKILL.md',
            'the document is empty',
        );

        self::assertSame(SkillResourceFailure::Corrupt, $exception->failure);
        self::assertSame('/vendor/waaseyaa/bimaaji/resources/skills/api-layer/SKILL.md', $exception->skillFile);
        self::assertStringContainsString('api-layer/SKILL.md is corrupt', $exception->getMessage());
        self::assertStringContainsString('the document is empty', $exception->getMessage());
        self::assertStringContainsString('/vendor/waaseyaa/bimaaji/resources/skills', $exception->getMessage());
    }
}
