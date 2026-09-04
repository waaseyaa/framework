<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ObservedTargetMode;
use Waaseyaa\SiteContract\Generation\ObservedTargetState;
use Waaseyaa\SiteContract\Generation\ProjectStateIdentity;
use Waaseyaa\SiteContract\Generation\ProjectStateTarget;

#[CoversClass(ProjectStateIdentity::class)]
#[CoversClass(ProjectStateTarget::class)]
final class ProjectStateIdentityTest extends TestCase
{
    private const string ABSENT = '0000000000000000000000000000000000000000000000000000000000000000';
    private const string SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[Test]
    public function itPublishesTheAbsentSentinelAsSixtyFourZeros(): void
    {
        self::assertSame(self::ABSENT, ProjectStateIdentity::ABSENT_DIGEST);
        self::assertSame('waaseyaa.project_state', ProjectStateIdentity::SCHEMA_ID);
        self::assertSame(1, ProjectStateIdentity::CONTRACT_VERSION);
    }

    #[Test]
    public function itEncodesTheClosedFourMemberDocument(): void
    {
        $identity = $this->identity();

        self::assertSame([
            'composer_json_sha256',
            'generated_metadata_sha256',
            'manifest_sha256',
            'schema',
            'targets',
            'version',
        ], $this->documentKeys($identity->canonicalJson));

        self::assertSame([
            'schema' => 'waaseyaa.project_state',
            'version' => 1,
            'generated_metadata_sha256' => self::SHA_A,
            'manifest_sha256' => self::ABSENT,
            'composer_json_sha256' => self::SHA_B,
            'targets' => [
                ['path' => 'src/Entity/Story.php', 'state' => 'absent', 'sha256' => self::ABSENT, 'mode' => 'unknown'],
                ['path' => 'tests/Entity/StoryTest.php', 'state' => 'file', 'sha256' => self::SHA_A, 'mode' => '0644'],
            ],
        ], $identity->toArray());
    }

    #[Test]
    public function itDigestsTheCanonicalDocumentWithATrailingNewline(): void
    {
        $identity = $this->identity();

        self::assertSame(CanonicalJson::encode($identity->toArray()), $identity->canonicalJson);
        self::assertSame(hash('sha256', $identity->canonicalJson . "\n"), $identity->digest);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $identity->digest);
    }

    #[Test]
    public function itIsAPureFunctionOfWhatWasObserved(): void
    {
        self::assertSame($this->identity()->digest, $this->identity()->digest);
    }

    #[Test]
    public function aChangedObservationChangesTheDigest(): void
    {
        $baseline = $this->identity();
        $moved = new ProjectStateIdentity(
            self::SHA_A,
            self::ABSENT,
            self::SHA_B,
            [
                new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Unknown),
                new ProjectStateTarget('tests/Entity/StoryTest.php', ObservedTargetState::File, self::SHA_B, ObservedTargetMode::Mode0644),
            ],
        );

        self::assertNotSame($baseline->digest, $moved->digest, 'A changed target digest must change the project-state digest.');
    }

    #[Test]
    public function itRefusesAMisorderedTargetListRatherThanSortingIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state targets must be sorted by path.');

        new ProjectStateIdentity(self::ABSENT, self::ABSENT, self::ABSENT, [
            new ProjectStateTarget('tests/Entity/StoryTest.php', ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Unknown),
            new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Unknown),
        ]);
    }

    #[Test]
    public function itRefusesATargetListThatIsNotAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state targets must be a list.');

        new ProjectStateIdentity(self::ABSENT, self::ABSENT, self::ABSENT, [
            'src/Entity/Story.php' => new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::Absent),
        ]);
    }

    #[Test]
    public function itRefusesADuplicateTargetPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state targets must be sorted by path.');

        new ProjectStateIdentity(self::ABSENT, self::ABSENT, self::ABSENT, [
            new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Unknown),
            new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::File, self::SHA_A, ObservedTargetMode::Mode0644),
        ]);
    }

    #[Test]
    public function itRefusesADocumentDigestThatIsNotLowercaseSha256(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state document digests must be 64 lowercase hex characters.');

        new ProjectStateIdentity(strtoupper(self::SHA_A), self::ABSENT, self::ABSENT, []);
    }

    #[Test]
    public function itRefusesATargetDigestThatIsNotLowercaseSha256(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state target sha256 must be 64 lowercase hex characters.');

        new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::File, 'not-a-digest', ObservedTargetMode::Mode0644);
    }

    /**
     * @param ObservedTargetState $state
     * @param string $sha256
     * @param ObservedTargetMode $mode
     */
    #[Test]
    #[DataProvider('impossibleTargetObservations')]
    public function itRefusesImpossibleTargetObservations(
        ObservedTargetState $state,
        string $sha256,
        ObservedTargetMode $mode,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state target observation is inconsistent.');

        new ProjectStateTarget('src/Entity/Story.php', $state, $sha256, $mode);
    }

    /** @return iterable<string, array{ObservedTargetState, string, ObservedTargetMode}> */
    public static function impossibleTargetObservations(): iterable
    {
        yield 'absent target with file bytes' => [ObservedTargetState::Absent, self::SHA_A, ObservedTargetMode::Unknown];
        yield 'absent target with a file mode' => [ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Mode0644];
        yield 'file target with the absent sentinel' => [ObservedTargetState::File, self::ABSENT, ObservedTargetMode::Mode0644];
        yield 'other target with file bytes' => [ObservedTargetState::Other, self::SHA_A, ObservedTargetMode::Other];
        yield 'other target with a file mode' => [ObservedTargetState::Other, self::ABSENT, ObservedTargetMode::Mode0755];
    }

    #[Test]
    public function itRefusesATargetPathThatEscapesTheProject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project state target paths must be safe project-relative paths.');

        new ProjectStateTarget('../outside.txt', ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Unknown);
    }

    #[Test]
    public function itRecordsEveryObservableStateAndModeVocabulary(): void
    {
        self::assertSame(
            ['absent', 'file', 'other'],
            array_map(static fn(ObservedTargetState $case): string => $case->value, ObservedTargetState::cases()),
        );
        self::assertSame(
            ['0644', '0755', 'other', 'unknown'],
            array_map(static fn(ObservedTargetMode $case): string => $case->value, ObservedTargetMode::cases()),
        );
    }

    private function identity(): ProjectStateIdentity
    {
        return new ProjectStateIdentity(
            self::SHA_A,
            self::ABSENT,
            self::SHA_B,
            [
                new ProjectStateTarget('src/Entity/Story.php', ObservedTargetState::Absent, self::ABSENT, ObservedTargetMode::Unknown),
                new ProjectStateTarget('tests/Entity/StoryTest.php', ObservedTargetState::File, self::SHA_A, ObservedTargetMode::Mode0644),
            ],
        );
    }

    /** @return list<string> */
    private function documentKeys(string $canonicalJson): array
    {
        $decoded = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_keys($decoded);
    }
}
