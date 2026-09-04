<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\EvaluatedArtifactPlan;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\ObservedTargetState;
use Waaseyaa\SiteContract\Generation\ProjectStateIdentity;
use Waaseyaa\SiteContract\Generation\ProjectStateTarget;

#[CoversClass(EvaluatedArtifactPlan::class)]
final class EvaluatedArtifactPlanTest extends TestCase
{
    private const string INPUT_DIGEST = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function itCarriesExactlyTheEvaluationMembersTheDecisionNames(): void
    {
        // D-6.2 fixes the member set. EvaluatedArtifactPlan is deliberately NOT
        // one of the four versioned `waaseyaa.*` documents D-5 enumerates: it is
        // a return value, so it carries no schema string and no digest of its own.
        $members = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            new \ReflectionClass(EvaluatedArtifactPlan::class)->getProperties(),
        );
        sort($members, SORT_STRING);

        self::assertSame(
            ['adds', 'drops', 'plan', 'planDigest', 'projectState', 'projectStateDigest', 'refusals', 'status'],
            $members,
        );
    }

    #[Test]
    public function itDerivesBothDigestsRatherThanAcceptingThem(): void
    {
        $plan = $this->plan();
        $state = $this->projectState();

        $evaluated = new EvaluatedArtifactPlan($plan, $state, $this->statusMap());

        self::assertSame($plan->digest, $evaluated->planDigest);
        self::assertSame($state->digest, $evaluated->projectStateDigest);
        self::assertSame($plan, $evaluated->plan, 'The plan is carried verbatim and unmodified.');
    }

    #[Test]
    public function itsSetDeltaComposesTheTwoSortedHalves(): void
    {
        $evaluated = new EvaluatedArtifactPlan(
            $this->plan(),
            $this->projectState(),
            $this->statusMap(),
            adds: ['src/Entity/Essay.php', 'src/Entity/Story.php'],
            drops: ['src/Entity/Legacy.php'],
        );

        self::assertSame(
            ['adds' => ['src/Entity/Essay.php', 'src/Entity/Story.php'], 'drops' => ['src/Entity/Legacy.php']],
            $evaluated->setDelta(),
        );
    }

    #[Test]
    public function itRefusesAnUnsortedOrDuplicatedSetDelta(): void
    {
        foreach (['adds', 'drops'] as $half) {
            try {
                new EvaluatedArtifactPlan($this->plan(), $this->projectState(), $this->statusMap(), ...[
                    $half => ['src/Entity/Story.php', 'src/Entity/Essay.php'],
                ]);
                self::fail("An unsorted {$half} must be refused.");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame("Evaluated plan {$half} must be sorted and unique.", $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itsListMembersMustRemainJsonLists(): void
    {
        foreach (['adds', 'drops', 'refusals'] as $member) {
            try {
                new EvaluatedArtifactPlan($this->plan(), $this->projectState(), $this->statusMap(), ...[
                    $member => [2 => $member === 'refusals'
                        ? new GenerationViolation(GenerationErrorCode::Locked, 'Refused.')
                        : 'src/Entity/Extra.php'],
                ]);
                self::fail("A keyed {$member} member must be refused.");
            } catch (\InvalidArgumentException $exception) {
                self::assertSame("Evaluated plan {$member} must be a list.", $exception->getMessage());
            }
        }
    }

    #[Test]
    public function itsStatusMapIsClosedOverThePlansArtifactPaths(): void
    {
        try {
            new EvaluatedArtifactPlan($this->plan(), $this->projectState(), [
                'src/Entity/Story.php' => ArtifactStatus::Created,
            ]);
            self::fail('A status map missing a plan artifact must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Evaluated plan status must name every plan artifact exactly once.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Evaluated plan status names a path the plan does not carry: docs/README.md');

        new EvaluatedArtifactPlan($this->plan(), $this->projectState(), [
            'docs/README.md' => ArtifactStatus::Created,
            'src/Entity/Story.php' => ArtifactStatus::Created,
            'tests/Entity/StoryTest.php' => ArtifactStatus::Created,
        ]);
    }

    #[Test]
    public function everyRefusedPathCarriesItsCodedDetail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Evaluated plan refusal detail is missing for: src/Entity/Story.php');

        new EvaluatedArtifactPlan($this->plan(), $this->projectState(), [
            'src/Entity/Story.php' => ArtifactStatus::Refused,
            'tests/Entity/StoryTest.php' => ArtifactStatus::Unchanged,
        ]);
    }

    #[Test]
    public function aRefusalWithoutAPathIsAllowedBecauseNotEveryRefusalHasAnAddress(): void
    {
        $evaluated = new EvaluatedArtifactPlan(
            $this->plan(),
            $this->projectState(),
            $this->statusMap(),
            refusals: [new GenerationViolation(GenerationErrorCode::Locked, 'A concurrent initialization holds the project lock.')],
        );

        self::assertCount(1, $evaluated->refusals);
    }

    #[Test]
    public function changedNamesTheCreatedAndChangedPathsInSortedOrder(): void
    {
        $evaluated = new EvaluatedArtifactPlan($this->plan(), $this->projectState(), [
            'src/Entity/Story.php' => ArtifactStatus::Changed,
            'tests/Entity/StoryTest.php' => ArtifactStatus::Unchanged,
        ]);

        self::assertSame(['src/Entity/Story.php'], $evaluated->changed());
    }

    #[Test]
    public function theStatusVocabularyIsClosedAtTheFourDecisionValues(): void
    {
        self::assertSame(
            ['created', 'changed', 'unchanged', 'refused'],
            array_map(static fn(ArtifactStatus $case): string => $case->value, ArtifactStatus::cases()),
        );
    }

    private function plan(): ArtifactPlan
    {
        return new ArtifactPlan(
            'Example\\Generation\\StoryScaffoldCompiler',
            1,
            'scaffold:content-type:story',
            GenerationUnitDisposition::Seeded,
            self::INPUT_DIGEST,
            [
                new GeneratedArtifact('src/Entity/Story.php', "<?php\n\nfinal class Story {}\n"),
                new GeneratedArtifact('tests/Entity/StoryTest.php', "<?php\n\nfinal class StoryTest {}\n"),
            ],
        );
    }

    private function projectState(): ProjectStateIdentity
    {
        return new ProjectStateIdentity(
            ProjectStateIdentity::ABSENT_DIGEST,
            ProjectStateIdentity::ABSENT_DIGEST,
            ProjectStateIdentity::ABSENT_DIGEST,
            [
                new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::Absent),
                new ProjectStateTarget('tests/Entity/StoryTest.php', ObservedTargetState::Absent),
            ],
        );
    }

    /** @return array<string, ArtifactStatus> */
    private function statusMap(): array
    {
        return [
            'src/Entity/Story.php' => ArtifactStatus::Created,
            'tests/Entity/StoryTest.php' => ArtifactStatus::Created,
        ];
    }
}
