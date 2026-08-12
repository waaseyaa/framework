<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

/** Declares a storage-level composite unique key for explicit schema sync. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class StorageUniqueKey
{
    /**
     * @param non-empty-list<string> $fields
     */
    public function __construct(
        public string $name,
        public array $fields,
    ) {
        if ($name === '' || in_array('', $fields, true)) {
            throw new \InvalidArgumentException('StorageUniqueKey requires a name and at least one non-empty field.');
        }
        if (count(array_unique($fields)) !== count($fields)) {
            throw new \InvalidArgumentException(sprintf('StorageUniqueKey "%s" contains duplicate fields.', $name));
        }
    }
}
