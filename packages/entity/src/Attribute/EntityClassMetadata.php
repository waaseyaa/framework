<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

use Waaseyaa\Field\FieldDefinition;

/**
 * Resolved {@see ContentEntityType}, {@see ContentEntityKeys}, and {@see Field}
 * metadata for a class.
 *
 * @phpstan-type EntityKeyMap array<string, string>
 */
final readonly class EntityClassMetadata
{
    /**
     * @param EntityKeyMap                       $keys
     * @param array<string, FieldDefinition>     $fields field-name → definition
     */
    public function __construct(
        public ?string $typeId,
        public array $keys,
        public string $label = '',
        public string $description = '',
        public bool $api = false,
        /** Primary storage backend id, or '' when the type declares none (#2157). */
        public string $storageBackend = '',
        public array $fields = [],
    ) {}
}
