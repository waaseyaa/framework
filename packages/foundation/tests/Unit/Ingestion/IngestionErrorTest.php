<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\IngestionError;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;

#[CoversClass(IngestionError::class)]
final class IngestionErrorTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::PAYLOAD_FIELD_MISSING,
            message: "Required field 'title' is missing.",
            field: 'title',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            details: ['schema_version' => '0.1.0'],
        );

        $this->assertSame(IngestionErrorCode::PAYLOAD_FIELD_MISSING, $error->code);
        $this->assertSame("Required field 'title' is missing.", $error->message);
        $this->assertSame('title', $error->field);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $error->traceId);
        $this->assertSame(['schema_version' => '0.1.0'], $error->details);
    }

    #[Test]
    public function constructWithOptionalFieldsDefaulted(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::ENVELOPE_FIELD_MISSING,
            message: 'Missing source.',
            field: 'source',
        );

        $this->assertNull($error->traceId);
        $this->assertSame([], $error->details);
    }

    #[Test]
    public function toArraySerializesEnumCodeAsString(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::PAYLOAD_FIELD_TOO_LONG,
            message: "Field 'title' exceeds max length.",
            field: 'title',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            details: ['max' => 512],
        );

        $arr = $error->toArray();

        $this->assertSame('PAYLOAD_FIELD_TOO_LONG', $arr['code']);
        $this->assertSame("Field 'title' exceeds max length.", $arr['message']);
        $this->assertSame('title', $arr['field']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $arr['trace_id']);
        $this->assertSame(['max' => 512], $arr['details']);
    }

    #[Test]
    public function toArrayIncludesNullTraceId(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::ENVELOPE_FIELD_EMPTY,
            message: 'Empty.',
            field: 'source',
        );

        $arr = $error->toArray();

        $this->assertArrayHasKey('trace_id', $arr);
        $this->assertNull($arr['trace_id']);
    }

    #[Test]
    public function toArrayIncludesEmptyDetails(): void
    {
        $error = new IngestionError(
            code: IngestionErrorCode::ENVELOPE_FIELD_EMPTY,
            message: 'Empty.',
            field: 'source',
        );

        $arr = $error->toArray();

        $this->assertSame([], $arr['details']);
    }
}
