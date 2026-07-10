<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Test-only bundle CONFIG entity (analogous to `node_type`) used by
 * {@see \Waaseyaa\Migration\Tests\Unit\ContentModel\ContentModelRegistrarTest}
 * and {@see \Waaseyaa\Migration\Tests\Integration\ContentModelRegistrationEndToEndTest}
 * to exercise {@see \Waaseyaa\Migration\ContentModel\ContentModelRegistrar}'s
 * `ensureBundleConfigEntity()` path (G-026, #1940) without pulling in a
 * higher-layer content package (`node`) as a test dependency.
 *
 * @internal Test fixture only.
 */
#[ContentEntityType(id: 'migration_test_content_type')]
#[ContentEntityKeys(id: 'id', label: 'label')]
final class MigrationTestContentTypeConfig extends ContentEntityBase
{
}
