<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;

#[CoversClass(CanonicalJson::class)]
final class CanonicalJsonTest extends TestCase
{
    private const STATE_DIGEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[Test]
    public function nestedStdClassMembersAreSortedWithoutBecomingArrays(): void
    {
        $payload = new \stdClass();
        $payload->zulu = 'last';
        $payload->alpha = new \stdClass();
        $payload->alpha->nested = new \stdClass();
        $payload->numeric = new \stdClass();
        $payload->numeric->{'0'} = 'alpha';
        $payload->list = [new \stdClass()];
        $payload->list[0]->item = 'β';

        self::assertSame(
            '{"root":{"alpha":{"nested":{}},"list":[{"item":"β"}],"numeric":{"0":"alpha"},"zulu":"last"}}',
            CanonicalJson::encode(['root' => $payload]),
        );
    }

    #[Test]
    public function emptyNestedObjectsRemainObjects(): void
    {
        $payload = new \stdClass();
        $payload->empty = new \stdClass();

        self::assertSame('{"value":{"empty":{}}}', CanonicalJson::encode(['value' => $payload]));
    }

    #[Test]
    public function callerObjectsAreNotMutated(): void
    {
        $payload = new \stdClass();
        $payload->zulu = 'last';
        $payload->alpha = new \stdClass();
        $payload->alpha->nested = new \stdClass();

        CanonicalJson::encode(['root' => $payload]);

        self::assertSame('last', $payload->zulu);
        self::assertObjectHasProperty('nested', $payload->alpha);
    }

    #[Test]
    public function existingArrayOnlyReceiptEncodingsRemainByteIdentical(): void
    {
        $empty = new ArtifactApplyResult(
            ArtifactApplyOutcome::Planned,
            str_repeat('a', 64),
            self::STATE_DIGEST,
            [],
            [],
        );
        $populated = new ArtifactApplyResult(
            ArtifactApplyOutcome::Applied,
            str_repeat('a', 64),
            self::STATE_DIGEST,
            ['src/Entity/Story.php' => ArtifactStatus::Created],
            ['src/Entity/Story.php'],
        );

        self::assertSame(
            '{"changed":[],"cleanup_pending":false,"errors":[],"outcome":"planned","plan_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","project_state_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","recovered_interrupted_transaction":false,"schema":"waaseyaa.artifact_result","status":{},"version":1}',
            $empty->canonicalJson(),
        );
        self::assertSame(
            '{"changed":["src/Entity/Story.php"],"cleanup_pending":false,"errors":[],"outcome":"applied","plan_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","project_state_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","recovered_interrupted_transaction":false,"schema":"waaseyaa.artifact_result","status":{"src/Entity/Story.php":"created"},"version":1}',
            $populated->canonicalJson(),
        );
    }
}
