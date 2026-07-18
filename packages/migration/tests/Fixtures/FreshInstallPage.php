<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/** @internal */
#[ContentEntityType(id: 'fresh_install_page')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'page_type')]
final class FreshInstallPage extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $page_type;
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $body;
}
