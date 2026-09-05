<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\EntityClassEmitter;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(EntityClassEmitter::class)]
final class EntityClassEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('entity-class', new EntityClassEmitter()->id());
    }

    #[Test]
    public function itEmitsOneArtifactPerEntityMatchingTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new EntityClassEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame(['src/Entity/Article.php'], $this->paths($emission->artifacts));
        self::assertSame($this->expected('minimal/src/Entity/Article.php'), $this->content($emission->artifacts, 'src/Entity/Article.php'));
        self::assertSame([], $emission->registrations);
        self::assertSame([], $emission->companionTests);
    }

    #[Test]
    public function itEmitsOneArtifactPerEntityMatchingTheCompleteGoldenFixtureWithTheRelationshipFieldOnTheFromEntityExactlyOnce(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new EntityClassEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame(['src/Entity/Article.php', 'src/Entity/Person.php'], $this->paths($emission->artifacts));
        self::assertSame($this->expected('complete/src/Entity/Article.php'), $this->content($emission->artifacts, 'src/Entity/Article.php'));
        self::assertSame($this->expected('complete/src/Entity/Person.php'), $this->content($emission->artifacts, 'src/Entity/Person.php'));

        $articleContent = $this->content($emission->artifacts, 'src/Entity/Article.php');
        self::assertSame(1, substr_count($articleContent, 'public ?int $author'));
        self::assertSame(0, substr_count($this->content($emission->artifacts, 'src/Entity/Person.php'), '$author'));
    }

    /** @param list<GeneratedArtifact> $artifacts @return list<string> */
    private function paths(array $artifacts): array
    {
        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $artifacts);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<GeneratedArtifact> $artifacts */
    private function content(array $artifacts, string $path): string
    {
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $path) {
                return $artifact->content;
            }
        }
        self::fail("No artifact at {$path}");
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
