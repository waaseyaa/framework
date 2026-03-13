<?php

declare(strict_types=1);

namespace Waaseyaa\Note\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Note\Ingestion\IngestionEnvelopeValidator;
use Waaseyaa\Note\Ingestion\ValidationError;

#[CoversClass(IngestionEnvelopeValidator::class)]
#[CoversClass(ValidationError::class)]
final class IngestionEnvelopeValidatorTest extends TestCase
{
    private IngestionEnvelopeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IngestionEnvelopeValidator();
    }

    // -----------------------------------------------------------------------
    // Valid envelopes
    // -----------------------------------------------------------------------

    #[Test]
    public function validEnvelopeProducesNoErrors(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:import-script',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => [
                'title'     => 'Imported Note',
                'body'      => 'Content here.',
            ],
        ]);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validEnvelopeWithoutBodyIsAccepted(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => [
                'title'     => 'A Title',
            ],
        ]);

        $this->assertSame([], $errors);
    }

    // -----------------------------------------------------------------------
    // INVALID_ENVELOPE — structural problems
    // -----------------------------------------------------------------------

    #[Test]
    public function missingEnvelopeVersionProducesError(): void
    {
        $errors = $this->validator->validate([
            'source'      => 'api:test',
            'ingested_at' => '2026-03-07T12:00:00Z',
            'payload'     => ['title' => 'T'],
        ]);

        $this->assertErrorWithCode($errors, 'INVALID_ENVELOPE', '/envelope_version');
    }

    #[Test]
    public function unsupportedEnvelopeVersionProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '99',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'T'],
        ]);

        $this->assertErrorWithCode($errors, 'INVALID_ENVELOPE', '/envelope_version');
    }

    #[Test]
    public function missingPayloadProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
        ]);

        $this->assertErrorWithCode($errors, 'INVALID_ENVELOPE', '/payload');
    }

    #[Test]
    public function payloadNotArrayProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => 'not-an-array',
        ]);

        $this->assertErrorWithCode($errors, 'INVALID_ENVELOPE', '/payload');
    }

    // -----------------------------------------------------------------------
    // MISSING_PROVENANCE — source / ingested_at
    // -----------------------------------------------------------------------

    #[Test]
    public function missingSourceProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'T'],
        ]);

        $this->assertErrorWithCode($errors, 'MISSING_PROVENANCE', '/source');
    }

    #[Test]
    public function emptySourceProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => '   ',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => 'T'],
        ]);

        $this->assertErrorWithCode($errors, 'MISSING_PROVENANCE', '/source');
    }

    #[Test]
    public function missingIngestedAtProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'payload'          => ['title' => 'T'],
        ]);

        $this->assertErrorWithCode($errors, 'MISSING_PROVENANCE', '/ingested_at');
    }

    #[Test]
    public function emptyIngestedAtProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '',
            'payload'          => ['title' => 'T'],
        ]);

        $this->assertErrorWithCode($errors, 'MISSING_PROVENANCE', '/ingested_at');
    }

    // -----------------------------------------------------------------------
    // SCHEMA_VIOLATION — payload field constraints
    // -----------------------------------------------------------------------

    #[Test]
    public function missingTitleInPayloadProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => [],
        ]);

        $this->assertErrorWithCode($errors, 'SCHEMA_VIOLATION', '/payload/title');
    }

    #[Test]
    public function emptyTitleInPayloadProducesError(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'source'           => 'api:test',
            'ingested_at'      => '2026-03-07T12:00:00Z',
            'payload'          => ['title' => '  '],
        ]);

        $this->assertErrorWithCode($errors, 'SCHEMA_VIOLATION', '/payload/title');
    }

    // -----------------------------------------------------------------------
    // ValidationError value object
    // -----------------------------------------------------------------------

    #[Test]
    public function validationErrorExposesPathCodeAndMessage(): void
    {
        $error = new ValidationError('/source', 'MISSING_PROVENANCE', 'source is required');

        $this->assertSame('/source', $error->path);
        $this->assertSame('MISSING_PROVENANCE', $error->code);
        $this->assertSame('source is required', $error->message);
    }

    #[Test]
    public function validationErrorSerializesToArray(): void
    {
        $error = new ValidationError('/payload/title', 'SCHEMA_VIOLATION', 'title is required');

        $this->assertSame([
            'path'    => '/payload/title',
            'code'    => 'SCHEMA_VIOLATION',
            'message' => 'title is required',
        ], $error->toArray());
    }

    // -----------------------------------------------------------------------
    // Multiple errors accumulate
    // -----------------------------------------------------------------------

    #[Test]
    public function multipleViolationsAreAllReported(): void
    {
        $errors = $this->validator->validate([
            'envelope_version' => '1',
            'ingested_at'      => '',
            'payload'          => ['title' => ''],
        ]);

        $codes = array_map(static fn(ValidationError $e): string => $e->code, $errors);
        $this->assertContains('MISSING_PROVENANCE', $codes);
        $this->assertContains('SCHEMA_VIOLATION', $codes);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param ValidationError[] $errors
     */
    private function assertErrorWithCode(array $errors, string $code, string $path): void
    {
        foreach ($errors as $error) {
            if ($error->code === $code && $error->path === $path) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail(sprintf(
            'Expected error with code "%s" at path "%s", got: %s',
            $code,
            $path,
            json_encode(array_map(static fn(ValidationError $e): array => $e->toArray(), $errors)),
        ));
    }
}
