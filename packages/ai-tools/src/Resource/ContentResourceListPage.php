<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

/**
 * One bounded content-resource discovery page.
 *
 * @api
 */
final readonly class ContentResourceListPage
{
    /** @var list<ContentResourceDescriptor> */
    public array $resources;

    /**
     * @param array<mixed> $resources
     * @param ?string      $nextToken provider-local resume token
     */
    public function __construct(
        array $resources,
        public ?string $nextToken = null,
    ) {
        if (!array_is_list($resources)) {
            throw new \InvalidArgumentException('Content resource list pages require a list of descriptors.');
        }
        $validated = [];
        foreach ($resources as $resource) {
            if (!$resource instanceof ContentResourceDescriptor) {
                throw new \InvalidArgumentException('Content resource list pages require ContentResourceDescriptor members.');
            }
            $validated[] = $resource;
        }
        if ($nextToken !== null
            && ($nextToken === '' || strlen($nextToken) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $nextToken) !== 1)
        ) {
            throw new \InvalidArgumentException('Content resource list resume tokens must be opaque and bounded.');
        }

        $this->resources = $validated;
    }
}
