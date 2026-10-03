<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\ScalarRouteMetadata;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;

/** Copies finalized entity declarations, never execution services. @internal */
final class RouteInputProjector
{
    /**
     * Exposure decisions must come from the existing boot-finalized policy.
     * This projector neither recalculates policy nor discovers capabilities.
     *
     * @param array<array-key, mixed> $effectiveExposure Validated against the complete definition roster.
     * @param array<array-key, mixed> $capabilities Finalized presence facts from manifest/declaration bootstrap.
     */
    public function project(EntityTypeManagerInterface $manager, array $effectiveExposure, array $capabilities = []): RouteContributionContext
    {
        try {
            $definitions = $manager->getDefinitions();
            $ids = array_keys($definitions);
            $exposedIds = array_keys($effectiveExposure);
            sort($ids, SORT_STRING);
            sort($exposedIds, SORT_STRING);
            if ($ids !== $exposedIds) {
                throw new \UnexpectedValueException();
            }
            $entities = [];
            foreach ($definitions as $id => $definition) {
                if ($definition->id() !== $id || !is_bool($effectiveExposure[$id])) {
                    throw new \UnexpectedValueException();
                }
                $bundleId = $definition->getBundleEntityType();
                if ($bundleId !== null) {
                    ScalarRouteMetadata::identifier($bundleId);
                }
                $entities[] = ['id' => $id, 'bundle_entity_type' => $bundleId, 'api_exposed' => $effectiveExposure[$id]];
            }
            // Keep shared inputs even for an all-noop cohort, so the kernel can
            // bind them into whole-source identity without a declarative provider.
            return new RouteContributionContext('foundation.inputs', 0, capabilities: $capabilities, entities: $entities);
        } catch (\Throwable) {
            throw new RouteCompositionException('inputs-unavailable', 'Finalized route entity and exposure inputs are unavailable.');
        }
    }

    /** @return array<string, RouteContributionContext> */
    public function contexts(RouteContributionContext $inputs, ValidatedRouteParticipation $participation): array
    {
        if ($inputs->sourceId !== 'foundation.inputs' || $inputs->sourceOrder !== 0 || $inputs->configuration !== []) {
            throw new RouteCompositionException('inputs-unavailable', 'Provider contexts require finalized shared route inputs.');
        }
        $contexts = [];
        foreach (array_values($participation->records) as $order => $record) {
            if ($record['kind'] === 'declarative') {
                $contexts[$record['provider']] = new RouteContributionContext($record['provider'], $order, capabilities: $inputs->capabilities, entities: $inputs->entities);
            }
        }
        return $contexts;
    }
}
