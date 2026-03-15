<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Handles GET /api — returns a JSON:API-style discovery document.
 *
 * Lists all registered entity types with their collection endpoint URLs.
 */
final class ApiDiscoveryController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    /**
     * Returns a discovery document describing all available entity type endpoints.
     *
     * @return array{meta: array<string, string>, links: array<string, mixed>}
     */
    public function discover(): array
    {
        $links = ['self' => $this->basePath];

        foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
            $links[$id] = [
                'href' => $this->basePath . '/' . $id,
                'meta' => ['type' => $id],
            ];
        }

        return [
            'meta' => [
                'api' => 'waaseyaa',
                'version' => '1.0',
            ],
            'links' => $links,
        ];
    }
}
