<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Http;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;

/**
 * Publishes the Wayfinding anchor catalog on the agent-readable read side
 * (FR-007), completing the read/write symmetry with the alpha.221 trio: an agent
 * reads this public, read-only catalog to learn which `data-anchor` IDs exist
 * before emitting beacons via the (separate, authenticated) write tier.
 *
 * Public and read-only by design — it lists no record data, only the static,
 * type-level anchor scheme. Route wiring lives in
 * {@see \Waaseyaa\Wayfinding\WayfindingServiceProvider::routes()}.
 *
 * @api
 */
final class AnchorCatalogController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function catalog(): Response
    {
        $registry = new AnchorRegistry($this->entityTypeManager);

        try {
            $anchors = array_map(
                static fn($anchor): array => $anchor->toArray(),
                $registry->catalog(),
            );
        } catch (\Throwable) {
            // Best-effort public inventory, mirroring the SEO/llms.txt surface:
            // degrade to a valid-but-empty catalog rather than a 500.
            $anchors = [];
        }

        $payload = [
            'version' => 1,
            'kinds' => ['list', 'list-field', 'view', 'field', 'form', 'action'],
            'anchors' => $anchors,
        ];

        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
