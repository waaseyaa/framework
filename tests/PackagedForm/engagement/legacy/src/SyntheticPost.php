<?php

declare(strict_types=1);

namespace EngagementConsumer;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'synthetic_post', label: 'Synthetic post', api: false)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class SyntheticPost extends ContentEntityBase
{
    #[Field(label: 'Publisher ID', settings: ['authorizationInput' => true])]
    public int $publisher_id = 0;

    #[Field(label: 'Featured subject ID')]
    public int $featured_subject_id = 0;

    #[Field(label: 'Title')]
    public string $title = '';

    #[Field(type: 'boolean', label: 'Published', default: true, settings: ['authorizationInput' => true])]
    public bool $status = true;
}
