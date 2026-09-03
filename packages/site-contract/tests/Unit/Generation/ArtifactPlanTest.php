<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;

#[CoversClass(ArtifactPlan::class)]
final class ArtifactPlanTest extends TestCase
{
    private const string INPUT_DIGEST = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /**
     * The digest a reviewer quotes. Pinned so any change to the member set,
     * the encoder, or the trailing-newline convention trips this wire rather
     * than silently re-addressing every plan already reviewed under it.
     */
    private const string SCAFFOLD_PLAN_DIGEST = 'c2a5e3893400b6862f3d281428bf154349f535a93c206d72c858510225acce8e';

    #[Test]
    public function itPublishesTheClosedVersionOneSchemaIdentity(): void
    {
        self::assertSame('waaseyaa.artifact_plan', ArtifactPlan::SCHEMA_ID);
        self::assertSame(1, ArtifactPlan::CONTRACT_VERSION);
    }

    #[Test]
    public function itEncodesExactlyTheTwelveMemberDocument(): void
    {
        $plan = $this->scaffoldPlan();

        self::assertSame([
            'schema' => 'waaseyaa.artifact_plan',
            'version' => 1,
            'generator' => ['fqcn' => 'Example\\Generation\\StoryScaffoldCompiler', 'version' => 1],
            'unit' => ['id' => 'scaffold:content-type:story', 'disposition' => 'seeded'],
            'input_digest' => self::INPUT_DIGEST,
            'artifacts' => [
                [
                    'path' => 'src/Entity/Story.php',
                    'mode' => '0644',
                    'content' => "<?php\n\nfinal class Story {}\n",
                ],
                [
                    'path' => 'tests/Entity/StoryTest.php',
                    'mode' => '0644',
                    'content' => "<?php\n\nfinal class StoryTest {}\n",
                ],
            ],
            'retires' => [],
            'registrations' => [['fqcn' => 'App\\Provider\\StoryServiceProvider']],
            'companion_tests' => ['tests/Entity/StoryTest.php'],
            'set_evolution' => 'frozen',
            'schema_effects' => [],
            'config_effects' => [],
        ], $plan->toArray());
    }

    #[Test]
    public function anArtifactRowCarriesItsExtensionRegionOnlyWhenOneIsDeclared(): void
    {
        $plan = $this->planWith(artifacts: [
            new GeneratedArtifact('AGENTS.md', "# Guide\n<!-- waaseyaa:extension:start local -->\n<!-- waaseyaa:extension:end local -->\n", 0o644, 'local'),
            new GeneratedArtifact('bin/maintenance/site-verify', "<?php\n", 0o755),
        ], companionTests: []);

        self::assertSame([
            [
                'path' => 'AGENTS.md',
                'mode' => '0644',
                'content' => "# Guide\n<!-- waaseyaa:extension:start local -->\n<!-- waaseyaa:extension:end local -->\n",
                'extension_region' => 'local',
            ],
            [
                'path' => 'bin/maintenance/site-verify',
                'mode' => '0755',
                'content' => "<?php\n",
            ],
        ], $plan->toArray()['artifacts']);
    }

