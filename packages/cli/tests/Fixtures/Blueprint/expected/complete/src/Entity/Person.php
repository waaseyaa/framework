<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

#[ContentEntityType(id: 'person', label: 'Person', storageBackend: 'sql-blob')]
#[ContentEntityKeys(
    id: 'id', uuid: 'uuid', label: 'name'
)]
final class Person extends ContentEntityBase
{
    #[Field(type: 'string', label: 'Name', required: true, translatable: false, revisionable: false, read: FieldReadLevel::Public)]
    public string $name = '';
}
