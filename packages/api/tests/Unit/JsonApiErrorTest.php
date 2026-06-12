<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Waaseyaa\Api\JsonApiError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonApiError::class)]
final class JsonApiErrorTest extends TestCase
{
    #[Test]
    public function constructsWithAllFields(): void
    {
        $error = new JsonApiError(
            status: '422',
            title: 'Validation Error',
            detail: 'The title field is required.',
            source: ['pointer' => '/data/attributes/title'],
        );

        $this->assertSame('422', $error->status);
        $this->assertSame('Validation Error', $error->title);
        $this->assertSame('The title field is required.', $error->detail);
        $this->assertSame(['pointer' => '/data/attributes/title'], $error->source);
    }

    #[Test]
    public function constructsWithMinimalFields(): void
    {
        $error = new JsonApiError(
            status: '500',
            title: 'Internal Server Error',
        );

        $this->assertSame('500', $error->status);
        $this->assertSame('Internal Server Error', $error->title);
        $this->assertSame('', $error->detail);
        $this->assertSame([], $error->source);
    }

    #[Test]
    public function toArrayIncludesRequiredFields(): void
    {
        $error = new JsonApiError(status: '404', title: 'Not Found');
        $array = $error->toArray();

        $this->assertSame('404', $array['status']);
        $this->assertSame('Not Found', $array['title']);
        $this->assertArrayNotHasKey('detail', $array);
        $this->assertArrayNotHasKey('source', $array);
    }

    #[Test]
    public function toArrayIncludesOptionalFieldsWhenPresent(): void
    {
        $error = new JsonApiError(
            status: '422',
            title: 'Unprocessable Entity',
            detail: 'Field validation failed.',
            source: ['pointer' => '/data/attributes/name'],
        );

        $array = $error->toArray();

        $this->assertSame('Field validation failed.', $array['detail']);
        $this->assertSame(['pointer' => '/data/attributes/name'], $array['source']);
    }

    #[Test]
    public function notFoundFactory(): void
    {
        $error = JsonApiError::notFound('Entity not found.');

        $this->assertSame('404', $error->status);
        $this->assertSame('Not Found', $error->title);
        $this->assertSame('Entity not found.', $error->detail);
    }

    #[Test]
    public function forbiddenFactory(): void
    {
        $error = JsonApiError::forbidden('Access denied.');

        $this->assertSame('403', $error->status);
        $this->assertSame('Forbidden', $error->title);
        $this->assertSame('Access denied.', $error->detail);
    }

    #[Test]
    public function unprocessableFactory(): void
    {
        $error = JsonApiError::unprocessable(
            'Invalid value.',
            ['pointer' => '/data/attributes/status'],
        );

        $this->assertSame('422', $error->status);
        $this->assertSame('Unprocessable Entity', $error->title);
        $this->assertSame('Invalid value.', $error->detail);
        $this->assertSame(['pointer' => '/data/attributes/status'], $error->source);
    }

    #[Test]
    public function badRequestFactory(): void
    {
        $error = JsonApiError::badRequest('Malformed JSON.');

        $this->assertSame('400', $error->status);
        $this->assertSame('Bad Request', $error->title);
        $this->assertSame('Malformed JSON.', $error->detail);
    }

    #[Test]
    public function internalErrorFactory(): void
    {
        $error = JsonApiError::internalError('Something went wrong.');

        $this->assertSame('500', $error->status);
        $this->assertSame('Internal Server Error', $error->title);
        $this->assertSame('Something went wrong.', $error->detail);
    }

    #[Test]
    public function isReadonly(): void
    {
        $reflection = new \ReflectionClass(JsonApiError::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }

    // -----------------------------------------------------------------------
    // meta member (optimistic-locking-01KTXCHY, contract conflict-surfaces.md §13)
    // -----------------------------------------------------------------------

    #[Test]
    public function metaDefaultsToEmptyAndIsOmittedFromToArray(): void
    {
        $error = new JsonApiError(
            status: '422',
            title: 'Unprocessable Entity',
            detail: 'Field validation failed.',
            source: ['pointer' => '/data/attributes/name'],
        );

        $this->assertSame([], $error->meta);
        // Every pre-existing error response stays byte-identical: no meta key.
        $this->assertSame(
            [
                'status' => '422',
                'title' => 'Unprocessable Entity',
                'detail' => 'Field validation failed.',
                'source' => ['pointer' => '/data/attributes/name'],
            ],
            $error->toArray(),
        );
    }

    #[Test]
    public function toArrayEmitsMetaWhenNonEmptyAndPreservesNullValues(): void
    {
        $error = new JsonApiError(
            status: '409',
            title: 'Conflict',
            meta: ['expected_revision_id' => 5, 'current_revision_id' => null],
        );

        $array = $error->toArray();
        $this->assertSame(['expected_revision_id' => 5, 'current_revision_id' => null], $array['meta']);
        // current_revision_id: null (vanished row) must serialize as JSON null,
        // not be dropped.
        $this->assertSame(
            '{"expected_revision_id":5,"current_revision_id":null}',
            json_encode($array['meta'], JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function conflictFactoryDefaultShapeIsUnchanged(): void
    {
        $error = JsonApiError::conflict('Resource id mismatch.');

        $this->assertSame('409', $error->status);
        $this->assertSame('Conflict', $error->title);
        $this->assertSame('', $error->code);
        $this->assertSame([], $error->meta);
        // The pre-existing data.id-vs-uuid 409 keeps its codeless shape.
        $this->assertSame(
            ['status' => '409', 'title' => 'Conflict', 'detail' => 'Resource id mismatch.'],
            $error->toArray(),
        );
    }

    #[Test]
    public function conflictFactoryPassesThroughCodeAndMeta(): void
    {
        $error = JsonApiError::conflict(
            'Entity was modified.',
            code: 'REVISION_CONFLICT',
            meta: ['expected_revision_id' => 1, 'current_revision_id' => 2],
        );

        $this->assertSame(
            [
                'status' => '409',
                'title' => 'Conflict',
                'code' => 'REVISION_CONFLICT',
                'detail' => 'Entity was modified.',
                'meta' => ['expected_revision_id' => 1, 'current_revision_id' => 2],
            ],
            $error->toArray(),
        );
    }
}
