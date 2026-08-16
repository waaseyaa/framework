<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

/** Names a domain-owned transition executed only by the coordinated schema plan. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class StorageSchemaTransition
{
    /** @param class-string $class */
    public function __construct(public string $class) {}
}
