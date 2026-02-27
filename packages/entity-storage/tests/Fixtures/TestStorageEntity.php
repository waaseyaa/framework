<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Fixtures;

use Aurora\Entity\ContentEntityBase;

/**
 * Test entity class for storage tests.
 */
class TestStorageEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'test_entity',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
