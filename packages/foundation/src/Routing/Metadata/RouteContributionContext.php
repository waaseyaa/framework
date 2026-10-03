<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** Finalized, non-secret declaration inputs. Contains no live kernel services. @api */
final readonly class RouteContributionContext
{
    public array $configuration;
    /** @var array<string, bool> */
    public array $capabilities;
    /** @var list<array<string, mixed>> */
    public array $entities;

    public function __construct(
        public string $sourceId,
        public int $sourceOrder,
        array $configuration = [],
        array $capabilities = [],
        array $entities = [],
    ) {
        ScalarRouteMetadata::identifier($sourceId);
        if ($sourceOrder < 0 || !array_is_list($entities)) {
            throw new \InvalidArgumentException('Invalid route contribution order or entity projection.');
        }
        foreach ($capabilities as $id => $present) {
            if (!is_string($id) || !is_bool($present)) {
                throw new \InvalidArgumentException('Route capability presence must map identifiers to booleans.');
            }
            ScalarRouteMetadata::identifier($id);
        }
        foreach ($entities as $entity) {
            if (!is_array($entity) || !is_string($entity['id'] ?? null)) {
                throw new \InvalidArgumentException('Route entity projection requires a scalar entity identifier.');
            }
            ScalarRouteMetadata::identifier($entity['id']);
        }
        $this->configuration = ScalarRouteMetadata::copy($configuration);
        $this->capabilities = ScalarRouteMetadata::copy($capabilities);
        $this->entities = ScalarRouteMetadata::copy($entities);
    }
}
