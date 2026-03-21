<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

use Waaseyaa\Plugin\Attribute\WaaseyaaPlugin;

/**
 * PHP attribute for declaring entity types.
 *
 * This extends the base WaaseyaaPlugin attribute with entity-type-specific
 * fields. It is provided for future plugin-based discovery of entity types.
 *
 * Usage:
 *   #[EntityTypeAttribute(
 *       id: 'node',
 *       label: 'Content',
 *       storageClass: NodeStorage::class,
 *       keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
 *   )]
 *   class Node extends ContentEntityBase { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class EntityTypeAttribute extends WaaseyaaPlugin
{
    /**
     * @param string $id Machine name of the entity type.
     * @param string $label Human-readable label.
     * @param string $description Description of the entity type.
     * @param string $package Package grouping.
     * @param class-string $storageClass The storage handler class.
     * @param array<string, string> $keys Entity keys mapping.
     * @param bool $revisionable Whether revisions are supported.
     * @param bool $translatable Whether translations are supported.
     */
    public function __construct(
        string $id,
        string $label = '',
        string $description = '',
        string $package = '',
        public readonly string $storageClass = '',
        public readonly array $keys = [],
        public readonly bool $revisionable = false,
        public readonly bool $revisionDefault = false,
        public readonly bool $translatable = false,
    ) {
        parent::__construct(
            id: $id,
            label: $label,
            description: $description,
            package: $package,
        );
    }
}
