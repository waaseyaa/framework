<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * FW-SITE-BLUEPRINT-01D retained negative boundary: declaring additive
 * evolution never authorizes the blueprint compiler by itself. The engine
 * requires a matching approved decision receipt on fresh and initialized
 * projects, and refuses the receipt-free plan with GEN011 before any write.
 * Positive admission and transition coverage lives in
 * BlueprintExecutionAdmissionTest.
 */
#[CoversClass(SiteInitializationService::class)]
final class GenerationBlueprintAdmissionTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            new Filesystem()->remove($root);
        }
    }

    #[Test]
    public function aFreshProjectRefusesTheCompilerPlanWithoutAReceiptBeforeAnyWrite(): void
    {
        $root = $this->root();
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($this->manifest('minimal.yaml'));

        try {
            new SiteInitializationService($root)->evaluate($plan);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $exception->violations[0]->code);
        }

        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function anAlreadyInitializedProjectRefusesTheCompilerPlanWithoutAReceiptBeforeAnyWrite(): void
    {
        $root = $this->root();
        file_put_contents($root . '/composer.lock', "{}\n");
        $blueprintFreeManifest = new SiteManifestParser()->parse($this->blueprintFreeManifest(), '<test>');
        $site = \Waaseyaa\CLI\Site\SiteArtifactRendererFactory::create()->render($blueprintFreeManifest);
        new SiteInitializationService($root)->initialize($site);
        $before = (string) file_get_contents($root . '/.waaseyaa/generated.json');

        $plan = ApplicationBlueprintCompilerFactory::create()->compile($this->manifest('minimal.yaml'));

        try {
            new SiteInitializationService($root)->evaluate($plan);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::UnauthorizedSetDelta, $exception->violations[0]->code);
        }

        self::assertSame($before, (string) file_get_contents($root . '/.waaseyaa/generated.json'));
        self::assertFileDoesNotExist($root . '/src/Entity/Article.php');
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_blueprint_admission_' . bin2hex(random_bytes(8));
        mkdir($root, 0o700);
        $this->roots[] = $root;

        return $root;
    }

    private function blueprintFreeManifest(): string
    {
        return <<<'YAML'
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
                state: not_needed
                reason: Not needed for this test.
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }

    private function manifest(string $fixture): \Waaseyaa\SiteContract\SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }
}
