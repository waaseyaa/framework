<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class GenerationAdditiveEvolutionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_additive_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        file_put_contents($this->root . '/composer.lock', "{}\n");
        new SiteInitializationService($this->root)->initialize($this->site(['page']));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function aRealRootSuccessorPublishesSortedGrowthAndPassesUnitDoctor(): void
    {
        $successor = $this->site(['page', 'story', 'article']);
        $plan = $this->rootPlan($successor, ArtifactSetEvolution::Additive);

        $evaluation = new SiteInitializationService($this->root)->evaluate($plan);

        self::assertSame([
            'src/Content/Bundle/ArticleBundle.php',
            'src/Content/Bundle/StoryBundle.php',
        ], $evaluation->setDelta()['adds']);
        self::assertSame([], $evaluation->setDelta()['drops']);

        $this->publish(new SiteInitializationService($this->root), $plan);

        self::assertFileExists($this->root . '/src/Content/Bundle/ArticleBundle.php');
        self::assertFileExists($this->root . '/src/Content/Bundle/StoryBundle.php');
        self::assertTrue(new SiteDoctorService()->inspectUnits($this->root)->passed);
    }

    #[Test]
    public function firstRootPublishAndUnchangedSuccessorHaveEmptySetDelta(): void
    {
        $site = $this->site(['page']);
        $fresh = sys_get_temp_dir() . '/waaseyaa_additive_fresh_' . bin2hex(random_bytes(8));
        mkdir($fresh, 0o700);
        try {
            $first = new SiteInitializationService($fresh)->evaluate($this->rootPlan($site, ArtifactSetEvolution::Additive));
            self::assertSame(['adds' => [], 'drops' => []], $first->setDelta());
        } finally {
            new Filesystem()->remove($fresh);
        }

        $unchanged = new SiteInitializationService($this->root)->evaluate($this->rootPlan($site, ArtifactSetEvolution::Additive));
        self::assertSame(['adds' => [], 'drops' => []], $unchanged->setDelta());
    }

    #[Test]
    public function sameInputGrowthChecksOnlyCarriedRows(): void
    {
        $site = $this->site(['page']);
        $artifacts = array_values(array_filter(
            $site->artifacts,
            static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
        ));
        $artifacts[] = new GeneratedArtifact('src/SameInputAddition.php', "<?php\n");
        usort($artifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));
        $plan = new ArtifactPlan(
            SiteArtifactRenderer::class,
            $site->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $site->manifestDigest,
            $artifacts,
            setEvolution: ArtifactSetEvolution::Additive,
        );

        self::assertSame(
            ['adds' => ['src/SameInputAddition.php'], 'drops' => []],
            new SiteInitializationService($this->root)->evaluate($plan)->setDelta(),
        );
    }

    #[Test]
    public function frozenGrowthRefusesGen011(): void
    {
        $this->expectRefusal('GEN011');
        new SiteInitializationService($this->root)->evaluate($this->rootPlan($this->site(['page', 'story'])));
    }

    #[Test]
    #[DataProvider('dropPolicies')]
    public function dropsRetainTheirDeclaredPolicy(ArtifactSetEvolution $evolution, string $code): void
    {
        $this->resetTo($this->site(['page', 'story']));
        $this->expectRefusal($code);
        new SiteInitializationService($this->root)->evaluate($this->rootPlan($this->site(['page']), $evolution));
    }

    public static function dropPolicies(): iterable
    {
        yield 'frozen drop remains undeclared retirement' => [ArtifactSetEvolution::Frozen, 'GEN009'];
        yield 'additive cannot shrink' => [ArtifactSetEvolution::Additive, 'GEN011'];
    }

    #[Test]
    #[DataProvider('ineligibleFirstPlans')]
    public function everyIneligibleFirstTupleRefusesGen011(string $generator, string $unit, GenerationUnitDisposition $disposition): void
    {
        $plan = new ArtifactPlan(
            $generator,
            1,
            $unit,
            $disposition,
            str_repeat('b', 64),
            [new GeneratedArtifact('src/First.php', "<?php\n")],
            setEvolution: ArtifactSetEvolution::Additive,
        );

        $this->expectRefusal('GEN011');
        new SiteInitializationService($this->root)->evaluate($plan);
    }

    public static function ineligibleFirstPlans(): iterable
    {
        yield 'root wrong compiler' => ['ExampleCompiler', 'site', GenerationUnitDisposition::Managed];
        yield 'root wrong disposition' => [SiteArtifactRenderer::class, 'site', GenerationUnitDisposition::Seeded];
        yield 'non-root renderer' => [SiteArtifactRenderer::class, 'scaffold:first', GenerationUnitDisposition::Managed];
        yield 'ordinary non-root compiler' => ['ExampleCompiler', 'scaffold:first', GenerationUnitDisposition::Managed];
    }

    #[Test]
    #[DataProvider('ineligibleExistingPlans')]
    public function everyIneligibleExistingTupleRefusesGen011(string $case): void
    {
        if (str_starts_with($case, 'root-')) {
            $site = $this->site(['page']);
            $baseline = $this->rootPlan($site);
            $plan = new ArtifactPlan(
                $case === 'root-compiler' ? 'ExampleCompiler' : SiteArtifactRenderer::class,
                $baseline->generatorVersion,
                'site',
                $case === 'root-disposition' ? GenerationUnitDisposition::Seeded : GenerationUnitDisposition::Managed,
                $baseline->inputDigest,
                $baseline->artifacts,
                setEvolution: ArtifactSetEvolution::Additive,
            );
        } else {
            $frozen = $this->nonRootPlan([new GeneratedArtifact('src/Owned.php', "<?php\n")]);
            $this->publish(new SiteInitializationService($this->root), $frozen);
            $plan = new ArtifactPlan(
                $case === 'non-root-renderer' ? SiteArtifactRenderer::class : 'ExampleCompiler',
                1,
                'scaffold:example',
                GenerationUnitDisposition::Managed,
                str_repeat('b', 64),
                $frozen->artifacts,
                setEvolution: ArtifactSetEvolution::Additive,
            );
        }

        $this->expectRefusal('GEN011');
        new SiteInitializationService($this->root)->evaluate($plan);
    }

    public static function ineligibleExistingPlans(): iterable
    {
        yield ['root-compiler'];
        yield ['root-disposition'];
        yield ['non-root-renderer'];
        yield ['non-root-compiler'];
    }

    #[Test]
    #[DataProvider('additionCollisions')]
    public function addedPathsUseExistingCollisionAuthority(string $owner): void
    {
        $path = 'src/Content/Bundle/StoryBundle.php';
        if ($owner === 'unowned') {
            file_put_contents($this->root . '/' . $path, 'foreign');
        } else {
            $this->publish(new SiteInitializationService($this->root), $this->nonRootPlan([
                new GeneratedArtifact($path, "<?php // rival\n"),
            ]));
        }

        $this->expectRefusal('GEN003');
        new SiteInitializationService($this->root)->evaluate(
            $this->rootPlan($this->site(['page', 'story']), ArtifactSetEvolution::Additive),
        );
    }

    public static function additionCollisions(): iterable
    {
        yield ['unowned'];
        yield ['other-unit'];
    }

    #[Test]
    public function changedCarriedContentStillRefusesAdditiveGrowth(): void
    {
        file_put_contents($this->root . '/AGENTS.md', "foreign\n", FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('edited outside an extension region');
        new SiteInitializationService($this->root)->evaluate(
            $this->rootPlan($this->site(['page', 'story']), ArtifactSetEvolution::Additive),
        );
    }

    #[Test]
    public function extensionBytesArePreservedAcrossAdditiveGrowth(): void
    {
        $agents = (string) file_get_contents($this->root . '/AGENTS.md');
        $agents = str_replace(
            '<!-- waaseyaa:extension:start local-guidance -->',
            "<!-- waaseyaa:extension:start local-guidance -->\nLocal rule.",
            $agents,
        );
        file_put_contents($this->root . '/AGENTS.md', $agents);

        $plan = $this->rootPlan($this->site(['page', 'story']), ArtifactSetEvolution::Additive);
        $this->publish(new SiteInitializationService($this->root), $plan);

        self::assertStringContainsString('Local rule.', (string) file_get_contents($this->root . '/AGENTS.md'));
        self::assertTrue(new SiteDoctorService()->inspectUnits($this->root)->passed);
    }

    #[Test]
    public function interruptedGrowthRollsBackBeforeMetadataCanClaimTheAddition(): void
    {
        $metadataBefore = (string) file_get_contents($this->root . '/.waaseyaa/generated.json');
        $plan = $this->rootPlan($this->site(['page', 'story']), ArtifactSetEvolution::Additive);
        $faulty = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path): void {
            if ($stage === 'before-replace' && $path === '.waaseyaa/generated.json') {
                throw new \Error('metadata boundary interrupted');
            }
        });

        try {
            $this->publish($faulty, $plan);
            self::fail('Expected interruption before metadata.');
        } catch (\Error $error) {
            self::assertSame('metadata boundary interrupted', $error->getMessage());
        }

        self::assertSame($metadataBefore, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertFileExists($this->root . '/src/Content/Bundle/StoryBundle.php');
        self::assertTrue($this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true));
        self::assertFileDoesNotExist($this->root . '/src/Content/Bundle/StoryBundle.php');
        self::assertSame($metadataBefore, file_get_contents($this->root . '/.waaseyaa/generated.json'));
        self::assertTrue(new SiteDoctorService()->inspectUnits($this->root)->passed);
    }

    #[Test]
    public function legacyInitializeStillRefusesRootGrowth(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generated ownership metadata does not match this generator version.');
        new SiteInitializationService($this->root)->initialize($this->site(['page', 'story']));
    }

    private function expectRefusal(string $code): void
    {
        $this->expectException(GenerationRefusalException::class);
        $this->expectExceptionMessage($code);
    }

    private function resetTo(GeneratedSite $site): void
    {
        new Filesystem()->remove($this->root);
        mkdir($this->root, 0o700);
        file_put_contents($this->root . '/composer.lock', "{}\n");
        new SiteInitializationService($this->root)->initialize($site);
    }

    /** @param list<GeneratedArtifact> $artifacts */
    private function nonRootPlan(array $artifacts, ArtifactSetEvolution $evolution = ArtifactSetEvolution::Frozen): ArtifactPlan
    {
        return new ArtifactPlan(
            'ExampleCompiler',
            1,
            'scaffold:example',
            GenerationUnitDisposition::Managed,
            str_repeat('b', 64),
            $artifacts,
            setEvolution: $evolution,
        );
    }

    private function rootPlan(GeneratedSite $site, ArtifactSetEvolution $evolution = ArtifactSetEvolution::Frozen): ArtifactPlan
    {
        return new ArtifactPlan(
            SiteArtifactRenderer::class,
            $site->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $site->manifestDigest,
            array_values(array_filter(
                $site->artifacts,
                static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
            )),
            setEvolution: $evolution,
        );
    }

    private function publish(SiteInitializationService $service, ArtifactPlan $plan): void
    {
        $lock = fopen($this->root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = $this->invoke($service, 'prepareUnitPlan', $plan);
            $this->invoke(
                $service,
                'publish',
                $prepared['prepared'],
                $prepared['retirements'],
                $prepared['composerMerge'],
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function invoke(SiteInitializationService $service, string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod($service, $method)->invoke($service, ...$arguments);
    }

    /** @param list<string> $contentTypes */
    private function site(array $contentTypes): GeneratedSite
    {
        $rows = '';
        foreach ($contentTypes as $id) {
            $route = $id === 'page' ? '/{slug}' : '/' . $id . '/{slug}';
            $rows .= "  - id: {$id}\n    canonical_route: {$route}\n";
        }
        $lockDigest = hash_file('sha256', $this->root . '/composer.lock');
        self::assertIsString($lockDigest);
        $manifest = sprintf(<<<'YAML'
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
              observed_lock_sha256: %s
            content_types:
            %s
            capabilities:
              - id: published_content
                state: active
                package: waaseyaa/listing
                provider: site.published_content
                configuration_authority: .waaseyaa/site.yaml#/capabilities/published_content
                public_routes: [/{slug}]
                data_classification: public
                lifecycle: [create, revise, publish, archive]
                verification: [tests/Acceptance/PublishedContentRecipeTest.php]
            personal_data_stores: []
            recipes:
              - id: published_content
                version: 1
                capability: published_content
                artifact_digest: %s
            verification:
              command: bin/maintenance/site-verify
            YAML, $lockDigest, $rows, PublishedContentRecipe::digest());

        return new SiteArtifactRenderer([new PublishedContentRecipe()])
            ->render(new SiteManifestParser()->parse($manifest));
    }
}
