<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\SiteManifest;

/**
 * Resolves the request-scoped blueprint lifecycle state from evidence —
 * never trusted from authored YAML (#2785, ADR-023 D-5).
 *
 * Only the request-scoped states are resolvable from a manifest and an
 * optional {@see BlueprintDecisionReceipt}: `Applied` and `Superseded`
 * additionally require `.waaseyaa/generated.json` evidence, which belongs to
 * #2787's initializer/doctor extension, not this class.
 *
 * @api
 */
final class BlueprintLifecycleResolver
{
    public function resolve(SiteManifest $manifest, ?BlueprintDecisionReceipt $receipt): BlueprintLifecycle
    {
        if ($manifest->applicationBlueprint === null) {
            throw new \LogicException('A blueprint lifecycle cannot be resolved for a manifest without an application_blueprint section.');
        }

        if ($receipt === null || !$receipt->matches($manifest)) {
            return BlueprintLifecycle::Proposed;
        }

        return match ($receipt->decision) {
            BlueprintDecision::Approved => BlueprintLifecycle::Approved,
            BlueprintDecision::Rejected => BlueprintLifecycle::Rejected,
        };
    }
}
