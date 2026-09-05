<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\ClaudeClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\CodexClientTransformer;
use Waaseyaa\Bimaaji\Install\InstallStateVerifier;
use Waaseyaa\Bimaaji\Install\PackagedSkillResources;
use Waaseyaa\Bimaaji\Install\SkillInventory;
use Waaseyaa\Bimaaji\Install\SkillSetParser;

#[CoversClass(InstallStateVerifier::class)]
final class InstallStateVerifierTest extends TestCase
{
    #[Test]
    public function codexAndClaudeShareTheSameCanonicalSourceHashes(): void
    {
        $inventory = SkillInventory::fromParser(new SkillSetParser(PackagedSkillResources::directory()));
        $verifier = new InstallStateVerifier();

        self::assertSame(
            $verifier->canonicalSkillSourceHashes($inventory),
            $inventory->sourceSha256ById(),
        );

        $claudeIds = array_map(
            static fn($file): ?string => $file->sourceSkill,
            (new ClaudeClientTransformer())->targetFiles($inventory->all()),
        );
        $codexIds = array_map(
            static fn($file): ?string => $file->sourceSkill,
            (new CodexClientTransformer())->targetFiles($inventory->all()),
        );

        self::assertSame(
            array_values(array_filter($claudeIds)),
            array_values(array_filter($codexIds)),
        );
    }
}
