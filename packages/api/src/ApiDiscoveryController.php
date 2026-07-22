<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Handles GET /api — returns a JSON:API-style discovery document.
 *
 * The envelope (meta + links.self) is caller-independent, but per-type links
 * are account-dependent: only authenticated accounts see registered entity
 * types, and types marked `discoverable: false` are absent for every caller
 * (mission request-surface-hardening-01KTX7F2, #1649).
 */
final class ApiDiscoveryController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
        private readonly ?AccountInterface $account = null,
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
    ) {}

    /**
     * Returns a discovery document describing available entity type endpoints.
     *
     * Anonymous or absent accounts receive zero entity-type links — the
     * envelope is unchanged. Authenticated accounts see every discoverable
     * type. No access-policy invocation, no storage, no queries (NFR-001).
     *
     * @return array{meta: array<string, string>, links: array<string, mixed>}
     */
    public function discover(): array
    {
        $links = ['self' => $this->basePath];

        if ($this->account?->isAuthenticated() === true) {
            foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
                if (!EntityTypeApiExposure::isExposed($definition, $this->exposurePolicy)) {
                    continue;
                }
                if (method_exists($definition, 'isDiscoverable') && !$definition->isDiscoverable()) {
                    continue; // non-discoverable: absent for every caller (FR-002)
                }
                $links[$id] = [
                    'href' => $this->basePath . '/' . $id,
                    'meta' => ['type' => $id],
                ];
            }
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
