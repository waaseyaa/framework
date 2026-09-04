<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Generation\ArtifactApplyOutcome;
use Waaseyaa\SiteContract\Generation\ChangeOutcome;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;

#[CoversClass(ChangeReceipt::class)]
#[CoversClass(ChangeOutcome::class)]
final class ChangeReceiptTest extends TestCase
{
    private const string PLAN_DIGEST = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    #[Test]
    public function theEnvelopeIsClosedAtTheDecisionsMembers(): void
    {
        self::assertSame([
            'receipt_id',
            'protocol_version',
            'authority',
            'authority_version',
            'operation',
            'plan_digest',
            'outcome',
            'correlation_id',
            'issued_at',
            'domain_payload',
        ], array_keys($this->receipt()->toArray()));
    }

    #[Test]
    public function theProtocolVersionIsOneAndTheGenerationAuthorityIsNamespaced(): void
    {
        $document = $this->receipt()->toArray();

        self::assertSame(1, $document['protocol_version']);
        self::assertSame(1, ChangeReceipt::PROTOCOL_VERSION);
        self::assertSame('waaseyaa.generation', $document['authority']);
    }

    #[Test]
    public function itDoesNotRestateTheAuthorityVersionItIsGiven(): void
    {
        // D-14.9: authority_version has exactly one machine-readable source.
        // The receipt carries whatever the authority supplies and declares no
        // default of its own -- "a fixture carrying a literal version integer
        // is a defect, not a convenience".
        $parameter = new \ReflectionClass(ChangeReceipt::class)
            ->getConstructor()?->getParameters();
        self::assertNotNull($parameter);
        $authorityVersion = array_values(array_filter(
            $parameter,
            static fn(\ReflectionParameter $p): bool => $p->getName() === 'authorityVersion',
        ));

        self::assertCount(1, $authorityVersion);
        self::assertFalse($authorityVersion[0]->isDefaultValueAvailable(), 'The receipt must not carry its own authority version.');
    }

    #[Test]
    public function optionalChainMembersAreOmittedWhenAbsentAndDistinctWhenPresent(): void
    {
        $receipt = $this->receipt(
            causationReceiptId: 'rcpt-parent',
            decisionReceiptId: 'decision-7',
        );
        $document = $receipt->toArray();

        self::assertSame('rcpt-parent', $document['causation_receipt_id']);
        self::assertSame('decision-7', $document['decision_receipt_id']);
        self::assertArrayNotHasKey('causation_receipt_id', $this->receipt()->toArray());
        self::assertArrayNotHasKey('decision_receipt_id', $this->receipt()->toArray());
    }

    #[Test]
    public function anApprovalIsNeverCarriedAsACausation(): void
    {
        // D-14.6: decision_receipt_id is "a reference, never a copy ... never
        // expressed by overloading causation_receipt_id, which chains change
        // receipts to change receipts only".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A change receipt causation must not be its decision receipt.');

        $this->receipt(causationReceiptId: 'decision-7', decisionReceiptId: 'decision-7');
    }

    #[Test]
    public function theDomainPayloadCarriesItsOwnVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A change receipt domain payload must carry its own version.');

        $this->receipt(domainPayload: ['status' => []]);
    }

    #[Test]
    public function issuedAtIsRfc3339Utc(): void
    {
        self::assertSame('2026-09-04T01:02:03+00:00', $this->receipt()->toArray()['issued_at']);
    }

    #[Test]
    public function itMintsNothingItselfAndRefusesAnEmptyIdentity(): void
    {
        foreach (['receiptId', 'authority', 'operation', 'correlationId'] as $member) {
            try {
                $this->receipt(...[$member => '']);
                self::fail("An empty {$member} must be refused.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('must not be empty', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function theOutcomeVocabularyIsTheDecisionsFive(): void
    {
        self::assertSame(
            ['applied', 'no_op', 'refused', 'failed', 'recovered'],
            array_map(static fn(ChangeOutcome $case): string => $case->value, ChangeOutcome::cases()),
        );
    }

    #[Test]
    public function previewAndCancellationEarnNoReceipt(): void
    {
        // D-14.7 maps the result vocabulary onto the envelope; D-14.4 says
        // `planned` and `cancelled` "emit no receipt -- both terminate before
        // controlled apply". A null return is the mapping, not an exception.
        self::assertNull(ChangeOutcome::forApplyOutcome(ArtifactApplyOutcome::Planned));
        self::assertNull(ChangeOutcome::forApplyOutcome(ArtifactApplyOutcome::Cancelled));
        self::assertSame(ChangeOutcome::Applied, ChangeOutcome::forApplyOutcome(ArtifactApplyOutcome::Applied));
        self::assertSame(ChangeOutcome::NoOp, ChangeOutcome::forApplyOutcome(ArtifactApplyOutcome::NoChanges));
        self::assertSame(ChangeOutcome::Refused, ChangeOutcome::forApplyOutcome(ArtifactApplyOutcome::Refused));
    }

    #[Test]
    public function failedAndRecoveredExistWithoutWideningTheResultType(): void
    {
        // Neither has an ArtifactApplyResult counterpart: a transaction that
        // could neither complete nor roll back, and a recovery-only run.
        foreach ([ChangeOutcome::Failed, ChangeOutcome::Recovered] as $outcome) {
            self::assertNotContains(
                $outcome->value,
                array_map(static fn(ArtifactApplyOutcome $case): string => $case->value, ArtifactApplyOutcome::cases()),
            );
            self::assertSame($outcome->value, $this->receipt(outcome: $outcome)->toArray()['outcome']);
        }
    }

    /** @param array<string, mixed>|null $domainPayload */
    private function receipt(
        ?string $receiptId = null,
        ?string $authority = null,
        ?string $operation = null,
        ?string $correlationId = null,
        ?ChangeOutcome $outcome = null,
        ?string $causationReceiptId = null,
        ?string $decisionReceiptId = null,
        ?array $domainPayload = null,
    ): ChangeReceipt {
        return new ChangeReceipt(
            $receiptId ?? 'rcpt-1',
            $authority ?? 'waaseyaa.generation',
            7,
            $operation ?? 'site.init',
            self::PLAN_DIGEST,
            $outcome ?? ChangeOutcome::Applied,
            $correlationId ?? 'corr-1',
            new \DateTimeImmutable('2026-09-04T01:02:03+00:00'),
            $domainPayload ?? ['version' => 1],
            $causationReceiptId,
            $decisionReceiptId,
        );
    }
}
