<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Field\FieldDefinition;

final class InternalFieldVisibilityPolicyTest extends TestCase
{
    #[Test]
    public function itCombinesFrameworkApplicationAndFieldDefinitionMetadata(): void
    {
        $policy = new InternalFieldVisibilityPolicy([
            'event' => ['legacy_origin'],
        ]);

        self::assertTrue($policy->isInternal('node', 'source_status'));
        self::assertTrue($policy->isInternal('event', 'legacy_origin'));
        self::assertTrue($policy->isInternal(
            'article',
            'editor_note',
            new FieldDefinition(name: 'editor_note', type: 'string', settings: ['internal' => true]),
        ));
        self::assertFalse($policy->isInternal(
            'event',
            'title',
            new FieldDefinition(name: 'title', type: 'string'),
        ));
        self::assertSame(['legacy_origin'], $policy->internalFields('event'));
    }

    #[Test]
    public function itLoadsApplicationMetadataFromConfig(): void
    {
        $policy = InternalFieldVisibilityPolicy::fromConfig([
            'entity' => [
                'internal_fields_by_type' => ['event' => ['legacy_origin']],
            ],
        ]);

        self::assertSame(['legacy_origin'], $policy->internalFields('event'));
    }

    #[Test]
    public function itRejectsMalformedEntityConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InternalFieldVisibilityPolicy::fromConfig(['entity' => 'invalid']);
    }

    #[Test]
    public function itRejectsMalformedInternalFieldMapConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InternalFieldVisibilityPolicy::fromConfig([
            'entity' => ['internal_fields_by_type' => 'invalid'],
        ]);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformedMetadata(): iterable
    {
        yield 'empty entity type' => [['' => ['field']]];
        yield 'map instead of list' => [['node' => ['field' => true]]];
        yield 'empty field' => [['node' => ['']]];
        yield 'non-string field' => [['node' => [1]]];
    }

    /** @param array<mixed> $metadata */
    #[Test]
    #[DataProvider('malformedMetadata')]
    public function malformedApplicationMetadataFailsClosed(array $metadata): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InternalFieldVisibilityPolicy($metadata);
    }
}
