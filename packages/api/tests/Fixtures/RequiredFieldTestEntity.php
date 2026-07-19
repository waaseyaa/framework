<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Test fixture whose constructor REQUIRES the {@code owner_id} field to be
 * present, throwing if it is absent — mirroring real production types like
 * engagement's Comment (requires user_id/body) or user's UserBlock (requires
 * blocker_id). Seeding declared fields in SchemaController::show() must satisfy
 * this so the schema endpoint stays available (200) instead of failing closed
 * (500) for a legitimate, constructor-strict type.
 */
#[ContentEntityType(id: 'required_field')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'type')]
final class RequiredFieldTestEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        if (!isset($values['owner_id'])) {
            throw new \RuntimeException('RequiredFieldTestEntity requires owner_id to be present.');
        }

        parent::__construct($values, $entityTypeId, $entityKeys, ApiFixtureFieldDefinitions::mergePublic($values, $fieldDefinitions));
    }

    /**
     * @return array<string, string>
     */
    public static function definitionKeys(): array
    {
        return EntityMetadataReader::forClass(self::class)->keys;
    }
}
