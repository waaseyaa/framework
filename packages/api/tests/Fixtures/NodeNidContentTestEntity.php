<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Node fixture with Drupal-style {@code nid} storage key (schema / MCP tool tests).
 */
#[ContentEntityType(id: 'node')]
#[ContentEntityKeys(id: 'nid', uuid: 'uuid', label: 'title', bundle: 'type')]
final class NodeNidContentTestEntity extends ContentEntityBase
{
    use ApiPublicContentFields;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
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
