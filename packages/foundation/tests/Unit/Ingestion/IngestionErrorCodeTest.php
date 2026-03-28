<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;

#[CoversClass(IngestionErrorCode::class)]
final class IngestionErrorCodeTest extends TestCase
{
    #[Test]
    public function enumHasExpectedCaseCount(): void
    {
        $cases = IngestionErrorCode::cases();

        $this->assertCount(14, $cases);
    }

    #[Test]
    public function allCasesHaveDistinctValues(): void
    {
        $values = array_map(
            static fn(IngestionErrorCode $c) => $c->value,
            IngestionErrorCode::cases(),
        );

        $this->assertSame($values, array_unique($values));
    }

    #[Test]
    public function envelopePrefixCoversEnvelopePhase(): void
    {
        $envelopeCases = array_filter(
            IngestionErrorCode::cases(),
            static fn(IngestionErrorCode $c) => str_starts_with($c->value, 'ENVELOPE_'),
        );

        $this->assertCount(6, $envelopeCases);
    }

    #[Test]
    public function payloadPrefixCoversPayloadPhase(): void
    {
        $payloadCases = array_filter(
            IngestionErrorCode::cases(),
            static fn(IngestionErrorCode $c) => str_starts_with($c->value, 'PAYLOAD_'),
        );

        $this->assertCount(8, $payloadCases);
    }

    #[Test]
    public function backedValueMatchesCaseName(): void
    {
        foreach (IngestionErrorCode::cases() as $case) {
            $this->assertSame($case->name, $case->value);
        }
    }

    #[Test]
    public function canBeResolvedFromStringValue(): void
    {
        $resolved = IngestionErrorCode::from('PAYLOAD_FIELD_MISSING');

        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_MISSING, $resolved);
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        $resolved = IngestionErrorCode::tryFrom('NONEXISTENT_CODE');

        $this->assertNull($resolved);
    }
}
