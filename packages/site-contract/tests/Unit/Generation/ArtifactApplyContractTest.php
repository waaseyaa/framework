<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;

#[CoversClass(ArtifactApplyRequest::class)]
#[CoversClass(ArtifactApplyResult::class)]
final class ArtifactApplyContractTest extends TestCase
{
    private const string INPUT_DIGEST = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string STATE_DIGEST = 'b1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function theRequestCarriesThePlanBytesNotOnlyItsDigest(): void
    {
        $plan = $this->plan();
        $request = new ArtifactApplyRequest($plan, self::STATE_DIGEST);
        $document = $request->toArray();

        self::assertSame(['schema', 'version', 'plan', 'plan_digest', 'project_state_digest'], array_keys($document));
        self::assertSame('waaseyaa.artifact_apply_request', $document['schema']);
        self::assertSame(1, $document['version']);
        self::assertSame($plan->toArray(), $document['plan'], 'Apply executes the plan it was handed; it does not recompile.');
        self::assertSame(
            "<?php\n\nfinal class Story {}\n",
            $document['plan']['artifacts'][0]['content'],
            'The artifact bytes travel with the request.',
        );
        self::assertSame($plan->digest, $document['plan_digest']);
        self::assertSame(self::STATE_DIGEST, $document['project_state_digest']);
    }

    #[Test]
    public function theRequestDerivesThePlanDigestSoTransportCannotDisagreeWithItself(): void
    {
        $request = new ArtifactApplyRequest($this->plan(), self::STATE_DIGEST);

        self::assertSame($this->plan()->digest, $request->planDigest);
    }

    #[Test]
    public function theRequestRefusesAProjectStateDigestThatIsNotLowercaseSha256(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact apply request project_state_digest must be 64 lowercase hex characters.');

        new ArtifactApplyRequest($this->plan(), strtoupper(self::STATE_DIGEST));
    }

    #[Test]
    public function theResultCarriesTheDecisionsEnvelopeMembers(): void
    {
        $result = new ArtifactApplyResult(
            ArtifactApplyOutcome::Applied,
            $this->plan()->digest,
            self::STATE_DIGEST,
            ['src/Entity/Story.php' => ArtifactStatus::Created],
            ['src/Entity/Story.php'],
        );

        self::assertSame([
            'schema' => 'waaseyaa.artifact_result',
            'version' => 1,
            'outcome' => 'applied',
            'plan_digest' => $this->plan()->digest,
            'project_state_digest' => self::STATE_DIGEST,
            'status' => ['src/Entity/Story.php' => 'created'],
            'changed' => ['src/Entity/Story.php'],
            'recovered_interrupted_transaction' => false,
            'cleanup_pending' => false,
            'errors' => [],
        ], $result->toArray());
    }

    #[Test]
    public function theResultIsAStrictSupersetOfTodaysInitializationResult(): void
    {
        // D-6.4: "a strict superset of today's SiteInitializationResult
        // (changedPaths, dryRun, recoveredInterruptedTransaction, cleanupPending,
        // cancelled)", with dryRun and cancelled absorbed into `outcome`.
        $document = new ArtifactApplyResult(ArtifactApplyOutcome::Planned, $this->plan()->digest, self::STATE_DIGEST, [], [])->toArray();

        self::assertArrayHasKey('changed', $document);
        self::assertArrayHasKey('recovered_interrupted_transaction', $document);
        self::assertArrayHasKey('cleanup_pending', $document);
        self::assertSame('planned', $document['outcome']);
        self::assertSame(
            ['planned', 'applied', 'no_changes', 'cancelled', 'refused'],
            array_map(static fn(ArtifactApplyOutcome $case): string => $case->value, ArtifactApplyOutcome::cases()),
        );
    }

    #[Test]
    public function errorsAreEmptyUnlessTheOutcomeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact apply result errors are empty unless the outcome is refused.');

        new ArtifactApplyResult(
            ArtifactApplyOutcome::Applied,
            $this->plan()->digest,
            self::STATE_DIGEST,
            [],
            [],
            errors: [new GenerationViolation(GenerationErrorCode::Locked, 'Refused.')],
        );
    }

    #[Test]
    public function aRefusedOutcomeMustCarryAtLeastOneCodedError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A refused artifact apply result must carry at least one coded error.');

        new ArtifactApplyResult(ArtifactApplyOutcome::Refused, $this->plan()->digest, self::STATE_DIGEST, [], []);
    }

    #[Test]
    public function aRefusedResultSerializesItsCodedErrors(): void
    {
        $result = new ArtifactApplyResult(
            ArtifactApplyOutcome::Refused,
            $this->plan()->digest,
            self::STATE_DIGEST,
            ['src/Entity/Story.php' => ArtifactStatus::Refused],
            [],
            errors: [new GenerationViolation(GenerationErrorCode::StalePlan, 'The captured project state moved.', pointer: '/plan_digest')],
        );

        self::assertSame([
            ['code' => 'GEN005_STALE_PLAN', 'message' => 'The captured project state moved.', 'pointer' => '/plan_digest'],
        ], $result->toArray()['errors']);
    }

    #[Test]
    public function statusIsAJsonObjectInEveryStateOfTheDocument(): void
    {
        $empty = new ArtifactApplyResult(ArtifactApplyOutcome::Planned, $this->plan()->digest, self::STATE_DIGEST, [], []);
        $populated = new ArtifactApplyResult(
            ArtifactApplyOutcome::Applied,
            $this->plan()->digest,
            self::STATE_DIGEST,
            ['src/Entity/Story.php' => ArtifactStatus::Created],
            ['src/Entity/Story.php'],
        );

        self::assertStringContainsString('"status":{}', $empty->canonicalJson(), 'An empty map must not become a JSON list.');
        self::assertStringContainsString('"status":{"src/Entity/Story.php":"created"}', $populated->canonicalJson());
    }

    #[Test]
    public function theResultRefusesAPublishedPathItReportsNoStatusFor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact apply result changed a path it reports no status for: src/Entity/Story.php');

        new ArtifactApplyResult(
            ArtifactApplyOutcome::Applied,
            $this->plan()->digest,
            self::STATE_DIGEST,
            [],
            ['src/Entity/Story.php'],
        );
    }

    #[Test]
    public function theResultRefusesAnUnsortedChangedList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Artifact apply result changed must be sorted and unique.');

        new ArtifactApplyResult(
            ArtifactApplyOutcome::Applied,
            $this->plan()->digest,
            self::STATE_DIGEST,
            [],
            ['tests/Entity/StoryTest.php', 'src/Entity/Story.php'],
        );
    }

    #[Test]
    public function bothDocumentsEncodeCanonically(): void
    {
        $request = new ArtifactApplyRequest($this->plan(), self::STATE_DIGEST);
        $result = new ArtifactApplyResult(ArtifactApplyOutcome::NoChanges, $this->plan()->digest, self::STATE_DIGEST, [], []);

        self::assertSame(CanonicalJson::encode($request->toArray()), $request->canonicalJson());
        self::assertSame(CanonicalJson::encode($result->toArray()), $result->canonicalJson());
    }

    private function plan(): ArtifactPlan
    {
        return new ArtifactPlan(
            'Example\\Generation\\StoryScaffoldCompiler',
            1,
            'scaffold:content-type:story',
            GenerationUnitDisposition::Seeded,
            self::INPUT_DIGEST,
            [new GeneratedArtifact('src/Entity/Story.php', "<?php\n\nfinal class Story {}\n")],
        );
    }
}
