<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

/**
 * Registry-composed discovery page with a sealable multi-provider resume.
 *
 * @api
 */
final readonly class ContentResourceRegistryListPage
{
    /**
     * @param list<ContentResourceDescriptor> $resources
     */
    public function __construct(
        public array $resources,
        public ?ContentResourceListResume $next = null,
    ) {
        if (!array_is_list($resources)) {
            throw new \InvalidArgumentException('Content resource registry pages require a list of descriptors.');
        }
        foreach ($resources as $resource) {
            if (!$resource instanceof ContentResourceDescriptor) {
                throw new \InvalidArgumentException('Content resource registry pages require ContentResourceDescriptor members.');
            }
        }
    }
}
