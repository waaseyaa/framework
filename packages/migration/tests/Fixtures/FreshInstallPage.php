<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/** @internal */
#[ContentEntityType(id: 'fresh_install_page')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'page_type')]
final class FreshInstallPage extends ContentEntityBase
{
}
