<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Test fixture whose constructor always throws — simulates an entity class
 * that cannot be instantiated as a bare prototype (e.g. a constructor that
 * enforces an invariant unmet by SchemaController's minimal $protoValues).
 *
 * Used to regression-test SchemaController::show() fail-closed behaviour: when
 * `new $class($protoValues)` throws, the controller must not fall through to
 * SchemaPresenter with a null entity (which would skip field-access filtering
 * and leak restricted field metadata) — see SchemaControllerTest.
 */
#[ContentEntityType(id: 'throwing_prototype')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'type')]
final class ThrowingPrototypeTestEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        throw new \RuntimeException('ThrowingPrototypeTestEntity always fails to construct.');
    }

    /**
     * @return array<string, string>
     */
    public static function definitionKeys(): array
    {
        return EntityMetadataReader::forClass(self::class)->keys;
    }
}
