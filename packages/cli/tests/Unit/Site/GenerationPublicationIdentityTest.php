<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\SiteHostPlatform;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class GenerationPublicationIdentityTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    #[DataProvider('platformProvider')]
    public function initializedRootMetadataBytesEqualRendererBytes(SiteHostPlatform $platform): void
    {
        $root = $this->root();
        $site = $this->site();

        new SiteInitializationService($root, null, $platform)->initialize($site);

        self::assertSame(
            $site->artifacts['.waaseyaa/generated.json']->content,
            file_get_contents($root . '/.waaseyaa/generated.json'),
        );
    }

    #[Test]
    #[DataProvider('platformProvider')]
    public function dormantRootPlanPublicationMetadataBytesEqualRendererBytes(SiteHostPlatform $platform): void
    {
        $root = $this->root();
        $site = $this->site();
        mkdir($root . '/.waaseyaa', 0700, true);
        $planArtifacts = array_values(array_filter(
            $site->artifacts,
            static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
        ));
        usort($planArtifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));
        $plan = new ArtifactPlan(
            SiteArtifactRenderer::class,
            $site->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $site->manifestDigest,
            $planArtifacts,
        );
        $service = new SiteInitializationService($root, null, $platform);
        $lock = fopen($root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = new \ReflectionMethod($service, 'prepareUnitPlan')->invoke($service, $plan);
            new \ReflectionMethod($service, 'publish')->invoke($service, $prepared['prepared'], $prepared['retirements']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(
            $site->artifacts['.waaseyaa/generated.json']->content,
            file_get_contents($root . '/.waaseyaa/generated.json'),
        );
    }

    /** @return iterable<string, array{SiteHostPlatform}> */
    public static function platformProvider(): iterable
    {
        yield 'posix' => [SiteHostPlatform::Posix];
        yield 'windows' => [SiteHostPlatform::Windows];
    }

    private function site(): GeneratedSite
    {
        $manifest = <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              id: example
              name: Example
              canonical_origin: {config_key: APP_ORIGIN}
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - {id: page, canonical_route: '/{slug}'}
            capabilities:
              - id: publishing
                state: active
                package: waaseyaa/publishing
                provider: site.publishing
                configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
                public_routes: []
                data_classification: public
                lifecycle: [create, publish]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification: {command: bin/maintenance/site-verify}
            YAML;

        return new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_generation_identity_' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $this->roots[] = $root;

        return $root;
    }
}
