<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\IngestionError;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;
use Waaseyaa\Foundation\Ingestion\InvalidEnvelopeException;

#[CoversClass(InvalidEnvelopeException::class)]
final class InvalidEnvelopeExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    #[Test]
    public function defaultMessageIsSet(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertSame('Envelope validation failed.', $exception->getMessage());
    }

    #[Test]
    public function customMessageOverridesDefault(): void
    {
        $exception = new InvalidEnvelopeException([], message: 'Custom error.');

        $this->assertSame('Custom error.', $exception->getMessage());
    }

    #[Test]
    public function errorsAreAccessible(): void
    {
        $errors = [
            new IngestionError(
                code: IngestionErrorCode::ENVELOPE_FIELD_MISSING,
                message: 'Missing source.',
                field: 'source',
            ),
            new IngestionError(
                code: IngestionErrorCode::ENVELOPE_FIELD_MISSING,
                message: 'Missing type.',
                field: 'type',
            ),
        ];

        $exception = new InvalidEnvelopeException($errors, traceId: 'trace-1');

        $this->assertCount(2, $exception->errors);
        $this->assertSame('source', $exception->errors[0]->field);
        $this->assertSame('type', $exception->errors[1]->field);
        $this->assertSame('trace-1', $exception->traceId);
    }

    #[Test]
    public function traceIdDefaultsToNull(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertNull($exception->traceId);
    }

    #[Test]
    public function toArrayDelegatesToIngestionErrorToArray(): void
    {
        $errors = [
            new IngestionError(
                code: IngestionErrorCode::ENVELOPE_FIELD_UNKNOWN,
                message: 'Unknown field.',
                field: 'extra',
                traceId: 'trace-1',
                details: ['hint' => 'remove field'],
            ),
        ];

        $exception = new InvalidEnvelopeException($errors);
        $arr = $exception->toArray();

        $this->assertCount(1, $arr);
        $this->assertSame('ENVELOPE_FIELD_UNKNOWN', $arr[0]['code']);
        $this->assertSame('Unknown field.', $arr[0]['message']);
        $this->assertSame('extra', $arr[0]['field']);
        $this->assertSame('trace-1', $arr[0]['trace_id']);
        $this->assertSame(['hint' => 'remove field'], $arr[0]['details']);
    }

    #[Test]
    public function toArrayWithEmptyErrorsReturnsEmptyList(): void
    {
        $exception = new InvalidEnvelopeException([]);

        $this->assertSame([], $exception->toArray());
    }
}
