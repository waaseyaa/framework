<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/** @internal */
#[ContentEntityType(id: 'fresh_install_page_type')]
#[ContentEntityKeys(id: 'id', label: 'label')]
final class FreshInstallPageType extends ContentEntityBase
{
}
