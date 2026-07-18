<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Test-only content entity (analogous to `node`) used by
 * {@see \Waaseyaa\Migration\Tests\Unit\ContentModel\ContentModelRegistrarTest}
 * and {@see \Waaseyaa\Migration\Tests\Integration\ContentModelRegistrationEndToEndTest}
 * to exercise a full G-026 (#1940) content-model-registration + destination-write
 * cycle: `page_type` is a bundle key backed by a real `bundleEntityType`
 * ({@see MigrationTestContentTypeConfig}), so
 * {@see \Waaseyaa\Migration\ContentModel\ContentModelRegistrar} exercises both
 * `ensureBundleConfigEntity()` and `declareFields()` — not just the latter, as
 * {@see MigrationTestWidgetType} (which declares no `bundleEntityType`) does.
 *
 * @internal Test fixture only.
 */
#[ContentEntityType(id: 'migration_test_page')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'page_type')]
final class MigrationTestPage extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $page_type;
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $body;
    #[Field(type: 'text', read: FieldReadLevel::Public)] public string $summary;
}
