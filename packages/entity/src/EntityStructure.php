<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Immutable selectors required to resolve definitions without reading fields.
 *
 * Labels and all other content-bearing values are deliberately absent.
 *
 * @api
 */
final readonly class EntityStructure
{
    /**
     * @param list<string> $knownTranslationIds
     * @param list<string> $fieldNames
     */
    public function __construct(
        public string $entityTypeId,
        public string $bundleId,
        public int|string|null $id = null,
        public ?string $uuid = null,
        public string $activeLanguageId = '',
        public string $defaultLanguageId = '',
        public array $knownTranslationIds = [],
        public int|string|null $revisionId = null,
        public bool $revisionTip = true,
        public bool $defaultRevision = true,
        public array $fieldNames = [],
    ) {
        if ($entityTypeId === '') {
            throw new \InvalidArgumentException('Entity structure requires an entity type id.');
        }
        if ($bundleId === '') {
            throw new \InvalidArgumentException('Entity structure requires a bundle id.');
        }
    }

    public function hasField(string $fieldName): bool
    {
        return in_array($fieldName, $this->fieldNames, true);
    }
}
