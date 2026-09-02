<?php

declare(strict_types=1);

namespace Fixture\CustomField;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'fixture_custom_product', label: 'Fixture custom product', api: true)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid')]
final class CustomProduct extends ContentEntityBase
{
    #[Field]
    public ?int $id = null;

    #[Field]
    public string $uuid = '';

    #[Field(type: 'fixture_custom_money', required: true)]
    public string $price = '';
}
