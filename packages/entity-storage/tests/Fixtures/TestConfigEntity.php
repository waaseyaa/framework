<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Test config entity class for storage tests.
 */
class TestConfigEntity extends ConfigEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)]
    public string $name;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'test_config',
        array $entityKeys = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    public function hasField(string $name): bool
    {
        return \array_key_exists($name, $this->values);
    }
}
