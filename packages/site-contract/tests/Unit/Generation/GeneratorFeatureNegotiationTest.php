<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GeneratorFeatureNegotiation;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(GeneratorFeatureNegotiation::class)]
final class GeneratorFeatureNegotiationTest extends TestCase
{
    #[Test]
    public function aBlueprintFreeManifestPassesWithAnEmptyRoster(): void
    {
        $manifest = new SiteManifestParser()->parse($this->blueprintFreeManifest());

        GeneratorFeatureNegotiation::assert($manifest, [], 'site:init');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aBlueprintBearingManifestWithAnEmptyRosterRefusesGen007(): void
    {
        $manifest = new SiteManifestParser()->parse($this->blueprintManifest());

        try {
            GeneratorFeatureNegotiation::assert($manifest, [], 'site:init');
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertCount(1, $exception->violations);
            $violation = $exception->violations[0];
            self::assertSame(GenerationErrorCode::UnsupportedDeclaration, $violation->code);
            self::assertSame('/application_blueprint', $violation->pointer);
            self::assertStringContainsString(ApplicationBlueprint::GENERATOR_FEATURE, $violation->message);
        }
    }

    #[Test]
    public function aRosterContainingTheTokenPasses(): void
    {
        $manifest = new SiteManifestParser()->parse($this->blueprintManifest());

        GeneratorFeatureNegotiation::assert($manifest, [ApplicationBlueprint::GENERATOR_FEATURE], 'site:init');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function extraAdvertisedTokensAreIgnored(): void
    {
        $manifest = new SiteManifestParser()->parse($this->blueprintManifest());

        GeneratorFeatureNegotiation::assert(
            $manifest,
            [ApplicationBlueprint::GENERATOR_FEATURE, 'some-other-feature-v1'],
            'site:init',
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aNearMissTokenDoesNotSatisfyV1(): void
    {
        $manifest = new SiteManifestParser()->parse($this->blueprintManifest());

        $this->expectException(GenerationRefusalException::class);

        GeneratorFeatureNegotiation::assert($manifest, ['site-application-blueprint-v2'], 'site:init');
    }

    private function blueprintFreeManifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Example Nation
              id: example-nation
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - id: page
                canonical_route: /{slug}
            capabilities:
              - id: payments
                state: not_needed
                reason: Payments are outside this application.
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }

    private function blueprintManifest(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 2) . '/Fixtures/Blueprint/valid/minimal.yaml',
        );
    }
}
