<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures\AttributeFirstEntities;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Storage fixture used by EntityRepositoryTest's constraint-derivation tests.
 * `label` is required, which causes the validator to derive a NotBlank
 * constraint; tests then verify manual constraint replacement / augmentation.
 */
#[ContentEntityType(id: 'required_label_entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
class RequiredLabelFixture extends ContentEntityBase
{
    #[Field(required: true, read: FieldReadLevel::Public)]
    public string $label = '';

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<string, mixed> $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