    #[Test]
    public function itDigestsTheCanonicalDocumentWithATrailingNewline(): void
    {
        $plan = $this->scaffoldPlan();

        self::assertSame(CanonicalJson::encode($plan->toArray()), $plan->canonicalJson);
        self::assertSame(hash('sha256', $plan->canonicalJson . "\n"), $plan->digest);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $plan->digest);
    }

    #[Test]
    public function theDigestIsPinnedSoAReviewedPlanStaysAddressable(): void
    {
        self::assertSame(self::SCAFFOLD_PLAN_DIGEST, $this->scaffoldPlan()->digest);
    }

    #[Test]
    public function twoCompilationsOfTheSameInputProduceByteIdenticalPlans(): void
    {
        self::assertSame($this->scaffoldPlan()->canonicalJson, $this->scaffoldPlan()->canonicalJson);
        self::assertSame($this->scaffoldPlan()->digest, $this->scaffoldPlan()->digest);
    }

    #[Test]
    public function changingAnyMemberChangesTheDigest(): void
    {
        $baseline = $this->scaffoldPlan()->digest;

        self::assertNotSame($baseline, $this->planWith(generatorVersion: 2)->digest);
        self::assertNotSame($baseline, $this->planWith(unitId: 'scaffold:content-type:essay')->digest);
        self::assertNotSame($baseline, $this->planWith(disposition: GenerationUnitDisposition::Managed)->digest);
        self::assertNotSame($baseline, $this->planWith(setEvolution: ArtifactSetEvolution::Additive)->digest);
        self::assertNotSame($baseline, $this->planWith(retires: ['scaffold:content-type:legacy'])->digest);
        self::assertNotSame($baseline, $this->planWith(schemaEffects: ['story.base_table'])->digest);
        self::assertNotSame($baseline, $this->planWith(configEffects: ['story.settings'])->digest);
    }

    #[Test]
    public function setEvolutionIsFrozenByDefaultAndCarriesTheDeclarationWithoutJudgingEligibility(): void
    {
        self::assertSame(ArtifactSetEvolution::Frozen, $this->scaffoldPlan()->setEvolution);
        self::assertSame(
            'additive',
            $this->planWith(setEvolution: ArtifactSetEvolution::Additive)->toArray()['set_evolution'],
        );
        self::assertSame(
            ['frozen', 'additive'],
            array_map(static fn(ArtifactSetEvolution $case): string => $case->value, ArtifactSetEvolution::cases()),
        );
    }

    #[Test]
    public function itCarriesExactlyTwoDispositions(): void
    {
        self::assertSame(
            ['managed', 'seeded'],
            array_map(static fn(GenerationUnitDisposition $case): string => $case->value, GenerationUnitDisposition::cases()),
        );
    }

    #[Test]
    public function itRefusesMisorderedArtifactsRatherThanSortingThem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan artifacts must be sorted by path.');

        $this->planWith(artifacts: [
            new GeneratedArtifact('tests/Entity/StoryTest.php', "<?php\n"),
            new GeneratedArtifact('src/Entity/Story.php', "<?php\n"),
        ], companionTests: []);
    }

    #[Test]
    public function itRefusesAnArtifactListThatIsNotAList(): void
    {
        // A path-keyed set is the idiom GeneratedSite uses internally, and it
        // survives every foreach-based order and uniqueness check. Canonical
        // encoding would then emit `artifacts` as a JSON object, re-sorted by
        // key -- which is both the wrong shape for a member D-6.1 declares a
        // list, and exactly the silent re-sorting D-6.3 forbids.
        $rows = [];
        foreach ([
            new GeneratedArtifact('src/Entity/Story.php', "<?php\n"),
            new GeneratedArtifact('tests/Entity/StoryTest.php', "<?php\n"),
        ] as $artifact) {
            $rows[$artifact->path] = $artifact;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan artifacts must be a list.');

        $this->planWith(artifacts: $rows, companionTests: []);
    }

    #[Test]
    public function itRefusesAStringListWithGapsLeftByAFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan retires must be a list.');

        $this->planWith(retires: array_filter(
            ['scaffold:content-type:alpha', 'scaffold:content-type:beta', 'scaffold:content-type:gamma'],
            static fn(string $unit): bool => $unit !== 'scaffold:content-type:beta',
        ));
    }

    #[Test]
    public function itRefusesEveryOtherPlanMemberThatIsNotAList(): void
    {
        foreach ([
            'registrations' => ['first' => new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider')],
            'companion_tests' => ['a' => 'tests/Entity/StoryTest.php'],
            'schema_effects' => ['a' => 'story.base_table'],
            'config_effects' => ['a' => 'story.settings'],
        ] as $member => $value) {
            try {
                $this->planWith(...[self::memberArgument($member) => $value]);
                self::fail("A non-list {$member} must be refused.");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame("Artifact plan {$member} must be a list.", $exception->getMessage());
            }
        }
    }

    private static function memberArgument(string $member): string
    {
        return match ($member) {
            'registrations' => 'registrations',
            'companion_tests' => 'companionTests',
            'schema_effects' => 'schemaEffects',
            'config_effects' => 'configEffects',
            default => throw new \LogicException("Unknown member {$member}"),
        };
    }

    #[Test]
    public function itRefusesADuplicateArtifactPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan artifacts must be sorted by path.');

        $this->planWith(artifacts: [
            new GeneratedArtifact('src/Entity/Story.php', "<?php\n"),
            new GeneratedArtifact('src/Entity/Story.php', "<?php\n// again\n"),
        ], companionTests: []);
    }

    #[Test]
    public function itRefusesMisorderedRegistrationsRatherThanSortingThem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan registrations must be sorted by fqcn then group.');

        $this->planWith(registrations: [
            new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider'),
            new ComposerProviderRegistration('App\\Provider\\EssayServiceProvider'),
        ]);
    }

    #[Test]
    public function registrationsOrderByFqcnAndAGroupNeverBreaksATie(): void
    {
        // The declared order is fqcn, then group with an absent group first.
        // Within one plan the tiebreak is unreachable, because a plan may
        // declare each fqcn only once — so the ordering an operator can
        // actually observe is decided entirely by fqcn.
        $plan = $this->planWith(registrations: [
            new ComposerProviderRegistration('App\\Provider\\EssayServiceProvider'),
            new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', 'cli'),
        ]);

        self::assertSame([
            ['fqcn' => 'App\\Provider\\EssayServiceProvider'],
            ['fqcn' => 'App\\Provider\\StoryServiceProvider', 'group' => 'cli'],
        ], $plan->toArray()['registrations']);
    }

    #[Test]
    public function itRefusesTheSameFqcnTwiceEvenWhenTheGroupOrderWouldBeCorrect(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan registrations must declare each fqcn once.');

        $this->planWith(registrations: [
            new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider'),
            new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', 'cli'),
        ]);
    }

    #[Test]
    public function itRefusesADuplicateRegistrationFqcnWithinOnePlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan registrations must declare each fqcn once.');

        $this->planWith(registrations: [
            new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', 'cli'),
            new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider', 'http'),
        ]);
    }

    #[Test]
    public function itRefusesMisorderedOrDuplicatedUnitIdLists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan retires must be sorted and unique.');

        $this->planWith(retires: ['scaffold:content-type:story2', 'scaffold:content-type:essay']);
    }

    #[Test]
    public function itRefusesMisorderedCompanionTests(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan companion_tests must be sorted and unique.');

        $this->planWith(
            artifacts: [
                new GeneratedArtifact('tests/Entity/AStoryTest.php', "<?php\n"),
                new GeneratedArtifact('tests/Entity/StoryTest.php', "<?php\n"),
            ],
            companionTests: ['tests/Entity/StoryTest.php', 'tests/Entity/AStoryTest.php'],
        );
    }

    #[Test]
    public function itRefusesMisorderedSchemaAndConfigEffects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan schema_effects must be sorted and unique.');

        $this->planWith(schemaEffects: ['story.table', 'essay.table']);
    }

    #[Test]
    public function itRefusesACompanionTestThatIsNotAlsoAnArtifact(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan companion test is not one of its artifacts: tests/Entity/EssayTest.php');

        $this->planWith(companionTests: ['tests/Entity/EssayTest.php']);
    }

    #[Test]
    public function itRefusesArtifactContentThatIsNotValidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan content must be valid UTF-8: src/Entity/Story.md');

        $this->planWith(artifacts: [
            new GeneratedArtifact('src/Entity/Story.md', "binary \xC3\x28 payload"),
        ], companionTests: []);
    }

    #[Test]
    public function itAcceptsTheReservedRootUnitIdBecauseTheRootCompilerSuppliesIt(): void
    {
        self::assertSame('site', $this->planWith(unitId: 'site')->toArray()['unit']['id']);
    }

    #[Test]
    public function itRefusesAUnitIdOutsideTheDeclaredGrammar(): void
    {
        foreach ([
            '',
            'Scaffold:content-type:story',
            'scaffold::story',
            '-scaffold:story',
            'scaffold:story-',
            'scaffold:content_type:story',
            'scaffold:content-type:story ',
            str_repeat('a', 129),
        ] as $candidate) {
            try {
                $this->planWith(unitId: $candidate);
                self::fail("Unit id must be refused: {$candidate}");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Generation unit id is invalid', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itAcceptsAUnitIdOfExactlyTheLengthLimit(): void
    {
        $id = str_repeat('a', 128);

        self::assertSame($id, $this->planWith(unitId: $id)->unitId);
    }

    #[Test]
    public function itRefusesARetiredUnitIdOutsideTheDeclaredGrammar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generation unit id is invalid');

        $this->planWith(retires: ['NotAUnitId']);
    }

    #[Test]
    public function itRefusesAPlanThatRetiresTheUnitItSupplies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan cannot retire the unit it supplies: scaffold:content-type:story');

        $this->planWith(retires: ['scaffold:content-type:story']);
    }

    #[Test]
    public function itRefusesAnInputDigestThatIsNotLowercaseSha256(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan input_digest must be 64 lowercase hex characters.');

        $this->planWith(inputDigest: strtoupper(self::INPUT_DIGEST));
    }

    #[Test]
    public function itRefusesAnEmptyGeneratorFqcnOrANonPositiveGeneratorVersion(): void
    {
        try {
            $this->planWith(generatorFqcn: '');
            self::fail('An empty generator fqcn must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Artifact plan generator identity is invalid.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan generator identity is invalid.');

        $this->planWith(generatorVersion: 0);
    }

    #[Test]
    public function itRefusesAnEmptyEffectDeclaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact plan config_effects must not contain an empty entry.');

        $this->planWith(configEffects: ['']);
    }

    private function scaffoldPlan(): ArtifactPlan
    {
        return $this->planWith();
    }

    /**
     * @param list<GeneratedArtifact>|null $artifacts
     * @param list<string>|null $retires
     * @param list<ComposerProviderRegistration>|null $registrations
     * @param list<string>|null $companionTests
     * @param list<string>|null $schemaEffects
     * @param list<string>|null $configEffects
     */
    private function planWith(
        ?string $generatorFqcn = null,
        ?int $generatorVersion = null,
        ?string $unitId = null,
        ?GenerationUnitDisposition $disposition = null,
        ?string $inputDigest = null,
        ?array $artifacts = null,
        ?array $retires = null,
        ?array $registrations = null,
        ?array $companionTests = null,
        ?ArtifactSetEvolution $setEvolution = null,
        ?array $schemaEffects = null,
        ?array $configEffects = null,
    ): ArtifactPlan {
        return new ArtifactPlan(
            $generatorFqcn ?? 'Example\\Generation\\StoryScaffoldCompiler',
            $generatorVersion ?? 1,
            $unitId ?? 'scaffold:content-type:story',
            $disposition ?? GenerationUnitDisposition::Seeded,
            $inputDigest ?? self::INPUT_DIGEST,
            $artifacts ?? [
                new GeneratedArtifact('src/Entity/Story.php', "<?php\n\nfinal class Story {}\n"),
                new GeneratedArtifact('tests/Entity/StoryTest.php', "<?php\n\nfinal class StoryTest {}\n"),
            ],
            $retires ?? [],
            $registrations ?? [new ComposerProviderRegistration('App\\Provider\\StoryServiceProvider')],
            $companionTests ?? ['tests/Entity/StoryTest.php'],
            $setEvolution ?? ArtifactSetEvolution::Frozen,
            $schemaEffects ?? [],
            $configEffects ?? [],
        );
    }
}
