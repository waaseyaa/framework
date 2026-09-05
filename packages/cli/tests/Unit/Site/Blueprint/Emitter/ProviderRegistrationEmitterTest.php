<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\ProviderRegistrationEmitter;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(ProviderRegistrationEmitter::class)]
final class ProviderRegistrationEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('provider-registration', new ProviderRegistrationEmitter()->id());
    }

    #[Test]
    public function itRegistersEveryEntityWithNoGroupMatchingTheCompleteGoldenFixture(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new ProviderRegistrationEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertCount(1, $emission->artifacts);
        self::assertSame('src/Provider/ApplicationBlueprintServiceProvider.php', $emission->artifacts[0]->path);
        self::assertSame($this->expected('complete/src/Provider/ApplicationBlueprintServiceProvider.php'), $emission->artifacts[0]->content);

        self::assertCount(1, $emission->registrations);
        self::assertSame('App\\Provider\\ApplicationBlueprintServiceProvider', $emission->registrations[0]->fqcn);
        self::assertNull($emission->registrations[0]->group);

        self::assertSame([], $emission->companionTests);
    }

    #[Test]
    public function itMatchesTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new ProviderRegistrationEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame($this->expected('minimal/src/Provider/ApplicationBlueprintServiceProvider.php'), $emission->artifacts[0]->content);
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
