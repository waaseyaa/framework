<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Http;

use Symfony\Component\HttpFoundation\Response;
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
 * Takes the shared {@see AnchorRegistry} singleton (bound in
 * {@see \Waaseyaa\Wayfinding\WayfindingServiceProvider::register()}) via
 * constructor injection rather than constructing its own, so this public
 * anonymous endpoint reuses the registry's per-instance memoized catalog
 * instead of re-deriving it (iterating every entity type and field through
 * `SchemaPresenter`) on every single request (audit L4-wayfinding.md, MAJOR
 * finding).
 *
 * @api
 */
final class AnchorCatalogController
{
    public function __construct(
        private readonly AnchorRegistry $anchorRegistry,
    ) {}

    public function catalog(): Response
    {
        try {
            $anchors = array_map(
                static fn($anchor): array => $anchor->toArray(),
                $this->anchorRegistry->catalog(),
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
