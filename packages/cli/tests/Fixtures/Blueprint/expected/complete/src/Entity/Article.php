<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ArticleStatus;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'article', label: 'Article', storageBackend: 'sql-column')]
#[ContentEntityKeys(
    id: 'id', uuid: 'uuid', label: 'title', revision: 'vid'
)]
final class Article extends ContentEntityBase
{
    #[Field(type: 'enum', label: 'Status', required: false, translatable: false, revisionable: false, settings: ['enum_class' => \App\Entity\Enum\ArticleStatus::class])]
    public ?ArticleStatus $status = null;

    #[Field(type: 'string', label: 'Title', required: true, translatable: false, revisionable: false, indexed: true)]
    public string $title = '';

    #[Field(type: 'entity_reference', label: 'Author', required: true, settings: ['target_entity_type_id' => 'person'])]
    public ?int $author = null;
}
