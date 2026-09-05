<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'article', label: 'Article', storageBackend: 'sql-blob')]
#[ContentEntityKeys(
    id: 'id', uuid: 'uuid', label: 'title'
)]
final class Article extends ContentEntityBase
{
    #[Field(type: 'string', label: 'Title', required: false, translatable: false, revisionable: false)]
    public string $title = '';
}
