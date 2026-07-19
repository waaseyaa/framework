<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/** @internal */
#[ContentEntityType(id: 'fresh_install_page_type')]
#[ContentEntityKeys(id: 'id', label: 'label')]
final class FreshInstallPageType extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $label;
}
