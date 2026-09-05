<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\RelationshipEmitter;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(RelationshipEmitter::class)]
final class RelationshipEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('relationship-registry', new RelationshipEmitter()->id());
    }

    #[Test]
    public function itEmitsAnEmptyRegistryForAManifestWithNoRelationships(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new RelationshipEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertCount(1, $emission->artifacts);
        self::assertSame('config/waaseyaa-blueprint/relationships.php', $emission->artifacts[0]->path);
        self::assertSame($this->expected('minimal/config/waaseyaa-blueprint/relationships.php'), $emission->artifacts[0]->content);
        self::assertSame([], $emission->registrations);
        self::assertSame([], $emission->companionTests);
    }

    #[Test]
    public function itEmitsOneRowPerRelationshipMatchingTheCompleteGoldenFixture(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new RelationshipEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertCount(1, $emission->artifacts);
        self::assertSame($this->expected('complete/config/waaseyaa-blueprint/relationships.php'), $emission->artifacts[0]->content);
        self::assertStringContainsString("'article_author' =>", $emission->artifacts[0]->content);
        self::assertStringContainsString("'on_delete' => 'nullify'", $emission->artifacts[0]->content);
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 6) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function expected(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/' . $relativePath);
    }
}
